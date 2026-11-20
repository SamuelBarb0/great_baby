<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La contraseña del ERP: cambiarla, y recuperarla cuando se olvida.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTE CONTROLADOR NO EXISTÍA Y HACÍA FALTA
 *
 * El ERP no tenía NINGUNA pantalla de contraseña. Ni «olvidé la mía» ni
 * cambiar la propia estando adentro. Lo único era el enlace de activación que
 * Gestión Humana manda una vez, al entrar a la empresa.
 *
 * Eso significa tres cosas en el día a día:
 *
 *   · quien olvida su clave tiene que escribirle a alguien de sistemas para
 *     que se la cambie a mano, y esa persona termina ESCOGIÉNDOLE la clave
 *     —y conociéndola—;
 *   · quien sospecha que alguien vio su clave no puede hacer nada al
 *     respecto;
 *   · una clave repartida en una demo se queda puesta para siempre, porque
 *     nadie tiene cómo cambiarla.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * LO QUE SE COPIA DEL PORTAL B2B, QUE YA LO TENÍA RESUELTO
 *
 *   · la respuesta al «olvidé» es SIEMPRE la misma, exista o no el correo:
 *     decir «ese correo no está registrado» le confirma a quien prueba una
 *     lista cuáles aciertan;
 *   · el enlace va firmado Y con un token de un solo uso en caché, con una
 *     hora de vida: la firma impide fabricarlo, el token impide reusarlo;
 *   · un intento por correo cada cinco minutos, para que el buzón de alguien
 *     no se pueda inundar desde la pantalla pública.
 */
class ClaveController extends Controller
{
    /** Cuánto vive el enlace de restablecer. */
    private const VIDA_ENLACE_MINUTOS = 60;

    // ───────────────────────────── estando adentro ─────────────────────────

    public function miClave(): Response
    {
        return Inertia::render('Auth/MiClave');
    }

    /**
     * Cambiar la propia contraseña.
     *
     * Pide la actual. No es burocracia: sin eso, una sesión abierta y olvidada
     * en un computador compartido deja a cualquiera cambiarle la clave al
     * dueño y quedarse con la cuenta.
     */
    public function cambiar(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'actual' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:actual'],
        ], [
            'password.different' => 'La contraseña nueva tiene que ser distinta de la actual.',
        ], [
            'actual' => 'contraseña actual',
            'password' => 'contraseña nueva',
        ]);

        $u = $r->user();

        if (! Hash::check($data['actual'], $u->password)) {
            // Se cuenta el intento fallido: probar la clave actual a ciegas es
            // una forma de adivinarla desde una sesión robada.
            Log::channel(
                array_key_exists('audit', config('logging.channels') ?? []) ? 'audit' : 'stack'
            )->warning('auth.clave.actual_incorrecta', [
                'user_id' => $u->id, 'ip' => $r->ip(),
            ]);

            throw ValidationException::withMessages([
                'actual' => 'Esa no es tu contraseña actual.',
            ]);
        }

        $u->forceFill(['password' => Hash::make($data['password'])])->save();

        /*
         * Las demás sesiones se caen.
         *
         * Quien cambia su contraseña casi siempre lo hace porque cree que
         * alguien más la tiene. Dejar viva la sesión de ese alguien convierte
         * el cambio en un gesto sin efecto.
         */
        Auth::logoutOtherDevices($data['password']);

        return back()->with('success', 'Listo, tu contraseña cambió. Las demás sesiones se cerraron.');
    }

    // ──────────────────────────── sin sesión ───────────────────────────────

    public function olvide(): Response
    {
        return Inertia::render('Auth/Olvide');
    }

    /**
     * Manda el enlace para poner una contraseña nueva.
     *
     * Responde lo mismo exista o no el correo. Quien escriba una lista de
     * direcciones no va a poder distinguir cuáles son de la empresa.
     */
    public function enviarEnlace(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'email' => ['required', 'email', 'max:190'],
        ], [], ['email' => 'correo']);

        $llave = 'app.olvide:'.md5(mb_strtolower($data['email']));

        // Uno cada cinco minutos por correo.
        if (Cache::add($llave, 1, 300)) {
            $u = User::where('email', $data['email'])->first();

            if ($u) {
                $this->mandarEnlace($u);
            }
        }

        return back()->with('success',
            'Si ese correo tiene una cuenta, le acabamos de mandar un enlace. Revisá también el correo no deseado.');
    }

    public function restablecerForm(Request $r): Response
    {
        return Inertia::render('Auth/Restablecer', [
            'token' => (string) $r->query('token'),
            'email' => (string) $r->query('email'),
            // La firma la comprueba el middleware de la ruta; si llegó acá,
            // el enlace no fue fabricado. Falta sólo que el token siga vivo.
            'vigente' => Cache::has($this->llaveDelToken((string) $r->query('token'))),
        ]);
    }

    public function restablecer(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [], ['password' => 'contraseña']);

        // `pull` lo borra al leerlo: el enlace sirve una sola vez.
        $userId = Cache::pull($this->llaveDelToken($data['token']));

        if (! $userId) {
            throw ValidationException::withMessages([
                'token' => 'Este enlace ya se usó o se venció. Pedí uno nuevo.',
            ]);
        }

        $u = User::where('id', $userId)->where('email', $data['email'])->first();

        if (! $u) {
            throw ValidationException::withMessages([
                'email' => 'El enlace no corresponde a esta cuenta.',
            ]);
        }

        $u->forceFill([
            'password' => Hash::make($data['password']),
            // Si la clave se recupera es porque se perdió el control de la
            // cuenta: toda sesión viva deja de valer.
            'remember_token' => Str::random(60),
        ])->save();

        Log::channel(
            array_key_exists('audit', config('logging.channels') ?? []) ? 'audit' : 'stack'
        )->info('auth.clave.restablecida', ['user_id' => $u->id, 'ip' => $r->ip()]);

        return redirect()->route('app.login')
            ->with('success', 'Contraseña cambiada. Entrá con la nueva.');
    }

    // ───────────────────────────── internos ────────────────────────────────

    private function llaveDelToken(string $token): string
    {
        return 'app.reset:'.hash('sha256', $token);
    }

    private function mandarEnlace(User $u): void
    {
        $token = Str::random(64);

        Cache::put($this->llaveDelToken($token), $u->id, now()->addMinutes(self::VIDA_ENLACE_MINUTOS));

        $url = URL::temporarySignedRoute(
            'app.clave.restablecer.form',
            now()->addMinutes(self::VIDA_ENLACE_MINUTOS),
            ['token' => $token, 'email' => $u->email],
        );

        try {
            Mail::raw(
                "Hola {$u->name},\n\n"
                ."Alguien pidió cambiar la contraseña de tu cuenta del ERP de Great Baby.\n\n"
                ."Abrí este enlace para poner una nueva. Vence en una hora y sirve una sola vez:\n{$url}\n\n"
                ."Si no fuiste vos, no hagas nada: tu contraseña sigue igual.",
                fn ($m) => $m->to($u->email)->subject('Cambiar tu contraseña · ERP Great Baby'),
            );
        } catch (\Throwable $e) {
            /*
             * Si el correo no sale, la pantalla NO cambia de respuesta.
             *
             * Decir «no se pudo enviar» sólo para las direcciones que existen
             * delataría cuáles existen, que es justo lo que el mensaje único
             * evita. Queda en el log, que es donde lo va a buscar quien
             * administra.
             */
            Log::warning('[app.olvide] no salió el correo', [
                'user_id' => $u->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
