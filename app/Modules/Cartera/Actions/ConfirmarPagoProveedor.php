<?php

namespace App\Modules\Cartera\Actions;

use App\Models\User;
use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Siigo\Jobs\PushPagoProveedorASiigo;
use App\Support\PasoNoPermitido;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * COMP-B5 · Confirmar un pago a proveedor: sale la plata, se escribe el asiento
 * y el documento viaja a SIIGO. Vivía en `PagosProveedorController::confirmar`;
 * se sacó (7-nov) para el panel de Dropi.
 */
class ConfirmarPagoProveedor
{
    use AsAction;

    public function handle(PagoProveedor $pago, User $quien): PagoProveedor
    {
        if ($pago->estado === 'confirmado') {
            throw PasoNoPermitido::info("Pago #{$pago->id} ya estaba confirmado.");
        }
        if ($pago->estado === 'anulado') {
            throw new PasoNoPermitido("Pago #{$pago->id} está anulado · no se puede confirmar.");
        }

        /*
         * Cuatro ojos: quien registró el pago no lo confirma. Sin excepción
         * para Aracely ni Gerencia: un control que la jefa puede saltarse es un
         * control que se salta siempre, y justo en el pago que importa.
         */
        if ((int) $pago->user_id === (int) $quien->id) {
            throw new PasoNoPermitido("Pago #{$pago->id}: vos lo registraste, así que no lo podés confirmar. "
                .'Lo tiene que revisar otra persona del equipo contable.');
        }

        $pago->forceFill([
            'estado' => 'confirmado',
            'confirmado_at' => now(),
            'confirmado_por' => $quien->id,
        ])->save();

        // El asiento va al CONFIRMAR y no al crear: hasta que alguien revisa,
        // la plata no salió.
        app(RegistrarAsientoPagoProveedor::class)->handle($pago->fresh());

        PushPagoProveedorASiigo::dispatchManual($pago->id);

        return $pago;
    }
}
