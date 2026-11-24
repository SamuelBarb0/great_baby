<?php

namespace App\Modules\Dropi\Support;

use App\Modules\Dropi\Enums\EstadoCorte;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use App\Modules\Dropi\Services\ImprimirGuiasDropi;
use Illuminate\Support\Collection;

/**
 * Qué se empaca y en qué orden (reunión con la señora Lahida, 24-nov-2026):
 * lo del corte cerrado que se está despachando, el producto con más pedidos primero (cien
 * «celular táctil» seguidos) y dentro de cada producto el más viejo.
 *
 * Es EL MISMO orden de ImprimirGuiasDropi: la pantalla va en fila con la pila
 * de stickers que imprimió Aracely, así el empacador no busca nada.
 *
 * Varios empacadores se reparten solos: cada uno toma el siguiente libre, y lo
 * que otro ya tiene abierto se salta.
 */
class ColaDeEmpaque
{
    /**
     * @return Collection<int, DropiPedido>  con items
     */
    public function pedidos(?int $operarioId = null): Collection
    {
        // Con un corte ya cerrado («Cerrar corte», en despacho) se empaca ese; si
        //   no hay ninguno, todo lo pendiente: con enTrabajo() lo que sobró de un
        //   corte que nadie cerró desaparecería de la cola al abrir el siguiente.
        $corte = DropiCorte::query()->where('estado', EstadoCorte::EnDespacho->value)
            ->orderBy('fecha')->orderBy('numero')->first();

        $ajenos = EmpaqueRegistro::query()->where('estado', 'en_curso')
            ->when($operarioId, fn ($q) => $q->where('operario_id', '!=', $operarioId))
            ->pluck('pedido_id');

        return ImprimirGuiasDropi::ordenar(DropiPedido::query()
            ->whereIn('estado', [EstadoPedidoDropi::Pending, EstadoPedidoDropi::Alistando])
            ->when($corte, fn ($q) => $q->where('corte_id', $corte->id))
            ->whereNotIn('id', $ajenos)
            ->with(['items.variante.producto', 'items.producto'])
            ->get());
    }

    /** El siguiente libre para este empacador. */
    public function siguiente(int $operarioId, ?int $saltar = null): ?DropiPedido
    {
        return $this->pedidos($operarioId)->first(fn (DropiPedido $p) => $p->id !== $saltar);
    }

    /**
     * El primero de la cola que lleva este producto: escanear el producto sin
     * pedido abierto abre su pedido («escanea el producto y que le salga la guía»).
     */
    public function siguienteCon(int $operarioId, ?int $varianteId, ?int $productoId): ?DropiPedido
    {
        return $this->pedidos($operarioId)->first(fn (DropiPedido $p) => $p->items->contains(
            fn ($it) => ($varianteId && (int) $it->variante_id === $varianteId)
                || ($productoId && ! $it->variante_id && (int) $it->producto_id === $productoId)
        ));
    }
}
