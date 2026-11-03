<?php

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\OrdenCompraItem;
use InvalidArgumentException;

/**
 * Lo que vale en PESOS la mercancía de una orden de importación.
 *
 * El precio de la orden está en la moneda del proveedor (RMB, USD). Hasta acá la
 * recepción y la liquidación lo tomaban tal cual: 3,50 RMB entraban al libro y al
 * inventario como 3,50 pesos.
 */
class CostoImportacion
{
    /** @var array<int, array<int, float>> orden_id => item_id => costo estimado */
    private array $estimados = [];

    /**
     * Pesos por unidad de la moneda de la orden. Manda la tasa del contenedor,
     * que es la de la nacionalización; si todavía no va en uno, la de la orden.
     */
    public function tasa(OrdenCompra $orden): float
    {
        if ($orden->moneda === 'COP') {
            return 1.0;
        }

        $contenedor = $orden->belongsToMany(Importacion::class, 'compras_importacion_ordenes', 'orden_id', 'importacion_id')->first();
        foreach ([$contenedor?->tasa_cambio_liquidacion, $orden->tasa_cambio] as $tasa) {
            // 1 es el valor por defecto de la orden, y ninguna moneda extranjera vale un peso.
            if ((float) $tasa > 1) {
                return (float) $tasa;
            }
        }

        throw new InvalidArgumentException("Falta la tasa de cambio de {$orden->moneda} de la orden {$orden->numero}: defínala en el contenedor antes de recibir.");
    }

    /** Lo que se le debe al proveedor por unidad, ya en pesos y con su descuento. */
    public function fobUnitario(OrdenCompraItem $item, OrdenCompra $orden): float
    {
        $neto = (float) $item->cantidad > 0
            ? (float) $item->subtotal / (float) $item->cantidad
            : (float) $item->precio_unit;

        return round($neto * $this->tasa($orden), 4);
    }

    /**
     * Costo con que entra al kardex cuando Jorge acepta. Si ya se liquidó es el
     * real. Si no, el de la pre-liquidación, y la liquidación lo corrige después.
     */
    public function costoParaKardex(OrdenCompraItem $item, OrdenCompra $orden): float
    {
        if ((float) $item->costo_prorrateado_unit > 0) {
            return (float) $item->costo_prorrateado_unit;
        }

        return $this->estimadoDe($orden)[$item->id] ?? $this->fobUnitario($item, $orden);
    }

    /** @return array<int, float> */
    private function estimadoDe(OrdenCompra $orden): array
    {
        if (! isset($this->estimados[$orden->id])) {
            $this->estimados[$orden->id] = collect(app(PreliquidarOrden::class)->handle($orden)['lineas'] ?? [])
                ->filter(fn ($l) => isset($l['unidad']['costo']))
                ->mapWithKeys(fn ($l) => [$l['id'] => (float) $l['unidad']['costo']])
                ->all();
        }

        return $this->estimados[$orden->id];
    }
}
