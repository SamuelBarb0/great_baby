<?php

namespace App\Modules\Cartera\Models;

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Enums\ClasificacionDiferencia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class PagoVenta extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'pagos_venta';

    // Los hooks created/deleted/restored viven en PagoVentaObserver
    // (registrado en AppServiceProvider). Se movió fuera de booted() porque
    // el trait OwenIt\Auditing\Auditable intercepta algunos eventos.

    /**
     * Un borrado en masa también tiene que pasar por el observer.
     *
     * `where(...)->delete()` no instancia modelos y por tanto no dispara
     * `deleted`: el pago queda borrado y su asiento vivo. Ver PagoVentaBuilder.
     */
    public function newEloquentBuilder($query): PagoVentaBuilder
    {
        return new PagoVentaBuilder($query);
    }

    // Re-audit DATOS #10 · $fillable coherente con FacturaVenta/MovimientoContable
    // ($guarded=['id']) + saving guard sobre factura_id post-creación (evita
    // re-apuntar un pago a otra factura después de contabilizar).
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $p) {
            // factura_id inmutable post-creación — cambiar el destino de un pago
            // dejaría el asiento contable con la ref vieja y descuadraría.
            if ($p->getOriginal('factura_id') && $p->isDirty('factura_id')) {
                throw new \RuntimeException(
                    "PagoVenta #{$p->id}: no se puede cambiar factura_id post-creación (rompe asiento contable)."
                );
            }
        });
    }

    protected $casts = [
        'fecha' => 'date',
        'monto_recibido' => 'decimal:2',
        'monto_aplicado' => 'decimal:2',
        'diferencia' => 'decimal:2',
        'clasificacion_diferencia' => ClasificacionDiferencia::class,
        'adjuntos' => 'array',
    ];

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_id');
    }

    /*
     * Los dos ejes de un pago son los de SU FACTURA. No son columnas acá a
     * proposito: un pago con destino propio permitiria un pago «fiscal» de una
     * factura interna, que no significa nada y deja la CxC fiscal en negativo
     * —se abonaria en el libro fiscal una deuda que ahi nunca se cargo—.
     *
     * Con estos accesores el estampado de `MovimientoContable` los encuentra
     * solo, sin que cada sitio que asienta un pago tenga que acordarse.
     */

    public function getLineaNegocioAttribute(): ?\App\Modules\Contabilidad\Enums\LineaNegocio
    {
        return $this->factura?->linea_negocio;
    }

    public function getDestinoFiscalAttribute(): ?\App\Modules\Contabilidad\Enums\DestinoFiscal
    {
        return $this->factura?->destino_fiscal;
    }

    public function contacto(): BelongsTo
    {
        return $this->belongsTo(Contacto::class);
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
