<?php

namespace App\Modules\Compras\Enums;

/**
 * Las etapas del contenedor, en orden, según lo que contaron Alejandro y Liz.
 *
 * `diasEsperados()` es cuánto suele tardar en LLEGAR a esa etapa desde la
 * anterior. Son los tiempos de las reuniones y sólo sirven mientras no haya
 * historia: con tres contenedores medidos manda el promedio real.
 */
enum EtapaContenedor: string
{
    case EnFabricacion = 'en_fabricacion';
    case Cargado = 'cargado';
    case Zarpado = 'zarpado';
    case EnPuerto = 'en_puerto';
    case Duta = 'duta';
    case CaminoZonaFranca = 'camino_zona_franca';
    case IngresoZonaFranca = 'ingreso_zona_franca';
    case Declaraciones = 'declaraciones';
    case Levante = 'levante';
    case CaminoBodega = 'camino_bodega';
    case EnBodega = 'en_bodega';

    public function label(): string
    {
        return match ($this) {
            self::EnFabricacion => 'En fabricación',
            self::Cargado => 'Cargado en China',
            self::Zarpado => 'Zarpó',
            self::EnPuerto => 'Llegó a puerto',
            self::Duta => 'DUTA aprobada',
            self::CaminoZonaFranca => 'En camino a zona franca',
            self::IngresoZonaFranca => 'Ingresó a zona franca',
            self::Declaraciones => 'Declaraciones pagadas',
            self::Levante => 'Levante',
            self::CaminoBodega => 'En camino a bodega',
            self::EnBodega => 'En bodega',
        };
    }

    /** Null: depende de cada pedido y no se le puede exigir un plazo. */
    public function diasEsperados(): ?int
    {
        return match ($this) {
            self::EnFabricacion, self::Cargado => null,
            self::Zarpado => 10,
            self::EnPuerto => 42,
            self::Duta => 10,
            self::CaminoZonaFranca => 1,
            self::IngresoZonaFranca => 2,
            self::Declaraciones => 5,
            self::Levante => 7,
            self::CaminoBodega => 1,
            self::EnBodega => 2,
        };
    }

    /** El estado general que le corresponde al contenedor al llegar a esta etapa. */
    public function estado(): EstadoImportacion
    {
        return match ($this) {
            self::EnFabricacion, self::Cargado, self::Zarpado => EstadoImportacion::EnTransito,
            self::EnPuerto, self::Duta, self::CaminoZonaFranca,
            self::IngresoZonaFranca, self::Declaraciones => EstadoImportacion::EnPuerto,
            self::Levante, self::CaminoBodega, self::EnBodega => EstadoImportacion::Nacionalizada,
        };
    }

    /** La fecha del contenedor que se lee de esta etapa, si alguna. */
    public function campoFecha(): ?string
    {
        return match ($this) {
            self::Zarpado => 'fecha_zarpe',
            self::EnPuerto => 'fecha_llegada',
            self::Levante => 'fecha_nacionalizacion',
            default => null,
        };
    }

    public function posicion(): int
    {
        return array_search($this, self::cases(), true);
    }

    public function anterior(): ?self
    {
        return self::cases()[$this->posicion() - 1] ?? null;
    }
}
