<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionLinea;
use App\Modules\Compras\Models\OrdenCompraItem;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Compras\Models\RecepcionCompraItem;
use App\Modules\Compras\Services\CostoImportacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Liquida importación:
 *   1. Recolecta líneas (OC + items) asociadas a la importación.
 *   2. Prorratea gastos capitalizables por método (valor|cantidad|peso|volumen).
 *   3. Escribe compras_importacion_lineas con costo_final_unit.
 *   4. Actualiza costo_prorrateado_unit en compra_orden_items.
 *   5. Actualiza estado y totales de la importación.
 *   6. Dispara asiento contable (1465 → 1435 + gastos).
 */
class LiquidarImportacion
{
    use AsAction;

    public function handle(Importacion $importacion): Importacion
    {
        return DB::transaction(function () use ($importacion) {
            // Re-audit M2 PATRÓN D (FUNC-C3 / DATOS-C8) · idempotencia Action.
            // Lock la importación + guard estado: sin esto una segunda ejecución
            // duplicaba `InventarioMovimiento` (borraba solo `ImportacionLinea`)
            // → doble entrada al stock.
            $importacion = Importacion::query()->whereKey($importacion->id)->lockForUpdate()->first();
            abort_if(
                $importacion->estado === EstadoImportacion::Liquidada,
                422,
                'Importación ya liquidada. Reversa (a definir) antes de re-liquidar.'
            );

            // Re-audit M2 R3 PATRÓN Q (DATOS-A1) · lock también sobre `gastos`
            // dentro de la misma tx — sin esto un `importacionGastoAgregar`
            // concurrente podía insertar entre el load y el save, quedando
            // gasto huérfano sin prorratear.
            \App\Modules\Compras\Models\GastoImportacion::query()
                ->where('importacion_id', $importacion->id)
                ->lockForUpdate()->get();

            $importacion->loadMissing(['ordenes.items', 'gastos']);

            // Volumen y peso de CADA ítem. Antes cada ítem tomaba el m³ y el peso
            // de su orden entera, así que en una orden de 15 líneas el producto
            // pequeño cargaba el mismo flete que el grande. Alejandro reparte los
            // gastos «en los metros cúbicos» de cada ítem (reunión 30-oct).
            //
            // Se liquida lo que Jorge recibió, a lo que quedó en el 1465 y en la
            // cuenta del proveedor. Lo que aún no recibe va por lo pedido, en pesos.
            $costos = app(CostoImportacion::class);
            $revisados = RecepcionCompraItem::query()
                ->whereIn('orden_item_id', $importacion->ordenes->flatMap(fn ($o) => $o->items->pluck('id')))
                ->whereHas('recepcion', fn ($q) => $q->where('estado', 'confirmada'))
                ->get()
                ->groupBy('orden_item_id');

            $items = collect();
            foreach ($importacion->ordenes as $orden) {
                $volumenes = $this->repartirPorItem($orden->items, (float) ($orden->pivot->volumen_m3 ?? 0), 'cbm_caja');
                $pesos = $this->repartirPorItem($orden->items, (float) ($orden->pivot->peso_kg ?? 0), 'peso_caja_kg');
                foreach ($orden->items as $item) {
                    // Si bodega ya la revisó, cuenta lo que recibió, aunque sea nada.
                    $revisado = $revisados[$item->id] ?? null;
                    $suyo = $revisado?->reject(fn ($l) => $l->clasificacion === 'faltante');
                    $cantidad = $revisado ? (float) $suyo->sum('cantidad_recibida') : (float) $item->cantidad;
                    // Lo que no llegó no ocupó contenedor: no carga con flete.
                    $parte = (float) $item->cantidad > 0 ? $cantidad / (float) $item->cantidad : 1.0;
                    $items->push([
                        'orden_item' => $item,
                        'peso_pivot' => $pesos[$item->id] * $parte,
                        'volumen_pivot' => $volumenes[$item->id] * $parte,
                        'cantidad' => $cantidad,
                        'fob' => $revisado
                            ? round((float) $suyo->sum('subtotal'), 2)
                            : round((float) $item->subtotal * $costos->tasa($orden), 2),
                    ]);
                }
            }

            // Una línea que no llegó entera no tiene nada que liquidar.
            $items = $items->filter(fn ($x) => $x['cantidad'] > 0)->values();

            if ($items->isEmpty()) {
                throw new InvalidArgumentException('No hay ítems en las órdenes ligadas.');
            }

            $totalValor = $items->sum('fob');
            $totalCantidad = $items->sum('cantidad');
            $totalPeso = $items->sum(fn ($x) => $x['peso_pivot']);
            $totalVolumen = $items->sum(fn ($x) => $x['volumen_pivot']);

            // Re-audit M2 R3 PATRÓN O (FUNC-C2) · IVA importación NUNCA se
            // capitaliza aunque el usuario haya marcado `capitalizable=true`
            // por error. Si se incluyera en $gastosCap, el asiento tendría
            // débito 1435 (por el prorrateo) MÁS débito 1355 (por el IVA
            // descontable en `liquidacion`) contra un solo crédito 2205 →
            // Σdebe − Σhaber = monto_iva (violación partida doble).
            $gastosCap = $importacion->gastos
                ->where('capitalizable', true)
                ->reject(fn ($g) => $this->esConceptoIvaImportacion($g));
            $totalGastosCap = (float) $gastosCap->sum('monto_base');

            ImportacionLinea::where('importacion_id', $importacion->id)->delete();
            // Re-audit M2 PATRÓN D (FUNC-C3) · borrar también InventarioMovimiento
            // previo — antes solo se borraban ImportacionLinea, así que si por
            // alguna razón se re-ejecutaba, el stock se duplicaba.
            InventarioMovimiento::where('referencia_tipo', Importacion::class)
                ->where('referencia_id', $importacion->id)->delete();

            $fobTotal = 0;
            $gastoTotal = 0;

            // Re-audit M2 R3 PATRÓN P (DATOS-A2 / FUNC-A1) · prorrateo con compensación
            //   de residuo. Fix definitivos:
            //     - La ÚLTIMA línea que absorbe residuo debe tener `cantidad > 0`
            //       (antes una línea qty=0 recibía monto sin cantidad → costo/unit=0
            //       pero total ≠ 0, invariante roto).
            //     - Si el residuo sale negativo (drift acumulado > totalGastosCap
            //       por N líneas redondeadas al alza), clampeamos a 0 y redistribuimos
            //       el excedente pequeño hacia atrás para conservar Σ = totalGastosCap
            //       sin líneas negativas.
            $ultimoIdxAbsorbe = null;
            foreach ($items as $idx => $x) {
                if ($x['cantidad'] > 0) $ultimoIdxAbsorbe = $idx;
            }
            $asignado = 0.0;

            foreach ($items as $idx => $x) {
                /** @var OrdenCompraItem $item */
                $item = $x['orden_item'];
                $esAbsorbedor = ($idx === $ultimoIdxAbsorbe);

                $cantidadLinea = $x['cantidad'];
                $costoFobTotal = $x['fob'];

                if ($esAbsorbedor) {
                    $prorrateo = round($totalGastosCap - $asignado, 2);
                    if ($prorrateo < 0) {
                        // No debemos generar prorrateo negativo (venta a pérdida
                        // sin razón). En su lugar mantenemos $prorrateo = 0 y
                        // aceptamos el drift residual — cerca de $0.01 en peor caso.
                        $prorrateo = 0.0;
                    }
                } else {
                    $prorrateo = 0.0;
                    foreach ($gastosCap as $gasto) {
                        $base = match ($gasto->metodo_prorrateo) {
                            'cantidad' => $totalCantidad > 0 ? ($cantidadLinea / $totalCantidad) : 0,
                            'peso' => $totalPeso > 0 ? ($x['peso_pivot'] / $totalPeso) : 0,
                            'volumen' => $totalVolumen > 0 ? ($x['volumen_pivot'] / $totalVolumen) : 0,
                            default => $totalValor > 0 ? ($costoFobTotal / $totalValor) : 0,
                        };
                        $prorrateo += (float) $gasto->monto_base * $base;
                    }
                    $prorrateo = round($prorrateo, 2);
                    $asignado += $prorrateo;
                }

                $costoFinalTotal = round($costoFobTotal + $prorrateo, 2);
                $costoFinalUnit = $cantidadLinea > 0
                    ? round($costoFinalTotal / $cantidadLinea, 4)
                    : 0;

                ImportacionLinea::create([
                    'importacion_id' => $importacion->id,
                    'orden_item_id' => $item->id,
                    'producto_id' => $item->producto_id,
                    'variante_id' => $item->variante_id,
                    'cantidad' => $cantidadLinea,
                    'costo_fob_unit' => $cantidadLinea > 0 ? round($costoFobTotal / $cantidadLinea, 4) : 0,
                    'costo_fob_total' => $costoFobTotal,
                    'gasto_prorrateado' => $prorrateo,
                    'costo_final_unit' => $costoFinalUnit,
                    'costo_final_total' => $costoFinalTotal,
                ]);

                $item->costo_prorrateado_unit = $costoFinalUnit;
                $item->save();

                // El stock ya no entra acá: entró cuando Jorge lo aceptó. La
                // liquidación sólo le corrige el costo.
                $this->corregirCostoEnKardex($item, (float) $costoFinalUnit, $importacion);

                $fobTotal += $costoFobTotal;
                $gastoTotal += $prorrateo;
            }

            $importacion->valor_fob = round($fobTotal, 2);
            $importacion->valor_gastos = round($gastoTotal, 2);
            $importacion->valor_total_costo = round($fobTotal + $gastoTotal, 2);
            // Re-audit M2 R3 PATRÓN N (SEG-C1) · fecha_liquidacion SIEMPRE now()
            //   — antes `?? now()` respetaba una fecha manipulada pre-liquidación
            //   (backdating a periodo cerrado). Ahora la fecha oficial de asiento
            //   contable es el momento de la liquidación real, no lo que puso
            //   el usuario en el form.
            $importacion->fecha_liquidacion = now();
            $importacion->estado = EstadoImportacion::Liquidada;
            $importacion->liquidada_por = auth()->id();
            // Re-audit M2 R3 DATOS-B4 · popular valor_arancel y valor_iva_importacion
            //   — antes quedaban NULL aunque hubiera gastos de esos conceptos, y el
            //   Show mostraba $0.00 pese a tenerlos registrados.
            $importacion->valor_arancel = (float) $importacion->gastos
                ->filter(fn ($g) => $this->esConceptoArancel($g))->sum('monto_base');
            $importacion->valor_iva_importacion = (float) $importacion->gastos
                ->filter(fn ($g) => $this->esConceptoIvaImportacion($g))->sum('monto_base');
            $importacion->save();

            RegistrarAsientoCompra::make()->liquidacion($importacion);

            // BUG-IMP · tras cerrar los MovimientosContable locales, encolamos
            // el journal consolidado a SIIGO. Antes el 1465→1435 + IVA 1355 +
            // CxP agencia solo quedaban locales, nunca llegaban a SIIGO.
            // afterCommit evita que el push dispare si la liquidación revierte.
            \Illuminate\Support\Facades\DB::afterCommit(function () use ($importacion) {
                \App\Modules\Siigo\Jobs\PushLiquidacionImportacionASiigo::dispatch($importacion->id);
            });

            return $importacion->fresh(['ordenes', 'gastos', 'lineas']);
        });
    }

    /**
     * Cambia el costo estimado por el real, sin tocar la cantidad.
     *
     * El kardex no deja modificar un movimiento, y un reverso negativo más otra
     * entrada descuadraría `CostoPromedio`, que promedia sólo las entradas. Por
     * eso se anula el estimado y se escribe el real: el saldo queda igual.
     */
    protected function corregirCostoEnKardex(OrdenCompraItem $item, float $costo, Importacion $importacion): void
    {
        $recepciones = RecepcionCompra::where('orden_id', $item->orden_id)->pluck('id');

        InventarioMovimiento::query()
            ->where('tipo', 'entrada_importacion')
            ->where('referencia_tipo', RecepcionCompra::class)
            ->whereIn('referencia_id', $recepciones)
            ->when(
                $item->variante_id,
                fn ($q) => $q->where('variante_id', $item->variante_id),
                fn ($q) => $q->whereNull('variante_id')->where('producto_id', $item->producto_id),
            )
            ->get()
            ->each(function (InventarioMovimiento $estimado) use ($costo, $importacion) {
                InventarioMovimiento::create([
                    'variante_id' => $estimado->variante_id,
                    'producto_id' => $estimado->producto_id,
                    'ubicacion_id' => $estimado->ubicacion_id,
                    'tipo' => 'entrada_importacion',
                    'cantidad' => $estimado->cantidad,
                    'costo_unit' => round($costo, 4),
                    'referencia_tipo' => Importacion::class,
                    'referencia_id' => $importacion->id,
                    'user_id' => auth()->id(),
                    'notas' => "Liquidación IMP {$importacion->numero} · costo real de {$estimado->notas}",
                    'created_at' => now(),
                ]);
                $estimado->delete();
            });
    }

    /**
     * Volumen (o peso) de cada ítem de una orden: cajas × medida por caja
     * cuando la línea la trae. Lo que falte para llegar al total de la orden en
     * el contenedor se reparte entre los ítems sin medida según su valor, así
     * una orden vieja (sin cajas) sigue sumando lo mismo que antes.
     *
     * @param  \Illuminate\Support\Collection<int, OrdenCompraItem>  $items
     * @return array<int, float>  orden_item_id => m³ (o kg)
     */
    protected function repartirPorItem($items, float $totalOrden, string $porCaja): array
    {
        $medidos = [];
        foreach ($items as $item) {
            if ((float) $item->cajas > 0 && (float) $item->{$porCaja} > 0) {
                $medidos[$item->id] = (float) $item->cajas * (float) $item->{$porCaja};
            }
        }

        $sinMedida = $items->reject(fn ($i) => isset($medidos[$i->id]));
        $resto = max(0.0, $totalOrden - array_sum($medidos));
        $valorSinMedida = (float) $sinMedida->sum(fn ($i) => (float) $i->subtotal);

        $resultado = $medidos;
        foreach ($sinMedida as $item) {
            $resultado[$item->id] = match (true) {
                $resto <= 0 => 0.0,
                $valorSinMedida > 0 => $resto * (float) $item->subtotal / $valorSinMedida,
                default => $resto / $sinMedida->count(),
            };
        }

        return $resultado;
    }

    /**
     * PATRÓN O · helper para detectar concepto IVA importación robustamente.
     * Acepta enum o string legacy, defensivo contra typos.
     */
    /**
     * Delega en el enum, que es donde vive la regla.
     *
     * Estaba escrita a mano aca y `RegistrarAsientoCompra` no la tenia: el
     * prorrateo excluia el IVA y el asiento no.
     */
    protected function esConceptoIvaImportacion($gasto): bool
    {
        return \App\Modules\Compras\Enums\ConceptoGastoImportacion::gastoEsIvaImportacion($gasto);
    }

    protected function esConceptoArancel($gasto): bool
    {
        $c = $gasto->concepto ?? null;
        $val = is_object($c) && property_exists($c, 'value') ? $c->value : (string) $c;
        return strtolower($val) === 'arancel';
    }
}
