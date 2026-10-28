<?php

namespace App\Modules\Rrhh\Enums;

/**
 * Por qué dice LA PERSONA que se va.
 *
 * Ojo con no confundirlo con `tipo_retiro`, que ya existe en el empleado:
 *
 *   · `tipo_retiro` es cómo clasifica la EMPRESA la salida —renuncia,
 *     terminación con justa causa, fin de obra, mutuo acuerdo—. Es el dato
 *     administrativo y legal.
 *   · Esto es lo que la persona CONTESTA en la entrevista de retiro.
 *
 * Son dos ejes distintos y los dos hacen falta. Veinte renuncias se cuentan
 * igual en el primero; en el segundo se ve si fueron por mejor oferta —que es
 * mercado— o por el jefe inmediato y el clima —que es algo que la empresa
 * puede arreglar—. La pregunta que Elena lleva a la Junta, «por qué se va la
 * gente», la responde éste, no aquél.
 */
enum MotivoSalida: string
{
    case MejorOferta = 'mejor_oferta';
    case Crecimiento = 'crecimiento';
    case ClimaLaboral = 'clima_laboral';
    case JefeInmediato = 'jefe_inmediato';
    case CondicionesTrabajo = 'condiciones_trabajo';
    case Personales = 'personales';
    case CambioResidencia = 'cambio_residencia';
    case FinContrato = 'fin_contrato';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::MejorOferta => 'Mejor oferta económica',
            self::Crecimiento => 'Oportunidad de crecimiento / desarrollo profesional',
            self::ClimaLaboral => 'Clima laboral / relaciones interpersonales',
            self::JefeInmediato => 'Relación con el jefe inmediato',
            self::CondicionesTrabajo => 'Condiciones de trabajo (horario, carga laboral, herramientas)',
            self::Personales => 'Motivos personales / familiares / salud',
            self::CambioResidencia => 'Cambio de residencia o ubicación geográfica',
            self::FinContrato => 'Finalización de obra / contrato',
            self::Otro => 'Otro',
        };
    }

    /**
     * ¿Es algo sobre lo que la empresa puede hacer algo?
     *
     * No es una etiqueta moral: es para que el indicador pueda separar lo que
     * se puede atacar de lo que no. Que alguien se mude de ciudad no se
     * arregla; que se vaya por el jefe o por el clima, sí. Si los veinte
     * retiros del año caen en esta mitad, el problema no es el mercado.
     */
    public function esAccionable(): bool
    {
        return in_array($this, [
            self::ClimaLaboral, self::JefeInmediato,
            self::CondicionesTrabajo, self::Crecimiento, self::MejorOferta,
        ], true);
    }

    /** @return array<array{valor:string,label:string,accionable:bool}> */
    public static function catalogo(): array
    {
        return array_map(fn (self $m) => [
            'valor' => $m->value,
            'label' => $m->label(),
            'accionable' => $m->esAccionable(),
        ], self::cases());
    }
}
