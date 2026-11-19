<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una pregunta que el jefe del área agregó a la entrevista de su vacante.
 *
 * Las del formato —`EntrevistaCandidato::SABER_HACER_BLOQUES`— son ejemplos de
 * lo que se puede preguntar y viven en código. Éstas son las que agrega quien
 * sabe qué hay que preguntarle a alguien para ESTE cargo.
 *
 * Nunca se borran: ver la migración.
 */
class PreguntaEntrevista extends Model
{
    protected $table = 'rrhh_preguntas_entrevista';

    protected $guarded = ['id'];

    protected $casts = [
        'activa' => 'boolean',
        'orden' => 'integer',
    ];

    public function vacante(): BelongsTo
    {
        return $this->belongsTo(Vacante::class);
    }

    public function creadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por_id');
    }

    /**
     * La clave con la que se guarda su respuesta.
     *
     * Sale del id y no del texto: renombrar la pregunta no puede huérfanar la
     * respuesta que alguien ya dio, y dos preguntas parecidas en vacantes
     * distintas no pueden pisarse. La `x` la separa de las del formato, que
     * son g1, a1, e10.
     */
    public function clave(): string
    {
        return 'x'.$this->id;
    }

    public function scopeActivas(Builder $q): Builder
    {
        return $q->where('activa', true);
    }

    /**
     * Las que se le muestran a quien está entrevistando AHORA.
     *
     * Sólo las activas: una desactivada ya no se pregunta, pero sigue viéndose
     * en las entrevistas que la respondieron.
     */
    public static function paraEntrevistar(int $vacanteId, string $tipo): array
    {
        return self::query()
            ->where('vacante_id', $vacanteId)
            ->where('tipo', $tipo)
            ->activas()
            ->orderBy('orden')->orderBy('id')
            ->get()
            ->map(fn (self $p) => [
                'id' => $p->id,
                'clave' => $p->clave(),
                'pregunta' => $p->pregunta,
            ])
            ->all();
    }

    /**
     * Las que hay que IMPRIMIR o mostrar de una entrevista ya hecha.
     *
     * Incluye las desactivadas que tengan respuesta: si se preguntó, va en la
     * hoja. Lo contrario sería imprimir una entrevista a la que le faltan
     * preguntas que sí se hicieron.
     */
    public static function paraMostrar(int $vacanteId, string $tipo, array $respuestas): array
    {
        return self::query()
            ->where('vacante_id', $vacanteId)
            ->where('tipo', $tipo)
            ->orderBy('orden')->orderBy('id')
            ->get()
            ->filter(fn (self $p) => $p->activa || filled($respuestas[$p->clave()] ?? null))
            ->map(fn (self $p) => [
                'id' => $p->id,
                'clave' => $p->clave(),
                'pregunta' => $p->pregunta,
                'activa' => $p->activa,
            ])
            ->values()
            ->all();
    }
}
