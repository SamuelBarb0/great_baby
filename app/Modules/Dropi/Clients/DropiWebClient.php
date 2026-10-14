<?php

namespace App\Modules\Dropi\Clients;

use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\DTOs\PagoWalletDTO;
use App\Modules\Dropi\Services\DropiWebAuth;
use App\Modules\Dropi\Services\MapearMovimientoWalletDropi;
use App\Modules\Dropi\Services\MapearPedidoDropiWeb;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Driver `web`: habla con la API interna que usa app.dropi.co, con la sesión de
 * una cuenta real (ver DropiWebAuth). Puente mientras Dropi entrega la API oficial
 * (§26); el resto del ERP no se entera porque depende del interface.
 *
 * El sync casi en tiempo real vive en SincronizarDropiWeb (tres ritmos); los
 * métodos del interface quedan para el `dropi:sync` clásico.
 */
class DropiWebClient implements DropiClientInterface
{
    public function __construct(protected DropiWebAuth $auth) {}

    public function driver(): string
    {
        return 'web';
    }

    /**
     * Una página cruda de «Mis pedidos», tal cual la devuelve Dropi.
     *
     * @return array{objects: array<int, array>, count: int|null}
     */
    public function pedidosCrudos(array $filtros = [], int $pagina = 0, int $porPagina = 50): array
    {
        $resp = $this->auth->request()->get('orders/myorders/v2', array_filter([
            'result_number' => $porPagina,
            'start' => $pagina * $porPagina,
        ] + $filtros, fn ($v) => $v !== null && $v !== ''));

        $body = $resp->json() ?? [];
        if (! $resp->successful() || ! DropiWebAuth::exito($body)) {
            throw new \RuntimeException('Dropi respondió HTTP ' . $resp->status() . ' al listar pedidos: ' . ($body['status_reason'] ?? $body['message'] ?? ''));
        }

        return [
            'objects' => $body['data']['objects'] ?? [],
            'count' => $body['data']['count'] ?? null,
        ];
    }

    /**
     * Detalle completo de un pedido (ítems con cantidad y precio de proveedor,
     * historial de estados). La lista no los trae.
     */
    public function pedidoDetalle(int $ordenId): array
    {
        $resp = $this->auth->request()->get("orders/myorders/v2/{$ordenId}", ['warranty' => 'false']);
        $body = $resp->json() ?? [];
        if (! $resp->successful() || ! DropiWebAuth::exito($body) || empty($body['data']['objects'])) {
            throw new \RuntimeException("Dropi no devolvió el pedido {$ordenId} (HTTP {$resp->status()}): " . ($body['status_reason'] ?? ''));
        }

        return $body['data']['objects'];
    }

    /**
     * Cambia el estatus del pedido EN DROPI (lo mismo que el botón «Actualizar
     * Estatus» de su web). Devuelve lo que Dropi deja en el pedido: estatus,
     * guía, transportadora y sticker. Lanza con el mensaje de Dropi si lo rechaza.
     *
     * @return array{shipping_guide: ?string, shipping_company: ?string, sticker: ?string}
     */
    public function actualizarEstatus(int $ordenId, string $estatus, ?string $motivo = null): array
    {
        $resp = $this->auth->requestLegacy()->put("orders/myorders/{$ordenId}", array_filter([
            'status' => $estatus,
            'reasonComment' => $motivo,
        ], fn ($v) => $v !== null && $v !== ''));

        $body = $resp->json() ?? [];
        if (! $resp->successful() || ! ($body['isSuccess'] ?? false)) {
            $error = $body['error_guias_generadas'][0]['error'] ?? $body['message'] ?? ('HTTP ' . $resp->status());
            throw new \RuntimeException("Dropi no cambió el pedido {$ordenId}: {$error}");
        }

        return [
            'shipping_guide' => $body['order']['shipping_guide'] ?? null,
            'shipping_company' => $body['order']['shipping_company'] ?? null,
            'sticker' => $body['order']['sticker'] ?? null,
        ];
    }

    /**
     * Etiquetas de la cuenta (las que se pueden poner a un pedido).
     *
     * @return array<int, array{id:int, nombre:string, color:?string}>
     */
    public function etiquetasCuenta(): array
    {
        $body = $this->legacyJson($this->auth->requestLegacy()->get('tags/showtagsselect'), 'listar etiquetas');

        return array_map(fn ($t) => self::normalizarEtiqueta($t), $body['objects'] ?? []);
    }

    /**
     * Etiquetas que tiene hoy el pedido. El detalle no las trae; la lista sí.
     *
     * @return array<int, array{id:int, nombre:string, color:?string}>
     */
    public function etiquetasPedido(int $ordenId): array
    {
        $o = collect($this->pedidosCrudos(['textToSearch' => (string) $ordenId], 0, 10)['objects'])
            ->first(fn ($o) => (int) ($o['id'] ?? 0) === $ordenId);

        return array_map(fn ($t) => self::normalizarEtiqueta($t), $o['tags'] ?? []);
    }

    public function crearEtiqueta(string $nombre, string $color): void
    {
        $this->legacyJson($this->auth->requestLegacy()->post('tags/addtag', ['tag_name' => $nombre, 'color' => $color]), "crear la etiqueta «{$nombre}»");
    }

    public function asignarEtiqueta(int $ordenId, int $tagId): void
    {
        $this->legacyJson($this->auth->requestLegacy()->post('tags/createorderstags', ['order_id' => $ordenId, 'tag_id' => $tagId]), "poner la etiqueta al pedido {$ordenId}");
    }

    public function quitarEtiqueta(int $ordenId, int $tagId): void
    {
        // La web de Dropi manda los datos del DELETE como query string.
        $this->legacyJson(
            $this->auth->requestLegacy()->delete('tags/deleteordertag?' . http_build_query(['tag_id' => $tagId, 'order_id' => $ordenId])),
            "quitar la etiqueta del pedido {$ordenId}",
        );
    }

    /** Respuesta del API clásico: {isSuccess, message, objects}. Lanza con el mensaje de Dropi. */
    protected function legacyJson(\Illuminate\Http\Client\Response $resp, string $que): array
    {
        $body = $resp->json() ?? [];
        if (! $resp->successful() || ! ($body['isSuccess'] ?? false)) {
            throw new \RuntimeException("Dropi no dejó {$que}: " . ($body['message'] ?? $body['error'] ?? ('HTTP ' . $resp->status())));
        }

        return $body;
    }

    /** @return array{id:int, nombre:string, color:?string} */
    protected static function normalizarEtiqueta(array $t): array
    {
        return [
            'id' => (int) ($t['id'] ?? $t['tag_id'] ?? 0),
            'nombre' => (string) ($t['tag_name'] ?? $t['name'] ?? ''),
            'color' => $t['color'] ?? $t['tag_color'] ?? null,
        ];
    }

    public function pedidosDesde(CarbonImmutable $desde): Collection
    {
        $filtros = [
            'orderBy' => 'id', 'orderDirection' => 'desc', 'filter_date_by' => 'Order Date',
            'from' => $desde->format('Y-m-d'), 'until' => now()->format('Y-m-d'),
        ];
        $mapper = app(MapearPedidoDropiWeb::class);
        $pedidos = collect();
        for ($p = 0; $p < 50; $p++) {
            $objetos = $this->pedidosCrudos($filtros, $p, 100)['objects'];
            foreach ($objetos as $o) {
                $pedidos->push($mapper->desdeDetalle($this->pedidoDetalle((int) $o['id'])));
            }
            if (count($objetos) < 100) {
                break;
            }
        }

        return $pedidos;
    }

    public function consultarGuia(string $guia): ?PedidoDropiDTO
    {
        $o = collect($this->pedidosCrudos(['textToSearch' => $guia], 0, 5)['objects'])
            ->first(fn ($o) => strcasecmp((string) ($o['shipping_guide'] ?? ''), $guia) === 0);

        return $o ? app(MapearPedidoDropiWeb::class)->desdeDetalle($this->pedidoDetalle((int) $o['id'])) : null;
    }

    /**
     * Una página cruda del historial de la wallet (GET /historywallet de la API
     * legacy, lo mismo que la pantalla «Historial de cartera» de Dropi). La
     * cuenta de Great Baby solo lo ve desde que Dropi le dio el rol nuevo.
     *
     * @param  array<string, mixed>  $filtros  from, until (AAAA-MM-DD), type, textToSearch…
     */
    public function walletCrudo(array $filtros = [], int $inicio = 0, int $cantidad = 50): array
    {
        $resp = $this->auth->requestLegacy()->get('historywallet', array_filter([
            'orderBy' => 'id',
            'orderDirection' => 'desc',
            'result_number' => $cantidad,
            'start' => $inicio,
            'textToSearch' => '',
        ] + $filtros, fn ($v) => $v !== null));

        return $this->legacyJson($resp, 'leer el historial de la wallet');
    }

    /** Catálogo de tipos de movimiento de la wallet, tal como lo usa la web de Dropi. */
    public function tiposWallet(): array
    {
        return $this->legacyJson($this->auth->requestLegacy()->get('wallet/consultconstantshistorywallets'), 'leer los tipos de la wallet');
    }

    /**
     * Movimientos de la wallet desde una fecha, del más viejo al más nuevo.
     *
     * Dropi IGNORA los filtros from/until/type de /historywallet (probado: con o
     * sin ellos devuelve el total, 55 155 al 28-sep-2026), así que se pagina
     * hacia atrás —el historial viene del más nuevo al más viejo— y se corta al
     * pasar la fecha. ~1,3 s por página de 200.
     *
     * @return Collection<int, PagoWalletDTO>
     */
    public function walletDesde(CarbonImmutable $desde): Collection
    {
        $porPagina = 200;
        $movimientos = [];

        for ($pagina = 0; $pagina < 2000; $pagina++) {
            $filas = $this->walletCrudo([], $pagina * $porPagina, $porPagina)['objects'] ?? [];

            foreach ($filas as $fila) {
                $dto = MapearMovimientoWalletDropi::map($fila);
                if ($dto->fecha->lt($desde)) {
                    return collect(array_reverse($movimientos));
                }
                $movimientos[] = $dto;
            }

            if (count($filas) < $porPagina) {
                break;
            }
        }

        return collect(array_reverse($movimientos));
    }
}
