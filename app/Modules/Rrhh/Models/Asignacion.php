<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use App\Modules\Rrhh\Models\Concerns\RegistraBitacora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Algo que la empresa le entregó a una persona.
 *
 * «La persona se fue y se llevó la tablet. Y no nos ha entregado la tablet»
 * (Elena, 19-oct). El problema no fue la tablet: fue que nadie sabía que la
 * tenía. Por eso lo que importa acá no es el inventario, sino poder preguntar
 * en cualquier momento «¿qué tiene esta persona?» y que la respuesta no
 * dependa de que un jefe se acuerde.
 */
class Asignacion extends Model
{
    use RegistraBitacora;
    use SoftDeletes;

    protected $table = 'rrhh_asignaciones';

    protected $fillable = [
        'empleado_id', 'tipo', 'descripcion', 'identificador', 'cantidad', 'valor',
        'fecha_entrega', 'entregada_por_id', 'observaciones',
        'estado', 'fecha_devolucion', 'recibida_por_id', 'estado_devolucion',
    ];

    protected $casts = [
        'fecha_entrega' => 'date',
        'fecha_devolucion' => 'date',
        'cantidad' => 'integer',
        'valor' => 'decimal:2',
    ];

    /**
     * Dotación y herramientas van juntas porque se manejan igual, pero se
     * distinguen porque significan cosas distintas: la dotación es una
     * obligación del empleador, la herramienta es un activo que se presta.
     */
    public const TIPOS = [
        'dotacion' => 'Dotación',
        'herramienta' => 'Herramienta de trabajo',
        'equipo' => 'Equipo de cómputo',
        'otro' => 'Otro',
    ];

    public const ESTADOS = [
        'asignada' => 'Con la persona',
        'devuelta' => 'Devuelta',
        'perdida' => 'No devuelta',
        'dada_de_baja' => 'Dada de baja',
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function entregadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entregada_por_id');
    }

    public function recibidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibida_por_id');
    }

    public function reportes(): HasMany
    {
        return $this->hasMany(ReporteAsignacion::class, 'asignacion_id')->latest('id');
    }

    /** Lo que todavía está en manos de la persona. */
    public function scopePendientes(Builder $q): Builder
    {
        return $q->where('estado', 'asignada');
    }

    public function estaPendiente(): bool
    {
        return $this->estado === 'asignada';
    }

    public function tipoLabel(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function estadoLabel(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    /** Cómo se nombra en una lista: la descripción con su identificador. */
    public function getRotuloAttribute(): string
    {
        return trim($this->descripcion.($this->identificador ? " · {$this->identificador}" : ''));
    }
}
