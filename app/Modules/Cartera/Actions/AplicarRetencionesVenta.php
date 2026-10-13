<?php

namespace App\Modules\Cartera\Actions;

use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Services\CalculadorRetenciones;

/**
 * Calcula y deja escritas en la factura las retenciones que el cliente nos va
 * a practicar al pagarla.
 *
 * En una venta no retiene Great Baby: retiene el CLIENTE, si es agente de
 * retención. Paga total − retenciones y lo retenido queda a favor de Great
 * Baby ante la DIAN. Por eso:
 *
 *   · quién retiene lo dice la ficha del cliente (`retenciones_default`:
 *     {fuente, ica, iva}); sin marcar, no se calcula nada;
 *   · las tarifas y bases mínimas son las mismas reglas de Cartera →
 *     Retenciones que usa compras, con el concepto `contable.concepto_retencion_venta`;
 *   · la cuenta NO es la de la regla (2365, retenciones por pagar), sino la
 *     1355 de ventas: es un saldo a favor, no una deuda;
 *   · el saldo de cartera queda en el neto, que es lo que va a llegar. Si no,
 *     el cliente que paga bien aparecería debiendo lo retenido.
 *
 * Debe correr después de crear los ítems y ANTES del asiento contable y del
 * envío a SIIGO, que leen `retenciones`.
 */
class AplicarRetencionesVenta
{
    public function __construct(private readonly CalculadorRetenciones $calc) {}

    /** @return float total retenido */
    public function ejecutar(FacturaVenta $factura): float
    {
        // Idempotente: una factura se retiene una sola vez.
        if ($factura->retencionesAplicadas()->exists()) {
            return (float) $factura->retenciones;
        }

        $contacto = $factura->contacto()->first();
        $marcadas = array_filter((array) ($contacto?->retenciones_default ?? []));
        if (! $marcadas) {
            return 0.0;
        }

        $tipos = array_keys(array_filter([
            'retefuente' => ! empty($marcadas['fuente']),
            'reteica' => ! empty($marcadas['ica']),
            'reteiva' => ! empty($marcadas['iva']),
        ]));

        $retenciones = collect($this->calc->calcular(
            base: (float) $factura->subtotal - (float) $factura->descuento,
            concepto: (string) setting('contable.concepto_retencion_venta', 'compras_generales'),
            ciudad: $contacto->ciudad,
            iva: (float) $factura->impuestos,
            granContribuyente: in_array('reteiva', $tipos, true),
        ))
            ->filter(fn ($r) => in_array($r['tipo'], $tipos, true))
            ->map(fn ($r) => ['cuenta_puc' => $this->cuentaVenta($r['tipo'])] + $r)
            ->values()
            ->all();

        if (! $retenciones) {
            return 0.0;
        }

        $this->calc->aplicar($factura, $retenciones);

        $total = round(array_sum(array_column($retenciones, 'valor')), 2);
        $factura->forceFill([
            'retenciones' => $total,
            'saldo' => round((float) $factura->saldo - $total, 2),
        ])->save();

        return $total;
    }

    private function cuentaVenta(string $tipo): string
    {
        return match ($tipo) {
            'retefuente' => (string) setting('contable.cta_retefuente_venta', '135515'),
            'reteiva' => (string) setting('contable.cta_reteiva_venta', '135517'),
            'reteica' => (string) setting('contable.cta_reteica_venta', '135518'),
        };
    }
}
