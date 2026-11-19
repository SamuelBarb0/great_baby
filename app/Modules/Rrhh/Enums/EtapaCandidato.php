<?php

namespace App\Modules\Rrhh\Enums;

/**
 * Las etapas reales por las que pasa un candidato.
 *
 * El enum que traía la tabla era `nuevo · revision · entrevista · prueba ·
 * oferta · contratado · descartado`, y colapsaba en una sola «entrevista» las
 * DOS que Great Baby hace de verdad: la del jefe inmediato, que mide el saber
 * hacer, y la de psicología, que mide habilidades blandas y salud mental. Son
 * dos momentos distintos, con responsables distintos y formatos distintos, y
 * el jefe que pidió el personal necesita ver en cuál de los dos va.
 *
 * Se corrigió con la tabla todavía vacía, así que no hubo datos que migrar.
 */
enum EtapaCandidato: string
{
    case Recibido = 'recibido';
    case Preseleccionado = 'preseleccionado';
    case EntrevistaJefe = 'entrevista_jefe';
    case EntrevistaPsicologia = 'entrevista_psicologia';
    case Pruebas = 'pruebas';
    case Validacion = 'validacion';
    case Terna = 'terna';
    case Seleccionado = 'seleccionado';
    case Descartado = 'descartado';

    public function label(): string
    {
        return match ($this) {
            self::Recibido => 'Recibido',
            self::Preseleccionado => 'Preseleccionado',
            self::EntrevistaJefe => 'Entrevista con el jefe',
            self::EntrevistaPsicologia => 'Entrevista de psicología',
            self::Pruebas => 'Pruebas',
            self::Validacion => 'Validación',
            self::Terna => 'En la terna',
            self::Seleccionado => 'Seleccionado',
            self::Descartado => 'Descartado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Recibido, self::Preseleccionado => 'gray',
            self::EntrevistaJefe, self::EntrevistaPsicologia, self::Pruebas => 'warning',
            self::Validacion, self::Terna => 'info',
            self::Seleccionado => 'success',
            self::Descartado => 'danger',
        };
    }

    /**
     * Las etapas del tablero, en orden. `Descartado` queda afuera a propósito:
     * no es un paso del camino, es una salida que puede ocurrir en cualquiera.
     */
    public static function delTablero(): array
    {
        return [
            self::Recibido, self::Preseleccionado, self::EntrevistaJefe,
            self::EntrevistaPsicologia, self::Pruebas, self::Validacion, self::Terna,
        ];
    }

    /** Un candidato descartado o ya seleccionado no se mueve más. */
    public function esFinal(): bool
    {
        return in_array($this, [self::Seleccionado, self::Descartado], true);
    }

    /**
     * Las etapas a las que no se llega sin haber revisado los antecedentes.
     *
     * Elena los consulta en la página de la rama judicial ANTES de citar a
     * entrevista. Es un paso que se salta solo el día que haya afán, y ese es
     * justo el día en que importa.
     */
    public function exigeAntecedentes(): bool
    {
        return in_array($this, [
            self::EntrevistaJefe, self::EntrevistaPsicologia,
            self::Pruebas, self::Validacion, self::Terna, self::Seleccionado,
        ], true);
    }

    /** Quién mueve esta etapa, en palabras, para que la pantalla lo diga. */
    public function responsable(): string
    {
        return match ($this) {
            self::EntrevistaJefe => 'Jefe del área',
            self::EntrevistaPsicologia => 'Psicología',
            self::Terna => 'Decide el jefe que pidió el personal',
            default => 'Gestión Humana',
        };
    }
}
