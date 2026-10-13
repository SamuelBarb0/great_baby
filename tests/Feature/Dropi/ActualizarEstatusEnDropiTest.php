<?php

use App\Modules\Dropi\Actions\ActualizarEstatusEnDropi;
use App\Modules\Dropi\Actions\SincronizarDropiWeb;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiWebSesion;
use App\Modules\Dropi\Services\DropiWebAuth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('dropi.web', [
        'email' => 'c@e.co', 'password' => 'x', 'white_brand_id' => 1, 'timeout' => 5,
        'bff_url' => 'https://bff.test', 'legacy_url' => 'https://legacy.test',
    ]);
    app()->forgetInstance(DropiWebAuth::class);
    app()->singleton(DropiWebAuth::class, fn ($app) => new DropiWebAuth($app['config']->get('dropi.web')));
    DropiWebSesion::create(['email' => 'c@e.co', 'estado' => DropiWebSesion::CONECTADO, 'token' => 'tok', 'expira_at' => now()->addHour()]);
});

/** Pedido real de la muestra, ya en el ERP con guía provisional. */
function pedidoPendiente(): DropiPedido
{
    $detalle = [
        'id' => 90719548, 'status' => 'PENDIENTE', 'shipping_guide' => null, 'shipping_company' => 'INTERRAPIDISIMO',
        'created_at' => '2026-09-24T12:03:01', 'name' => 'Ana', 'surname' => 'Prueba', 'total_order' => 179400,
        'orderdetails' => [['quantity' => 1, 'supplier_price' => 35000, 'product_id' => 1, 'product' => ['sku' => 'TP', 'name' => 'TAPETE']]],
        'history' => [],
    ];
    Http::fake([
        'bff.test/orders/myorders/v2/*' => Http::response(['is_succesfull' => true, 'data' => ['objects' => $detalle]]),
        'bff.test/*' => Http::response(['is_succesfull' => true, 'data' => ['objects' => [$detalle]]]),
    ]);
    app(SincronizarDropiWeb::class)->handle('recientes');

    return DropiPedido::where('dropi_orden_id', '90719548')->firstOrFail();
}

it('genera la guía en Dropi, deja auditoría y trae la guía al ERP', function () {
    $pedido = pedidoPendiente();

    $conGuia = [
        'id' => 90719548, 'status' => 'GUIA_GENERADA', 'shipping_guide' => '240099990001', 'shipping_company' => 'INTERRAPIDISIMO',
        'created_at' => '2026-09-24T12:03:01', 'name' => 'Ana', 'surname' => 'Prueba', 'total_order' => 179400,
        'orderdetails' => [['quantity' => 1, 'supplier_price' => 35000, 'product_id' => 1, 'product' => ['sku' => 'TP', 'name' => 'TAPETE']]],
        'history' => [],
    ];
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        'legacy.test/orders/myorders/90719548' => Http::response(['isSuccess' => true, 'order' => ['shipping_guide' => '240099990001', 'shipping_company' => 'INTERRAPIDISIMO', 'sticker' => null]]),
        'bff.test/orders/myorders/v2/90719548*' => Http::response(['is_succesfull' => true, 'data' => ['objects' => $conGuia]]),
    ]);

    $r = ActualizarEstatusEnDropi::run($pedido, 'GUIA_GENERADA', null, null);

    expect($r['shipping_guide'])->toBe('240099990001');
    Http::assertSent(fn (Request $req) => $req->method() === 'PUT'
        && $req->url() === 'https://legacy.test/orders/myorders/90719548'
        && $req['status'] === 'GUIA_GENERADA'
        && $req->hasHeader('X-Authorization', 'Bearer tok')
        && str_contains($req->header('User-Agent')[0] ?? '', 'Mozilla'));

    expect($pedido->fresh()->guia)->toBe('240099990001');
    $audit = DB::table('dropi_web_acciones')->first();
    expect((bool) $audit->exito)->toBeTrue()
        ->and($audit->estatus_antes)->toBe('PENDIENTE')
        ->and($audit->estatus_pedido)->toBe('GUIA_GENERADA');
});

it('muestra el motivo de Dropi cuando rechaza el cambio y lo deja auditado', function () {
    $pedido = pedidoPendiente();
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        'legacy.test/*' => Http::response(['isSuccess' => false, 'message' => 'La orden no se puede anular en este estado'], 400),
    ]);

    expect(fn () => ActualizarEstatusEnDropi::run($pedido, 'GUIA_ANULADA'))
        ->toThrow(\RuntimeException::class, 'La orden no se puede anular en este estado');

    $audit = DB::table('dropi_web_acciones')->first();
    expect((bool) $audit->exito)->toBeFalse()
        ->and($audit->respuesta)->toContain('no se puede anular');
});

it('no deja rechazar sin motivo ni mandar estatus inventados', function () {
    $pedido = pedidoPendiente();
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake();

    expect(fn () => ActualizarEstatusEnDropi::run($pedido, 'RECHAZADO'))->toThrow(\InvalidArgumentException::class);
    expect(fn () => ActualizarEstatusEnDropi::run($pedido, 'ENTREGADO'))->toThrow(\InvalidArgumentException::class);
    Http::assertNothingSent();
});
