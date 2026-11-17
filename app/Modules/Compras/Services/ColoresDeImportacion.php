<?php

namespace App\Modules\Compras\Services;

use App\Modules\Catalogo\Models\Color;
use App\Modules\Compras\Actions\CrearProductosDeOrden;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\OrdenCompraItem;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Compras\Models\RecepcionCompraItem;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * El reparto por color (Word de Great Baby, sección 1). Se pide por color, pero de
 * China llega agrupado y en otras cantidades: la verdad la sabe Don Jorge al contar.
 * El tope deja de ser cada color y pasa a ser el total de la referencia.
 *
 * Un producto con movimientos no puede pasar a tener colores: lo prohíbe un trigger
 * de la base, porque su stock ya registrado se quedaría sin color. A ese producto el
 * color se le guarda en la recepción y su stock sigue siendo uno solo.
 */
class ColoresDeImportacion
{
    public const SIN_IDENTIFICAR = 'Sin identificar';

    /** La variante de ese color, creándola si falta. Null si el producto no admite colores. */
    public function varianteDe(Producto $producto, string $color): ?ProductoVariante
    {
        $color = trim($color);
        if ($color === '' || ! $this->admiteColores($producto)) {
            return null;
        }

        if (! $producto->desglose_stock) {
            // Sin eventos: el cambio de modo no debe salir solo hacia SIIGO.
            Producto::whereKey($producto->id)->update(['desglose_stock' => true]);
            $producto->desglose_stock = true;
        }

        $existente = $producto->variantes()
            ->whereRaw('LOWER(color_nombre) = ?', [mb_strtolower($color)])
            ->first();
        if ($existente) {
            return $existente;
        }

        $catalogo = Color::query()->whereRaw('LOWER(nombre) = ?', [mb_strtolower($color)])->first();

        return $producto->variantes()->create([
            'color_id' => $catalogo?->id,
            'color_codigo' => $catalogo?->codigo ?? $this->codigoNuevo($producto, $color),
            'color_nombre' => $catalogo?->nombre ?? $color,
        ]);
    }

    public function admiteColores(Producto $producto): bool
    {
        return $producto->desglose_stock
            || ! InventarioMovimiento::where('producto_id', $producto->id)->exists();
    }

    /**
     * Le pone su variante a cada fila contada. Primero las que traen color, porque
     * son las que pasan el producto a tener colores; lo que quede sin color en un
     * producto con colores no tiene dónde más entrar: va a «Sin identificar».
     */
    public function resolverEnRecepcion(RecepcionCompra $recepcion): void
    {
        $filas = $recepcion->items->reject(fn (RecepcionCompraItem $i) => $i->clasificacion === 'faltante');

        foreach ($filas as $fila) {
            $ordenItem = OrdenCompraItem::find($fila->orden_item_id);
            if ($ordenItem && ! $fila->producto_id) {
                $fila->producto_id = $ordenItem->producto_id;
            }
        }

        foreach ($filas->filter(fn ($f) => $f->color_nombre && $f->producto_id) as $fila) {
            $variante = $this->varianteDe(Producto::findOrFail($fila->producto_id), $fila->color_nombre);
            $fila->variante_id = $variante?->id;
            $fila->save();
        }

        foreach ($filas->filter(fn ($f) => ! $f->color_nombre && ! $f->variante_id && $f->producto_id) as $fila) {
            $producto = Producto::findOrFail($fila->producto_id);
            if ($producto->desglose_stock) {
                $fila->color_nombre = self::SIN_IDENTIFICAR;
                $fila->variante_id = $this->varianteDe($producto, self::SIN_IDENTIFICAR)?->id;
                $fila->save();
            }
        }
    }

    /**
     * Las referencias en las que se contó más de lo que faltaba por recibir.
     *
     * @param  iterable<array{orden_item_id:int, cantidad_recibida:float, clasificacion?:?string}>  $filas
     * @return array<string, array{contado: float, esperado: float}>
     */
    public function excesos(OrdenCompra $orden, iterable $filas): array
    {
        $referenciaDe = fn (OrdenCompraItem $i) => CrearProductosDeOrden::referenciaDe($i) ?: "Línea {$i->id}";
        $items = $orden->items->reject->esComponente()->keyBy('id');

        $cuenta = [];
        foreach ($items as $item) {
            $ref = $referenciaDe($item);
            $cuenta[$ref] ??= ['contado' => 0.0, 'esperado' => 0.0];
            $cuenta[$ref]['esperado'] += $item->pendiente();
        }
        foreach ($filas as $fila) {
            $item = $items[$fila['orden_item_id']] ?? null;
            if ($item && ($fila['clasificacion'] ?? 'apto') !== 'faltante') {
                $cuenta[$referenciaDe($item)]['contado'] += (float) $fila['cantidad_recibida'];
            }
        }

        return array_filter($cuenta, fn ($c) => $c['contado'] > $c['esperado'] + 0.0001);
    }

    /** Lo que sigue en «Sin identificar» de una fila, en la bodega donde se recibió. */
    public function saldoSinIdentificar(RecepcionCompraItem $fila, int $bodegaId): float
    {
        if ($fila->color_nombre !== self::SIN_IDENTIFICAR || ! $fila->variante_id) {
            return 0.0;
        }

        return max(0.0, (float) InventarioMovimiento::where('variante_id', $fila->variante_id)
            ->where('ubicacion_id', $bodegaId)->sum('cantidad'));
    }

    /**
     * Lo que llegó revuelto ya se clasificó: pasa al color sin rehacer la recepción.
     * El total del producto no cambia y conserva su costo.
     */
    public function reclasificar(RecepcionCompraItem $fila, string $color, float $cantidad, ?int $userId): ProductoVariante
    {
        $recepcion = $fila->recepcion;
        $color = trim($color);
        if ($cantidad <= 0 || $color === '' || mb_strtolower($color) === mb_strtolower(self::SIN_IDENTIFICAR)) {
            throw new InvalidArgumentException('Escriba el color y una cantidad mayor que cero.');
        }

        return DB::transaction(function () use ($fila, $recepcion, $color, $cantidad, $userId) {
            /*
             * La bodega es la de la FILA, no la del encabezado.
             *
             * Desde que una recepción se puede repartir, el encabezado ya no
             * dice dónde quedó cada línea. Leerlo acá tenía dos finales malos:
             * el saldo salía en cero y la mercancía se quedaba sin color para
             * siempre, o —peor— los dos movimientos de la reclasificación se
             * escribían en la bodega equivocada y dejaban negativo lo que
             * estaba bien.
             *
             * Y es «dónde QUEDÓ», no «a dónde la mandaron»: una línea que no
             * llegó apta tiene un destino elegido que no se respetó, porque el
             * stock entró a averías. Con el destino elegido los dos
             * movimientos se escribían en una bodega sin saldo.
             */
            $bodega = $fila->bodegaDondeQuedo();

            if ($bodega === null) {
                throw new InvalidArgumentException(
                    'Esa línea no entró a ninguna bodega: se marcó como faltante al descargar.'
                );
            }

            $origen = ProductoVariante::whereKey($fila->variante_id)->lockForUpdate()->first();
            $saldo = $this->saldoSinIdentificar($fila, $bodega);
            if (! $origen || $cantidad > $saldo + 0.0001) {
                throw new InvalidArgumentException("Sólo quedan {$saldo} sin identificar.");
            }

            $destino = $this->varianteDe(Producto::findOrFail($origen->producto_id), $color);
            $costo = (float) InventarioMovimiento::where('variante_id', $origen->id)
                ->where('ubicacion_id', $bodega)->where('cantidad', '>', 0)
                ->latest('id')->value('costo_unit');

            foreach ([[$origen, -$cantidad, 'reclasificacion_salida'], [$destino, $cantidad, 'reclasificacion_entrada']] as [$variante, $movida, $tipo]) {
                InventarioMovimiento::create([
                    'variante_id' => $variante->id,
                    'producto_id' => $variante->producto_id,
                    'ubicacion_id' => $bodega,
                    'tipo' => $tipo,
                    'cantidad' => $movida,
                    'costo_unit' => $costo,
                    'referencia_tipo' => RecepcionCompra::class,
                    'referencia_id' => $recepcion->id,
                    'user_id' => $userId,
                    'notas' => "Rec {$recepcion->numero} · sin identificar → {$destino->color_nombre}",
                    'created_at' => now(),
                ]);
            }

            return $destino;
        });
    }

    /** Código corto para un color que no está en el catálogo. No se repite en el producto. */
    private function codigoNuevo(Producto $producto, string $color): string
    {
        $base = $color === self::SIN_IDENTIFICAR
            ? 'SI'
            : (Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($color)), 0, 4)) ?: 'COL');

        $codigo = $base;
        for ($n = 2; $producto->variantes()->where('color_codigo', $codigo)->exists(); $n++) {
            $codigo = $base.$n;
        }

        return $codigo;
    }
}
