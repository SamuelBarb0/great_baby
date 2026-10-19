<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use App\Modules\Rrhh\Enums\EtapaCandidato;
use App\Modules\Rrhh\Enums\ValoracionCandidato;
use App\Modules\Rrhh\Models\Concerns\RegistraBitacora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * El aspirante a una vacante, con su recorrido por las etapas de selección.
 *
 * `etapa` es el campo que el jefe solicitante mira desde el móvil, así que
 * cada movimiento se registra en bitácora con quién lo movió y cuándo.
 */
class Candidato extends Model
{
    use RegistraBitacora;
    use SoftDeletes;

    /** Una terna es de tres. Más de tres deja de ser una terna. */
    public const TAMANO_TERNA = 3;

    protected $table = 'rrhh_candidatos';

    protected $fillable = [
        'vacante_id', 'nombre', 'documento', 'email', 'telefono', 'cv_path',
        'fuente', 'etapa', 'calificacion', 'notas', 'valoracion',
        'valoracion_observacion', 'antecedentes_revisados',
        'antecedentes_resultado', 'motivo_descarte', 'elegido_at', 'elegido_por_id',
    ];

    protected $casts = [
        'etapa' => EtapaCandidato::class,
        'valoracion' => ValoracionCandidato::class,
        'antecedentes_revisados' => 'boolean',
        'elegido_at' => 'datetime',
    ];

    public function vacante(): BelongsTo
    {
        return $this->belongsTo(Vacante::class);
    }

    public function elegidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'elegido_por_id');
    }

    public function scopeEnTablero(Builder $q): Builder
    {
        return $q->whereNotIn('etapa', [
            EtapaCandidato::Descartado->value, EtapaCandidato::Seleccionado->value,
        ]);
    }

    /** Los que se le presentan al jefe para que elija. */
    public function scopeDeLaTerna(Builder $q): Builder
    {
        return $q->where('etapa', EtapaCandidato::Terna->value);
    }

    /**
     * Mueve el candidato de etapa dejando rastro.
     *
     * Devuelve false si ya estaba ahí, para no llenar la bitácora de líneas
     * vacías cuando alguien arrastra la tarjeta a la misma columna.
     */
    public function moverA(EtapaCandidato $etapa, ?string $nota = null): bool
    {
        return $this->cambiarEstado($etapa->value, $nota, [], 'etapa');
    }

    /**
     * ¿Qué impide llevarlo a esta etapa? Devuelve el motivo, o null si se puede.
     *
     * Las dos reglas son de la reunión del 19-oct, no inventadas:
     *
     *   · Elena revisa los antecedentes judiciales ANTES de citar a entrevista.
     *     Si el sistema deja citar sin revisarlos, el paso se salta solo el día
     *     que haya afán — que es justo el día en que importa.
     *   · Una terna es de tres. El cuarto candidato en terna significa que a
     *     alguien se le va a presentar una lista que ya no es una terna.
     */
    public function porQueNoPuedeIrA(EtapaCandidato $destino): ?string
    {
        if ($this->etapa->esFinal()) {
            return 'Este candidato ya salió del proceso ('.$this->etapa->label().').';
        }

        if ($destino->exigeAntecedentes() && ! $this->antecedentes_revisados) {
            return 'Antes de citar hay que revisar los antecedentes judiciales.';
        }

        if ($destino === EtapaCandidato::Terna && $this->etapa !== EtapaCandidato::Terna) {
            $enTerna = static::where('vacante_id', $this->vacante_id)->deLaTerna()->count();
            if ($enTerna >= self::TAMANO_TERNA) {
                return 'La terna ya está completa con '.self::TAMANO_TERNA.' candidatos.';
            }
        }

        return null;
    }
}
