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
            // Packs: «X 2 CAJAS» pide 2 del ERP por cada una de Dropi.
            if ($disponible < (int) $it['cantidad'] * ($destino['unidades'] ?? 1)) {
                return EstadoPedidoDropi::PendienteInventario;
            }
        }

        return EstadoPedidoDropi::Pending;
    }

    /**
     * Como porSku(), pero descontando lo que ya se apartó para otros pedidos en
     * la misma revisión (LiberarPedidosPorStock): dos pedidos no se quedan con la
     * misma unidad. Si alcanza, aparta lo suyo en $apartado.
     *
     * @param  array<int, array{sku:string, cantidad:int}>  $items
     * @param  array<string, int>  $apartado  'v:{id}' / 'p:{id}' => unidades ya apartadas
     */
    public function alcanzaConApartado(array $items, array &$apartado): bool
    {
        $pide = [];
        foreach ($items as $it) {
            $destino = $this->destinoPara($it['sku']);
            if (! $destino) {
                return false;
            }
            $llave = $destino['variante_id'] ? 'v:'.$destino['variante_id'] : 'p:'.$destino['producto_id'];
            $pide[$llave] = ($pide[$llave] ?? 0) + (int) $it['cantidad'] * ($destino['unidades'] ?? 1);
        }

        foreach ($pide as $llave => $cantidad) {
            [$tipo, $id] = explode(':', $llave);
            $disponible = $tipo === 'v' ? $this->stockDisponible((int) $id) : $this->stockDisponibleAgregado((int) $id);
            if ($disponible - ($apartado[$llave] ?? 0) < $cantidad) {
                return false;
            }
        }

        foreach ($pide as $llave => $cantidad) {
            $apartado[$llave] = ($apartado[$llave] ?? 0) + $cantidad;
        }

        return true;
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
     * @return array{variante_id: ?int, producto_id: ?int, unidades: int}|null
     */
    public function destinoPara(?string $sku): ?array
    {
        if ($sku === null || $sku === '') {
            return null;
        }

        $vinculo = DropiVinculoProducto::where('sku_dropi', $sku)->first(['variante_id', 'producto_id', 'unidades']);
        if ($vinculo && ($vinculo->variante_id || $vinculo->producto_id)) {
            return [
                'variante_id' => $vinculo->variante_id ? (int) $vinculo->variante_id : null,
                'producto_id' => $vinculo->variante_id ? null : (int) $vinculo->producto_id,
                'unidades' => max(1, (int) ($vinculo->unidades ?? 1)),
            ];
        }

        $varianteId = ProductoVariante::where('codigo_barras', $sku)->value('id');

        return $varianteId ? ['variante_id' => (int) $varianteId, 'producto_id' => null, 'unidades' => 1] : null;
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
