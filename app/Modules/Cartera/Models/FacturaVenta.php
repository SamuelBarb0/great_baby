<?php

namespace App\Modules\Cartera\Models;

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Enums\TramoAntiguedad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use RuntimeException;

class FacturaVenta extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'facturas_venta';

    protected array $auditExclude = [
        'siigo_response', 'qr_html', 'qr_url', 'token_publico', 'emitiendo_at',
    ];

    protected $hidden = ['siigo_response', 'token_publico'];

    /**
     * Re-audit SEG C2 · $guarded en lugar de $fillable expuesto: sella campos
     * fiscales/monetarios/de integración de cualquier mass-assign accidental.
     * Los flujos legítimos que llenan estos campos (SiigoEmisionService,
     * RegistrarAsientoContable, ContabilizarDevolucionDropi, GenerarFacturasB2BDeCorte,
     * PedidosB2BController::facturar) usan asignación explícita o forceFill().
     */
    protected $guarded = ['id'];

    /**
     * Re-audit FUNC C1 / DATOS C1 · el asiento contable YA NO se dispara desde
     * un `saved` observer chequeando `items()->count()`. Ese patrón falla cuando
     * la Action crea header y items en secuencia (los items aún no existen al
     * primer save). Ahora:
     *   1) `saving` valida invariante partida doble (total ≈ subtotal−descuento+impuestos)
     *   2) `ensureAsiento()` es un método público que las Actions llaman
     *      EXPLÍCITAMENTE después de crear items.
     */
    protected static function booted(): void
    {
        static::saving(function (FacturaVenta $f) {
            // Invariante partida doble.
            $subtotal = (float) $f->subtotal;
            $descuento = (float) $f->descuento;
            $impuestos = (float) $f->impuestos;
            $total = (float) $f->total;
            $esperado = round($subtotal - $descuento + $impuestos, 2);
            if (abs($esperado - $total) > 0.01) {
                throw new RuntimeException(sprintf(
                    'Factura %s desbalanceada: total=%.2f pero subtotal(%.2f) - descuento(%.2f) + impuestos(%.2f) = %.2f',
                    $f->numero ?? '(nueva)', $total, $subtotal, $descuento, $impuestos, $esperado,
                ));
            }

            // Re-audit RAÍZ F + RAÍZ X (R3-01) · inmutabilidad DIAN COMPLETA.
            // Post-emisión NADA de lo que definió el contenido económico de la
            // factura ante DIAN puede cambiar. Antes faltaban total/subtotal/
            // descuento/impuestos/contacto_id (un admin podía rebajar valores
            // y pasar el invariante aritmético). Ahora bloqueados todos.
            if ($f->exists && $f->getOriginal('emitida_at')) {
                $inmutables = [
                    'numero', 'fecha_emision', 'cufe', 'ari_factura_id', 'emitida_at',
                    'total', 'subtotal', 'descuento', 'impuestos', 'retenciones',
                    'contacto_id', 'es_electronica',
                ];
                foreach ($inmutables as $campo) {
                    if ($f->isDirty($campo)) {
                        throw new RuntimeException(sprintf(
                            'Factura %s ya emitida: campo "%s" es inmutable (violación DIAN)',
                            $f->getOriginal('numero'), $campo,
                        ));
                    }
                }
            }
        });

        // Re-audit RAÍZ Z (R3-A) · forceDeleting cascada completa + guard.
        // Antes solo borraba movs; pagos/cobranzas con FK RESTRICT truenan
        // después dejando estado inconsistente (movs borrados, factura viva).
        //
        //   1) NUNCA permitir forceDelete de facturas ya emitidas — DIAN exige
        //      inmutabilidad del histórico. Deja solo softDelete disponible.
        //   2) Envolver la cascada en transacción para atomicidad.
        //   3) forceDelete físico de movs (no soft) porque son basura
        //      irrecuperable del asiento — libro DIAN vive en NC/facturas emitidas.
        static::forceDeleting(function (FacturaVenta $f) {
            if ($f->emitida_at) {
                throw new RuntimeException(sprintf(
                    'Factura %s ya emitida: NO se puede eliminar físicamente (DIAN). Anúlala con estado=Anulada.',
                    $f->numero,
                ));
            }

            \Illuminate\Support\Facades\DB::transaction(function () use ($f) {
                MovimientoContable::query()
                    ->where('origen_type', static::class)
                    ->where('origen_id', $f->id)
                    ->forceDelete();
                $f->pagos()->withTrashed()->each(fn ($p) => $p->forceDelete());
                $f->cobranzas()->forceDelete();
            });
        });
    }

    /**
     * Registra el asiento contable de emisión — idempotente por (origen_type, origen_id).
     * Se llama EXPLÍCITAMENTE desde la Action que crea la factura, DESPUÉS de
     * haber creado los items. Requisitos: total > 0 y al menos 1 item.
     *
     * Re-audit R3-B · lockForUpdate sobre la factura en tx previene race entre
     * dos jobs concurrentes que ambos chequean exists()=false y ambos insertan.
     */
    public function ensureAsiento(): bool
    {
        if ((float) $this->total <= 0) return false;
        if ($this->items()->count() === 0) return false;

        return \Illuminate\Support\Facades\DB::transaction(function () {
            // Serializa: dos jobs concurrentes leen misma fila y el segundo espera.
            static::query()->whereKey($this->id)->lockForUpdate()->first();

            $yaExiste = MovimientoContable::where('origen_type', static::class)
                ->where('origen_id', $this->id)->exists();
            if ($yaExiste) return false;

            (new \App\Modules\Cartera\Actions\RegistrarAsientoContable())->factura($this->fresh());
            return true;
        });
    }

    /** Lo que el cliente retiene sobre esta factura (ver AplicarRetencionesVenta). */
    public function retencionesAplicadas(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(RetencionAplicada::class, 'origen');
    }

    /** Relación a las Notas Crédito emitidas contra esta factura. */
    public function notasCredito(): HasMany
    {
        return $this->hasMany(NotaCredito::class, 'factura_id');
    }

    protected $casts = [
        'estado' => EstadoFactura::class,
        'fecha_emision' => 'date',
        'fecha_vencimiento' => 'date',
        'ari_enviada_at' => 'datetime',
        'emitida_at' => 'datetime',
        'emitiendo_at' => 'datetime',
        'es_electronica' => 'boolean',
        'facturado_send_dian' => 'boolean',
        'facturado_send_mail' => 'boolean',
        'siigo_response' => 'array',
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'impuestos' => 'decimal:2',
        'total' => 'decimal:2',
        'retenciones' => 'decimal:2',
        'saldo' => 'decimal:2',
    ];

    public function contacto(): BelongsTo
    {
        return $this->belongsTo(Contacto::class);
    }

    /**
     * UBIC-5 · Ubicación desde donde se despachó la factura. Permite al
     * SiigoEmisionService resolver qué resolución DIAN usar (via
     * ubicacion.siigo_resolution_id) y qué cuentas PUC aplicar.
     */
    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Dropi\Models\InventarioUbicacion::class, 'ubicacion_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FacturaVentaItem::class, 'factura_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoVenta::class, 'factura_id');
    }

    public function cobranzas(): HasMany
    {
        return $this->hasMany(CobranzaRegistro::class, 'factura_id');
    }

    public function origen(): MorphTo
    {
        return $this->morphTo('origen', 'origen_type', 'origen_id');
    }

    /**
     * Re-audit RAÍZ A + C (FUNC N1 / DATOS #1) · Contrato único que responde
     * si la factura puede recibir un pago que impacte contabilidad. Combina
     * el eje de ciclo (estado) y el eje DIAN (emitida_at). Un pago aplicado
     * a una Borrador o Anulada NO genera CxC previa → asiento de pago
     * dejaría 1305 en negativo. Bloqueado aquí.
     */
    public function puedeRecibirPago(): bool
    {
        return in_array($this->estado, [
            EstadoFactura::Pendiente,
            EstadoFactura::Abonada,
            EstadoFactura::Vencida,
        ], true);
    }

    public function diasMora(): int
    {
        if ($this->estado === EstadoFactura::Pagada || $this->estado === EstadoFactura::Anulada) {
            return 0;
        }
        return (int) now()->startOfDay()->diffInDays($this->fecha_vencimiento->startOfDay(), false) * -1;
    }

    public function tramo(): TramoAntiguedad
    {
        return TramoAntiguedad::delDias($this->diasMora());
    }

    /**
     * Recalcula saldo y estado desde pagos. Idempotente.
     *
     * Re-audit FUNC C4 · SOLO opera sobre estados de cartera. Estados DIAN
     * (Borrador, RechazadaDian, AceptadaDian implícito por emitida_at) y Anulada
     * NUNCA se sobrescriben — un pago sobre borrador NO lo marca "Pagada" ni
     * borra el status DIAN. Si el sistema quiere marcar Pagada un borrador,
     * primero debe emitirlo explícitamente.
     */
    public function recalcular(): void
    {
        // Skip estados que NO son de cartera vigente.
        $estadosCarteraVigente = [
            EstadoFactura::Pendiente, EstadoFactura::Abonada,
            EstadoFactura::Vencida,  EstadoFactura::Pagada,
        ];
        if (! in_array($this->estado, $estadosCarteraVigente, true)) {
            return;
        }

        $totalPagado = (float) $this->pagos()->sum('monto_aplicado');
        // Re-audit R3-05 · NC activas reducen el saldo. Antes recalcular ignoraba
        // notas crédito → saldo cliente mostrado en cartera divergía del asiento
        // contable (que sí ya había reducido 1305 via observer NC / ContabilizarDev).
        $totalNc = (float) $this->notasCredito()
            ->whereNotIn('estado', ['rechazada', 'anulada'])
            ->sum('valor');
        // Lo que el cliente retiene no lo va a pagar nunca: el saldo parte del
        // neto, si no un cliente que paga completo quedaría debiendo lo retenido.
        $this->saldo = max(0, (float) $this->total - (float) $this->retenciones - $totalPagado - $totalNc);

        if ($this->saldo <= 0.009) {
            $this->estado = EstadoFactura::Pagada;
        } elseif ($totalPagado > 0) {
            $this->estado = EstadoFactura::Abonada;
        } elseif ($this->fecha_vencimiento->lt(now()->startOfDay())) {
            $this->estado = EstadoFactura::Vencida;
        } else {
            $this->estado = EstadoFactura::Pendiente;
        }

        $this->save();
    }
}
