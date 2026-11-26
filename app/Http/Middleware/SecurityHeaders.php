<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * QA-final: cabeceras de seguridad estándar para producción.
 * Aplicado global vía bootstrap/app.php.
 *
 * - X-Frame-Options: DENY (evita clickjacking)
 * - X-Content-Type-Options: nosniff (MIME sniffing)
 * - Referrer-Policy: same-origin (no fugar referers a terceros)
 * - Permissions-Policy: apaga sensores no usados
 * - Content-Security-Policy: solo permite scripts/estilos del propio origen y CDNs conocidos que usamos
 *
 * Notas:
 * - No aplicamos CSP estricta a rutas de PDF (dompdf carga inline styles).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // PDFs se sirven como binarios sin cabeceras CSP (dompdf inline styles).
        $contentType = $response->headers->get('Content-Type', '');
        $esPdf = str_contains($contentType, 'application/pdf');

        // DENY salvo que la respuesta pida SAMEORIGIN: sólo el sticker de la guía,
        //   que la estación de empaque muestra e imprime en un iframe del propio
        //   ERP (StickerPedidoController, 25-nov-2026). Ningún otro sitio lo enmarca.
        $mismoOrigen = $response->headers->get('X-Frame-Options') === 'SAMEORIGIN';
        $response->headers->set('X-Frame-Options', $mismoOrigen ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(self), payment=()');

        if (! $esPdf && app()->environment('production')) {
            // CSP moderada: same-origin + estilos inline (Tailwind hydrated, algunos componentes),
            // scripts solo del propio origen (Inertia manifest en public/build/*).
            // Si en el futuro sumás un CDN, agregalo aquí.
            $csp = [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https:",  // WhatsApp CDN, logos externos permitidos si son https
                "font-src 'self' data:",
                "connect-src 'self'",
                "frame-src 'self' blob:",       // preview PDF plantillas (iframe con blob URL)
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ];
            $response->headers->set('Content-Security-Policy', implode('; ', $csp));

            // HSTS: forzar HTTPS 1 año (después de verificar con el cliente que todos los subdominios están OK).
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
