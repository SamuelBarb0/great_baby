<?php

namespace App\Modules\Rrhh\Models;

use App\Modules\Rrhh\Models\Concerns\RegistraBitacora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La persona ya contratada.
 *
 * Nace de una contratación completada, no de un formulario en blanco: así los
 * datos que ya dio como candidata —nombre, cédula, teléfono— no se vuelven a
 * digitar, que es donde se cuelan los errores.
 *
 * Lleva bitácora porque su historia laboral es justamente eso: el rastro de lo
 * que le fue pasando, desde la inducción hasta el retiro.
 */
class Empleado extends Model
{
    use RegistraBitacora;
    use SoftDeletes;

    protected $table = 'rrhh_empleados';

    protected $fillable = [
        'candidato_id', 'vacante_id', 'user_id', 'nombre', 'tipo_documento', 'numero_documento',
        'activacion_token', 'activacion_expira_at', 'acceso_creado_at',
        'email', 'telefono', 'cargo', 'area', 'tipo_contrato', 'salario',
        'fecha_ingreso', 'fecha_retiro', 'tipo_retiro', 'motivo_retiro', 'retirado_por_id',
        'estado', 'estado_induccion', 'notas',
        'induccion_at', 'induccion_por_id', 'induccion_temas',
        'prueba_hasta', 'prueba_evaluada_at', 'prueba_evaluada_por_id',
        'prueba_resultado', 'prueba_observacion',
        'prueba_jefe_at', 'prueba_jefe_por_id', 'prueba_jefe_resultado', 'prueba_jefe_observacion',
    ];

    protected $casts = [
        'fecha_ingreso' => 'date',
        'fecha_retiro' => 'date',
        'salario' => 'decimal:2',
        'induccion_at' => 'datetime',
        'prueba_hasta' => 'date',
        'prueba_evaluada_at' => 'datetime',
        'prueba_jefe_at' => 'datetime',
        'activacion_expira_at' => 'datetime',
        'acceso_creado_at' => 'datetime',
    ];

    /**
     * Hasta cuándo propone el sistema que vaya el período de prueba.
     *
     * Dos meses desde el ingreso, que es el tope legal para un contrato a
     * término indefinido. Es una PROPUESTA: en un contrato a término fijo el
     * plazo es menor y depende de la duración pactada, así que la fecha que
     * manda es la que quede guardada, no ésta.
     */
    public function pruebaSugerida(): ?\Illuminate\Support\Carbon
    {
        return $this->fecha_ingreso?->copy()->addMonths(2);
    }

    /** Cuántos días quedan de período de prueba. Negativo = ya se venció. */
    public function diasDePrueba(): ?int
    {
        if (! $this->prueba_hasta || $this->prueba_evaluada_at) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->prueba_hasta, false);
    }

    public function induccionPor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'induccion_por_id');
    }

    public function pruebaJefePor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'prueba_jefe_por_id');
    }

    public function pruebaEvaluadaPor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'prueba_evaluada_por_id');
    }

    /**
     * ¿Ya opinó el jefe del área?
     *
     * «Ese período de prueba debe estar validado por el jefe del área y por
     * gestión humana» (Elena, 19-oct). Son DOS conceptos, no uno: el jefe sabe
     * si la persona rinde en el puesto; Gestión Humana mira lo demás y decide
     * viendo lo que él opinó, no a ciegas.
     */
    public function tieneConceptoDelJefe(): bool
    {
        return (bool) $this->prueba_jefe_at;
    }

    /**
     * Quién es el jefe que debe dar el concepto.
     *
     * Es quien pidió el personal: la requisición de la que salió la vacante
     * por la que entró esta persona. Si entró por fuera del proceso (los
     * empleados que ya estaban), no hay a quién pedírselo.
     */
    public function jefeDelArea(): ?int
    {
        return $this->vacante?->requisicion?->solicitante_id;
    }

    /**
     * A quién se le vence la prueba pronto y todavía nadie decidió.
     *
     * Es la consulta que hoy no existe y por eso el período se vence solo:
     * pasada la fecha, terminar el contrato ya cuesta indemnización.
     */
    /**
     * La gente que entró por las solicitudes de este jefe.
     *
     * El vínculo es la cadena requisición → vacante → empleado. Quien ya
     * estaba antes del módulo no tiene esa cadena y por eso no aparece: no
     * hay forma de saber quién lo pidió.
     */
    public function scopeDeJefe(\Illuminate\Database\Eloquent\Builder $q, int $userId): \Illuminate\Database\Eloquent\Builder
    {
        return $q->whereHas('vacante.requisicion', fn ($x) => $x->where('solicitante_id', $userId));
    }

    public function scopePruebaPorVencer(\Illuminate\Database\Eloquent\Builder $q, int $dias = 15): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('estado', 'activo')
            ->whereNull('prueba_evaluada_at')
            ->whereNotNull('prueba_hasta')
            ->where('prueba_hasta', '<=', now()->addDays($dias)->toDateString());
    }

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    public function vacante(): BelongsTo
    {
        return $this->belongsTo(Vacante::class);
    }

    public function contratacion(): HasOne
    {
        return $this->hasOne(Contratacion::class);
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoEmpleado::class);
    }

    /** Su cuenta para entrar al portal, si Gestión Humana ya se la creó. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /** Lo que la empresa le entregó: dotación y herramientas. */
    public function asignaciones(): HasMany
    {
        return $this->hasMany(Asignacion::class)->latest('id');
    }

    /**
     * Lo que todavía tiene en su poder.
     *
     * Es la consulta que no existía el día que alguien se fue con la tablet:
     * sin ella, el paz y salvo se firma a ciegas.
     */
    public function pendientesDeDevolver()
    {
        return $this->asignaciones()->pendientes();
    }
}
