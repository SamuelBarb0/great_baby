<?php

namespace App\Modules\Compras\Services;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Lee la lista de empaque (o la factura) TAL COMO la manda cada proveedor
 * (reunión con Alejandro y Liz, 30-oct-2026: «cada proveedor tiene su formato
 * establecido» y ninguno lo va a cambiar).
 *
 * Los formatos reales (Topwin, DUQ, Yiwu Jidong) comparten una sola cosa: una
 * fila de encabezados y debajo una fila por referencia. Así que:
 *   - En cada hoja se busca la fila que más encabezados conocidos tiene (en
 *     español, inglés o chino) y gana la hoja con más columnas reconocidas: la
 *     «PACKING LIST» trae m³ y peso, la «INVOICE» de al lado no.
 *   - Quien sube el archivo ve lo que se detectó y puede corregir cada columna;
 *     ese mapa se recuerda por proveedor (`FormatoProveedor`).
 *   - Un m³ puede venir por caja o por la línea entera: se decide con las
 *     medidas de la caja cuando las hay.
 *   - Las celdas con fórmula se leen por su valor calculado.
 */
class LectorListaEmpaque
{
    private const FILAS_BUSQUEDA = 40;
    private const MAX_FILAS = 3000;

    /** Campo → encabezados (normalizados) con que lo escriben los proveedores. */
    public const CAMPOS = [
        'referencia' => ['REF', 'REF.', 'REFERENCIA', 'ITEM', 'ITEM NO', 'ITEM NO.', 'ITEM NUMBER', 'ART NO', 'ARTICULO', 'MODELO', 'MODEL'],
        'descripcion' => ['DESCRIPCION', 'DESCRIPTION', 'DES.', 'DES', 'DESC', 'NOMBRE', 'PRODUCTO', 'PRODUCT', 'NAME'],
        'cajas' => ['CAJAS', 'TOTAL CAJAS', 'CTNS', 'CTN', 'CARTONS', 'CARTON NOS.', 'CARTON NOS', 'CARTON QTY', 'BULTOS', 'PKGS', 'PACKAGES'],
        'unidades_por_caja' => ['QTY/CTN', 'PCS/CTN', 'CANTI/CA', 'UNIDAD CAJA', 'UNIDADES/CAJA', 'UDS/CAJA', 'CTN/CAJA', 'PACKING'],
        'cantidad' => ['T.QTY', 'TOTAL QTY', 'QUANTITY', 'QTY', 'CANTI/TT', 'TOTAL CANTIDAD', 'CANTIDAD', 'CANTIDAD TOTAL', 'PCS'],
        'precio' => ['UNIT PRICE', 'UNIT PRICE RMB', 'UNIT PRICE USD', 'UNITE PRICE@', 'UNIT PRICE@', 'PRECIO', 'PRECIO RMB', 'PRECIO USD', 'PRICE'],
        'cbm_caja' => ['CUBI/CA', 'CBM/CTN', 'CBM CAJA', 'CBM'],
        'cbm_total' => ['CUBI/TT', 'TOTAL CBM', 'CBM TOTAL', 'METRO CUBICO', 'MEAS.', 'MEAS', 'MEASUREMENT', 'VOLUME', 'VOLUMEN'],
        'peso_caja' => ['G.W/KG', 'PESO/CAJA KGS', 'PESO CTN', 'PESO XCTN', 'GW/CTN', 'PESO CAJA'],
        'peso_total' => ['G.W/TT', 'TOTAL PESO KGS', 'PESO TOTAL', 'GROSS WEIGHT', 'TOTAL G.W', 'TOTAL GW'],
        'largo' => ['M1', 'L', 'LARGO', 'LENGTH', '外箱长度'],
        'ancho' => ['M2', 'A', 'ANCHO', 'WIDTH', '外箱宽度'],
        'alto' => ['M3', 'P', 'ALTO', 'HEIGHT', '外箱高度'],
    ];

    public const ETIQUETAS = [
        'referencia' => 'Referencia', 'descripcion' => 'Descripción', 'cajas' => 'Cajas',
        'unidades_por_caja' => 'Unidades por caja', 'cantidad' => 'Cantidad total', 'precio' => 'Precio unitario',
        'cbm_caja' => 'm³ por caja', 'cbm_total' => 'm³ de la línea', 'peso_caja' => 'Peso por caja (kg)',
        'peso_total' => 'Peso de la línea (kg)', 'largo' => 'Largo caja (cm)', 'ancho' => 'Ancho caja (cm)', 'alto' => 'Alto caja (cm)',
    ];

    /**
     * Hojas del archivo con sus encabezados candidatos: lo que la pantalla
     * muestra para que el usuario elija o corrija.
     *
     * @return list<array{hoja: string, fila: int, encabezados: array<int,string>, mapa: array<string,int>, puntaje: int}>
     */
    public function detectar(string $ruta): array
    {
        $candidatas = [];
        $filas = [];
        $this->recorrer($ruta, function (string $hoja, int $fila, array $valores) use (&$candidatas, &$filas) {
            if ($fila > self::FILAS_BUSQUEDA) {
                return false;
            }
            $filas[$hoja][$fila] = $valores;
            $mapa = self::mapear($valores);
            $puntaje = count($mapa);
            if (isset($mapa['referencia']) && (isset($mapa['cajas']) || isset($mapa['cantidad'])) && $puntaje >= 3
                && $puntaje > ($candidatas[$hoja]['puntaje'] ?? 0)) {
                $candidatas[$hoja] = [
                    'hoja' => $hoja,
                    'fila' => $fila,
                    'encabezados' => array_map(fn ($v) => is_scalar($v) ? trim(preg_replace('/\s+/', ' ', (string) $v)) : '', $valores),
                    'mapa' => $mapa,
                    'puntaje' => $puntaje,
                ];
            }

            return true;
        });

        // Los encabezados no siempre caen encima de su dato: en la lista final de
        // Topwin «REF» está sobre la columna de la foto y las referencias van
        // bajo «FOTO». Se corrige mirando las primeras filas de datos.
        foreach ($candidatas as $hoja => $c) {
            $muestra = array_slice(array_filter($filas[$hoja], fn ($f) => $f > $c['fila'], ARRAY_FILTER_USE_KEY), 0, 15);
            $candidatas[$hoja]['mapa'] = self::ajustarConDatos($c['mapa'], $muestra);
        }

        usort($candidatas, fn ($a, $b) => [$b['puntaje'], self::esEmpaque($b['hoja'])] <=> [$a['puntaje'], self::esEmpaque($a['hoja'])]);

        return array_values($candidatas);
    }

    /**
     * Lee las filas de una hoja con un mapa de columnas (el detectado o el que
     * corrigió el usuario).
     *
     * @param  array<string,int>  $mapa  campo → índice de columna
     * @return array{lineas: list<array>, totales: array<string,float>, avisos: list<string>}
     */
    public function leer(string $ruta, string $hoja, int $filaEncabezado, array $mapa): array
    {
        if (! isset($mapa['referencia']) || (! isset($mapa['cajas']) && ! isset($mapa['cantidad']))) {
            throw new RuntimeException('Hace falta al menos la columna de referencia y la de cajas o cantidad.');
        }

        $lineas = [];
        $this->recorrer($ruta, function (string $nombre, int $fila, array $valores) use ($hoja, $filaEncabezado, $mapa, &$lineas) {
            if ($nombre !== $hoja || $fila <= $filaEncabezado) {
                return true;
            }
            if ($linea = self::linea($mapa, $valores, $fila)) {
                $lineas[] = $linea;
                if (count($lineas) > self::MAX_FILAS) {
                    throw new RuntimeException('La lista pasa de '.self::MAX_FILAS.' líneas.');
                }
            }

            return true;
        }, $hoja);

        $avisos = [];
        if (! $lineas) {
            $avisos[] = 'No encontré filas con referencia y cajas o cantidad debajo de los encabezados.';
        }
        if ($sinCbm = count(array_filter($lineas, fn ($l) => $l['cbm_total'] === null))) {
            $avisos[] = "{$sinCbm} línea(s) sin m³: revise la columna de metros cúbicos o las medidas de la caja.";
        }

        return [
            'lineas' => $lineas,
            'totales' => [
                'cajas' => array_sum(array_column($lineas, 'cajas')),
                'cantidad' => array_sum(array_column($lineas, 'cantidad')),
                'cbm' => round(array_sum(array_map(fn ($l) => $l['cbm_total'] ?? 0, $lineas)), 6),
                'peso' => round(array_sum(array_map(fn ($l) => $l['peso_total'] ?? 0, $lineas)), 2),
            ],
            'avisos' => $avisos,
        ];
    }

    /** Huella de los encabezados, para reconocer el formato del proveedor la próxima vez. */
    public static function firma(array $encabezados): string
    {
        return sha1(implode('|', array_map(fn ($v) => is_scalar($v) ? self::normalizar((string) $v) : '', $encabezados)));
    }

    // ───────────────────────── internos ─────────────────────────

    /** @param  callable(string $hoja, int $fila, array $valores): bool  $porFila  false = saltar al resto de la hoja */
    private function recorrer(string $ruta, callable $porFila, ?string $soloHoja = null): void
    {
        $reader = new Reader();
        $reader->open($ruta);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $hoja = $sheet->getName();
                if ($soloHoja !== null && $hoja !== $soloHoja) {
                    continue;
                }
                // La clave del iterador cuenta desde 0; la fila de Excel, desde 1.
                foreach ($sheet->getRowIterator() as $indice => $row) {
                    $valores = array_map(fn (Cell $c) => $c instanceof Cell\FormulaCell ? $c->getComputedValue() : $c->getValue(), $row->getCells());
                    if ($porFila($hoja, $indice + 1, $valores) === false) {
                        break;
                    }
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Si la columna de referencia viene vacía en los datos y una vecina sin
     * asignar trae los códigos, la referencia es la vecina.
     *
     * @param  array<string,int>  $mapa
     * @param  list<array>  $muestra  filas de datos bajo el encabezado
     * @return array<string,int>
     */
    private static function ajustarConDatos(array $mapa, array $muestra): array
    {
        if (! $muestra || ! isset($mapa['referencia'])) {
            return $mapa;
        }
        $llenas = fn (int $i) => count(array_filter($muestra, fn ($f) => self::texto($f[$i] ?? null) !== null && ! is_numeric($f[$i] ?? null)));
        $actual = $mapa['referencia'];
        if ($llenas($actual) >= count($muestra) / 2) {
            return $mapa;
        }
        $usadas = array_flip($mapa);
        foreach ([$actual - 1, $actual + 1] as $vecina) {
            if ($vecina >= 0 && ! isset($usadas[$vecina]) && $llenas($vecina) >= count($muestra) / 2) {
                $mapa['referencia'] = $vecina;
                break;
            }
        }

        return $mapa;
    }

    /** @return array<string,int> */
    private static function mapear(array $valores): array
    {
        $normalizados = array_map(fn ($v) => is_scalar($v) ? self::normalizar((string) $v) : '', $valores);
        $mapa = [];
        $usadas = [];
        foreach (self::CAMPOS as $campo => $alias) {
            foreach ($alias as $a) {
                foreach ($normalizados as $i => $n) {
                    if ($n === $a && ! isset($usadas[$i])) {
                        $mapa[$campo] = $i;
                        $usadas[$i] = true;
                        break 2;
                    }
                }
            }
        }

        return $mapa;
    }

    private static function linea(array $mapa, array $valores, int $fila): ?array
    {
        $col = fn (string $campo) => isset($mapa[$campo]) ? ($valores[$mapa[$campo]] ?? null) : null;

        $ref = self::texto($col('referencia'));
        $descripcion = self::texto($col('descripcion'));
        $cajas = self::numero($col('cajas'));
        $porCaja = self::numero($col('unidades_por_caja'));
        $cantidad = self::numero($col('cantidad'));
        if ($cantidad <= 0 && $cajas > 0 && $porCaja > 0) {
            $cantidad = $cajas * $porCaja;
        }

        // Filas de título, totales, marcas de envío o vacías: sin referencia o sin nada que contar.
        if ($ref === null || ($cajas <= 0 && $cantidad <= 0) || str_contains(self::normalizar($ref), 'TOTAL')) {
            return null;
        }

        [$largo, $ancho, $alto] = [self::numero($col('largo')), self::numero($col('ancho')), self::numero($col('alto'))];
        $cbmMedidas = ($largo > 0 && $ancho > 0 && $alto > 0) ? $largo * $ancho * $alto / 1_000_000 : null;

        $cbmCaja = self::numero($col('cbm_caja'));
        $cbmTotal = self::numero($col('cbm_total'));
        if ($cbmTotal <= 0 && $cbmCaja > 0) {
            $cbmTotal = $cajas > 0 ? $cbmCaja * $cajas : 0;
        }
        // Una sola columna de m³ que en realidad era por caja (o al revés): las
        // medidas dicen cuál cuadra.
        if ($cbmMedidas && $cajas > 1 && $cbmTotal > 0
            && abs($cbmTotal - $cbmMedidas) < abs($cbmTotal - $cbmMedidas * $cajas)) {
            $cbmTotal *= $cajas;
        }
        if ($cbmTotal <= 0 && $cbmMedidas && $cajas > 0) {
            $cbmTotal = $cbmMedidas * $cajas;
        }

        $pesoTotal = self::numero($col('peso_total'));
        $pesoCaja = self::numero($col('peso_caja'));
        if ($pesoTotal <= 0 && $pesoCaja > 0 && $cajas > 0) {
            $pesoTotal = $pesoCaja * $cajas;
        }

        return [
            'fila' => $fila,
            'referencia' => $ref,
            'descripcion' => $descripcion !== null ? mb_substr($descripcion, 0, 200) : null,
            'cajas' => (int) round($cajas),
            'unidades_por_caja' => $porCaja > 0 ? (int) round($porCaja) : ($cajas > 0 && $cantidad > 0 ? (int) round($cantidad / $cajas) : null),
            'cantidad' => round($cantidad, 2),
            'precio_unit' => ($p = self::numero($col('precio'))) > 0 ? round($p, 4) : null,
            'cbm_total' => $cbmTotal > 0 ? round($cbmTotal, 6) : null,
            'peso_total' => $pesoTotal > 0 ? round($pesoTotal, 2) : null,
        ];
    }

    private static function esEmpaque(string $hoja): int
    {
        $h = self::normalizar($hoja);

        return (int) (str_contains($h, 'PACKING') || str_contains($h, 'EMPAQUE') || str_contains($h, 'LIST'));
    }

    private static function normalizar(string $v): string
    {
        $v = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $v)));

        return strtr($v, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', '：' => ':']);
    }

    private static function numero(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric($n = str_replace(',', '.', trim($v)))) {
            return (float) $n;
        }

        return 0.0;
    }

    private static function texto(mixed $v): ?string
    {
        if ($v === null || is_bool($v) || $v instanceof \DateTimeInterface) {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', (string) $v));
        if ($v === '' || in_array(mb_strtoupper($v), ['N/A', 'N/M', '-'], true)) {
            return null;
        }
        // Sin fórmulas inyectadas (mismo criterio que los otros lectores).
        if (in_array(mb_substr($v, 0, 1), ['=', '+', '-', '@', "\t", "\r"], true)) {
            $v = "'".$v;
        }

        return mb_substr($v, 0, 150);
    }
}
