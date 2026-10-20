<?php

namespace App\Modules\Cartera\Support;

use App\Modules\Cartera\Actions\SiguienteConsecutivoFactura;

/**
 * Número de la próxima factura de venta (prefijo DIAN + fecha + consecutivo
 * dentro del rango autorizado). Antes vivía privado en PedidosB2BController;
 * la factura Dropi al despachar necesita el mismo consecutivo, no otro.
 */
class NumeroFactura
{
    public static function siguiente(): string
    {
        $prefijo = (setting('empresa.prefijo_dian') ?: 'FV') . '-' . now()->format('ymd') . '-';
        $rangoHasta = (int) setting('empresa.rango_hasta', 0) ?: null;

        return SiguienteConsecutivoFactura::run($prefijo, 4, $rangoHasta);
    }
}
