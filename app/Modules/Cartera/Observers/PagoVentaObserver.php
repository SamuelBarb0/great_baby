<?php

namespace App\Modules\Cartera\Observers;

use App\Modules\Cartera\Actions\RegistrarAsientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Siigo\Jobs\PushVoucherASiigo;
use Illuminate\Support\Facades\DB;

/**
 * Observer explícito para PagoVenta.
 * Reemplaza los hooks de booted() que Auditable trait a veces intercepta.
 *
 * F10 · Dispara push a SIIGO (voucher) tras crear/editar pagos.
 * Envuelto en DB::afterCommit para que un rollback no encole un job huérfano.
 */
class PagoVentaObserver
{
    public function created(PagoVenta $pago): void
    {
        (new RegistrarAsientoContable())->pago($pago);

        // Bajar el saldo de la factura.
        //
        // Era el único de los cuatro handlers que NO lo hacía: `updated`,
        // `deleted` y `restored` sí llamaban `recalcular()`. Resultado: cobrar
        // un pago dejaba la factura con el saldo completo y el estado sin
        // tocar, así que el cliente seguía debiendo en el ERP después de pagar
        // y `cartera:cobrar` le mandaba WhatsApp por una deuda ya saldada.
        // Sólo se corregía de rebote si alguien editaba el pago después.
        $pago->factura?->recalcular();

        // F10 · voucher SIIGO (recibo de caja aplicado a la factura).
        // Skip si el pago no tiene monto_aplicado > 0 (parcial sin aplicar).
        if ((float) $pago->monto_aplicado <= 0) return;
        $id = $pago->id;
        DB::afterCommit(function () use ($id) {
            PushVoucherASiigo::dispatch($id);
        });
    }

    /**
     * Re-audit R3-C · si Aracely edita monto_aplicado / medio_pago / clasificación
     * post-creación (desde Filament), el asiento contable ANTES quedaba con el
     * valor viejo (cartera y contabilidad divergían). Ahora se re-genera.
     *
     * PagoVenta::updating bloquea cambios a `factura_id`; el resto de campos
     * SÍ es legítimo editar (corrección de captura). Este handler mantiene
     * consistencia contable con la edición.
     */
    public function updated(PagoVenta $pago): void
    {
        $camposContables = ['monto_recibido', 'monto_aplicado', 'diferencia',
            'clasificacion_diferencia', 'medio_pago', 'fecha'];
        if (! $pago->wasChanged($camposContables)) return;

        // Re-generar asiento (limpiarPrevios → forceDelete → nuevo).
        (new RegistrarAsientoContable())->pago($pago);
        $pago->factura?->recalcular();
    }

    public function deleted(PagoVenta $pago): void
    {
        // Un cobro desde la wallet de Dropi lo deshace un reverso de Dropi
        // (1009), no una corrección de captura: es un hecho contable que pasó,
        // y el recibo ya puede estar en SIIGO. Se reversa con contra-asiento y
        // se conserva la historia, igual que los demás movimientos de la wallet.
        if ($pago->medio_pago === 'wallet_dropi') {
            $this->reversarConContraAsiento($pago);
            $pago->factura?->recalcular();

            return;
        }

        // 1. Reversar movimientos contables originados por este pago.
        //    forceDelete físico — no deja shadow rows en `movimientos_contables`.
        \App\Modules\Cartera\Models\MovimientoContable::where('origen_type', PagoVenta::class)
            ->where('origen_id', $pago->id)
            ->forceDelete();

        // 2. Recalcular saldo/estado de la factura
        $pago->factura?->recalcular();
    }

    /**
     * Escribe la reversa de cada línea viva del pago (marcada `[REVERSA #id]`).
     * Si el recibo ya estaba en SIIGO, la reversa viaja como comprobante
     * contable: allá la billetera y la cartera tienen que bajar igual que acá.
     */
    private function reversarConContraAsiento(PagoVenta $pago): void
    {
        $mc = \App\Modules\Cartera\Models\MovimientoContable::class;
        $lineas = $mc::where('origen_type', PagoVenta::class)->where('origen_id', $pago->id)->orderBy('id')->get();

        $escribio = DB::transaction(function () use ($lineas, $pago, $mc) {
            $escribio = false;
            foreach ($lineas as $l) {
                if (str_starts_with((string) $l->descripcion, '[REVERSA')) continue;
                if ($lineas->contains(fn ($o) => str_starts_with((string) $o->descripcion, '[REVERSA #'.$l->id.']'))) continue;

                $mc::create([
                    'fecha' => now()->toDateString(),
                    'cuenta_puc' => $l->cuenta_puc,
                    'tercero_type' => $l->tercero_type, 'tercero_id' => $l->tercero_id,
                    'debe' => (float) $l->haber, 'haber' => (float) $l->debe,
                    'origen_type' => PagoVenta::class, 'origen_id' => $pago->id,
                    'descripcion' => '[REVERSA #'.$l->id.'] '.($l->descripcion ?? ''),
                    // Los ejes se copian de la línea: el pago ya está borrado y
                    // el estampado no lo encontraría (caería en Great Baby).
                    'linea_negocio' => $l->linea_negocio,
                    'destino_fiscal' => $l->destino_fiscal,
                    'user_id' => auth()->id(),
                ]);
                $escribio = true;
            }

            return $escribio;
        });

        if ($escribio && $pago->siigo_id) {
            $id = $pago->id;
            DB::afterCommit(fn () => \App\Modules\Siigo\Jobs\PushAsientoDropiASiigo::dispatch(PagoVenta::class, $id));
        }
    }

    public function restored(PagoVenta $pago): void
    {
        // Re-generar asiento contable (el delete lo borró)
        (new \App\Modules\Cartera\Actions\RegistrarAsientoContable())->pago($pago);
        $pago->factura?->recalcular();
    }
}
