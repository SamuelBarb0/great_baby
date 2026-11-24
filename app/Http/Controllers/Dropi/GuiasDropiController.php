<?php

namespace App\Http\Controllers\Dropi;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Dropi\Services\ImprimirGuiasDropi;
use Symfony\Component\HttpFoundation\Response;

/**
 * El PDF de «Imprimir guías» de «Pedidos Dropi»: la lista de alistamiento y
 * los stickers de los pedidos escogidos, en el orden de empaque. Los stickers
 * llevan nombre, teléfono y dirección del cliente: sólo quien cambia pedidos
 * en Dropi.
 */
class GuiasDropiController extends Controller
{
    public function imprimir(string $token, ImprimirGuiasDropi $imprimir): Response
    {
        abort_unless(Permisos::puede(auth()->user(), 'dropi_cambiar_en_dropi'), 403);
        $ids = ImprimirGuiasDropi::seleccion($token);
        abort_if($ids === null, 410, 'Esta impresión ya venció: vuelve a escoger los pedidos y dale «Imprimir guías».');

        $r = $imprimir->generar($ids, auth()->id());

        return response($r['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="guias-dropi-' . now()->format('Ymd-Hi') . '.pdf"',
        ]);
    }
}
