<?php

namespace App\Modules\Contabilidad\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class AsientoManual extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'asientos_manuales';
    protected $guarded = ['id'];

    protected $casts = [
        'fecha' => 'date',
        'valor_total' => 'decimal:2',
        'siigo_sync_at' => 'datetime',
        'anulado_at' => 'datetime',
        // Sus líneas en el libro heredan esta línea de negocio (7-nov).
        'linea_negocio' => \App\Modules\Contabilidad\Enums\LineaNegocio::class,
    ];

    public function lineas(): HasMany
    {
        return $this->hasMany(AsientoManualLinea::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cuadra(): bool
    {
        $debe = (float) $this->lineas->sum('debe');
        $haber = (float) $this->lineas->sum('haber');
        return abs($debe - $haber) < 0.01 && $debe > 0;
    }

    protected static function booted(): void
    {
        // QA-FIX #3 · state machine · valida transiciones lícitas.
        static::saving(function (self $a) {
            // Inmutabilidad post-sync (existente).
            if ($a->exists && $a->getOriginal('siigo_journal_id')) {
                foreach (['fecha', 'valor_total', 'siigo_journal_id'] as $c) {
                    if ($a->isDirty($c)) {
                        throw new \RuntimeException("Asiento #{$a->id} ya sincronizado: '{$c}' es inmutable.");
                    }
                }
            }
            // Guardarraíl de transiciones de estado.
            if ($a->exists && $a->isDirty('estado')) {
                $desde = $a->getOriginal('estado');
                $hasta = $a->estado;
                /*
                 * `anulado` es terminal y NO sale de `sincronizado`: un
                 * asiento que ya viajó a SIIGO no se anula de este lado,
                 * porque dejaría los dos libros diciendo cosas distintas.
                 * Ese caso se corrige con un asiento contrario.
                 */
                $ok = match ($desde) {
                    'borrador' => in_array($hasta, ['cuadrado', 'borrador', 'anulado'], true),
                    'cuadrado' => in_array($hasta, ['aprobado', 'borrador', 'anulado'], true),
                    'aprobado' => in_array($hasta, ['sincronizado', 'cuadrado', 'anulado'], true),
                    'sincronizado' => $hasta === 'sincronizado',
                    'anulado' => $hasta === 'anulado',
                    default => false,
                };
                if (! $ok) {
                    throw new \RuntimeException("Asiento #{$a->id}: transición inválida '{$desde}'→'{$hasta}'.");
                }
            }
        });
    }
}
