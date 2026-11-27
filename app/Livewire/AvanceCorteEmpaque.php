<?php

namespace App\Livewire;

use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Support\RecoleccionDelCorte;
use Livewire\Component;

/**
 * El avance del corte en la estación de empaque (27-nov-2026: «que en la
 * estación viéramos cómo se van bajando los pedidos a medida que vamos
 * pistoleando, y arriba los que faltan»). Un componente aparte que se refresca
 * solo cada 5 s: así el refresco no toca el campo donde escanea la pistola.
 */
class AvanceCorteEmpaque extends Component
{
    /** Cuántos empacados se listan; el resto se cuenta. */
    public const EMPACADOS_VISIBLES = 15;

    public function render()
    {
        $recoleccion = app(RecoleccionDelCorte::class);
        $empacando = RecoleccionDelCorte::empacandoAhora();

        $cortes = $recoleccion->cortesEnTrabajo()->map(function (DropiCorte $c) use ($recoleccion, $empacando) {
            $base = fn () => DropiPedido::query()->where('corte_id', $c->id)->whereNotNull('lote_impresion_id');
            $faltan = $base()->whereIn('estado', DropiPedido::estadosDespachables())->guiaImpresa()
                ->with(['items.variante.producto', 'items.producto'])
                ->orderByRaw('orden_empaque IS NULL')->orderBy('orden_empaque')->orderBy('id')->get()
                ->map(fn (DropiPedido $p) => [
                    'guia' => (string) $p->guia,
                    'cliente' => (string) $p->cliente_nombre,
                    'productos' => RecoleccionDelCorte::resumenProductos($p),
                    'empacando' => $empacando[$p->id] ?? null,
                ]);
            $empacados = $recoleccion->empacados($c->id);
            $total = $faltan->count() + $empacados->count();

            return [
                'etiqueta' => $c->etiqueta(),
                'faltan' => $faltan,
                'empacados' => $empacados,
                'total' => $total,
                'pct' => $total > 0 ? (int) round(100 * $empacados->count() / $total) : 0,
            ];
        });

        return view('livewire.avance-corte-empaque', ['cortes' => $cortes]);
    }
}
