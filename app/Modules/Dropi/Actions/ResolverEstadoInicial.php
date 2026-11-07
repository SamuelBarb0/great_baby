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
 * Este método revisa el stock disponible de la variante (o del producto agregado).
 */
class ResolverEstadoInicial
{
    /**
     * @param  array<int, array{sku:string, cantidad:int}>  $items
     */
    public function porSku(array $items): EstadoPedidoDropi
    {
        foreach ($items as $it) {
            $destino = $this->destinoPara($it['sku']);
            if (! $destino) {
                // SKU desconocido → tratar como pendiente para que el alistador lo revise.
                return EstadoPedidoDropi::PendienteInventario;
            }

            $disponible = $destino['variante_id']
                ? $this->stockDisponible($destino['variante_id'])
                : $this->stockDisponibleAgregado($destino['producto_id']);
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
        return $this->destinoPara($sku)['variante_id'] ?? null;
    }

    /**
     * A qué apunta una llave de Dropi: una variante, o un producto AGREGADO
     * (sin variantes; su stock es un solo total por producto). NULL = sin vincular.
     *
     * @return array{variante_id: ?int, producto_id: ?int}|null
     */
    public function destinoPara(?string $sku): ?array
    {
        if ($sku === null || $sku === '') {
            return null;
        }

        $vinculo = DropiVinculoProducto::where('sku_dropi', $sku)->first(['variante_id', 'producto_id']);
        if ($vinculo && ($vinculo->variante_id || $vinculo->producto_id)) {
            return [
                'variante_id' => $vinculo->variante_id ? (int) $vinculo->variante_id : null,
                'producto_id' => $vinculo->variante_id ? null : (int) $vinculo->producto_id,
            ];
        }

        $varianteId = ProductoVariante::where('codigo_barras', $sku)->value('id');

        return $varianteId ? ['variante_id' => (int) $varianteId, 'producto_id' => null] : null;
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

    /**
     * Lo mismo para un producto agregado: su kardex va por `producto_id` sin
     * variante (así lo escribe SalidaInventarioDropi y así lo lee StockService).
     */
    protected function stockDisponibleAgregado(int $productoId): int
    {
        $ubicacionesVenta = InventarioUbicacion::query()
            ->where('disponible_para_venta', true)
            ->where('activa', true)
            ->pluck('id');

        if ($ubicacionesVenta->isEmpty()) {
            return 0;
        }

        return (int) InventarioMovimiento::query()
            ->where('producto_id', $productoId)
            ->whereNull('variante_id')
            ->whereIn('ubicacion_id', $ubicacionesVenta)
            ->sum('cantidad');
    }
}
