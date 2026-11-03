<?php

namespace App\Modules\Compras\Models;

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\CondicionCredito;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Dropi\Models\InventarioUbicacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class OrdenCompra extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'compras_ordenes';

    // Re-audit M2 PATRÓN B (DATOS-C2 / FUNC-C4) · antes `$fillable` explícito hacía
    // que `$o->update(['estado' => Anulada])` DESCARTARA `estado` silenciosamente
    // (no estaba en fillable, iba a `$backendOnly` informativo) → la OC quedaba
    // "anulada" en observaciones pero seguía viva → doble consumo posible.
    // Ahora `$guarded = ['id']`: TODOS los campos son asignables, y bloqueamos
    // ediciones peligrosas en `booted::saving` una vez la OC ya no es Borrador.
    protected $guarded = ['id'];

    protected $casts = [
        // Los dos ejes. Sin el cast vuelven como string y cualquier
        // `->label()` revienta con «Call to a member function label() on string».
        'linea_negocio' => \App\Modules\Contabilidad\Enums\LineaNegocio::class,
        'destino_fiscal' => \App\Modules\Contabilidad\Enums\DestinoFiscal::class,
        'fecha_emision' => 'date',
        'fecha_esperada' => 'date',
        'aprobado_at' => 'datetime',
        'anulado_at' => 'datetime',
        'estado' => EstadoOrdenCompra::class,
        'meta' => 'array',
        'parametros_preliquidacion' => 'array',
        'tasa_cambio' => 'decimal:6',
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'iva' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /**
     * Re-audit M2 R3 PATRÓN M (DATOS-C2 + SEG-M3) · inmutabilidad AMPLIADA
     * y transición de `estado` validada.
     *
     * Errores de rondas anteriores corregidos:
     *   - `$inmutables` no incluía `fecha_emision` → un update post-aprobación
     *     movía el asiento a otro periodo fiscal (violación DIAN).
     *   - `aprobado_por|aprobado_at|anulado_*` mutables después de aprobar →
     *     un update posterior podía FALSIFICAR el audit trail (aunque
     *     Auditable deja el histórico, el estado "actual" mentía).
     *   - Guard solo aplicaba post-Borrador → `$oc->update(['estado'=>'cerrada',
     *     'total'=>1_000_000])` en Borrador brincaba approval + recepción sin
     *     validación.
     *
     * Ahora:
     *   1) Transición de `estado` SIEMPRE validada por state-machine
     *      (transicionValida) — funciona en Borrador y en cualquier otro estado.
     *   2) Inmutables completos post-Borrador: bodega/proveedor/tipo/moneda/
     *      tasa/subtotal/iva/total/numero/fecha_emision/fecha_esperada/
     *      condicion_credito_id/aprobado_por/aprobado_at/anulado_por/
     *      anulado_at/motivo_anulacion/creado_por.
     */
    protected static function booted(): void
    {
        static::saving(function (OrdenCompra $o) {
            if (! $o->exists) return;

            $original = $o->getOriginal('estado');
            $estadoAnterior = $original instanceof EstadoOrdenCompra
                ? $original
                : ($original ? EstadoOrdenCompra::tryFrom($original) : null);

            // 1) Validar transición de estado SIEMPRE (incluye Borrador).
            //    Bloquea mass-assign de `estado` que brincaba approval.
            if ($o->isDirty('estado')) {
                $nuevo = $o->estado instanceof EstadoOrdenCompra
                    ? $o->estado
                    : EstadoOrdenCompra::tryFrom($o->estado);
                if (! self::transicionValida($estadoAnterior, $nuevo)) {
                    throw new \RuntimeException(sprintf(
                        'OC %s: transición %s → %s no permitida.',
                        $o->getOriginal('numero'), $estadoAnterior?->value ?? 'null', $nuevo?->value ?? 'null',
                    ));
                }
            }

            if ($estadoAnterior === null || $estadoAnterior === EstadoOrdenCompra::Borrador) return;

            // 2) Inmutables post-Borrador. Los de anulación se permiten SOLO
            //    en la transición hacia Anulada (una vez).
            $inmutables = [
                'bodega_id', 'proveedor_id', 'tipo', 'moneda', 'tasa_cambio',
                'subtotal', 'iva', 'total', 'numero',
                'fecha_emision', 'fecha_esperada', 'condicion_credito_id',
                'creado_por', 'aprobado_por', 'aprobado_at',
            ];
            // Los campos de anulación son inmutables si YA estaba anulada.
            if ($estadoAnterior === EstadoOrdenCompra::Anulada) {
                $inmutables = array_merge($inmutables, ['anulado_por', 'anulado_at', 'motivo_anulacion']);
            }

            foreach ($inmutables as $campo) {
                if ($o->isDirty($campo)) {
                    throw new \RuntimeException(sprintf(
                        'OC %s (%s): campo "%s" es inmutable post-aprobación.',
                        $o->getOriginal('numero'), $estadoAnterior->value, $campo,
                    ));
                }
            }
        });
    }

    /**
     * State machine de transiciones válidas.
     *   Borrador → Enviada|Aprobada|Anulada
     *   Enviada  → Aprobada|Anulada
     *   Aprobada → Parcial|Recibida|Anulada
     *   Parcial  → Recibida|Anulada
     *   Recibida → Cerrada
     *   Cerrada|Anulada → terminales
     */
    public static function transicionValida(?EstadoOrdenCompra $desde, ?EstadoOrdenCompra $hacia): bool
    {
        if ($desde === null || $hacia === null) return false;
        if ($desde === $hacia) return true; // no-op update de otro campo
        return match ($desde) {
            EstadoOrdenCompra::Borrador => in_array($hacia, [EstadoOrdenCompra::Enviada, EstadoOrdenCompra::Aprobada, EstadoOrdenCompra::Anulada], true),
            EstadoOrdenCompra::Enviada => in_array($hacia, [EstadoOrdenCompra::Aprobada, EstadoOrdenCompra::Anulada], true),
            EstadoOrdenCompra::Aprobada => in_array($hacia, [EstadoOrdenCompra::Parcial, EstadoOrdenCompra::Recibida, EstadoOrdenCompra::Anulada], true),
            EstadoOrdenCompra::Parcial => in_array($hacia, [EstadoOrdenCompra::Recibida, EstadoOrdenCompra::Anulada], true),
            EstadoOrdenCompra::Recibida => $hacia === EstadoOrdenCompra::Cerrada,
            default => false,
        };
    }

    public function compromisosPago(): HasMany
    {
        return $this->hasMany(CompromisoPago::class, 'orden_id');
    }

    /** El contenedor en que viaja. Es una lista por cómo es la tabla, pero va en uno. */
    public function importaciones(): BelongsToMany
    {
        return $this->belongsToMany(Importacion::class, 'compras_importacion_ordenes', 'orden_id', 'importacion_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Contacto::class, 'proveedor_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(InventarioUbicacion::class, 'bodega_id');
    }

    public function condicionCredito(): BelongsTo
    {
        return $this->belongsTo(CondicionCredito::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrdenCompraItem::class, 'orden_id');
    }

    public function recepciones(): HasMany
    {
        return $this->hasMany(RecepcionCompra::class, 'orden_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function esImportacion(): bool
    {
        return $this->tipo === 'importacion';
    }

    public function porcentajeRecibido(): float
    {
        $total = $this->items->sum('cantidad');
        if ($total <= 0) {
            return 0;
        }

        return round($this->items->sum('cantidad_recibida') / $total * 100, 1);
    }

    /**
     * Re-audit M2 PATRÓN C (DATOS-C4 / FUNC-A5) · consecutivo atómico.
     *   Antes: `count()+1` sin lock → dos POST concurrentes generaban mismo
     *   numero → violación unique + pérdida del segundo POST.
     *   Ahora: SELECT MAX FOR UPDATE dentro de transacción — MariaDB bloquea
     *   la ventana hasta commit.
     * Re-audit PATRÓN I · usa now('America/Bogota') para determinar el año,
     *   evitando que 31-dic 20:00 Bogotá (01:00 UTC del 1-ene) salte de año.
     */
    public static function siguienteNumero(): string
    {
        $year = now('America/Bogota')->year;
        return \Illuminate\Support\Facades\DB::transaction(function () use ($year) {
            $ultimo = static::query()
                ->where('numero', 'like', "OC-{$year}-%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('numero');
            $seq = $ultimo ? ((int) substr($ultimo, -6)) + 1 : 1;
            return sprintf('OC-%d-%06d', $year, $seq);
        });
    }
}
