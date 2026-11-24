<?php

/**
 * Vista previa del Excel de compras de Dropi (24-nov-2026): «que tengan una
 * vista previa para asignárselo a un producto».
 *
 * Subir el Excel no crea nada: cada orden se abre llena en «Nueva OC», con el
 * producto y la variante ya puestos donde casaron y marcados donde no, para
 * escogerlos antes de crear. Lo del Excel no entra a un producto con variantes
 * sin la suya.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Filament\Pages\NuevaOrdenCompraDropi;
use App\Modules\Dropi\Filament\Pages\OrdenesCompraDropi;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Dropi\Services\ImportadorOrdenesCompraDropi;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->seed(Database\Seeders\PermisosSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->deshacerRepeater = Repeater::fake();

    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->actingAs($this->aracely);

    $this->bodega = InventarioUbicacion::create(['codigo' => 'VP-RA1', 'nombre' => 'Rack A · Nivel 01', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true]);

    $this->peluche = Producto::create(['referencia' => 'VP-PEL', 'nombre' => 'PELUCHE', 'activo' => true, 'desglose_stock' => true]);
    $this->rosa = ProductoVariante::create(['producto_id' => $this->peluche->id, 'color_codigo' => 'ROS', 'color_nombre' => 'ROSA', 'codigo_barras' => 'VP-PEL-ROS']);
    $this->azul = ProductoVariante::create(['producto_id' => $this->peluche->id, 'color_codigo' => 'AZU', 'color_nombre' => 'AZUL', 'codigo_barras' => 'VP-PEL-AZU']);
    $this->guitarra = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => 'VP-GUIT', 'nombre' => 'GUITARRA', 'activo' => true, 'desglose_stock' => false]));
});

afterEach(fn () => ($this->deshacerRepeater)());

/** Dos órdenes: la 1 con una línea que casa, una con color que no está y un color nuevo; la 2 limpia. */
function vpExcel(): string
{
    $cols = ImportadorOrdenesCompraDropi::COLUMNAS;
    $base = ['proveedor_nit' => '900777001', 'proveedor_nombre' => 'Juguetes Nuevos SAS', 'bodega' => 'Rack A · Nivel 01', 'iva_pct' => 0];
    $filas = [
        ['referencia_oc' => 'VP-1', 'producto_referencia' => 'VP-PEL', 'color' => 'rosa', 'cantidad' => 60, 'costo_unitario' => 23492],
        ['referencia_oc' => 'VP-1', 'producto_referencia' => 'VP-PEL', 'color' => 'FUCSIA', 'cantidad' => 60, 'costo_unitario' => 23492],
        ['referencia_oc' => 'VP-1', 'producto_referencia' => 'VP-GUIT', 'color' => 'ROJO', 'cantidad' => 96, 'costo_unitario' => 11020],
        ['referencia_oc' => 'VP-2', 'producto_referencia' => 'VP-GUIT', 'cantidad' => 10, 'costo_unitario' => 11020],
    ];
    $ruta = tempnam(sys_get_temp_dir(), 'vp').'.xlsx';
    $w = new Writer();
    $w->openToFile($ruta);
    $w->addRow(Row::fromValues($cols));
    foreach ($filas as $f) {
        $f += $base;
        $w->addRow(Row::fromValues(array_map(fn ($c) => $f[$c] ?? '', $cols)));
    }
    $w->close();

    return $ruta;
}

it('analizar no escribe nada: ni órdenes, ni proveedores, ni colores', function () {
    $antes = [OrdenCompra::count(), Contacto::count(), ProductoVariante::count()];

    $r = app(ImportadorOrdenesCompraDropi::class)->analizar(vpExcel());

    expect([OrdenCompra::count(), Contacto::count(), ProductoVariante::count()])->toBe($antes)
        ->and($r['errores'])->toBe([])
        ->and($r['ordenes'])->toHaveCount(2);

    [$uno] = $r['ordenes'];
    expect($uno['proveedor_id'])->toBeNull()
        ->and($uno['proveedor_pendiente'])->toBe(['nit' => '900777001', 'nombre' => 'Juguetes Nuevos SAS'])
        ->and($uno['bodega_id'])->toBe($this->bodega->id)
        ->and($uno['items'][0]['variante_id'])->toBe($this->rosa->id)
        ->and($uno['items'][0]['aviso'])->toBeNull()
        ->and($uno['items'][1]['variante_id'])->toBeNull()
        ->and($uno['items'][1]['aviso'])->toContain('FUCSIA')->toContain('Tiene: ROSA, AZUL')
        ->and($uno['items'][2]['color_nuevo'])->toBe('ROJO')
        ->and($uno['items'][2]['aviso'])->toBeNull();
});

it('«Importar desde Excel» no crea nada y abre la primera orden en «Nueva OC»', function () {
    Livewire::test(OrdenesCompraDropi::class)
        ->callAction('importar', data: [
            'archivo' => UploadedFile::fake()->createWithContent('compra-prueba.xlsx', file_get_contents(vpExcel())),
        ])
        ->assertHasNoActionErrors()
        ->assertRedirect(NuevaOrdenCompraDropi::getUrl(['desde' => 'excel']));

    expect(OrdenCompra::count())->toBe(0)
        ->and(session(NuevaOrdenCompraDropi::SESION_IMPORTACION)['ordenes'])->toHaveCount(2);
});

it('«Nueva OC» se llena con la orden del Excel y no deja crear hasta escoger la variante que no casó', function () {
    $r = app(ImportadorOrdenesCompraDropi::class)->analizar(vpExcel());
    NuevaOrdenCompraDropi::empezarImportacion($r['ordenes'], 'compra-prueba.xlsx');

    $pagina = Livewire::withQueryParams(['desde' => 'excel'])->test(NuevaOrdenCompraDropi::class)
        ->assertSee('Orden 1 de 2')
        ->assertSee('VP-1')
        ->assertSee('1 línea marcada')
        ->assertSee('FUCSIA')
        ->assertSee('Se creará el color «ROJO»')
        ->assertSee('Juguetes Nuevos SAS')
        ->assertSee('Crear OC y seguir con la 2')
        ->assertSet('data.items.0.variante_id', $this->rosa->id)
        ->assertSet('data.items.1.variante_id', null);

    $pagina->call('crear')->assertHasFormErrors(['items.1.variante_id' => 'required']);
    expect(OrdenCompra::count())->toBe(0);

    $pagina->set('data.items.1.variante_id', $this->azul->id)
        ->call('crear')
        ->assertHasNoFormErrors()
        ->assertRedirect(NuevaOrdenCompraDropi::getUrl(['desde' => 'excel']));

    $oc = OrdenCompra::with('items', 'proveedor')->sole();
    $rojo = ProductoVariante::where('producto_id', $this->guitarra->id)->sole();
    expect($oc->items->pluck('variante_id')->all())->toBe([$this->rosa->id, $this->azul->id, $rojo->id])
        ->and($rojo->color_nombre)->toBe('ROJO')
        ->and($rojo->codigo_barras)->not->toBeEmpty()
        ->and($oc->bodega_id)->toBe($this->bodega->id)
        ->and($oc->proveedor->numero_documento)->toBe('900777001')
        ->and($oc->proveedor->en_dropi)->toBeTrue()
        ->and((float) $oc->items->sum(fn ($i) => $i->cantidad * $i->precio_unit))->toBe(60 * 23492 * 2 + 96 * 11020.0)
        ->and(session(NuevaOrdenCompraDropi::SESION_IMPORTACION)['actual'])->toBe(1);
});

it('«Saltar esta orden» no la crea, y al terminar vuelve a la lista con el resumen', function () {
    $r = app(ImportadorOrdenesCompraDropi::class)->analizar(vpExcel());
    NuevaOrdenCompraDropi::empezarImportacion($r['ordenes'], 'compra-prueba.xlsx');

    Livewire::withQueryParams(['desde' => 'excel'])->test(NuevaOrdenCompraDropi::class)
        ->call('saltar')
        ->assertRedirect(NuevaOrdenCompraDropi::getUrl(['desde' => 'excel']));

    // La 2 es de la guitarra sin color: casa sola. Es la última: vuelve a la lista.
    Livewire::withQueryParams(['desde' => 'excel'])->test(NuevaOrdenCompraDropi::class)
        ->assertSee('Orden 2 de 2')
        ->assertSee('Todo casó con el ERP')
        ->call('crear')
        ->assertHasNoFormErrors()
        ->assertRedirect(OrdenesCompraDropi::getUrl())
        ->assertNotified('Importación de «compra-prueba.xlsx» terminada');

    $oc = OrdenCompra::with('items')->sole();
    expect($oc->items->sole()->producto_id)->toBe($this->guitarra->id)
        ->and($oc->items->sole()->variante_id)->toBeNull()
        ->and(session()->has(NuevaOrdenCompraDropi::SESION_IMPORTACION))->toBeFalse()
        ->and(ProductoVariante::where('producto_id', $this->guitarra->id)->count())->toBe(0);
});

it('sin órdenes del Excel pendientes, «Nueva OC» abre vacía como siempre', function () {
    Livewire::withQueryParams(['desde' => 'excel'])->test(NuevaOrdenCompraDropi::class)
        ->assertDontSee('Orden 1 de')
        ->assertSet('desde', null)
        ->assertNotified('No hay órdenes del Excel por revisar');
});
