<?php

namespace App\Modules\Rrhh\Enums;

/**
 * Los exámenes médicos ocupacionales de la Resolución 2346 de 2007.
 *
 * El formato que mandó el área (SST-F-001) está redactado para el de ingreso,
 * que es el único que hoy toca el ERP. Pero la misma carta sirve para los
 * otros, y dos de ellos ya hacen falta: el PERIÓDICO lo exige la norma
 * mientras la persona trabaje, y el de EGRESO hay que practicarlo al salir —si
 * no se hace, la empresa queda sin con qué demostrar en qué condiciones se fue
 * la persona, y una demanda posterior por enfermedad laboral se pierde sola.
 *
 * Por eso el tipo es un campo y no una constante escondida en la plantilla.
 */
enum TipoExamenMedico: string
{
    case Ingreso = 'ingreso';
    case Periodico = 'periodico';
    case Egreso = 'egreso';
    case PostIncapacidad = 'post_incapacidad';
    case CambioOcupacion = 'cambio_ocupacion';

    public function label(): string
    {
        return match ($this) {
            self::Ingreso => 'De ingreso (preocupacional)',
            self::Periodico => 'Periódico',
            self::Egreso => 'De egreso (retiro)',
            self::PostIncapacidad => 'Post incapacidad',
            self::CambioOcupacion => 'Por cambio de ocupación',
        };
    }

    /** Cómo se nombra dentro del texto de la carta. */
    public function enLaCarta(): string
    {
        return match ($this) {
            self::Ingreso => 'examen médico ocupacional de ingreso',
            self::Periodico => 'examen médico ocupacional periódico',
            self::Egreso => 'examen médico ocupacional de egreso',
            self::PostIncapacidad => 'examen médico ocupacional post incapacidad',
            self::CambioOcupacion => 'examen médico ocupacional por cambio de ocupación',
        };
    }

    /**
     * Por qué se está pidiendo, en una línea.
     *
     * Va impresa para que la IPS sepa qué batería aplicar: no es lo mismo un
     * ingreso que un egreso, y mandar la carta sin decirlo obliga a una
     * llamada.
     */
    public function motivo(): string
    {
        return match ($this) {
            self::Ingreso => 'Ingreso de un nuevo colaborador a la organización.',
            self::Periodico => 'Seguimiento periódico al estado de salud del colaborador.',
            self::Egreso => 'Retiro del colaborador de la organización.',
            self::PostIncapacidad => 'Reintegro después de una incapacidad prolongada.',
            self::CambioOcupacion => 'Cambio de cargo o de condiciones de trabajo.',
        };
    }

    /** Los que se le piden a alguien que ya trabaja acá. */
    public static function deEmpleado(): array
    {
        return [self::Periodico, self::Egreso, self::PostIncapacidad, self::CambioOcupacion];
    }

    /** @return array<array{valor:string,label:string}> */
    public static function catalogo(array $casos): array
    {
        return array_map(fn (self $t) => ['valor' => $t->value, 'label' => $t->label()], $casos);
    }
}
