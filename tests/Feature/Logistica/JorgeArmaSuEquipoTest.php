<?php

/**
 * El jefe de bodega arma su propio equipo.
 *
 * Antes, crear al alistador que entró el lunes y decir de qué estante responde
 * era de Gerencia: dos tareas chicas que pasaban por el cuello de botella más
 * ocupado de la empresa. El resultado medible fue que los estantes se quedaban
 * sin responsable durante semanas, y como la cola de alistamiento filtra por
 * responsable, esos alistadores abrían su pantalla todos los días y la veían
 * vacía.
 *
 * Lo que se abrió, y lo que NO:
 *
 *   · Jorge puede crear gente de bodega —alistador, despachador, otro jefe— y
 *     decir quién responde por cada ubicación SUYA.
 *   · No puede crear una Gerencia, ni repartir las ubicaciones de otra sede,
 *     ni nombrar responsable a alguien que no tiene papel en bodega.
 *   · Y nombrar responsable a un alistador YA NO lo promueve a jefe de bodega.
 *     Eso lo hacía el código viejo «para que pudiera ver su trabajo», y de
 *     paso le entregaba poder para mover inventario entre las ubicaciones de
 *     su sede, incluidas avería y bajas. Desde que ver y operar son dos
 *     alcances distintos, la promoción dejó de hacer falta y el poder se
 *     quedó donde estaba.
 */

use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;

/**
 * El rol con los permisos que le da la MATRIZ.
 *
 * Con `RefreshDatabase` la tabla de roles nace vacía y el sembrador sólo
 * reparte entre los que ya existen. Tiene nombre propio porque las funciones
 * que se declaran en un archivo de Pest no se ven desde otro.
 */
function rolDeEquipo(string $nombre): \Spatie\Permission\Models\Role
{
    $rol = \Spatie\Permission\Models\Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

    $rol->syncPermissions(collect(\App\Auth\Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($nombre, $roles, true))
        ->keys()
        ->flatMap(fn ($seccion) => ["ver.{$seccion}", "editar.{$seccion}", "crear.{$seccion}"])
        ->filter(fn ($p) => \Spatie\Permission\Models\Permission::where('name', $p)->exists())
        ->all());

    return $rol;
}

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);

    // Los tres papeles de bodega existen, como en la base de verdad.
    foreach (['AdminBodega', 'Alistador', 'Despachador', 'Vendedor'] as $papel) {
        rolDeEquipo($papel);
    }

    $this->jorge = User::factory()->create(['name' => 'Jorge AdminBodega']);
    $this->jorge->assignRole(rolDeEquipo('AdminBodega'));

    $this->suya = InventarioUbicacion::create([
        'codigo' => 'BOG-9', 'nombre' => 'Bodega Bogotá',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'responsable_user_id' => $this->jorge->id,
    ]);

    $this->ajena = InventarioUbicacion::create([
        'codigo' => 'CAL-9', 'nombre' => 'Bodega Cali',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
});

it('Jorge crea un alistador sin pasar por Gerencia', function () {
    $r = $this->actingAs($this->jorge)->postJson('/app/inventario/ubicaciones/admin-bodega', [
        'name' => 'Yeison Alistador',
        'email' => 'yeison@greatbaby.test',
        'rol' => 'Alistador',
    ]);

    $r->assertOk()->assertJsonPath('ok', true);

    $nuevo = User::where('email', 'yeison@greatbaby.test')->first();

    expect($nuevo)->not->toBeNull()
        ->and($nuevo->getRoleNames()->all())->toBe(['Alistador'])
        // Y le queda una clave usable, que la pantalla muestra una sola vez.
        ->and($r->json('password_generado'))->not->toBeEmpty();
});

it('pero no puede fabricarse una Gerencia', function () {
    // Si el rol quedara abierto, esta casilla sería una fábrica de permisos.
    $this->actingAs($this->jorge)->postJson('/app/inventario/ubicaciones/admin-bodega', [
        'name' => 'Yo Mismo', 'email' => 'yo@greatbaby.test', 'rol' => 'Gerencia',
    ])->assertStatus(422);

    expect(User::where('email', 'yo@greatbaby.test')->exists())->toBeFalse();
});

it('Jorge dice quién responde por SU bodega', function () {
    $alistador = User::factory()->create();
    $alistador->assignRole(rolDeEquipo('Alistador'));

    $this->actingAs($this->jorge)->postJson('/app/inventario/ubicaciones/responsable', [
        'ubicacion_id' => $this->suya->id,
        'responsable_user_id' => $alistador->id,
    ])->assertOk();

    expect($this->suya->fresh()->responsable_user_id)->toBe($alistador->id);
});

it('nombrarlo responsable NO lo convierte en jefe de bodega', function () {
    // Es la pieza que evita que asignar un estante sea la puerta por la que se
    // entrega el poder de mover inventario.
    $alistador = User::factory()->create();
    $alistador->assignRole(rolDeEquipo('Alistador'));

    $this->actingAs($this->jorge)->postJson('/app/inventario/ubicaciones/responsable', [
        'ubicacion_id' => $this->suya->id,
        'responsable_user_id' => $alistador->id,
    ])->assertOk();

    $alistador->refresh();

    expect($alistador->getRoleNames()->all())->toBe(['Alistador'])
        // Ve su bodega…
        ->and($alistador->bodegasQueVeIds())->toContain($this->suya->id)
        // …y sigue sin poder moverla.
        ->and($alistador->bodegasAsignadasIds())->toBe([]);
});

it('no puede repartir las ubicaciones de otra sede', function () {
    $alistador = User::factory()->create();
    $alistador->assignRole(rolDeEquipo('Alistador'));

    $this->actingAs($this->jorge)->postJson('/app/inventario/ubicaciones/responsable', [
        'ubicacion_id' => $this->ajena->id,
        'responsable_user_id' => $alistador->id,
    ])->assertForbidden();

    expect($this->ajena->fresh()->responsable_user_id)->toBeNull();
});

it('no puede nombrar responsable a quien no trabaja en bodega', function () {
    // Si no, esta pantalla sería la puerta por la que un vendedor termina
    // viendo el inventario de una sede.
    $vendedor = User::factory()->create();
    $vendedor->assignRole(rolDeEquipo('Vendedor'));

    $this->actingAs($this->jorge)->postJson('/app/inventario/ubicaciones/responsable', [
        'ubicacion_id' => $this->suya->id,
        'responsable_user_id' => $vendedor->id,
    ])->assertStatus(422);

    expect($this->suya->fresh()->responsable_user_id)->toBe($this->jorge->id);
});

it('quitar el responsable se puede, y deja la ubicación sin dueño', function () {
    $this->actingAs($this->jorge)->postJson('/app/inventario/ubicaciones/responsable', [
        'ubicacion_id' => $this->suya->id,
        'responsable_user_id' => null,
    ])->assertOk();

    expect($this->suya->fresh()->responsable_user_id)->toBeNull();
});

it('un vendedor no puede crear gente de bodega ni repartir ubicaciones', function () {
    $vendedor = User::factory()->create();
    $vendedor->assignRole(rolDeEquipo('Vendedor'));

    $this->actingAs($vendedor)->postJson('/app/inventario/ubicaciones/admin-bodega', [
        'name' => 'Cualquiera', 'email' => 'x@greatbaby.test', 'rol' => 'Alistador',
    ])->assertForbidden();

    $this->actingAs($vendedor)->postJson('/app/inventario/ubicaciones/responsable', [
        'ubicacion_id' => $this->suya->id, 'responsable_user_id' => $vendedor->id,
    ])->assertForbidden();
});
