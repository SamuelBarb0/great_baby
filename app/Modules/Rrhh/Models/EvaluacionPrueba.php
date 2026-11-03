<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El formato GH-F-015 que el jefe inmediato llena sobre alguien en prueba.
 *
 * Nueve aspectos de 1 a 5, qué resaltar, qué mejorar y el veredicto. Antes el
 * sistema guardaba sólo «continúa / no continúa» y una nota libre: suficiente
 * para seguir el flujo, insuficiente el día que alguien reclama la decisión.
 */
class EvaluacionPrueba extends Model
{
    protected $table = 'rrhh_evaluaciones_prueba';

    protected $fillable = [
        'empleado_id', 'fecha_evaluacion', 'jefe_id', 'jefe_cargo',
        'cumplimiento_normas', 'cumplimiento_horario', 'cumplimiento_tareas',
        'relaciones_interpersonales', 'recibe_retroalimentacion',
        'capacidad_aprendizaje', 'resolucion_problemas', 'actitud_trabajo',
        'manejo_personal',
        'aspectos_resaltar', 'aspectos_mejorar',
        'resultado', 'motivo', 'observaciones',
    ];

    protected $casts = ['fecha_evaluacion' => 'date'];

    /**
     * Los nueve aspectos del formato, en su orden y con su texto.
     *
     * Están acá y no repartidos entre la pantalla y el impreso para que no
     * puedan decir cosas distintas: el jefe calificaría una cosa y el papel
     * mostraría otra.
     *
     * `manejo_personal` queda al final y marcado, porque el formato dice «si
     * aplica»: a un auxiliar sin gente a cargo no se le puede exigir una nota
     * ahí, y ponerle una baja por no tener equipo sería castigarlo por el
     * cargo que ocupa.
     *
     * @var array<string, array{label:string, si_aplica:bool}>
     */
    public const ASPECTOS = [
        'cumplimiento_normas' => ['label' => 'Cumplimiento de normas y procedimientos de la empresa', 'si_aplica' => false],
        'cumplimiento_horario' => ['label' => 'Cumplimiento en el horario de trabajo', 'si_aplica' => false],
        'cumplimiento_tareas' => ['label' => 'Cumplimiento de las tareas asignadas', 'si_aplica' => false],
        'relaciones_interpersonales' => ['label' => 'Relaciones interpersonales (jefes, compañeros)', 'si_aplica' => false],
        'recibe_retroalimentacion' => ['label' => 'Disposición para recibir retroalimentación y corrección', 'si_aplica' => false],
        'capacidad_aprendizaje' => ['label' => 'Capacidad de aprendizaje', 'si_aplica' => false],
        'resolucion_problemas' => ['label' => 'Capacidad para resolver problemas', 'si_aplica' => false],
        'actitud_trabajo' => ['label' => 'Actitud hacia el trabajo', 'si_aplica' => false],
        'manejo_personal' => ['label' => 'Manejo de personal', 'si_aplica' => true],
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function jefe(): BelongsTo
    {
        return $this->belongsTo(User::class, 'jefe_id');
    }

    /**
     * El promedio de lo que SÍ se calificó.
     *
     * Los nulos no entran: «manejo de personal» en blanco significa que no
     * aplica, y contarlo como cero hundiría el promedio de cualquiera que no
     * tenga gente a cargo.
     *
     * Es un resumen para leer de un vistazo, no la decisión: el formato no
     * dice en ninguna parte que un promedio bajo signifique «no continúa», y
     * el ERP tampoco lo decide por nadie.
     */
    public function promedio(): ?float
    {
        $notas = array_filter(
            array_map(fn (string $k) => $this->{$k}, array_keys(self::ASPECTOS)),
            fn ($v) => $v !== null,
        );

        return $notas === [] ? null : round(array_sum($notas) / count($notas), 2);
    }

    /** Las notas con su etiqueta, para pintar o imprimir. */
    public function calificaciones(): array
    {
        $out = [];

        foreach (self::ASPECTOS as $clave => $a) {
            $out[] = [
                'clave' => $clave,
                'label' => $a['label'],
                'si_aplica' => $a['si_aplica'],
                'nota' => $this->{$clave},
            ];
        }

        return $out;
    }

    /** Lo que faltó calificar, sin contar lo que es «si aplica». */
    public function sinCalificar(): array
    {
        $faltan = [];

        foreach (self::ASPECTOS as $clave => $a) {
            if (! $a['si_aplica'] && $this->{$clave} === null) {
                $faltan[] = mb_strtolower($a['label']);
            }
        }

        return $faltan;
    }

    /** Para mandar a la pantalla. */
    public static function catalogo(): array
    {
        return array_map(
            fn (string $k) => ['clave' => $k] + self::ASPECTOS[$k],
            array_keys(self::ASPECTOS),
        );
    }
}
