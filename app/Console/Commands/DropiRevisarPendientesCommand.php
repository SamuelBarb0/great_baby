<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Services\RevisarPedidoEnDropi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Lo que el ERP tiene sin despachar, contra lo que dice Dropi hoy (26-nov-2026).
 * Un pedido que el dropshipper edita queda en el ERP como pendiente para
 * siempre: Dropi lo reemplaza por otra orden y la vieja ya no sale en la lista
 * que lee el sync. Así se colaban en el alistador, en los cortes y en las
 * guías. Cada vuelta pregunta por los que llevan más tiempo sin mirarse.
 */
class DropiRevisarPendientesCommand extends Command
{
    protected $signature = 'dropi:revisar-pendientes
        {--max=40 : Cuántos pedidos revisar en esta vuelta}
        {--simular : Solo mostrar qué pasaría con cada uno, sin cambiar nada}';

    protected $description = 'Pregunta a Dropi por los pedidos sin despachar y da de baja los reemplazados o cancelados allá.';

    /** Lo que todavía no sale de la bodega. */
    public const SIN_DESPACHAR = [
        EstadoPedidoDropi::Pending, EstadoPedidoDropi::PendienteInventario, EstadoPedidoDropi::Alistando,
    ];

    public function handle(RevisarPedidoEnDropi $revisar): int
    {
        $simular = (bool) $this->option('simular');
        $pedidos = DropiPedido::query()
            ->select('dropi_pedidos.*')
            ->leftJoin('dropi_web_huellas as h', 'h.dropi_orden_id', '=', 'dropi_pedidos.dropi_orden_id')
            ->whereIn('dropi_pedidos.estado', self::SIN_DESPACHAR)
            ->whereNotNull('dropi_pedidos.dropi_orden_id')
            // Primero lo que nunca se miró o lleva más tiempo sin mirarse.
            ->orderByRaw('h.sincronizado_at IS NOT NULL, h.sincronizado_at, dropi_pedidos.id')
            ->limit(max(1, (int) $this->option('max')))
            ->get();

        $cuenta = [RevisarPedidoEnDropi::PENDIENTE => 0, RevisarPedidoEnDropi::NO_EXISTE => 0, RevisarPedidoEnDropi::OTRO_ESTATUS => 0, RevisarPedidoEnDropi::ERROR => 0];
        foreach ($pedidos as $p) {
            $r = $simular ? $revisar->diagnosticar($p) : $revisar->revisar($p, 'revision_dropi');
            $cuenta[$r['tipo']]++;
            if ($r['tipo'] === RevisarPedidoEnDropi::PENDIENTE) {
                continue;
            }

            $nueva = $r['tipo'] === RevisarPedidoEnDropi::NO_EXISTE ? RevisarPedidoEnDropi::reemplazoDe($p) : null;
            $linea = sprintf('#%d orden %s (%s): %s%s%s',
                $p->id, $p->dropi_orden_id, $p->cliente_nombre,
                $r['estatus'] ? "en Dropi «{$r['estatus']}»" : $r['motivo'],
                $nueva ? " · la nueva es la orden {$nueva->dropi_orden_id} ({$nueva->estado?->label()})" : '',
                $simular ? '' : ' → ERP: ' . ($p->fresh()?->estado?->label() ?? '—'),
            );
            $this->line($linea);
            if (! $simular && $r['tipo'] !== RevisarPedidoEnDropi::ERROR) {
                Log::info('dropi.revisar_pendientes', ['pedido_id' => $p->id, 'orden' => $p->dropi_orden_id, 'tipo' => $r['tipo'], 'estatus' => $r['estatus']]);
            }
        }

        $this->info(sprintf('%srevisados %d · siguen pendientes %d · ya no existen %d · en otro estatus %d · sin respuesta %d',
            $simular ? '[simulación] ' : '', $pedidos->count(), $cuenta[RevisarPedidoEnDropi::PENDIENTE],
            $cuenta[RevisarPedidoEnDropi::NO_EXISTE], $cuenta[RevisarPedidoEnDropi::OTRO_ESTATUS], $cuenta[RevisarPedidoEnDropi::ERROR]));

        return self::SUCCESS;
    }
}
