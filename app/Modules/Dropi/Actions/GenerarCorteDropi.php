<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Enums\EstadoCorte;
use App\Modules\Dropi\Jobs\GenerarGuiasEnBloqueJob;
use App\Modules\Dropi\Listeners\RecalcularContadoresCorte;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiLoteImpresion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Services\ImprimirGuiasDropi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * «Generar corte» de «Pedidos Dropi» (25-nov-2026): Aracely filtra, revisa a
 * mano qué está pendiente y qué ya tiene guía sin imprimir, selecciona y le da
 * un solo botón. Eso ES el corte:
 *
 *  1. La hora de ese momento dice cuál: antes de las 2 p. m. el Corte 1 del día,
 *     después el 2; si el de la franja ya se cerró, el siguiente
 *     (AsignarCorteACarga). Otra vez en la misma franja suma al mismo corte.
 *  2. Los seleccionados entran a ese corte.
 *  3. A los PENDIENTE en Dropi sin guía se les pide la guía; lo que ya venía con
 *     guía de Dropi sólo entra.
 *  4. Cuando terminan las guías sale el reporte del corte: la hoja de
 *     alistamiento y los stickers, con lo que trabajan la Vista del Alistador y
 *     la Estación de Empaque.
 */
class GenerarCorteDropi
{
    use AsAction;

    /**
     * @param  list<int>  $pedidoIds
     * @return array{corte: ?DropiCorte, reporte: ?DropiLoteImpresion, a_guia: int, con_guia: int, sin_stock: int, saltados: int}
     */
    public function handle(array $pedidoIds, ?int $userId, ?CarbonImmutable $ahora = null): array
    {
        $grupos = self::clasificar(DropiPedido::query()->whereIn('id', $pedidoIds)->with(['huella', 'corte'])->get());
        $entran = $grupos['a_guia']->concat($grupos['con_guia']);
        $vacio = ['corte' => null, 'reporte' => null, 'a_guia' => 0, 'con_guia' => 0, 'sin_stock' => 0, 'saltados' => $grupos['saltados']->count()];
        if ($entran->isEmpty()) {
            return $vacio;
        }

        $corte = AsignarCorteACarga::run($ahora ?? CarbonImmutable::now());

        $reporte = DB::transaction(function () use ($entran, $corte, $grupos, $userId) {
            $antes = $entran->pluck('corte_id')->filter()->unique()->all();
            DropiPedido::whereIn('id', $entran->pluck('id'))->update(['corte_id' => $corte->id]);
            foreach ([...$antes, $corte->id] as $id) {
                RecalcularContadoresCorte::recontar((int) $id);
            }

            return DropiLoteImpresion::create([
                'corte_id' => $corte->id,
                'user_id' => $userId,
                'pedidos' => $entran->pluck('id')->all(),
                'total' => $entran->count(),
                'guias_pendientes' => $grupos['a_guia']->count(),
                'estado' => $grupos['a_guia']->isEmpty() ? DropiLoteImpresion::DESCARGANDO : DropiLoteImpresion::GENERANDO_GUIAS,
            ]);
        });

        if ($grupos['a_guia']->isEmpty()) {
            app(ImprimirGuiasDropi::class)->iniciar($reporte);
        } else {
            GenerarGuiasEnBloqueJob::lanzar($grupos['a_guia']->pluck('id')->all(), $userId, $reporte->id);
        }

        return [
            'corte' => $corte->fresh(),
            'reporte' => $reporte->fresh(),
            'a_guia' => $grupos['a_guia']->count(),
            'con_guia' => $grupos['con_guia']->count(),
            'sin_stock' => $entran->filter(fn (DropiPedido $p) => $p->sinStockErp())->count(),
            'saltados' => $grupos['saltados']->count(),
        ];
    }

    /**
     * Qué pasa con cada escogido: le falta la guía, ya la tiene (de Dropi) o se
     * salta (ya está en un reporte, su corte está cerrado, ya no se despacha,
     * o sin stock con la regla «Exigir stock del ERP» prendida).
     *
     * @param  Collection<int, DropiPedido>  $pedidos  con huella y corte
     * @return array{a_guia: Collection<int, DropiPedido>, con_guia: Collection<int, DropiPedido>, saltados: Collection<int, DropiPedido>}
     */
    public static function clasificar(Collection $pedidos): array
    {
        $a = collect();
        $con = collect();
        $salta = collect();
        foreach ($pedidos as $p) {
            $cerrado = $p->corte && EstadoCorte::de($p->corte->estado) === EstadoCorte::Cerrado;
            if ($cerrado || $p->lote_impresion_id || ! $p->listoParaDespachar() || ! $p->dropi_orden_id) {
                $salta->push($p);
            } elseif ($p->tieneGuiaReal()) {
                $con->push($p);
            } elseif ($p->huella?->estatus_dropi === 'PENDIENTE') {
                $a->push($p);
            } else {
                $salta->push($p);
            }
        }

        return ['a_guia' => $a, 'con_guia' => $con, 'saltados' => $salta];
    }
}
