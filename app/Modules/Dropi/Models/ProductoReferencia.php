<?php

namespace App\Modules\Dropi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Otra referencia con la que se conoce un producto: la que tenía antes, o la que
 * le puso un proveedor. Sigue siendo el mismo producto.
 */
class ProductoReferencia extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'producto_referencias';

    protected $guarded = ['id'];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
