<?php

namespace App\Modules\Siigo\Observers;

use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Siigo\Jobs\PushPagoProveedorASiigo;
use Illuminate\Support\Facades\DB;

/**
 * El egreso viaja a SIIGO cuando alguien lo CONFIRMA, no cuando se registra.
 *
 * El flujo de dos pasos existe —`pendiente` → `confirmado`— y la migracion que
 * lo creo dice que es para que el push espere la revision de Aracely (verificar
 * NIT, monto y cuenta). Pero este observer despachaba en `created`, cuando el
 * pago todavia nace `pendiente`, y el job tampoco miraba el estado: la
 * compuerta estaba ahi y no cerraba nada.
 *
 * El patron correcto ya existia un archivo mas alla: `PushAsientoManualASiigo`
 * chequea el estado antes de enviar.
 */
class PagoProveedorObserver
{
    public function created(PagoProveedor $p): void
    {
        // Un pago recien registrado esta `pendiente`: no sale hasta que lo
        // confirmen. Lo despacha `PagosProveedorController::confirmar()`.
        if ($p->estado !== 'confirmado') {
            return;
        }

        $this->empujar($p);
    }

    /** Si alguien lo confirma por otra via, que igual salga. */
    public function updated(PagoProveedor $p): void
    {
        if (! $p->wasChanged('estado') || $p->estado !== 'confirmado') {
            return;
        }

        $this->empujar($p);
    }

    private function empujar(PagoProveedor $p): void
    {
        if ($p->siigo_voucher_id) {
            return;
        }

        $id = $p->id;
        DB::afterCommit(function () use ($id) {
            PushPagoProveedorASiigo::dispatch($id);
        });
    }
}
