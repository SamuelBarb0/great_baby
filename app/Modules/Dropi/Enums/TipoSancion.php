<?php

namespace App\Modules\Dropi\Enums;

/**
 * §19 Diseño Dropi — Tipos de sanciones detectadas por conciliación wallet o auditorías.
 * F16 · Antes DropiSancion.tipo era string libre; ahora enum controlado.
 */
enum TipoSancion: string
{
    case DiferenciaPrecio = 'diferencia_precio';
    // Dropi pagó MÁS de lo esperado (GB debe devolverlo). La conciliación ya lo
    // guardaba y el enum no lo tenía: leer una de estas sanciones reventaba.
    case Sobrepago = 'sobrepago';
    case CategoriaExplicita = 'categoria_explicita';
    case PagoSobreDevuelto = 'pago_sobre_devuelto';
    case MercanciaFantasma = 'mercancia_fantasma';
    case MercanciaEnTransito = 'mercancia_en_transito';

    public function label(): string
    {
        return match ($this) {
            self::DiferenciaPrecio => 'Diferencia de precio',
            self::Sobrepago => 'Sobrepago de Dropi',
            self::CategoriaExplicita => 'Sanción explícita',
            self::PagoSobreDevuelto => 'Pago sobre pedido devuelto',
            self::MercanciaFantasma => 'Mercancía fantasma',
            self::MercanciaEnTransito => 'Mercancía en tránsito',
        };
    }
}
