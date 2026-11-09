<?php

/**
 * Fase 1 de compras en el panel de Dropi: armar la OC, aprobarla y recibirla.
 *
 * Lo que importa probar: que la orden nace en la línea Dropi y que todo lo que
 * cuelga de ella (la recepción, el inventario, el asiento contable) sale de
 * Dropi, con las mismas acciones que /app. Y que desde este panel no se abre
 * una orden de Great Baby.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Filament\Pages\NuevaOrdenCompraDropi;
use App\Modules\Dropi\Filament\Pages\RegistrarRecepcionDropi;
use App\Modules\Dropi\Filament\Pages\VerOrdenCompraDropi;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->seed(Database\Seeders\PermisosSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->deshacerRepeater = Repeater::fake();

    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->bodega = InventarioUbicacion::create(['codigo' => 'ECO-1', 'nombre' => 'Bodega e-commerce', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true]);
    $this->proveedor = Contacto::create(['numero_documento' => '901222333', 'nombre_completo' => 'Juguetes del Valle', 'es_proveedor' => true]);
    $this->producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'DR-ABE', 'nombre' => 'Abejita musical', 'activo' => true, 'desglose_stock' => false, 'precio_proveedor' => 18000,
    ]));
});

afterEach(fn () => ($this->deshacerRepeater)());

function ocDropiPorPantalla(float $cantidad = 10, float $precio = 18000): OrdenCompra
{
    test()->actingAs(test()->aracely);
    Livewire::test(NuevaOrdenCompraDropi::class)
        ->fillForm([
            'proveedor_id' => test()->proveedor->id,
            'bodega_id' => test()->bodega->id,
            'items' => [[
                'producto_id' => test()->producto->id, 'descripcion' => 'Abejita musical',
                'cantidad' => $cantidad, 'precio_unit' => $precio, 'descuento_pct' => 0, 'iva_pct' => 0,
            ]],
        ])
        ->call('crear')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    return OrdenCompra::latest('id')->firstOrFail();
}

it('«Nueva OC» crea la orden en la línea Dropi, en borrador', function () {
    $oc = ocDropiPorPantalla(10, 18000);

    expect($oc->linea_negocio)->toBe(LineaNegocio::Dropi)
        ->and($oc->estado)->toBe(EstadoOrdenCompra::Borrador)
        ->and($oc->tipo)->toBe('nacional')
        ->and((float) $oc->total)->toBe(180000.0)
        ->and($oc->items)->toHaveCount(1)
        ->and($oc->items->first()->producto_id)->toBe($this->producto->id);
});

it('aprobar y recibir: sube el inventario y la recepción y su asiento son de Dropi', function () {
    $oc = ocDropiPorPantalla(10, 18000);

    Livewire::test(VerOrdenCompraDropi::class, ['orden' => $oc->id])
        ->callAction('aprobar')
        ->assertNotified("OC {$oc->numero} aprobada");
    expect($oc->fresh()->estado)->toBe(EstadoOrdenCompra::Aprobada);

    Livewire::test(RegistrarRecepcionDropi::class, ['orden' => $oc->id])
        ->fillForm([
            'factura_proveedor' => 'FV-889',
            'items' => [['orden_item_id' => $oc->items->first()->id, 'cantidad_recibida' => 10, 'clasificacion' => 'apto']],
        ])
        ->call('confirmar')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $rc = RecepcionCompra::where('orden_id', $oc->id)->firstOrFail();
    expect($rc->estado)->toBe('confirmada')
        ->and($rc->linea_negocio)->toBe(LineaNegocio::Dropi)
        ->and($rc->factura_proveedor)->toBe('FV-889')
        ->and($oc->fresh()->estado)->toBe(EstadoOrdenCompra::Recibida)
        ->and((int) InventarioMovimiento::where('producto_id', $this->producto->id)->sum('cantidad'))->toBe(10);

    $asiento = MovimientoContable::where('origen_type', RecepcionCompra::class)->where('origen_id', $rc->id)->get();
    expect($asiento)->not->toBeEmpty()
        ->and($asiento->every(fn ($m) => $m->linea_negocio === LineaNegocio::Dropi))->toBeTrue();
});

it('recibir de a partes deja la orden parcial y sólo pide lo que falta', function () {
    $oc = ocDropiPorPantalla(10, 18000);
    Livewire::test(VerOrdenCompraDropi::class, ['orden' => $oc->id])->callAction('aprobar');

    Livewire::test(RegistrarRecepcionDropi::class, ['orden' => $oc->id])
        ->fillForm(['items' => [['orden_item_id' => $oc->items->first()->id, 'cantidad_recibida' => 4, 'clasificacion' => 'apto']]])
        ->call('confirmar')->assertHasNoFormErrors();

    expect($oc->fresh()->estado)->toBe(EstadoOrdenCompra::Parcial);

    $linea = collect(Livewire::test(RegistrarRecepcionDropi::class, ['orden' => $oc->id])->get('data.items'))->first();
    expect((float) $linea['pendiente'])->toBe(6.0)
        ->and((float) $linea['cantidad_recibida'])->toBe(6.0);
});

it('anular pide motivo y duplicar conserva la línea Dropi', function () {
    $oc = ocDropiPorPantalla(5, 18000);

    Livewire::test(VerOrdenCompraDropi::class, ['orden' => $oc->id])
        ->callAction('duplicar');
    $copia = OrdenCompra::latest('id')->first();
    expect($copia->id)->not->toBe($oc->id)
        ->and($copia->linea_negocio)->toBe(LineaNegocio::Dropi);

    Livewire::test(VerOrdenCompraDropi::class, ['orden' => $oc->id])
        ->callAction('anular', ['motivo' => 'corto'])
        ->assertHasFormErrors(['motivo']);
    Livewire::test(VerOrdenCompraDropi::class, ['orden' => $oc->id])
        ->callAction('anular', ['motivo' => 'El proveedor no tiene el producto'])
        ->assertHasNoFormErrors();
    expect($oc->fresh()->estado)->toBe(EstadoOrdenCompra::Anulada);
});

it('duplicar desde /app también conserva la línea (antes la copia nacía de Great Baby)', function () {
    $oc = ocDropiPorPantalla(5, 18000);

    $this->post("/app/compras/oc/{$oc->id}/duplicar")->assertRedirect();

    expect(OrdenCompra::latest('id')->first()->linea_negocio)->toBe(LineaNegocio::Dropi);
});

it('desde el panel de Dropi no se abre una orden de Great Baby', function () {
    $gb = CrearOrdenCompra::run([
        'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
        'items' => [['descripcion' => 'GB', 'cantidad' => 1, 'precio_unit' => 1000]],
    ]);
    expect($gb->linea_negocio)->toBe(LineaNegocio::GreatBaby);

    $this->actingAs($this->aracely)->get(VerOrdenCompraDropi::getUrl(['orden' => $gb->id]))->assertNotFound();
    $this->actingAs($this->aracely)->get(RegistrarRecepcionDropi::getUrl(['orden' => $gb->id]))->assertNotFound();
});

it('el proveedor se crea desde la orden con la misma regla que /app', function () {
    $this->actingAs($this->aracely);
    Contacto::create(['numero_documento' => '800999888', 'nombre_completo' => 'Ya es cliente', 'es_cliente' => true, 'es_proveedor' => false]);

    $r = \App\Modules\Compras\Actions\RegistrarProveedor::run(['nombre' => 'Otro nombre', 'tipo_documento' => 'NIT', 'numero_documento' => '800999888']);

    expect($r['ya_existia'])->toBeTrue()
        ->and($r['contacto']->es_proveedor)->toBeTrue()
        ->and(Contacto::where('numero_documento', '800999888')->count())->toBe(1);
});

it('el Alistador no arma órdenes de Dropi', function () {
    $alistador = User::factory()->create();
    $alistador->assignRole(Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']));

    $this->actingAs($alistador)->get(NuevaOrdenCompraDropi::getUrl())->assertForbidden();
});
