<?php

/**
 * 1.5 · Conector a la API oficial de integraciones de Dropi.
 *
 * La API es la clásica bajo `/integrations/`, autenticada con el token de una
 * tienda en el encabezado `dropi-integration-key` (no `Bearer`, que es lo que
 * mandaba el driver viejo). Lee los pedidos con los mismos campos que la web,
 * así que el sync en tres ritmos funciona igual.
 */

use App\Modules\Dropi\Actions\SincronizarDropiWeb;
use App\Modules\Dropi\Clients\DropiApiClient;
use App\Modules\Dropi\Clients\DropiClientInterface;
use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Exceptions\DropiSesionNoDisponible;
use App\Modules\Dropi\Models\DropiPedido;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const URL_INTEGRACIONES = 'https://api.dropi.co/integrations/';

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    config([
        'dropi.driver' => 'api',
        'dropi.api' => ['url' => URL_INTEGRACIONES, 'key' => 'tok-prueba', 'timeout' => 5],
    ]);
    app()->forgetInstance(DropiClientInterface::class);
    app()->forgetInstance(DropiApiClient::class);
});

function ordenDropi(int $id, string $status = 'PENDIENTE', ?string $guia = null): array
{
    return [
        'id' => $id, 'status' => $status, 'shipping_guide' => $guia, 'shipping_company' => $guia ? 'SERVIENTREGA' : null,
        'total_order' => 99900, 'name' => 'Ana', 'surname' => 'Ruiz', 'phone' => '300', 'dir' => 'Cra 1', 'city' => 'BOGOTA',
        'state' => 'CUNDINAMARCA', 'created_at' => '2026-10-20T09:28:40', 'shop' => ['name' => 'Tienda'],
        'user' => ['name' => 'Vend', 'surname' => 'Edor'], 'dropshipper_amount_to_win' => 20000, 'shipping_amount' => 12000,
        'tags' => [],
        'orderdetails' => [[
            'quantity' => 2, 'supplier_price' => 45000, 'variation_id' => 777,
            'product' => ['name' => 'Coche paseador'], 'variation' => null,
        ]],
        'history' => $guia ? [['status' => 'EN TRANSITO', 'created_at' => '2026-10-20T15:00:00']] : [],
    ];
}

it('el driver api queda enlazado al contrato y al cliente que usa el sync', function () {
    expect(app(DropiClientInterface::class))->toBeInstanceOf(DropiApiClient::class)
        ->and(app(DropiWebClient::class))->toBeInstanceOf(DropiApiClient::class)
        ->and(app(DropiClientInterface::class)->driver())->toBe('api');

    config(['dropi.driver' => 'web']);
    expect(app(DropiWebClient::class))->not->toBeInstanceOf(DropiApiClient::class);
});

it('manda el token en dropi-integration-key, no como Bearer', function () {
    Http::fake([URL_INTEGRACIONES.'orders/myorders*' => Http::response(['isSuccess' => true, 'objects' => [ordenDropi(1)]])]);

    $r = app(DropiApiClient::class)->pedidosCrudos(['orderBy' => 'id'], 2, 50);

    expect($r['objects'])->toHaveCount(1);
    Http::assertSent(fn (Request $req) => $req->hasHeader('dropi-integration-key', 'tok-prueba')
        && ! $req->hasHeader('Authorization')
        && str_contains($req->url(), 'start=100')
        && str_contains($req->url(), 'result_number=50'));
});

it('el detalle se mapea igual que el de la web (cantidades, precio de proveedor, despacho)', function () {
    Http::fake([URL_INTEGRACIONES.'orders/myorders/55' => Http::response([
        'isSuccess' => true, 'message' => 'Execution completed', 'objects' => ordenDropi(55, 'EN TRANSITO', 'GUIA-55'),
    ])]);

    $dto = app(App\Modules\Dropi\Services\MapearPedidoDropiWeb::class)
        ->desdeDetalle(app(DropiApiClient::class)->pedidoDetalle(55));

    expect($dto->guia)->toBe('GUIA-55')
        ->and($dto->estadoDropi)->toBe('despachado')
        ->and($dto->montoEsperadoProveedor)->toBe(90000.0)
        ->and($dto->items[0]->skuDropi)->toBe('DV-777')
        ->and($dto->items[0]->cantidad)->toBe(2);
});

it('un token rechazado se trata como sesión caída (el sync lo registra una vez por hora)', function () {
    Http::fake([URL_INTEGRACIONES.'*' => Http::response(['message' => 'Access denied'], 401)]);

    expect(fn () => app(DropiApiClient::class)->pedidosCrudos())
        ->toThrow(DropiSesionNoDisponible::class, 'rechazó el token');
});

it('sin token configurado avisa qué falta en el .env', function () {
    config(['dropi.api.key' => '']);
    app()->forgetInstance(DropiApiClient::class);

    expect(fn () => app(DropiApiClient::class)->pedidosCrudos())
        ->toThrow(DropiSesionNoDisponible::class, 'DROPI_API_KEY');
});

it('ante el límite de peticiones (429) espera y reintenta', function () {
    Http::fake([
        URL_INTEGRACIONES.'orders/myorders/9' => Http::sequence()
            ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '1'])
            ->push(['isSuccess' => true, 'objects' => ordenDropi(9)]),
    ]);
    Illuminate\Support\Sleep::fake();

    $o = app(DropiApiClient::class)->pedidoDetalle(9);

    expect($o['id'])->toBe(9);
    Http::assertSentCount(2);
    expect(DropiApiClient::esperaMs(new Illuminate\Http\Client\RequestException(
        new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(429, ['Retry-After' => '90']))
    )))->toBe(30000);
});

it('el sync en tiempo real importa pedidos por la API oficial', function () {
    Http::fake([
        URL_INTEGRACIONES.'orders/myorders/101' => Http::response(['isSuccess' => true, 'objects' => ordenDropi(101)]),
        URL_INTEGRACIONES.'orders/myorders/102' => Http::response(['isSuccess' => true, 'objects' => ordenDropi(102, 'ENTREGADO', 'G-102')]),
        URL_INTEGRACIONES.'orders/myorders*' => Http::response(['isSuccess' => true, 'objects' => [ordenDropi(101), ordenDropi(102, 'ENTREGADO', 'G-102')]]),
    ]);

    $r = app(SincronizarDropiWeb::class)->handle('recientes');

    expect($r['nuevos'])->toBe(2)->and($r['errores'])->toBe(0)
        ->and(DropiPedido::where('dropi_orden_id', '101')->value('guia'))->toBe('SIN-GUIA-101')
        ->and(DropiPedido::where('dropi_orden_id', '102')->value('guia'))->toBe('G-102');

    // Segunda vuelta sin cambios: la huella evita volver a pedir el detalle.
    $r2 = app(SincronizarDropiWeb::class)->handle('recientes');
    expect($r2['cambiados'])->toBe(0);
});
