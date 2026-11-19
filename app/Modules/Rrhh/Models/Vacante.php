<?php

namespace App\Modules\Rrhh\Models;

use App\Modules\Rrhh\Models\Concerns\RegistraBitacora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La vacante que se publica, nacida de una requisición aprobada.
 *
 * `requisicion_id` es nullable por las que ya existieran antes del módulo de
 * requisiciones, no porque abrir una sin solicitud sea válido: eso lo frena el
 * controlador.
 */
class Vacante extends Model
{
    use RegistraBitacora;
    use SoftDeletes;
    protected $table = 'rrhh_vacantes';
    protected $fillable = [
        'titulo', 'area', 'descripcion', 'requisitos', 'salario_min', 'salario_max',
        'modalidad', 'tipo_contrato', 'estado', 'fecha_apertura', 'fecha_cierre', 'creado_por',
        'requisicion_id', 'fuente',
    ];
    protected $casts = ['fecha_apertura' => 'date', 'fecha_cierre' => 'date'];
    public function candidatos(): HasMany { return $this->hasMany(Candidato::class); }

    public function requisicion(): BelongsTo { return $this->belongsTo(Requisicion::class, 'requisicion_id'); }

    /**
     * De dónde se trae gente. La publicación en agencias públicas de empleo es
     * OBLIGATORIA por normativa —toda empresa debe hacerlo— y por eso la caja
     * de compensación encabeza la lista. El resto son los canales propios.
     */
    public const FUENTES = [
        'caja_compensacion' => 'Agencia pública de empleo (caja de compensación)',
        'redes_sociales' => 'Redes sociales',
        'correo' => 'Correo electrónico',
        'referido' => 'Referido',
        'otra' => 'Otra',
    ];
}
