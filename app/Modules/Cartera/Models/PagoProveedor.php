<?php

namespace App\Modules\Cartera\Models;

use App\Models\Contacto;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class PagoProveedor extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'pagos_proveedor';
    protected $guarded = ['id'];
    protected $hidden = ['siigo_response'];

    protected $casts = [
        'fecha' => 'date',
        'monto_bruto' => 'decimal:2',
        'iva' => 'decimal:2',
        'monto_retenciones' => 'decimal:2',
        'monto_neto' => 'decimal:2',
        'gran_contribuyente' => 'boolean',
        'siigo_response' => 'array',
        'siigo_sync_at' => 'datetime',
        'confirmado_at' => 'datetime',
        'anulado_at' => 'datetime',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Contacto::class, 'contacto_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function retenciones(): MorphMany
    {
        return $this->morphMany(RetencionAplicada::class, 'origen');
    }

    // COMP-B3 · recepción origen del pago. Permite al emisor SIIGO enlazar el
    // voucher egreso a la factura de compra (invoice_id) para que SIIGO baje
    // el saldo de ESA CxP específica en vez de dejarlo como ajuste suelto.
    /** Los que deben llegar a SIIGO · ver RecepcionCompra::scopeDeclarable(). */
    public function scopeDeclarable(Builder $q): Builder
    {
        return $q->whereDoesntHave('recepcion.orden', fn (Builder $o) => $o->where(
            'destino_fiscal', \App\Modules\Contabilidad\Enums\DestinoFiscal::Interno->value,
        ));
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Compras\Models\RecepcionCompra::class, 'recepcion_id');
    }
}
