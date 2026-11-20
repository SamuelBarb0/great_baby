<?php

namespace App\Modules\Inventario\Support;

use App\Modules\Dropi\Models\InventarioMovimiento;

/**
 * Costo promedio ponderado de lo que hay en una ubicación.
 *
 * Es el costo con el que sale la mercancía: se promedian las ENTRADAS con su
 * costo, ponderadas por cantidad. Las salidas no entran en el promedio porque
 * salen justamente a ese costo; si se incluyeran, cada venta movería el costo
 * de lo que queda en bodega, que es exactamente lo que el método promedio
 * busca evitar.
 *
 * La fórmula ya existía escrita a mano dentro de `DevolucionProveedorController`
 * y sólo servía para variantes. Acá vive una sola vez y entiende también los
 * productos agregados (los que no tienen variante).
 */
class CostoPromedio
{
    /**
     * Costos de varios sujetos en una ubicación, en una sola consulta.
     *
     * @param  list<array{variante_id:?int, producto_id:?int}>  $sujetos
     * @return array<string, float>  clave `v:{id}` o `p:{id}` => costo unitario
     */
    public static function porUbicacion(array $sujetos, int $ubicacionId): array
    {
        return self::porUbicaciones($sujetos, [$ubicacionId]);
    }

    /**
     * Lo mismo, pero mirando VARIAS ubicaciones como si fueran una sola.
     *
     * ───────────────────────────────────────────────────────────────────────
     * PARA QUÉ
     *
     * Una bodega y sus estantes son el mismo sitio físico. Desde que la
     * mercancía entra directo a `R12-S1-N1`, la fila de la sede «PPAL» puede
     * no tener ni una entrada propia — y preguntarle el costo a la sede sola
     * devolvía CERO.
     *
     * Eso no era un redondeo: el despacho escribía el egreso del kardex con
     * costo 0 y el asiento de costo de ventas se iba en cero con él. El
     * inventario salía regalado en los libros y nadie se enteraba, porque
     * cero es un número perfectamente válido para una columna de dinero.
     *
     * Promediando la sede JUNTO CON sus posiciones, el costo es el mismo que
     * tenía antes de que alguien bajara las cajas al estante — que es lo
     * correcto: mover una caja de sitio no le cambia lo que costó.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @param  list<array{variante_id:?int, producto_id:?int}>  $sujetos
     * @param  list<int>  $ubicacionIds
     * @return array<string, float>  clave `v:{id}` o `p:{id}` => costo unitario
     */
    public static function porUbicaciones(array $sujetos, array $ubicacionIds): array
    {
        $ubicacionIds = array_values(array_unique(array_filter($ubicacionIds)));

        if (! $ubicacionIds) {
            return [];
        }

        $variantes = array_values(array_filter(array_column($sujetos, 'variante_id')));
        // Un producto sólo cuenta como «agregado» si la línea no trae variante.
        $productos = [];
        foreach ($sujetos as $s) {
            if (empty($s['variante_id']) && ! empty($s['producto_id'])) {
                $productos[] = $s['producto_id'];
            }
        }

        /*
         * Una entrada SIN costo registrado no promedia como si costara cero:
         * queda fuera de la cuenta.
         *
         * ───────────────────────────────────────────────────────────────────
         * POR QUÉ
         *
         * `COALESCE(costo_unit, 0)` trataba «no sé cuánto costó» igual que
         * «costó nada», y eso arrastra el promedio hacia abajo en proporción
         * a cuántas unidades vinieron sin dato. Con seis mil unidades sin
         * costo contra nueve mil a $18.283, el estante quedaba valorizado en
         * $10.970 — y ése es el costo con el que después se despacha y se
         * asienta el costo de ventas.
         *
         * No es una política nueva: el «Reporte de stock por bodega», que es
         * lo que Gerencia lee, ya filtra `costo_unit > 0` desde que existe.
         * Las dos fórmulas del sistema daban números distintos del mismo
         * producto. Esto las pone de acuerdo.
         *
         * Y es coherente con el resto: `CerrarTomaFisica` se niega a cerrar un
         * ajuste con `costo_unit <= 0` precisamente porque lo lee como dato
         * faltante, no como mercancía regalada.
         * ───────────────────────────────────────────────────────────────────
         */
        $conCosto = 'cantidad > 0 AND costo_unit IS NOT NULL AND costo_unit > 0';

        $promedio = "CASE WHEN SUM(CASE WHEN {$conCosto} THEN cantidad ELSE 0 END) > 0 "
            ."THEN SUM(CASE WHEN {$conCosto} THEN cantidad * costo_unit ELSE 0 END) "
            ."     / SUM(CASE WHEN {$conCosto} THEN cantidad ELSE 0 END) "
            .'ELSE 0 END AS costo_prom';

        $out = [];

        if ($variantes) {
            InventarioMovimiento::query()
                ->whereIn('variante_id', $variantes)
                ->whereIn('ubicacion_id', $ubicacionIds)
                ->selectRaw("variante_id, {$promedio}")
                ->groupBy('variante_id')
                ->get()
                ->each(function ($r) use (&$out) {
                    $out['v:'.$r->variante_id] = round((float) $r->costo_prom, 2);
                });
        }

        if ($productos) {
            InventarioMovimiento::query()
                ->whereIn('producto_id', $productos)
                ->whereNull('variante_id')
                ->whereIn('ubicacion_id', $ubicacionIds)
                ->selectRaw("producto_id, {$promedio}")
                ->groupBy('producto_id')
                ->get()
                ->each(function ($r) use (&$out) {
                    $out['p:'.$r->producto_id] = round((float) $r->costo_prom, 2);
                });
        }

        return $out;
    }

    /** Clave con la que se busca un sujeto en el resultado de arriba. */
    public static function clave(?int $varianteId, ?int $productoId): string
    {
        return $varianteId ? "v:{$varianteId}" : "p:{$productoId}";
    }
}
