<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user('web');
        $cliente = $request->user('cliente');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'roles' => $user->getRoleNames()->all(),
                    'es_aracely' => method_exists($user, 'esAracely') && $user->esAracely(),
                ] : null,
                // Qué puede abrir en el menú. Se calcula con la misma expresión
                // que usa el controller de cada ruta (ver App\Auth\MenuApp), así
                // el sidebar no puede ofrecer algo que después responda 403 —
                // que era lo que pasaba con las listas de roles del Vue.
                'menu' => $user ? \App\Auth\MenuApp::clavesDe($user) : [],
                'cliente' => $cliente ? [
                    'id' => $cliente->id,
                    'email' => $cliente->email,
                    'nombre_display' => $cliente->razon_social ?: $cliente->nombre_completo,
                    'lista_precios_id' => $cliente->lista_precios_id,
                ] : null,
            ],
            // C-QA-D-8: badge de pedidos B2B nuevos para Aracely (lazy prop, sólo se evalúa si el layout la lee).
            'badges' => [
                'pedidos_b2b_pendientes' => fn () => $user?->esAracely()
                    ? (int) \App\Modules\Portal\Models\PedidoCliente::where('estado', 'enviado')->count()
                    : 0,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
                'empaqueResultado' => fn () => $request->session()->get('empaqueResultado'),
                'errores' => fn () => $request->session()->get('errores'),
                'importResumen' => fn () => $request->session()->get('importResumen'),
                // F7 · Enlace de activación del portal del colaborador. Se
                //   muestra una sola vez, para que Gestión Humana lo copie y
                //   se lo mande a la persona por WhatsApp.
                'enlace_activacion' => fn () => $request->session()->get('enlace_activacion'),
                // Enlace al Excel que SIIGO acaba de generar (balance de prueba).
                'siigo_reporte_url' => fn () => $request->session()->get('siigo_reporte_url'),
            ],
            'app' => [
                'name' => config('app.name'),
            ],
        ];
    }
}
