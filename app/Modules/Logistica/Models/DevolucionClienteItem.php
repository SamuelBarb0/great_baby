<?php

namespace App\Modules\Logistica\Models;

use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de lo devuelto.
 *
 * Apunta a una VARIANTE (color/talla) o a un PRODUCTO a secas, nunca a las
 * dos: el catálogo de Great Baby tiene referencias desglosadas y referencias
 * que llevan el stock sin desglosar, y el kardex exige que cada movimiento
 * diga cuál de las dos es.
 */
class DevolucionClienteItem extends Model
{
    protected $table = 'devoluciones_cliente_items';

    protected $fillable = [
        'devolucion_id', 'variante_id', 'producto_id',
        'cantidad', 'costo_unit', 'motivo_item',
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'costo_unit' => 'decimal:4',
    ];

    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(DevolucionCliente::class, 'devolucion_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /** Sin variante y con producto: el stock lo lleva la referencia entera. */
    public function esAgregado(): bool
    {
        return $this->variante_id === null && $this->producto_id !== null;
    }
}
