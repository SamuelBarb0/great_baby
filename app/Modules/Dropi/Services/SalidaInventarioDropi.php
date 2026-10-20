<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiPedidoItem;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Inventario\Support\BodegaDeSalida;
use App\Modules\Inventario\Support\CostoPromedio;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Salida de inventario de un pedido Dropi: kardex + costo de ventas.
 *
 * Es el mismo hueco que tenía el despacho B2B (`DescontarStockPorDespacho`):
 *   · el egreso salía siempre de «la primera bodega de venta», tuviera o no
 *     la mercancía, y sin mirar el saldo → kardex en negativo sin aviso;
 *   · se escribía sin `costo_unit`, así que la venta Dropi nunca causaba
 *     costo: el margen del canal daba 100 % y el 1435 no bajaba;
 *   · el reverso (rework) reingresaba en la primera bodega de venta, no en la
 *     que salió, y sin costo → bajaba el costo promedio de lo que quedaba;
 *   · si Dropi pasaba el pedido directo a despachado (sync) sin pasar por la
 *     estación de empaque, no se descontaba nada nunca.
 *
 * Ahora sale de donde está la mercancía (bodegas de venta, la de más saldo),
 * con su costo promedio, y el costo se causa en el mismo momento: debe 6135,
 * haber 1435, con la cascada de cuentas del producto.
 */
class SalidaInventarioDropi
{
    public const EGRESO = 'dropi_pedido_egreso';

    public const REVERSO = 'dropi_pedido_egreso_reverso';

    /** Unidades que el pedido tiene hoy fuera del kardex (egresos − reversos). */
    public static function unidadesFuera(int $pedidoId): int
    {
        return -(int) InventarioMovimiento::query()
            ->whereIn('referencia_tipo', [self::EGRESO, self::REVERSO])
            ->where('referencia_id', $pedidoId)
            ->sum('cantidad');
    }

    /**
     * Unidades del pedido que el kardex puede descontar (ítems vinculados a
     * una variante o a un producto agregado).
     */
    public static function unidadesDescontables(DropiPedido $pedido): int
    {
        return (int) $pedido->items
            ->filter(fn ($i) => ($i->variante_id || $i->producto_id) && (int) $i->cantidad > 0)
            ->sum(fn ($i) => (int) $i->cantidad);
    }

    /**
     * Costo unitario con el que salió cada sujeto del pedido (neto de reversos).
     * Lo usan la devolución y su asiento para reversar al costo real, no al
     * precio de venta.
     *
     * @return array<string, float>  clave `v:{id}` / `p:{id}` => costo unitario
     */
    public static function costosDeSalida(int $pedidoId): array
    {
        $out = [];
        foreach (self::netoPorSujeto($pedidoId) as $r) {
            $out[CostoPromedio::clave($r->variante_id, $r->producto_id)] = $r->unidades > 0
                ? round($r->valor / $r->unidades, 2)
                : 0.0;
        }

        return $out;
    }

    /**
     * Saca el pedido del kardex y causa su costo. Idempotente: si ya tiene
     * unidades fuera, no hace nada.
     *
     * Con `$exigirSaldo` (empaque en la estación) frena si la bodega de salida
     * no alcanza, igual que el despacho B2B. Sin él (Dropi avisó que ya salió)
     * descuenta igual —la mercancía ya no está— y deja el rastro en el log.
     *
     * Debe llamarse DENTRO de la transacción del cambio de estado.
     *
     * @return int unidades descontadas
     */
    public function descontar(DropiPedido $pedido, ?int $userId, bool $exigirSaldo): int
    {
        if (self::unidadesFuera($pedido->id) > 0) {
            return 0;
        }

        $pedido->loadMissing(['items.variante.producto', 'items.producto']);

        // Un mismo SKU puede venir en dos líneas: cada una sola cabría aunque
        // juntas no. Se agrupan por sujeto para medir el saldo.
        $porSujeto = [];
        foreach ($pedido->items as $item) {
            if (! $item->variante_id && ! $item->producto_id) continue;
            $cantidad = (int) ($item->cantidad ?? 0);
            if ($cantidad <= 0) continue;

            $clave = CostoPromedio::clave($item->variante_id, $item->producto_id);
            $porSujeto[$clave] ??= ['item' => $item, 'cantidad' => 0];
            $porSujeto[$clave]['cantidad'] += $cantidad;
        }

        if (! $porSujeto) {
            return 0;
        }

        $sujetos = array_map(fn ($s) => [
            'variante_id' => $s['item']->variante_id,
            'producto_id' => $s['item']->variante_id ? null : $s['item']->producto_id,
        ], array_values($porSujeto));

        $bodegas = BodegaDeSalida::resolver($sujetos, null, [CategoriaUbicacion::Venta->value]);

        $faltantes = [];
        foreach ($porSujeto as $clave => $s) {
            if (! isset($bodegas[$clave])) {
                // Sin existencias en ninguna bodega de venta: no se inventa un
                // egreso de algo que el kardex nunca registró como entrada
                // (mismo criterio del B2B).
                continue;
            }
            $saldo = $this->saldoBloqueado($s['item'], $bodegas[$clave]);
            if ($saldo < $s['cantidad']) {
                $faltantes[] = $this->referencia($s['item'])." pide {$s['cantidad']}, hay ".max($saldo, 0);
            }
        }

        if ($faltantes && $exigirSaldo) {
            throw new InvalidArgumentException(
                'No hay saldo suficiente en bodega para empacar: '.implode(' · ', $faltantes)
                .'. Cargá la entrada o trasladá la mercancía antes de empacar.'
            );
        }
        if ($faltantes) {
            Log::warning('[dropi] salida sin saldo suficiente (Dropi ya lo despachó)', [
                'pedido_id' => $pedido->id, 'guia' => $pedido->guia, 'faltantes' => $faltantes,
            ]);
        }

        $costos = [];
        foreach (array_unique(array_values($bodegas)) as $ubi) {
            $costos[$ubi] = CostoPromedio::porUbicacion($sujetos, $ubi);
        }

        $unidades = 0;
        $asiento = [];
        foreach ($pedido->items as $item) {
            if (! $item->variante_id && ! $item->producto_id) continue;
            $cantidad = (int) ($item->cantidad ?? 0);
            if ($cantidad <= 0) continue;

            $clave = CostoPromedio::clave($item->variante_id, $item->producto_id);
            $ubicacionId = $bodegas[$clave] ?? null;
            if (! $ubicacionId) {
                Log::warning('[dropi] ítem sin existencias en bodegas de venta: no se descontó', [
                    'pedido_id' => $pedido->id, 'guia' => $pedido->guia, 'sku' => $item->sku_dropi,
                ]);
                continue;
            }

            $costoUnit = $costos[$ubicacionId][$clave] ?? 0.0;
            $esAgregado = $item->variante_id === null;

            InventarioMovimiento::create([
                'variante_id' => $item->variante_id,
                'producto_id' => $item->producto_id,
                'ubicacion_id' => $ubicacionId,
                'tipo' => 'egreso',
                // Negativo: SUM(cantidad) es el saldo físico.
                'cantidad' => -$cantidad,
                'costo_unit' => $costoUnit,
                'referencia_tipo' => self::EGRESO,
                'referencia_id' => $pedido->id,
                'user_id' => $userId,
                'notas' => "Empaque guía {$pedido->guia}".($esAgregado ? ' · AGREGADO' : ''),
            ]);
            $unidades += $cantidad;

            if ($costoUnit > 0) {
                $asiento[] = [
                    'producto' => $item->variante?->producto ?? $item->producto,
                    'sku' => $item->sku_dropi,
                    'valor' => round($costoUnit * $cantidad, 2),
                ];
            }
        }

        $this->asentar($pedido, $asiento, $userId, reverso: false);

        return $unidades;
    }

    /**
     * Devuelve al kardex lo que el pedido tiene fuera —a la misma bodega y con
     * el mismo costo con que salió— y reversa el costo causado. Para rework
     * (Empacado → Alistando) y para cancelaciones de un pedido ya empacado: la
     * mercancía sigue en la bodega.
     *
     * @return int unidades reingresadas
     */
    public function revertir(DropiPedido $pedido, ?int $userId, string $motivo): int
    {
        $netos = self::netoPorSujeto($pedido->id);
        if ($netos->isEmpty()) {
            return 0;
        }

        $unidades = 0;
        $asiento = [];
        foreach ($netos as $r) {
            // Mismo lock que el egreso, contra conteos o traslados simultáneos.
            $q = InventarioMovimiento::query()->where('ubicacion_id', $r->ubicacion_id);
            $r->variante_id
                ? $q->where('variante_id', $r->variante_id)
                : $q->where('producto_id', $r->producto_id)->whereNull('variante_id');
            $q->lockForUpdate()->get(['id']);

            $costoUnit = $r->unidades > 0 ? round($r->valor / $r->unidades, 2) : 0.0;

            InventarioMovimiento::create([
                'variante_id' => $r->variante_id,
                'producto_id' => $r->producto_id,
                'ubicacion_id' => $r->ubicacion_id,
                'tipo' => 'ingreso',
                'cantidad' => (int) $r->unidades,
                'costo_unit' => $costoUnit,
                'referencia_tipo' => self::REVERSO,
                'referencia_id' => $pedido->id,
                'user_id' => $userId,
                'notas' => "Reverso de empaque guía {$pedido->guia} ({$motivo})"
                    .($r->variante_id ? '' : ' · AGREGADO'),
            ]);
            $unidades += (int) $r->unidades;

            if ($r->valor > 0) {
                $asiento[] = [
                    'producto' => $r->variante_id
                        ? ProductoVariante::with('producto')->find($r->variante_id)?->producto
                        : Producto::find($r->producto_id),
                    'sku' => null,
                    'valor' => round((float) $r->valor, 2),
                ];
            }
        }

        $this->asentar($pedido, $asiento, $userId, reverso: true);

        return $unidades;
    }

    /**
     * Lo que el pedido tiene fuera, por sujeto y bodega, con su valor a costo.
     *
     * @return \Illuminate\Support\Collection<int, object{variante_id:?int, producto_id:?int, ubicacion_id:int, unidades:int, valor:float}>
     */
    private static function netoPorSujeto(int $pedidoId)
    {
        return InventarioMovimiento::query()
            ->whereIn('referencia_tipo', [self::EGRESO, self::REVERSO])
            ->where('referencia_id', $pedidoId)
            ->selectRaw('variante_id, producto_id, ubicacion_id,
                -SUM(cantidad) AS unidades,
                -SUM(cantidad * COALESCE(costo_unit, 0)) AS valor')
            ->groupBy('variante_id', 'producto_id', 'ubicacion_id')
            ->havingRaw('SUM(cantidad) < 0')
            ->toBase()
            ->get()
            ->map(function ($r) {
                $r->variante_id = $r->variante_id ? (int) $r->variante_id : null;
                $r->producto_id = $r->producto_id ? (int) $r->producto_id : null;
                $r->unidades = (int) $r->unidades;
                $r->valor = (float) $r->valor;

                return $r;
            });
    }

    private function saldoBloqueado(DropiPedidoItem $item, int $ubicacionId): int
    {
        $q = InventarioMovimiento::query()->where('ubicacion_id', $ubicacionId);
        $item->variante_id
            ? $q->where('variante_id', $item->variante_id)
            : $q->where('producto_id', $item->producto_id)->whereNull('variante_id');

        return (int) $q->lockForUpdate()->get(['cantidad'])->sum('cantidad');
    }

    private function referencia(DropiPedidoItem $item): string
    {
        return $item->variante?->producto?->referencia
            ?? $item->producto?->referencia
            ?? $item->sku_dropi
            ?? "ítem #{$item->id}";
    }

    /**
     * Costo de ventas: debe 6135 / haber 1435 (al revés en el reverso), con
     * la cuenta propia del producto si la tiene.
     *
     * @param  list<array{producto:mixed, sku:?string, valor:float}>  $asiento
     */
    private function asentar(DropiPedido $pedido, array $asiento, ?int $userId, bool $reverso): void
    {
        if (! $asiento) {
            if (! $reverso) {
                Log::warning('[dropi] sin costo conocido: no se causó el costo de ventas', [
                    'pedido_id' => $pedido->id, 'guia' => $pedido->guia,
                ]);
            }

            return;
        }

        $fecha = now('America/Bogota')->toDateString();
        $lineas = [];
        foreach ($asiento as $a) {
            $producto = $a['producto'];
            $ctaCosto = $producto?->cta('costo') ?? setting('contable.cta_costo_default', '6135');
            $ctaInv = $producto?->cta('inventario') ?? setting('contable.cta_inventario_default', '1435');
            $ref = $producto?->referencia ?? $a['sku'] ?? 'sin referencia';
            $valor = $a['valor'];

            $lineas[] = [
                'fecha' => $fecha, 'cuenta_puc' => $ctaCosto,
                'debe' => $reverso ? 0 : $valor, 'haber' => $reverso ? $valor : 0,
                'origen_type' => DropiPedido::class, 'origen_id' => $pedido->id,
                'descripcion' => ($reverso ? 'Reverso costo de venta ' : 'Costo de venta ')
                    ."{$ref} · Dropi guía {$pedido->guia}",
                'user_id' => $userId,
            ];
            $lineas[] = [
                'fecha' => $fecha, 'cuenta_puc' => $ctaInv,
                'debe' => $reverso ? $valor : 0, 'haber' => $reverso ? 0 : $valor,
                'origen_type' => DropiPedido::class, 'origen_id' => $pedido->id,
                'descripcion' => ($reverso ? 'Reingreso inventario ' : 'Salida inventario ')
                    ."{$ref} · Dropi guía {$pedido->guia}",
                'user_id' => $userId,
            ];
        }

        MovimientoContable::registrarAsientoAtomico($lineas);
    }
}
