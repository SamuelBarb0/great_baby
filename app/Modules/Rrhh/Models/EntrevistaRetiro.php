<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use App\Modules\Rrhh\Enums\MotivoSalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La entrevista de retiro: lo que la persona cuenta al irse.
 *
 * Es el único momento del proceso en que alguien dice la verdad sin costo, y
 * hasta ahora el ERP no lo guardaba en ninguna parte.
 */
class EntrevistaRetiro extends Model
{
    protected $table = 'rrhh_entrevistas_retiro';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha_entrevista' => 'date',
        'motivo_principal' => MotivoSalida::class,
        'funciones_correspondian' => 'bool',
        'recomendaria' => 'bool',
        'accesos_cerrados' => 'bool',
    ];

    /**
     * Los ocho criterios de la experiencia laboral, en el orden del formato.
     *
     * Las columnas van con prefijo —`n_` la nota, `c_` el comentario— para que
     * no se confundan con el resto y para poder recorrerlas sin listarlas otra
     * vez en cada sitio.
     *
     * @var array<string, string>
     */
    public const CRITERIOS = [
        'jefe_inmediato' => 'Relación con su jefe inmediato (liderazgo, comunicación, apoyo)',
        'equipo_trabajo' => 'Relación con su equipo de trabajo (compañerismo, colaboración)',
        'carga_laboral' => 'Carga laboral y volumen de tareas',
        'herramientas' => 'Herramientas e insumos de trabajo brindados',
        'capacitacion' => 'Oportunidades de capacitación y desarrollo',
        'condiciones_salariales' => 'Condiciones salariales y beneficios recibidos',
        'equilibrio_vida' => 'Equilibrio vida personal – vida laboral',
        'ambiente' => 'Ambiente de trabajo y cultura organizacional',
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function entrevistador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entrevistador_id');
    }

    /** Las ocho notas con su etiqueta y su comentario. */
    public function criterios(): array
    {
        $out = [];

        foreach (self::CRITERIOS as $clave => $label) {
            $out[] = [
                'clave' => $clave,
                'label' => $label,
                'nota' => $this->{"n_{$clave}"},
                'comentario' => $this->{"c_{$clave}"},
            ];
        }

        return $out;
    }

    /**
     * El promedio de lo que se calificó, sin contar lo que quedó en blanco.
     *
     * Es un resumen, no un veredicto: una entrevista de retiro no se aprueba
     * ni se reprueba. Sirve para comparar entre salidas.
     */
    public function promedio(): ?float
    {
        $notas = array_values(array_filter(
            array_map(fn (string $k) => $this->{"n_{$k}"}, array_keys(self::CRITERIOS)),
            fn ($v) => $v !== null,
        ));

        return $notas === [] ? null : round(array_sum($notas) / count($notas), 2);
    }

    /**
     * Los criterios peor calificados, que son el motivo de hacer esto.
     *
     * Un promedio de 3,5 no dice nada; «herramientas: 1» y «carga laboral: 2»
     * sí. Devuelve los que están en 1 o 2, que es donde hay algo que arreglar.
     */
    public function puntosBajos(): array
    {
        $bajos = [];

        foreach (self::CRITERIOS as $clave => $label) {
            $nota = $this->{"n_{$clave}"};

            if ($nota !== null && $nota <= 2) {
                $bajos[] = [
                    'label' => $label,
                    'nota' => $nota,
                    'comentario' => $this->{"c_{$clave}"},
                ];
            }
        }

        return $bajos;
    }

    /** El motivo en palabras, resolviendo el «otro, ¿cuál?». */
    public function motivoEnPalabras(): ?string
    {
        if (! $this->motivo_principal) {
            return null;
        }

        return $this->motivo_principal === MotivoSalida::Otro && $this->motivo_otro
            ? $this->motivo_otro
            : $this->motivo_principal->label();
    }

    /** Para mandar a la pantalla. */
    public static function catalogoCriterios(): array
    {
        return array_map(
            fn (string $k) => ['clave' => $k, 'label' => self::CRITERIOS[$k]],
            array_keys(self::CRITERIOS),
        );
    }
}
