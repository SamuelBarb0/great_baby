<?php

namespace App\Modules\Inventario\Models;

use App\Models\User;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Enums\EstadoTraslado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Traslado extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'traslados_inventario';

    // Re-audit M3 PATRÓN η · $guarded (evita mass-assign silencioso) + guard
    //   de transición de estado por state-machine (rechaza saltos ilegales).
    protected $guarded = ['id'];

    protected $casts = [
        'fecha_solicitud' => 'date',
        'fecha_envio' => 'datetime',
        'fecha_ejecucion' => 'datetime',
        'anulado_at' => 'datetime',
        'aprobado_at' => 'datetime',
        'estado' => EstadoTraslado::class,
    ];

    /** MKT-5 · cómo puede venir la mercancía al entregarla o devolverla. */
    public const ESTADOS_MERCANCIA = [
        'completo' => 'Completa y en buen estado',
        'incompleto' => 'Incompleta',
        'con_novedad' => 'Con daños o novedad',
    ];

    /**
     * MKT-5 · préstamo o devolución de muestras de Marketing: una de las dos
     * puntas es un almacén `MKT-` (el mismo criterio del panel de Marketing).
     */
    public function esMuestra(): bool
    {
        return $this->motivo === 'prestamo'
            && ($this->esPrestamoMarketing() || str_starts_with((string) $this->origen?->codigo, 'MKT-'));
    }

    /** Sale de bodega hacia Marketing: es la que necesita aprobación. */
    public function esPrestamoMarketing(): bool
    {
        return $this->motivo === 'prestamo' && str_starts_with((string) $this->destino?->codigo, 'MKT-');
    }

    /**
     * Re-audit M3 PATRÓN γ · state machine formal para traslados:
     *   Borrador → EnTransito|Anulado
     *   EnTransito → Recibido|Anulado
     *   Recibido → Anulado (con reversa)
     */
    protected static function booted(): void
    {
        static::saving(function (Traslado $t) {
            if (! $t->exists || ! $t->isDirty('estado')) return;
            $original = $t->getOriginal('estado');
            $desde = $original instanceof EstadoTraslado ? $original : ($original ? EstadoTraslado::tryFrom($original) : null);
            $hacia = $t->estado instanceof EstadoTraslado ? $t->estado : EstadoTraslado::tryFrom($t->estado);
            if (! self::transicionValida($desde, $hacia)) {
                throw new \RuntimeException(sprintf(
                    'Traslado %s: transición %s → %s no permitida.',
                    $t->getOriginal('numero'), $desde?->value ?? 'null', $hacia?->value ?? 'null',
                ));
            }
        });
    }

    public static function transicionValida(?EstadoTraslado $desde, ?EstadoTraslado $hacia): bool
    {
        if ($desde === null || $hacia === null || $desde === $hacia) return $desde === $hacia;
        return match ($desde) {
            EstadoTraslado::Borrador => in_array($hacia, [EstadoTraslado::EnTransito, EstadoTraslado::Anulado], true),
            EstadoTraslado::EnTransito => in_array($hacia, [EstadoTraslado::Recibido, EstadoTraslado::Anulado], true),
            EstadoTraslado::Recibido => $hacia === EstadoTraslado::Anulado,
            default => false,
        };
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(InventarioUbicacion::class, 'origen_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(InventarioUbicacion::class, 'destino_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function ejecutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ejecutado_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function enviador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    public function recogedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recogido_por');
    }

    /** En una devolución de Marketing, el préstamo que cierra. */
    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prestamo_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TrasladoItem::class, 'traslado_id');
    }

    /**
     * Re-audit M3 PATRÓN π (FUNC-M2) · consecutivo atómico en TZ Colombia
     *   basado en MAX(SUBSTRING) — no en orderByDesc('id').
     *
     *   Antes: `orderByDesc('id')->value('numero')` volaba si un seed/import
     *   metía filas con id alto y numero de año viejo → la secuencia saltaba
     *   al numero viejo y omitía huecos legales.
     *
     *   Ahora: MAX(CAST(SUBSTRING(numero, ...) AS UNSIGNED)) sobre el año
     *   corriente bajo lockForUpdate. Robusto a ordenamiento físico.
     */
    public static function siguienteNumero(): string
    {
        $year = now('America/Bogota')->year;
        return \Illuminate\Support\Facades\DB::transaction(function () use ($year) {
            $prefijoLen = strlen("TRA-{$year}-");
            $max = static::query()
                ->where('numero', 'like', "TRA-{$year}-%")
                ->lockForUpdate()
                ->selectRaw("COALESCE(MAX(CAST(SUBSTRING(numero, ?) AS UNSIGNED)), 0) AS seq", [$prefijoLen + 1])
                ->value('seq');
            $seq = ((int) $max) + 1;
            return sprintf('TRA-%d-%06d', $year, $seq);
        });
    }
}
