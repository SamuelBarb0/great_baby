<?php

namespace App\Modules\Dropi\Listeners;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Events\PedidoDropiTransicionado;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Services\SalidaInventarioDropi;

/**
 * P3 · Al pasar un pedido Dropi a Empacado, descuenta la mercancía del stock
 * de venta y causa su costo (ver `SalidaInventarioDropi`).
 *
 * Se ejecuta síncrono dentro de la transacción del cambio de estado: si no hay
 * saldo, la excepción deshace también el paso a Empacado.
 *
 * También cubre el pedido que llega a Despachado sin haber pasado por la
 * estación (Dropi lo despachó y el sync lo trae así): antes ese pedido no se
 * descontaba nunca. Ahí no se exige saldo, porque la mercancía ya salió.
 */
class DescontarInventarioAlEmpacar
{
    public const REFERENCIA_TIPO = SalidaInventarioDropi::EGRESO;

    /** Estados desde los que un salto a Despachado significa «se saltó el empaque». */
    private const ANTES_DEL_EMPAQUE = [
        EstadoPedidoDropi::Pending,
        EstadoPedidoDropi::PendienteInventario,
        EstadoPedidoDropi::Alistando,
    ];

    public function handle(PedidoDropiTransicionado $e): void
    {
        $alEmpacar = $e->estadoHasta === EstadoPedidoDropi::Empacado;
        $despachoSinEmpaque = $e->estadoHasta === EstadoPedidoDropi::Despachado
            && in_array($e->estadoDesde, self::ANTES_DEL_EMPAQUE, true);

        if (! $alEmpacar && ! $despachoSinEmpaque) {
            return;
        }

        $pedido = DropiPedido::find($e->pedido->id);
        if (! $pedido) return;

        app(SalidaInventarioDropi::class)->descontar(
            $pedido,
            $e->userId,
            // Sólo frena quien empaca a mano: el sync no puede «desdespachar».
            exigirSaldo: $e->fuente === 'manual',
        );
    }
}
