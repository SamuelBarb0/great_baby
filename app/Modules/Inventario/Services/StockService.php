<?php

namespace App\Modules\Inventario\Services;

use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Inventario\Models\ReservaInventario;
use Illuminate\Support\Collection;

/**
 * Fuente única de verdad para saldos de stock.
 *
 * ─── Modo GRANULAR (producto.desglose_stock = true, comportamiento clásico) ───
 *   saldo_fisico(variante, ubicacion) = SUM(cantidad) del kardex por variante
 *   saldo_reservado(variante, ubicacion) = SUM(cantidad) reservas activas
 *   saldo_disponible = saldo_fisico - saldo_reservado
 *
 * ─── Modo AGREGADO (C-F1: producto.desglose_stock = false) ───
 *   saldo_fisico(producto, ubicacion) = SUM(cantidad) del kardex por producto SIN variante
 *   saldo_reservado(producto, ubicacion) = SUM(cantidad) reservas por producto SIN variante
 *   saldo_disponible = saldo_fisico - saldo_reservado
 *
 * Los métodos `*Sujeto()` bifurcan según el tipo del argumento.
 */
class StockService
{
    // ─────────────────────────────────────────────────────────
    // API GRANULAR (variante) — sin cambios de comportamiento vs. versión previa
    // ─────────────────────────────────────────────────────────

    public function saldoFisico(int $varianteId, ?int $ubicacionId = null): int
    {
        $q = InventarioMovimiento::query()->where('variante_id', $varianteId);
        if ($ubicacionId) {
            $q->where('ubicacion_id', $ubicacionId);
        }

        return (int) $q->sum('cantidad');
    }

    public function saldoReservado(int $varianteId, ?int $ubicacionId = null): int
    {
        $q = ReservaInventario::query()
            ->where('variante_id', $varianteId)
            ->where('activa', true)
            ->where(function ($q) {
                $q->whereNull('expira_at')->orWhere('expira_at', '>', now());
            });
        if ($ubicacionId) {
            $q->where('ubicacion_id', $ubicacionId);
        }

        return (int) $q->sum('cantidad');
    }

    public function saldoDisponible(int $varianteId, ?int $ubicacionId = null): int
    {
        return $this->saldoFisico($varianteId, $ubicacionId)
             - $this->saldoReservado($varianteId, $ubicacionId);
    }

    /**
     * Saldo físico agrupado por (variante, ubicacion) para un set de variantes.
     * Devuelve una colección keyed por "variante_id-ubicacion_id".
     */
    public function saldosMasivos(array $varianteIds): Collection
    {
        return InventarioMovimiento::query()
            ->whereIn('variante_id', $varianteIds)
            ->selectRaw('variante_id, ubicacion_id, SUM(cantidad) as saldo')
            ->groupBy('variante_id', 'ubicacion_id')
            ->get()
            ->keyBy(fn ($row) => "{$row->variante_id}-{$row->ubicacion_id}");
    }

    /**
     * C-F2 · Análogo de saldosMasivos() pero para productos agregados.
     * Devuelve una colección keyed por "producto_id-ubicacion_id".
     * Solo cuenta movimientos "de producto" (variante_id IS NULL).
     */
    public function saldosMasivosProducto(array $productoIds): Collection
    {
        return InventarioMovimiento::query()
            ->whereIn('producto_id', $productoIds)
            ->whereNull('variante_id')
            ->selectRaw('producto_id, ubicacion_id, SUM(cantidad) as saldo')
            ->groupBy('producto_id', 'ubicacion_id')
            ->get()
            ->keyBy(fn ($row) => "{$row->producto_id}-{$row->ubicacion_id}");
    }

    // ─────────────────────────────────────────────────────────
    // API AGREGADO (producto sin variantes) — C-F2 nuevo
    // ─────────────────────────────────────────────────────────

    /**
     * Saldo físico de un producto agregado (`desglose_stock=false`).
     * Suma solo movimientos donde variante_id IS NULL (movimientos "de producto").
     */
    public function saldoFisicoProducto(int $productoId, ?int $ubicacionId = null): int
    {
        $q = InventarioMovimiento::query()
            ->where('producto_id', $productoId)
            ->whereNull('variante_id');
        if ($ubicacionId) {
            $q->where('ubicacion_id', $ubicacionId);
        }

        return (int) $q->sum('cantidad');
    }

    public function saldoReservadoProducto(int $productoId, ?int $ubicacionId = null): int
    {
        $q = ReservaInventario::query()
            ->where('producto_id', $productoId)
            ->whereNull('variante_id')
            ->where('activa', true)
            ->where(function ($q) {
                $q->whereNull('expira_at')->orWhere('expira_at', '>', now());
            });
        if ($ubicacionId) {
            $q->where('ubicacion_id', $ubicacionId);
        }

        return (int) $q->sum('cantidad');
    }

    public function saldoDisponibleProducto(int $productoId, ?int $ubicacionId = null): int
    {
        return $this->saldoFisicoProducto($productoId, $ubicacionId)
             - $this->saldoReservadoProducto($productoId, $ubicacionId);
    }

    // ─────────────────────────────────────────────────────────
    // API POLIMÓRFICA · el motor decide por el tipo del sujeto
    // ─────────────────────────────────────────────────────────

    /**
     * Saldo físico de un sujeto (Producto agregado o ProductoVariante granular).
     * Es el punto de entrada preferido para código nuevo.
     */
    public function saldoFisicoSujeto(Producto|ProductoVariante $sujeto, ?int $ubicacionId = null): int
    {
        if ($sujeto instanceof ProductoVariante) {
            return $this->saldoFisico($sujeto->id, $ubicacionId);
        }
        // Producto agregado — debe tener desglose_stock=false, sino es error de uso.
        $this->assertProductoAgregado($sujeto);
        return $this->saldoFisicoProducto($sujeto->id, $ubicacionId);
    }

    public function saldoReservadoSujeto(Producto|ProductoVariante $sujeto, ?int $ubicacionId = null): int
    {
        if ($sujeto instanceof ProductoVariante) {
            return $this->saldoReservado($sujeto->id, $ubicacionId);
        }
        $this->assertProductoAgregado($sujeto);
        return $this->saldoReservadoProducto($sujeto->id, $ubicacionId);
    }

    public function saldoDisponibleSujeto(Producto|ProductoVariante $sujeto, ?int $ubicacionId = null): int
    {
        return $this->saldoFisicoSujeto($sujeto, $ubicacionId)
             - $this->saldoReservadoSujeto($sujeto, $ubicacionId);
    }

    // ─────────────────────────────────────────────────────────
    // LO QUE SE LE PUEDE PROMETER A UN CLIENTE
    // ─────────────────────────────────────────────────────────

    /**
     * Las bodegas desde las que sí se vende.
     *
     * Pasar `null` como ubicación a los métodos de arriba suma TODAS, y entre
     * ellas están avería, cuarentena y revisión: mercancía que existe en el
     * inventario y que nadie puede comprar. Prometerla sería mentirle al
     * cliente en la cara.
     *
     * @return array<int,int>
     */
    public function ubicacionesDeVenta(): array
    {
        return \App\Modules\Dropi\Models\InventarioUbicacion::query()
            ->where('disponible_para_venta', true)
            ->where('activa', true)
            ->pluck('id')->all();
    }

    /**
     * Cuánto se le puede ofrecer a un cliente de un producto o una variante.
     *
     * Físico menos reservado, sumando sólo las bodegas de venta. Nunca
     * negativo: si el inventario quedó en rojo por un descuadre, el cliente no
     * tiene por qué ver un número negativo — para él es cero.
     */
    public function disponibleParaVenta(Producto|ProductoVariante $sujeto): int
    {
        /*
         * Dos consultas, no dos por ubicación.
         *
         * Esto preguntaba ubicación por ubicación: `foreach
         * (ubicacionesDeVenta() as $id) { fisico($id) - reservado($id) }`. Con
         * las 52 ubicaciones de antes eran ~105 consultas y se notaba poco.
         * Al cargar las 435 posiciones de la bodega, `ubicacionesDeVenta()`
         * pasó a devolver 487 y la cuenta se fue a 975 consultas y ~1 segundo
         * POR SUJETO. La ficha de producto del portal lo llama una vez por el
         * agregado y una por cada variante, así que un producto de tres
         * variantes se iba a ~2.900 consultas y ~2 segundos; y
         * `CrearPedidoCliente` lo llama por cada línea del pedido.
         *
         * Medido el 19-nov-2026. El resultado es idéntico: sumar
         * (físico − reservado) de cada ubicación es lo mismo que restar las
         * dos sumas, y el `max(0, …)` se sigue aplicando al final, no por
         * ubicación, igual que antes.
         *
         * La versión por lotes de más abajo —`disponibleParaVentaDeProductos`,
         * la que usa el índice del catálogo— ya lo hacía así; esta se quedó
         * atrás.
         */
        $ubicaciones = $this->ubicacionesDeVenta();

        if ($ubicaciones === []) {
            return 0;
        }

        $esVariante = $sujeto instanceof ProductoVariante;

        if (! $esVariante) {
            $this->assertProductoAgregado($sujeto);
        }

        $fisico = (int) InventarioMovimiento::query()
            ->whereIn('ubicacion_id', $ubicaciones)
            ->when($esVariante,
                fn ($q) => $q->where('variante_id', $sujeto->id),
                fn ($q) => $q->where('producto_id', $sujeto->id)->whereNull('variante_id'),
            )
            ->sum('cantidad');

        $reservado = (int) ReservaInventario::query()
            ->whereIn('ubicacion_id', $ubicaciones)
            ->where('activa', true)
            ->where(fn ($q) => $q->whereNull('expira_at')->orWhere('expira_at', '>', now()))
            ->when($esVariante,
                fn ($q) => $q->where('variante_id', $sujeto->id),
                fn ($q) => $q->where('producto_id', $sujeto->id)->whereNull('variante_id'),
            )
            ->sum('cantidad');

        return max(0, $fisico - $reservado);
    }

    /**
     * Lo mismo para una lista de productos, en dos consultas.
     *
     * El catálogo del portal pinta veinte productos por página; preguntar uno
     * por uno serían cuarenta consultas por pantalla.
     *
     * @param  array<int,int>  $productoIds
     * @return array<int,int> producto_id => disponible para vender
     */
    public function disponibleParaVentaDeProductos(array $productoIds): array
    {
        if ($productoIds === []) {
            return [];
        }

        $bodegas = $this->ubicacionesDeVenta();
        if ($bodegas === []) {
            return array_fill_keys($productoIds, 0);
        }

        /*
         * Cada producto se cuenta por su propio modo.
         *
         * Un producto granular lleva el stock en sus variantes; uno agregado,
         * en el producto. Sumar las dos cosas da un número que no coincide con
         * ninguna de las dos pantallas: la tarjeta del catálogo prometía 20 de
         * un producto cuyas tres variantes decían «Agotado», porque en los
         * datos había movimientos sueltos a nivel de producto. Se cuenta sólo
         * el lado que corresponde, igual que `saldoFisicoProducto`.
         */
        $granular = Producto::whereIn('id', $productoIds)
            ->where('desglose_stock', true)->pluck('id')->all();

        $suma = function (string $modelo, array $ids, bool $conVariante) use ($bodegas) {
            if ($ids === []) {
                return collect();
            }
            $q = $modelo::query()
                ->whereIn('producto_id', $ids)
                ->whereIn('ubicacion_id', $bodegas);
            $conVariante ? $q->whereNotNull('variante_id') : $q->whereNull('variante_id');
            if ($modelo === ReservaInventario::class) {
                $q->where('activa', true)
                    ->where(fn ($w) => $w->whereNull('expira_at')->orWhere('expira_at', '>', now()));
            }

            return $q->selectRaw('producto_id, SUM(cantidad) total')
                ->groupBy('producto_id')->pluck('total', 'producto_id');
        };

        $agregados = array_values(array_diff($productoIds, $granular));

        $fisico = $suma(InventarioMovimiento::class, $granular, true)
            ->union($suma(InventarioMovimiento::class, $agregados, false));
        $reservado = $suma(ReservaInventario::class, $granular, true)
            ->union($suma(ReservaInventario::class, $agregados, false));

        $out = [];
        foreach ($productoIds as $id) {
            $out[$id] = max(0, (int) ($fisico[$id] ?? 0) - (int) ($reservado[$id] ?? 0));
        }

        return $out;
    }

    /**
     * Guardarraíl · llamar API agregada sobre un producto con desglose=true es un bug de uso:
     * el caller debería iterar sus variantes. Fallamos rápido y ruidoso.
     */
    private function assertProductoAgregado(Producto $p): void
    {
        if ($p->desglose_stock) {
            throw new \DomainException(
                "Producto #{$p->id} ({$p->referencia}) tiene desglose_stock=true. ".
                "Usa saldoFisicoSujeto con una ProductoVariante, o agrega saldos por variante."
            );
        }
    }
}
