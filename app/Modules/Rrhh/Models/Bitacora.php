<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Quién hizo qué y cuándo, en cualquier registro del módulo.
 *
 * Elena lo pidió con estas palabras: que el jefe que solicitó personal pueda
 * abrir su móvil y ver «ya mis candidatos van en la etapa tal» sin tener que
 * preguntarle a nadie. Eso sólo funciona si cada movimiento deja rastro solo,
 * sin que alguien se acuerde de anotarlo.
 *
 * No tiene `updated_at` a propósito: una línea de bitácora no se edita. Si algo
 * cambió, es otra línea.
 */
class Bitacora extends Model
{
    protected $table = 'rrhh_bitacora';

    public const UPDATED_AT = null;

    protected $fillable = [
        'registrable_type', 'registrable_id', 'evento', 'descripcion',
        'de_estado', 'a_estado', 'datos', 'user_id',
    ];

    protected $casts = [
        'datos' => 'array',
        'created_at' => 'datetime',
    ];

    public function registrable(): MorphTo
    {
        return $this->morphTo();
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Lo que se pinta en la línea de tiempo cuando no hay descripción propia. */
    public function getResumenAttribute(): string
    {
        if ($this->descripcion) {
            return $this->descripcion;
        }

        return $this->de_estado && $this->a_estado
            ? "{$this->de_estado} → {$this->a_estado}"
            : $this->evento;
    }
}
