<?php

namespace App\Modules\Dropi\Clients;

use App\Modules\Dropi\Exceptions\DropiSesionNoDisponible;
use App\Modules\Dropi\Services\DropiWebAuth;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Driver `api`: la API oficial de integraciones de Dropi (§26).
 *
 * Es la misma API clásica de Dropi bajo el prefijo `/integrations/`, pero se
 * autentica con un token de integración —el de una tienda de la cuenta en
 * Dropi → My Shop— en el encabezado `dropi-integration-key`. Ese token no
 * vence a las 4 h ni pide código por correo como la sesión del driver `web`.
 * Verificado el 20-oct-2026 con la integración «LOCAL» (MASTERTOOLS) de Great
 * Baby: devuelve los pedidos de la cuenta como PROVEEDOR, con los mismos campos
 * que la web (el detalle trae cantidades, `supplier_price` e historial), así
 * que el mapeo y el sync en tres ritmos (SincronizarDropiWeb) no cambian.
 *
 * Lo que la API de integraciones NO expone (404 al 20-oct): la wallet y las
 * etiquetas. Eso, y cambiar el estatus del pedido, siguen por la sesión de la
 * cuenta que hereda de DropiWebClient.
 */
class DropiApiClient extends DropiWebClient
{
    public function __construct(DropiWebAuth $auth, protected array $config)
    {
        parent::__construct($auth);
    }

    public function driver(): string
    {
        return 'api';
    }

    /** @return array{objects: array<int, array>, count: int|null} */
    public function pedidosCrudos(array $filtros = [], int $pagina = 0, int $porPagina = 50): array
    {
        $resp = $this->integraciones()->get('orders/myorders', array_filter([
            'result_number' => $porPagina,
            'start' => $pagina * $porPagina,
        ] + $filtros, fn ($v) => $v !== null && $v !== ''));

        $body = $this->cuerpo($resp, 'listar pedidos');

        return ['objects' => $body['objects'] ?? [], 'count' => $body['count'] ?? null];
    }

    public function pedidoDetalle(int $ordenId): array
    {
        $body = $this->cuerpo($this->integraciones()->get("orders/myorders/{$ordenId}"), "leer el pedido {$ordenId}");
        if (empty($body['objects']) || array_is_list($body['objects'])) {
            throw new \RuntimeException("Dropi no devolvió el pedido {$ordenId}.");
        }

        return $body['objects'];
    }

    /**
     * Una página del catálogo de productos (POST `products/index`, el mismo que
     * usa el plugin oficial de WooCommerce para listar productos de Dropi).
     *
     * Verificado el 6-nov-2026: `startData` es un DESPLAZAMIENTO (no un número
     * de página), Dropi corta en 40 por página aunque se pidan más, y `count`
     * vuelve en 0: se pagina hasta que una página llega incompleta. Con
     * `warehouse_id` devuelve sólo lo de esa bodega.
     *
     * @return array<int, array>
     */
    public function productosCrudos(int $desde = 0, int $porPagina = 40, array $filtros = []): array
    {
        // Plazo propio, más largo que el de pedidos: el 6-nov Dropi tardó 27 s
        // en devolver 5 productos. El catálogo se lee en segundo plano, así
        // que esperar no le cuesta nada a nadie; cortar a los 30 s sí.
        $resp = $this->integraciones()
            ->timeout((int) ($this->config['timeout_catalogo'] ?? 120))
            ->post('products/index', array_filter([
            'startData' => $desde,
            'pageSize' => $porPagina,
            'order_type' => 'desc',
            'order_by' => 'id',
            'keywords' => '',
            'active' => true,
            'no_count' => true,
            'integration' => true,
            'get_stock' => false,
        ] + $filtros, fn ($v) => $v !== null));

        return $this->cuerpo($resp, 'listar productos')['objects'] ?? [];
    }

    protected function integraciones(): PendingRequest
    {
        $url = (string) ($this->config['url'] ?? '');
        $key = (string) ($this->config['key'] ?? '');
        if ($url === '' || $key === '') {
            throw new DropiSesionNoDisponible(
                'Falta el token de integración de Dropi: configure DROPI_API_URL y DROPI_API_KEY en el .env.'
            );
        }

        return Http::baseUrl(rtrim($url, '/').'/')
            ->withHeaders(['dropi-integration-key' => $key])
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 30))
            // La API de integraciones limita las peticiones por minuto (429 «Too
            // Many Attempts», visto en la primera vuelta del 20-oct con ~50
            // detalles seguidos): se espera lo que diga Retry-After y se reintenta.
            // También 5xx sueltos en páginas pesadas.
            ->retry(3, fn (int $intento, \Throwable $e) => self::esperaMs($e), function (\Throwable $e) {
                return $e instanceof \Illuminate\Http\Client\ConnectionException
                    || ($e instanceof \Illuminate\Http\Client\RequestException
                        && ($e->response->status() === 429 || $e->response->serverError()));
            }, throw: false);
    }

    /** Milisegundos a esperar antes de reintentar: Retry-After de Dropi (tope 30 s) o medio segundo. */
    public static function esperaMs(\Throwable $e): int
    {
        if ($e instanceof \Illuminate\Http\Client\RequestException && $e->response->status() === 429) {
            $s = (int) ($e->response->header('Retry-After') ?: 5);

            return min(max($s, 1), 30) * 1000;
        }

        return 500;
    }

    /** {isSuccess, message, objects}. Un 401/403 es el token: se trata como sesión caída. */
    protected function cuerpo(Response $resp, string $que): array
    {
        if (in_array($resp->status(), [401, 403], true)) {
            throw new DropiSesionNoDisponible(
                "Dropi rechazó el token de integración al {$que} (HTTP {$resp->status()}): revíselo en Dropi → My Shop."
            );
        }

        $body = $resp->json() ?? [];
        if (! $resp->successful() || ! ($body['isSuccess'] ?? false)) {
            throw new \RuntimeException("Dropi no dejó {$que}: ".($body['message'] ?? ('HTTP '.$resp->status())));
        }

        return $body;
    }
}
