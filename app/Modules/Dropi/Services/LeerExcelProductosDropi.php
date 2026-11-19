<?php

namespace App\Modules\Dropi\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lee el Excel de inventario del cliente para cargarlo a Dropi:
 * PRODUCTO («REF NOMBRE» en una celda), CANT y VARIACIONES («8 ROJO- 9 AZUL»
 * o «NO HAY VARIABLE»). Busca el encabezado en las primeras filas, así que
 * aguanta títulos arriba y columnas extra (COSTO, PRECIO DROPI…).
 */
class LeerExcelProductosDropi
{
    /**
     * @return array<int, array{fila:int, ref:string, nombre:string, cantidad:float, variaciones:?string}>
     */
    public function leer(string $ruta): array
    {
        $filas = IOFactory::load($ruta)->getActiveSheet()->toArray(null, true, false, false);

        [$encabezado, $cols] = $this->encabezado($filas);
        if ($encabezado === null) {
            throw new \InvalidArgumentException('No encontré el encabezado: el Excel necesita las columnas PRODUCTO y CANT (y opcionalmente VARIACIONES).');
        }

        $out = [];
        foreach ($filas as $i => $fila) {
            if ($i <= $encabezado) {
                continue;
            }
            $producto = trim((string) ($fila[$cols['producto']] ?? ''));
            $cantidad = $fila[$cols['cantidad']] ?? null;
            if ($producto === '' || ! is_numeric($cantidad)) {
                continue;
            }
            [$ref, $nombre] = preg_match('/^(\S+)\s+(.*)$/u', $producto, $m) ? [$m[1], trim($m[2])] : [$producto, ''];
            $out[] = [
                'fila' => $i + 1,
                'ref' => $ref,
                'nombre' => $nombre,
                'cantidad' => (float) $cantidad,
                'variaciones' => isset($cols['variaciones']) ? (trim((string) ($fila[$cols['variaciones']] ?? '')) ?: null) : null,
                // Costo unitario (columna COSTO, no «COSTO TOTAL»): valoriza el
                // ingreso al inventario del ERP. Vacío o ilegible = sin costo.
                'costo' => isset($cols['costo']) ? self::costo($fila[$cols['costo']] ?? null) : null,
            ];
        }

        if (! $out) {
            throw new \InvalidArgumentException('El Excel no trae filas con producto y cantidad debajo del encabezado.');
        }

        return $out;
    }

    private static function costo(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return $v > 0 ? round((float) $v, 4) : null;
        }

        return $v === null ? null : \App\Modules\Inventario\Actions\RegistrarCargaInventario::parsearCosto((string) $v);
    }

    /** @return array{0: ?int, 1: array<string, int>} */
    private function encabezado(array $filas): array
    {
        foreach (array_slice($filas, 0, 15, true) as $i => $fila) {
            $cols = [];
            foreach ($fila as $c => $valor) {
                $t = mb_strtoupper(trim((string) $valor));
                if ($t === 'PRODUCTO' || str_starts_with($t, 'PRODUCTO ')) {
                    $cols['producto'] = $c;
                } elseif (in_array($t, ['CANT', 'CANTIDAD', 'STOCK', 'EXISTENCIA'], true)) {
                    $cols['cantidad'] = $c;
                } elseif (str_starts_with($t, 'VARIACION')) {
                    $cols['variaciones'] = $c;
                } elseif (! isset($cols['costo']) && \App\Modules\Inventario\Actions\RegistrarCargaInventario::esEncabezadoCosto($t)) {
                    $cols['costo'] = $c;
                }
            }
            if (isset($cols['producto'], $cols['cantidad'])) {
                return [$i, $cols];
            }
        }

        return [null, []];
    }
}
