<?php

namespace App\Modules\Cartera\Actions;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Models\DropiDevolucion;
use App\Modules\Dropi\Support\CuentasDropi;
use App\Modules\Dropi\Services\SalidaInventarioDropi;
use App\Modules\Inventario\Support\CostoPromedio;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * El asiento de una devolución de Dropi: SÓLO el inventario que vuelve.
 *
 *   DEBE  1435xx inventario de la categoría   = costo con que salió
 *   HABER 6135xx costo de ventas de la categoría
 *
 * ── Por qué ya no reversa la venta (19-nov-2026) ───────────────────────────
 *
 * Este asiento también escribía la reversa de la venta (4175 contra 1305) por
 * el valor de proveedor. Tres defectos de una:
 *
 *   1. La venta se reversaba DOS VECES. Al crearse la devolución corre este
 *      asiento y enseguida EmitirNotaCreditoDropi crea la nota crédito, cuyo
 *      observer asienta la NC (RegistrarAsientoNota): 4175 contra 1305 otra vez.
 *   2. Metía el IVA dentro de la devolución: 4175 por el valor con IVA y la
 *      2408 intacta. El ingreso quedaba más bajo de lo real y el IVA por pagar
 *      más alto.
 *   3. Abonaba la cartera aunque el pedido NUNCA se hubiera facturado (con la
 *      facturación automática apagada no hay factura): la 13050501 de Dropi
 *      quedaba en negativo, y a nombre del vendedor de Dropi mientras la
 *      factura es a Consumidor Final.
 *
 * La venta la reversa ahora UN solo documento, la nota crédito, que es además
 * lo que viaja a SIIGO: base a devoluciones, IVA en la proporción de la
 * factura, cartera al cliente de la factura. Sin factura no hay venta que
 * reversar, y entonces sólo vuelve el inventario.
 *
 * Idempotente: si la devolución ya tiene asiento propio no vuelve a escribir.
 */
class ContabilizarDevolucionDropi
{
    use AsAction;

    public function handle(DropiDevolucion $devolucion): int
    {
        if ($devolucion->destino_inventario !== 'reingreso') {
            return 0;
        }

        $devolucion->loadMissing([
            'pedido.items.variante.producto.categoriaMaestra.padre',
            'pedido.items.producto.categoriaMaestra.padre',
        ]);
        $pedido = $devolucion->pedido;
        if (! $pedido) {
            return 0;
        }

        if (MovimientoContable::where('origen_type', DropiDevolucion::class)
            ->where('origen_id', $devolucion->id)->vivas()->exists()) {
            return 0;
        }

        $fecha = $devolucion->recibido_at?->toDateString() ?? now()->toDateString();
        $userId = auth()->id();

        // Costo con que salió cada ítem (egreso del empaque). Se reversa a ESE
        // valor y sólo lo que de verdad volvió al kardex (lo escaneado al
        // recibir, 5.4), repartido entre las líneas del mismo producto.
        $costos = SalidaInventarioDropi::costosDeSalida($pedido->id);
        $quedan = [];
        $lineas = [];

        foreach ($pedido->items as $item) {
            if (! $item->variante_id && ! $item->producto_id) {
                continue;
            }
            $clave = CostoPromedio::clave($item->variante_id, $item->producto_id);
            $quedan[$clave] ??= is_array($devolucion->recibidos)
                ? (int) ($devolucion->recibidos[$clave] ?? 0)
                : PHP_INT_MAX;
            $vuelven = min((int) ($item->cantidad ?? 1), $quedan[$clave]);
            $quedan[$clave] -= $vuelven;

            $costo = round(($costos[$clave] ?? 0) * $vuelven, 2);
            if ($costo <= 0) {
                continue;
            }

            $producto = $item->variante?->producto ?? $item->producto;
            $ref = $producto?->referencia ?? $item->sku_dropi ?? '—';
            $base = [
                'fecha' => $fecha,
                'origen_type' => DropiDevolucion::class, 'origen_id' => $devolucion->id,
                'user_id' => $userId,
            ];

            $lineas[] = [...$base,
                'cuenta_puc' => CuentasDropi::inventario($producto),
                'debe' => $costo, 'haber' => 0,
                'descripcion' => "Reingreso inventario {$ref} · devolución guía {$pedido->guia}",
            ];
            $lineas[] = [...$base,
                'cuenta_puc' => CuentasDropi::costo($producto),
                'debe' => 0, 'haber' => $costo,
                'descripcion' => "Reverso costo de venta {$ref} · devolución guía {$pedido->guia}",
            ];
        }

        return MovimientoContable::registrarAsientoAtomico($lineas);
    }
}
