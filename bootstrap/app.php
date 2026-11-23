<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\SecurityHeaders::class, // QA-final #6
        ]);

        /*
         * Cada puerta manda a SU login.
         *
         * Hay una ruta puente que manda todo lo no autenticado a /app/login,
         * «el único login real», y para el equipo está bien: una sola puerta
         * de entrada. Pero el portal tiene su propia puerta y sus propias
         * credenciales, así que un cliente con la sesión vencida aterrizaba en
         * la pantalla del personal —que dice «SISTEMA ERP»— donde su correo no
         * sirve y su clave tampoco. Desde ahí no hay forma de volver: no hay
         * link al portal.
         *
         * Lo que el cliente concluye es que le quitaron el acceso, y la
         * llamada entra por el WhatsApp comercial.
         */
        $middleware->redirectGuestsTo(fn ($request) => $request->is('portal', 'portal/*')
            ? route('portal.login')
            : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * 405 · «Method Not Allowed» (23-nov-2026).
         *
         * A la clienta le salió una página en inglés («Oops! An Error
         * Occurred») al ir de crear un tercero a crear la compra, en la
         * dirección interna de Livewire. Laravel no anota los 405, así que no
         * había cómo saber qué pasó. Ahora:
         *   · queda anotado en el log con quién, desde qué pantalla y cómo;
         *   · la persona ve qué pasó y un botón para volver a donde estaba.
         * Lo típico: la página quedó abierta desde antes de una actualización,
         * o se usó «atrás»/recargar sobre una dirección interna.
         */
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e, \Illuminate\Http\Request $request) {
            \Illuminate\Support\Facades\Log::warning('405 · método no permitido', [
                'metodo' => $request->method(),
                'url' => $request->fullUrl(),
                'desde' => $request->headers->get('referer'),
                'usuario' => $request->user()?->email,
                'livewire' => $request->hasHeader('X-Livewire'),
                'agente' => mb_substr((string) $request->userAgent(), 0, 160),
            ]);

            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return response()->json(['message' => 'La página quedó desactualizada. Recárguela e intente de nuevo.'], 405);
            }

            // Volver a la pantalla de donde venía, si es de este mismo sitio
            // y no es otra dirección interna; si no, al inicio del panel.
            $desde = (string) $request->headers->get('referer');
            $volver = str_starts_with($desde, $request->getSchemeAndHttpHost()) && ! str_contains($desde, '/livewire')
                ? $desde
                : (str_starts_with($request->path(), 'app') ? '/app' : '/admin');

            return response()->view('errors.desactualizada', ['volver' => $volver], 405);
        });
    })->create();
