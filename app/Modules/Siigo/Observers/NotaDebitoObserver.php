<?php

namespace App\Modules\Siigo\Observers;

use App\Modules\Cartera\Models\NotaDebito;
use App\Modules\Siigo\Jobs\PushNotaDebitoASiigo;
use Illuminate\Support\Facades\DB;

class NotaDebitoObserver
{
    public function created(NotaDebito $nd): void
    {
        /*
         * La nota debito no llegaba a NINGUN lado: ni asiento ni saldo.
         *
         * `FacturaVenta::recalcular()` resta las notas credito y no suma las
         * notas debito, asi que una ND aumentaba la deuda del cliente ante la
         * DIAN y el ERP no se enteraba por ningun lado: ni en cartera, ni en el
         * balance, ni en el estado de resultados.
         */
        app(\App\Modules\Cartera\Actions\RegistrarAsientoNota::class)->debito($nd->fresh());
        $nd->factura?->recalcular();

        if ($nd->siigo_id) return;
        $id = $nd->id;
        DB::afterCommit(function () use ($id) {
            PushNotaDebitoASiigo::dispatch($id);
            \App\Modules\Notificaciones\Jobs\NotificarCadenaValorJob::dispatch('nd-emitida', $id);
        });
    }
}
