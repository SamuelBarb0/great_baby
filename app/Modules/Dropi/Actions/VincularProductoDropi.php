<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiPedidoItem;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Enlaza una llave de Dropi con una variante del catálogo GB (una sola vez).
 *
 *  1. Guarda el vínculo: desde ahí el importador y el sync lo resuelven solos.
 *  2. Asigna la variante a los ítems que ya estaban esperando. Si se cambia un
 *     vínculo existente, también re-asigna los de pedidos que aún no se alistan
 *     (pending / pendiente_inventario); lo alistado ya movió inventario y no se toca.
 *  3. Re-evalúa los pedidos en «pendiente por inventario»: si ya tienen todo
 *     vinculado y con stock, pasan a pending (fuente=sistema, con bitácora).
 */
class VincularProductoDropi
{
    use AsAction;

    private const ESTADOS_REASIGNABLES = ['pending', 'pendiente_inventario'];

    public function __construct(protected ResolverEstadoInicial $resolverEstado) {}

    /**
     * @return array{items: int, pedidos_liberados: int}
     */
    public function handle(string $sku, int $varianteId, ?int $userId = null): array
    {
        $variante = ProductoVariante::findOrFail($varianteId);

        $items = DB::transaction(function () use ($sku, $variante, $userId) {
            $muestra = DropiPedidoItem::where('sku_dropi', $sku)->latest('id')->first();

            DropiVinculoProducto::updateOrCreate(
                ['sku_dropi' => $sku],
                [
                    'variante_id' => $variante->id,
                    'producto_nombre' => $muestra?->producto_nombre,
                    'variacion' => $muestra?->variacion,
                    'vinculado_por' => $userId,
                ],
            );

            $items = DropiPedidoItem::query()
                ->where('sku_dropi', $sku)
                ->where(fn ($q) => $q->where('variante_id', '!=', $variante->id)->orWhereNull('variante_id'))
                ->where(fn ($q) => $q->whereNull('variante_id')
                    ->orWhereHas('pedido', fn ($p) => $p->whereIn('estado', self::ESTADOS_REASIGNABLES)))
                ->get();

            // Uno a uno (no update masivo) para que quede en la auditoría del ítem.
            foreach ($items as $item) {
                $item->update(['variante_id' => $variante->id, 'producto_id' => $variante->producto_id]);
            }

            return $items;
        });

        $liberados = 0;
        $pendientes = DropiPedido::query()
            ->whereIn('id', $items->pluck('pedido_id')->unique())
            ->where('estado', EstadoPedidoDropi::PendienteInventario)
            ->with('items')
            ->get();

        foreach ($pendientes as $pedido) {
            $lineas = $pedido->items
                ->map(fn ($i) => ['sku' => $i->sku_dropi, 'cantidad' => $i->cantidad])
                ->all();

            if ($this->resolverEstado->porSku($lineas) !== EstadoPedidoDropi::Pending) {
                continue;
            }

            try {
                $pedido->transicionar(EstadoPedidoDropi::Pending, 'sistema', $userId, [
                    'motivo' => 'producto_dropi_vinculado', 'sku_dropi' => $sku,
                ]);
                $liberados++;
            } catch (\Throwable $e) {
                // Corte cerrado u otro guard: el vínculo ya quedó; el pedido se mueve a mano.
                Log::warning('dropi.vinculo.no_libero_pedido', ['guia' => $pedido->guia, 'msg' => $e->getMessage()]);
            }
        }

        return ['items' => $items->count(), 'pedidos_liberados' => $liberados];
    }
}
