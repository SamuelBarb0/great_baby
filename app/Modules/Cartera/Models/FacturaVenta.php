<?php

namespace App\Modules\Cartera\Models;

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Enums\TramoAntiguedad;
use App\Modules\Contabilidad\Enums\LineaNegocio;
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
        /*
         * La línea de negocio se decide al nacer la factura.
         *
         * Nadie la escribía. Ni el pedido B2B, ni la facturación de Dropi, ni
         * la manual cuando no se elige: la columna tiene `great_baby` por
         * defecto y ahí se quedaba. Resultado medido el 2026-11-06: 34
         * facturas y 247 movimientos contables, TODOS en `great_baby`, cero en
         * `dropi`. El eje que existe justamente para que los dos negocios no se
         * mezclen no tenía un solo registro del otro lado.
         *
         * Lo peor es que el libro contable sí sabe deducirla —
         * `MovimientoContable` usa `LineaNegocio::deOrigen()` cuando el
         * documento no la trae— pero el default de la columna hace que la
         * factura SÍ la traiga, con el valor equivocado, y eso le gana a la
         * deducción. El default tapaba el camino correcto.
         *
         * Manda la razón social: si la factura sale por la empresa con la que
         * se factura Dropi, es de Dropi. Si no, se deduce del documento que la
         * originó, que es lo que ya hacía el libro.
         */
        static::creating(function (FacturaVenta $f) {
            if ($f->linea_negocio === null) {
                $f->linea_negocio = self::lineaQueLeCorresponde($f);
            }
        });

        /*
         * Y que no se puedan cruzar.
         *
         * Aracely lo pidió con todas las letras: «que nunca se me vayan a unir,
         * siempre las necesito aparte». Una factura de la razón social de Dropi
         * marcada como Great Baby es exactamente esa unión, y en el balance no
         * se ve: sale sumada y ya. Mejor que reviente acá.
         */
        static::saving(function (FacturaVenta $f) {
            $deLaEmpresa = self::lineaDeLaEmpresa($f->empresa_id);

            if ($deLaEmpresa === null || $f->linea_negocio === null
                || $f->linea_negocio === $deLaEmpresa) {
                return;
            }

            /*
             * Si NADIE tocó la línea en este guardado, se corrige sola.
             *
             * Antes esto reventaba siempre, y `saving` corre en CADA guardado:
             * aplicar un pago, cambiar el estado, tocar el saldo. Una factura
             * vieja —de cuando la columna tenía default `great_baby` y nadie
             * miraba la razón social— quedaba imposible de guardar para
             * siempre. No se podía ni registrar su pago, y el mensaje hablaba
             * de líneas de negocio, que no tiene nada que ver con lo que la
             * persona estaba haciendo.
             *
             * Manda la razón social, que es lo que este mismo archivo dice
             * arriba y lo que hace el hook de `creating`. Corregir la fila
             * cumple lo que pidió Aracely —que nunca se unan— sin tumbar una
             * operación que no tiene la culpa.
             */
            if (! $f->isDirty('linea_negocio')) {
                $f->linea_negocio = $deLaEmpresa;

                return;
            }

            /*
             * Pero si alguien la está CAMBIANDO a una que no corresponde, eso
             * sí revienta: es la unión que no puede pasar, hecha a propósito.
             */
            throw new RuntimeException(sprintf(
                'Factura %s: la razón social «%s» factura %s, pero se está guardando como %s. '
                .'Las dos líneas de negocio no se mezclan.',
                $f->numero ?? '(nueva)',
                \App\Models\EmpresaConfig::porId($f->empresa_id)->nombreCorto(),
                $deLaEmpresa->label(),
                $f->linea_negocio->label(),
            ));
        });

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
    /**
     * La línea que le toca a una factura: primero la razón social, si no el origen.
     */
    private static function lineaQueLeCorresponde(FacturaVenta $f): LineaNegocio
    {
        return self::lineaDeLaEmpresa($f->empresa_id)
            ?? LineaNegocio::deOrigen($f->origen_type);
    }

    /**
     * La línea de negocio que impone una razón social, si impone alguna.
     *
     * Cuál es la empresa de Dropi sale de la regla `dropi.empresa`, que es
     * donde ya estaba escrito —y no de un código fijo acá—, porque el día que
     * cambien de razón social para el canal, esto tiene que seguirlos.
     *
     * Devuelve null para las demás empresas: Great Baby y Arsutex facturan lo
     * suyo, y ahí la línea la decide el documento que originó la factura.
     *
     * Y devuelve null TAMBIÉN cuando la empresa de Dropi es la principal.
     *
     * Esto se escribió dando por hecho que cada línea de negocio tenía su
     * razón social: `dropi.empresa` valía `ecommerce`, una empresa dedicada al
     * canal, y entonces «sale por esa razón social» equivalía a «es de Dropi».
     *
     * El 7-nov la regla pasó a `great_baby`: misma empresa, otra resolución
     * DIAN. Una razón social que factura las DOS líneas no distingue ninguna,
     * y seguir deduciendo de ahí le decía a toda factura de Great Baby que
     * tenía que ser Dropi. La guarda de `saving` reventaba con un mensaje que
     * se contradecía solo, y el libro quedaba al revés.
     *
     * La prueba es la misma que usa `DropiReestamparLineaCommand`: «desde el
     * 7-nov Dropi factura con Great Baby, así que entonces sólo cuenta el
     * origen». Acá eso es devolver null y dejar que decida `deOrigen()`.
     *
     * El candado que pidió Aracely no se pierde: lo que nace de un pedido
     * Dropi sigue siendo Dropi, y si algún día vuelven a una razón social
     * propia —`ecommerce`, `arsutex`— esto vuelve a imponerla solo.
     */
    private static function lineaDeLaEmpresa(?int $empresaId): ?LineaNegocio
    {
        $codigoDropi = trim((string) setting('dropi.empresa'));
        if ($codigoDropi === '') {
            return null;
        }

        $empresa = \App\Models\EmpresaConfig::porId($empresaId);

        if ($empresa->es_principal) {
            return null;
        }

        return $empresa->codigo === $codigoDropi ? LineaNegocio::Dropi : null;
    }

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

    /** Lo que se le cobra DE MAS al cliente sobre esta factura. */
    public function notasDebito(): HasMany
    {
        return $this->hasMany(NotaDebito::class, 'factura_id');
    }

    protected $casts = [
        'estado' => EstadoFactura::class,
        // F1 · los dos ejes. Sin el cast vuelven como string y cualquier
        // `->label()` revienta con «Call to a member function label() on string».
        'linea_negocio' => LineaNegocio::class,
        'destino_fiscal' => \App\Modules\Contabilidad\Enums\DestinoFiscal::class,
        'monto_fiscal' => 'decimal:2',
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
        /*
         * Y lo que se le cobra de MAS. La nota debito no entraba por ningun
         * lado: aumentaba la deuda ante la DIAN y el saldo del ERP seguia
         * igual, asi que el cliente quedaba debiendo menos de lo que debia.
         */
        $totalNd = (float) $this->notasDebito()
            ->whereNotIn('estado', ['rechazada', 'anulada'])
            ->sum('valor');

        $this->saldo = max(0, (float) $this->total - (float) $this->retenciones - $totalPagado - $totalNc + $totalNd);

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
