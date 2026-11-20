<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Dropi\Enums\TipoMovimientoWallet;
use App\Modules\Dropi\Listeners\RegistrarAsientoWalletDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use Illuminate\Support\Facades\DB;

/**
 * El cobro de una factura de Dropi desde la wallet: UNA sola regla para los
 * dos órdenes en que pueden llegar las cosas.
 *
 *   · Factura primero, pago después (lo normal): ConciliarWalletDropi llama a
 *     `registrar()` al recibir el pago de guía.
 *   · Pago primero, factura después: el pago se asentó como ANTICIPO
 *     (28050501), porque no había factura que cobrar. Al facturar,
 *     FacturarPedidoDropi llama a `cruzarPrevios()`: cada pago de guía del
 *     pedido se convierte en recibo de caja de la factura y su asiento de
 *     anticipo se reversa. Sin esto la factura quedaba pendiente de cobro para
 *     siempre aunque Dropi ya hubiera pagado, y el anticipo nunca se cerraba.
 *
 * El recibo (PagoVenta `wallet_dropi`) asienta wallet contra cartera y viaja a
 * SIIGO como recibo de caja; ver PagoVentaObserver.
 */
class CobroWalletDeFactura
{
    /** El recibo de caja de un pago de guía. Idempotente por la referencia del movimiento. */
    public static function registrar(DropiPedido $pedido, float $monto, string $fecha, ?string $movId): ?PagoVenta
    {
        if (! $pedido->factura_venta_id || $monto <= 0) {
            return null;
        }
        $factura = FacturaVenta::find($pedido->factura_venta_id);
        if (! $factura) {
            return null;
        }
        $referencia = $movId ? mb_substr($movId, 0, 60) : null;
        if ($referencia && PagoVenta::where('medio_pago', 'wallet_dropi')->where('referencia', $referencia)->exists()) {
            return null;
        }

        $aplicado = min($monto, max(0, (float) $factura->saldo));

        return PagoVenta::create([
            'factura_id' => $factura->id,
            'contacto_id' => $factura->contacto_id,
            'fecha' => $fecha,
            'monto_recibido' => $monto,
            'monto_aplicado' => $aplicado,
            'diferencia' => round($aplicado - $monto, 2),
            'medio_pago' => 'wallet_dropi',
            'referencia' => $referencia,
            'banco' => 'Wallet Dropi',
            'notas' => "Pago Dropi guía {$pedido->guia}",
        ]);
    }

    /** Un reverso 1009 deshace el recibo: la factura vuelve a quedar por cobrar. */
    public static function reversar(DropiPedido $pedido, float $monto): void
    {
        if (! $pedido->factura_venta_id) {
            return;
        }
        PagoVenta::where('factura_id', $pedido->factura_venta_id)
            ->where('medio_pago', 'wallet_dropi')
            ->orderByRaw('ABS(monto_recibido - ?) ASC', [abs($monto)])
            ->orderByDesc('id')
            ->first()
            ?->delete();
    }

    /**
     * Al facturar un pedido que Dropi ya había pagado: los pagos de guía que
     * llegaron antes pasan de anticipo a recibo de caja de la factura.
     *
     * @return int movimientos cruzados
     */
    public static function cruzarPrevios(DropiPedido $pedido): int
    {
        $pedido->refresh();
        if (! $pedido->factura_venta_id) {
            return 0;
        }

        $movs = DropiWalletMovimiento::where('pedido_id', $pedido->id)
            ->where('tipo', TipoMovimientoWallet::PagoGuia->value)
            ->orderBy('fecha')->orderBy('id')
            ->get();

        if ($movs->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($movs, $pedido) {
            [$entradas, $reversos] = $movs->partition(fn ($m) => (float) $m->monto > 0);

            // Cada pago, su recibo (idempotente por la referencia).
            foreach ($entradas as $m) {
                self::registrar($pedido, (float) $m->monto, $m->fecha->toDateString(), $m->dropi_movimiento_id);
            }

            // Cada reverso deshace un recibo, pero sólo hasta dejar los que
            // corresponden: así correrlo dos veces no borra un recibo de más.
            $esperados = max(0, $entradas->count() - $reversos->count());
            $vivos = fn () => PagoVenta::where('factura_id', $pedido->factura_venta_id)->where('medio_pago', 'wallet_dropi')->count();
            foreach ($reversos as $m) {
                if ($vivos() <= $esperados) {
                    break;
                }
                self::reversar($pedido, (float) $m->monto);
            }

            // Con la factura puesta el listener ya no asienta el pago: sólo
            // escribe la reversa del anticipo que había hecho.
            $listener = new RegistrarAsientoWalletDropi();
            foreach ($movs as $m) {
                $m->setRelation('pedido', $pedido);
                $listener->handle($m);
            }

            return $movs->count();
        });
    }
}
