<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiPedidoItem;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Enlaza una llave de Dropi con el catálogo GB (una sola vez): con una variante,
 * o —si el producto está en modo AGREGADO, sin variantes— con el producto.
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
    public function handle(string $sku, int $varianteId, ?int $userId = null, array $nombres = []): array
    {
        $variante = ProductoVariante::findOrFail($varianteId);

        return $this->vincular($sku, $variante->id, $variante->producto_id, $userId, $nombres);
    }

    /**
     * Vincula la llave con un producto AGREGADO (`desglose_stock = false`).
     *
     * Todas las variaciones de Dropi de ese producto descuentan del mismo total,
     * que es como el ERP ya lleva su stock. Un producto con desglose NO se
     * vincula entero: su stock está repartido por variante y hay que elegir una.
     *
     * @param  array{producto_nombre?: ?string, variacion?: ?string}  $nombres  lo que dice Dropi, para la lista
     */
    public function aProducto(string $sku, int $productoId, ?int $userId = null, array $nombres = []): array
    {
        $producto = Producto::findOrFail($productoId);
        if ($producto->desglose_stock) {
            throw new \DomainException(
                "El producto {$producto->referencia} lleva el stock por variante: vincule la llave {$sku} con una de sus variantes."
            );
        }

        return $this->vincular($sku, null, $producto->id, $userId, $nombres);
    }

    /** @return array{items: int, pedidos_liberados: int} */
    private function vincular(string $sku, ?int $varianteId, int $productoId, ?int $userId, array $nombres): array
    {
        $items = DB::transaction(function () use ($sku, $varianteId, $productoId, $userId, $nombres) {
            $muestra = DropiPedidoItem::where('sku_dropi', $sku)->latest('id')->first();
            $variacion = $muestra?->variacion ?? ($nombres['variacion'] ?? null);
            $existente = DropiVinculoProducto::where('sku_dropi', $sku)->first();

            DropiVinculoProducto::updateOrCreate(
                ['sku_dropi' => $sku],
                [
                    'variante_id' => $varianteId,
                    // Sólo se guarda el producto cuando ES el destino (agregado);
                    // con variante, el producto sale de la variante.
                    'producto_id' => $varianteId ? null : $productoId,
                    'producto_nombre' => $muestra?->producto_nombre ?? ($nombres['producto_nombre'] ?? null),
                    'variacion' => $variacion,
                    // Packs (27-nov-2026): «X 2 CAJAS» son 2 del ERP. Lo que ya
                    //   tenía el vínculo se respeta (pudo corregirse a mano).
                    'unidades' => $existente?->unidades ?? DropiVinculoProducto::detectarUnidades($variacion),
                    'vinculado_por' => $userId,
                ],
            );

            // ¿Ya apunta exactamente a este destino? Entonces no hay nada que reasignar.
            // El `whereNotNull` no sobra: sin él, en un ítem que todavía no tiene
            // destino `variante_id = X` vale NULL, el NOT de NULL también, y el
            // ítem que justo se quería vincular quedaba fuera.
            $yaApunta = fn ($q) => $varianteId
                ? $q->whereNotNull('variante_id')->where('variante_id', $varianteId)
                : $q->whereNull('variante_id')->whereNotNull('producto_id')->where('producto_id', $productoId);

            $items = DropiPedidoItem::query()
                ->where('sku_dropi', $sku)
                ->whereNot($yaApunta)
                // Sin destino todavía, o de un pedido que aún no se alista: lo
                // alistado ya movió inventario con el destino viejo y no se toca.
                ->where(fn ($q) => $q->where(fn ($w) => $w->whereNull('variante_id')->whereNull('producto_id'))
                    ->orWhereHas('pedido', fn ($p) => $p->whereIn('estado', self::ESTADOS_REASIGNABLES)))
                ->get();

            // Uno a uno (no update masivo) para que quede en la auditoría del ítem.
            foreach ($items as $item) {
                $item->update(['variante_id' => $varianteId, 'producto_id' => $productoId]);
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
