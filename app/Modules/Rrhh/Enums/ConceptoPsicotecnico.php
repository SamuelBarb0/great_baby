<?php

namespace App\Modules\Rrhh\Enums;

/**
 * Fase C · El concepto del profesional sobre una prueba psicotécnica.
 *
 * Son tres y no dos a propósito. «Apto con observaciones» es el caso real más
 * frecuente —la persona sirve para el cargo pero hay algo que el jefe debería
 * saber— y sin esa casilla ese matiz termina escrito en un campo de notas que
 * nadie lee, o se redondea a «apto» y se pierde.
 */
enum ConceptoPsicotecnico: string
{
    case Apto = 'apto';
    case AptoConObservaciones = 'apto_con_observaciones';
    case NoApto = 'no_apto';

    public function label(): string
    {
        return match ($this) {
            self::Apto => 'Apto',
            self::AptoConObservaciones => 'Apto con observaciones',
            self::NoApto => 'No apto',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Apto => 'success',
            self::AptoConObservaciones => 'warning',
            self::NoApto => 'danger',
        };
    }

    /**
     * ¿El proceso sigue?
     *
     * «Apto con observaciones» sigue: las observaciones son para tenerlas en
     * cuenta, no para frenar. Quien decide es el jefe que pidió el personal.
     */
    public function continuaElProceso(): bool
    {
        return $this !== self::NoApto;
    }

    /**
     * ¿Hay que escribir por qué?
     *
     * Sí cuando el concepto no es un «apto» liso: un «no apto» sin sustento no
     * se le puede explicar a nadie, y unas observaciones vacías no son
     * observaciones.
     */
    public function exigeObservaciones(): bool
    {
        return $this !== self::Apto;
    }

    /** @return array<array{valor:string, label:string, color:string}> */
    public static function catalogo(): array
    {
        return array_map(fn (self $c) => [
            'valor' => $c->value,
            'label' => $c->label(),
            'color' => $c->color(),
            'exige_observaciones' => $c->exigeObservaciones(),
        ], self::cases());
    }
}
