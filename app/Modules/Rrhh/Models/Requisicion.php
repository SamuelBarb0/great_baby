<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use App\Modules\Rrhh\Enums\EstadoRequisicion;
use App\Modules\Rrhh\Models\Concerns\RegistraBitacora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La solicitud de personal que hace un líder de área.
 *
 * Es el arranque de todo el proceso de selección y hoy es un papel: «a veces sí
 * me dan la hojita, a veces no me dan la hojita, se me pierde el rastro», dijo
 * Elena. Mientras esto sea papel, nadie puede responder en qué va una
 * contratación sin preguntarle a alguien.
 *
 * Quien decide la CONTRATACIÓN no es el líder: eso lo autoriza la Junta
 * Directiva con otro formato («la orden»). Acá sólo vive el requerimiento.
 */
class Requisicion extends Model
{
    use RegistraBitacora;
    use SoftDeletes;

    protected $table = 'rrhh_requisiciones';

    protected $fillable = [
        'numero', 'cargo', 'area', 'numero_vacantes', 'motivo', 'funciones',
        'competencias', 'estudios', 'experiencia', 'salario_min', 'salario_max',
        'tipo_contrato', 'fecha_requerida', 'solicitante_id', 'estado',
        'enviada_at', 'aprobada_por_id', 'aprobada_at', 'rechazada_at',
        'motivo_rechazo', 'observaciones',
    ];

    protected $casts = [
        'estado' => EstadoRequisicion::class,
        'numero_vacantes' => 'integer',
        'salario_min' => 'decimal:2',
        'salario_max' => 'decimal:2',
        'fecha_requerida' => 'date',
        'enviada_at' => 'datetime',
        'aprobada_at' => 'datetime',
        'rechazada_at' => 'datetime',
    ];

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    public function aprobadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobada_por_id');
    }

    public function vacantes(): HasMany
    {
        return $this->hasMany(Vacante::class, 'requisicion_id');
    }

    /**
     * Las que puede ver un líder de área: las suyas y nada más.
     *
     * Un jefe no tiene por qué ver cuánto se le ofrece a un cargo de otra área.
     */
    public function scopeDeSolicitante(Builder $q, int $userId): Builder
    {
        return $q->where('solicitante_id', $userId);
    }

    public function scopePendientesDeAprobar(Builder $q): Builder
    {
        return $q->where('estado', EstadoRequisicion::Enviada->value);
    }

    /**
     * Consecutivo del año: RQ-2026-0001.
     *
     * Se calcula sobre el máximo existente del año y no sobre el conteo, que
     * repetiría número en cuanto alguien borre una.
     */
    public static function siguienteNumero(): string
    {
        $anio = now()->year;
        $ultimo = static::withTrashed()
            ->where('numero', 'like', "RQ-{$anio}-%")
            ->selectRaw('MAX(CAST(SUBSTRING(numero, 9) AS UNSIGNED)) as n')
            ->value('n');

        return sprintf('RQ-%d-%04d', $anio, ((int) $ultimo) + 1);
    }

    /** Cuántas vacantes faltan por abrir de las que se pidieron. */
    public function getVacantesPendientesAttribute(): int
    {
        return max(0, $this->numero_vacantes - $this->vacantes()->count());
    }
}
