<?php

namespace App\Modules\Dropi\Support;

use App\Modules\Dropi\Enums\EstadoCorte;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use App\Modules\Dropi\Services\ImprimirGuiasDropi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Qué se empaca y en qué orden (reunión con la señora Lahida, 24-nov-2026): lo
 * que Aracely imprimió y nada más. Imprimir es mandar a empaque: ella filtra lo
 * que ve, lo selecciona e imprime, y esos pedidos les van llegando solos a los
 * empacadores.
 *
 * Va en el orden de la pila de stickers: lo impreso desde el ERP por su
 * posición en la pila (un lote detrás del otro); lo impreso en Dropi después,
 * con el mismo orden de ImprimirGuiasDropi (el producto con más pedidos
 * primero y, dentro, el más viejo).
 *
 * Varios empacadores se reparten solos: cada uno toma el siguiente libre, y lo
 * que otro ya tiene abierto se salta.
 *
 * Son 300 a 800 paquetes y cada escaneo, confirmación y vuelta de la estación
 * pregunta por la cola: el orden sale de la base (`orden_empaque`) y los
 * productos se cargan sólo de los pocos pedidos que se muestran o se abren.
 */
class ColaDeEmpaque
{
    private const CON_ITEMS = ['items.variante.producto', 'items.producto'];

    /**
     * La cola, o sus primeros `$limite`.
     *
     * @return Collection<int, DropiPedido>  con items
     */
    public function pedidos(?int $operarioId = null, ?int $limite = null): Collection
    {
        $delErp = $this->base($operarioId)->whereNotNull('orden_empaque')
            ->orderBy('orden_empaque')->orderBy('id')
            ->when($limite, fn ($q) => $q->limit($limite))
            ->with(self::CON_ITEMS)->get();

        if ($limite && $delErp->count() >= $limite) {
            return $delErp->values();
        }

        // Lo impreso en Dropi no tiene posición en la pila: suele ser poco.
        $deDropi = ImprimirGuiasDropi::ordenar($this->base($operarioId)->whereNull('orden_empaque')->with(self::CON_ITEMS)->get());

        $todo = $delErp->concat($deDropi)->values();

        return $limite ? $todo->take($limite)->values() : $todo;
    }

    /**
     * Los primeros de un lote impreso, en el orden de la pila (Vista del Alistador).
     *
     * @return Collection<int, DropiPedido>  con items
     */
    public function delLote(int $loteId, ?int $operarioId = null, int $limite = 20): Collection
    {
        return $this->base($operarioId)->where('lote_impresion_id', $loteId)
            ->orderBy('orden_empaque')->orderBy('id')->limit($limite)
            ->with(self::CON_ITEMS)->get();
    }

    /** Cuántos hay por empacar, sin cargar ninguno. */
    public function contar(?int $operarioId = null): int
    {
        return $this->base($operarioId)->count();
    }

    /** Pendientes que nadie ha impreso: no le llegan a nadie hasta que Aracely los imprima. */
    public function sinImprimir(): int
    {
        $esperan = array_filter(DropiPedido::estadosDespachables(), fn ($e) => $e !== EstadoPedidoDropi::Alistando);

        return DropiPedido::query()->whereIn('estado', $esperan)->guiaImpresa(false)->count();
    }

    /** El siguiente libre para este empacador. */
    public function siguiente(int $operarioId, ?int $saltar = null): ?DropiPedido
    {
        return $this->pedidos($operarioId, 2)->first(fn (DropiPedido $p) => $p->id !== $saltar);
    }

    /**
     * El primero de la cola que lleva este producto: escanear el producto sin
     * pedido abierto abre su pedido («escanea el producto y que le salga la guía»).
     */
    public function siguienteCon(int $operarioId, ?int $varianteId, ?int $productoId): ?DropiPedido
    {
        if (! $varianteId && ! $productoId) {
            return null;
        }
        $lleva = fn (Builder $q) => $q->whereHas('items', fn ($i) => $i
            ->when($varianteId, fn ($w) => $w->where('variante_id', $varianteId))
            ->when(! $varianteId, fn ($w) => $w->whereNull('variante_id')->where('producto_id', $productoId)));

        return $lleva($this->base($operarioId))->whereNotNull('orden_empaque')
            ->orderBy('orden_empaque')->orderBy('id')->with(self::CON_ITEMS)->first()
            ?? ImprimirGuiasDropi::ordenar($lleva($this->base($operarioId))->whereNull('orden_empaque')->with(self::CON_ITEMS)->get())->first();
    }

    /** Lo que se puede empacar: impreso, despachable, fuera de un corte cerrado y sin otro empacador encima. */
    private function base(?int $operarioId): Builder
    {
        $ajenos = EmpaqueRegistro::query()->where('estado', 'en_curso')
            ->when($operarioId, fn ($q) => $q->where('operario_id', '!=', $operarioId))
            ->select('pedido_id');

        return DropiPedido::query()
            // También lo «pendiente por inventario» mientras no se exija stock del ERP.
            ->whereIn('estado', DropiPedido::estadosDespachables())
            ->guiaImpresa()
            // Un corte cerrado no deja tocar sus pedidos: abrirlo tumbaba la estación.
            ->whereDoesntHave('corte', fn ($q) => $q->where('estado', EstadoCorte::Cerrado->value))
            ->whereNotIn('id', $ajenos);
    }
}
