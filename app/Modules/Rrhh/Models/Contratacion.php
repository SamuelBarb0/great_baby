<?php

namespace App\Modules\Rrhh\Models;

use App\Modules\Rrhh\Enums\ValoracionCandidato;
use App\Modules\Rrhh\Models\Concerns\RegistraBitacora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * El expediente de contratación de una persona ya elegida.
 *
 * Es el tramo entre «lo elegimos» y «ya trabaja acá», que hoy vive en carpetas
 * y en la memoria de Elena. La lista de pendientes NO se guarda: se calcula a
 * partir de las fechas que faltan, así que no hay manera de marcar como hecho
 * un paso que no se hizo.
 */
class Contratacion extends Model
{
    use RegistraBitacora;
    use SoftDeletes;

    protected $table = 'rrhh_contrataciones';

    protected $fillable = [
        'candidato_id', 'vacante_id', 'estado', 'salario', 'tipo_contrato',
        'fecha_ingreso', 'examen_at', 'examen_resultado', 'examen_observacion',
        'contrato_firmado_at', 'orden_id',
        'eps', 'eps_at', 'arl', 'arl_at', 'arl_fecha',
        'pension', 'pension_at', 'caja', 'caja_at',
        'empleado_id', 'completada_at', 'cancelada_at', 'motivo_cancelacion',
    ];

    protected $casts = [
        'salario' => 'decimal:2',
        'fecha_ingreso' => 'date',
        'examen_at' => 'datetime',
        'contrato_firmado_at' => 'datetime',
        'eps_at' => 'datetime',
        'arl_at' => 'datetime',
        'arl_fecha' => 'date',
        'pension_at' => 'datetime',
        'caja_at' => 'datetime',
        'completada_at' => 'datetime',
        'cancelada_at' => 'datetime',
    ];

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    public function vacante(): BelongsTo
    {
        return $this->belongsTo(Vacante::class);
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    /** La autorización de la Junta Directiva que permitió esta contratación. */
    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenContratacion::class, 'orden_id');
    }

    /**
     * Las cuatro afiliaciones de ley.
     *
     * Van juntas porque se hacen juntas y porque faltar una sola ya es un
     * problema: si falta la ARL y la persona se accidenta el primer día, la
     * empresa responde.
     */
    public const AFILIACIONES = [
        'eps' => 'EPS (salud)',
        'arl' => 'ARL (riesgos laborales)',
        'pension' => 'Fondo de pensiones',
        'caja' => 'Caja de compensación',
    ];

    /**
     * Qué falta para poder cerrar la contratación.
     *
     * Devuelve la lista en palabras, para mostrarla y para frenar el cierre
     * con el mismo cálculo. Si fueran dos listas distintas, un día dirían
     * cosas distintas.
     *
     * @return list<string>
     */
    public function loQueFalta(): array
    {
        $falta = [];

        if (! $this->examen_at) {
            $falta[] = 'Examen médico de ingreso';
        }
        if (! $this->contrato_firmado_at) {
            $falta[] = 'Firma del contrato';
        }
        if (! $this->fecha_ingreso) {
            $falta[] = 'Fecha de ingreso';
        }

        foreach (self::AFILIACIONES as $campo => $label) {
            if (! $this->{$campo.'_at'}) {
                $falta[] = $label;
            }
        }

        return $falta;
    }

    /**
     * ¿El examen médico frena la contratación?
     *
     * «No apto» no es un trámite más que quede pendiente: significa que esta
     * persona no puede ocupar ese puesto, y seguir adelante sería exponerla a
     * ella y a la empresa.
     */
    public function examenLoFrena(): bool
    {
        return $this->examen_resultado === ValoracionCandidato::NoApto->value;
    }

    public function estaCompletada(): bool
    {
        return $this->estado === 'completada';
    }
}
