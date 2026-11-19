<?php

namespace App\Modules\Compras\Models;

use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

// Re-audit M2 R3 PATRÓN R · Auditable — trazabilidad DIAN de cambios post-recepción.
class RecepcionCompraItem extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'compras_recepcion_items';

    protected $fillable = [
        'recepcion_id', 'orden_item_id', 'producto_id', 'variante_id',
        'cantidad_recibida', 'costo_unit', 'subtotal', 'color_nombre',
        'lote', 'fecha_vencimiento', 'observaciones',
        // LOG-J8 · clasificación al descargar (apto|averia|cuarentena|revision|faltante)
        'clasificacion', 'motivo_clasificacion',
    ];

    protected $casts = [
        'cantidad_recibida' => 'decimal:3',
        'costo_unit' => 'decimal:4',
        'subtotal' => 'decimal:2',
        'fecha_vencimiento' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (RecepcionCompraItem $item) {
            $item->subtotal = round((float) $item->cantidad_recibida * (float) $item->costo_unit, 2);
        });
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionCompra::class, 'recepcion_id');
    }

    public function ordenItem(): BelongsTo
    {
        return $this->belongsTo(OrdenCompraItem::class, 'orden_item_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class);
    }
}
