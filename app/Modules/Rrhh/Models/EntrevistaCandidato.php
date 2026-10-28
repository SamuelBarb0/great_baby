<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase C · Una entrevista de selección.
 *
 * Dos formas distintas en la misma tabla: la de «saber hacer» y la
 * psicológica. El cuestionario de cada una vive acá como catálogo —igual que
 * los nueve aspectos del período de prueba— para que el formato del área y lo
 * que pregunta la pantalla no puedan separarse.
 */
class EntrevistaCandidato extends Model
{
    protected $table = 'rrhh_entrevistas_candidato';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha' => 'date',
        'respuestas' => 'array',
        'calificaciones' => 'array',
        'total' => 'integer',
    ];

    public const SABER_HACER = 'saber_hacer';

    public const PSICOLOGICA = 'psicologica';

    public const TIPOS = [
        self::SABER_HACER => 'Entrevista de saber hacer',
        self::PSICOLOGICA => 'Entrevista psicológica',
    ];

    /**
     * El cuestionario de saber hacer, tal como lo trae el formato.
     *
     * Tres bloques y quince preguntas (2 + 3 + 10). El primero no indaga nada: está para
     * romper el hielo, y por eso va primero y se conserva aunque parezca
     * prescindible —una entrevista que arranca preguntando por el promedio
     * académico no saca nada de nadie—.
     *
     * @var array<string, array{titulo:string, preguntas:array<string,string>}>
     */
    public const SABER_HACER_BLOQUES = [
        'generalidades' => [
            'titulo' => 'Generalidades',
            'nota' => 'Establezca un buen clima tratando un tema de interés general, para romper tensión.',
            'preguntas' => [
                'g1' => '¿Conoce los productos de esta empresa?',
                'g2' => '¿Los ha utilizado? ¿Cómo le parecen?',
            ],
        ],
        'academica' => [
            'titulo' => 'Formación académica',
            'nota' => null,
            'preguntas' => [
                'a1' => '¿Qué estudios ha realizado? ¿Qué cursos adicionales?',
                'a2' => '¿Por qué en esas áreas?',
                'a3' => 'Durante sus estudios, ¿qué materias se le facilitaban más? ¿En cuáles le iba mejor? ¿En cuánto terminó su promedio general académico?',
            ],
        ],
        'experiencia' => [
            'titulo' => 'Experiencia laboral y saber-hacer',
            'nota' => null,
            'preguntas' => [
                'e1' => '¿Cuántos años lleva trabajando?',
                'e2' => '¿Qué cargos ha desempeñado y por cuánto tiempo?',
                'e3' => '¿Habitualmente qué funciones o actividades desempeña?',
                'e4' => 'Respecto a lo que ha tenido que hacer en sus trabajos, ¿en qué se considera más habilidoso o qué sabe hacer mejor?',
                'e5' => '¿En qué se considera menos hábil?',
                'e6' => '¿Cuál es el problema más grande que se le ha presentado a nivel laboral y cómo lo resolvió?',
                'e7' => '¿Qué le aportaría a esta organización de acuerdo con su experiencia?',
                'e8' => '¿Cuál ha sido su éxito más grande y cómo hizo para alcanzarlo?',
                'e9' => '¿Cuál ha sido su fracaso más grande y cómo lo superó?',
                'e10' => '¿Cuál es su aspiración salarial?',
            ],
        ],
    ];

    /**
     * Los seis ítems que se califican de 1 a 5.
     *
     * El total va de 6 a 30. El formato NO dice qué puntaje descarta a nadie,
     * así que el sistema tampoco: muestra el número y quien decide decide.
     * Inventar un umbral sería inventarle una política al área.
     *
     * @var array<string, string>
     */
    public const SABER_HACER_ITEMS = [
        'estudios' => 'Los estudios académicos son suficientes y adecuados para el desarrollo del cargo.',
        'estabilidad' => 'La experiencia laboral es suficiente y garantiza estabilidad.',
        'potencial' => 'Es una persona con potencial que podría desarrollar trabajos que beneficien a la empresa.',
        'ajuste' => 'La experiencia laboral se ajusta a las necesidades del cargo.',
        'salario' => 'La aspiración salarial es acorde con el salario que ofrece la compañía.',
        'interes' => 'Revela alto interés por el cargo y por pertenecer a la organización.',
    ];

    /**
     * La entrevista psicológica · GH-F-004.
     *
     * Tres tablas que crecen —familia, estudios y empleos— y cinco bloques de
     * texto abierto. Lo que más valor tiene de este formato no es ninguno de
     * los cinco: es el **motivo de retiro de cada empleo anterior**, que es
     * donde se ve un patrón que una sola entrevista no muestra.
     *
     * @var array<string, array{titulo:string, campos:array<string,string>}>
     */
    public const PSICOLOGICA_BLOQUES = [
        'familia' => [
            'titulo' => 'Entorno familiar',
            'tabla' => ['nombre' => 'Nombre', 'edad' => 'Edad', 'parentesco' => 'Parentesco',
                'escolaridad' => 'Escolaridad', 'ocupacion' => 'Ocupación'],
            'campos' => [
                'valores' => '¿Cuáles son los principales valores de la familia?',
                'metas' => '¿Qué metas tienen como familia?',
            ],
        ],
        'academica' => [
            'titulo' => 'Información académica',
            'tabla' => ['nivel' => 'Nivel', 'programa' => 'Programa', 'institucion' => 'Institución',
                'ano' => 'Año en que terminó', 'ciudad' => 'Ciudad'],
            'campos' => [
                'actuales' => 'Estudios actuales: programa, semestre, horario (diurno o nocturno)',
                'intereses' => 'Áreas de mayor interés académico, materias preferidas y evitadas',
            ],
        ],
        'laboral' => [
            'titulo' => 'Experiencia laboral',
            // El motivo de retiro es la columna que justifica el bloque: tres
            // salidas por lo mismo dicen más que cualquier respuesta.
            'tabla' => ['empresa' => 'Empresa', 'cargo' => 'Cargo', 'tiempo' => 'Tiempo',
                'motivo_retiro' => 'Motivo del retiro', 'observaciones' => 'Observaciones'],
            'campos' => [],
        ],
        'persona' => [
            'titulo' => 'La persona',
            'tabla' => null,
            'campos' => [
                'autoconcepto' => 'Autoconcepto: puntos fuertes y aspectos susceptibles de mejora',
                'proyeccion' => 'Proyección y plan de vida',
                'intereses' => 'Intereses y actividades lúdicas y de recreación',
                'estres' => '¿Qué situaciones le generan estrés?',
            ],
        ],
    ];

    /** Los niveles educativos del formato, para el desplegable de la tabla. */
    public const NIVELES_EDUCATIVOS = [
        'Bachillerato', 'Técnico', 'Tecnológico', 'Universitario', 'Postgrado', 'Otro',
    ];

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por_id');
    }

    public function tipoLabel(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    /** La respuesta a una pregunta, o null si quedó en blanco. */
    public function respuesta(string $clave): ?string
    {
        $v = $this->respuestas[$clave] ?? null;

        return blank($v) ? null : (string) $v;
    }

    /** Las filas de una de las tres tablas de la psicológica. */
    public function filasDe(string $bloque): array
    {
        $filas = $this->respuestas["{$bloque}_filas"] ?? [];

        return is_array($filas) ? array_values(array_filter($filas, fn ($f) => filled(array_filter((array) $f)))) : [];
    }

    /**
     * Cuántas preguntas quedaron sin contestar.
     *
     * Se cuenta y se avisa, pero no se obliga: una entrevista es una
     * conversación, no un formulario, y hay preguntas que no se alcanzan a
     * hacer. Obligarlas llevaría a llenarlas con cualquier cosa.
     */
    public function sinContestar(): int
    {
        if ($this->tipo !== self::SABER_HACER) {
            return 0;
        }

        $faltan = 0;

        foreach (self::SABER_HACER_BLOQUES as $b) {
            foreach (array_keys($b['preguntas']) as $clave) {
                if ($this->respuesta($clave) === null) {
                    $faltan++;
                }
            }
        }

        return $faltan;
    }

    /**
     * El total de la evaluación, de 6 a 30.
     *
     * Se recalcula desde las calificaciones en vez de confiar en la columna:
     * si alguien corrige un ítem y el total viniera del cliente, el papel
     * saldría con un número que no suma.
     */
    public function totalCalculado(): ?int
    {
        $notas = array_filter(
            array_map('intval', (array) ($this->calificaciones ?? [])),
            fn (int $n) => $n >= 1 && $n <= 5,
        );

        return $notas === [] ? null : array_sum($notas);
    }

    /** El resumen que ve el jefe en el tablero: el total y nada más. */
    public function paraElTablero(): array
    {
        return [
            'tipo' => $this->tipo,
            'tipo_label' => $this->tipoLabel(),
            'fecha' => $this->fecha?->toDateString(),
            'entrevistador' => $this->entrevistador_nombre,
            'total' => $this->totalCalculado(),
            'sin_contestar' => $this->sinContestar(),
        ];
    }
}
