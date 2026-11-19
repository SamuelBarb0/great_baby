<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Dropi\Actions\EjecutarOperacionDropi;
use App\Modules\Dropi\Models\DropiCargaOperacion as Op;
use App\Modules\Dropi\Models\DropiCargaProducto as Carga;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * El resultado de una carga a Dropi en Excel, para el equipo: qué se
 * actualizó, qué se creó y qué no (con el porqué), la razón de cada fila y lo
 * que quedó en Dropi variación por variación. Lo pendiente se completa a mano
 * en la pantalla de la carga.
 */
class ExportarCargaDropi
{
    private const COLORES = [
        'Actualizado' => 'D1FAE5', 'Creado' => 'D1FAE5', 'Error' => 'FEE2E2',
        'Pendiente: revisar' => 'FEF3C7', 'Pendiente: crear' => 'FEF3C7',
        'Omitido' => 'E5E7EB', 'Listo, sin mandar' => 'DBEAFE',
    ];

    public function libro(Carga $carga): Spreadsheet
    {
        $libro = new Spreadsheet();
        $filas = $carga->operaciones->map(fn (Op $o) => $this->fila($carga, $o));

        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Productos');
        $encabezado = ['Fila Excel', 'Referencia', 'Producto (Excel)', 'Cantidad Excel', 'Variaciones Excel', 'Resultado',
            'Qué falta / por qué', 'Id Dropi', 'Producto en Dropi', 'Qué se movió en Dropi', 'Razón', 'Razón que quedó en Dropi', 'Inventario del ERP'];
        $hoja->fromArray($encabezado, null, 'A1');
        $hoja->fromArray($filas->map(fn ($f) => array_values($f))->all(), null, 'A2');

        $ultima = $filas->count() + 1;
        foreach ($filas->values() as $i => $f) {
            $color = self::COLORES[$f['resultado']] ?? null;
            if ($color) {
                $hoja->getStyle('F' . ($i + 2))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
            }
        }
        foreach (['A' => 9, 'B' => 18, 'C' => 34, 'D' => 10, 'E' => 32, 'F' => 18, 'G' => 50, 'H' => 10, 'I' => 34, 'J' => 50, 'K' => 34, 'L' => 40, 'M' => 34] as $col => $w) {
            $hoja->getColumnDimension($col)->setWidth($w);
        }
        $hoja->getStyle('A1:M1')->getFont()->setBold(true);
        $hoja->getStyle("A2:M{$ultima}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $hoja->setAutoFilter("A1:M{$ultima}");
        $hoja->freezePane('A2');

        $resumen = $libro->createSheet(0);
        $resumen->setTitle('Resumen');
        $conteo = $filas->countBy('resultado');
        $datos = [
            ['Carga', $carga->numero],
            ['Concepto', $carga->concepto],
            ['Modo', $carga->modo === 'sumar' ? 'Las cantidades del Excel se suman a lo que hay en Dropi' : 'El Excel dice cuánto debe quedar en Dropi'],
            ['Archivo', $carga->archivo_nombre ?? '—'],
            ['Fecha', $carga->created_at?->timezone('America/Bogota')->format('d/m/Y H:i')],
            ['Terminada', $carga->terminado_at?->timezone('America/Bogota')->format('d/m/Y H:i') ?? 'No'],
            ['Inventario del ERP', $carga->cargaInventario
                ? "{$carga->cargaInventario->numero} · " . (float) $carga->cargaInventario->total_unidades . ' unidades · asiento por $' . number_format((float) $carga->cargaInventario->total_valor, 0, ',', '.')
                    . ' · ' . (int) ($carga->ingreso_erp['resultado']['creados'] ?? 0) . ' productos creados en el ERP'
                : (! empty($carga->ingreso_erp['activo']) ? 'Pendiente: se registra al confirmar' : 'No se registró (solo Dropi)')],
            [],
            ['Resultado', 'Filas'],
        ];
        foreach (array_keys(self::COLORES) as $r) {
            if ($conteo->get($r)) {
                $datos[] = [$r, $conteo->get($r)];
            }
        }
        $datos[] = ['Total', $filas->count()];
        $datos[] = [];
        $datos[] = ['Lo pendiente se completa en la pantalla de la carga (Dropi → Productos y stock en Dropi). La razón de cada fila se puede editar allí en cualquier momento.'];
        $resumen->fromArray($datos, null, 'A1');
        $resumen->getColumnDimension('A')->setWidth(28);
        $resumen->getColumnDimension('B')->setWidth(70);
        $resumen->getStyle('A1:A7')->getFont()->setBold(true);
        $resumen->getStyle('A9:B9')->getFont()->setBold(true);
        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    /** @return array<string, mixed> */
    private function fila(Carga $carga, Op $o): array
    {
        $d = $o->detalle ?? [];
        $resultado = match (true) {
            $o->estado === Op::OK && $o->tipo === Op::CREAR => 'Creado',
            $o->estado === Op::OK => 'Actualizado',
            $o->estado === Op::ERROR => 'Error',
            $o->estado === Op::OMITIDO => 'Omitido',
            $o->estado === Op::LISTO => 'Listo, sin mandar',
            $o->tipo === Op::CREAR => 'Pendiente: crear',
            default => 'Pendiente: revisar',
        };

        $movido = collect($d['lineas'] ?? [])
            ->filter(fn ($l) => ($l['movimiento'] ?? null) !== null)
            ->map(fn ($l) => $l['movimiento'] === 'NINGUNO'
                ? "{$l['etiqueta']}: sin cambio ({$l['antes']})"
                : "{$l['etiqueta']}: {$l['movimiento']} {$l['unidades']} ({$l['antes']} → " . ($l['despues'] ?? '?') . ')')
            ->implode("\n");

        $falta = $o->error ?: ($o->estado === Op::OK ? ($o->motivo ?? '') : ($o->motivo ?? ''));
        if ($o->estado === Op::REVISAR && $o->candidato) {
            $falta = trim($falta . "\nCandidato en Dropi: #{$o->candidato['id']} {$o->candidato['nombre']}");
        }

        return [
            'fila' => $o->fila,
            'ref' => $o->ref_excel,
            'nombre' => $o->nombre_excel,
            'cantidad' => $o->cantidad_excel,
            'variaciones' => $o->variaciones_excel,
            'resultado' => $resultado,
            'falta' => $falta,
            'dropi_id' => $o->dropi_producto_id,
            'dropi_nombre' => $o->dropi_nombre ?? ($o->tipo === Op::CREAR ? ($d['nombre'] ?? null) : null),
            'movido' => $movido,
            'razon' => EjecutarOperacionDropi::razon($carga, $o),
            'razon_enviada' => $o->razon_enviada,
            'erp' => $carga->ingreso_erp['resultado']['filas'][$o->ref_excel] ?? '',
        ];
    }
}
