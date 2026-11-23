<?php

/**
 * Productos que bodega crea directo en Dropi y llegan en pedidos sin existir en
 * el ERP: se crean y vinculan solos. Lo que no frena pedidos se queda quieto.
 *
 * Caso real del 23-nov-2026: «Robot bailarín Dancing 360» (sin SKU → DP-<id>)
 * y «Tablero de dibujo led» (SKU DIDA-004) frenaban 4 pedidos; otras 123
 * llaves sin vincular no tenían pedidos y no se debían tocar.
 */

use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Dropi\Actions\CrearProductosNuevosDropi;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\Clients\DropiApiClient;
use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiPedidoItem;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\CatalogoDropi;
use App\Modules\Dropi\Services\DropiWebAuth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Spatie\Permission\Models\Role;

const CPN_DUENO = 151057;

function cpnSimple(int $id, ?string $sku, string $nombre): array
{
    return [
        'id' => $id, 'name' => $nombre, 'type' => 'SIMPLE', 'sku' => $sku, 'description' => "<p>{$nombre} de Dropi</p>",
        'sale_price' => '43000.00', 'suggested_price' => '60000.00', 'privated_product' => false,
        'user' => ['id' => CPN_DUENO], 'variations' => [],
        'warehouse_product' => [['warehouse_id' => 10548, 'stock' => 9]],
    ];
}

function cpnVariable(int $id, string $sku, string $nombre, array $variaciones): array
{
    return [
        'id' => $id, 'name' => $nombre, 'type' => 'VARIABLE', 'sku' => $sku,
        'sale_price' => null, 'privated_product' => false, 'user' => ['id' => CPN_DUENO], 'warehouse_product' => [],
        'variations' => array_map(fn ($v) => [
            'id' => $v[0], 'sku' => '-0', 'sale_price' => '30000.00',
            'attribute_values' => [['attribute_name' => 'COLOR', 'value' => $v[1]]],
            'warehouse_product_variation' => [['warehouse_id' => 10548, 'stock' => 3]],
        ], $variaciones),
    ];
}

function cpnPedido(string $guia, string $sku, string $nombre = 'Juguete'): PedidoDropiDTO
{
    return new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: 'O-' . $guia, transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 30000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO($sku, 1, 30000, $nombre, null)],
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
    Cache::flush();
    config()->set('dropi.api.bodega_id', 10548);
    config()->set('dropi.api.proveedor_id', CPN_DUENO);
    app()->instance(DropiWebClient::class, new DropiApiClient(app(DropiWebAuth::class), [
        'url' => 'https://api.test/integrations/', 'key' => 'llave-test', 'timeout' => 5,
    ]));

    $this->catalogoDropi = [
        cpnSimple(2352474, null, 'Robot bailarín Dancing 360'),
        cpnSimple(2350019, 'DIDA-004', 'Tablero de dibujo led, pizarra luminosa'),
        cpnSimple(30, 'QUIETO-1', 'Sin pedidos, no se toca'),
        cpnVariable(40, 'VAR-40', 'Pelota sensorial', [[401, 'ROJO'], [402, 'AZUL']]),
        cpnVariable(50, 'MIX-50', 'Mitad vinculado', [[501, 'ROJO'], [502, 'AZUL']]),
        cpnSimple(60, 'PAPEL-1', 'Está en la papelera del ERP'),
    ];
    $this->lecturas = 0;
    Http::fake(function (HttpRequest $r) {
        $this->lecturas++;

        return Http::response(['isSuccess' => true, 'objects' => $this->catalogoDropi, 'count' => 0]);
    });

    // DV-501 ya apunta a un producto del ERP: ese producto de Dropi no es nuevo.
    $existente = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => 'EXIST-50', 'nombre' => 'Pelota ya en el ERP', 'desglose_stock' => false]));
    VincularProductoDropi::make()->aProducto('DV-501', $existente->id);
    Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => 'PAPEL-1', 'nombre' => 'Borrado', 'desglose_stock' => false]))->delete();

    Role::findOrCreate('Gerencia', 'web');
    $this->jefa = User::factory()->create();
    $this->jefa->assignRole('Gerencia');
    $this->otro = User::factory()->create();

    app(SincronizarPedidosDropi::class)->importarPedidos([
        cpnPedido('NV-1', 'DP-2352474', 'Robot bailarín Dancing 360'),
        cpnPedido('NV-2', 'DP-2352474', 'Robot bailarín Dancing 360'),
        cpnPedido('NV-3', 'DIDA-004', 'Tablero de dibujo led'),
        cpnPedido('NV-4', 'DV-401', 'Pelota sensorial'),
        cpnPedido('NV-5', 'DV-502', 'Mitad vinculado'),
        cpnPedido('NV-6', 'PAPEL-1', 'Está en la papelera'),
    ]);
    app(CatalogoDropi::class)->actualizar();
    $this->lecturas = 0;
});

it('crea y vincula solo los productos nuevos que frenan pedidos', function () {
    $r = app(CrearProductosNuevosDropi::class)->handle();

    $creados = collect($r['creados'])->keyBy('referencia');
    expect($creados->keys()->sort()->values()->all())->toBe(['DIDA-004', 'DROPI-2352474', 'VAR-40']);

    // Sin SKU → DROPI-<id>; con SKU → el SKU. Sin variantes, stock 0, con su id de Dropi.
    $robot = Producto::where('referencia', 'DROPI-2352474')->firstOrFail();
    expect($robot->nombre)->toBe('Robot bailarín Dancing 360')
        ->and((bool) $robot->desglose_stock)->toBeFalse()
        ->and($robot->dropi_sku)->toBe('2352474')
        ->and($robot->descripcion)->toBe('Robot bailarín Dancing 360 de Dropi')
        ->and(DropiVinculoProducto::where('sku_dropi', 'DP-2352474')->value('producto_id'))->toBe($robot->id)
        ->and(DropiPedidoItem::where('sku_dropi', 'DP-2352474')->pluck('producto_id')->unique()->all())->toBe([$robot->id]);

    // Las dos variaciones quedan en la misma ficha, aunque solo una tenga pedidos:
    //   cada una en su variante (ver «con variaciones nace con una variante por color»).
    $pelota = Producto::where('referencia', 'VAR-40')->firstOrFail();
    expect(DropiVinculoProducto::whereIn('sku_dropi', ['DV-401', 'DV-402'])->with('variante')->get()
        ->pluck('variante.producto_id')->unique()->all())->toBe([$pelota->id]);

    // Sin stock los pedidos siguen esperando: el stock lo carga bodega.
    expect(DropiPedido::where('guia', 'NV-1')->value('estado'))->toBe(EstadoPedidoDropi::PendienteInventario);
});

it('con variaciones nace con una variante por color, cada una con su código de barras', function () {
    app(CrearProductosNuevosDropi::class)->handle();

    // Bodega, 23-nov-2026: «que no me salga generalizado, no voy a saber cuáles eran».
    $pelota = Producto::where('referencia', 'VAR-40')->firstOrFail();
    $variantes = $pelota->variantes()->orderBy('dropi_variacion_id')->get();

    expect((bool) $pelota->desglose_stock)->toBeTrue()
        ->and($variantes->pluck('color_nombre')->all())->toBe(['ROJO', 'AZUL'])
        ->and($variantes->pluck('codigo_barras')->all())->toBe(['VAR-40-ROJO', 'VAR-40-AZUL'])
        ->and($variantes->pluck('dropi_variacion_id')->all())->toBe(['401', '402'])
        // Cada llave de Dropi en su variante, y el pedido que esperaba ya apunta al color.
        ->and(DropiVinculoProducto::where('sku_dropi', 'DV-401')->value('variante_id'))->toBe($variantes[0]->id)
        ->and(DropiVinculoProducto::where('sku_dropi', 'DV-402')->value('variante_id'))->toBe($variantes[1]->id)
        ->and(DropiPedidoItem::where('sku_dropi', 'DV-401')->value('variante_id'))->toBe($variantes[0]->id);

    // La siguiente lectura las reconoce: ya no son «nuevo» ni se vuelven a crear.
    $filas = collect(app(CatalogoDropi::class)->previsualizar()['filas'])->whereIn('llave', ['DV-401', 'DV-402']);
    expect($filas->pluck('estado')->unique()->all())->not->toContain('nuevo');

    // Los SIMPLE siguen agrupados, sin variantes.
    $robot = Producto::where('referencia', 'DROPI-2352474')->firstOrFail();
    expect((bool) $robot->desglose_stock)->toBeFalse()->and($robot->variantes()->count())->toBe(0);
});

it('las etiquetas del producto nuevo con colores salen con un código por color', function () {
    app(CrearProductosNuevosDropi::class)->handle();
    $codigos = null;
    \Illuminate\Support\Facades\View::composer('catalogo.pdf.etiquetas-dropi', function ($v) use (&$codigos) {
        $codigos = array_column($v->getData()['etiquetas'], 'codigo');
    });

    $this->seed(\Database\Seeders\PermisosSeeder::class);
    $aracely = User::factory()->create();
    $aracely->assignRole(Role::findOrCreate('Aracely', 'web'));
    $this->actingAs($aracely)->get(route('catalogo.etiquetas.dropi', 40))->assertOk();

    expect($codigos)->toBe(['VAR-40-ROJO', 'VAR-40-AZUL']);
});

it('deja quietos los que no tienen pedidos, los que ya tienen algo en el ERP y los de la papelera', function () {
    $r = app(CrearProductosNuevosDropi::class)->handle();

    expect(Producto::where('referencia', 'QUIETO-1')->exists())->toBeFalse()
        ->and(DropiVinculoProducto::where('sku_dropi', 'QUIETO-1')->exists())->toBeFalse()
        // Mitad vinculado: no se crea otra ficha para la otra variación.
        ->and(Producto::where('referencia', 'MIX-50')->exists())->toBeFalse()
        ->and(DropiVinculoProducto::where('sku_dropi', 'DV-502')->exists())->toBeFalse()
        // La referencia vive en la papelera: no se crea, se avisa.
        ->and(Producto::withTrashed()->where('referencia', 'PAPEL-1')->count())->toBe(1)
        ->and($r['errores'])->toHaveCount(1)
        ->and($r['errores'][0])->toContain('PAPEL-1');
});

it('avisa en la campana a quien vincula productos de Dropi', function () {
    app(CrearProductosNuevosDropi::class)->handle();

    $robot = Producto::where('referencia', 'DROPI-2352474')->firstOrFail();
    $aviso = NotificacionErp::where('user_id', $this->jefa->id)->where('url', "/app/catalogo/productos/{$robot->id}")->first();

    expect($aviso)->not->toBeNull()
        ->and($aviso->tipo)->toBe('dropi_producto_creado')
        ->and($aviso->mensaje)->toContain('Falta cargar su stock y su costo')
        ->and(NotificacionErp::where('user_id', $this->otro->id)->exists())->toBeFalse();
});

it('una segunda corrida no duplica nada', function () {
    app(CrearProductosNuevosDropi::class)->handle();
    $antes = Producto::withTrashed()->count();

    $r = app(CrearProductosNuevosDropi::class)->handle();

    expect($r['creados'])->toBe([])
        ->and(Producto::withTrashed()->count())->toBe($antes);
});

it('simular no escribe nada', function () {
    $antes = Producto::withTrashed()->count();

    $this->artisan('dropi:crear-productos-nuevos', ['--simular' => true])
        ->expectsOutputToContain('Simulación: no se escribió nada.')
        ->assertSuccessful();

    expect(Producto::withTrashed()->count())->toBe($antes)
        ->and(DropiVinculoProducto::where('sku_dropi', 'DP-2352474')->exists())->toBeFalse()
        ->and(NotificacionErp::count())->toBe(0);
});

it('si un pedido trae un producto que la foto no tiene, relee Dropi una vez por hora', function () {
    $this->catalogoDropi[] = cpnSimple(70, 'RECIEN-70', 'Recién creado en Dropi');
    app(SincronizarPedidosDropi::class)->importarPedidos([cpnPedido('NV-7', 'RECIEN-70', 'Recién creado')]);

    $r = app(CrearProductosNuevosDropi::class)->handle();

    expect($r['releido'])->toBeTrue()
        ->and($this->lecturas)->toBe(1)
        ->and(Producto::where('referencia', 'RECIEN-70')->exists())->toBeTrue();

    // Una llave que sigue sin estar en Dropi (archivada) no hace releer en cada corrida.
    app(SincronizarPedidosDropi::class)->importarPedidos([cpnPedido('NV-8', 'ARCHIVADO-9')]);
    expect(app(CrearProductosNuevosDropi::class)->handle()['releido'])->toBeFalse()
        ->and($this->lecturas)->toBe(1);
});
