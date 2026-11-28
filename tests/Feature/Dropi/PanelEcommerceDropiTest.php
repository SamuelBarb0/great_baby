<?php

/**
 * El e-commerce dentro del panel Dropi (/admin): sólo «Productos de Dropi»
 * (página del panel) y «Mis productos en Dropi», que lleva dentro «Crear
 * producto en Dropi» y «Editar en Dropi» (ventana aparte: Dropi no se deja
 * embeber). Y que «Productos sin vincular» ya no cuente lo vinculado a un
 * producto agregado.
 */

use App\Models\User;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Pages\ProductosDeDropi;
use App\Modules\Dropi\Jobs\LeerCatalogoDropiJob;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\CatalogoDropi;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function admUsuario(string $rol): User
{
    $u = User::factory()->create();
    $u->assignRole(Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']));

    return $u;
}

function admPedido(string $guia, string $sku): PedidoDropiDTO
{
    return new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: 'O-' . $guia, transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 12500, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO($sku, 1, 12500, 'Volante de Juguete con Música', 'Negro-amarillo')],
        creadoAt: CarbonImmutable::now(),
    );
}

/** Foto del catálogo escrita a mano, con la forma que deja CatalogoDropi::actualizar(). */
function admFoto(array $productosDropi): void
{
    Storage::disk('local')->put(CatalogoDropi::FOTO, json_encode([
        'leido_at' => now()->toIso8601String(),
        'productos' => array_map(fn ($p) => CatalogoDropi::resumir($p), $productosDropi),
    ]));
}

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Queue::fake();
    Storage::fake('local');
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    $this->aracely = admUsuario('Aracely');
    Filament::setCurrentPanel('admin');

    $venta = InventarioUbicacion::create(['codigo' => 'ADM-VENTA', 'nombre' => 'Venta', 'activa' => true, 'categoria' => 'venta']);
    $this->volante = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => '899', 'nombre' => 'VOLANTE CON SONIDO', 'desglose_stock' => false,
    ]));
    InventarioMovimiento::create(['producto_id' => $this->volante->id, 'ubicacion_id' => $venta->id, 'tipo' => 'entrada_compra', 'cantidad' => 50, 'costo_unit' => 5000]);

    admFoto([[
        'id' => 1, 'name' => 'Volante de Juguete con Música', 'type' => 'VARIABLE', 'sku' => null, 'user' => ['id' => 151057],
        'warehouse_product' => [],
        'variations' => [['id' => 1843712, 'sku' => '-0', 'sale_price' => '12500.00',
            'attribute_values' => [['value' => 'Negro-amarillo']], 'warehouse_product_variation' => [['stock' => 1147]]]],
    ]]);
});

it('el panel Dropi está organizado en Operación, Productos, Dinero y Configuración', function () {
    $alistador = admUsuario('Alistador');
    $this->actingAs($alistador)->get('/admin/productos-de-dropi')->assertForbidden();
    $this->actingAs($alistador)->get('/admin/mis-productos-dropi')->assertForbidden();

    $html = $this->actingAs($this->aracely)->get('/admin/productos-de-dropi')->assertOk()
        ->assertSee('Mis productos en Dropi')
        ->assertSee('Vincular productos')
        // «Crear producto en Dropi» ya no está en el menú: vive en «Mis productos en Dropi».
        ->assertDontSee('Crear producto en Dropi')
        ->assertDontSee('https://app.dropi.co/dashboard/products/add', false)
        // Se abre en ventana al lado, no en pestaña: el script la intercepta.
        ->assertSee('gb_dropi_crear', false)
        ->assertDontSee('Productos sin vincular')
        ->assertDontSee('E-commerce')
        ->getContent();

    // Los grupos, en este orden.
    $pos = fn (string $t) => mb_strpos($html, '>' . $t . '<') ?: mb_strpos($html, $t);
    expect($pos('Operación'))->toBeLessThan($pos('Productos'))
        ->and($pos('Productos'))->toBeLessThan($pos('Dinero'))
        ->and($pos('Dinero'))->toBeLessThan($pos('Configuración'));

    $this->actingAs($this->aracely)->get('/admin/mis-productos-dropi')->assertOk()
        ->assertSee('Crear producto en Dropi')
        ->assertSee('https://app.dropi.co/dashboard/products/add', false)
        // La carga masiva ya no manda a las plantillas de Dropi (no aplicaban
        // los cambios, 18-nov-2026): va a la carga del ERP.
        ->assertSee('Cargar productos y stock (Excel)')
        ->assertSee('/app/dropi/carga-productos', false)
        ->assertDontSee('href="https://app.dropi.co/dashboard/products"', false)
        // El script abre en ventana todo lo que cuelga de /dashboard/products.
        ->assertSee('"https:\/\/app.dropi.co\/dashboard\/products"', false);

    $this->actingAs($this->aracely)->get('/admin/ventas-ecommerce')->assertNotFound();
});

it('Mis productos en Dropi muestra el catálogo sin fotos, con precio, stock y si está vinculado', function () {
    admFoto([
        [
            'id' => 1, 'name' => 'Volante de Juguete con Música', 'type' => 'VARIABLE', 'sku' => null, 'user' => ['id' => 151057],
            'categories' => [['name' => 'Bebé']], 'description' => '<p>Volante con <b>luces</b> y música</p>',
            'gallery' => [['main' => false, 'url' => null, 'urlS3' => 'colombia/products/1/otra.jpg'], ['main' => true, 'url' => null, 'urlS3' => 'colombia/products/1/principal.gif']],
            'warehouse_product' => [],
            'variations' => [
                ['id' => 1843712, 'sku' => '-0', 'sale_price' => '12500.00', 'attribute_values' => [['value' => 'Negro-amarillo']], 'warehouse_product_variation' => [['stock' => 10]]],
                ['id' => 1843713, 'sku' => '-0', 'sale_price' => '13000.00', 'attribute_values' => [['value' => 'Rosado-celeste']], 'warehouse_product_variation' => [['stock' => 0]]],
            ],
        ],
        ['id' => 2, 'name' => 'Cascos para niños', 'type' => 'SIMPLE', 'sku' => 'CASCO', 'sale_price' => '45000', 'user' => ['id' => 151057],
         'variations' => [], 'warehouse_product' => [['stock' => 0]], 'gallery' => []],
    ]);
    VincularProductoDropi::make()->aProducto('DV-1843712', $this->volante->id);
    $this->actingAs($this->aracely);

    Livewire::test(\App\Modules\Dropi\Filament\Pages\MisProductosDropi::class)
        ->assertSee('Volante de Juguete con Música')
        ->assertDontSee('principal.gif', false)                                     // Dropi no deja subir fotos: no se muestran
        ->assertSee('$12.500 – $13.000')
        ->assertSee('Stock 10')
        ->assertSee('Falta en parte (1 de 2)')                                     // 1 de 2 variaciones
        ->assertSee('Bebé')
        ->assertSee('Cascos para niños')
        ->assertDontSee('Sin foto')
        // Cada tarjeta lleva a su formulario de edición en Dropi, por su ID de Dropi.
        ->assertSee('Editar en Dropi')
        ->assertSee('https://app.dropi.co/dashboard/products/edit?id=1', false)
        ->assertSee('https://app.dropi.co/dashboard/products/edit?id=2', false)
        ->set('filtro', 'sin_stock')
        ->assertSee('Cascos para niños')
        ->assertDontSee('Volante de Juguete con Música')
        ->set('filtro', 'todos')
        ->set('buscar', 'rosado')                                                   // busca también en variaciones
        ->assertSee('Volante de Juguete con Música')
        ->assertDontSee('Cascos para niños');

    $foto = app(CatalogoDropi::class)->foto()['productos'][0];
    expect($foto['descripcion'])->toBe('Volante con luces y música')
        ->and($foto)->not->toHaveKey('imagen');                                      // tampoco se guardan
});

it('Mis productos en Dropi avisa si todavía no se ha leído Dropi', function () {
    Storage::disk('local')->delete(CatalogoDropi::FOTO);
    $this->actingAs($this->aracely);

    Livewire::test(\App\Modules\Dropi\Filament\Pages\MisProductosDropi::class)
        ->assertSee('Todavía no se ha leído el catálogo de Dropi');
});

it('Productos de Dropi en /admin muestra lo que espera y vincula una llave a un producto agregado', function () {
    app(SincronizarPedidosDropi::class)->importarPedidos([admPedido('GADM-1', 'DV-1843712'), admPedido('GADM-2', 'DV-1843712')]);
    $this->actingAs($this->aracely);

    Livewire::test(ProductosDeDropi::class)
        ->assertSet('filtro', null)                       // arranca por «con pedidos esperando»
        ->assertSee('Volante de Juguete con Música')
        ->assertSee('DV-1843712')
        ->callAction('vincularLlave', ['destino' => 'p:' . $this->volante->id], ['llave' => 'DV-1843712', 'nombre' => 'Volante'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(DropiVinculoProducto::where('sku_dropi', 'DV-1843712')->value('producto_id'))->toBe($this->volante->id)
        ->and(DropiPedido::whereIn('guia', ['GADM-1', 'GADM-2'])->pluck('estado')->unique()->all())
        ->toBe([EstadoPedidoDropi::Pending]);
});

it('Leer de Dropi encola la lectura y Vincular hace lo seguro', function () {
    // Mismo SKU en Dropi y en el ERP → seguro.
    $tapete = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => 'D2341', 'nombre' => 'Tapete', 'desglose_stock' => false]));
    admFoto([['id' => 2, 'name' => 'TAPETE MAGICO', 'type' => 'SIMPLE', 'sku' => 'D2341', 'sale_price' => '20000', 'user' => ['id' => 151057], 'variations' => [], 'warehouse_product' => []]]);
    $this->actingAs($this->aracely);

    Livewire::test(ProductosDeDropi::class)
        ->callAction('leer')
        ->callAction('vincularSeguros');

    Queue::assertPushed(LeerCatalogoDropiJob::class);
    expect(DropiVinculoProducto::where('sku_dropi', 'D2341')->value('producto_id'))->toBe($tapete->id);
});

it('«Productos sin vincular» ya no existe: lo vinculado a un producto agregado sale de «con pedidos esperando»', function () {
    app(SincronizarPedidosDropi::class)->importarPedidos([admPedido('GADM-3', 'DV-1843712')]);
    expect(app(CatalogoDropi::class)->previsualizar()['resumen']['con_pedidos'])->toBe(1);

    VincularProductoDropi::make()->aProducto('DV-1843712', $this->volante->id);

    expect(app(CatalogoDropi::class)->previsualizar()['resumen']['con_pedidos'])->toBe(0);
    $this->actingAs($this->aracely)->get('/admin/productos-sin-vincular')->assertNotFound();
    $this->actingAs($this->aracely)->get('/admin/productos-de-dropi')->assertOk()->assertDontSee('Productos sin vincular');
});
