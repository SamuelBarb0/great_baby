<?php

namespace App\Modules\Cartera\Actions;

use App\Models\User;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoProveedor;
use App\Support\PasoNoPermitido;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Anular un pago a proveedor deja el libro como estaba: se borran las líneas
 * de su asiento (las retenciones son la base de la declaración ante la DIAN).
 * Vivía en `PagosProveedorController::anular`; se sacó (7-nov) para el panel de Dropi.
 *
 * @return int cuántas líneas del libro se revirtieron
 */
class AnularPagoProveedor
{
    use AsAction;

    /** ¿Se puede anular? Lanza el aviso si no. Va antes de pedir el motivo. */
    public static function verificar(PagoProveedor $pago): void
    {
        if ($pago->siigo_voucher_id) {
            throw new PasoNoPermitido("Pago #{$pago->id} ya está en SIIGO · usar nota de ajuste allá.");
        }

        // Anular dos veces borraría un asiento que ya no está y pisaría el motivo del primero.
        if ($pago->estado === 'anulado') {
            throw PasoNoPermitido::info("Pago #{$pago->id} ya estaba anulado.");
        }
    }

    public function handle(PagoProveedor $pago, User $quien, string $motivo): int
    {
        self::verificar($pago);

        return DB::transaction(function () use ($pago, $quien, $motivo) {
            $n =MovimientoContable::query()
                ->where('origen_type', PagoProveedor::class)
                ->where('origen_id', $pago->id)
                ->forceDelete();

            $pago->forceFill([
                'estado' => 'anulado',
                'anulado_at' => now(),
                'anulado_por' => $quien->id,
                'motivo_anulacion' => $motivo,
            ])->save();

            return $n;
        });
    }
}
