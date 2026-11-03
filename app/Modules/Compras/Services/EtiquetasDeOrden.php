<?php

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Actions\CrearProductosDeOrden;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\OrdenCompraItem;
use App\Modules\Dropi\Models\Producto;
use App\Support\Code128;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Las dos cosas que van pegadas en cada caja: la etiqueta de importación y el código
 * de barras. Van separadas porque meter el código en la etiqueta obligaría a otra
 * legalización (reunión con Alejandro, 21-oct-2026).
 */
class EtiquetasDeOrden
{
    private const COLUMNAS = ['Referencia', 'Descripción', 'Cajas', 'Uds/caja', 'CBM caja', 'Peso caja kg', 'Cantidad', 'Precio', 'Total', 'Etiqueta', 'Código de barras'];

    /** @return array{con: int, sin: int} */
    public function resumen(OrdenCompra $orden): array
    {
        $productos = $orden->items->map(fn ($i) => $i->producto)->filter();

        return [
            'con' => $productos->filter(fn (Producto $p) => $p->getFirstMedia('etiqueta_importacion'))->count(),
            'sin' => $orden->items->count() - $productos->filter(fn (Producto $p) => $p->getFirstMedia('etiqueta_importacion'))->count(),
        ];
    }

    /** @return list<array{referencia: ?string, descripcion: string, cajas: int, etiqueta: ?string, barras: ?string}> imágenes en data URI */
    public function paraPdf(OrdenCompra $orden): array
    {
        return $orden->items->map(function (OrdenCompraItem $i) {
            $codigo = $this->codigoDe($i);
            $etiqueta = $i->producto?->getFirstMedia('etiqueta_importacion');

            return [
                'referencia' => $codigo,
                'descripcion' => (string) $i->descripcion,
                'cajas' => (int) $i->cajas,
                'etiqueta' => $etiqueta && is_file($etiqueta->getPath())
                    ? 'data:'.$etiqueta->mime_type.';base64,'.base64_encode((string) file_get_contents($etiqueta->getPath()))
                    : null,
                'barras' => $this->barras($codigo),
            ];
        })->all();
    }

    /** El Excel que va a China, con una hoja por destino y las imágenes ya puestas. */
    public function excel(OrdenCompra $orden): Spreadsheet
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);
        $principal = \App\Models\EmpresaConfig::current();

        $grupos = $orden->items->groupBy(fn ($i) => $i->empresaDestino?->nombreCorto() ?? $principal->nombreCorto());
        // La hoja de la empresa principal primero, como en el Excel de Alejandro.
        $grupos = $grupos->sortBy(fn ($v, $k) => $k === $principal->nombreCorto() ? 0 : 1);

        foreach ($grupos as $nombre => $items) {
            $this->hoja($libro, $orden, (string) $nombre, $items);
        }

        return $libro;
    }

    private function hoja(Spreadsheet $libro, OrdenCompra $orden, string $nombre, $items): void
    {
        // Excel no admite estos caracteres ni más de 31 en el nombre de una hoja.
        $titulo = mb_substr(str_replace(['*', ':', '/', '\\', '?', '[', ']'], ' ', $nombre), 0, 31);
        $hoja = new Worksheet($libro, $titulo);
        $libro->addSheet($hoja);

        $hoja->setCellValue('A1', "Orden {$orden->numero}");
        $hoja->setCellValue('A2', $orden->proveedor?->razon_social ?: $orden->proveedor?->nombre_completo);
        foreach (self::COLUMNAS as $c => $titulo) {
            $hoja->setCellValue([$c + 1, 4], $titulo);
        }
        $hoja->getStyle('A4:K4')->getFont()->setBold(true);
        $hoja->freezePane('A5');

        $fila = 5;
        foreach ($items as $i) {
            $codigo = $this->codigoDe($i);
            $hoja->setCellValueExplicit([1, $fila], (string) $codigo, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $hoja->setCellValue([2, $fila], $this->detalle($i, $codigo));
            $hoja->setCellValue([3, $fila], (int) $i->cajas);
            $hoja->setCellValue([4, $fila], (int) $i->unidades_por_caja);
            $hoja->setCellValue([5, $fila], (float) $i->cbm_caja);
            $hoja->setCellValue([6, $fila], (float) $i->peso_caja_kg);
            $hoja->setCellValue([7, $fila], (float) $i->cantidad);
            $hoja->setCellValue([8, $fila], (float) $i->precio_unit);
            $hoja->setCellValue([9, $fila], (float) $i->subtotal);
            $hoja->getRowDimension($fila)->setRowHeight(70);

            $etiqueta = $i->producto?->getFirstMedia('etiqueta_importacion');
            if ($etiqueta && is_file($etiqueta->getPath())) {
                $this->imagen($hoja, $etiqueta->getPath(), "J{$fila}");
            }
            $png = $this->pngBarras($codigo);
            if ($png) {
                $temporal = tempnam(sys_get_temp_dir(), 'bar').'.png';
                file_put_contents($temporal, $png);
                $this->imagen($hoja, $temporal, "K{$fila}");
            }
            $fila++;
        }

        $hoja->setCellValue([2, $fila], 'Total');
        $hoja->setCellValue([3, $fila], $items->sum(fn ($i) => (int) $i->cajas));
        $hoja->setCellValue([9, $fila], (float) $items->sum('subtotal'));
        $hoja->getStyle("A{$fila}:K{$fila}")->getFont()->setBold(true);

        foreach (['A' => 16, 'B' => 42, 'J' => 22, 'K' => 26] as $col => $ancho) {
            $hoja->getColumnDimension($col)->setWidth($ancho);
        }
    }

    private function imagen(Worksheet $hoja, string $ruta, string $celda): void
    {
        $d = new Drawing;
        $d->setPath($ruta);
        $d->setHeight(85);
        $d->setCoordinates($celda);
        $d->setOffsetX(4);
        $d->setOffsetY(4);
        $d->setWorksheet($hoja);
    }

    /** El código del sticker: el de la variante, el del producto o, si no, su referencia. */
    private function codigoDe(OrdenCompraItem $i): ?string
    {
        return $i->variante?->codigo_barras
            ?: $i->producto?->barcode_padre
            ?: $i->producto?->referencia
            ?: CrearProductosDeOrden::referenciaDe($i);
    }

    private function detalle(OrdenCompraItem $i, ?string $codigo): string
    {
        $descripcion = (string) $i->descripcion;
        $prefijo = $codigo ? "{$codigo} · " : '';

        return $prefijo && str_starts_with($descripcion, $prefijo) ? mb_substr($descripcion, mb_strlen($prefijo)) : $descripcion;
    }

    private function barras(?string $codigo): ?string
    {
        $png = $this->pngBarras($codigo);

        return $png ? 'data:image/png;base64,'.base64_encode($png) : null;
    }

    private function pngBarras(?string $codigo): ?string
    {
        if (! $codigo) {
            return null;
        }
        try {
            return Code128::png($codigo);
        } catch (Throwable) {
            return null;
        }
    }
}
