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
        //
    })->create();
