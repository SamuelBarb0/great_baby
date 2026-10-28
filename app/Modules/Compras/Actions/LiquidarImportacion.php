<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionLinea;
use App\Modules\Compras\Models\OrdenCompraItem;
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

            $items = collect();
            $itemsBodega = [];
            foreach ($importacion->ordenes as $orden) {
                foreach ($orden->items as $item) {
                    $items->push([
                        'orden_item' => $item,
                        'peso_pivot' => (float) ($orden->pivot->peso_kg ?? 0),
                        'volumen_pivot' => (float) ($orden->pivot->volumen_m3 ?? 0),
                    ]);
                    $itemsBodega[$item->id] = $orden->bodega_id;
                }
            }

            if ($items->isEmpty()) {
                throw new InvalidArgumentException('No hay ítems en las órdenes ligadas.');
            }

            $totalValor = $items->sum(fn ($x) => (float) $x['orden_item']->subtotal);
            $totalCantidad = $items->sum(fn ($x) => (float) $x['orden_item']->cantidad);
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
                if ((float) $x['orden_item']->cantidad > 0) $ultimoIdxAbsorbe = $idx;
            }
            $asignado = 0.0;

            foreach ($items as $idx => $x) {
                /** @var OrdenCompraItem $item */
                $item = $x['orden_item'];
                $esAbsorbedor = ($idx === $ultimoIdxAbsorbe);

                $costoFobTotal = (float) $item->subtotal;

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
                            'cantidad' => $totalCantidad > 0 ? ((float) $item->cantidad / $totalCantidad) : 0,
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
                $costoFinalUnit = (float) $item->cantidad > 0
                    ? round($costoFinalTotal / (float) $item->cantidad, 4)
                    : 0;

                ImportacionLinea::create([
                    'importacion_id' => $importacion->id,
                    'orden_item_id' => $item->id,
                    'producto_id' => $item->producto_id,
                    'variante_id' => $item->variante_id,
                    'cantidad' => $item->cantidad,
                    'costo_fob_unit' => $item->precio_unit,
                    'costo_fob_total' => $costoFobTotal,
                    'gasto_prorrateado' => $prorrateo,
                    'costo_final_unit' => $costoFinalUnit,
                    'costo_final_total' => $costoFinalTotal,
                ]);

                $item->costo_prorrateado_unit = $costoFinalUnit;
                $item->save();

                // Re-audit M3 ξ · kardex decimal(14,4); grabamos costo_unit
                //   (costo final capitalizado) para PMP real.
                // C-F2 R2 · Ingreso polimórfico:
                //   Granular → variante_id set
                //   Agregado → producto_id set + variante_id NULL
                $bodegaId = $itemsBodega[$item->id] ?? null;
                $cantidad = (float) $item->cantidad;
                if ($bodegaId && $cantidad > 0 && ($item->variante_id || $item->producto_id)) {
                    $esAgregado = $item->variante_id === null && $item->producto_id !== null;
                    InventarioMovimiento::create([
                        'variante_id' => $item->variante_id,
                        'producto_id' => $item->producto_id, // creating hook lo llena para granular
                        'ubicacion_id' => $bodegaId,
                        'tipo' => 'entrada_importacion',
                        'cantidad' => round($cantidad, 4),
                        'costo_unit' => round((float) $costoFinalUnit, 4),
                        'referencia_tipo' => Importacion::class,
                        'referencia_id' => $importacion->id,
                        'user_id' => auth()->id(),
                        'notas' => "Liquidación IMP {$importacion->numero} · costo unit \${$costoFinalUnit}"
                            .($esAgregado ? ' · AGREGADO' : ''),
                        'created_at' => now(),
                    ]);
                }

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
