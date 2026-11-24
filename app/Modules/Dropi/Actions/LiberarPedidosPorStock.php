<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Cuando entra mercancía, los pedidos que estaban frenados por falta de stock
 * vuelven a Pending si ya les alcanza (24-nov-2026: al recibir SA383 el pedido
 * que sólo esperaba el DIDA-004 seguía en «Pendiente por inventario» y no
 * aparecía en las colas de recolección ni de empaque).
 *
 * El más viejo primero, y lo que toma uno se descuenta antes de revisar el
 * siguiente (ResolverEstadoInicial::alcanzaConApartado): dos pedidos no se
 * quedan con la misma unidad. Es la misma regla de stock con que nace el pedido
 * y con que se libera al vincular un producto (VincularProductoDropi).
 */
class LiberarPedidosPorStock
{
    use AsAction;

    public function __construct(protected ResolverEstadoInicial $resolverEstado) {}

    /**
     * @param  array<int, int>  $varianteIds  variantes que recibieron stock
     * @param  array<int, int>  $productoIds  productos sin variante (agregados) que recibieron stock
     * @return int pedidos que pasaron a Pending
     */
    public function handle(array $varianteIds, array $productoIds, ?int $userId = null, array $payload = []): int
    {
        $varianteIds = array_values(array_unique(array_filter($varianteIds)));
        $productoIds = array_values(array_unique(array_filter($productoIds)));
        if (! $varianteIds && ! $productoIds) {
            return 0;
        }

        $pedidos = DropiPedido::query()
            ->where('estado', EstadoPedidoDropi::PendienteInventario)
            ->whereHas('items', fn ($q) => $q->where(fn ($w) => $w
                ->when($varianteIds, fn ($x) => $x->orWhereIn('variante_id', $varianteIds))
                ->when($productoIds, fn ($x) => $x->orWhere(fn ($a) => $a->whereNull('variante_id')->whereIn('producto_id', $productoIds)))))
            ->with('items')
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $apartado = [];
        $liberados = 0;
        foreach ($pedidos as $pedido) {
            $lineas = $pedido->items->map(fn ($i) => ['sku' => $i->sku_dropi, 'cantidad' => (int) $i->cantidad])->all();
            if (! $this->resolverEstado->alcanzaConApartado($lineas, $apartado)) {
                continue;
            }

            try {
                $pedido->transicionar(EstadoPedidoDropi::Pending, 'sistema', $userId, ['motivo' => 'stock_recibido'] + $payload);
                $liberados++;
            } catch (\Throwable $e) {
                // Corte finalizado u otro guard: el stock ya entró; el pedido se mueve a mano.
                Log::warning('dropi.stock.no_libero_pedido', ['guia' => $pedido->guia, 'msg' => $e->getMessage()]);
            }
        }

        return $liberados;
    }
}
