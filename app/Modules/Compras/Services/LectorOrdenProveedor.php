<?php

namespace App\Modules\Compras\Services;

use App\Models\EmpresaConfig;
use App\Modules\Dropi\Models\Producto;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Lee la orden de pedido TAL COMO la arma Alejandro en Excel (hoja «NUEVA
 * ORDEN»: REF, FOTO, CONTACTO, DESCRIPCION, CAJAS, CTN/CAJA, PRECIO, M1-M3,
 * CBM… y a la derecha su pre-liquidación) y la convierte en líneas para el
 * formulario de la OC. No guarda nada: él revisa y guarda.
 *
 * A diferencia de `ImportadorOrdenesCompra` no exige una plantilla propia:
 *   - La fila de encabezados se busca (en su archivo es la 3, con título arriba).
 *   - Las celdas con fórmula se leen por su valor calculado (`CBM` = M1×M2×M3).
 *   - El proveedor se elige en el formulario, así que el archivo no trae NIT.
 *   - Una hoja cuyo nombre diga «DROP» es lo del e-commerce de Aracely.
 *   - La referencia que no está en el catálogo entra igual, sin producto.
 *
 * Las cantidades y el valor salen de CAJAS × CTN/CAJA × PRECIO, no de su
 * columna «TOTAL RMB»: en el archivo del 24-oct las primeras filas la
 * calculan con otra fórmula (×1,16×560) y el total no cuadra.
 */
class LectorOrdenProveedor
{
    private const MAX_FILAS = 2000;

    /** Encabezado normalizado → campo. El primero que aparezca gana. */
    private const COLUMNAS = [
        'ref' => ['REF', 'REFERENCIA', 'ITEM', 'ITEM NO', 'ARTICULO'],
        'contacto' => ['CONTACTO'],
        'descripcion' => ['DESCRIPCION', 'DESCRIPTION', 'NOMBRE'],
        'cajas' => ['CAJAS', 'CTNS', 'CARTONS', 'BULTOS'],
        'unidades_por_caja' => ['CTN/CAJA', 'UDS/CAJA', 'UNIDADES/CAJA', 'PCS/CTN', 'QTY/CTN'],
        'precio' => ['PRECIO', 'PRICE', 'PRECIO RMB', 'UNIT PRICE'],
        'largo' => ['M1', 'LARGO'],
        'ancho' => ['M2', 'ANCHO'],
        'alto' => ['M3', 'ALTO'],
        'cbm' => ['CBM', 'CBM/CTN', 'CBM CAJA'],
        'peso' => ['PESO', 'G.W.', 'GW', 'PESO BRUTO'],
    ];

    /**
     * @return array{lineas:array<int,array>, avisos:array<int,string>}
     */
    public function leer(string $ruta): array
    {
        $reader = new Reader();
        $reader->open($ruta);

        $ecommerce = EmpresaConfig::query()->where('codigo', 'ecommerce')->value('id');
        $lineas = [];
        $avisos = [];
        $hojasLeidas = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $hoja = $sheet->getName();
                $esDrop = str_contains(mb_strtoupper($hoja), 'DROP');
                $mapa = null;

                // La clave del iterador cuenta desde 0; la fila de Excel, desde 1.
                foreach ($sheet->getRowIterator() as $indice => $row) {
                    $n = $indice + 1;
                    $valores = array_map(fn (Cell $c) => self::valor($c), $row->getCells());

                    if ($mapa === null) {
                        $mapa = self::buscarEncabezados($valores);
                        continue;
                    }

                    $linea = $this->linea($mapa, $valores, $hoja, $n);
                    if ($linea === null) {
                        continue;
                    }
                    if ($esDrop && $ecommerce) {
                        $linea['empresa_destino_id'] = $ecommerce;
                    }
                    $lineas[] = $linea;

                    if (count($lineas) > self::MAX_FILAS) {
                        throw new \RuntimeException('El archivo pasa de ' . self::MAX_FILAS . ' líneas.');
                    }
                }

                if ($mapa !== null) {
                    $hojasLeidas++;
                }
            }
        } finally {
            $reader->close();
        }

        if ($hojasLeidas === 0) {
            throw new \RuntimeException('No encontré la fila de encabezados (REF, CAJAS, CTN/CAJA, PRECIO) en ninguna hoja.');
        }
        if ($sinRef = count(array_filter($lineas, fn ($l) => $l['referencia'] === null))) {
            $avisos[] = "{$sinRef} fila(s) sin REF: entran con el número de fila; mira la foto en el Excel para saber qué son.";
        }
        if ($sinProducto = count(array_filter($lineas, fn ($l) => $l['referencia'] !== null && ! $l['producto_id']))) {
            $avisos[] = "{$sinProducto} referencia(s) no están en el catálogo: entran sin producto y no van a sumar stock al recibir.";
        }

        return ['lineas' => $lineas, 'avisos' => $avisos];
    }

    /**
     * Valor de la celda; si es fórmula, el que Excel dejó calculado.
     */
    private static function valor(Cell $c): mixed
    {
        return $c instanceof Cell\FormulaCell ? $c->getComputedValue() : $c->getValue();
    }

    /** @return array<string,int>|null campo → índice de columna */
    private static function buscarEncabezados(array $valores): ?array
    {
        $normalizados = array_map(fn ($v) => self::normalizar((string) $v), $valores);
        $mapa = [];
        foreach (self::COLUMNAS as $campo => $alias) {
            foreach ($alias as $a) {
                $i = array_search($a, $normalizados, true);
                if ($i !== false) {
                    $mapa[$campo] = $i;
                    break;
                }
            }
        }

        foreach (['cajas', 'unidades_por_caja', 'precio'] as $obligatorio) {
            if (! isset($mapa[$obligatorio])) {
                return null;
            }
        }

        return $mapa;
    }

    private function linea(array $mapa, array $valores, string $hoja, int $fila): ?array
    {
        $col = fn (string $campo) => isset($mapa[$campo]) ? ($valores[$mapa[$campo]] ?? null) : null;

        $cajas = self::numero($col('cajas'));
        $uds = self::numero($col('unidades_por_caja'));
        // La fila de totales y las vacías no traen cajas.
        if ($cajas <= 0 || $uds <= 0) {
            return null;
        }

        $ref = self::texto($col('ref'));
        $detalle = trim(implode(' · ', array_filter([self::texto($col('descripcion')), self::texto($col('contacto'))])));
        $descripcion = $ref
            ? trim($ref . ($detalle !== '' ? " · {$detalle}" : ''))
            : "Sin REF ({$hoja} fila {$fila})" . ($detalle !== '' ? " · {$detalle}" : '');

        $largo = self::numero($col('largo'));
        $ancho = self::numero($col('ancho'));
        $alto = self::numero($col('alto'));
        $cbm = self::numero($col('cbm'));
        $conMedidas = $largo > 0 && $ancho > 0 && $alto > 0;

        $producto = $ref ? Producto::query()->where('referencia', $ref)->first(['id', 'nombre']) : null;

        return [
            'referencia' => $ref,
            'producto_id' => $producto?->id,
            'variante_id' => null,
            'descripcion' => mb_substr($producto ? "{$ref} · {$producto->nombre}" . ($detalle !== '' ? " · {$detalle}" : '') : $descripcion, 0, 200),
            'cajas' => (int) round($cajas),
            'unidades_por_caja' => (int) round($uds),
            'cantidad' => (int) round($cajas) * (int) round($uds),
            'precio_unit' => round(self::numero($col('precio')), 4),
            'caja_largo_cm' => $conMedidas ? $largo : null,
            'caja_ancho_cm' => $conMedidas ? $ancho : null,
            'caja_alto_cm' => $conMedidas ? $alto : null,
            // Manda su columna CBM: en el archivo del 24-oct hay filas con medidas
            // y un CBM escrito encima (0,3) que no es M1×M2×M3, y su total de 74,2 m³
            // sale de ese CBM. Las medidas sólo lo llenan cuando viene vacío.
            'cbm_caja' => $cbm > 0 ? round($cbm, 6) : ($conMedidas ? round($largo * $ancho * $alto / 1_000_000, 6) : null),
            'peso_caja_kg' => ($p = self::numero($col('peso'))) > 0 ? $p : null,
            'empresa_destino_id' => null,
            // Importación: el IVA se paga en aduana y ya va en la pre-liquidación.
            'iva_pct' => 0,
            'descuento_pct' => 0,
        ];
    }

    private static function normalizar(string $v): string
    {
        $v = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $v)));

        return strtr($v, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
    }

    private static function numero(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric(str_replace(',', '.', trim($v)))) {
            return (float) str_replace(',', '.', trim($v));
        }

        return 0.0;
    }

    /** Texto limpio y sin fórmulas inyectadas (mismo criterio que el importador viejo). */
    private static function texto(mixed $v): ?string
    {
        if ($v === null || is_bool($v)) {
            return null;
        }
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (in_array(mb_substr($v, 0, 1), ['=', '+', '-', '@', "\t", "\r"], true)) {
            $v = "'" . $v;
        }

        return mb_substr($v, 0, 150);
    }
}
