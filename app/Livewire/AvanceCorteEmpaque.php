<?php

namespace App\Livewire;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use App\Modules\Dropi\Support\RecoleccionDelCorte;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * El avance del corte en la estación de empaque (27-nov-2026: «que en la
 * estación viéramos cómo se van bajando los pedidos a medida que vamos
 * pistoleando, y arriba los que faltan»). Un componente aparte que se refresca
 * solo cada 5 s: así el refresco no toca el campo donde escanea la pistola.
 *
 * Con su búsqueda («una barra de búsqueda para saber si un pedido está
 * empacado o está por empacar»): por guía, orden o cliente, en cualquier corte.
 *
 * Y lo que quedó de cortes anteriores (28-nov-2026, la señora Lahida: «del corte
 * tal faltan cinco pedidos, cinco zapatos»; a veces pasan dos días sin
 * despacharse): por corte, cuántos y qué productos, y cada pedido con
 * «Empacar →», que lo abre en la estación sin ir a buscarlo.
 */
class AvanceCorteEmpaque extends Component
{
    /** Cuántos empacados se listan sin buscar; el resto se cuenta. */
    public const EMPACADOS_VISIBLES = 15;

    /** Cuántos resultados de la búsqueda se muestran. */
    public const RESULTADOS = 6;

    /** Hasta cuántos días atrás se buscan pedidos que quedaron de otros cortes. */
    public const DIAS_ANTERIORES = 15;

    /** Cuántos productos se nombran en el resumen de un corte anterior; el resto se cuenta. */
    public const PRODUCTOS_RESUMEN = 4;

    public string $buscar = '';

    public function render()
    {
        $recoleccion = app(RecoleccionDelCorte::class);
        $empacando = RecoleccionDelCorte::empacandoAhora();
        $q = mb_strtolower(trim($this->buscar));
        $coincide = fn (array $fila) => $q === '' || str_contains(mb_strtolower($fila['guia'] . ' ' . ($fila['orden'] ?? '') . ' ' . $fila['cliente'] . ' ' . $fila['productos']), $q);

        $enTrabajo = $recoleccion->cortesEnTrabajo();
        $cortes = $enTrabajo->map(function (DropiCorte $c) use ($recoleccion, $empacando, $coincide) {
            $base = fn () => DropiPedido::query()->where('corte_id', $c->id)->whereNotNull('lote_impresion_id');
            $faltan = $base()->whereIn('estado', DropiPedido::estadosDespachables())->guiaImpresa()
                ->with(['items.variante.producto', 'items.producto', 'items.vinculo'])
                ->orderByRaw('orden_empaque IS NULL')->orderBy('orden_empaque')->orderBy('id')->get()
                ->values()
                ->map(fn (DropiPedido $p, int $i) => [
                    'id' => $p->id,
                    'guia' => (string) $p->guia,
                    'orden' => (string) $p->dropi_orden_id,
                    'cliente' => (string) $p->cliente_nombre,
                    'productos' => RecoleccionDelCorte::resumenProductos($p),
                    'empacando' => $empacando[$p->id] ?? null,
                    'puesto' => $i + 1,
                ]);
            $empacados = $recoleccion->empacados($c->id);
            $total = $faltan->count() + $empacados->count();

            return [
                'etiqueta' => $c->etiqueta(),
                'faltan' => $faltan->filter($coincide)->values(),
                'empacados' => $empacados->filter($coincide)->values(),
                'faltan_total' => $faltan->count(),
                'empacados_total' => $empacados->count(),
                'total' => $total,
                'pct' => $total > 0 ? (int) round(100 * $empacados->count() / $total) : 0,
            ];
        });

        return view('livewire.avance-corte-empaque', [
            'cortes' => $cortes,
            'anteriores' => $this->anteriores($enTrabajo->pluck('id')->all(), $empacando, $coincide),
            'resultados' => mb_strlen($q) >= 3 ? $this->buscarPedidos($q, $cortes) : null,
        ]);
    }

    /**
     * Lo que quedó por empacar de cortes que ya no están en trabajo, el corte
     * más viejo primero: con guía impresa, sin empacar ni cancelar.
     *
     * @param  list<int>  $enTrabajo  los cortes que ya se muestran arriba
     * @return Collection<int, array{etiqueta:string, total:int, productos:string, pedidos:Collection}>
     */
    private function anteriores(array $enTrabajo, array $empacando, callable $coincide): Collection
    {
        return DropiPedido::query()
            ->whereNotNull('corte_id')->whereNotIn('corte_id', $enTrabajo ?: [0])
            ->whereHas('corte', fn ($q) => $q->whereDate('fecha', '>=', now()->subDays(self::DIAS_ANTERIORES)->toDateString()))
            ->whereNotNull('lote_impresion_id')
            ->whereIn('estado', DropiPedido::estadosDespachables())->guiaImpresa()
            ->with(['corte:id,numero,fecha', 'items.variante.producto', 'items.producto', 'items.vinculo'])
            ->orderByRaw('orden_empaque IS NULL')->orderBy('orden_empaque')->orderBy('id')->get()
            ->groupBy('corte_id')
            ->sortBy(fn (Collection $ps) => $ps->first()->corte->fecha->format('Y-m-d') . sprintf('%05d', $ps->first()->corte->numero))
            ->map(function (Collection $ps) use ($empacando, $coincide) {
                // «5× Zapato niña (T25), 2× Tapete…»: lo que hay que ir a buscar.
                $unidades = $ps->flatMap->items
                    ->groupBy(fn ($it) => (($it->variante?->producto ?? $it->producto)?->nombre ?? $it->sku_dropi)
                        . (($v = $it->nombreVariante()) !== '' ? " ({$v})" : ''))
                    ->map(fn ($items) => $items->sum(fn ($it) => $it->unidadesErp()))
                    ->sortDesc();
                $productos = $unidades->take(self::PRODUCTOS_RESUMEN)->map(fn ($n, $nombre) => "{$n}× {$nombre}")->implode(', ')
                    . ($unidades->count() > self::PRODUCTOS_RESUMEN ? ' y ' . ($unidades->count() - self::PRODUCTOS_RESUMEN) . ' más' : '');

                return [
                    'etiqueta' => $ps->first()->corte->etiqueta(),
                    'total' => $ps->count(),
                    'productos' => $productos,
                    'pedidos' => $ps->map(fn (DropiPedido $p) => [
                        'id' => $p->id,
                        'guia' => (string) $p->guia,
                        'orden' => (string) $p->dropi_orden_id,
                        'cliente' => (string) $p->cliente_nombre,
                        'productos' => RecoleccionDelCorte::resumenProductos($p),
                        'empacando' => $empacando[$p->id] ?? null,
                    ])->filter($coincide)->values(),
                ];
            })->values();
    }

    /**
     * Dónde está un pedido, en cualquier corte: empacado (cuándo y quién), por
     * empacar (en qué puesto de la fila), empacándose (quién) o en qué estado.
     *
     * @return Collection<int, array{guia:string, orden:string, cliente:string, estado:string, detalle:string, tono:string}>
     */
    private function buscarPedidos(string $q, Collection $cortes): Collection
    {
        $pedidos = DropiPedido::query()
            ->where(fn ($w) => $w->where('guia', 'like', "%{$q}%")
                ->orWhere('dropi_orden_id', 'like', "%{$q}%")
                ->orWhere('cliente_nombre', 'like', "%{$q}%"))
            ->with('corte:id,numero,fecha')
            ->latest('id')->limit(self::RESULTADOS)->get();

        $puestos = $cortes->flatMap(fn ($c) => $c['faltan']->mapWithKeys(fn ($f) => [$f['guia'] => $f['puesto']]));
        $empacando = RecoleccionDelCorte::empacandoAhora();
        $registros = EmpaqueRegistro::query()->whereIn('pedido_id', $pedidos->pluck('id'))->where('estado', 'completado')
            ->with('operario:id,name')->orderByDesc('fin_at')->get()->unique('pedido_id')->keyBy('pedido_id');

        return $pedidos->map(function (DropiPedido $p) use ($puestos, $empacando, $registros) {
            $r = $registros->get($p->id);
            [$estado, $detalle, $tono] = match (true) {
                isset($empacando[$p->id]) => ['📦 Empacando', $empacando[$p->id], 'trabajo'],
                in_array($p->estado, [EstadoPedidoDropi::Empacado, EstadoPedidoDropi::Despachado, EstadoPedidoDropi::Entregado, EstadoPedidoDropi::Pagado], true)
                    => ['✔ ' . $p->estado->label(), trim(($r?->fin_at?->timezone('America/Bogota')->format('d/m g:i a') ?? '') . ($r?->operario ? ' · ' . $r->operario->name : '')), 'listo'],
                in_array($p->estado, DropiPedido::estadosDespachables(), true) && ! $p->tieneGuiaImpresa()
                    => ['⏳ Sin imprimir', 'todavía no está en un reporte', 'atencion'],
                in_array($p->estado, DropiPedido::estadosDespachables(), true)
                    => ['⏳ Por empacar', isset($puestos[$p->guia]) ? '#' . $puestos[$p->guia] . ' en la fila' : 'fuera de los cortes en trabajo', 'pendiente'],
                default => ['• ' . ($p->estado?->label() ?? '—'), '', 'neutro'],
            };

            return [
                'guia' => (string) $p->guia,
                'orden' => (string) $p->dropi_orden_id,
                'cliente' => (string) $p->cliente_nombre,
                'estado' => $estado,
                'detalle' => implode(' · ', array_filter([$detalle, $p->corte?->etiqueta()])),
                'tono' => $tono,
            ];
        });
    }
}
