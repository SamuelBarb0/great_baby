<?php

namespace App\Modules\Dropi\Listeners;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Events\PedidoDropiTransicionado;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Services\SalidaInventarioDropi;

/**
 * Re-audit H4 datos · un pedido empacado que vuelve atrás (rework
 * `Empacado → Alistando`) o se cancela antes de salir sigue físicamente en la
 * bodega: se reingresa lo descontado, a la misma bodega y con el mismo costo
 * con que salió, y se reversa el costo de ventas.
 *
 * Antes sólo cubría el rework: un pedido empacado y luego cancelado dejaba
 * las unidades descontadas para siempre. Idempotente: si el pedido no tiene
 * nada fuera del kardex, no hace nada.
 */
class RevertirEgresoAlDesempacar
{
    public const REFERENCIA_TIPO = SalidaInventarioDropi::REVERSO;

    private const VUELVE_A_BODEGA = [
        EstadoPedidoDropi::Alistando,
        EstadoPedidoDropi::Pending,
        EstadoPedidoDropi::PendienteInventario,
        EstadoPedidoDropi::CanceladoGb,
        EstadoPedidoDropi::CanceladoDropi,
    ];

    public function handle(PedidoDropiTransicionado $e): void
    {
        if ($e->estadoDesde !== EstadoPedidoDropi::Empacado
            || ! in_array($e->estadoHasta, self::VUELVE_A_BODEGA, true)) {
            return;
        }

        $pedido = DropiPedido::find($e->pedido->id);
        if (! $pedido) return;

        app(SalidaInventarioDropi::class)->revertir(
            $pedido,
            $e->userId,
            $e->estadoHasta === EstadoPedidoDropi::Alistando ? 'rework' : $e->estadoHasta->label(),
        );
    }
}
