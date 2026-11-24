<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\ColoresDeImportacion;
use App\Modules\Compras\Models\OrdenCompraItem;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Compras\Services\CostoImportacion;
use App\Modules\Compras\Services\SeguimientoContenedor;
use App\Modules\Compras\Services\TrasRecepcion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Siigo\Jobs\PushRecepcionASiigo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Confirma una recepción: mueve stock (+ movimiento inventario tipo 'entrada'),
 * actualiza cantidad_recibida en los items de OC, cambia estado OC a parcial/recibida
 * y genera asientos contables.
 *
 * Re-audit M2 PATRÓN C · TODO va dentro de una tx con `lockForUpdate` sobre la OC
 *   y sobre cada OrdenCompraItem afectado. Antes: dos POST concurrentes leían
 *   el mismo `cantidad_recibida`, sumaban su delta → stock inflado + asientos
 *   duplicados. Además la sobre-recepción NO se validaba (`Recepcion/Nueva.vue`
 *   solo tenía `:max` hint HTML).
 *
 * Re-audit M2 PATRÓN H · `InventarioMovimiento.cantidad` es INT; el trunc por
 *   `(int)round` perdía decimales cuando OC/Recepción manejan `decimal(12,3)`.
 *   Ahora abortamos si la cantidad recibida no es entera — hasta que exista
 *   migración `movimientos_inventario.cantidad → decimal`.
 */
class RecibirMercancia
{
    use AsAction;

    public function handle(RecepcionCompra $recepcion): RecepcionCompra
    {
        return DB::transaction(function () use ($recepcion) {
            // Lock la recepción para evitar doble-confirmación concurrente.
            $recepcion = RecepcionCompra::query()->whereKey($recepcion->id)->lockForUpdate()->first();
            if ($recepcion->estado === 'confirmada') {
                throw new InvalidArgumentException('La recepción ya fue confirmada.');
            }

            $recepcion->loadMissing('items.ordenItem', 'orden.items');

            if ($recepcion->items->isEmpty()) {
                throw new InvalidArgumentException('La recepción no tiene ítems.');
            }

            // Lock la OC completa antes de tocar sus items.
            OrdenCompra::query()->whereKey($recepcion->orden_id)->lockForUpdate()->first();

            $esImportacion = $recepcion->orden->esImportacion();
            // Sin producto, lo que Jorge acepta no llegaría al inventario.
            if ($esImportacion) {
                CrearProductosDeOrden::run($recepcion->orden);
                app(ColoresDeImportacion::class)->resolverEnRecepcion($recepcion);
            }
            $costos = app(CostoImportacion::class);
            $tras = app(TrasRecepcion::class);

            // La bodega contra la que se recibe. Se la pasamos a
            // `ubicacionNoVendible()` para que la ubicación de averías, si hay
            // que crearla, herede su sede y su responsable en vez de nacer
            // huérfana —y por tanto invisible para el jefe de bodega—.
            $bodegaDeLaRecepcion = $recepcion->bodega_id
                ? \App\Modules\Dropi\Models\InventarioUbicacion::find($recepcion->bodega_id)
                : null;

            foreach ($recepcion->items as $it) {
                $cantidadRecibida = (float) $it->cantidad_recibida;
                if ($cantidadRecibida <= 0) continue;

                // Lo que no llegó no se recibe: ni stock, ni deuda, y la orden lo
                // sigue teniendo pendiente. La línea queda como constancia.
                $clasificacion = $it->clasificacion ?? 'apto';
                if ($clasificacion === 'faltante') {
                    $it->costo_unit = 0;
                    $it->save();
                    continue;
                }

                // Lock el item para leer cantidad_recibida sin race y validar el tope.
                $ordenItem = OrdenCompraItem::query()->whereKey($it->orden_item_id)->lockForUpdate()->first();
                if (! $ordenItem) {
                    throw new InvalidArgumentException("Item OC {$it->orden_item_id} no existe.");
                }

                // Re-audit FUNC-C1 · guard sobre-recepción atómico.
                $pendiente = (float) $ordenItem->cantidad - (float) $ordenItem->cantidad_recibida;
                /*
                 * De China llega agrupado y en otras cantidades por color: en una
                 * importación el tope es el total de la referencia, y lo revisa
                 * `recepcionCrear`.
                 *
                 * En una compra nacional el tope por línea sigue en pie, con una
                 * excepción: que alguien haya escrito POR QUÉ llegó de más.
                 * `RegistrarRecepcion` exige ese motivo antes de dejar pasar un
                 * exceso, así que si está lleno es porque la bodega contó de más
                 * a conciencia y eso es un hecho, no un error de digitación.
                 *
                 * Sin el motivo se rechaza igual que siempre: un conteo que
                 * supera lo pedido sin explicación descuadra la deuda con el
                 * proveedor y nadie se entera hasta que llega la factura.
                 */
                $excesoAutorizado = filled($recepcion->motivo_exceso);

                if (! $esImportacion && ! $excesoAutorizado && $cantidadRecibida > $pendiente + 0.0001) {
                    throw new InvalidArgumentException(sprintf(
                        'Sobre-recepción bloqueada · item "%s": pedido %.3f, ya recibido %.3f, esta recepción %.3f (pendiente %.3f).',
                        $ordenItem->descripcion ?? '?', $ordenItem->cantidad, $ordenItem->cantidad_recibida,
                        $cantidadRecibida, $pendiente,
                    ));
                }

                $ordenItem->cantidad_recibida = (float) $ordenItem->cantidad_recibida + $cantidadRecibida;
                $ordenItem->save();

                if (! $it->producto_id && ! $it->variante_id) {
                    $it->producto_id = $ordenItem->producto_id;
                    $it->variante_id = $ordenItem->variante_id;
                    $it->save();
                }

                // La deuda con el proveedor y el 1465 van en pesos, no en su moneda.
                if ($esImportacion) {
                    $it->costo_unit = $costos->fobUnitario($ordenItem, $recepcion->orden);
                    $it->save();
                }

                // C-F2 R2 · Recepción polimórfica.
                //   Granular: item con variante_id → mov apunta a variante.
                //   Agregado: item con producto_id (sin variante) → mov apunta a producto.
                //   La importación entra cuando Jorge la acepta, con lo que él contó.
                //   Entra a costo estimado y `LiquidarImportacion` lo corrige.
                if ($it->variante_id || $it->producto_id) {
                    // Re-audit M3 ρ ι · kardex ya soporta decimal(14,4); no
                    //   abortamos por fraccionarios. Grabamos `costo_unit`
                    //   para que PMP real (patrón μ) pueda calcular sin
                    //   caer al precio_proveedor del maestro.
                    $costoUnit = $esImportacion
                        ? $costos->costoParaKardex($ordenItem, $recepcion->orden)
                        : (float) ($ordenItem->precio_unit ?? 0);
                    $esAgregado = $it->variante_id === null && $it->producto_id !== null;
                    InventarioMovimiento::create([
                        'variante_id' => $it->variante_id,
                        'producto_id' => $it->producto_id, // creating hook lo popula si vacío en granular
                        /*
                         * El destino lo elige quien descarga, línea por línea:
                         * «de estas 50, 30 a Great Baby y 20 a Dropi». Sin
                         * elegir nada va a la bodega de la orden, que es como
                         * entró todo hasta hoy.
                         *
                         * Lo que llegó mal entra al inventario pero no a la
                         * venta. Eso pesa más que el destino elegido… pero no
                         * borra DE QUIÉN es: la avería de Dropi va a la avería
                         * de Dropi y la de Great Baby a la suya. Antes todo
                         * caía en la primera ubicación de avería por id y las
                         * dos marcas quedaban revueltas en el mismo estante.
                         */
                        'ubicacion_id' => $clasificacion === 'apto'
                            ? ($it->ubicacion_id ?: $recepcion->bodega_id)
                            : $tras->ubicacionDeAveria(
                                $it->ubicacion_id ? InventarioUbicacion::find($it->ubicacion_id) : null,
                                $bodegaDeLaRecepcion,
                            )->id,
                        'tipo' => $esImportacion ? 'entrada_importacion' : 'entrada_compra',
                        'cantidad' => round($cantidadRecibida, 4),
                        'costo_unit' => $costoUnit,
                        'referencia_tipo' => RecepcionCompra::class,
                        'referencia_id' => $recepcion->id,
                        'user_id' => auth()->id(),
                        'notas' => "Rec {$recepcion->numero} OC {$recepcion->orden->numero}"
                            .($esAgregado ? ' · AGREGADO' : '')
                            .($clasificacion !== 'apto' ? " · {$clasificacion}: {$it->motivo_clasificacion}" : ''),
                        'created_at' => now(),
                    ]);
                }
            }

            $totalRecibido = $recepcion->items->sum(fn ($i) => (float) $i->subtotal);
            $recepcion->total_recibido = $totalRecibido;
            $recepcion->estado = 'confirmada';
            $recepcion->confirmada_at = now();
            $recepcion->save();

            $this->actualizarEstadoOrden($recepcion->orden);

            RegistrarAsientoCompra::make()->recepcion($recepcion);

            if ($esImportacion) {
                $tras->separarEcommerce($recepcion);
                app(SeguimientoContenedor::class)->llegoABodega($recepcion->orden);
            }

            // COMP-B7 · push automático a SIIGO como factura de compra al
            // confirmar · evita el estado "recepciones sin SIIGO" que requería
            // que Aracely entrara una por una a reenviar manual. El job
            // respeta el kill-switch global y se encola después del commit
            // para no disparar si la tx se revierte.
            DB::afterCommit(function () use ($recepcion) {
                PushRecepcionASiigo::dispatch($recepcion->id);
            });

            // Lo que entró apto puede destrabar pedidos de Dropi que esperaban ese
            //   stock (24-nov-2026): vuelven a Pending y aparecen en las colas. Va
            //   después del commit y no tumba la recepción si algo falla.
            $aptos = $recepcion->items->filter(fn ($i) => ($i->clasificacion ?? 'apto') === 'apto' && (float) $i->cantidad_recibida > 0);
            DB::afterCommit(function () use ($aptos, $recepcion) {
                try {
                    \App\Modules\Dropi\Actions\LiberarPedidosPorStock::run(
                        $aptos->pluck('variante_id')->filter()->all(),
                        $aptos->whereNull('variante_id')->pluck('producto_id')->filter()->all(),
                        auth()->id(),
                        ['recepcion' => $recepcion->numero],
                    );
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            return $recepcion->fresh(['items', 'orden']);
        });
    }

    protected function actualizarEstadoOrden(OrdenCompra $orden): void
    {
        $orden->load('items');
        $total = (float) $orden->items->sum(fn ($i) => (float) $i->cantidad);
        $recibido = (float) $orden->items->sum(fn ($i) => (float) $i->cantidad_recibida);

        if ($total <= 0) return;

        $nuevo = match (true) {
            $recibido <= 0 => EstadoOrdenCompra::Aprobada,
            $recibido >= $total - 0.0001 => EstadoOrdenCompra::Recibida,
            default => EstadoOrdenCompra::Parcial,
        };

        $orden->estado = $nuevo;
        $orden->save();
    }
}
