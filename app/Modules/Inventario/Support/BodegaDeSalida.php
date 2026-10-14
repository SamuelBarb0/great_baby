<?php

namespace App\Modules\Inventario\Support;

use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;

/**
 * De qué bodega sale cada ítem de un despacho.
 *
 * El pedido trae `ubicacion_origen_id` cuando una regla de ruteo por ciudad lo
 * resolvió al crearse. El problema encontrado el 2026-10-13 llevando una venta
 * B2B completa por la interfaz: la tabla `reglas_ruteo_ciudad` está vacía y no
 * existe ninguna pantalla para cargarla, así que TODO pedido nace con origen
 * nulo. El descuento de inventario se rendía ahí mismo —devolvía 0 en silencio—
 * y el resultado era el que el propio descuento dice evitar: se despacha, se
 * factura, y el ERP sigue mostrando en bodega lo que ya va camino al cliente.
 * Sin egreso tampoco hay costo de ventas, así que el margen bruto daba 100%.
 *
 * Cuando el pedido no dice de dónde sale, se deduce de la única fuente que no
 * miente: dónde está la mercancía. Si una referencia tiene saldo en una sola
 * bodega, de ahí sale. No se adivina nada que el kardex no respalde.
 */
class BodegaDeSalida
{
    /**
     * Ubicación por ítem, en una sola consulta.
     *
     * @param  list<array{variante_id:?int, producto_id:?int}>  $sujetos
     * @return array<string, int>  clave `v:{id}` o `p:{id}` => ubicacion_id
     */
    public static function resolver(array $sujetos, ?int $origenDelPedido = null): array
    {
        if ($origenDelPedido) {
            // El pedido ya sabe de dónde sale: se respeta tal cual, que es el
            // comportamiento que ya existía y el que usa la Cola de Jorge.
            return array_fill_keys(
                array_map(fn ($s) => CostoPromedio::clave($s['variante_id'] ?? null, $s['producto_id'] ?? null), $sujetos),
                $origenDelPedido
            );
        }

        $variantes = array_values(array_filter(array_column($sujetos, 'variante_id')));
        $productos = [];
        foreach ($sujetos as $s) {
            if (empty($s['variante_id']) && ! empty($s['producto_id'])) {
                $productos[] = $s['producto_id'];
            }
        }

        // Bodegas elegibles: activas, de verdad, no los espejos del catálogo de
        // SIIGO —esas no guardan nada, son sólo el reflejo de la cuenta ajena.
        $elegibles = InventarioUbicacion::query()
            ->where('activa', true)
            ->where('codigo', 'not like', 'SIIGO-%')
            ->pluck('id')->all();

        if (! $elegibles) {
            return [];
        }

        $out = [];

        $saldos = function ($campo, array $ids) use ($elegibles) {
            if (! $ids) {
                return collect();
            }
            $q = InventarioMovimiento::query()
                ->whereIn($campo, $ids)
                ->whereIn('ubicacion_id', $elegibles)
                ->selectRaw("{$campo} as sujeto, ubicacion_id, SUM(cantidad) as saldo")
                ->groupBy($campo, 'ubicacion_id')
                ->havingRaw('SUM(cantidad) > 0');

            // Un producto sólo cuenta como agregado si el movimiento no tiene
            // variante; si no, el saldo de las variantes se sumaría dos veces.
            if ($campo === 'producto_id') {
                $q->whereNull('variante_id');
            }

            return $q->get();
        };

        foreach ([['variante_id', $variantes, 'v'], ['producto_id', $productos, 'p']] as [$campo, $ids, $pre]) {
            $saldos($campo, $ids)
                // La bodega con más existencias de esa referencia: es de donde
                // el alistador la habría tomado.
                ->sortByDesc('saldo')
                ->each(function ($r) use (&$out, $pre) {
                    $out["{$pre}:{$r->sujeto}"] ??= (int) $r->ubicacion_id;
                });
        }

        return $out;
    }
}
