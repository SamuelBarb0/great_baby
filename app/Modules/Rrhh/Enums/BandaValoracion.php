<?php

namespace App\Modules\Rrhh\Enums;

/**
 * En qué banda cae el promedio de la entrevista del ser.
 *
 * Los tres cortes los puso el área y son los que usa en papel. Están acá y no
 * en una regla configurable porque no son una preferencia: son el criterio con
 * el que se decide a quién se contrata, y moverlos a mitad de un proceso
 * cambiaría el resultado de entrevistas ya hechas sin que nadie se entere.
 *
 *   ≤ 2,50                 Riesgo
 *   > 2,50  y  < 3,50      Aceptable con seguimiento
 *   ≥ 3,50                 Recomendable
 *
 * Los extremos caen del lado más exigente a propósito: un 2,50 clavado es
 * Riesgo y un 3,50 clavado es Recomendable. Así lo pidió el área.
 *
 * La banda NO reemplaza la valoración final —`ValoracionCandidato`, que sigue
 * siendo Apto / Apto con restricción / No apto—: la sugiere. Quien decide
 * firma, y si se aparta de la banda tiene que explicarlo. Un promedio no
 * despide ni contrata a nadie.
 */
enum BandaValoracion: string
{
    case Riesgo = 'riesgo';
    case AceptableConSeguimiento = 'aceptable_con_seguimiento';
    case Recomendable = 'recomendable';

    /** La banda que le corresponde a un promedio. */
    public static function dePromedio(float $promedio): self
    {
        return match (true) {
            $promedio >= 3.5 => self::Recomendable,
            $promedio > 2.5 => self::AceptableConSeguimiento,
            default => self::Riesgo,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Riesgo => 'Riesgo',
            self::AceptableConSeguimiento => 'Aceptable con seguimiento',
            self::Recomendable => 'Recomendable',
        };
    }

    /** Qué significa, dicho para quien lee la ficha y no estuvo en la entrevista. */
    public function queSignifica(): string
    {
        return match ($this) {
            self::Riesgo => 'Los puntajes no respaldan la contratación. Si se contrata igual, que quede escrito por qué.',
            self::AceptableConSeguimiento => 'Sirve, pero hay algo que mirar de cerca en el período de prueba.',
            self::Recomendable => 'Los seis criterios lo respaldan.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Riesgo => 'danger',
            self::AceptableConSeguimiento => 'warning',
            self::Recomendable => 'success',
        };
    }

    /** Qué valoración sugiere, para pre-cargar el selector sin decidir por nadie. */
    public function valoracionSugerida(): ValoracionCandidato
    {
        return match ($this) {
            self::Riesgo => ValoracionCandidato::NoApto,
            self::AceptableConSeguimiento => ValoracionCandidato::AptoConRestriccion,
            self::Recomendable => ValoracionCandidato::Apto,
        };
    }

    /** Para el selector de la pantalla, sin que el Vue repita los textos. */
    public static function catalogo(): array
    {
        return array_map(fn (self $b) => [
            'valor' => $b->value,
            'label' => $b->label(),
            'significa' => $b->queSignifica(),
            'color' => $b->color(),
        ], self::cases());
    }
}
