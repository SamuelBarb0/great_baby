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
        'numero', 'cargo', 'area', 'ciudad', 'razon_comercial', 'tipo_seleccion',
        'numero_vacantes', 'motivo', 'motivo_seleccion', 'motivo_seleccion_otro',
        'horario', 'funciones',
        'competencias', 'estudios', 'experiencia', 'salario_min', 'salario_max',
        'tipo_contrato', 'fecha_requerida', 'solicitante_id', 'estado',
        'enviada_at', 'aprobada_por_id', 'aprobada_at', 'rechazada_at',
        'motivo_rechazo', 'observaciones',
    ];

    /**
     * Los motivos del papel, en lista cerrada.
     *
     * `motivo` sigue siendo el texto libre que explica el caso; esto es para
     * CONTAR. Con la lista se puede responder «cuántas contrataciones del año
     * fueron ampliación de planta», que es lo que pregunta una junta, y con
     * texto libre eso no se puede responder nunca.
     *
     * @var array<string, string>
     */
    public const MOTIVOS_SELECCION = [
        'reemplazo_titular' => 'Reemplazo del titular',
        'ampliacion_planta' => 'Ampliación de planta',
        'nuevo_proyecto' => 'Nuevo proyecto',
        'reemplazo_vacaciones' => 'Reemplazo de vacaciones',
        'reestructuracion' => 'Reestructuración',
        'cubrimiento_licencia' => 'Cubrimiento de licencias',
        'otro' => 'Otro',
    ];

    /** Interna se busca entre quienes ya están; externa se publica. */
    public const TIPOS_SELECCION = [
        'interna' => 'Selección interna',
        'externa' => 'Selección externa',
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

    /** El motivo en palabras, resolviendo el caso «otro». */
    public function motivoEnPalabras(): ?string
    {
        if (blank($this->motivo_seleccion)) {
            return null;
        }

        return $this->motivo_seleccion === 'otro'
            ? ($this->motivo_seleccion_otro ?: 'Otro')
            : (self::MOTIVOS_SELECCION[$this->motivo_seleccion] ?? $this->motivo_seleccion);
    }

    /**
     * Qué casillas del formato quedaron vacías.
     *
     * Se avisa, no se frena: el líder pide el personal cuando lo necesita y
     * no cuando tiene todos los datos. Pero el papel sale con huecos y quien
     * lo firma debería saberlo antes de firmarlo.
     *
     * @return array<string>
     */
    public function faltantesDelFormato(): array
    {
        $falta = [];

        foreach ([
            'ciudad' => 'la ciudad',
            'tipo_seleccion' => 'si la selección es interna o externa',
            'motivo_seleccion' => 'el motivo de la selección',
            'horario' => 'el horario',
            'funciones' => 'las funciones por desempeñar',
        ] as $campo => $nombre) {
            if (blank($this->{$campo})) {
                $falta[] = $nombre;
            }
        }

        return $falta;
    }
}
