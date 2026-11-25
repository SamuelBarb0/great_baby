<?php

namespace App\Modules\Dropi\Support;

use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiLoteImpresion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiPedidoItem;
use App\Modules\Dropi\Models\DropiRecoleccionMarca;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Logistica\Support\ZonaDeLaBodega;
use Illuminate\Support\Collection;

/**
 * Lo que hay que sacar de la bodega para un corte Dropi, en el orden en que se
 * camina.
 *
 * Reunión del 19-nov-2026: «la bodega es por niveles, el sistema lee cada
 * factura y, para no hacer tantos viajes, tienen una ruta». La ruta es la
 * misma de la hoja de oleada del ERP (ZonaDeLaBodega): zona, puesto del rack
 * en el recorrido, y el código, que ya trae sección y nivel en orden.
 *
 * Lo usan la Vista del Alistador (pantalla y cola de empaque) y el PDF de la
 * recolección: si cada uno ordenara por su cuenta, la hoja impresa y la tablet
 * mandarían a caminar distinto.
 *
 * DE DÓNDE SALE LA UBICACIÓN
 *
 * `dropi_pedido_items.ubicacion_asignada_id` existe pero nada lo llena, así que
 * la pantalla mostraba «—» en todas las filas. La ubicación se toma de donde
 * está la mercancía según el kardex: la posición de venta con más saldo de esa
 * referencia, que es de donde el alistador la tomaría.
 */
class RecoleccionDelCorte
{
    /** Estados que ya no hay que recoger: salieron de la bodega o no salen. */
    public const ESTADOS_FUERA = [
        EstadoPedidoDropi::Empacado, EstadoPedidoDropi::Despachado,
        EstadoPedidoDropi::Entregado, EstadoPedidoDropi::Pagado,
        EstadoPedidoDropi::CanceladoDropi, EstadoPedidoDropi::CanceladoGb,
        EstadoPedidoDropi::Devuelto, EstadoPedidoDropi::GestionadoEnDropi,
    ];

    /**
     * Totales por SKU de Dropi, ya en el orden del recorrido.
     *
     * @return array<string, array<string, mixed>> por SKU de Dropi
     */
    public function totales(int $corteId): array
    {
        return $this->totalesDe(DropiPedido::query()
            ->where('corte_id', $corteId)
            ->whereNotIn('estado', self::ESTADOS_FUERA)
            ->with(['items.variante.producto', 'items.producto'])
            ->get());
    }

    /**
     * Lo mismo para unos pedidos escogidos: la lista que acompaña las guías
     * que Aracely imprime de una selección (24-nov-2026).
     *
     * @param  Collection<int, DropiPedido>  $pedidos  con items.variante.producto e items.producto
     * @return array<string, array<string, mixed>>
     */
    public function totalesDe(Collection $pedidos): array
    {
        $items = $pedidos->flatMap->items;
        $ubicaciones = $this->ubicacionesDe($items);

        $grupos = [];
        foreach ($items as $it) {
            $key = $it->sku_dropi ?: 'sin-sku';
            // Un ítem es de una variante o de un producto agregado (sin
            // variantes): los agregados son la mayoría de los de Dropi.
            $producto = $it->variante?->producto ?? $it->producto;
            $u = $ubicaciones[self::clave($it)] ?? null;

            $grupos[$key] ??= [
                'sku' => $key,
                // Sin vínculo con el ERP, el nombre que trae Dropi: bodega no
                //   reconoce «DV-1799321» (prueba del 24-nov-2026).
                'producto' => $producto?->nombre ?? ($it->producto_nombre ?: 'SKU sin match'),
                'vinculado' => $producto !== null,
                'referencia' => $producto?->referencia ?? '—',
                'variante' => $it->nombreVariante(),
                'total_unidades' => 0,
                'ubicacion_sugerida' => $u['codigo'] ?? '—',
                'zona' => $u['zona'] ?? null,
                'orden' => $u['orden'] ?? self::SIN_UBICAR,
            ];
            $grupos[$key]['total_unidades'] += (int) $it->cantidad;
        }

        uasort($grupos, fn ($a, $b) => [$a['orden'], $a['producto'], $a['sku']] <=> [$b['orden'], $b['producto'], $b['sku']]);

        return $grupos;
    }

    /**
     * Los totales con el «chulito» de quien ya recogió cada referencia.
     *
     * @return list<array<string, mixed>>
     */
    public function conMarcas(int $corteId): array
    {
        return $this->marcar($this->totales($corteId), DropiRecoleccionMarca::query()->where('corte_id', $corteId));
    }

    /**
     * @param  array<string, array<string, mixed>>  $grupos
     * @param  \Illuminate\Database\Eloquent\Builder<DropiRecoleccionMarca>  $deDonde  las marcas del corte o del lote
     * @return list<array<string, mixed>>
     */
    private function marcar(array $grupos, \Illuminate\Database\Eloquent\Builder $deDonde): array
    {
        $marcas = $deDonde
            ->whereIn('sku_dropi', array_keys($grupos))
            ->with('usuario:id,name')
            ->get()
            ->keyBy('sku_dropi');

        foreach ($grupos as $sku => &$g) {
            $marca = $marcas->get($sku);
            // Si después de marcar entró otro pedido con esta referencia, lo
            // marcado ya no alcanza: vuelve a pendiente con lo que falta.
            $g['marcada'] = $marca !== null && $marca->cantidad >= $g['total_unidades'];
            $g['faltan'] = $marca !== null && ! $g['marcada'] ? $g['total_unidades'] - $marca->cantidad : 0;
            $g['marcada_por'] = $marca?->usuario?->name;
            $g['marcada_at'] = $marca?->marcado_at?->format('H:i');
        }
        unset($g);

        return array_values($grupos);
    }

    /**
     * Lo mismo para un lote de «Imprimir guías»: la hoja que la encargada tiene
     * en la mano (24-nov-2026). Las marcas de «recogido» son de ese lote.
     *
     * @return list<array<string, mixed>>
     */
    public function conMarcasDeLote(DropiLoteImpresion $lote): array
    {
        $grupos = $this->totalesDe(DropiPedido::query()
            ->where('lote_impresion_id', $lote->id)
            ->whereNotIn('estado', self::ESTADOS_FUERA)
            ->with(['items.variante.producto', 'items.producto'])
            ->get());

        return $this->marcar($grupos, DropiRecoleccionMarca::query()->where('lote_impresion_id', $lote->id));
    }

    /**
     * Los lotes impresos que todavía tienen algo por empacar, el más viejo
     * primero: lo que la Vista del Alistador ofrece. Sin ninguno, no hay nada
     * que recoger hasta que Aracely genere e imprima las guías.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, DropiLoteImpresion>
     */
    public function lotesAbiertos(): \Illuminate\Database\Eloquent\Collection
    {
        return DropiLoteImpresion::query()
            ->where('estado', DropiLoteImpresion::LISTO)
            ->whereHas('pedidosImpresos', fn ($q) => $q->whereIn('estado', DropiPedido::estadosDespachables()))
            ->oldest('id')
            ->get();
    }

    /**
     * La cola de empaque en el orden del recorrido: primero el pedido cuya
     * primera parada queda más cerca del inicio de la ruta. Con el mismo
     * puesto, el más viejo primero, como estaba.
     *
     * @param  Collection<int, DropiPedido>  $pedidos  con `items` cargados
     * @return Collection<int, DropiPedido>
     */
    public function ordenarPorRuta(Collection $pedidos): Collection
    {
        $ubicaciones = $this->ubicacionesDe($pedidos->flatMap->items);

        $primeraParada = fn (DropiPedido $p) => $p->items
            ->map(fn ($it) => $ubicaciones[self::clave($it)]['orden'] ?? self::SIN_UBICAR)
            ->min() ?? self::SIN_UBICAR;

        return $pedidos
            ->sort(fn ($a, $b) => [$primeraParada($a), $a->created_at, $a->id] <=> [$primeraParada($b), $b->created_at, $b->id])
            ->values();
    }

    /**
     * De qué posición se saca cada ítem de la cola de empaque.
     *
     * @param  Collection<int, DropiPedido>  $pedidos  con `items` cargados
     * @return array<int, string> item_id => código de la posición
     */
    public function ubicacionPorItem(Collection $pedidos): array
    {
        $items = $pedidos->flatMap->items;
        $ubicaciones = $this->ubicacionesDe($items);

        $out = [];
        foreach ($items as $it) {
            if ($u = $ubicaciones[self::clave($it)] ?? null) {
                $out[$it->id] = $u['codigo'];
            }
        }

        return $out;
    }

    /** Lo que no tiene ubicación va al final del recorrido. */
    private const SIN_UBICAR = [1, 'ZZZ', PHP_INT_MAX, '', ''];

    private static function clave(DropiPedidoItem $it): string
    {
        return $it->variante_id ? 'v:' . $it->variante_id : 'p:' . $it->producto_id;
    }

    /**
     * Dónde está cada referencia: la posición de venta con más saldo.
     *
     * @param  Collection<int, DropiPedidoItem>  $items
     * @return array<string, array{codigo: string, zona: ?string, orden: array}>
     */
    private function ubicacionesDe(Collection $items): array
    {
        $variantes = $items->pluck('variante_id')->filter()->unique()->values()->all();
        $productos = $items->whereNull('variante_id')->pluck('producto_id')->filter()->unique()->values()->all();

        if (! $variantes && ! $productos) {
            return [];
        }

        $posiciones = InventarioUbicacion::query()
            ->where('activa', true)
            ->where('categoria', CategoriaUbicacion::Venta->value)
            // Los espejos del catálogo de SIIGO no guardan mercancía.
            ->where('codigo', 'not like', 'SIIGO-%')
            ->get(['id', 'codigo', 'pasillo'])
            ->keyBy('id');

        if ($posiciones->isEmpty()) {
            return [];
        }

        $saldos = function (string $campo, array $ids, string $prefijo) use ($posiciones) {
            if (! $ids) {
                return collect();
            }

            return InventarioMovimiento::query()
                ->whereIn($campo, $ids)
                ->whereIn('ubicacion_id', $posiciones->keys())
                // Un producto cuenta como agregado sólo si el movimiento no
                // trae variante; si no, se sumaría el saldo de sus variantes.
                ->when($campo === 'producto_id', fn ($q) => $q->whereNull('variante_id'))
                ->selectRaw("{$campo} as sujeto, ubicacion_id, SUM(cantidad) as saldo")
                ->groupBy($campo, 'ubicacion_id')
                ->havingRaw('SUM(cantidad) > 0')
                ->get()
                ->map(fn ($r) => ['clave' => $prefijo . ':' . $r->sujeto, 'ubicacion_id' => (int) $r->ubicacion_id, 'saldo' => (int) $r->saldo]);
        };

        $out = [];
        $saldos('variante_id', $variantes, 'v')
            ->concat($saldos('producto_id', $productos, 'p'))
            ->sortByDesc('saldo')
            ->each(function ($r) use (&$out, $posiciones) {
                if (isset($out[$r['clave']])) {
                    return;
                }
                $pos = $posiciones[$r['ubicacion_id']];
                $zona = ZonaDeLaBodega::deRack($pos->pasillo);
                $out[$r['clave']] = [
                    'codigo' => $pos->codigo,
                    'zona' => $zona,
                    'orden' => [0, $zona ?? 'ZZZ', ZonaDeLaBodega::ordenEnElRecorrido($pos->pasillo), (string) $pos->pasillo, $pos->codigo],
                ];
            });

        return $out;
    }
}
