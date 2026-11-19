<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthController extends Controller
{
    public function showLogin(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): SymfonyResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password']], (bool) ($data['remember'] ?? false))) {
            throw ValidationException::withMessages([
                'email' => 'Correo o contraseña incorrectos.',
            ]);
        }

        $request->session()->regenerate();

        $u = $request->user();
        $home = $u->rutaInicial();

        // El «Modo» de la barra del panel (Empresa / DROPI) arrancaba siempre
        //   en Empresa, también para quien sólo trabaja en Dropi. Quien
        //   aterriza en el panel arranca en DROPI; el resto, como antes.
        $request->session()->put('gb_canal', str_starts_with($home, '/admin') ? 'dropi' : 'empresa');

        // `intended` recuerda la última URL que el visitante intentó abrir, y
        // puede ser una que su rol no tiene permitida (quedaba entrando a un
        // 403 recién logueado). Sólo la respetamos para los perfiles que ven
        // todo el sistema; los demás van siempre a su pantalla de trabajo.
        $puedeDeepLink = $u->esAracely() || $u->hasRole('Gerente');
        if (! $puedeDeepLink) {
            $request->session()->forget('url.intended');
            return self::ir($home);
        }

        return self::ir($request->session()->pull('url.intended', $home));
    }

    /**
     * Redirige después del login sin que el panel quede dibujado encima.
     *
     * El formulario de login es de Inertia: un redirect común a `/admin`
     * (Filament, que no es Inertia) Inertia lo pinta en una ventana gris sobre
     * el login en vez de navegar. Fuera de `/app` hace falta una visita
     * completa (`Inertia::location`); adentro, el redirect de siempre.
     */
    public static function ir(string $destino): SymfonyResponse
    {
        $ruta = parse_url($destino, PHP_URL_PATH) ?? '/';

        return str_starts_with($ruta, '/app') ? redirect($destino) : Inertia::location($destino);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/app/login');
    }
}
