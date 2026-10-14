<?php

/**
 * El stock inicial entra donde la persona dice, no donde caiga.
 *
 * Encontrado el 2026-10-13 montando una bodega desde cero: se crea la bodega
 * nueva, se escriben 60 unidades en «Stock inicial» del producto… y las 60
 * aparecen en otra bodega. El formulario pedía la cantidad y nunca el lugar, y
 * por dentro se tomaba **la primera ubicación activa por id**.
 *
 * El error es invisible: el producto queda creado, el stock existe, los
 * números cuadran. Sólo se descubre cuando alguien va a despachar y la
 * mercancía no está donde debía.
 *
 * Se conserva el comportamiento viejo cuando nadie elige, porque los
 * importadores y los seeders no mandan ubicación y no hay que romperlos.
 */

use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    // La primera por id: la que el código elegía solo.
    $this->vieja = InventarioUbicacion::create([
        'codigo' => 'A-VIEJA', 'nombre' => 'Bodega de siempre',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $this->nueva = InventarioUbicacion::create([
        'codigo' => 'Z-NUEVA', 'nombre' => 'Bodega recién creada',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
});

function crearProductoWeb($test, array $extra = [])
{
    return $test->actingAs($test->admin)->post('/app/catalogo/productos', array_merge([
        'referencia' => 'GB-'.fake()->unique()->numerify('####'),
        'nombre' => 'Producto de prueba',
        'desglose_stock' => false,
        'stock_directo' => 40,
    ], $extra));
}

function dondeQuedo(string $referencia): ?string
{
    $p = Producto::where('referencia', $referencia)->firstOrFail();

    return InventarioMovimiento::where('producto_id', $p->id)
        ->with('ubicacion:id,codigo')->first()?->ubicacion?->codigo;
}

it('deja el stock en la bodega que eligió la persona', function () {
    crearProductoWeb($this, [
        'referencia' => 'GB-ELEGIDA',
        'stock_ubicacion_id' => $this->nueva->id,
    ])->assertRedirect();

    expect(dondeQuedo('GB-ELEGIDA'))->toBe('Z-NUEVA');
});

it('sin elegir bodega se comporta como antes, en la primera activa', function () {
    // Los importadores y seeders no mandan ubicación: romperlos sería peor
    // que el problema que esto resuelve.
    crearProductoWeb($this, ['referencia' => 'GB-SINELEGIR'])->assertRedirect();

    expect(dondeQuedo('GB-SINELEGIR'))->toBe('A-VIEJA');
});

it('rechaza una bodega que no existe', function () {
    crearProductoWeb($this, [
        'referencia' => 'GB-INVENTADA',
        'stock_ubicacion_id' => 999999,
    ])->assertSessionHasErrors('stock_ubicacion_id');

    expect(Producto::where('referencia', 'GB-INVENTADA')->exists())->toBeFalse();
});

it('sin stock inicial no inventa ningún movimiento', function () {
    crearProductoWeb($this, [
        'referencia' => 'GB-SINSTOCK',
        'stock_directo' => 0,
        'stock_ubicacion_id' => $this->nueva->id,
    ])->assertRedirect();

    expect(dondeQuedo('GB-SINSTOCK'))->toBeNull();
});

it('el formulario ofrece las bodegas para elegir', function () {
    // Sin esta lista el selector sale vacío y volvemos al problema original.
    $catalogos = $this->actingAs($this->admin)->get('/app/catalogo/productos/nuevo')
        ->assertOk()->viewData('page')['props']['catalogos'];

    expect($catalogos)->toHaveKey('ubicaciones')
        ->and(collect($catalogos['ubicaciones'])->pluck('codigo'))
        ->toContain('Z-NUEVA');
});

it('el formulario no ofrece las bodegas importadas de SIIGO', function () {
    InventarioUbicacion::create([
        'codigo' => 'SIIGO-16', 'nombre' => 'Bodega ajena del catálogo',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $catalogos = $this->actingAs($this->admin)->get('/app/catalogo/productos/nuevo')
        ->assertOk()->viewData('page')['props']['catalogos'];

    expect(collect($catalogos['ubicaciones'])->pluck('codigo'))->not->toContain('SIIGO-16');
});
