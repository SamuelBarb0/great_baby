<?php

namespace App\Http\Controllers\Dropi;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Dropi\Models\DropiPedido;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * El sticker de la guía de un pedido, servido desde el ERP (reunión con la
 * señora Lahida, 25-nov-2026: «escanea y ahí imprime la guía», sin abrir
 * pestañas). Dropi lo publica en CloudFront, pero el navegador no deja mandar
 * a imprimir un PDF de otro dominio desde la página: por eso pasa por aquí.
 *
 * Se guarda la primera vez (la vista previa de la estación lo pide antes de
 * imprimir, y un reimpreso no vuelve a CloudFront). El nombre lleva la ruta de
 * Dropi: si la guía se anula y se genera otra, se baja la nueva.
 */
class StickerPedidoController extends Controller
{
    public function __invoke(DropiPedido $pedido): Response
    {
        $u = auth()->user();
        abort_unless(Permisos::puede($u, 'estacion_empaque') || Permisos::puede($u, 'dropi_cambiar_en_dropi'), 403);
        abort_unless($pedido->stickerUrl(), 404, 'Este pedido todavía no tiene sticker de Dropi.');

        $disco = Storage::disk('local');
        $ruta = "dropi/stickers/{$pedido->id}-" . md5((string) $pedido->sticker_path) . '.pdf';

        if (! $disco->exists($ruta)) {
            $r = rescue(fn () => Http::timeout(20)->get($pedido->stickerUrl()), null, false);
            $bytes = $r?->successful() ? $r->body() : null;
            abort_unless($bytes && str_starts_with($bytes, '%PDF'), 404, 'Dropi todavía no publica el sticker: reintenta en un momento.');
            $disco->put($ruta, $bytes);
        }

        return response($disco->get($ruta), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="guia-' . ($pedido->guia ?: $pedido->id) . '.pdf"',
            'Cache-Control' => 'private, max-age=3600',
            // La estación lo enmarca para verlo e imprimirlo (ver SecurityHeaders).
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }
}
