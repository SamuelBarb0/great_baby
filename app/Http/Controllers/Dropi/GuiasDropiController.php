<?php

namespace App\Http\Controllers\Dropi;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Dropi\Models\DropiLoteImpresion;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las partes del PDF de un lote de «Imprimir guías» de «Pedidos Dropi»: la
 * lista de alistamiento y los stickers, en el orden de empaque. Los stickers
 * llevan nombre, teléfono y dirección del cliente: sólo quien cambia pedidos
 * en Dropi.
 */
class GuiasDropiController extends Controller
{
    public function parte(DropiLoteImpresion $lote, int $parte): Response
    {
        abort_unless(Permisos::puede(auth()->user(), 'dropi_cambiar_en_dropi'), 403);
        $ruta = ($lote->partes ?? [])[$parte - 1] ?? null;
        abort_if($ruta === null || ! Storage::disk('local')->exists($ruta), 404, 'Esa parte del lote todavía no está lista.');

        return response(Storage::disk('local')->get($ruta), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($ruta) . '"',
        ]);
    }
}
