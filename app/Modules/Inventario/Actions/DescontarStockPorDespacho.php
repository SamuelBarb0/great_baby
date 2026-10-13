<?php

namespace App\Modules\Inventario\Actions;

// El modelo del kardex vive en el namespace Dropi por historia del proyecto:
// es el kardex de TODO el inventario, no sólo de ese canal.
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Inventario\Support\CostoPromedio;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Support\Facades\Log;

/**
 * Saca del kardex la mercancía que se despacha en un pedido B2B.
 *
 * Hasta ahora una venta B2B NUNCA bajaba el stock: el único egreso de venta que
 * existía era el del canal Dropi (`DescontarInventarioAlEmpacar`). Se despachaban
 * 50 unidades, se facturaban, y el ERP seguía mostrándolas en bodega — el
 * inventario del sistema no volvía a coincidir con el estante nunca.
 *
 * Se descuenta al DESPACHAR, no al facturar, porque es el momento en que la
 * mercancía sale físicamente y es lo que el conteo del estante va a reflejar.
 */
class DescontarStockPorDespacho
{
    public const REFERENCIA_TIPO = 'pedido_b2b_despacho';

    /**
     * Debe llamarse DENTRO de la transacción del despacho.
     *
     * @return int cuántas líneas se descontaron
     */
    public function ejecutar(PedidoCliente $pedido, ?int $userId = null): int
    {
        // Idempotencia: si el pedido ya tiene su egreso, no duplicar. El despacho
        // ya se protege con `despachado_at`, pero esto cubre un reintento manual
        // o un despacho corregido a mano.
        $yaDescontado = InventarioMovimiento::query()
            ->where('referencia_tipo', self::REFERENCIA_TIPO)
            ->where('referencia_id', $pedido->id)
            ->exists();

        if ($yaDescontado) {
            return 0;
        }

        $ubicacionId = $pedido->ubicacion_origen_id;
        if (! $ubicacionId) {
            // Sin bodega de origen no sabemos de dónde sacarlo. No frenamos el
            // despacho —la mercancía ya está saliendo— pero queda el rastro para
            // que Jorge lo cuadre.
            Log::warning('[despacho] pedido sin ubicación de origen: no se descontó stock', [
                'pedido_id' => $pedido->id, 'numero' => $pedido->numero,
            ]);

            return 0;
        }

        // Se cargan los productos por adelantado: el asiento los necesita para
        // resolver la cuenta propia de cada uno. Con sólo `items`, `$producto`
        // salía null, el costo caía siempre en la cuenta global y la glosa del
        // libro decía «sin-ref» en vez de la referencia.
        $pedido->loadMissing(['items.variante.producto', 'items.producto']);

        // Antes de escribir nada: si una línea deja el kardex en negativo, el
        // despacho no sale. Los locks que toma quedan hasta el commit.
        $this->exigirSaldo($pedido, $ubicacionId);

        $lineas = 0;

        // Costo con el que sale cada ítem, en una sola consulta para no hacer
        // una por línea. Es el promedio ponderado de las entradas a esa bodega.
        $costos = CostoPromedio::porUbicacion(
            $pedido->items->map(fn ($i) => [
                'variante_id' => $i->variante_id,
                'producto_id' => $i->producto_id,
            ])->all(),
            $ubicacionId
        );
        $asiento = [];

        foreach ($pedido->items as $item) {
            // Ítem sin catálogo (texto libre): no hay nada que descontar.
            if (! $item->variante_id && ! $item->producto_id) {
                continue;
            }
            $cantidad = (int) ($item->cantidad ?? 0);
            if ($cantidad <= 0) {
                continue;
            }

            $esAgregado = $item->variante_id === null && $item->producto_id !== null;

            $costoUnit = $costos[CostoPromedio::clave($item->variante_id, $item->producto_id)] ?? 0.0;

            InventarioMovimiento::create([
                'variante_id' => $item->variante_id,
                'producto_id' => $item->producto_id,
                'ubicacion_id' => $ubicacionId,
                'tipo' => 'salida_venta',
                // Negativo: `SUM(cantidad)` es el saldo físico.
                'cantidad' => -$cantidad,
                // Sin costo el kardex no se puede valorizar ni asentar.
                'costo_unit' => $costoUnit,
                'referencia_tipo' => self::REFERENCIA_TIPO,
                'referencia_id' => $pedido->id,
                'user_id' => $userId,
                'notas' => "Despacho pedido {$pedido->numero}"
                    . ($pedido->guia_transportadora ? " · guía {$pedido->guia_transportadora}" : '')
                    . ($esAgregado ? ' · AGREGADO' : ''),
            ]);
            $lineas++;

            if ($costoUnit > 0) {
                $asiento[] = [
                    'producto' => $item->variante?->producto ?? $item->producto,
                    // El producto puede estar borrado (pasa con datos viejos);
                    // el SKU que el pedido guardo al crearse siempre esta.
                    'sku' => $item->sku_snapshot,
                    'valor' => round($costoUnit * $cantidad, 2),
                ];
            }
        }

        $this->causarCosto($pedido, $asiento, $userId);

        return $lineas;
    }

    /**
     * Frena el despacho si la bodega de origen no tiene saldo para todo el pedido.
     *
     * Hasta ahora el egreso se escribía siempre: despachar 10 con 3 en bodega
     * dejaba el kardex en −7 sin avisar a nadie. Se compara contra el saldo
     * FÍSICO (lo que hay en el estante), no el disponible: el pedido que se
     * está despachando puede tener su propia reserva y se contaría dos veces.
     *
     * Las cantidades se suman por producto porque un mismo SKU puede venir en
     * dos líneas, y cada una sola cabría aunque juntas no.
     */
    private function exigirSaldo(PedidoCliente $pedido, int $ubicacionId): void
    {
        $pedidas = [];
        foreach ($pedido->items as $item) {
            if (! $item->variante_id && ! $item->producto_id) continue;
            $cantidad = (int) ($item->cantidad ?? 0);
            if ($cantidad <= 0) continue;

            $clave = CostoPromedio::clave($item->variante_id, $item->producto_id);
            $pedidas[$clave] ??= ['item' => $item, 'cantidad' => 0];
            $pedidas[$clave]['cantidad'] += $cantidad;
        }

        $faltantes = [];
        foreach ($pedidas as ['item' => $item, 'cantidad' => $cantidad]) {
            // Serializa contra un conteo o traslado simultáneo sobre el mismo
            // producto y bodega, igual que hace el egreso de Dropi.
            $q = InventarioMovimiento::query()->where('ubicacion_id', $ubicacionId);
            $item->variante_id
                ? $q->where('variante_id', $item->variante_id)
                : $q->where('producto_id', $item->producto_id)->whereNull('variante_id');
            $saldo = (int) $q->lockForUpdate()->get(['cantidad'])->sum('cantidad');

            if ($saldo < $cantidad) {
                $ref = $item->sku_snapshot ?: ($item->descripcion_snapshot ?: "ítem #{$item->id}");
                $faltantes[] = "{$ref}: pide {$cantidad}, hay ".max($saldo, 0);
            }
        }

        abort_if($faltantes, 422,
            'No hay saldo suficiente en la bodega de origen para despachar: '
            .implode(' · ', $faltantes).'. Cargá la entrada o trasladá la mercancía antes de despachar.');
    }

    /**
     * Lleva al libro el costo de lo que salió: debe costo, haber inventario.
     *
     * Hasta ahora la venta sólo registraba el ingreso. El resultado era que el
     * margen bruto del Estado de resultados daba siempre 100% —no había un solo
     * movimiento de clase 6 en toda la base— y la cuenta de inventario jamás
     * bajaba, así que el balance sobrestimaba el activo de forma permanente.
     *
     * Se causa al DESPACHAR, igual que el egreso de kardex: es el momento en
     * que la mercancía sale, y así el libro y el estante cuentan lo mismo.
     *
     * Las cuentas salen de la misma cascada que usa la devolución Dropi:
     * primero la del producto, si no la global del plan.
     *
     * @param  list<array{producto:mixed, sku:?string, valor:float}>  $asiento
     */
    private function causarCosto(PedidoCliente $pedido, array $asiento, ?int $userId): void
    {
        if (! $asiento) {
            // Pasa cuando ningún ítem tiene costo conocido todavía. No se
            // frena el despacho, pero queda el rastro: un asiento en cero
            // ensuciaría el libro sin decir nada.
            Log::warning('[despacho] sin costo conocido: no se causó el costo de ventas', [
                'pedido_id' => $pedido->id, 'numero' => $pedido->numero,
            ]);

            return;
        }

        $fecha = now('America/Bogota')->toDateString();
        $lineas = [];

        foreach ($asiento as $a) {
            $producto = $a['producto'];
            $ctaCosto = $producto?->cta('costo') ?? setting('contable.cta_costo_default', '6135');
            $ctaInv = $producto?->cta('inventario') ?? setting('contable.cta_inventario_default', '1435');
            $ref = $producto?->referencia ?? $a['sku'] ?? 'sin referencia';

            $lineas[] = [
                'fecha' => $fecha, 'cuenta_puc' => $ctaCosto,
                'debe' => $a['valor'], 'haber' => 0,
                'origen_type' => PedidoCliente::class, 'origen_id' => $pedido->id,
                'descripcion' => "Costo de venta {$ref} · despacho {$pedido->numero}",
                'user_id' => $userId,
            ];
            $lineas[] = [
                'fecha' => $fecha, 'cuenta_puc' => $ctaInv,
                'debe' => 0, 'haber' => $a['valor'],
                'origen_type' => PedidoCliente::class, 'origen_id' => $pedido->id,
                'descripcion' => "Salida inventario {$ref} · despacho {$pedido->numero}",
                'user_id' => $userId,
            ];
        }

        MovimientoContable::registrarAsientoAtomico($lineas);
    }
}
