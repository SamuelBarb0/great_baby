<?php

/**
 * Lo que se pidió en la reunión del 19-nov-2026 para la primera prueba en bodega:
 * el «chulito» del alistador en el modo Recolección (guardado y compartido entre
 * tablets), las pestañas de la lista de pedidos por etapa, el catálogo del ERP
 * dentro del panel Dropi, la ruta de la bodega en recolección y empaque, el PDF
 * de la recolección y la foto de empaque opcional.
 */

use App\Models\User;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Pages\EstacionEmpaque;
use App\Modules\Dropi\Filament\Pages\VistaAlistador;
use App\Modules\Dropi\Filament\Resources\DropiPedidoResource\Pages\ListDropiPedidos;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiRecoleccionMarca;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Logistica\Support\ZonaDeLaBodega;
use App\Support\Reglas;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Queue::fake();
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->aracely = User::factory()->create(['name' => 'Aracely']);
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->luli = User::factory()->create(['name' => 'Luli']);
    $this->luli->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    // Una referencia con variantes y una agregada (sin variantes), que es como
    // están casi todos los productos que vinieron de Dropi.
    $coche = Producto::create(['referencia' => 'COCHE-01', 'nombre' => 'Coche', 'desglose_stock' => true]);
    $azul = ProductoVariante::create(['producto_id' => $coche->id, 'color_codigo' => 'AZU', 'color_nombre' => 'Azul', 'codigo_barras' => '7700000000999']);
    VincularProductoDropi::run('DV-555', $azul->id);
    $volante = Producto::create(['referencia' => '899', 'nombre' => 'Volante', 'desglose_stock' => false]);
    app(VincularProductoDropi::class)->aProducto('SKU-899', $volante->id);
});

/** El importador le asigna el corte del día (AsignarCorteACarga), el que abre la vista. */
function pedidoEnCorte(string $guia, array $items): DropiPedido
{
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: 'O-'.$guia, transportadora: 'X', tienda: 'T', vendedorNombre: 'Vendedora',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 30000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: array_map(fn ($i) => new ItemDropiDTO($i[0], $i[1], 30000, 'x', 'x'), $items),
        creadoAt: CarbonImmutable::now(),
    )]);
    return DropiPedido::where('guia', $guia)->firstOrFail();
}

function recoleccionPorSku(): array
{
    return collect(Livewire::test(VistaAlistador::class)->instance()->recoleccion)->keyBy('sku')->all();
}

it('la recolección nombra también los productos agregados, no solo los de variante', function () {
    pedidoEnCorte('G-1', [['DV-555', 2], ['SKU-899', 3]]);
    $this->actingAs($this->aracely);

    $r = recoleccionPorSku();
    expect($r['DV-555'])->producto->toBe('Coche')->variante->toBe('Azul')->total_unidades->toBe(2)->marcada->toBeFalse()
        ->and($r['SKU-899'])->producto->toBe('Volante')->referencia->toBe('899')->total_unidades->toBe(3);
});

it('el chulito queda guardado con quién y cuándo, lo ve otra tablet y se puede desmarcar', function () {
    pedidoEnCorte('G-1', [['SKU-899', 3]]);
    $this->travelTo(now()->setTime(8, 15));

    $this->actingAs($this->aracely);
    Livewire::test(VistaAlistador::class)->call('marcarRecogido', 'SKU-899')
        ->assertSeeText('Aracely · 08:15')->assertSeeText('1 de 1 referencias recogidas');

    $marca = DropiRecoleccionMarca::sole();
    expect($marca->corte_id)->toBe(DropiPedido::sole()->corte_id)->and($marca->cantidad)->toBe(3)->and($marca->marcado_por)->toBe($this->aracely->id);

    // Otra persona, en otra tablet, la ve marcada; tocarla otra vez la desmarca.
    $this->actingAs($this->luli);
    expect(recoleccionPorSku()['SKU-899']['marcada'])->toBeTrue();
    Livewire::test(VistaAlistador::class)->call('marcarRecogido', 'SKU-899')->assertSeeText('0 de 1 referencias recogidas');
    expect(DropiRecoleccionMarca::count())->toBe(0);
});

it('si entra otro pedido con la referencia ya marcada, vuelve a pendiente con lo que falta', function () {
    pedidoEnCorte('G-1', [['SKU-899', 3]]);
    $this->actingAs($this->aracely);
    Livewire::test(VistaAlistador::class)->call('marcarRecogido', 'SKU-899');

    pedidoEnCorte('G-2', [['SKU-899', 2]]);
    expect(recoleccionPorSku()['SKU-899'])->marcada->toBeFalse()->faltan->toBe(2)->total_unidades->toBe(5);

    // Tocarla de nuevo la marca con el total nuevo, no la desmarca.
    Livewire::test(VistaAlistador::class)->call('marcarRecogido', 'SKU-899')->assertSeeText('1 de 1 referencias recogidas');
    expect(DropiRecoleccionMarca::sole()->cantidad)->toBe(5);
});

it('sin acceso a la vista del alistador no se marca nada', function () {
    $p = pedidoEnCorte('G-1', [['SKU-899', 3]]);
    $this->actingAs(User::factory()->create());

    expect(VistaAlistador::canAccess())->toBeFalse();
    $pagina = new VistaAlistador();
    $pagina->corteId = $p->corte_id;
    $pagina->marcarRecogido('SKU-899');
    expect(DropiRecoleccionMarca::count())->toBe(0);
});

it('la lista de pedidos se separa en pestañas por etapa, con «Todos» por defecto', function () {
    $nuevo = pedidoEnCorte('G-1', [['SKU-899', 1]]);
    $alistando = pedidoEnCorte('G-2', [['SKU-899', 1]]);
    $alistando->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->aracely->id);
    $this->actingAs($this->aracely);

    $lista = Livewire::test(ListDropiPedidos::class);
    expect($lista->get('activeTab'))->toBe('todos');
    $lista->assertCanSeeTableRecords([$nuevo, $alistando]);

    $lista->set('activeTab', 'nuevos')->assertCanSeeTableRecords([$nuevo])->assertCanNotSeeTableRecords([$alistando]);
    $lista->set('activeTab', 'por_empacar')->assertCanSeeTableRecords([$alistando])->assertCanNotSeeTableRecords([$nuevo]);

    $tabs = $lista->instance()->getTabs();
    expect($tabs['nuevos']->getBadge())->toEqual(1)->and($tabs['por_empacar']->getBadge())->toEqual(1)->and($tabs['empacados']->getBadge())->toEqual(0);
});

it('el catálogo se ve dentro del panel Dropi, sólo para quien tiene el permiso de productos', function () {
    // 23-nov-2026: ya no es un enlace al catálogo del ERP («que no se me vaya al ERP»).
    expect(collect(Filament::getPanel('admin')->getNavigationItems())->map(fn ($i) => $i->getUrl())->all())
        ->not->toContain('/app/catalogo/productos');

    $this->actingAs($this->aracely);
    expect(App\Modules\Dropi\Filament\Pages\CatalogoProductosDropi::canAccess())->toBeTrue();
    $this->get('/admin/catalogo-productos')->assertOk();

    $this->actingAs(User::factory()->create());
    expect(App\Modules\Dropi\Filament\Pages\CatalogoProductosDropi::canAccess())->toBeFalse();
});

/** Una posición de venta en un rack del plano, con saldo de lo que se le pase. */
function posicionConSaldo(string $codigo, string $rack, array $saldos): InventarioUbicacion
{
    $u = InventarioUbicacion::create([
        'codigo' => $codigo, 'nombre' => $codigo, 'categoria' => CategoriaUbicacion::Venta,
        'activa' => true, 'pasillo' => $rack,
    ]);
    foreach ($saldos as [$campo, $id, $cantidad]) {
        InventarioMovimiento::create([
            $campo => $id, 'ubicacion_id' => $u->id, 'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => 9000,
        ]);
    }

    return $u;
}

/** El coche azul en el rack 7 (zona 2) y el volante en el rack 1 (zona 1, junto a la entrada). */
function bodegaConRuta(): void
{
    $azul = ProductoVariante::where('codigo_barras', '7700000000999')->sole();
    posicionConSaldo('R7-A-1', '7', [['variante_id', $azul->id, 10]]);
    posicionConSaldo('R1-A-1', '1', [['producto_id', Producto::where('referencia', '899')->value('id'), 10]]);
}

it('la recolección sale en el orden del recorrido, con la ubicación y la zona de cada referencia', function () {
    bodegaConRuta();
    pedidoEnCorte('G-1', [['DV-555', 2], ['SKU-NADA', 1], ['SKU-899', 3]]);
    $this->actingAs($this->aracely);

    $r = Livewire::test(VistaAlistador::class)->instance()->recoleccion;
    expect(array_column($r, 'sku'))->toBe(['SKU-899', 'DV-555', 'SKU-NADA'])
        ->and($r[0])->ubicacion_sugerida->toBe('R1-A-1')->zona->toBe(ZonaDeLaBodega::deRack('1'))
        ->and($r[1])->ubicacion_sugerida->toBe('R7-A-1')->zona->toBe(ZonaDeLaBodega::deRack('7'))
        ->and(ZonaDeLaBodega::deRack('1'))->not->toBe(ZonaDeLaBodega::deRack('7'))
        ->and($r[2])->ubicacion_sugerida->toBe('—')->zona->toBeNull();
});

it('la cola de empaque también va en el orden de la ruta', function () {
    bodegaConRuta();
    $this->travelTo(now()->subMinutes(5));
    $lejos = pedidoEnCorte('G-1', [['DV-555', 1]]);
    $this->travelBack();
    $cerca = pedidoEnCorte('G-2', [['SKU-899', 1]]);
    $this->actingAs($this->aracely);

    expect(Livewire::test(VistaAlistador::class)->instance()->empaque->pluck('guia')->all())->toBe(['G-2', 'G-1']);
});

it('el PDF de la recolección lo baja el alistador, con casillas y la ruta por zonas', function () {
    bodegaConRuta();
    $p = pedidoEnCorte('G-1', [['DV-555', 2], ['SKU-899', 3]]);
    $this->actingAs($this->aracely);
    Livewire::test(VistaAlistador::class)->call('marcarRecogido', 'SKU-899');

    $this->get(route('dropi.recoleccion.pdf', $p->corte_id))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $html = view('dropi.pdf.recoleccion', [
        'corte' => $p->corte, 'filas' => app(\App\Modules\Dropi\Support\RecoleccionDelCorte::class)->conMarcas($p->corte_id),
        'unidades' => 5, 'generado' => now(),
    ])->render();
    expect($html)->toContain('Zona '.ZonaDeLaBodega::deRack('1'))->toContain('Zona '.ZonaDeLaBodega::deRack('7'))->toContain('R1-A-1')->toContain('R7-A-1')
        ->and(strpos($html, 'Volante'))->toBeLessThan(strpos($html, 'Coche'))
        ->and(substr_count($html, 'class="hecha"'))->toBe(1);

    $this->actingAs(User::factory()->create());
    $this->get(route('dropi.recoleccion.pdf', $p->corte_id))->assertForbidden();
});

it('la estación de empaque del panel deja confirmar sin foto cuando la regla está apagada', function () {
    bodegaConRuta();
    $p = pedidoEnCorte('G-1', [['SKU-899', 1]]);
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->aracely->id);
    $p->items()->update(['cantidad_pickeada' => 1]);
    EmpaqueRegistro::create([
        'pedido_id' => $p->id, 'operario_id' => $this->aracely->id, 'inicio_at' => now(),
        'items_totales' => 1, 'items_escaneados' => 1, 'estado' => 'en_curso',
    ]);
    $this->actingAs($this->aracely);

    // Con la regla prendida (como está hoy) sigue pidiendo la foto.
    Reglas::set('empaque.foto_obligatoria', true);
    Livewire::test(EstacionEmpaque::class)->call('confirmarYSiguiente');
    expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Alistando);

    Reglas::set('empaque.foto_obligatoria', false);
    Livewire::test(EstacionEmpaque::class)->call('confirmarYSiguiente');
    expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Empacado);
});

it('el panel enlaza a Dropi para el escaneo de la entrega a la transportadora', function () {
    $item = fn () => collect(Filament::getPanel('admin')->getNavigationItems())->first(fn ($i) => $i->getLabel() === 'Escaneo Dropi');

    $this->actingAs($this->aracely);
    expect($item())->not->toBeNull()
        ->and($item()->getUrl())->toBe('https://app.dropi.co/dashboard/home')
        ->and($item()->shouldOpenUrlInNewTab())->toBeTrue()
        ->and($item()->isVisible())->toBeTrue();

    $this->actingAs(User::factory()->create());
    expect($item()->isVisible())->toBeFalse();
});

it('el escáner de despacho no ofrece despachar un pedido que todavía no se empacó', function () {
    $p = pedidoEnCorte('G-1', [['SKU-899', 1]]);
    $this->actingAs($this->aracely);

    $r = $this->postJson('/dropi/escaner/analizar', ['guia' => 'G-1'])->assertOk()->json();
    expect($r['accion'])->toBe('sin_empacar')->and($r['titulo'])->toContain('no está empacado');

    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->aracely->id);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->aracely->id);
    expect($this->postJson('/dropi/escaner/analizar', ['guia' => 'G-1'])->json('accion'))->toBe('despachar');
});

it('el escáner de guías por cámara sale del menú y del buscador: la entrega se escanea en Dropi', function () {
    $this->actingAs($this->aracely);

    $menu = collect(Filament::getPanel('admin')->getNavigationItems())->map(fn ($i) => $i->getLabel());
    expect($menu)->not->toContain('Escáner cámara')
        ->and($menu)->toContain('Escaneo Dropi')
        ->and(json_encode($this->getJson('/app/buscar?q=camara')->json()))->not->toContain('escaner-camara');
});

it('las dos estaciones de empaque traen el botón para escanear con la cámara', function () {
    expect(file_get_contents(resource_path('views/dropi/pages/estacion-empaque.blade.php')))->toContain('gbCamaraCodigos.abrir')
        ->and(file_get_contents(resource_path('js/Pages/EstacionEmpaque.vue')))->toContain('gbCamaraCodigos.abrir')
        ->and(file_exists(public_path('js/gb-camara-codigos.js')))->toBeTrue()
        ->and(file_exists(public_path('js/vendor/zxing-0.21.3.min.js')))->toBeTrue();
});

/**
 * Bodega, 23-nov-2026: la hoja salía con «—» en VARIANTE en todas las filas.
 * Casi todo lo de Dropi está vinculado a un producto SIN variantes: no hay
 * variante del ERP, pero Dropi manda la variación en cada línea del pedido.
 */
it('la recolección, el PDF y el empaque muestran la variación de Dropi cuando no hay variante del ERP', function () {
    $cuatrimoto = Producto::create(['referencia' => 'BY-002', 'nombre' => 'Cuatrimoto', 'desglose_stock' => false]);
    app(VincularProductoDropi::class)->aProducto('DV-2427000', $cuatrimoto->id);
    app(VincularProductoDropi::class)->aProducto('DV-2427001', $cuatrimoto->id);

    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: 'G-VAR', dropiOrdenId: 'O-G-VAR', transportadora: 'X', tienda: 'T', vendedorNombre: 'Vendedora',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 30000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [
            new ItemDropiDTO('DV-2427000', 1, 30000, 'CUATRIMOTO', 'VERDE'),
            new ItemDropiDTO('DV-2427001', 2, 30000, 'CUATRIMOTO', 'ROJO'),
            new ItemDropiDTO('DV-555', 1, 30000, 'COCHE', 'AZUL DROPI'),
        ],
        creadoAt: CarbonImmutable::now(),
    )]);
    $p = DropiPedido::where('guia', 'G-VAR')->firstOrFail();
    $this->actingAs($this->aracely);

    $r = recoleccionPorSku();
    expect($r['DV-2427000'])->producto->toBe('Cuatrimoto')->variante->toBe('VERDE')
        ->and($r['DV-2427001'])->variante->toBe('ROJO')
        // Con variante del ERP manda la del ERP.
        ->and($r['DV-555'])->variante->toBe('Azul');

    $html = view('dropi.pdf.recoleccion', [
        'corte' => $p->corte, 'filas' => app(\App\Modules\Dropi\Support\RecoleccionDelCorte::class)->conMarcas($p->corte_id),
        'unidades' => 4, 'generado' => now(),
    ])->render();
    expect($html)->toContain('<td>VERDE</td>')->toContain('<td>ROJO</td>');

    // Empaque y escáner usan lo mismo; un producto simple de Dropi no tiene variación.
    $items = $p->items()->get()->keyBy('sku_dropi');
    expect($items['DV-2427001']->nombreVariante())->toBe('ROJO')
        ->and((new \App\Modules\Dropi\Models\DropiPedidoItem(['variacion' => null]))->nombreVariante())->toBe('');
});
