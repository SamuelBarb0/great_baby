<?php

namespace App\Modules\Cartera\Support;

use App\Models\EmpresaConfig;
use App\Modules\Cartera\Actions\SiguienteConsecutivoFactura;
use RuntimeException;

/**
 * Número de la próxima factura de venta (prefijo DIAN + fecha + consecutivo
 * dentro del rango autorizado). Antes vivía privado en PedidosB2BController;
 * la factura Dropi al despachar necesita el mismo consecutivo, no otro.
 *
 * Multiempresa · cada razón social numera con SU prefijo y SU rango, y como
 * el consecutivo se lleva por prefijo, cada una tiene su propia secuencia. La
 * principal sigue leyendo los ajustes `empresa.*` de siempre.
 */
class NumeroFactura
{
    public static function siguiente(?EmpresaConfig $empresa = null): string
    {
        if ($empresa && ! $empresa->es_principal) {
            if (blank($empresa->prefijo_dian)) {
                throw new RuntimeException(
                    "La empresa {$empresa->nombreCorto()} no tiene prefijo de facturación. Configuralo en Configuración → Empresas."
                );
            }
            $prefijo = $empresa->prefijo_dian.'-'.now()->format('ymd').'-';

            return SiguienteConsecutivoFactura::run($prefijo, 4, $empresa->rango_hasta ?: null);
        }

        $prefijo = (setting('empresa.prefijo_dian') ?: 'FV') . '-' . now()->format('ymd') . '-';
        $rangoHasta = (int) setting('empresa.rango_hasta', 0) ?: null;

        return SiguienteConsecutivoFactura::run($prefijo, 4, $rangoHasta);
    }
}
