<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Dropi\Exceptions\DropiSesionNoDisponible;
use App\Modules\Dropi\Models\DropiWebCuenta;
use App\Modules\Dropi\Models\DropiWebSesion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Login contra la API interna de app.dropi.co, el mismo flujo que hace su web:
 *
 *   1. POST {bff}/auth/core/beforeLoginUnknownDevice  → Dropi dice si el equipo es
 *      desconocido (isUnknownDevice) o si la cuenta pide OTP (login_otp). En ambos
 *      casos manda un código al correo de la cuenta.
 *   2. POST {bff}/auth/core/login con `otp` = ese código → devuelve un JWT.
 *
 * Después, cada llamada lleva `X-Authorization: Bearer <jwt>` (ver request()).
 * Si Dropi pide captcha o bloquea el login, queda la salida manual: pegar el
 * token de una sesión abierta en el navegador (usarTokenManual).
 *
 * OJO: se habla con el BFF (api-v2.dropi.co/bff), no con api.dropi.co/api. El
 * segundo responde 403 «Access denied» a todo lo que no sea un navegador, aun con
 * token válido (verificado el 24-sep-2026). Por eso un 403 NO se toma como sesión
 * caída; solo un 401.
 */
class DropiWebAuth
{
    public function __construct(protected array $config) {}

    public function sesion(): DropiWebSesion
    {
        return DropiWebSesion::actual($this->email());
    }

    /**
     * Paso 1. Devuelve el estado en que queda la sesión.
     */
    public function iniciar(?int $userId = null): string
    {
        $sesion = $this->sesion();
        $resp = $this->bff()->post('auth/core/beforeLoginUnknownDevice', $this->credenciales());
        $body = $resp->json() ?? [];

        if (! $resp->successful() || ! self::exito($body)) {
            return $this->fallar($sesion, 'Dropi rechazó el inicio de sesión: ' . $this->motivo($resp->status(), $body));
        }

        $data = $body['data'] ?? [];
        if (($data['isUnknownDevice'] ?? false) || ($data['login_otp'] ?? false)) {
            $sesion->update(['estado' => DropiWebSesion::ESPERANDO_CODIGO, 'ultimo_error' => null]);

            return DropiWebSesion::ESPERANDO_CODIGO;
        }

        return $this->login($sesion, null, $userId);
    }

    /**
     * Paso 2. El código que llegó al correo de la cuenta de Dropi.
     */
    public function confirmarCodigo(string $codigo, ?int $userId = null): string
    {
        $codigo = preg_replace('/\s+/', '', $codigo);

        return $this->login($this->sesion(), $codigo, $userId);
    }

    /**
     * Salida manual: token copiado de una sesión abierta en el navegador
     * (localStorage → DROPI_token). Se valida contra Dropi antes de guardarlo.
     */
    public function usarTokenManual(string $token, ?int $userId = null): string
    {
        $token = trim($token, " \t\n\r\0\x0B\"");
        $sesion = $this->sesion();

        $resp = $this->api($token)->get('orders/myorders/v2', ['result_number' => 1, 'start' => 0]);
        if ($resp->status() === 401 || ! self::exito($resp->json() ?? [])) {
            return $this->fallar($sesion, 'Dropi no aceptó ese token (HTTP ' . $resp->status() . ').');
        }

        return $this->guardarToken($sesion, $token, $userId);
    }

    public function desconectar(): void
    {
        $this->sesion()->update(['token' => null, 'expira_at' => null, 'estado' => DropiWebSesion::DESCONECTADO]);
    }

    /**
     * Cambia la cuenta de Dropi desde el CRM. Cierra la sesión de la cuenta
     * anterior (su token no debe seguir usándose) y deja la nueva desconectada,
     * a la espera de que alguien pulse «Conectar». Con la misma cuenta y la clave
     * vacía, conserva la clave guardada.
     */
    public function cambiarCuenta(string $email, ?string $password, ?int $userId = null): void
    {
        $email = strtolower(trim($email));
        $password = (string) $password;
        $actual = $this->cuenta();

        if ($password === '') {
            if ($actual['email'] === '' || strtolower($actual['email']) !== $email) {
                throw new \InvalidArgumentException('Escribe la contraseña de la cuenta nueva.');
            }
            $password = $actual['password'];
        }

        if ($actual['email'] !== '') {
            $this->desconectar();
        }

        DB::transaction(function () use ($email, $password, $userId) {
            $cuenta = DropiWebCuenta::actual() ?? new DropiWebCuenta;
            $cuenta->fill(['email' => $email, 'password' => $password, 'configurada_por' => $userId])->save();
        });

        // El freno de reconexión era de la cuenta anterior.
        Cache::forget('dropi:web:reconexion');
    }

    /**
     * Borra la cuenta del CRM y vuelve a la del .env, cerrando la sesión actual.
     */
    public function usarCuentaDelEnv(): void
    {
        if ($this->origenCuenta() === 'crm') {
            $this->desconectar();
        }
        DropiWebCuenta::query()->get()->each->delete();
        Cache::forget('dropi:web:reconexion');
    }

    /** 'crm' si la cuenta sale del CRM, 'env' si del .env, null si no hay ninguna. */
    public function origenCuenta(): ?string
    {
        if (DropiWebCuenta::actual()) {
            return 'crm';
        }

        return ($this->config['email'] ?? '') !== '' ? 'env' : null;
    }

    /**
     * La cuenta con la que se entra: la del CRM si existe, si no la del .env.
     *
     * @return array{email: string, password: string}
     */
    public function cuenta(): array
    {
        $cuenta = DropiWebCuenta::actual();
        if ($cuenta) {
            return ['email' => $cuenta->email, 'password' => (string) $cuenta->password];
        }

        return [
            'email' => (string) ($this->config['email'] ?? ''),
            'password' => (string) ($this->config['password'] ?? ''),
        ];
    }

    /**
     * Cliente HTTP autenticado contra el BFF de Dropi. Lanza si no hay sesión.
     */
    public function request(): PendingRequest
    {
        $sesion = $this->sesion();
        $token = $sesion->tokenVigente();

        if (! $token) {
            if ($sesion->estado === DropiWebSesion::CONECTADO) {
                $sesion->update(['estado' => DropiWebSesion::EXPIRADO]);
            }
            $token = $this->reconectarSolo($sesion);
        }
        if (! $token) {
            throw new DropiSesionNoDisponible(match ($sesion->estado) {
                DropiWebSesion::ESPERANDO_CODIGO => 'Dropi pidió el código del correo para reconectar. Escríbelo en Dropi → Conexión Dropi.',
                DropiWebSesion::ESPERANDO_APP => 'Dropi pidió el código de la app de autenticación para reconectar. Escríbelo en Dropi → Conexión Dropi.',
                default => 'No hay sesión activa con Dropi. Conéctala en Dropi → Conexión Dropi.',
            });
        }

        $sesion->forceFill(['ultimo_uso_at' => now()])->saveQuietly();

        return $this->api($token)->throwIf(function ($response) use ($sesion) {
            if ($response->status() === 401) {
                $sesion->update(['estado' => DropiWebSesion::EXPIRADO, 'ultimo_error' => 'Dropi cerró la sesión (HTTP 401).']);
                throw new DropiSesionNoDisponible('Dropi cerró la sesión. Vuelve a conectarla en Dropi → Conexión Dropi.');
            }

            return false;
        });
    }

    /**
     * La sesión de Dropi dura 4 h. Cuando vence (o Dropi la cierra con un 401),
     * se vuelve a entrar sola: desde un equipo que Dropi ya conoce no pide código
     * (verificado el 24-sep-2026). Si esta vez sí lo pide, se queda esperando al
     * humano y NO reintenta: cada intento le manda otro código al correo.
     * No actúa si alguien desconectó a propósito (estado desconectado).
     *
     * @return string|null el token nuevo, o null si no se pudo
     */
    protected function reconectarSolo(DropiWebSesion $sesion): ?string
    {
        if ($sesion->estado !== DropiWebSesion::EXPIRADO || $this->cuenta()['password'] === '') {
            return null;
        }
        // Un intento cada 10 min como mucho, aunque varias tareas lo pidan a la vez.
        if (! Cache::add('dropi:web:reconexion', true, 600)) {
            return null;
        }

        try {
            $estado = $this->iniciar();
        } catch (\Throwable $e) {
            Log::warning('dropi.web.reconexion_fallida', ['msg' => $e->getMessage()]);

            return null;
        }

        $sesion->refresh();
        if ($estado === DropiWebSesion::CONECTADO) {
            Cache::forget('dropi:web:reconexion');
            Log::info('dropi.web.reconectado_solo', ['expira_at' => (string) $sesion->expira_at]);

            return $sesion->tokenVigente();
        }
        if ($sesion->estado === DropiWebSesion::DESCONECTADO) {
            // Falló por red o porque Dropi rechazó el login: no es una desconexión
            //   a propósito, así que queda «vencida» y se reintenta en 10 min.
            $sesion->update(['estado' => DropiWebSesion::EXPIRADO]);
        }
        Log::warning('dropi.web.reconexion_sin_exito', ['estado' => $sesion->estado, 'error' => $sesion->ultimo_error]);

        return null;
    }

    /**
     * Cliente contra el API clásico (api.dropi.co/api), que es donde viven las
     * escrituras que usa la web (p. ej. PUT orders/myorders/{id}). Ese servidor
     * responde 403 «Access denied» si la petición no trae los encabezados de un
     * navegador (verificado el 24-sep-2026), así que se mandan.
     */
    public function requestLegacy(): PendingRequest
    {
        return $this->request()
            ->baseUrl(rtrim($this->config['legacy_url'] ?? 'https://api.dropi.co/api', '/'))
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0',
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'es-CO,es;q=0.9',
                'sec-ch-ua' => '"Chromium";v="140", "Microsoft Edge";v="140", "Not;A=Brand";v="24"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'Sec-Fetch-Site' => 'same-site',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Dest' => 'empty',
            ]);
    }

    protected function login(DropiWebSesion $sesion, ?string $codigo, ?int $userId): string
    {
        $resp = $this->bff()->post('auth/core/login', $this->credenciales() + ['otp' => $codigo, 'with_cdc' => false]);
        $body = $resp->json() ?? [];

        if (! $resp->successful() || ! self::exito($body)) {
            return $this->fallar($sesion, 'Dropi no aceptó el login: ' . $this->motivo($resp->status(), $body));
        }

        $token = $body['data']['token'] ?? null;
        if (! $token) {
            return $this->fallar($sesion, 'Dropi respondió sin token.');
        }

        $claims = $this->claims($token);
        if (($claims['token_type'] ?? null) === '2FA' && ($claims['aud'] ?? null) === 'CONFIG') {
            // Dropi responde lo mismo si la cuenta aún no configuró el 2FA y si ya lo
            //   tiene y falta el código de la app: lo distingue `objects.actived`
            //   (verificado el 26-oct-2026). Con el código, su web repite el login con
            //   `otp` = el código de la app.
            if (! ($body['data']['objects']['actived'] ?? false)) {
                return $this->fallar($sesion, 'Dropi pide configurar la verificación en dos pasos en esta cuenta. Hazlo una vez desde app.dropi.co y reintenta.');
            }
            if ($codigo !== null) {
                return $this->fallar($sesion, 'Dropi no aceptó el código de la app de autenticación. Escribe el que aparece ahora (cambia cada 30 segundos).');
            }
            $sesion->update(['estado' => DropiWebSesion::ESPERANDO_APP, 'ultimo_error' => null]);

            return DropiWebSesion::ESPERANDO_APP;
        }
        if (($claims['aud'] ?? null) === 'password_expired') {
            return $this->fallar($sesion, 'La contraseña de la cuenta de Dropi expiró. Cámbiala en app.dropi.co y escríbela aquí, en «Cuenta de Dropi».');
        }

        return $this->guardarToken($sesion, $token, $userId);
    }

    protected function guardarToken(DropiWebSesion $sesion, string $token, ?int $userId): string
    {
        $exp = $this->claims($token)['exp'] ?? null;

        $sesion->update([
            'token' => $token,
            // createFromTimestamp devuelve UTC; la columna guarda hora local sin zona.
            'expira_at' => $exp ? CarbonImmutable::createFromTimestamp($exp)->setTimezone(config('app.timezone')) : null,
            'estado' => DropiWebSesion::CONECTADO,
            'ultimo_error' => null,
            'conectado_por' => $userId,
            'conectado_at' => now(),
        ]);

        return DropiWebSesion::CONECTADO;
    }

    protected function fallar(DropiWebSesion $sesion, string $mensaje): string
    {
        Log::warning('dropi.web.login_fallido', ['motivo' => $mensaje]);
        $sesion->update([
            'estado' => in_array($sesion->estado, [DropiWebSesion::ESPERANDO_CODIGO, DropiWebSesion::ESPERANDO_APP], true) ? $sesion->estado : DropiWebSesion::DESCONECTADO,
            'ultimo_error' => $mensaje,
        ]);

        return $sesion->estado;
    }

    /** Claims del JWT sin verificar firma: solo se usan para leer exp y el tipo. */
    protected function claims(string $jwt): array
    {
        $partes = explode('.', $jwt);
        if (count($partes) < 2) {
            return [];
        }
        $json = base64_decode(strtr($partes[1], '-_', '+/'), true);

        return is_string($json) ? (json_decode($json, true) ?: []) : [];
    }

    protected function credenciales(): array
    {
        return [
            'email' => strtolower($this->email()),
            'password' => $this->cuenta()['password'],
            'white_brand_id' => (int) ($this->config['white_brand_id'] ?? 1),
            'ipAddress' => '',
        ];
    }

    protected function email(): string
    {
        $email = $this->cuenta()['email'];
        if ($email === '') {
            throw new DropiSesionNoDisponible('No hay cuenta de Dropi configurada. Escríbela en Dropi → Conexión Dropi.');
        }

        return $email;
    }

    /**
     * ¿Dropi dice que salió bien? Su BFF respondía `is_succesfull` (con la
     * errata) y en octubre de 2026 lo corrigió a `is_successful` sin aviso:
     * el login empezó a fallar con «rechazó… OK». Se aceptan las dos.
     */
    public static function exito(array $body): bool
    {
        return (bool) ($body['is_successful'] ?? $body['is_succesfull'] ?? false);
    }

    protected function motivo(int $status, array $body): string
    {
        return $body['status_reason'] ?? $body['message'] ?? ('HTTP ' . $status);
    }

    protected function bff(): PendingRequest
    {
        return $this->base()->baseUrl(rtrim($this->config['bff_url'], '/'));
    }

    protected function api(string $token): PendingRequest
    {
        return $this->base()
            ->baseUrl(rtrim($this->config['bff_url'], '/'))
            ->withHeaders(['X-Authorization' => 'Bearer ' . $token]);
    }

    /** Mismos encabezados de origen que la web de Dropi. */
    protected function base(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout((int) ($this->config['timeout'] ?? 20))
            ->withHeaders([
                'Origin' => 'https://app.dropi.co',
                'Referer' => 'https://app.dropi.co/',
            ]);
    }
}
