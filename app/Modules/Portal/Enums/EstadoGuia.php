<?php

namespace App\Modules\Portal\Enums;

/**
 * En qué va la guía de un pedido despachado.
 *
 * No es una columna: se calcula con la fecha de despacho, la de entrega y los
 * plazos que configure logística. Guardarlo sería tener que recalcularlo todas
 * las noches, y el día que se cambie el plazo quedaría mintiendo lo viejo.
 *
 * Los cuatro estados son los que pide el contrato. El orden importa: así se
 * ordena la pantalla, lo que hay que reclamar primero arriba.
 */
enum EstadoGuia: string
{
    /** Despachado hace más de la cuenta y sin noticias. Hay que reclamar. */
    case NoEntregada = 'no_entregada';

    /** Se pasó del plazo normal, pero todavía dentro de lo razonable. */
    case Tardia = 'tardia';

    /** En camino, dentro del plazo. */
    case ATiempo = 'a_tiempo';

    /** Facturado pero todavía en bodega. */
    case Pendiente = 'pendiente';

    /** Llegó. */
    case Entregada = 'entregada';

    public function label(): string
    {
        return match ($this) {
            self::NoEntregada => 'No entregada',
            self::Tardia => 'Tardía',
            self::ATiempo => 'A tiempo',
            self::Pendiente => 'Pendiente de despacho',
            self::Entregada => 'Entregada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NoEntregada => 'red',
            self::Tardia => 'amber',
            self::ATiempo => 'emerald',
            self::Pendiente => 'surface',
            self::Entregada => 'sky',
        };
    }

    /** Las que alguien tiene que mirar hoy. */
    public function pideAtencion(): bool
    {
        return $this === self::NoEntregada || $this === self::Tardia;
    }
}
