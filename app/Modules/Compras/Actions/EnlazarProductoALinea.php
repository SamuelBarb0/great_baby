<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Models\OrdenCompraItem;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Le pone producto del catálogo a una línea de orden que nació sin él.
 *
 * El caso real: llega la factura del proveedor con mercancía que todavía no
 * está en el catálogo. Hasta hoy esa línea se recibía, la orden se marcaba
 * recibida, el asiento contable se hacía… y no entraba una sola unidad al
 * inventario, en silencio, porque `RecibirMercancia` sólo escribe kardex si la
 * línea tiene producto o variante. Quien descargó no tenía forma de arreglarlo
 * desde donde estaba parado: tenía que irse al catálogo, crear el producto,
 * volver a la orden —que exige otra casilla de permiso— y recién ahí recibir.
 *
 * Hace una de dos cosas, y nunca las dos:
 *   · ENLAZAR, cuando la persona encontró el producto buscándolo;
 *   · CREAR, con lo mínimo que hace falta para que exista y se pueda mover.
 *
 * No borra ni reescribe un enlace que ya estaba: si la línea ya tiene
 * producto, se niega. Cambiar a qué producto apunta una línea que YA se
 * recibió movería stock de un producto a otro sin dejar rastro.
 */
class EnlazarProductoALinea
{
    use AsAction;

    /**
     * @param  array{producto_id?:?int, nombre?:?string, referencia?:?string,
     *     desglose_stock?:bool, color_nombre?:?string}  $datos
     */
    public function handle(OrdenCompraItem $item, array $datos): OrdenCompraItem
    {
        if ($item->producto_id || $item->variante_id) {
            throw new InvalidArgumentException(
                'Esa línea ya tiene producto. Cambiarlo movería el stock de un producto a otro sin dejar rastro.'
            );
        }

        return DB::transaction(function () use ($item, $datos) {
            $producto = isset($datos['producto_id']) && $datos['producto_id']
                ? Producto::findOrFail($datos['producto_id'])
                : $this->crear($datos);

            $color = trim((string) ($datos['color_nombre'] ?? ''));

            $variante = ($producto->desglose_stock && $color !== '')
                ? $this->varianteDe($producto, $color)
                : null;

            $item->forceFill([
                'producto_id' => $producto->id,
                'variante_id' => $variante?->id,
                /*
                 * El color también va en la línea.
                 *
                 * `RegistrarRecepcion` compara el color contado contra el de
                 * la línea y sólo hereda la variante si coinciden. Con la
                 * línea en blanco y la variante puesta, en una importación la
                 * recepción se iba sin variante y la mercancía quedaba otra
                 * vez sin a qué sumarle.
                 */
                'color_nombre' => $variante ? $color : $item->color_nombre,
            ])->save();

            return $item->fresh();
        });
    }

    private function crear(array $datos): Producto
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $referencia = trim((string) ($datos['referencia'] ?? ''));

        if ($nombre === '' || $referencia === '') {
            throw new InvalidArgumentException('Para crear el producto hacen falta el nombre y la referencia.');
        }

        if (Producto::where('referencia', $referencia)->exists()) {
            throw new InvalidArgumentException(
                "Ya hay un producto con la referencia «{$referencia}». Búsquelo y enlácelo en vez de crear otro."
            );
        }

        return Producto::create([
            'nombre' => $nombre,
            'referencia' => $referencia,
            /*
             * Granular o agregado se decide acá y es difícil de cambiar
             * después: un producto que ya tiene movimientos no puede empezar a
             * contar por color sin rehacer su kardex. Por eso la pantalla lo
             * pregunta en vez de asumirlo.
             */
            'desglose_stock' => (bool) ($datos['desglose_stock'] ?? false),
            'activo' => true,
        ]);
    }

    /**
     * La variante del color, reusando la que ya sabe hacerlo bien.
     *
     * `ColoresDeImportacion::varianteDe` resuelve el código de barras, busca
     * el color en el catálogo y respeta el modo del producto. Escribir una
     * segunda forma de crear variantes es como terminan existiendo dos
     * productos para la misma caja.
     */
    private function varianteDe(Producto $producto, string $color): ?ProductoVariante
    {
        return app(\App\Modules\Compras\Services\ColoresDeImportacion::class)
            ->varianteDe($producto, $color);
    }
}
