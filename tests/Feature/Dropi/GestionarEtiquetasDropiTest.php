<?php

use App\Modules\Dropi\Actions\GestionarEtiquetasDropi;
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

    $corte = \App\Modules\Dropi\Actions\AsignarCorteACarga::run(\Carbon\CarbonImmutable::now());
    $this->pedido = DropiPedido::create([
        'corte_id' => $corte->id, 'guia' => 'SIN-GUIA-555', 'dropi_orden_id' => '555',
        'cliente_nombre' => 'Ana', 'monto_esperado_proveedor' => 1000,
    ]);
});

/** Dropi simulado: la cuenta tiene «Urgente»(1) y «Revisar»(2); el pedido tiene «Revisar». */
function dropiEtiquetas(array $cuenta = [['id' => 1, 'tag_name' => 'Urgente', 'color' => '#f00'], ['id' => 2, 'tag_name' => 'Revisar', 'color' => '#0f0']]): void
{
    Http::fake([
        'legacy.test/tags/showtagsselect' => Http::response(['isSuccess' => true, 'objects' => $cuenta]),
        'legacy.test/tags/*' => Http::response(['isSuccess' => true, 'objects' => []]),
        'bff.test/orders/*' => Http::response(['is_succesfull' => true, 'data' => ['objects' => [
            ['id' => 555, 'status' => 'PENDIENTE', 'tags' => [['id' => 2, 'tag_name' => 'Revisar', 'color' => '#0f0']]],
        ]]]),
    ]);
}

it('pone las etiquetas nuevas y quita las que sobran, y deja auditoría', function () {
    dropiEtiquetas();

    $r = GestionarEtiquetasDropi::run($this->pedido, [1], null, null, null);

    expect($r)->toBe(['puestas' => 1, 'quitadas' => 1, 'creada' => false]);
    Http::assertSent(fn (Request $q) => $q->method() === 'POST' && str_ends_with($q->url(), '/tags/createorderstags')
        && $q['order_id'] === 555 && $q['tag_id'] === 1);
    Http::assertSent(fn (Request $q) => $q->method() === 'DELETE' && str_contains($q->url(), '/tags/deleteordertag?tag_id=2&order_id=555'));
    expect(DB::table('dropi_web_acciones')->where('exito', true)->pluck('accion')->sort()->values()->all())
        ->toBe(['poner_etiqueta', 'quitar_etiqueta']);
});

it('no escribe nada si el pedido ya tiene exactamente esas etiquetas', function () {
    dropiEtiquetas();

    $r = GestionarEtiquetasDropi::run($this->pedido, [2]);

    expect($r['puestas'] + $r['quitadas'])->toBe(0);
    Http::assertNotSent(fn (Request $q) => in_array($q->method(), ['POST', 'DELETE', 'PUT'], true));
});

it('crea una etiqueta nueva en la cuenta y se la pone al pedido', function () {
    $llamadas = 0;
    Http::fake(function (Request $q) use (&$llamadas) {
        if (str_ends_with($q->url(), '/tags/showtagsselect')) {
            // Antes de crearla no existe; después sí.
            return Http::response(['isSuccess' => true, 'objects' => $llamadas++ === 0 ? [] : [['id' => 9, 'tag_name' => 'Revisar dirección']]]);
        }
        if (str_contains($q->url(), 'bff.test/orders')) {
            return Http::response(['is_succesfull' => true, 'data' => ['objects' => [['id' => 555, 'tags' => []]]]]);
        }

        return Http::response(['isSuccess' => true]);
    });

    $r = GestionarEtiquetasDropi::run($this->pedido, [], 'Revisar dirección', '#123456');

    expect($r)->toBe(['puestas' => 1, 'quitadas' => 0, 'creada' => true]);
    Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/tags/addtag') && $q['tag_name'] === 'Revisar dirección' && $q['color'] === '#123456');
    Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/tags/createorderstags') && $q['tag_id'] === 9);
});

it('muestra el mensaje de Dropi si rechaza la etiqueta y lo audita como fallido', function () {
    Http::fake([
        'legacy.test/tags/createorderstags' => Http::response(['isSuccess' => false, 'message' => 'Etiqueta no válida'], 400),
        'bff.test/orders/*' => Http::response(['is_succesfull' => true, 'data' => ['objects' => [['id' => 555, 'tags' => []]]]]),
    ]);

    expect(fn () => GestionarEtiquetasDropi::run($this->pedido, [1]))->toThrow(\RuntimeException::class, 'Etiqueta no válida');
    expect((bool) DB::table('dropi_web_acciones')->value('exito'))->toBeFalse();
});
