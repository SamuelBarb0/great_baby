<?php

namespace App\Modules\Portal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una linea cotizada, con el precio congelado.
 *
 * El SKU y la descripcion van en snapshot: si el producto se renombra despues,
 * la cotizacion impresa tiene que seguir diciendo lo que decia el dia que se
 * firmo.
 */
class CotizacionItem extends Model
{
    protected $table = 'cotizacion_items';

    protected $guarded = ['id'];

    protected $casts = [
        'cantidad' => 'integer',
        'precio_unitario' => 'decimal:2',
        'iva_porcentaje' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'iva_valor' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'cotizacion_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Dropi\Models\ProductoVariante::class, 'variante_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Dropi\Models\Producto::class, 'producto_id');
    }

    /** La llave con la que el pedido reconoce este renglon al convertir. */
    public function claveDeLinea(): string
    {
        return $this->variante_id ? 'variante-'.$this->variante_id : 'producto-'.$this->producto_id;
    }
}
