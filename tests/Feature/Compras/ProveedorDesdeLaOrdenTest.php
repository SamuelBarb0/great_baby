<?php

/**
 * Compras cambia mucho de proveedor, y crearlo exigía la pantalla de Contactos,
 * que es de contabilidad porque también muestra a los clientes. Ahora se crea
 * desde la orden nueva, con lo mínimo, sin ver nada de los demás contactos.
 */

use App\Models\Contacto;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    (require database_path('migrations/2026_11_05_110000_rol_de_compras.php'))->up();

    $this->compras = User::factory()->create();
    $this->compras->assignRole('Compras');
});

it('crea el proveedor y queda para elegir en la orden', function () {
    $r = $this->actingAs($this->compras)->postJson('/app/compras/proveedor', [
        'nombre' => 'Ningbo Topwin Trading', 'tipo_documento' => 'NIT', 'numero_documento' => 'NB-2024-778',
        'email' => 'ventas@topwin.cn', 'telefono' => '+86 574 0000',
    ])->assertCreated()->assertJsonPath('proveedor.nombre', 'Ningbo Topwin Trading');

    $c = Contacto::findOrFail($r->json('proveedor.id'));
    expect($c->es_proveedor)->toBeTrue()
        ->and($c->activo)->toBeTrue()
        ->and($c->es_cliente)->toBeFalsy()
        ->and($c->email)->toBe('ventas@topwin.cn');

    $this->actingAs($this->compras)->get('/app/compras/oc/nueva')
        ->assertInertia(fn ($p) => $p->where('proveedores', fn ($lista) => collect($lista)->contains('id', $c->id)));
});

it('si el documento ya es de un cliente, lo marca proveedor sin mostrar sus datos', function () {
    $cliente = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900123456', 'nombre_completo' => 'Bebés del Norte SAS',
        'email' => 'cartera@bebesdelnorte.co', 'telefono' => '3000000000', 'es_cliente' => true, 'activo' => true,
    ]);

    $r = $this->actingAs($this->compras)->postJson('/app/compras/proveedor', [
        'nombre' => 'Otro nombre', 'tipo_documento' => 'NIT', 'numero_documento' => '900123456',
    ])->assertOk()->assertJsonPath('proveedor.id', $cliente->id)->assertJsonPath('ya_existia', true);

    expect(json_encode($r->json()))->not->toContain('cartera@')->not->toContain('3000000000');
    $cliente->refresh();
    expect($cliente->es_proveedor)->toBeTrue()
        ->and($cliente->es_cliente)->toBeTrue()
        ->and($cliente->nombre_completo)->toBe('Bebés del Norte SAS');
    expect(Contacto::where('numero_documento', '900123456')->count())->toBe(1);
});

it('no revive contactos eliminados ni inactivos', function () {
    $borrado = Contacto::create(['tipo_documento' => 'NIT', 'numero_documento' => '111', 'nombre_completo' => 'Viejo', 'es_proveedor' => true, 'activo' => true]);
    $borrado->delete();
    Contacto::create(['tipo_documento' => 'NIT', 'numero_documento' => '222', 'nombre_completo' => 'Inactivo', 'es_proveedor' => true, 'activo' => false]);

    $this->actingAs($this->compras)->postJson('/app/compras/proveedor', ['nombre' => 'X', 'tipo_documento' => 'NIT', 'numero_documento' => '111'])
        ->assertUnprocessable()->assertJsonValidationErrors('numero_documento');
    $this->actingAs($this->compras)->postJson('/app/compras/proveedor', ['nombre' => 'X', 'tipo_documento' => 'NIT', 'numero_documento' => '222'])
        ->assertUnprocessable()->assertJsonValidationErrors('numero_documento');
});

it('pide nombre y documento, en español', function () {
    $this->actingAs($this->compras)->postJson('/app/compras/proveedor', ['tipo_documento' => 'NIT'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.nombre.0', 'Escriba el nombre del proveedor.')
        ->assertJsonPath('errors.numero_documento.0', 'Escriba el número de documento. Si es del exterior, el de su registro o su factura.');
});

it('sólo lo usa quien arma órdenes', function () {
    $vendedor = User::factory()->create();
    $vendedor->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    $this->actingAs($vendedor)->postJson('/app/compras/proveedor', ['nombre' => 'X', 'tipo_documento' => 'NIT', 'numero_documento' => '333'])
        ->assertForbidden();
    expect(Contacto::where('numero_documento', '333')->exists())->toBeFalse();
});
