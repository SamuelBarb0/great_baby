<?php

namespace App\Modules\Dropi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Llave de Dropi (`DV-<variación>` o SKU) → variante del catálogo GB.
 * Ver VincularProductoDropi y ResolverEstadoInicial::varianteIdPara().
 */
class DropiVinculoProducto extends Model
{
    protected $table = 'dropi_vinculos_producto';

    protected $fillable = ['sku_dropi', 'variante_id', 'producto_nombre', 'variacion', 'vinculado_por'];

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }
}
