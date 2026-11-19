<?php

namespace App\Modules\Rrhh\Enums;

/**
 * Por dónde va una solicitud de personal.
 *
 * El líder de área la crea en BORRADOR y la envía; Gestión Humana aprueba o
 * rechaza. Sólo una requisición APROBADA puede convertirse en vacante — ese es
 * el control que hoy se pierde con «la hojita» que a veces llega y a veces no.
 *
 * La autorización de la Junta Directiva (la «orden de contratación» de la
 * reunión) NO vive acá: es un segundo flujo, y entra cuando se negocie.
 */
enum EstadoRequisicion: string
{
    case Borrador = 'borrador';
    case Enviada = 'enviada';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';
    case Cerrada = 'cerrada';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Enviada => 'Enviada · esperando aprobación',
            self::Aprobada => 'Aprobada',
            self::Rechazada => 'Rechazada',
            self::Cerrada => 'Cerrada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Borrador => 'gray',
            self::Enviada => 'warning',
            self::Aprobada => 'success',
            self::Rechazada => 'danger',
            self::Cerrada => 'gray',
        };
    }

    /** Sólo desde acá se puede abrir una vacante. */
    public function permiteVacante(): bool
    {
        return $this === self::Aprobada;
    }
}
