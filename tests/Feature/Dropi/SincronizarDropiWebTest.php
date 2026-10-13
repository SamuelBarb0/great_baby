<?php

use App\Modules\Dropi\Actions\SincronizarDropiWeb;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiWebSesion;
use App\Modules\Dropi\Services\DropiWebAuth;
use App\Modules\Dropi\Services\MapearPedidoDropiWeb;
use Illuminate\Support\Facades\Http;

/** Detalle con la forma real de bff/orders/myorders/v2/{id} (datos de cliente inventados). */
function detalleDropi(array $cambios = []): array
{
    return array_replace_recursive([
        'id' => 90719548,
        'status' => 'PENDIENTE',
        'shipping_guide' => null,
        'shipping_company' => 'INTERRAPIDISIMO',
        'created_at' => '2026-09-24T12:03:01',
        'name' => 'Ana', 'surname' => 'Prueba', 'phone' => '3000000000',
        'dir' => 'Calle 1 # 2-3', 'city' => 'ACACIAS', 'state' => 'META',
        'total_order' => 179400, 'shipping_amount' => 27537, 'dropshipper_amount_to_win' => 40000,
        'shop' => ['id' => 315907, 'name' => 'TIENDA DE PRUEBA'],
        'user' => ['id' => 499723, 'name' => 'VENDEDORA', 'surname' => 'DEMO'],
        'orderdetails' => [[
            'quantity' => 2, 'supplier_price' => 35000, 'product_id' => 1725980, 'variation_id' => null,
            'product' => ['id' => 1725980, 'sku' => 'TP', 'name' => 'TAPETE PLEGABLE'],
        ]],
        'history' => [['status' => 'PENDIENTE', 'created_at' => '2026-09-24T12:03:03']],
    ], $cambios);
}

function resumenLista(array $detalle): array
{
    return array_intersect_key($detalle, array_flip(['id', 'status', 'shipping_guide', 'shipping_company', 'total_order']));
}

beforeEach(function () {
    $GLOBALS['dropiFakeRegistrado'] = false;
    config()->set('dropi.web', ['email' => 'c@e.co', 'password' => 'x', 'white_brand_id' => 1, 'bff_url' => 'https://bff.test', 'timeout' => 5]);
    app()->forgetInstance(DropiWebAuth::class);
    app()->singleton(DropiWebAuth::class, fn ($app) => new DropiWebAuth($app['config']->get('dropi.web')));
    DropiWebSesion::create(['email' => 'c@e.co', 'estado' => DropiWebSesion::CONECTADO, 'token' => 'tok', 'expira_at' => now()->addHour()]);
});

/** Http::fake acumula stubs; el estado de Dropi vive en una variable que cada vuelta reemplaza. */
function fakeDropi(array $detalles): void
{
    $GLOBALS['dropiFalso'] = $detalles;
    if ($GLOBALS['dropiFakeRegistrado'] ?? false) {
        return;
    }
    $GLOBALS['dropiFakeRegistrado'] = true;

    Http::fake(function ($request) {
        $detalles = $GLOBALS['dropiFalso'];
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (preg_match('#/orders/myorders/v2/(\d+)$#', $path, $m)) {
            return Http::response(['is_succesfull' => true, 'data' => ['objects' => $detalles[(int) $m[1]]]]);
        }

        return Http::response(['is_succesfull' => true, 'data' => ['count' => null, 'objects' => array_values(array_map('resumenLista', $detalles))]]);
    });
}

it('mapea el detalle de Dropi: guía provisional, ítems y monto de proveedor', function () {
    $dto = app(MapearPedidoDropiWeb::class)->desdeDetalle(detalleDropi());

    expect($dto->guia)->toBe('SIN-GUIA-90719548')
        ->and($dto->dropiOrdenId)->toBe('90719548')
        ->and($dto->estadoDropi)->toBe('')
        ->and($dto->items)->toHaveCount(1)
        ->and($dto->items[0]->skuDropi)->toBe('TP')
        ->and($dto->items[0]->cantidad)->toBe(2)
        ->and($dto->montoEsperadoProveedor)->toBe(70000.0)
        ->and($dto->clienteNombre)->toBe('Ana Prueba')
        ->and($dto->creadoAt->format('Y-m-d H:i'))->toBe('2026-09-24 12:03');
});

it('clasifica los estados de Dropi sin pisar la bodega', function (string $estatus, ?string $esperado) {
    expect(MapearPedidoDropiWeb::estadoCanonico($estatus))->toBe($esperado);
})->with([
    ['PENDIENTE', null], ['GUIA_GENERADA', null], ['REEMPLAZADA', 'cancelado'], ['INDEMNIZADO', null],
    ['EN REPARTO', 'despachado'], ['NOVEDAD', 'despachado'], ['INTENTO DE ENTREGA', 'despachado'],
    ['ENTREGADO', 'entregado'], ['DEVOLUCION', 'devolucion'], ['CANCELADO', 'cancelado'], ['RECHAZADO', 'cancelado'],
]);

it('mete el pedido pendiente sin guía y le pone la guía real cuando Dropi la asigna', function () {
    fakeDropi([90719548 => detalleDropi()]);
    $r = app(SincronizarDropiWeb::class)->handle('recientes');

    expect($r['nuevos'])->toBe(1);
    $pedido = DropiPedido::where('dropi_orden_id', '90719548')->first();
    expect($pedido->guia)->toBe('SIN-GUIA-90719548');

    // La bodega lo empieza a alistar; Dropi genera la guía (sigue «antes del despacho»).
    $pedido->transicionar(EstadoPedidoDropi::Alistando, 'sistema');
    fakeDropi([90719548 => detalleDropi(['status' => 'GUIA_GENERADA', 'shipping_guide' => '064109999999'])]);
    app(SincronizarDropiWeb::class)->handle('recientes');

    $pedido->refresh();
    expect($pedido->guia)->toBe('064109999999')
        ->and($pedido->estado)->toBe(EstadoPedidoDropi::Alistando)
        ->and(DropiPedido::count())->toBe(1);
});

it('no vuelve a pedir el detalle si el pedido no cambió', function () {
    fakeDropi([90719548 => detalleDropi()]);
    $sync = app(SincronizarDropiWeb::class);

    $sync->handle('recientes');
    $segunda = $sync->handle('recientes');

    expect($segunda['vistos'])->toBe(1)->and($segunda['cambiados'])->toBe(0);
    Http::assertSentCount(3); // lista + detalle + lista
});

it('pasa el pedido a entregado cuando Dropi lo marca', function () {
    fakeDropi([90719548 => detalleDropi(['status' => 'EN REPARTO', 'shipping_guide' => '064109999999'])]);
    app(SincronizarDropiWeb::class)->handle('recientes');
    expect(DropiPedido::first()->estado)->toBe(EstadoPedidoDropi::Despachado);

    fakeDropi([90719548 => detalleDropi([
        'status' => 'ENTREGADO', 'shipping_guide' => '064109999999',
        'history' => [['status' => 'ENTREGADO', 'created_at' => '2026-09-26T15:00:00']],
    ])]);
    app(SincronizarDropiWeb::class)->handle('recientes');

    $pedido = DropiPedido::first();
    expect($pedido->estado)->toBe(EstadoPedidoDropi::Entregado)
        ->and($pedido->entregado_at->format('Y-m-d H:i'))->toBe('2026-09-26 15:00');
});

it('el sync no borra lo escrito en el ERP ni cambia el pedido de corte', function () {
    fakeDropi([90719548 => detalleDropi()]);
    app(SincronizarDropiWeb::class)->handle('recientes');

    $pedido = DropiPedido::first();
    $otroCorte = \App\Modules\Dropi\Models\DropiCorte::create(['fecha' => now()->addDay()->toDateString(), 'numero' => 2]);
    $pedido->forceFill(['cliente_doc' => '1098765432', 'corte_id' => $otroCorte->id])->save();

    // Dropi cambia el pedido (genera guía); no manda documento.
    fakeDropi([90719548 => detalleDropi(['status' => 'GUIA_GENERADA', 'shipping_guide' => '064100000001'])]);
    app(SincronizarDropiWeb::class)->handle('recientes');

    $pedido->refresh();
    expect($pedido->guia)->toBe('064100000001')
        ->and($pedido->cliente_doc)->toBe('1098765432')
        ->and($pedido->corte_id)->toBe($otroCorte->id);
});
