<?php

namespace App\Modules\Dropi\Jobs;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Services\RevisarPedidoEnDropi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Llegó un pedido nuevo: ¿reemplaza a otro? (26-nov-2026). Cuando el
 * dropshipper edita un pedido, Dropi crea otra orden para el mismo cliente y la
 * vieja queda «Orden no encontrada». El sync ve la nueva en segundos, así que
 * ahí mismo se pregunta a Dropi por los pedidos sin despachar del mismo
 * teléfono: el teléfono sólo dice a quién preguntar; lo que se cancela es lo
 * que Dropi confirma que ya no existe. Lo que no se detecte así (otro teléfono,
 * cancelado sin orden nueva) lo recoge dropi:revisar-pendientes.
 */
class RevisarReemplazadosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Una consulta a Dropi por candidato (casi siempre uno): por debajo del `retry_after` de 90 s. */
    public int $timeout = 80;

    /** Hasta cuántos días antes de la nueva pudo crearse la que reemplaza. */
    public const DIAS_ATRAS = 2;

    public function __construct(public int $pedidoNuevoId)
    {
        // La cola de las guías: SIIGO no la frena.
        $this->onQueue(GenerarGuiasEnBloqueJob::COLA);
    }

    /** Sólo con el sync web prendido: es lo que habla con Dropi. */
    public static function alLlegar(DropiPedido $nuevo): void
    {
        if (! config('dropi.web.sync_activo') || blank($nuevo->cliente_telefono)) {
            return;
        }
        self::dispatch($nuevo->id)->afterCommit();
    }

    /** Los pedidos sin despachar del mismo cliente, creados antes que el nuevo y hasta dos días atrás. */
    public static function candidatos(DropiPedido $nuevo)
    {
        $cuando = $nuevo->dropi_creado_at ?? $nuevo->created_at;

        return DropiPedido::query()
            ->where('cliente_telefono', $nuevo->cliente_telefono)
            ->whereKeyNot($nuevo->id)
            ->whereNotNull('dropi_orden_id')
            ->whereIn('estado', [EstadoPedidoDropi::Pending, EstadoPedidoDropi::PendienteInventario, EstadoPedidoDropi::Alistando])
            ->when($cuando, fn ($q) => $q->whereBetween('dropi_creado_at', [$cuando->copy()->subDays(self::DIAS_ATRAS), $cuando]))
            ->get();
    }

    public function handle(RevisarPedidoEnDropi $revisar): void
    {
        $nuevo = DropiPedido::find($this->pedidoNuevoId);
        if (! $nuevo) {
            return;
        }

        foreach (self::candidatos($nuevo) as $viejo) {
            $r = $revisar->revisar($viejo, 'reemplazo_detectado');
            if ($r['tipo'] !== RevisarPedidoEnDropi::PENDIENTE) {
                Log::info('dropi.reemplazo_detectado', [
                    'pedido_id' => $viejo->id, 'orden' => $viejo->dropi_orden_id, 'nueva' => $nuevo->dropi_orden_id,
                    'tipo' => $r['tipo'], 'estatus' => $r['estatus'],
                ]);
            }
        }
    }
}
