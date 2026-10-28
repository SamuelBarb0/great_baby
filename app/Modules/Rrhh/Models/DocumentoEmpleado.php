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
     * Los papeles que Great Baby pide para contratar · GH-F-007.
     *
     * Están acá y no en la pantalla para que la lista de pendientes, el
     * formulario de carga y la carta impresa no puedan decir cosas distintas.
     *
     * La lista anterior tenía diez renglones genéricos («Certificados de
     * estudio»); el formato del área pide diez cosas CONCRETAS, y la
     * diferencia importa: «certificados de estudio» no le dice a nadie que
     * hacen falta el diploma Y el acta de grado, ni que el de estudios
     * superiores sólo aplica a algunos cargos. Por eso cada renglón trae
     * además su `nota`, que es la letra chica del formato.
     *
     * `exigencia`:
     *   · `siempre`   — sin esto no se contrata
     *   · `si_aplica` — depende del cargo o de la persona (tarjeta
     *                   profesional, libreta militar, estudios superiores)
     *   · `interno`   — lo produce la empresa, no lo trae el aspirante
     *
     * Las claves viejas se conservaron todas: hay carpetas en producción
     * colgando de ellas y renombrarlas las dejaría huérfanas.
     *
     * @var array<string, array{label:string, exigencia:string, nota:string}>
     */
    public const CATALOGO = [
        'cedula' => [
            'label' => 'Documento de identidad',
            'exigencia' => 'siempre',
            'nota' => 'Fotocopia ampliada al 150%.',
        ],
        'hoja_vida' => [
            'label' => 'Hoja de vida',
            'exigencia' => 'siempre',
            'nota' => '',
        ],
        'diploma_bachiller' => [
            'label' => 'Diploma y acta de grado de bachiller',
            'exigencia' => 'siempre',
            'nota' => 'Original y copia.',
        ],
        'certificado_estudio' => [
            'label' => 'Diploma y acta de grado de estudios superiores',
            'exigencia' => 'si_aplica',
            'nota' => 'Original y copia, si le aplica.',
        ],
        'tarjeta_profesional' => [
            'label' => 'Registro o tarjeta profesional',
            'exigencia' => 'si_aplica',
            'nota' => 'Original y copia, si aplica para su cargo.',
        ],
        'capacitaciones' => [
            'label' => 'Certificados de capacitaciones, seminarios y congresos',
            'exigencia' => 'si_aplica',
            'nota' => 'Fotocopia.',
        ],
        'libreta_militar' => [
            'label' => 'Libreta militar',
            'exigencia' => 'si_aplica',
            'nota' => 'Original y copia, si la tiene.',
        ],
        'afiliaciones' => [
            'label' => 'Certificación de afiliación a EPS, fondo de pensiones y fondo de cesantías',
            'exigencia' => 'siempre',
            'nota' => '',
        ],
        'examen_medico' => [
            'label' => 'Examen médico ocupacional de ingreso',
            'exigencia' => 'siempre',
            'nota' => 'Con la carta de autorización que entrega Gestión Humana (SST-F-001).',
        ],
        'certificado_laboral' => [
            'label' => 'Referencias laborales',
            'exigencia' => 'siempre',
            'nota' => 'En hoja membretada.',
        ],
        'cuenta_bancaria' => [
            'label' => 'Certificación bancaria de la cuenta de nómina',
            'exigencia' => 'siempre',
            'nota' => 'Carta emitida por el banco con el número de cuenta.',
        ],
        'beneficiarios' => [
            'label' => 'Documentos de beneficiarios para seguridad social',
            'exigencia' => 'si_aplica',
            'nota' => 'Cónyuge: documento de identidad y registro civil de matrimonio. '
                .'Hijos: tarjeta de identidad ampliada al 150% y registro civil de nacimiento. '
                .'Padres: documento de identidad y registro civil de nacimiento del cotizante.',
        ],
        'antecedentes' => [
            'label' => 'Antecedentes judiciales',
            'exigencia' => 'interno',
            'nota' => 'Los consulta Gestión Humana; no los trae el aspirante.',
        ],
        'contrato' => [
            'label' => 'Contrato firmado',
            'exigencia' => 'interno',
            'nota' => 'Lo produce la empresa.',
        ],
        'otro' => [
            'label' => 'Otro',
            'exigencia' => 'si_aplica',
            'nota' => '',
        ],
    ];

    /** Clave → etiqueta. Se conserva porque medio módulo la usa así. */
    public const TIPOS = [
        'cedula' => 'Documento de identidad',
        'hoja_vida' => 'Hoja de vida',
        'diploma_bachiller' => 'Diploma y acta de grado de bachiller',
        'certificado_estudio' => 'Diploma y acta de grado de estudios superiores',
        'tarjeta_profesional' => 'Registro o tarjeta profesional',
        'capacitaciones' => 'Certificados de capacitaciones, seminarios y congresos',
        'libreta_militar' => 'Libreta militar',
        'afiliaciones' => 'Certificación de afiliación a EPS, pensiones y cesantías',
        'examen_medico' => 'Examen médico ocupacional de ingreso',
        'certificado_laboral' => 'Referencias laborales',
        'cuenta_bancaria' => 'Certificación bancaria de la cuenta de nómina',
        'beneficiarios' => 'Documentos de beneficiarios para seguridad social',
        'antecedentes' => 'Antecedentes judiciales',
        'contrato' => 'Contrato firmado',
        'otro' => 'Otro',
    ];

    /**
     * Lo que el ASPIRANTE tiene que traer, que es de lo que habla la carta.
     *
     * Deja afuera lo interno: los antecedentes los consulta Gestión Humana y
     * el contrato lo produce la empresa. Pedírselos a la persona en la carta
     * la manda a buscar algo que no le corresponde.
     *
     * @return array<string, array{label:string, exigencia:string, nota:string}>
     */
    public static function loQueTraeElAspirante(): array
    {
        return array_filter(
            self::CATALOGO,
            fn (array $d, string $k) => $d['exigencia'] !== 'interno' && $k !== 'otro',
            ARRAY_FILTER_USE_BOTH,
        );
    }

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
