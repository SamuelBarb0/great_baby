<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Enums\EstadoCorte;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Listeners\RecalcularContadoresCorte;
use App\Modules\Dropi\Models\DropiPedido;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Arranque del ERP «en cero» (reunión con la señora Lahida, 24-nov-2026): al
 * primer corte del ERP sólo entran los pedidos que en Dropi siguen PENDIENTE.
 *
 * Los que Aracely ya trabajó en Dropi con el flujo anterior (guía generada
 * allá) o que Dropi ya no muestra pasan a «Gestionado en Dropi»: salen de todas
 * las colas del ERP sin tocar inventario, y la sincronización los sigue
 * moviendo cuando Dropi los despache, entregue o cancele.
 *
 * Los que se quedan (PENDIENTE en Dropi) se juntan en el corte que está
 * recibiendo pedidos al correrlo: estaban regados en cortes viejos que nunca se
 * cerraron y la lista de recolección sólo mira el corte en trabajo. Por eso se
 * corre el día del arranque, antes del primer corte.
 *
 * Sólo toca «pending» y «pendiente_inventario». Los empacados se quedan: sacarlos
 * revertiría el egreso de inventario.
 *
 * Sin --ejecutar sólo cuenta.
 */
class DropiArranqueCommand extends Command
{
    protected $signature = 'dropi:arranque {--ejecutar : Aplica los cambios; sin esto sólo muestra el conteo}';

    protected $description = 'Saca de las colas del ERP los pedidos que en Dropi ya no están PENDIENTE (arranque en cero).';

    public function handle(): int
    {
        $base = DB::table('dropi_pedidos as p')
            ->leftJoin('dropi_web_huellas as h', 'h.dropi_orden_id', '=', 'p.dropi_orden_id')
            ->whereNull('p.deleted_at')
            ->whereIn('p.estado', [EstadoPedidoDropi::Pending->value, EstadoPedidoDropi::PendienteInventario->value]);

        $this->table(['Estado ERP', 'Estado en Dropi', 'Pedidos', 'Queda en el ERP'], (clone $base)
            ->selectRaw("p.estado as estado_erp, COALESCE(h.estatus_dropi, '(Dropi ya no lo muestra)') as en_dropi, COUNT(*) as n")
            ->groupBy('estado_erp', 'en_dropi')
            ->orderBy('estado_erp')->orderByDesc('n')
            ->get()
            ->map(fn ($r) => [$r->estado_erp, $r->en_dropi, $r->n, $r->en_dropi === 'PENDIENTE' ? 'sí' : 'no'])
            ->all());

        $ids = (clone $base)
            ->where(fn ($q) => $q->whereNull('h.estatus_dropi')->orWhere('h.estatus_dropi', '!=', 'PENDIENTE'))
            ->pluck('p.id');

        $this->info("Pasan a «Gestionado en Dropi»: {$ids->count()}");

        // Los que se quedan estaban regados en cortes viejos que nunca se
        // cerraron. Desde el 25-nov-2026 el corte nace cuando Aracely genera:
        // quedan «sin corte», esperando a que ella los seleccione.
        $aMover = (clone $base)
            ->where('h.estatus_dropi', 'PENDIENTE')
            ->leftJoin('dropi_cortes as c', 'c.id', '=', 'p.corte_id')
            ->whereNotNull('p.corte_id')
            ->where(fn ($q) => $q->whereNull('c.estado')->orWhere('c.estado', '!=', EstadoCorte::Cerrado->value))
            ->pluck('p.id');

        $this->info("Quedan sin corte, para el primer «Generar corte»: {$aMover->count()}");

        if (! $this->option('ejecutar')) {
            $this->warn('Modo prueba: no se cambió nada. Usa --ejecutar para aplicarlo.');

            return self::SUCCESS;
        }

        $hechos = 0;
        $errores = [];
        foreach ($ids as $id) {
            $pedido = DropiPedido::find($id);
            $estatus = DB::table('dropi_web_huellas')->where('dropi_orden_id', $pedido->dropi_orden_id)->value('estatus_dropi');
            try {
                $pedido->transicionar(EstadoPedidoDropi::GestionadoEnDropi, 'sistema', null, [
                    'motivo' => 'arranque_erp',
                    'estatus_dropi' => $estatus,
                ]);
                $hechos++;
            } catch (\Throwable $e) {
                $errores[] = "{$pedido->dropi_orden_id}: {$e->getMessage()}";
            }
        }

        $this->info("Hechos: {$hechos}");

        // Sacar de un corte no es un cambio de estado: se recalculan a mano los
        // contadores de los cortes que pierden pedidos.
        $cortesTocados = DropiPedido::whereIn('id', $aMover)->distinct()->pluck('corte_id')->filter()->unique();
        DropiPedido::whereIn('id', $aMover)->update(['corte_id' => null]);
        foreach ($cortesTocados as $corteId) {
            RecalcularContadoresCorte::recontar((int) $corteId);
        }
        $this->info("Sin corte, esperando a Aracely: {$aMover->count()}");
        foreach ($errores as $e) {
            $this->error($e);
        }

        return $errores ? self::FAILURE : self::SUCCESS;
    }
}
