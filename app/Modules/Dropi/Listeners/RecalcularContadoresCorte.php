<?php

namespace App\Modules\Dropi\Listeners;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Events\PedidoDropiTransicionado;
use App\Modules\Dropi\Models\DropiCorte;

/**
 * A4 · Al cambiar el estado de un pedido, recalcula los contadores del corte
 * (totales / despachados / pagados / pendientes_inv) — antes se actualizaban
 * SOLO durante el sync, así que la conciliación wallet no reflejaba el
 * pedidos_pagados en tiempo real.
 *
 * Cuenta por SQL puro con COUNT filtrado — el corte típico no pasa de 500
 * pedidos, así que 4 COUNT indexados son <5 ms.
 */
class RecalcularContadoresCorte
{
    public function handle(PedidoDropiTransicionado $e): void
    {
        $corteId = $e->pedido->corte_id;
        if (! $corteId) return;

        // H6 datos · si el corte está cerrado, NO tocamos los contadores — el
        // snapshot_json + hash SHA-256 del manifiesto es la fuente de verdad
        // congelada. Actualizar la tabla sería contradecir el PDF firmado.
        $corte = DropiCorte::find($corteId);
        if (! $corte) return;
        $estado = $corte->estado instanceof \App\Modules\Dropi\Enums\EstadoCorte
            ? $corte->estado->value
            : (string) $corte->estado;
        if ($estado === \App\Modules\Dropi\Enums\EstadoCorte::Cerrado->value) {
            return;
        }

        // Query builder plano: con DropiCorte::query()->from('dropi_pedidos') el
        //   scope de SoftDeletes de DropiCorte agregaba `dropi_cortes.deleted_at IS
        //   NULL` a una consulta sobre dropi_pedidos → error SQL → la transacción de
        //   transicionar() se revertía y NINGÚN pedido de corte abierto cambiaba de estado.
        $counts = \Illuminate\Support\Facades\DB::table('dropi_pedidos')
            ->where('corte_id', $corteId)
            ->whereNull('deleted_at')
            ->selectRaw("
                COUNT(*) as totales,
                SUM(CASE WHEN estado IN ('despachado','entregado','pagado') THEN 1 ELSE 0 END) as despachados,
                SUM(CASE WHEN estado = 'pagado' THEN 1 ELSE 0 END) as pagados,
                SUM(CASE WHEN estado = 'pendiente_inventario' THEN 1 ELSE 0 END) as pendientes
            ")
            ->first();

        DropiCorte::where('id', $corteId)->update([
            'pedidos_totales' => (int) ($counts->totales ?? 0),
            'pedidos_despachados' => (int) ($counts->despachados ?? 0),
            'pedidos_pagados' => (int) ($counts->pagados ?? 0),
            'pedidos_pendientes_inv' => (int) ($counts->pendientes ?? 0),
        ]);
    }
}
