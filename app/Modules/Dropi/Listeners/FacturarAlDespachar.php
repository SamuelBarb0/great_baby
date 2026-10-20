<?php

namespace App\Modules\Dropi\Listeners;

use App\Modules\Dropi\Actions\FacturarPedidoDropi;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Events\PedidoDropiTransicionado;
use App\Modules\Dropi\Models\DropiPedido;
use Illuminate\Support\Facades\Log;

/**
 * 9.1 / 9.3 · Al despachar un pedido Dropi: remisión 1:1 + factura a
 * consumidor final (ver FacturarPedidoDropi). Vale para el despacho en la
 * bodega y para el que trae el sync cuando Dropi ya lo despachó.
 *
 * Una factura que no se puede crear NO frena el despacho: la mercancía ya
 * salió. El error queda en el log y el pedido sin `factura_venta_id`, a la
 * vista para facturarlo después.
 */
class FacturarAlDespachar
{
    private const ANTES_DEL_DESPACHO = [
        EstadoPedidoDropi::Pending,
        EstadoPedidoDropi::PendienteInventario,
        EstadoPedidoDropi::Alistando,
        EstadoPedidoDropi::Empacado,
    ];

    public function handle(PedidoDropiTransicionado $e): void
    {
        if ($e->estadoHasta !== EstadoPedidoDropi::Despachado
            || ($e->estadoDesde !== null && ! in_array($e->estadoDesde, self::ANTES_DEL_DESPACHO, true))) {
            return;
        }

        $pedido = DropiPedido::find($e->pedido->id);
        if (! $pedido) return;

        // `new`, no `app()`: dentro de un listener, AsAction entrega su
        // decorador y se pierde `$motivo`.
        $accion = new FacturarPedidoDropi();
        try {
            // Su propia transacción (un savepoint dentro de la del cambio de
            // estado): si falla, se deshace sólo la factura, no el despacho.
            $factura = $accion->handle($pedido, $e->userId);
        } catch (\Throwable $ex) {
            Log::error('dropi.facturar_al_despachar', [
                'pedido_id' => $pedido->id, 'guia' => $pedido->guia, 'msg' => $ex->getMessage(),
            ]);

            return;
        }

        if (! $factura && $accion->motivo && $accion->motivo !== 'facturación automática apagada') {
            Log::warning('dropi.sin_factura_al_despachar', [
                'pedido_id' => $pedido->id, 'guia' => $pedido->guia, 'motivo' => $accion->motivo,
            ]);
        }
    }
}
