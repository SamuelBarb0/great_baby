<?php

namespace App\Modules\Siigo\Clients;

use App\Modules\Siigo\Exceptions\SiigoRateLimitedException;
use App\Modules\Siigo\Models\SiigoConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Modules\Siigo\Exceptions\CredencialSiigoInvalida;
use RuntimeException;

/**
 * Cliente HTTP para la API oficial de SIIGO.
 *
 * Reutilizado del demo greatbaby con integración probada.
 * Documentación: https://developers.siigo.com/
 *
 * - Auth con OAuth simple (POST /auth con username + access_key → access_token 24h)
 * - Base URL: https://api.siigo.com/v1/...
 * - Reintento automático al 401 (token expirado) y al 429 (rate limit con backoff)
 * - Sanitización de logs (redacta tokens y access_keys)
 * - Header Partner-Id obligatorio si viene configurado
 */
class SiigoClient
{
    private const BASE_URL = 'https://api.siigo.com';
    private const AUTH_URL = 'https://api.siigo.com/auth';
    private const TIMEOUT_SEGUNDOS = 30;
    private const MAX_REINTENTOS = 3;

    public function __construct(private readonly SiigoConfig $config) {}

    /** Multiempresa · qué cuenta de SIIGO usa este cliente (una por razón social). */
    public function configId(): int
    {
        return (int) $this->config->id;
    }

    public function authenticate(): string
    {
        // Modo FAKE · para demo/pruebas sin credenciales SIIGO reales.
        // Activar con SIIGO_DRIVER=fake en .env. Devuelve un token dummy
        // vigente 24h y todos los request() responden con IDs simulados.
        if (config('siigo.driver', 'real') === 'fake') {
            // Deja constancia igual que el modo real. Sin esto, el driver
            // falso autenticaba «bien» pero `ultimo_auth_ok` se quedaba como
            // estuviera: la pantalla seguía diciendo que la credencial está
            // muerta y el middleware aplazaba todos los push, en una
            // instalación donde por definición nada puede fallar.
            $this->registrarAuth(true);

            return 'fake_token_greatbaby_' . now()->format('Ymd');
        }

        if ($this->tokenEsVigente()) {
            return (string) $this->config->token_cache;
        }

        if (empty($this->config->username) || empty($this->config->access_key)) {
            $this->registrarAuth(false, 'Credenciales SIIGO no configuradas.');

            throw new CredencialSiigoInvalida('Credenciales SIIGO no configuradas.');
        }

        $response = Http::timeout(self::TIMEOUT_SEGUNDOS)
            ->acceptJson()->asJson()
            ->post(self::AUTH_URL, [
                'username' => $this->config->username,
                'access_key' => $this->config->access_key,
            ]);

        if ($response->failed()) {
            Log::channel('siigo')->error('SIIGO auth failed', [
                'status' => $response->status(),
                'body' => $this->sanitizarRespuesta($response->body()),
            ]);

            // Se deja constancia del fallo para que la pantalla de integración
            // muestre el estado REAL. Antes decía «Activa» en verde con las
            // credenciales vencidas y nadie se enteraba de que no salía nada.
            $this->registrarAuth(false, 'HTTP '.$response->status().' · '.$this->motivoDe($response));

            throw new CredencialSiigoInvalida('Fallo autenticando contra SIIGO: HTTP '.$response->status());
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        $this->config->forceFill([
            'token_cache' => $token,
            'token_expires_at' => now()->addSeconds(max(60, $expiresIn - 30)),
            'ultimo_auth_ok' => true,
            'ultimo_auth_at' => now(),
            'ultimo_auth_error' => null,
        ])->save();

        return $token;
    }

    /**
     * Deja escrito el resultado del último intento de autenticación.
     *
     * Es lo que alimenta la tarjeta «Estado» de la pantalla de integración, que
     * antes leía la casilla `activo` y por eso mostraba verde aunque SIIGO
     * estuviera devolviendo 401 en cada llamada.
     *
     * Nunca tumba la operación: si por lo que sea no se puede escribir, el
     * error original tiene que seguir su camino.
     */
    private function registrarAuth(bool $ok, ?string $error = null): void
    {
        try {
            $this->config->forceFill([
                'ultimo_auth_ok' => $ok,
                'ultimo_auth_at' => now(),
                'ultimo_auth_error' => $error ? mb_substr($error, 0, 200) : null,
            ])->save();
        } catch (\Throwable) {
            // columna aún sin migrar o BD de solo lectura · no es crítico
        }
    }

    /** Motivo legible que manda SIIGO, sin exponer la credencial. */
    private function motivoDe(\Illuminate\Http\Client\Response $r): string
    {
        $msg = $r->json('errors.0.message') ?? $r->json('message') ?? '';

        return $msg !== ''
            ? (string) $msg
            : 'SIIGO rechazó las credenciales. Revisá usuario y access key en el portal de SIIGO.';
    }

    /**
     * Ejecuta un request autenticado con reintento inteligente.
     *
     * @param  array<string, mixed>  $payload
     * @param  ?string  $idempotencyKey  Opcional · clave estable del documento
     *     origen (ej. "dev:1"). Se hashea a ≤28 chars (SIIGO exige ≤30 y
     *     alfanumérico sin especiales). Si el reintento repite la misma clave,
     *     SIIGO devuelve el documento previo en vez de duplicarlo. Solo tiene
     *     efecto en POST a /v1/journals, /v1/invoices, /v1/credit-notes y
     *     /v1/vouchers (en /purchases y /payment-receipts SIIGO no lo soporta,
     *     el header se envía sin efecto pero no rompe).
     */
    public function request(string $method, string $path, array $payload = [], int $intento = 1, ?string $idempotencyKey = null): Response
    {
        // Modo FAKE · responde OK simulando SIIGO sin tocar la API real.
        // Perfecto para demos con el cliente antes de tener credenciales.
        if (config('siigo.driver', 'real') === 'fake') {
            return $this->fakeResponse(strtoupper($method), $path, $payload);
        }

        $token = $this->authenticate();
        $method = strtoupper($method);
        $url = rtrim(self::BASE_URL, '/').'/'.ltrim($path, '/');

        $sinCuerpo = in_array($method, ['GET', 'DELETE'], true);

        // Los callers escriben el path con la query ya puesta
        // (ej. `/v1/document-types?type=FV`). La separamos para que Laravel la
        // mande como parámetros y no la duplique al fusionarla con $payload.
        $query = [];
        if ($sinCuerpo && str_contains($url, '?')) {
            [$url, $qs] = explode('?', $url, 2);
            parse_str($qs, $query);
        }

        $pending = $this->cliente($token, ! $sinCuerpo);

        // SIIGO exige Idempotency-Key alfanumérico ≤30 chars; UUID (36) es rechazado.
        if ($method === 'POST' && $idempotencyKey !== null && $idempotencyKey !== '') {
            $safeKey = 'gb'.substr(sha1($idempotencyKey), 0, 28); // 2+28=30
            $pending = $pending->withHeaders(['Idempotency-Key' => $safeKey]);
        }

        $response = match ($method) {
            'GET' => $pending->get($url, array_merge($query, $payload)),
            'POST' => $pending->post($url, $payload),
            'PUT' => $pending->put($url, $payload),
            'PATCH' => $pending->patch($url, $payload),
            'DELETE' => $pending->delete($url, array_merge($query, $payload)),
            default => throw new RuntimeException("Método HTTP no soportado: {$method}"),
        };

        if ($response->status() === 401 && $intento === 1) {
            $this->forgetToken();
            return $this->request($method, $path, $payload, $intento + 1);
        }

        // B3-M1 · en 429 lanzamos excepción tipada · el Job la captura y hace
        // release al worker (no dormimos aquí para no bloquear otros jobs
        // 30 s enteros usando la mitad del timeout=60).
        if ($response->status() === 429) {
            $espera = (int) ($response->header('Retry-After') ?: pow(2, $intento));
            Log::channel('siigo')->warning('SIIGO rate limit → release al worker', [
                'path' => $path, 'espera_segundos' => $espera,
            ]);
            throw new SiigoRateLimitedException(min(max($espera, 5), 300), $path);
        }

        if ($response->failed()) {
            Log::channel('siigo')->error('SIIGO request failed', [
                'method' => $method, 'path' => $path,
                'status' => $response->status(),
                'body' => $this->sanitizarRespuesta($response->body()),
            ]);
            $this->registrarCuentaRechazada($response);
        }

        return $response;
    }

    /**
     * Simula respuestas SIIGO para modo demo/pruebas sin credenciales reales.
     * Devuelve IDs falsos pero consistentes (mismo path → mismo id) para que
     * la UI marque las entidades como "sincronizadas" y podamos ver el flujo
     * completo end-to-end.
     */
    private function fakeResponse(string $method, string $path, array $payload = []): Response
    {
        Log::channel('single')->info('[SIIGO FAKE] '.$method.' '.$path, ['payload_keys' => array_keys($payload)]);

        // GETs de descubrimiento (document-types, payment-types, warehouses, accounts…)
        if ($method === 'GET') {
            $fixtures = [
                '/v1/document-types' => [
                    ['id' => 24446, 'code' => 'FV', 'name' => 'Factura de Venta', 'type' => 'FV'],
                    ['id' => 27524, 'code' => 'NC', 'name' => 'Nota Crédito', 'type' => 'NC'],
                    ['id' => 27525, 'code' => 'ND', 'name' => 'Nota Débito', 'type' => 'ND'],
                    ['id' => 27600, 'code' => 'FC', 'name' => 'Factura de Compra', 'type' => 'FC'],
                    ['id' => 27700, 'code' => 'RC', 'name' => 'Recibo de Caja', 'type' => 'RC'],
                    ['id' => 27800, 'code' => 'CE', 'name' => 'Comprobante Egreso', 'type' => 'CE'],
                ],
                '/v1/payment-types' => [
                    ['id' => 5636, 'name' => 'Efectivo', 'type' => 'DebtPayment'],
                    ['id' => 5637, 'name' => 'Transferencia', 'type' => 'DebtPayment'],
                    ['id' => 5638, 'name' => 'Consignación', 'type' => 'DebtPayment'],
                ],
                '/v1/warehouses' => [
                    ['id' => 'wh-01', 'name' => 'Bodega Principal', 'active' => true],
                    ['id' => 'wh-02', 'name' => 'Punto de Venta', 'active' => true],
                ],
                '/v1/taxes' => [
                    ['id' => 13156, 'name' => 'IVA 19%', 'type' => 'IVA', 'percentage' => 19],
                    ['id' => 13157, 'name' => 'IVA 0%', 'type' => 'IVA', 'percentage' => 0],
                ],
            ];
            foreach ($fixtures as $prefix => $data) {
                if (str_starts_with($path, $prefix)) {
                    return new Response(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($data)));
                }
            }
            return new Response(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([])));
        }

        // POST/PUT/PATCH → simula creación exitosa con id derivado del path + timestamp.
        $fakeId = 'fake-' . substr(md5($path . microtime(true)), 0, 12);
        $body = [
            'id' => $fakeId,
            'metadata' => [
                'created' => now()->toIso8601String(),
                'last_updated' => now()->toIso8601String(),
            ],
        ];
        // Para NC/ND/FV que devuelven CUFE.
        if (str_contains($path, '/invoices') || str_contains($path, '/credit-notes') || str_contains($path, '/debit-notes')) {
            $body['stamp'] = ['cufe' => 'FAKE-' . strtoupper(substr(md5(microtime(true)), 0, 40))];
        }
        return new Response(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
    }

    /**
     * @param  bool  $conCuerpoJson  Sólo para métodos que mandan payload. En GET
     *     y DELETE NO debe activarse: `asJson()` fija `Content-Type: application/json`
     *     y Laravel manda un cuerpo vacío `[]`, que SIIGO responde con HTTP 400.
     *     Ese era el motivo de que `/v1/document-types?type=FV` fallara desde el
     *     ERP y funcionara con un curl normal.
     */
    private function cliente(string $token, bool $conCuerpoJson = true): PendingRequest
    {
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];

        if (! empty($this->config->partner_id)) {
            $headers['Partner-Id'] = (string) $this->config->partner_id;
        }

        $req = Http::withHeaders($headers)
            ->timeout(self::TIMEOUT_SEGUNDOS)
            ->acceptJson();

        return $conCuerpoJson ? $req->asJson() : $req;
    }

    private function tokenEsVigente(): bool
    {
        if (empty($this->config->token_cache) || $this->config->token_expires_at === null) {
            return false;
        }
        return $this->config->token_expires_at->isFuture();
    }

    private function forgetToken(): void
    {
        $this->config->forceFill([
            'token_cache' => null, 'token_expires_at' => null,
        ])->save();
    }

    /**
     * Deja registrada la cuenta contable que SIIGO acaba de rechazar.
     *
     * SIIGO es el único que sabe qué cuentas acepta (no publica su plan de
     * cuentas por API), y ese dato se perdía dentro del log técnico de un job:
     * la contadora veía "falló" sin saber qué mapear. Guardándolo en
     * `siigo_sync_log` con recurso `cuentas_rechazadas`, la pantalla de
     * validación PUC puede listar exactamente qué cuentas hay que mapear.
     */
    private function registrarCuentaRechazada(Response $response): void
    {
        $json = $response->json();
        if (! is_array($json)) {
            return;
        }

        $errores = $json['Errors'] ?? $json['errors'] ?? [];
        foreach ($errores as $e) {
            $code = mb_strtolower((string) ($e['Code'] ?? $e['code'] ?? ''));
            if (! in_array($code, ['account_not_allowed', 'invalid_reference', 'account_settings'], true)) {
                continue;
            }

            $mensaje = (string) ($e['Message'] ?? $e['message'] ?? '');
            // Los mensajes traen el código entre texto: "The code 11100501 cannot be used…"
            if (! preg_match('/\b(\d{4,12})\b/', $mensaje, $m)) {
                continue;
            }

            // Un tercero que no existe también cae acá, porque el NIT es una
            // cifra larga y el código del error es el mismo. Se guardaba como
            // «SIIGO no acepta la cuenta 901738354», y la pantalla de
            // validación PUC le pedía a la contadora mapear un NIT como si
            // fuera una cuenta del plan. Se separan: lo que SIIGO llama
            // `customer` es un tercero, no una cuenta.
            $esTercero = stripos($mensaje, 'customer') !== false;

            try {
                \App\Modules\Siigo\Models\SiigoSyncLog::create([
                    'recurso' => $esTercero ? 'terceros_rechazados' : 'cuentas_rechazadas',
                    'estado' => 'fallido',
                    'mensaje' => $esTercero
                        ? "SIIGO no conoce el tercero {$m[1]}: {$mensaje}"
                        : "SIIGO no acepta la cuenta {$m[1]}: {$mensaje}",
                    'detalle' => [$esTercero ? 'tercero' : 'cuenta' => $m[1], 'code' => $code],
                ]);
            } catch (\Throwable) {
                // Nunca romper la petición original por no poder dejar el registro.
            }
        }
    }

    /**
     * B4-M3 · redacta secretos en cualquier respuesta antes de loggear/persistir.
     * Cubre access_token/access_key/refresh_token en JSON (a cualquier nivel de
     * anidamiento) y "Authorization: Bearer …" en headers/body plano.
     */
    public function sanitizarRespuesta(string $body): string
    {
        $body = preg_replace('/"access_token"\s*:\s*"[^"]*"/i', '"access_token":"[REDACTED]"', $body) ?? $body;
        $body = preg_replace('/"access_key"\s*:\s*"[^"]*"/i',   '"access_key":"[REDACTED]"',   $body) ?? $body;
        $body = preg_replace('/"refresh_token"\s*:\s*"[^"]*"/i','"refresh_token":"[REDACTED]"',$body) ?? $body;
        $body = preg_replace('/Bearer\s+[A-Za-z0-9\.\-_=]+/',   'Bearer [REDACTED]',            $body) ?? $body;
        return mb_substr($body, 0, 1500);
    }

    /**
     * B4-M3 · versión array de la sanitización · usada por las Actions al
     * persistir `response_body` en siigo_sync_log (antes iba JSON puro sin filtro).
     * Retorna un array con las claves sensibles reemplazadas por [REDACTED].
     */
    public function sanitizarRespuestaArray(?array $body): ?array
    {
        if ($body === null) return null;

        array_walk_recursive($body, function (&$v, $k) {
            if (! is_string($k)) return;
            if (in_array(strtolower($k), ['access_token', 'access_key', 'refresh_token', 'authorization'], true)) {
                $v = '[REDACTED]';
            }
        });

        return $body;
    }

    /** @return array{ok: bool, mensaje: string} */
    public function probarConexion(): array
    {
        try {
            $this->forgetToken();
            $this->authenticate();
            return ['ok' => true, 'mensaje' => 'Conexión exitosa con SIIGO ('.$this->config->ambiente.').'];
        } catch (RuntimeException $e) {
            Log::channel('siigo')->warning('SIIGO probarConexion fallo controlado', ['mensaje' => $e->getMessage()]);
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        } catch (RequestException|\Throwable $e) {
            Log::channel('siigo')->error('SIIGO probarConexion excepción', [
                'mensaje' => $e->getMessage(), 'clase' => $e::class,
            ]);
            return ['ok' => false, 'mensaje' => 'Error inesperado al contactar SIIGO. Revisa los logs.'];
        }
    }
}
