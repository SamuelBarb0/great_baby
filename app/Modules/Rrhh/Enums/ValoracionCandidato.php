<?php

namespace App\Modules\Rrhh\Enums;

/**
 * El dictamen de Gestión Humana sobre un candidato.
 *
 * «Apto con restricción» no es un término nuestro: es como lo dijo Elena en la
 * reunión. Significa que el candidato sirve pero algo no cumple del todo, y esa
 * salvedad tiene que quedar escrita, porque es lo que el jefe necesita saber
 * antes de elegir entre los tres de la terna.
 */
enum ValoracionCandidato: string
{
    case Apto = 'apto';
    case AptoConRestriccion = 'apto_con_restriccion';
    case NoApto = 'no_apto';

    public function label(): string
    {
        return match ($this) {
            self::Apto => 'Apto',
            self::AptoConRestriccion => 'Apto con restricción',
            self::NoApto => 'No apto',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Apto => 'success',
            self::AptoConRestriccion => 'warning',
            self::NoApto => 'danger',
        };
    }

    /** La restricción sin explicar no sirve de nada: hay que exigir el motivo. */
    public function exigeObservacion(): bool
    {
        return $this !== self::Apto;
    }
}
