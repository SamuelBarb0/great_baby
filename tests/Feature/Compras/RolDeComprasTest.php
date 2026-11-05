<?php

/**
 * Alejandro y Liz no tenían rol: crear y aprobar órdenes sólo le tocaba al Gerente,
 * que además abre contabilidad y finanzas. Y marcar las casillas de Compras en la
 * pantalla de Roles no servía: la lista y la orden nueva sólo preguntaban si la
 * persona era de bodega o de contabilidad.
 */

use App\Auth\MenuApp;
use App\Auth\Permisos;
use App\Models\Contacto;
use App\Models\User;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Como en producción: los permisos ya están sembrados cuando corre la migración del rol.
    $this->seed(Database\Seeders\PermisosSeeder::class);
    (require database_path('migrations/2026_11_05_110000_rol_de_compras.php'))->up();

    $this->alejandro = User::factory()->create(['name' => 'Alejandro']);
    $this->alejandro->assignRole('Compras');

    $this->root = User::factory()->create();
    $this->root->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
});

it('abre lo que Compras necesita', function (string $pantalla) {
    $this->actingAs($this->alejandro)->get($pantalla)->assertOk();
})->with([
    '/app/compras',
    '/app/compras/oc/nueva',
    '/app/compras/calculadora',
    '/app/compras/sugerido',
    '/app/compras/importacion',
    '/app/compras/importacion/nueva',
    '/app/compras/pagos-exterior',
    '/app/compras/reporte',
]);

it('no abre contabilidad, finanzas ni el conteo de Jorge', function (string $pantalla) {
    $this->actingAs($this->alejandro)->get($pantalla)->assertForbidden();
})->with([
    '/app/contabilidad/cajas',
    '/app/compras/cuentas-por-pagar',
    '/app/compras/programacion-pagos',
    '/app/compras/recepcion/nueva',
    '/app/contactos',
    '/app/roles',
]);

it('al entrar llega a sus órdenes, no a una pantalla que le da 403', function () {
    expect($this->alejandro->rutaInicial())->toBe('/app/compras');

    $this->post('/app/login', ['email' => $this->alejandro->email, 'password' => 'password'])
        ->assertRedirect('/app/compras');
    $this->get('/app/compras')->assertOk();
});

it('el menú le ofrece la lista y la orden nueva', function () {
    expect(MenuApp::clavesDe($this->alejandro))
        ->toContain('compras_lista')
        ->toContain('compras_gestion')
        ->toContain('compras_importacion')
        ->not->toContain('contabilidad');
});

it('crea la orden, no aprueba la suya y sí la de su compañera', function () {
    $proveedor = Contacto::create(['tipo_documento' => 'NIT', 'numero_documento' => '900777888', 'nombre_completo' => 'Topwin', 'es_proveedor' => true]);
    $bodega = InventarioUbicacion::create(['codigo' => 'PPAL', 'nombre' => 'Principal', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true]);

    $this->actingAs($this->alejandro)->post('/app/compras/oc', [
        'proveedor_id' => $proveedor->id, 'bodega_id' => $bodega->id, 'tipo' => 'importacion',
        'moneda' => 'CNY', 'tasa_cambio' => 520,
        'items' => [['descripcion' => 'TW-123 · oso', 'cantidad' => 100, 'precio_unit' => 10, 'iva_pct' => 0]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $orden = OrdenCompra::latest('id')->firstOrFail();
    // Quien la crea no la aprueba, y la pantalla no le ofrece un botón que termina en 403.
    $this->actingAs($this->alejandro)->get("/app/compras/oc/{$orden->id}")
        ->assertInertia(fn ($p) => $p->where('puede_aprobar', false)->where('puede_pdf', true)
            ->where('paso.acciones', fn ($acciones) => collect($acciones)->where('accion', 'aprobar')->isEmpty()));
    $this->actingAs($this->alejandro)->post("/app/compras/oc/{$orden->id}/aprobar")->assertForbidden();

    $liz = User::factory()->create(['name' => 'Liz']);
    $liz->assignRole('Compras');
    $this->actingAs($liz)->get("/app/compras/oc/{$orden->id}")
        ->assertInertia(fn ($p) => $p->where('puede_aprobar', true));
    $this->actingAs($liz)->post("/app/compras/oc/{$orden->id}/aprobar")->assertRedirect();

    expect($orden->fresh()->estado->value)->toBe('aprobada');
});

it('la pantalla de Roles muestra el rol con sus casillas marcadas', function () {
    $rol = Role::findByName('Compras', 'web');

    $this->actingAs($this->root)->get('/app/roles')->assertOk();
    $this->actingAs($this->root)->get("/app/roles/{$rol->id}")
        ->assertInertia(fn ($p) => $p
            ->where('rol.protegido', false)
            ->where('rol.permisos', fn ($permisos) => collect($permisos)->contains(Permisos::permiso('compras_oc'))
                && collect($permisos)->contains(Permisos::permiso('compras_oc', 'editar'))
                && ! collect($permisos)->contains(Permisos::permiso('contabilidad'))));
});

it('desmarcar la casilla en la pantalla de Roles de verdad cierra la pantalla', function () {
    $rol = Role::findByName('Compras', 'web');
    $sinOrdenes = $rol->permissions->pluck('name')->reject(fn ($p) => str_ends_with($p, '.compras_oc'))->values()->all();

    $this->actingAs($this->root)->post("/app/roles/{$rol->id}", ['nombre' => 'Compras', 'permisos' => $sinOrdenes])
        ->assertSessionHasNoErrors()->assertRedirect('/app/roles');

    $alejandro = User::findOrFail($this->alejandro->id);
    $this->actingAs($alejandro)->get('/app/compras/oc/nueva')->assertForbidden();
    expect(MenuApp::clavesDe($alejandro))->not->toContain('compras_gestion');
});

it('un rol nuevo creado en esa pantalla guarda sus casillas de crear y editar', function () {
    $permisos = [Permisos::permiso('compras_oc'), Permisos::permiso('compras_oc', 'crear'), Permisos::permiso('compras_oc', 'editar')];

    $this->actingAs($this->root)->post('/app/roles', ['nombre' => 'Compras junior', 'permisos' => $permisos])
        ->assertSessionHasNoErrors()->assertRedirect('/app/roles');

    expect(Role::findByName('Compras junior', 'web')->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect($permisos)->sort()->values()->all());

    $u = User::factory()->create();
    $u->assignRole('Compras junior');
    $this->actingAs($u)->get('/app/compras/oc/nueva')->assertOk();
});
