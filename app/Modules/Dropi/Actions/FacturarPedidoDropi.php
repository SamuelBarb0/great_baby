<?php

namespace App\Modules\Dropi\Actions;

use App\Models\Contacto;
use App\Modules\Cartera\Actions\RegistrarAsientoContable;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\FacturaVentaItem;
use App\Modules\Cartera\Support\NumeroFactura;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiRemision;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Tarjeta Dropi · 9. Facturación Dropi → SIIGO.
 *
 * Decidido con Samuel el 2026-10-20: cada pedido Dropi se factura UNO A UNO,
 * AL DESPACHAR, a CONSUMIDOR FINAL, por el valor de proveedor (lo que Dropi le
 * paga a Great Baby, no lo que paga el cliente final).
 *
 *   9.1 · remisión 1:1 por valor de proveedor. Antes sólo nacía al cerrar el
 *         corte (11 de 148 cortes cerrados → 16 remisiones para ~4 900 pedidos).
 *   9.3 · factura electrónica: nace `es_electronica` si SIIGO está activo y va
 *         por ReintentarEmisionDian, el mismo camino de la factura B2B.
 *
 * IVA: Dropi no manda impuestos (`iva_total_product` llega null). El precio de
 * proveedor es lo que entra a la wallet, así que se toma con IVA INCLUIDO y se
 * separa con la tarifa del producto en el catálogo: el total de la factura es
 * exactamente lo que paga Dropi y cuadra con el pago 1006.
 *
 * Detrás de la regla `dropi.facturacion_automatica` (apagada) y del corte
 * `dropi.facturar_desde`: facturar ante la DIAN no se deshace, así que se
 * prende a propósito y no factura la historia.
 */
class FacturarPedidoDropi
{
    use AsAction;

    public const DOC_CONSUMIDOR_FINAL = '222222222222';

    /** Por qué no se facturó, para la bitácora y el log. */
    public ?string $motivo = null;

    public function handle(DropiPedido $pedido, ?int $userId = null): ?FacturaVenta
    {
        $this->motivo = null;

        if (! (bool) setting('dropi.facturacion_automatica', false)) {
            $this->motivo = 'facturación automática apagada';

            return null;
        }
        $desde = trim((string) setting('dropi.facturar_desde', ''));
        if ($desde !== '' && now()->toDateString() < $desde) {
            $this->motivo = "facturación desde {$desde}";

            return null;
        }

        return DB::transaction(function () use ($pedido, $userId) {
            $p = DropiPedido::with(['items.variante.producto.impuesto', 'items.producto.impuesto', 'remision'])
                ->lockForUpdate()->findOrFail($pedido->id);

            if ($p->factura_venta_id) {
                $this->motivo = 'ya facturado';

                return FacturaVenta::find($p->factura_venta_id);
            }

            // 9.1 · remisión 1:1 (si el cierre del corte no la creó ya).
            if (! $p->remision) {
                DropiRemision::create([
                    'pedido_id' => $p->id,
                    'consecutivo' => GenerarRemisionesCorte::siguienteConsecutivo(),
                    'valor_proveedor' => $p->monto_esperado_proveedor,
                ]);
            }

            $lineas = [];
            $sinCatalogo = [];
            foreach ($p->items as $it) {
                $cant = (int) ($it->cantidad ?? 0);
                if ($cant <= 0) continue;
                if (! $it->variante_id && ! $it->producto_id) {
                    $sinCatalogo[] = $it->sku_dropi ?? "ítem #{$it->id}";
                    continue;
                }
                $producto = $it->variante?->producto ?? $it->producto;
                $ivaPct = (float) ($producto?->impuesto?->porcentaje ?? 0);
                $conIva = (float) $it->precio_proveedor_unit;
                $base = round($conIva / (1 + $ivaPct / 100), 2);
                $lineas[] = [
                    'item' => $it, 'cantidad' => $cant, 'base' => $base, 'iva_pct' => $ivaPct,
                    'total' => round($conIva * $cant, 2),
                    'descripcion' => trim(($producto?->nombre ?? $it->sku_dropi ?? 'Producto').' · guía '.$p->guia),
                ];
            }

            if (! $lineas) {
                // Sin productos del catálogo no hay factura posible (la DIAN
                // pide el producto). Queda la remisión y el motivo.
                $this->motivo = 'ningún ítem vinculado al catálogo: '.implode(', ', $sinCatalogo);

                return null;
            }

            $total = round(array_sum(array_column($lineas, 'total')), 2);
            $subtotal = round(array_sum(array_map(fn ($l) => $l['base'] * $l['cantidad'], $lineas)), 2);
            $siigoActivo = (bool) (\App\Modules\Siigo\Models\SiigoConfig::current()->activo ?? false);

            $factura = FacturaVenta::create([
                'numero' => NumeroFactura::siguiente(),
                'contacto_id' => self::consumidorFinal()->id,
                'fecha_emision' => now()->toDateString(),
                'fecha_vencimiento' => now()->toDateString(),
                'estado' => 'pendiente',
                'tipo' => 'contado',
                'subtotal' => $subtotal,
                'descuento' => 0,
                'impuestos' => round($total - $subtotal, 2),
                'total' => $total,
                'saldo' => $total,
                'es_electronica' => $siigoActivo,
                'origen_type' => DropiPedido::class,
                'origen_id' => $p->id,
                'observaciones' => "Dropi guía {$p->guia} · orden {$p->dropi_orden_id} · remisión "
                    .($p->remision?->consecutivo ?? DropiRemision::where('pedido_id', $p->id)->value('consecutivo'))
                    .($sinCatalogo ? ' · SIN PRODUCTO VINCULADO (no facturado): '.implode(', ', $sinCatalogo) : ''),
                'facturado_por_user_id' => $userId,
            ]);

            foreach ($lineas as $l) {
                FacturaVentaItem::create([
                    'factura_id' => $factura->id,
                    'variante_id' => $l['item']->variante_id,
                    'producto_id' => $l['item']->producto_id,
                    'descripcion' => mb_substr($l['descripcion'], 0, 250),
                    'cantidad' => $l['cantidad'],
                    'precio_unit' => $l['base'],
                    'descuento_pct' => 0,
                    'impuesto_pct' => $l['iva_pct'],
                    'subtotal' => round($l['base'] * $l['cantidad'], 2),
                ]);
            }

            // Igual que la B2B: el observer `saved` corre con 0 ítems; el
            // asiento (1305 contra ingreso e IVA) se pide explícito.
            (new RegistrarAsientoContable())->factura($factura->refresh());

            DB::table('dropi_pedidos')->where('id', $p->id)->update(['factura_venta_id' => $factura->id]);

            if ($factura->es_electronica) {
                $id = $factura->id;
                DB::afterCommit(fn () => \App\Jobs\ReintentarEmisionDian::dispatch($id)->onQueue('siigo'));
            }

            return $factura;
        });
    }

    /** El tercero genérico de la DIAN para ventas a personas sin identificar. */
    public static function consumidorFinal(): Contacto
    {
        return Contacto::firstOrCreate(
            ['numero_documento' => self::DOC_CONSUMIDOR_FINAL],
            [
                'tipo_documento' => 'CC',
                'nombre_completo' => 'CONSUMIDOR FINAL',
                'es_cliente' => true,
                'activo' => true,
            ],
        );
    }
}
