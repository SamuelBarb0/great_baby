<?php

namespace App\Modules\Dropi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Llave de Dropi (`DV-<variación>`, SKU o `DP-<producto>`) → catálogo GB.
 *
 * Apunta a UNA de dos cosas: una variante (producto con desglose de stock) o
 * un producto agregado (`desglose_stock = false`, sin variantes; todas las
 * variaciones de Dropi descuentan del mismo total). Ver VincularProductoDropi
 * y ResolverEstadoInicial::destinoPara().
 */
class DropiVinculoProducto extends Model
{
    protected $table = 'dropi_vinculos_producto';

    protected $fillable = ['sku_dropi', 'variante_id', 'producto_id', 'producto_nombre', 'variacion', 'vinculado_por'];

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
