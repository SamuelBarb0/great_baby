<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase G · Un formato del sistema documental.
 *
 * Es el renglón del listado maestro: código, nombre, proceso, versión y desde
 * cuándo rige esa versión. Lo que antes estaba escrito a mano en cada
 * plantilla.
 */
class FormatoControlado extends Model
{
    protected $table = 'formatos_controlados';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha_version' => 'date',
        'actualizado_at' => 'datetime',
    ];

    public const PROCESO_GH = 'Gestión Humana';

    public const PROCESO_SST = 'Seguridad y Salud en el Trabajo';

    /**
     * Los formatos que el ERP imprime hoy, tal como los mandó el área.
     *
     * Esto es la SEMILLA, no la verdad: una vez sembrados manda la tabla,
     * porque el que actualiza versiones es el área desde su pantalla. Igual
     * que con los permisos, donde la matriz siembra y la base decide.
     *
     * Las fechas son las que traen los formatos en papel. Las dos que no
     * tienen código llegaron así: la entrevista de retiro dice «GH-F-XX» y la
     * entrega de cargo no trae recuadro.
     *
     * @var array<string, array{codigo:?string, nombre:string, proceso:string, version:string, fecha:?string}>
     */
    public const SEMILLA = [
        'requisicion' => [
            'codigo' => null,
            'nombre' => 'Requisición de personal',
            'proceso' => self::PROCESO_GH,
            'version' => '001',
            'fecha' => null,
        ],
        'constancia_induccion' => [
            'codigo' => null,
            'nombre' => 'Constancia de inducción',
            'proceso' => self::PROCESO_GH,
            'version' => '001',
            'fecha' => null,
        ],
        'solicitud_documentos' => [
            'codigo' => 'GH-F-007',
            'nombre' => 'Solicitud de documentos para contratación',
            'proceso' => self::PROCESO_GH,
            'version' => '001',
            'fecha' => '2023-05-30',
        ],
        'evaluacion_prueba' => [
            'codigo' => 'GH-F-015',
            'nombre' => 'Evaluación período de prueba',
            'proceso' => self::PROCESO_GH,
            'version' => '001',
            'fecha' => '2023-07-11',
        ],
        'orden_contratacion' => [
            'codigo' => 'GH-F-018',
            'nombre' => 'Autorización de contratación',
            'proceso' => self::PROCESO_GH,
            'version' => '001',
            'fecha' => '2026-01-07',
        ],
        'entrevista_retiro' => [
            'codigo' => null,
            'nombre' => 'Entrevista de retiro',
            'proceso' => self::PROCESO_GH,
            'version' => '001',
            'fecha' => '2026-01-07',
        ],
        'entrega_cargo' => [
            'codigo' => null,
            'nombre' => 'Entrega de cargo · paz y salvo',
            'proceso' => self::PROCESO_GH,
            'version' => '001',
            'fecha' => null,
        ],
        'entrevista_saber_hacer' => [
            'codigo' => null,
            'nombre' => 'Entrevista de saber hacer',
            'proceso' => self::PROCESO_GH,
            'version' => '001',
            'fecha' => null,
        ],
        'entrevista_psicologica' => [
            // Éste SÍ trae código en el papel: GH-F-004, versión 001.
            'codigo' => 'GH-F-004',
            'nombre' => 'Entrevista psicológica',
            'proceso' => self::PROCESO_GH,
            'version' => '001',
            'fecha' => '2023-05-24',
        ],
        'orden_examen_medico' => [
            'codigo' => 'SST-F-001',
            'nombre' => 'Orden de realización de examen médico ocupacional',
            // No es de Gestión Humana: el examen médico ocupacional pertenece
            // al proceso de SST, y por eso su código empieza distinto.
            'proceso' => self::PROCESO_SST,
            'version' => '001',
            'fecha' => '2026-01-07',
        ],
    ];

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_id');
    }

    /**
     * El formato que va a imprimirse.
     *
     * Si alguien borró el renglón de la tabla, se devuelve uno en memoria con
     * la semilla: un formato que no se puede imprimir porque falta su ficha de
     * control sería cambiar un dato faltante por una pantalla rota.
     */
    public static function de(string $clave): self
    {
        $fila = static::where('clave', $clave)->first();

        if ($fila) {
            return $fila;
        }

        $s = self::SEMILLA[$clave] ?? [
            'codigo' => null,
            'nombre' => str($clave)->replace('_', ' ')->ucfirst()->toString(),
            'proceso' => self::PROCESO_GH,
            'version' => '001',
            'fecha' => null,
        ];

        return new self([
            'clave' => $clave,
            'codigo' => $s['codigo'],
            'nombre' => $s['nombre'],
            'proceso' => $s['proceso'],
            'version' => $s['version'],
            'fecha_version' => $s['fecha'],
        ]);
    }

    /** ¿El área ya le asignó código, o sigue sin asignar? */
    public function tieneCodigo(): bool
    {
        return filled($this->codigo);
    }

    /** El código para imprimir, o el aviso de que falta. */
    public function codigoImpreso(): string
    {
        return $this->codigo ?: 'sin asignar';
    }

    public function fechaImpresa(): string
    {
        return $this->fecha_version?->format('d/m/Y') ?: '—';
    }
}
