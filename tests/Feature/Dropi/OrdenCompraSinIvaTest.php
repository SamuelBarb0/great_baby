<?php

/**
 * Órdenes de compra con o sin IVA (25-nov-2026: «debes poder seleccionar si
 * llevan o si no llevan IVA»): al crearlas, en el panel Dropi y en /app, y
 * mientras siguen en borrador.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Compras\Actions\CambiarIvaOrdenCompra;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Filament\Pages\NuevaOrdenCompraDropi;
use App\Modules\Dropi\Filament\Pages\VerOrdenCompraDropi;
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

/** Una OC nacional en borrador: 10 × $18.000 por línea, con el IVA de cada una. */
function ocConLineas(array $ivas): OrdenCompra
{
    return CrearOrdenCompra::run([
        'proveedor_id' => test()->proveedor->id,
        'bodega_id' => test()->bodega->id,
        'linea_negocio' => LineaNegocio::Dropi,
        'items' => array_map(fn ($iva) => [
            'producto_id' => test()->producto->id, 'descripcion' => 'Abejita musical',
            'cantidad' => 10, 'precio_unit' => 18000, 'iva_pct' => $iva,
        ], $ivas),
    ]);
}

function ocPorPantallaDropi(bool $llevaIva): OrdenCompra
{
    test()->actingAs(test()->aracely);
    Livewire::test(NuevaOrdenCompraDropi::class)
        ->fillForm([
            'proveedor_id' => test()->proveedor->id,
            'bodega_id' => test()->bodega->id,
            'lleva_iva' => $llevaIva,
            'items' => [[
                'producto_id' => test()->producto->id, 'descripcion' => 'Abejita musical',
                'cantidad' => 10, 'precio_unit' => 18000, 'descuento_pct' => 0, 'iva_pct' => 19,
            ]],
        ])
        ->call('crear')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    return OrdenCompra::latest('id')->firstOrFail();
}

it('«Nueva OC» del panel Dropi marcada sin IVA deja la orden en 0 % aunque la línea diga 19', function () {
    $oc = ocPorPantallaDropi(false);

    expect((float) $oc->iva)->toBe(0.0)
        ->and((float) $oc->total)->toBe(180000.0)
        ->and((float) $oc->items->first()->iva_pct)->toBe(0.0);
});

it('«Nueva OC» del panel Dropi con IVA conserva el 19 % de la línea', function () {
    $oc = ocPorPantallaDropi(true);

    expect((float) $oc->iva)->toBe(34200.0)
        ->and((float) $oc->total)->toBe(214200.0);
});

it('marcar el interruptor en No pone todas las líneas del formulario en 0 %', function () {
    $this->actingAs($this->aracely);

    $form = Livewire::test(NuevaOrdenCompraDropi::class)
        ->fillForm(['items' => [['iva_pct' => 19], ['iva_pct' => 5]]])
        ->fillForm(['lleva_iva' => false]);

    expect(collect($form->get('data.items'))->pluck('iva_pct')->all())->toBe([0, 0]);
});

it('en borrador, quitar el IVA deja todo en 0 y ponerlo vuelve a 19 sólo lo que estaba en 0', function () {
    $oc = ocConLineas([19, 5]);

    $oc = CambiarIvaOrdenCompra::run($oc, false);
    expect((float) $oc->iva)->toBe(0.0)
        ->and((float) $oc->total)->toBe(360000.0)
        ->and($oc->items()->pluck('iva_pct')->map(fn ($v) => (float) $v)->all())->toBe([0.0, 0.0]);

    $otra = ocConLineas([0, 5]);
    $otra = CambiarIvaOrdenCompra::run($otra, true);
    expect($otra->items()->orderBy('id')->pluck('iva_pct')->map(fn ($v) => (float) $v)->all())->toBe([19.0, 5.0])
        ->and((float) $otra->iva)->toBe(34200.0 + 9000.0)
        ->and((float) $otra->total)->toBe(360000.0 + 43200.0);
});

it('una orden aprobada o de importación no cambia su IVA', function () {
    $aprobada = ocConLineas([19]);
    $aprobada->update(['estado' => EstadoOrdenCompra::Aprobada]);
    expect(fn () => CambiarIvaOrdenCompra::run($aprobada, false))
        ->toThrow(InvalidArgumentException::class, 'borrador');

    $imp = ocConLineas([0]);
    $imp->update(['tipo' => 'importacion']);
    expect(fn () => CambiarIvaOrdenCompra::run($imp, true))
        ->toThrow(InvalidArgumentException::class, 'aduana');

    expect((float) $aprobada->fresh()->iva)->toBe(34200.0);
});

it('la vista de la OC en el panel Dropi quita y pone el IVA del borrador', function () {
    $oc = ocConLineas([19]);
    $this->actingAs($this->aracely);

    Livewire::test(VerOrdenCompraDropi::class, ['orden' => $oc->id])
        ->assertActionVisible('iva')
        ->callAction('iva');
    expect((float) $oc->fresh()->iva)->toBe(0.0);

    Livewire::test(VerOrdenCompraDropi::class, ['orden' => $oc->id])->callAction('iva');
    expect((float) $oc->fresh()->iva)->toBe(34200.0);

    $oc->update(['estado' => EstadoOrdenCompra::Aprobada]);
    Livewire::test(VerOrdenCompraDropi::class, ['orden' => $oc->id])->assertActionHidden('iva');
});

it('/app quita el IVA del borrador y lo dice en la orden', function () {
    $oc = ocConLineas([19]);

    $this->actingAs($this->aracely)
        ->post(route('app.compras.oc.iva', $oc), ['lleva_iva' => false])
        ->assertRedirect()->assertSessionHas('success');
    expect((float) $oc->fresh()->iva)->toBe(0.0);

    $this->get(route('app.compras.oc.show', $oc))
        ->assertInertia(fn ($p) => $p->where('orden.lleva_iva', false)->where('puede_armar', true));
});

it('/app crea la orden sin IVA cuando se marca así', function () {
    $this->actingAs($this->aracely)
        ->post(route('app.compras.oc.crear'), [
            'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
            'tipo' => 'nacional', 'moneda' => 'COP', 'tasa_cambio' => 1, 'lleva_iva' => false,
            'items' => [['producto_id' => $this->producto->id, 'descripcion' => 'Abejita musical', 'cantidad' => 10, 'precio_unit' => 18000, 'iva_pct' => 19]],
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect((float) OrdenCompra::latest('id')->first()->iva)->toBe(0.0);
});
