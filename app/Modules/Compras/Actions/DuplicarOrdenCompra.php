<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Models\OrdenCompra;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Sprint 3 · A.1 · Crea otra OC en borrador con los mismos ítems, para reordenar
 * al mismo proveedor casi lo mismo. Vivía en `ComprasGestionController::ocDuplicar`.
 *
 * Conserva la línea de negocio (7-nov): una OC de Dropi duplicada sigue siendo
 * de Dropi. Antes la copia nacía siempre de Great Baby.
 */
class DuplicarOrdenCompra
{
    use AsAction;

    public function handle(OrdenCompra $o): OrdenCompra
    {
        $o->loadMissing('items');

        return CrearOrdenCompra::run([
            'proveedor_id' => $o->proveedor_id,
            'bodega_id' => $o->bodega_id,
            'tipo' => $o->tipo,
            'moneda' => $o->moneda ?? 'COP',
            'tasa_cambio' => (float) ($o->tasa_cambio ?? 1),
            'fecha_esperada' => null,
            'observaciones' => "Duplicada desde {$o->numero}",
            'linea_negocio' => $o->linea_negocio,
            'items' => $o->items->map(fn ($i) => [
                'descripcion' => $i->descripcion,
                'cantidad' => (float) $i->cantidad,
                'precio_unit' => (float) $i->precio_unit,
                'iva_pct' => (float) ($i->iva_pct ?? 0),
                'descuento_pct' => (float) ($i->descuento_pct ?? 0),
                'producto_id' => $i->producto_id,
                'variante_id' => $i->variante_id,
            ])->all(),
        ]);
    }
}
