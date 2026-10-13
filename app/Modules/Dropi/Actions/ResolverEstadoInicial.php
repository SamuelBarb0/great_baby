<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\ProductoVariante;

/**
 * §5 Diseño Dropi — El pedido nace en pending, PERO:
 * Si al alistar no hay inventario en zonas de venta, cae a "pendiente_inventario".
 * Este método revisa el stock disponible de la variante.
 */
class ResolverEstadoInicial
{
    /**
     * @param  array<int, array{sku:string, cantidad:int}>  $items
     */
    public function porSku(array $items): EstadoPedidoDropi
    {
        foreach ($items as $it) {
            $varianteId = $this->varianteIdPara($it['sku']);
            if (! $varianteId) {
                // SKU desconocido → tratar como pendiente para que el alistador lo revise.
                return EstadoPedidoDropi::PendienteInventario;
            }

            $disponible = $this->stockDisponible($varianteId);
            if ($disponible < (int) $it['cantidad']) {
                return EstadoPedidoDropi::PendienteInventario;
            }
        }

        return EstadoPedidoDropi::Pending;
    }

    /**
     * Variante del catálogo GB para una llave de Dropi: primero el vínculo que
     * alguien hizo a mano (Productos sin vincular), después el código de barras.
     */
    public function varianteIdPara(?string $sku): ?int
    {
        if ($sku === null || $sku === '') {
            return null;
        }

        return DropiVinculoProducto::where('sku_dropi', $sku)->value('variante_id')
            ?? ProductoVariante::where('codigo_barras', $sku)->value('id');
    }

    /**
     * §7 y §11 — SOLO cuenta stock en ubicaciones disponibles para venta.
     * Nunca sugiere stock de averia/garantia para suplir un pedido normal.
     */
    protected function stockDisponible(int $varianteId): int
    {
        $ubicacionesVenta = InventarioUbicacion::query()
            ->where('disponible_para_venta', true)
            ->where('activa', true)
            ->pluck('id');

        if ($ubicacionesVenta->isEmpty()) {
            return 0;
        }

        return (int) InventarioMovimiento::query()
            ->where('variante_id', $varianteId)
            ->whereIn('ubicacion_id', $ubicacionesVenta)
            ->sum('cantidad');
    }
}
