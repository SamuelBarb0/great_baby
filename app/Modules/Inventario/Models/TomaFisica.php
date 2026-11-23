<?php

namespace App\Modules\Inventario\Models;

use App\Models\User;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Enums\EstadoTomaFisica;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class TomaFisica extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'tomas_fisicas';

    // Re-audit M3 PATRÓN η · $guarded (evita mass-assign silencioso).
    protected $guarded = ['id'];

    protected $casts = [
        'fecha_conteo' => 'date',
        'cerrada_at' => 'datetime',
        'estado' => EstadoTomaFisica::class,
        'valor_ajuste' => 'decimal:2',
    ];

    /**
     * Re-audit M3 PATRÓN γ + DATOS-M5 · state machine para toma física.
     *   Borrador → EnConteo | Anulada
     *   EnConteo → Ajustada | Anulada
     *   Ajustada / Anulada = finales
     */
    protected static function booted(): void
    {
        static::saving(function (TomaFisica $t) {
            if (! $t->exists || ! $t->isDirty('estado')) return;
            $original = $t->getOriginal('estado');
            $desde = $original instanceof EstadoTomaFisica ? $original : ($original ? EstadoTomaFisica::tryFrom($original) : null);
            $hacia = $t->estado instanceof EstadoTomaFisica ? $t->estado : EstadoTomaFisica::tryFrom($t->estado);
            if (! self::transicionValida($desde, $hacia)) {
                throw new \RuntimeException(sprintf(
                    'TomaFisica %s: transición %s → %s no permitida.',
                    $t->getOriginal('numero'), $desde?->value ?? 'null', $hacia?->value ?? 'null',
                ));
            }
        });
    }

    public static function transicionValida(?EstadoTomaFisica $desde, ?EstadoTomaFisica $hacia): bool
    {
        if ($desde === null || $hacia === null || $desde === $hacia) return $desde === $hacia;
        return match ($desde) {
            EstadoTomaFisica::Borrador => in_array($hacia, [EstadoTomaFisica::EnConteo, EstadoTomaFisica::Anulada], true),
            EstadoTomaFisica::EnConteo => in_array($hacia, [EstadoTomaFisica::Ajustada, EstadoTomaFisica::Anulada], true),
            default => false, // Ajustada, Anulada = terminales
        };
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(InventarioUbicacion::class, 'ubicacion_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TomaFisicaItem::class, 'toma_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }

    public function cerrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }

    /**
     * LA TOMA ABIERTA QUE CHOCA CON ESTE SITIO, SI LA HAY.
     *
     * No basta con mirar el mismo `ubicacion_id`. Una toma sobre una SEDE
     * cuenta la sede más todas sus posiciones (`PrepararTomaFisica` congela la
     * suma), así que choca con cualquier toma de uno de sus racks — y al
     * revés. Comparando el id exacto, las dos conviven, las dos congelan las
     * mismas unidades físicas y las dos escriben su ajuste: el kardex baja dos
     * veces el mismo faltante y quedan dos asientos contables por una sola
     * diferencia.
     *
     * Se mira en los dos sentidos:
     *   • una toma abierta cuyo sitio cae DENTRO del alcance que se pide
     *     (pedir la bodega cuando ya se está contando uno de sus racks);
     *   • una toma abierta de la sede a la que pertenece el sitio pedido
     *     (pedir un rack cuando ya se está contando la bodega entera).
     *
     * La jerarquía del ERP es de dos niveles —sede y posiciones colgando de
     * ella—, así que con el padre y las hijas se cubre. Si algún día se
     * anidara un tercer nivel, esto habría que volverlo recursivo.
     */
    public static function abiertaQueChoqueCon(int $ubicacionId): ?self
    {
        $alcance = InventarioUbicacion::query()
            ->where('bodega_id', $ubicacionId)
            ->pluck('id')
            ->map(fn ($x) => (int) $x)
            ->push($ubicacionId)
            ->all();

        $padre = (int) (InventarioUbicacion::whereKey($ubicacionId)->value('bodega_id') ?? 0);

        return static::query()
            ->whereIn('estado', [EstadoTomaFisica::Borrador, EstadoTomaFisica::EnConteo])
            ->whereIn('ubicacion_id', $padre ? [...$alcance, $padre] : $alcance)
            ->with('ubicacion:id,codigo,nombre')
            ->first();
    }

    /**
     * Re-audit M3 PATRÓN π (FUNC-M2) · consecutivo atómico basado en MAX(SUBSTRING)
     *   por seguridad frente a filas con id físico desordenado (seed/import
     *   con id alto y año viejo).
     */
    public static function siguienteNumero(): string
    {
        $year = now('America/Bogota')->year;
        return \Illuminate\Support\Facades\DB::transaction(function () use ($year) {
            $prefijoLen = strlen("TF-{$year}-");
            $max = static::query()
                ->where('numero', 'like', "TF-{$year}-%")
                ->lockForUpdate()
                ->selectRaw("COALESCE(MAX(CAST(SUBSTRING(numero, ?) AS UNSIGNED)), 0) AS seq", [$prefijoLen + 1])
                ->value('seq');
            $seq = ((int) $max) + 1;
            return sprintf('TF-%d-%06d', $year, $seq);
        });
    }
}
