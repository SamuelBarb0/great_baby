<?php

namespace App\Modules\Cartera\Actions;

use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Registra los asientos de partida doble en movimientos_contables usando
 * el helper atómico `MovimientoContable::registrarAsientoAtomico` que valida
 * ΣDebe = ΣHaber ANTES de commitear. Esto convierte cualquier factura mal
 * armada en excepción — nada de asientos silenciosamente descuadrados.
 *
 * Cuentas PUC usadas (PUC colombiano estándar):
 *   1305 - Clientes B2B (CxC)
 *   1355 - Impuestos anticipos y saldos a favor
 *   4135 - Comercio al por mayor y menor (ingreso)
 *   2408 - IVA por pagar
 *   1105 - Caja / 1110 - Bancos
 *   530510 - Descuentos comerciales condicionados
 *   5195 - Transportes fletes y acarreos
 *   2805 - Anticipos y avances recibidos (para sobrepagos)
 */
class RegistrarAsientoContable
{
    use AsAction;

    public function factura(FacturaVenta $factura): int
    {
        return DB::transaction(function () use ($factura) {
            $this->limpiarPrevios($factura);
            $terceroTipo = 'App\\Models\\Contacto';
            $userId = auth()->id();

            $lineas = [];

            // CxC al cliente: lo que va a pagar, neto de lo que nos retiene.
            $retenido = (float) $factura->retenciones;
            $lineas[] = [
                'fecha' => $factura->fecha_emision,
                'cuenta_puc' => '1305', 'tercero_type' => $terceroTipo, 'tercero_id' => $factura->contacto_id,
                'debe' => round((float) $factura->total - $retenido, 2), 'haber' => 0,
                'origen_type' => FacturaVenta::class, 'origen_id' => $factura->id,
                'descripcion' => "Factura {$factura->numero} — {$factura->contacto?->nombreDisplay()}",
                'user_id' => $userId,
            ];

            // Retenciones que nos practica el cliente: saldo a favor (1355xx).
            if ($retenido > 0) {
                foreach ($factura->retencionesAplicadas()->get() as $r) {
                    $lineas[] = [
                        'fecha' => $factura->fecha_emision,
                        'cuenta_puc' => $r->cuenta_puc, 'tercero_type' => $terceroTipo, 'tercero_id' => $factura->contacto_id,
                        'debe' => (float) $r->valor, 'haber' => 0,
                        'origen_type' => FacturaVenta::class, 'origen_id' => $factura->id,
                        'descripcion' => ucfirst($r->tipo).' '.(float) $r->tarifa_pct."% factura {$factura->numero}",
                        'user_id' => $userId,
                    ];
                }
            }

            // Ingreso por venta
            $ingreso = (float) $factura->subtotal - (float) $factura->descuento;
            $lineas[] = [
                'fecha' => $factura->fecha_emision,
                'cuenta_puc' => '4135', 'tercero_type' => $terceroTipo, 'tercero_id' => $factura->contacto_id,
                'debe' => 0, 'haber' => $ingreso,
                'origen_type' => FacturaVenta::class, 'origen_id' => $factura->id,
                'descripcion' => "Venta {$factura->numero}",
                'user_id' => $userId,
            ];

            // IVA por pagar (si aplica)
            if ((float) $factura->impuestos > 0) {
                $lineas[] = [
                    'fecha' => $factura->fecha_emision,
                    'cuenta_puc' => '2408', 'tercero_type' => $terceroTipo, 'tercero_id' => $factura->contacto_id,
                    'debe' => 0, 'haber' => (float) $factura->impuestos,
                    'origen_type' => FacturaVenta::class, 'origen_id' => $factura->id,
                    'descripcion' => "IVA factura {$factura->numero}",
                    'user_id' => $userId,
                ];
            }

            // Raíz C · valida partida doble antes de commit.
            return MovimientoContable::registrarAsientoAtomico($lineas);
        });
    }

    public function pago(PagoVenta $pago): int
    {
        return DB::transaction(function () use ($pago) {
            $this->limpiarPrevios($pago);
            $terceroTipo = 'App\\Models\\Contacto';
            $cuentaCaja = match ($pago->medio_pago) {
                'efectivo' => '1105',
                // 9.3 · el pago de Dropi entra a la wallet, no al banco.
                'wallet_dropi' => \App\Modules\Dropi\Listeners\RegistrarAsientoWalletDropi::CUENTA_WALLET,
                default => '1110',
            };
            $userId = auth()->id();

            $lineas = [];

            // Ingreso a caja/banco
            $lineas[] = [
                'fecha' => $pago->fecha,
                'cuenta_puc' => $cuentaCaja, 'tercero_type' => $terceroTipo, 'tercero_id' => $pago->contacto_id,
                'debe' => (float) $pago->monto_recibido, 'haber' => 0,
                'origen_type' => PagoVenta::class, 'origen_id' => $pago->id,
                'descripcion' => "Pago {$pago->medio_pago} — factura {$pago->factura?->numero}",
                'user_id' => $userId,
            ];

            // Cierre de CxC por el monto aplicado
            $lineas[] = [
                'fecha' => $pago->fecha,
                'cuenta_puc' => '1305', 'tercero_type' => $terceroTipo, 'tercero_id' => $pago->contacto_id,
                'debe' => 0, 'haber' => (float) $pago->monto_aplicado,
                'origen_type' => PagoVenta::class, 'origen_id' => $pago->id,
                'descripcion' => "Aplicación pago factura {$pago->factura?->numero}",
                'user_id' => $userId,
            ];

            $dif = (float) $pago->diferencia;
            if ($dif > 0 && $pago->clasificacion_diferencia) {
                $cuenta = match ($pago->clasificacion_diferencia->value) {
                    'descuento_pronto_pago', 'descuento_fuera_plazo' => '530510',
                    'flete_asumido_gb' => '5195',
                    default => null,
                };
                if ($cuenta) {
                    $lineas[] = [
                        'fecha' => $pago->fecha,
                        'cuenta_puc' => $cuenta, 'tercero_type' => $terceroTipo, 'tercero_id' => $pago->contacto_id,
                        'debe' => $dif, 'haber' => 0,
                        'origen_type' => PagoVenta::class, 'origen_id' => $pago->id,
                        'descripcion' => "Ajuste: " . $pago->clasificacion_diferencia->label(),
                        'user_id' => $userId,
                    ];
                }
            }

            // SOBREPAGO → 2805 anticipos
            $sobrepago = (float) $pago->monto_recibido - (float) $pago->monto_aplicado - max($dif, 0);
            if ($sobrepago > 0.009) {
                $lineas[] = [
                    'fecha' => $pago->fecha,
                    'cuenta_puc' => '2805', 'tercero_type' => $terceroTipo, 'tercero_id' => $pago->contacto_id,
                    'debe' => 0, 'haber' => $sobrepago,
                    'origen_type' => PagoVenta::class, 'origen_id' => $pago->id,
                    'descripcion' => "Sobrepago cliente — anticipo a favor por \${$sobrepago}",
                    'user_id' => $userId,
                ];
            }

            // Raíz C · valida partida doble antes de commit.
            return MovimientoContable::registrarAsientoAtomico($lineas);
        });
    }

    protected function limpiarPrevios($modelo): void
    {
        // Re-audit RAÍZ Y (R3-1 datos) · forceDelete físico. Antes soft-borraba
        // → cada re-corrida acumulaba filas soft-deleted + vivas (shadow rows),
        // hinchaba `audits`, y `MovimientoContable::withTrashed()` mostraba
        // versiones contradictorias del mismo hecho. Un asiento previo a la
        // re-corrida es basura — el libro DIAN vive en NC/facturas emitidas.
        MovimientoContable::query()
            ->where('origen_type', get_class($modelo))
            ->where('origen_id', $modelo->id)
            ->forceDelete();
    }
}
