<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un papel de la carpeta de alguien.
 *
 * Cuelga del empleado O del candidato, porque los documentos empiezan a llegar
 * cuando la persona todavía no ha sido contratada: trae su cédula y sus
 * certificados durante el proceso. Al crearse el empleado, los suyos se
 * heredan sin volver a pedirlos.
 */
class DocumentoEmpleado extends Model
{
    protected $table = 'rrhh_documentos';

    protected $fillable = [
        'empleado_id', 'candidato_id', 'tipo', 'nombre',
        'archivo_path', 'subido_por', 'fecha_vencimiento',
    ];

    protected $casts = ['fecha_vencimiento' => 'date'];

    /**
     * Los papeles que Great Baby pide para contratar.
     *
     * Están acá y no en la pantalla para que la lista de pendientes y el
     * formulario de carga no puedan decir cosas distintas.
     */
    public const TIPOS = [
        'cedula' => 'Cédula de ciudadanía',
        'hoja_vida' => 'Hoja de vida',
        'certificado_estudio' => 'Certificados de estudio',
        'certificado_laboral' => 'Certificados laborales',
        'examen_medico' => 'Examen médico de ingreso',
        'contrato' => 'Contrato firmado',
        'afiliaciones' => 'Soportes de afiliación',
        'cuenta_bancaria' => 'Certificación bancaria',
        'antecedentes' => 'Antecedentes judiciales',
        'otro' => 'Otro',
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function getTipoLabelAttribute(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }
}
