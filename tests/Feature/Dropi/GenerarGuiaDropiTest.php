<?php

/**
 * La guía de Dropi sale al confirmar el empaque (Samuel y bodega, 23-nov-2026):
 * el ERP la pide como «Actualizar estatus → GUIA_GENERADA» y trae el sticker
 * que Dropi publica, para pegarlo en la caja que se acaba de cerrar.
 */

use App\Models\User;
use App\Modules\Dropi\Actions\GenerarGuiaDropi;
use App\Modules\Dropi\Actions\SincronizarDropiWeb;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiWebSesion;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use App\Modules\Dropi\Services\DropiWebAuth;
use App\Support\Reglas;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

const GGD_ORDEN = 90719548;
const GGD_RUTA = 'colombia/guias/interrapidisimo/ORDEN-90719548-GUIA-240099990001.pdf';

beforeEach(function () {
    Queue::fake();
    config()->set('dropi.web', [
        'email' => 'c@e.co', 'password' => 'x', 'white_brand_id' => 1, 'timeout' => 5,
        'bff_url' => 'https://bff.test', 'legacy_url' => 'https://legacy.test',
        'stickers_url' => 'https://stickers.test',
    ]);
    app()->forgetInstance(DropiWebAuth::class);
    app()->singleton(DropiWebAuth::class, fn ($app) => new DropiWebAuth($app['config']->get('dropi.web')));
    DropiWebSesion::create(['email' => 'c@e.co', 'estado' => DropiWebSesion::CONECTADO, 'token' => 'tok', 'expira_at' => now()->addHour()]);
});

function ggdOrden(array $cambios = []): array
{
    return $cambios + [
        'id' => GGD_ORDEN, 'status' => 'PENDIENTE', 'shipping_guide' => null, 'shipping_company' => 'INTERRAPIDISIMO',
        'created_at' => '2026-11-23T12:03:01', 'name' => 'Ana', 'surname' => 'Prueba', 'total_order' => 179400,
        'orderdetails' => [['quantity' => 1, 'supplier_price' => 35000, 'product_id' => 1, 'product' => ['sku' => 'TP', 'name' => 'TAPETE']]],
        'history' => [],
    ];
}

/** El pedido ya en el ERP, con su guía provisional, en el estado pedido. */
function ggdPedido(EstadoPedidoDropi $estado = EstadoPedidoDropi::Empacado): DropiPedido
{
    $o = ggdOrden();
    Http::fake([
        'bff.test/orders/myorders/v2/*' => Http::response(['is_succesfull' => true, 'data' => ['objects' => $o]]),
        'bff.test/*' => Http::response(['is_succesfull' => true, 'data' => ['objects' => [$o]]]),
    ]);
    app(SincronizarDropiWeb::class)->handle('recientes');
    Http::swap(new \Illuminate\Http\Client\Factory);

    $p = DropiPedido::where('dropi_orden_id', (string) GGD_ORDEN)->firstOrFail();
    $p->estado = $estado;
    $p->save();

    return $p;
}

/** Dropi genera la guía: el PUT, el detalle con la guía y la lista con el sticker. */
function ggdDropiDaLaGuia(): void
{
    $conGuia = ggdOrden(['status' => 'GUIA_GENERADA', 'shipping_guide' => '240099990001']);
    Http::fake([
        'legacy.test/orders/myorders/' . GGD_ORDEN => Http::response(['isSuccess' => true, 'order' => [
            'shipping_guide' => '240099990001', 'shipping_company' => 'INTERRAPIDISIMO', 'sticker' => 'ORDEN-90719548-GUIA-240099990001.pdf']]),
        'bff.test/orders/myorders/v2/' . GGD_ORDEN . '*' => Http::response(['is_succesfull' => true, 'data' => ['objects' => $conGuia]]),
        'bff.test/orders/myorders/v2*' => Http::response(['is_succesfull' => true, 'data' => ['objects' => [$conGuia + ['guia_urls3' => GGD_RUTA]]]]),
    ]);
}

it('genera la guía en Dropi y deja el sticker listo para imprimir', function () {
    $pedido = ggdPedido();
    ggdDropiDaLaGuia();

    $r = GenerarGuiaDropi::make()->handle($pedido);

    expect($r['generada'])->toBeTrue()
        ->and($r['guia'])->toBe('240099990001')
        ->and($r['sticker_url'])->toBe('https://stickers.test/' . GGD_RUTA);
    Http::assertSent(fn (Request $req) => $req->method() === 'PUT' && $req['status'] === 'GUIA_GENERADA');

    $pedido->refresh();
    expect($pedido->guia)->toBe('240099990001')
        ->and($pedido->sticker_path)->toBe(GGD_RUTA)
        // La guía no lo devuelve a pendiente: sigue empacado.
        ->and($pedido->estado)->toBe(EstadoPedidoDropi::Empacado);
});

it('no pide una segunda guía a un pedido que ya la tiene: sólo trae su sticker', function () {
    $pedido = ggdPedido();
    ggdDropiDaLaGuia();
    GenerarGuiaDropi::make()->handle($pedido);

    Http::swap(new \Illuminate\Http\Client\Factory);
    DropiPedido::whereKey($pedido->id)->update(['sticker_path' => null]);
    ggdDropiDaLaGuia();

    $r = GenerarGuiaDropi::make()->handle($pedido->fresh());

    expect($r['generada'])->toBeFalse()->and($r['sticker_url'])->toBe('https://stickers.test/' . GGD_RUTA);
    Http::assertNotSent(fn (Request $req) => $req->method() === 'PUT');
});

it('la sincronización guarda el sticker de los pedidos que ya tenían guía', function () {
    $pedido = ggdPedido(EstadoPedidoDropi::Pending);

    SincronizarDropiWeb::guardarStickers([['id' => GGD_ORDEN, 'guia_urls3' => GGD_RUTA], ['id' => 1, 'guia_urls3' => 'otra.pdf']]);

    expect($pedido->fresh()->stickerUrl())->toBe('https://stickers.test/' . GGD_RUTA);
});

describe('en la Estación de Empaque', function () {
    beforeEach(function () {
        $this->seed(\Database\Seeders\PermisosSeeder::class);
        $this->aracely = User::factory()->create(['name' => 'Aracely']);
        $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
        Reglas::set('empaque.foto_obligatoria', false);
    });

    /** Pedido abierto en la estación, con todo escaneado: listo para confirmar. */
    function ggdListoParaConfirmar($test): DropiPedido
    {
        $p = ggdPedido(EstadoPedidoDropi::Alistando);
        $p->items()->update(['cantidad_pickeada' => 1]);
        EmpaqueRegistro::create([
            'pedido_id' => $p->id, 'operario_id' => $test->aracely->id, 'inicio_at' => now(),
            'items_totales' => 1, 'items_escaneados' => 1, 'estado' => 'en_curso',
        ]);

        return $p;
    }

    it('con la regla prendida, confirmar el empaque genera la guía y ofrece el sticker', function () {
        Reglas::set('dropi.guia_al_empacar', true);
        $p = ggdListoParaConfirmar($this);
        ggdDropiDaLaGuia();

        $this->actingAs($this->aracely)->withSession(['empaque.pedidoActivoId' => $p->id])
            ->post('/app/estacion-empaque/confirmar')
            ->assertSessionHas('empaqueResultado', fn ($r) => $r['sticker_url'] === 'https://stickers.test/' . GGD_RUTA
                && str_contains($r['mensaje'], '240099990001'));

        expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Empacado)
            ->and($p->fresh()->guia)->toBe('240099990001');
    });

    it('si Dropi rechaza la guía, el empaque queda confirmado y se puede reintentar', function () {
        Reglas::set('dropi.guia_al_empacar', true);
        $p = ggdListoParaConfirmar($this);
        Http::fake(['legacy.test/*' => Http::response(['isSuccess' => false, 'message' => 'La orden no está confirmada'], 400)]);

        $this->actingAs($this->aracely)->withSession(['empaque.pedidoActivoId' => $p->id])
            ->post('/app/estacion-empaque/confirmar')
            ->assertSessionHas('empaqueResultado', fn ($r) => $r['guia_pedido_id'] === $p->id
                && str_contains($r['mensaje'], 'La orden no está confirmada'));

        expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Empacado)
            ->and($p->fresh()->tieneGuiaReal())->toBeFalse();

        // El reintento desde la estación ya la consigue.
        Http::swap(new \Illuminate\Http\Client\Factory);
        ggdDropiDaLaGuia();
        $this->post('/app/estacion-empaque/guia', ['pedido_id' => $p->id])
            ->assertSessionHas('empaqueResultado', fn ($r) => $r['sticker_url'] === 'https://stickers.test/' . GGD_RUTA);
        expect($p->fresh()->guia)->toBe('240099990001');
    });

    it('con la regla apagada, confirmar el empaque no le pide nada a Dropi', function () {
        Reglas::set('dropi.guia_al_empacar', false);
        $p = ggdListoParaConfirmar($this);
        Http::fake();

        $this->actingAs($this->aracely)->withSession(['empaque.pedidoActivoId' => $p->id])
            ->post('/app/estacion-empaque/confirmar')
            ->assertSessionHas('empaqueResultado', fn ($r) => ! isset($r['guia_pedido_id']));

        expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Empacado);
        Http::assertNothingSent();
    });

    it('sólo quien empacó el pedido pide su guía', function () {
        Reglas::set('dropi.guia_al_empacar', true);
        $p = ggdPedido();
        $otro = User::factory()->create();
        $otro->givePermissionTo(\App\Auth\Permisos::permiso('vista_alistador'));
        Http::fake();

        $this->actingAs($otro)->post('/app/estacion-empaque/guia', ['pedido_id' => $p->id])->assertForbidden();
        Http::assertNothingSent();
    });
});

it('al cierre del corte se piden de una vez las guías de los empacados que quedaron sin guía', function () {
    $pedido = ggdPedido();
    ggdDropiDaLaGuia();

    expect(GenerarGuiaDropi::sinGuia($pedido->corte)->count())->toBe(1);

    $r = GenerarGuiaDropi::make()->pendientesDelCorte($pedido->corte);

    expect($r['generadas'])->toBe(1)->and($r['errores'])->toBe([])
        ->and(GenerarGuiaDropi::sinGuia($pedido->corte)->count())->toBe(0);
});

describe('observaciones del recorrido del 23-nov-2026', function () {
    beforeEach(function () {
        $this->seed(\Database\Seeders\PermisosSeeder::class);
        $this->aracely = User::factory()->create(['name' => 'Aracely']);
        $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
        Reglas::set('empaque.foto_obligatoria', false);
    });

    it('la hora de inicio del empaque no se reescribe al escanear ni al confirmar', function () {
        $p = ggdPedido(EstadoPedidoDropi::Alistando);
        $inicio = now()->subMinutes(3)->startOfSecond();
        $r = EmpaqueRegistro::create([
            'pedido_id' => $p->id, 'operario_id' => $this->aracely->id, 'inicio_at' => $inicio,
            'items_totales' => 1, 'items_escaneados' => 0, 'estado' => 'en_curso',
        ]);
        $p->items()->update(['cantidad_pickeada' => 1]);

        EmpaqueRegistro::whereKey($r->id)->increment('items_escaneados');
        expect($r->fresh()->inicio_at->equalTo($inicio))->toBeTrue();

        Http::fake();
        $this->actingAs($this->aracely)->withSession(['empaque.pedidoActivoId' => $p->id])
            ->post('/app/estacion-empaque/confirmar');

        $r->refresh();
        expect($r->estado)->toBe(\App\Modules\Dropi\Enums\EstadoEmpaque::Completado)
            ->and($r->inicio_at->equalTo($inicio))->toBeTrue()
            ->and($r->duracion_segundos)->toBeGreaterThanOrEqual(180)->toBeLessThan(240);
    });

    it('ninguna de las cinco fechas queda con ON UPDATE en la base', function () {
        if (! in_array(\Illuminate\Support\Facades\DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requiere MySQL.');
        }
        $conOnUpdate = collect(\Illuminate\Support\Facades\DB::select(
            "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%on update%'"))->pluck('c');

        expect($conOnUpdate->intersect(['empaques_registro.inicio_at', 'dropi_devoluciones.recibido_at',
            'garantia_tickets.plazo_concepto_at', 'cobranzas_registro.enviado_at', 'dropi_sanciones.detectada_at'])->all())->toBe([]);
    });

    it('«Mis stickers de hoy» deja imprimir el sticker aunque se recargue la página, sólo del propio operario', function () {
        Reglas::set('dropi.guia_al_empacar', true);
        $p = ggdPedido(EstadoPedidoDropi::Alistando);
        $p->items()->update(['cantidad_pickeada' => 1]);
        EmpaqueRegistro::create([
            'pedido_id' => $p->id, 'operario_id' => $this->aracely->id, 'inicio_at' => now(),
            'items_totales' => 1, 'items_escaneados' => 1, 'estado' => 'en_curso',
        ]);
        ggdDropiDaLaGuia();
        $this->actingAs($this->aracely)->withSession(['empaque.pedidoActivoId' => $p->id])
            ->post('/app/estacion-empaque/confirmar');

        $this->get('/app/estacion-empaque')->assertInertia(fn ($page) => $page
            ->where('misStickers.0.pedido_id', $p->id)
            ->where('misStickers.0.guia', '240099990001')
            ->where('misStickers.0.sticker_url', 'https://stickers.test/' . GGD_RUTA));

        $otro = User::factory()->create();
        $otro->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
        $this->actingAs($otro)->get('/app/estacion-empaque')->assertInertia(fn ($page) => $page->where('misStickers', []));
    });

    it('la estación muestra el color que pidió el cliente en Dropi en un producto agregado', function () {
        $loro = \App\Modules\Dropi\Models\Producto::sinKardexAutomatico(fn () => \App\Modules\Dropi\Models\Producto::create(
            ['referencia' => 'AND2410-458', 'nombre' => 'LORO MUSICAL FELPA', 'desglose_stock' => false]));
        $p = ggdPedido(EstadoPedidoDropi::Alistando);
        $p->items()->update(['producto_id' => $loro->id, 'variante_id' => null, 'variacion' => 'ROJO']);
        EmpaqueRegistro::create([
            'pedido_id' => $p->id, 'operario_id' => $this->aracely->id, 'inicio_at' => now(),
            'items_totales' => 1, 'items_escaneados' => 0, 'estado' => 'en_curso',
        ]);

        $this->actingAs($this->aracely)->withSession(['empaque.pedidoActivoId' => $p->id])
            ->get('/app/estacion-empaque')
            ->assertInertia(fn ($page) => $page->where('pedidoActivo.items.0.color', 'ROJO')->where('pedidoActivo.items.0.es_agregado', true));

        $r = \App\Modules\Dropi\Actions\ProcesarEscaneoEmpaque::run('AND2410-458', $p->id, $this->aracely->id);
        expect($r['tipo'])->toBe('variante_marcada')->and($r['mensaje'])->toContain('ROJO')->not->toContain('surtidos');
    });
});

describe('en la Estación de Empaque del panel (/admin)', function () {
    beforeEach(function () {
        $this->seed(\Database\Seeders\PermisosSeeder::class);
        \Filament\Facades\Filament::setCurrentPanel('admin');
        $this->aracely = User::factory()->create(['name' => 'Aracely']);
        $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
        $this->aracely->givePermissionTo(\App\Auth\Permisos::permiso('estacion_empaque'));
        Reglas::set('empaque.foto_obligatoria', false);
    });

    it('confirmar el empaque pide la guía y el sticker queda en «Mis stickers de hoy»', function () {
        Reglas::set('dropi.guia_al_empacar', true);
        $p = ggdListoParaConfirmar($this);
        ggdDropiDaLaGuia();
        $this->actingAs($this->aracely);

        $pagina = \Livewire\Livewire::test(\App\Modules\Dropi\Filament\Pages\EstacionEmpaque::class)
            ->assertSet('pedidoActivoId', $p->id)
            ->call('confirmarYSiguiente')
            ->assertNotified();

        expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Empacado)
            ->and($p->fresh()->guia)->toBe('240099990001');

        $stickers = $pagina->instance()->misStickers();
        expect($stickers)->toHaveCount(1)
            ->and($stickers[0]['guia'])->toBe('240099990001')
            ->and($stickers[0]['sticker_url'])->toBe('https://stickers.test/' . GGD_RUTA);
        $pagina->assertSee('Mis stickers de hoy (1)')->assertSee('Imprimir sticker');
    });

    it('si Dropi rechaza la guía, el empaque queda y «↻ Pedir guía» la consigue después', function () {
        Reglas::set('dropi.guia_al_empacar', true);
        $p = ggdListoParaConfirmar($this);
        Http::fake(['legacy.test/*' => Http::response(['isSuccess' => false, 'message' => 'La orden no está confirmada'], 400)]);
        $this->actingAs($this->aracely);

        $pagina = \Livewire\Livewire::test(\App\Modules\Dropi\Filament\Pages\EstacionEmpaque::class)
            ->call('confirmarYSiguiente');

        expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Empacado)
            ->and($p->fresh()->tieneGuiaReal())->toBeFalse();
        $pagina->assertSee('Mis stickers de hoy (1)')->assertSee('Pedir guía');

        Http::swap(new \Illuminate\Http\Client\Factory);
        ggdDropiDaLaGuia();
        $pagina->call('pedirGuia', $p->id)->assertNotified();

        expect($p->fresh()->guia)->toBe('240099990001');
        $pagina->assertSee('Imprimir sticker');
    });

    it('con la regla apagada y sin guía, confirmar no le pide nada a Dropi', function () {
        Reglas::set('dropi.guia_al_empacar', false);
        $p = ggdListoParaConfirmar($this);
        Http::fake();
        $this->actingAs($this->aracely);

        \Livewire\Livewire::test(\App\Modules\Dropi\Filament\Pages\EstacionEmpaque::class)->call('confirmarYSiguiente');

        expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Empacado);
        Http::assertNothingSent();
    });
});
