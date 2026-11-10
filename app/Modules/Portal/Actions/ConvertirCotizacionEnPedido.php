<?php

namespace App\Modules\Portal\Actions;

use App\Modules\Portal\Models\Cotizacion;
use App\Modules\Portal\Models\CotizacionItem;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Convierte una cotización aceptada en un pedido, respetando lo cotizado.
 *
 * Antes esto se hacía a mano: el vendedor volvía a teclear el pedido con lo
 * que había prometido por WhatsApp. Se equivocaban, y cuando se equivocaban
 * nadie tenía contra qué comparar.
 *
 * El pedido se crea por `CrearPedidoCliente`, el mismo camino que usan el
 * portal y el vendedor, para no tener una segunda copia de la compuerta de
 * cartera, el ruteo por ciudad y el consecutivo. Lo único que se le agrega es
 * la lista de precios pactados: lo que manda es lo que se cotizó, no la lista
 * de hoy. Si se recalculara, cotizar no serviría de nada.
 */
class ConvertirCotizacionEnPedido
{
    /**
     * @param  int|null  $convertidaPor  Quién apretó el botón, que no es de
     *                                   quién es la venta. Ver abajo.
     */
    public function ejecutar(Cotizacion $cotizacion, ?int $convertidaPor = null): PedidoCliente
    {
        $cotizacion->loadMissing('items', 'cliente');

        /*
         * El motivo se pide al modelo y no se reimplementa acá.
         *
         * La pantalla muestra ese mismo texto en el botón deshabilitado, así
         * que si la razón viviera en dos sitios, un día dirían cosas distintas
         * y el vendedor no sabría a cuál creerle.
         */
        if ($porque = $cotizacion->porQueNoSeConvierte()) {
            throw ValidationException::withMessages(['cotizacion' => $porque]);
        }

        if ($cotizacion->items->isEmpty()) {
            throw ValidationException::withMessages([
                'cotizacion' => 'La cotización no tiene renglones.',
            ]);
        }

        $items = $cotizacion->items->map(fn (CotizacionItem $i) => [
            'variante_id' => $i->variante_id,
            'producto_id' => $i->producto_id,
            'cantidad' => (int) $i->cantidad,
        ])->all();

        $pactados = $cotizacion->items
            ->mapWithKeys(fn (CotizacionItem $i) => [$i->claveDeLinea() => (float) $i->precio_unitario])
            ->all();

        return DB::transaction(function () use ($cotizacion, $items, $pactados, $convertidaPor) {
            /*
             * Se vuelve a leer con bloqueo antes de crear nada.
             *
             * Dos clics seguidos en «Convertir» son dos peticiones, y sin esto
             * las dos pasarían la validación y crearían dos pedidos iguales
             * por la misma cotización.
             */
            $fresca = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();

            if ($fresca->pedido_id) {
                throw ValidationException::withMessages([
                    'cotizacion' => 'Esta cotización ya se convirtió en el pedido '
                        .($fresca->pedido?->numero ?? '#'.$fresca->pedido_id).'.',
                ]);
            }

            /*
             * La venta es del vendedor que cotizó, no de quien apretó el botón.
             *
             * Gerencia tiene que poder convertir la cotización de un vendedor
             * —él está en la calle, el cliente llamó y dijo que sí—, pero antes
             * eso mandaba el usuario de la sesión como vendedor del pedido y la
             * venta, con su comisión, salía a nombre de Gerencia.
             *
             * El `??` cubre la cotización sin vendedor: queda de quien la
             * convirtió, que es mejor que dejar el pedido huérfano. Y ojo que
             * ese dato no es decorativo: `CrearPedidoCliente` trata el pedido
             * sin vendedor como cliente autogestionado y le aplica el bloqueo
             * por stock del portal.
             */
            $pedido = app(CrearPedidoCliente::class)->ejecutar(
                cliente: $cotizacion->cliente,
                items: $items,
                notas: $cotizacion->notas,
                vendedorId: $fresca->vendedor_id ?? $convertidaPor,
                empresaId: $cotizacion->empresa_id,
                preciosPactados: $pactados,
            );

            $fresca->update([
                'estado' => 'convertida',
                'pedido_id' => $pedido->id,
                'convertida_at' => now(),
                // Quién apretó el botón queda acá, que es donde pasó:
                // `pedidos_cliente` no tiene columna de quién lo creó.
                'convertida_por' => $convertidaPor,
            ]);

            return $pedido;
        });
    }
}
