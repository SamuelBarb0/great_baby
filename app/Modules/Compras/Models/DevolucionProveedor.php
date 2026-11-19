<?php

namespace App\Modules\Compras\Models;

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Dropi\Models\InventarioUbicacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * COMP-B1 · Devolución a proveedor (header). Hoja compacta similar a
 * RecepcionCompra pero en sentido inverso: baja stock y genera NC compra
 * en SIIGO + MovimientoContable local inverso.
 */
class DevolucionProveedor extends Model
{
    protected $table = 'devoluciones_proveedor';

    protected $fillable = [
        'numero', 'proveedor_id', 'recepcion_id', 'ubicacion_id',
        'fecha', 'motivo', 'estado', 'subtotal', 'iva', 'total',
        'siigo_id', 'siigo_sync_at', 'creada_por', 'confirmada_at',
    ];

    protected $casts = [
        'fecha' => 'date',
        'confirmada_at' => 'datetime',
        'siigo_sync_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'iva' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function proveedor(): BelongsTo { return $this->belongsTo(Contacto::class, 'proveedor_id'); }
    public function recepcion(): BelongsTo { return $this->belongsTo(RecepcionCompra::class, 'recepcion_id'); }

    /** Las que deben llegar a SIIGO · ver RecepcionCompra::scopeDeclarable(). */
    public function scopeDeclarable(Builder $q): Builder
    {
        return $q->whereDoesntHave('recepcion.orden', fn (Builder $o) => $o->where(
            'destino_fiscal', \App\Modules\Contabilidad\Enums\DestinoFiscal::Interno->value,
        ));
    }
    public function ubicacion(): BelongsTo { return $this->belongsTo(InventarioUbicacion::class, 'ubicacion_id'); }
    public function creador(): BelongsTo { return $this->belongsTo(User::class, 'creada_por'); }
    public function items(): HasMany { return $this->hasMany(DevolucionProveedorItem::class, 'devolucion_id'); }
}
