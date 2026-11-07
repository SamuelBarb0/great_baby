<?php

/**
 * «Traer productos de Dropi» · lee el catálogo por la API (falsa aquí), lo
 * cruza con el ERP y vincula sólo lo seguro.
 *
 * Las respuestas imitan las reales del 6-nov-2026: páginas de 40 aunque se
 * pidan más, variaciones con `attribute_values` y stock por bodega, SKU basura
 * («-0») en las variaciones y SKU vacío en muchos simples.
 */

use App\Models\User;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\Clients\DropiApiClient;
use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Jobs\LeerCatalogoDropiJob;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\CatalogoDropi;
use App\Modules\Dropi\Services\DropiWebAuth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

const CAT_DUENO = 151057;

function catSimple(int $id, ?string $sku, string $nombre, int $dueno = CAT_DUENO, int $stock = 5): array
{
    return [
        'id' => $id, 'name' => $nombre, 'type' => 'SIMPLE', 'sku' => $sku,
        'sale_price' => '43000.00', 'suggested_price' => '43000.00', 'privated_product' => false,
        'user' => ['id' => $dueno], 'variations' => [],
        'warehouse_product' => [['warehouse_id' => 10548, 'stock' => $stock]],
    ];
}

function catVariable(int $id, string $sku, string $nombre, array $variaciones): array
{
    return [
        'id' => $id, 'name' => $nombre, 'type' => 'VARIABLE', 'sku' => $sku,
        'sale_price' => null, 'privated_product' => false, 'user' => ['id' => CAT_DUENO], 'warehouse_product' => [],
        'variations' => array_map(fn ($v) => [
            'id' => $v[0], 'sku' => '-0', 'sale_price' => '30000.00',
            'attribute_values' => [['attribute_name' => 'SET DE PIEZAS', 'value' => $v[1]]],
            'warehouse_product_variation' => [['warehouse_id' => 10548, 'stock' => $v[2]]],
        ], $variaciones),
    ];
}

function catPedido(string $guia, string $sku): PedidoDropiDTO
{
    return new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: 'O-' . $guia, transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 30000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO($sku, 1, 30000, 'Bloques', '40 PZS')],
        creadoAt: CarbonImmutable::now(),
    );
}

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Queue::fake();
    Sleep::fake();
    Storage::fake('local');
    config()->set('dropi.api.bodega_id', 10548);
    config()->set('dropi.api.proveedor_id', CAT_DUENO);
    app()->instance(DropiWebClient::class, new DropiApiClient(app(DropiWebAuth::class), [
        'url' => 'https://api.test/integrations/', 'key' => 'llave-test', 'timeout' => 5,
    ]));

    $this->venta = InventarioUbicacion::create(['codigo' => 'CAT-VENTA', 'nombre' => 'Venta', 'activa' => true, 'categoria' => 'venta']);
    $agr = fn (string $ref, string $nombre) => Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => $ref, 'nombre' => $nombre, 'desglose_stock' => false]));
    $this->tapete = $agr('D2341', 'Tapete mágico');
    $this->bloques = $agr('2801-16', 'Bloques suaves');
    $this->coche = Producto::create(['referencia' => 'DES-01', 'nombre' => 'Coche', 'desglose_stock' => true]);
    $this->ya = $agr('YA-01', 'Ya vinculado');
    VincularProductoDropi::make()->aProducto('YA-01', $this->ya->id);

    // Página 1: 40 (Dropi corta ahí) → hay que pedir la 2. Incluye uno de otro proveedor.
    $pagina1 = [
        catSimple(1, 'D2341', 'TAPETE MAGICO'),
        catVariable(2, '2801-16', 'BLOQUES SUAVES', [[2508490, '40 PZS', 47], [2508491, '80 PZS', 0]]),
        catSimple(3, 'DES-01', 'COCHE'),
        catSimple(4, 'combo', 'Combo A'),
        catSimple(5, 'combo', 'Combo B'),
        catSimple(6, 'YA-01', 'Ya vinculado'),
        catSimple(7, null, 'Sin SKU'),
        catSimple(8, 'AJENO-1', 'De otro proveedor', 999),
    ];
    for ($i = 100; count($pagina1) < 40; $i++) {
        $pagina1[] = catSimple($i, "OTRO-{$i}", "Relleno {$i}", 999);
    }
    $this->peticiones = [];
    $this->limitarVeces = 0;   // cuántas respuestas 429 da Dropi antes de contestar
    $this->lentoVeces = 0;     // cuántas veces Dropi se pasa de los 30 s (cURL 28)
    Http::fake(function (HttpRequest $r) use ($pagina1) {
        if ($this->lentoVeces > 0) {
            $this->lentoVeces--;
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 30013 milliseconds');
        }
        if ($this->limitarVeces > 0) {
            $this->limitarVeces--;

            return Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '1']);
        }
        $this->peticiones[] = $r->data();
        $objs = ($r->data()['startData'] ?? 0) === 0 ? $pagina1 : [catSimple(9, 'NUEVO-9', 'Producto nuevo')];

        return Http::response(['isSuccess' => true, 'objects' => $objs, 'count' => 0]);
    });
});

it('lee todas las páginas, filtra la bodega y descarta lo de otros proveedores', function () {
    $r = app(CatalogoDropi::class)->actualizar();

    expect($r['productos'])->toBe(8)   // 7 propios de la pág. 1 + 1 de la pág. 2
        ->and($this->peticiones)->toHaveCount(2)
        ->and($this->peticiones[0]['warehouse_id'])->toBe(10548)
        ->and($this->peticiones[1]['startData'])->toBe(40);
    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('dropi-integration-key', 'llave-test')
        && str_ends_with($r->url(), '/products/index'));
});

it('la vista previa decide cada llave como la armaría un pedido', function () {
    app(CatalogoDropi::class)->actualizar();
    $filas = collect(app(CatalogoDropi::class)->previsualizar()['filas'])->keyBy('llave');

    // Simple con SKU → la llave es el SKU; agregado con la misma referencia → se vincula.
    expect($filas['D2341']['estado'])->toBe('enlazar')
        ->and($filas['D2341']['destino'])->toBe(['tipo' => 'producto', 'id' => $this->tapete->id])
        // Variable → una llave por variación (`DV-`), las dos al mismo producto agregado.
        ->and($filas['DV-2508490']['estado'])->toBe('enlazar')
        ->and($filas['DV-2508491']['destino']['id'])->toBe($this->bloques->id)
        ->and($filas['DV-2508490']['variacion'])->toBe('40 PZS')
        ->and($filas['DV-2508490']['stock_dropi'])->toBe(47)
        // Producto con desglose: hay que elegir la variante a mano.
        ->and($filas['DES-01']['estado'])->toBe('revisar')
        // SKU repetido en Dropi: no se decide por SKU.
        ->and($filas['combo']['estado'])->toBe('revisar')
        ->and($filas['YA-01']['estado'])->toBe('vinculado')
        // Sin SKU → DP-<id>, como lo arma el pedido; no existe en el ERP.
        ->and($filas['DP-7']['estado'])->toBe('nuevo')
        ->and($filas['NUEVO-9']['estado'])->toBe('nuevo')
        ->and($filas->has('AJENO-1'))->toBeFalse();
});

it('vincular hace sólo lo seguro y los pedidos que esperaban toman el producto', function () {
    InventarioMovimiento::create(['producto_id' => $this->bloques->id, 'ubicacion_id' => $this->venta->id, 'tipo' => 'entrada_compra', 'cantidad' => 10, 'costo_unit' => 1000]);
    app(SincronizarPedidosDropi::class)->importarPedidos([catPedido('GC-1', 'DV-2508490')]);
    expect(DropiPedido::where('guia', 'GC-1')->value('estado'))->toBe(EstadoPedidoDropi::PendienteInventario);

    app(CatalogoDropi::class)->actualizar();
    $r = app(CatalogoDropi::class)->vincular(null);

    expect($r['vinculos'])->toBe(3)                // D2341, DV-2508490, DV-2508491
        ->and($r['items'])->toBe(1)
        ->and($r['pedidos_liberados'])->toBe(1)
        ->and($r['errores'])->toBe([])
        ->and(DropiPedido::where('guia', 'GC-1')->value('estado'))->toBe(EstadoPedidoDropi::Pending)
        ->and(DropiVinculoProducto::where('sku_dropi', 'DES-01')->exists())->toBeFalse()
        ->and(DropiVinculoProducto::where('sku_dropi', 'combo')->exists())->toBeFalse();

    // Dos veces seguidas no duplica nada.
    expect(app(CatalogoDropi::class)->vincular(null)['vinculos'])->toBe(0);
});

it('si Dropi limita, espera y reintenta la página', function () {
    // El cliente reintenta 3 veces por su cuenta; con 4 respuestas 429 seguidas
    // se rinde y el catálogo tiene que esperar 30 s y volver a pedir la página.
    $this->limitarVeces = 4;

    expect(app(CatalogoDropi::class)->actualizar()['productos'])->toBe(8);
    Sleep::assertSlept(fn ($d) => (int) $d->totalSeconds === 30, 1);
});

it('si Dropi tarda demasiado en una página, espera y la vuelve a pedir', function () {
    // El cliente reintenta 3 veces por su cuenta; la 4.ª la recoge el catálogo.
    $this->lentoVeces = 4;

    expect(app(CatalogoDropi::class)->actualizar()['productos'])->toBe(8);
    Sleep::assertSlept(fn ($d) => (int) $d->totalSeconds === 30, 1);
});

it('cuenta los pedidos que esperan cada llave y muestra las que no están en el catálogo', function () {
    app(SincronizarPedidosDropi::class)->importarPedidos([
        catPedido('GW-1', 'DV-2508490'), catPedido('GW-2', 'DV-2508490'), catPedido('GW-3', 'ARCHIVADO-1'),
    ]);
    app(CatalogoDropi::class)->actualizar();

    $previa = app(CatalogoDropi::class)->previsualizar();
    $filas = collect($previa['filas'])->keyBy('llave');

    expect($filas['DV-2508490']['pedidos_esperando'])->toBe(2)
        ->and($filas['D2341']['pedidos_esperando'])->toBe(0)
        ->and($filas['ARCHIVADO-1']['estado'])->toBe('sin_catalogo')
        ->and($filas['ARCHIVADO-1']['pedidos_esperando'])->toBe(1)
        ->and($previa['resumen']['sin_catalogo'])->toBe(1)
        ->and($previa['resumen']['con_pedidos'])->toBe(2);
});

it('desde la pantalla se busca y se vincula una llave a un producto agregado o a una variante', function () {
    $aracely = User::factory()->create();
    $aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    InventarioMovimiento::create(['producto_id' => $this->tapete->id, 'ubicacion_id' => $this->venta->id, 'tipo' => 'entrada_compra', 'cantidad' => 3, 'costo_unit' => 1000]);
    app(SincronizarPedidosDropi::class)->importarPedidos([catPedido('GV-1', 'ARCHIVADO-2')]);
    app(CatalogoDropi::class)->actualizar();

    $this->actingAs($aracely)->getJson('/app/dropi/catalogo/buscar?q=tapete')
        ->assertOk()
        ->assertJsonFragment(['valor' => 'p:' . $this->tapete->id, 'agregado' => true]);

    $this->actingAs($aracely)->post('/app/dropi/catalogo/vincular-llave', ['llave' => 'ARCHIVADO-2', 'destino' => 'p:' . $this->tapete->id])
        ->assertSessionHas('success');

    expect(DropiVinculoProducto::where('sku_dropi', 'ARCHIVADO-2')->value('producto_id'))->toBe($this->tapete->id)
        ->and(DropiPedido::where('guia', 'GV-1')->value('estado'))->toBe(EstadoPedidoDropi::Pending);

    // Un producto con desglose no se vincula entero: avisa, no rompe.
    $this->actingAs($aracely)->post('/app/dropi/catalogo/vincular-llave', ['llave' => 'DES-01', 'destino' => 'p:' . $this->coche->id])
        ->assertSessionHas('error');
    // Destino con otro formato: no pasa la validación.
    $this->actingAs($aracely)->post('/app/dropi/catalogo/vincular-llave', ['llave' => 'X', 'destino' => '5'])
        ->assertSessionHasErrors('destino');

    $alistador = User::factory()->create();
    $alistador->assignRole(Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']));
    $this->actingAs($alistador)->getJson('/app/dropi/catalogo/buscar?q=tapete')->assertForbidden();
    $this->actingAs($alistador)->post('/app/dropi/catalogo/vincular-llave', ['llave' => 'D2341', 'destino' => 'p:' . $this->tapete->id])->assertForbidden();
});

it('la pantalla es de Aracely, encola la lectura y vincula', function () {
    $aracely = User::factory()->create();
    $aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $alistador = User::factory()->create();
    $alistador->assignRole(Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']));

    $this->actingAs($alistador)->get('/app/dropi/catalogo')->assertForbidden();

    $this->actingAs($aracely)->get('/app/dropi/catalogo')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Dropi/Catalogo')->where('leido_at', null));

    $this->actingAs($aracely)->post('/app/dropi/catalogo/leer')->assertRedirect();
    Queue::assertPushed(LeerCatalogoDropiJob::class);

    app(CatalogoDropi::class)->actualizar();
    $this->actingAs($aracely)->get('/app/dropi/catalogo')
        ->assertInertia(fn (Assert $p) => $p->where('resumen.enlazar', 3)->where('resumen.productos', 8));

    $this->actingAs($aracely)->post('/app/dropi/catalogo/vincular')->assertSessionHas('success');
    expect(DropiVinculoProducto::count())->toBe(4);   // YA-01 + los 3 nuevos
});
