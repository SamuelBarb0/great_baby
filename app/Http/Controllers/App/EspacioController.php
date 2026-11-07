<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\EspacioTrabajo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * El selector «¿en qué empresa estoy?» de la barra superior de `/app`.
 */
class EspacioController extends Controller
{
    public function cambiar(Request $r): RedirectResponse
    {
        $u = $r->user();
        abort_unless(EspacioTrabajo::puedeCambiar($u), 403);

        $data = $r->validate([
            'espacio' => ['required', 'in:'.EspacioTrabajo::GENERAL.','.EspacioTrabajo::ECOMMERCE],
        ]);

        EspacioTrabajo::fijar($data['espacio']);

        /*
         * Se aterriza en el inicio del espacio y no en la misma pantalla: si
         * estaba en algo del ERP general (cartera de Great Baby, nómina…) y
         * pasa al e-commerce, quedarse ahí es justo lo que pidió no ver.
         */
        if ($data['espacio'] === EspacioTrabajo::ECOMMERCE) {
            return redirect($u->esAracely() ? '/app/ecommerce/ventas' : '/app/dropi/alistador');
        }

        return redirect('/app');
    }
}
