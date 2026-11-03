<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\OrdenCompraItem;
use App\Modules\Compras\Services\CostoImportacion;
use App\Modules\Dropi\Models\Producto;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Da producto a las líneas de una orden de importación que no lo tienen.
 *
 * El Excel de Alejandro trae referencias nuevas en cada contenedor, y una línea sin
 * producto se recibe pero no sube al inventario. La misma referencia es el mismo
 * producto: si ya existe se usa, y sólo se crea cuando no está.
 */
class CrearProductosDeOrden
{
    use AsAction;

    /**
     * @param  array<int, string>  $referencias  orden_item_id => referencia, para las líneas que no la traen
     * @return int cuántas líneas quedaron con producto
     */
    public function handle(OrdenCompra $orden, array $referencias = []): int
    {
        $orden->loadMissing('items');
        $resueltas = 0;

        foreach ($orden->items as $item) {
            if ($item->producto_id || $item->variante_id) {
                continue;
            }

            $ref = trim((string) ($referencias[$item->id] ?? '')) ?: self::referenciaDe($item);
            if (! $ref) {
                continue;
            }

            $producto = Producto::buscarPorReferencia($ref)
                ?? Producto::sinKardexAutomatico(fn () => Producto::create([
                    'referencia' => $ref,
                    'nombre' => self::nombreDe($item, $ref),
                    'precio_proveedor' => $this->costoEnPesos($item, $orden),
                    'activo' => true,
                    'desglose_stock' => false,
                ]));

            $item->producto_id = $producto->id;
            $item->save();
            $resueltas++;
        }

        return $resueltas;
    }

    /**
     * La referencia viene al principio de la descripción («AB-0085 · oso beige»). Las
     * filas sin ella dicen «Sin REF (NUEVA ORDEN fila 6)», y una referencia real
     * siempre trae algún número: eso evita tomar «Tetero» por una.
     */
    public static function referenciaDe(OrdenCompraItem $item): ?string
    {
        $inicio = trim(explode(' · ', (string) $item->descripcion)[0]);

        return preg_match('/^(?=.*\d)[\w\-.\/]+$/u', $inicio) ? $inicio : null;
    }

    private static function nombreDe(OrdenCompraItem $item, string $ref): string
    {
        $partes = explode(' · ', (string) $item->descripcion, 2);
        $detalle = trim($partes[1] ?? '');

        return mb_substr($detalle !== '' ? $detalle : $ref, 0, 200);
    }

    private function costoEnPesos(OrdenCompraItem $item, OrdenCompra $orden): float
    {
        try {
            return app(CostoImportacion::class)->fobUnitario($item, $orden);
        } catch (InvalidArgumentException) {
            return 0.0;
        }
    }
}
