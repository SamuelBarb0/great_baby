<?php

namespace App\Modules\Siigo\Services;

use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * UBIC-10 · Genera el archivo Excel que SIIGO acepta en su pantalla
 * "Saldos iniciales de inventario" (/initial-balance-inventory).
 *
 * Opción B del plan: el ERP exporta → Aracely tilda "Ingresar saldos por
 * Excel" en SIIGO → pega el archivo. Más seguro que el push automático porque
 * contabilidad revisa antes de contabilizar y la fecha la elige SIIGO.
 *
 * Formato nativo SIIGO (según su UI):
 *   Columna A: Producto · código único (coincide con el `code` de /v1/products)
 *   Columna B: Bodega · nombre de la warehouse
 *   Columna C: Cantidad
 *   Columna D: Costo unitario
 *   Columna E (nuestra): Costo total (= C × D) · opcional, SIIGO ignora
 *
 * El servicio decide por cada SKU si el stock se agrega por variante
 * (código de barras) o por producto agregado (referencia), y
 * **sólo exporta ubicaciones que estén mapeadas a una warehouse SIIGO**
 * (siigo_id no nulo) · si no, SIIGO no sabría a dónde cargar el saldo.
 */
class ExportSaldosInicialesService
{
    /**
     * Devuelve ruta temporal al xlsx generado.
     * $soloCategorias filtra por categorías de ubicación (ej. ['venta'] para
     * excluir avería/cuarentena del saldo que viaja a contabilidad).
     */
    public function generar(array $soloCategorias = ['venta']): string
    {
        // 1. Saldos a nivel (variante|producto) × ubicación.
        $rows = DB::table('inventario_movimientos as m')
            ->join('inventario_ubicaciones as u', 'u.id', '=', 'm.ubicacion_id')
            ->whereNotNull('u.siigo_id')          // bodegas sincronizadas con SIIGO
            ->where('u.activa', true)
            ->whereIn('u.categoria', $soloCategorias)
            // INV-A6 · un movimiento anulado no es saldo. El kardex se borra
            // suave a propósito, y `DB::table()` no sabe de `deleted_at`: sin
            // esto, el stock fantasma que `inventario:reparar-kardex` anuló
            // viaja en el Excel y entra a SIIGO como saldo de apertura real.
            ->whereNull('m.deleted_at')
            ->selectRaw(
                "COALESCE(m.variante_id, 0) as variante_id, "
                ."COALESCE(m.producto_id, 0) as producto_id, "
                ."u.id as ubicacion_id, u.codigo as ubic_codigo, u.nombre as ubic_nombre, "
                ."u.siigo_id as warehouse_id, "
                ."SUM(m.cantidad) as saldo, "
                ."CASE WHEN SUM(m.cantidad) > 0 "
                ."THEN SUM(CASE WHEN m.cantidad > 0 THEN m.cantidad * COALESCE(m.costo_unit, 0) ELSE 0 END) "
                ."     / NULLIF(SUM(CASE WHEN m.cantidad > 0 THEN m.cantidad ELSE 0 END), 0) "
                ."ELSE 0 END as costo_prom"
            )
            // FIX · agrupar por las columnas reales (ONLY_FULL_GROUP_BY MySQL)
            ->groupBy('m.variante_id', 'm.producto_id', 'u.id', 'u.codigo', 'u.nombre', 'u.siigo_id')
            ->havingRaw('SUM(m.cantidad) > 0')
            ->get();

        if ($rows->isEmpty()) {
            // Documento válido pero vacío · el UI avisa al descargar.
        }

        // 2. Resolvemos códigos SIIGO.
        $varIds = $rows->pluck('variante_id')->filter()->unique()->all();
        $prodIds = $rows->pluck('producto_id')->filter()->unique()->all();
        $varMap = ProductoVariante::whereIn('id', $varIds)
            ->with('producto:id,nombre,siigo_code,referencia')
            ->get()->keyBy('id');
        $prodMap = Producto::whereIn('id', $prodIds)
            ->get(['id', 'nombre', 'siigo_code', 'referencia'])->keyBy('id');

        // 3. Armamos el Excel con layout SIIGO.
        $xls = new Spreadsheet();
        $s = $xls->getActiveSheet();
        $s->setTitle('Saldos iniciales');

        $cab = ['Producto', 'Bodega', 'Cantidad', 'Costo unitario', 'Costo total'];
        foreach ($cab as $i => $c) $s->setCellValue(chr(65 + $i).'1', $c);

        $s->getStyle('A1:E1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F62FE']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $fila = 2;
        $totalValor = 0;
        $sinCodigo = 0;

        foreach ($rows as $r) {
            [$code, $nombre] = $this->resolverCodigoYNombre($r, $varMap, $prodMap);
            if ($code === null) { $sinCodigo++; continue; }

            $cant = (float) $r->saldo;
            $costo = round((float) $r->costo_prom, 2);
            $subtotal = round($cant * $costo, 2);
            $totalValor += $subtotal;

            $s->setCellValueExplicit("A{$fila}", $code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $s->setCellValue("B{$fila}", $r->ubic_nombre);
            $s->setCellValue("C{$fila}", $cant);
            $s->setCellValue("D{$fila}", $costo);
            $s->setCellValue("E{$fila}", $subtotal);
            // Comentario con detalle del producto para que Aracely identifique.
            $s->getComment("A{$fila}")->getText()->createText($nombre ?? '(sin nombre)');
            $fila++;
        }

        foreach (['A', 'B', 'C', 'D', 'E'] as $c) $s->getColumnDimension($c)->setAutoSize(true);
        $s->getStyle('C2:E'.($fila - 1))->getNumberFormat()->setFormatCode('#,##0.00');

        if ($fila > 2) {
            $s->getStyle("A1:E".($fila - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
            ]);
        }

        // Fila total informativa.
        $s->setCellValue("D{$fila}", 'Total valorizado');
        $s->setCellValue("E{$fila}", round($totalValor, 2));
        $s->getStyle("D{$fila}:E{$fila}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F0FF']],
        ]);

        // 4. Hoja de instrucciones para Aracely.
        $ins = $xls->createSheet();
        $ins->setTitle('Cómo cargarlo en SIIGO');
        $this->escribirInstrucciones($ins, $rows->count(), $sinCodigo, $totalValor);

        $tmp = tempnam(sys_get_temp_dir(), 'saldos_iniciales_siigo_').'.xlsx';
        (new Xlsx($xls))->save($tmp);
        return $tmp;
    }

    /**
     * Resuelve el (código SIIGO, nombre) de la fila:
     *   · Variante → `siigo_code` del variante, o código de barras si falta.
     *   · Producto agregado → `siigo_code` del producto, o referencia.
     * Devuelve [null, null] si el producto no tiene ningún identificador útil.
     */
    private function resolverCodigoYNombre($r, $varMap, $prodMap): array
    {
        if ($r->variante_id && ($v = $varMap->get($r->variante_id))) {
            $code = $v->getAttribute('siigo_code') ?: $v->codigo_barras;
            $nombre = $v->producto?->nombre.' · '.trim(($v->color_nombre ?? '').' '.($v->talla ?? ''));
            return [$code, $nombre];
        }
        if ($r->producto_id && ($p = $prodMap->get($r->producto_id))) {
            $code = $p->siigo_code ?: $p->referencia;
            return [$code, $p->nombre];
        }
        return [null, null];
    }

    private function escribirInstrucciones($s, int $filasExportadas, int $sinCodigo, float $totalValor): void
    {
        $lineas = [
            'SALDOS INICIALES DE INVENTARIO · GREAT BABY → SIIGO',
            '',
            'Generado el '.now('America/Bogota')->format('d/m/Y H:i'),
            'Filas con stock: '.$filasExportadas,
            'Valor total: $'.number_format($totalValor, 2),
            $sinCodigo > 0 ? "⚠ {$sinCodigo} productos quedaron fuera porque no tienen código SIIGO (siigo_code ni referencia). Sincronizalos antes de cargar." : '',
            '',
            'PASOS EN SIIGO:',
            '',
            '1. Entrá a SIIGO Nube → menú Inventario → Saldos iniciales de inventario.',
            '2. Elegí la fecha de corte (recomendado: fin de mes anterior).',
            '3. Dejá "Moneda" en COP · Peso colombiano.',
            '4. Marcá la casilla "Ingresar saldos por Excel".',
            '5. Descargá la plantilla de SIIGO para comparar que las columnas coincidan: Producto / Bodega / Cantidad / Costo unitario.',
            '6. Copiá los datos de la hoja "Saldos iniciales" de ESTE archivo (A2 hacia abajo, columnas A-D).',
            '7. Pegalos en la plantilla de SIIGO y cargala.',
            '8. SIIGO va a cuadrar automático contra "Saldos iniciales por conciliar". Luego contabilidad hace el ajuste con el PUC real.',
            '',
            'IMPORTANTE:',
            '',
            '- Solo se exportaron ubicaciones mapeadas a una warehouse SIIGO (siigo_id ≠ NULL).',
            '- Solo se exportan ubicaciones de categoría "venta" (apto para comercializar).',
            '- La columna E (Costo total) es informativa · SIIGO solo usa A-D.',
            '- El costo es el PROMEDIO PONDERADO del kardex local.',
            '- Si una fila sale con cantidad 0 o costo 0, revisá el kardex.',
        ];
        foreach ($lineas as $i => $l) $s->setCellValue('A'.($i + 1), $l);
        $s->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'size' => 14]]);
        $s->getColumnDimension('A')->setWidth(100);
    }
}
