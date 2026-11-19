<?php

namespace App\Modules\Rrhh\Enums;

/**
 * Fase C · Las pruebas psicotécnicas que aplica el área.
 *
 * ── Lo que el ERP guarda y lo que NO ────────────────────────────────────────
 *
 * Guarda el CONCEPTO del profesional y el PDF firmado. **No guarda las
 * respuestas.** La decisión es deliberada y tiene dos razones:
 *
 *  1. Las respuestas de un MINIMULT son dato sensible de salud mental. La Ley
 *     1581 de 2012 las trata como categoría especial: exigen autorización
 *     expresa y finalidad determinada, y guardarlas en una tabla que cualquier
 *     día alguien exporta a Excel es exactamente lo que la norma evita.
 *  2. Los dos instrumentos son obras con derechos de autor. Reproducir sus
 *     ítems dentro del sistema sería copiarlos.
 *
 * Lo que la empresa necesita para decidir una contratación no son las 71
 * respuestas: es si el profesional conceptúa apto o no. Eso es lo que se
 * guarda, con nombre y tarjeta de quien lo firma.
 */
enum InstrumentoPsicotecnico: string
{
    case Minimult = 'minimult';
    case Valanti = 'valanti';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Minimult => 'MINIMULT',
            self::Valanti => 'VALANTI',
            self::Otro => 'Otra prueba',
        };
    }

    /** Qué mide, en una línea, para quien no es del área. */
    public function queMide(): string
    {
        return match ($this) {
            self::Minimult => 'Inventario de personalidad abreviado.',
            self::Valanti => 'Escala de valores interpersonales y de trabajo.',
            self::Otro => 'La que indique el profesional.',
        };
    }

    /**
     * ¿Es un instrumento clínico?
     *
     * El MINIMULT lo es: sólo lo aplica e interpreta un psicólogo con tarjeta
     * profesional (Ley 1090 de 2006). El VALANTI mide valores, no salud.
     */
    public function exigeProfesional(): bool
    {
        return $this === self::Minimult;
    }

    /** @return array<array{valor:string, label:string, que_mide:string}> */
    public static function catalogo(): array
    {
        return array_map(fn (self $i) => [
            'valor' => $i->value,
            'label' => $i->label(),
            'que_mide' => $i->queMide(),
            'exige_profesional' => $i->exigeProfesional(),
        ], self::cases());
    }
}
