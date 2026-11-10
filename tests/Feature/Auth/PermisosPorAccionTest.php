<?php

/**
 * Ver no es lo mismo que poder tocar.
 *
 * Hasta acá una sección era UN permiso: quien podía verla podía hacerle todo.
 * Eso obligaba a elegir entre dar acceso completo o no dar nada, y en la
 * práctica se daba completo — el auxiliar que sólo necesitaba consultar
 * terminaba pudiendo borrar.
 *
 * Ahora cada sección tiene cuatro: ver, crear, editar y eliminar.
 *
 * Lo delicado es el despliegue. Partir un permiso en cuatro no puede quitarle
 * nada a nadie: si el Contador podía editar la cartera ayer, hoy tiene que
 * poder. Por eso el seeder es ADITIVO —a cada rol que ya tenía el `ver` de
 * una sección le da sus tres acciones— y restringir queda como decisión en la
 * pantalla de Roles, no como efecto de actualizar el sistema.
 */

use App\Auth\Permisos;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/** Nombre propio: en Pest las funciones del archivo son globales. */
function unRolCon(string $nombre, array $permisos): Role
{
    $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
    foreach ($permisos as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    $rol->syncPermissions($permisos);

    return $rol;
}

function unaPersonaCon(array $permisos): User
{
    $u = User::factory()->create();
    $u->assignRole(unRolCon('RolDePrueba'.fake()->unique()->numerify('###'), $permisos));

    return $u->fresh();
}

it('el seeder crea las cuatro acciones de cada sección', function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);

    $secciones = count(Permisos::MATRIZ);

    expect(Permission::where('name', 'like', 'ver.%')->count())->toBe($secciones)
        ->and(Permission::where('name', 'like', 'crear.%')->count())->toBe($secciones)
        ->and(Permission::where('name', 'like', 'editar.%')->count())->toBe($secciones)
        ->and(Permission::where('name', 'like', 'eliminar.%')->count())->toBe($secciones);
});

it('actualizar no le quita nada a quien ya tenía la sección', function () {
    // Así quedaba un rol con el modelo viejo: un solo permiso por sección.
    $rol = unRolCon('ContadorViejo', ['ver.contabilidad', 'ver.facturas']);

    $this->seed(Database\Seeders\PermisosSeeder::class);

    $tiene = $rol->fresh()->permissions->pluck('name');

    // Las tres acciones aparecen solas: si no, el día del despliegue el
    // Contador dejaría de poder editar lo que editaba ayer.
    expect($tiene)->toContain('editar.contabilidad')
        ->and($tiene)->toContain('crear.facturas')
        ->and($tiene)->toContain('eliminar.facturas')
        // Y no se le regala lo que nunca tuvo.
        ->and($tiene)->not->toContain('ver.nomina')
        ->and($tiene)->not->toContain('editar.nomina');
});

it('ver sin más es sólo lectura', function () {
    $u = unaPersonaCon(['ver.cajas']);

    expect(Permisos::puede($u, 'cajas'))->toBeTrue()
        ->and(Permisos::puedeAccion($u, 'cajas', 'ver'))->toBeTrue()
        ->and(Permisos::puedeAccion($u, 'cajas', 'crear'))->toBeFalse()
        ->and(Permisos::puedeEditar($u, 'cajas'))->toBeFalse()
        ->and(Permisos::puedeAccion($u, 'cajas', 'eliminar'))->toBeFalse();
});

it('con la acción marcada sí puede', function () {
    $u = unaPersonaCon(['ver.cajas', 'editar.cajas']);

    expect(Permisos::puedeEditar($u, 'cajas'))->toBeTrue()
        // Pero sólo esa: editar no regala crear ni eliminar.
        ->and(Permisos::puedeAccion($u, 'cajas', 'crear'))->toBeFalse()
        ->and(Permisos::puedeAccion($u, 'cajas', 'eliminar'))->toBeFalse();
});

it('sin ver, las demás acciones no sirven', function () {
    // Una casilla mal puesta no debería abrir nada: la puerta es `ver`.
    $u = unaPersonaCon(['editar.cajas', 'eliminar.cajas']);

    expect(Permisos::puede($u, 'cajas'))->toBeFalse()
        ->and(Permisos::puedeEditar($u, 'cajas'))->toBeFalse()
        ->and(Permisos::puedeAccion($u, 'cajas', 'eliminar'))->toBeFalse();
});

it('una acción inventada no abre nada', function () {
    $u = unaPersonaCon(['ver.cajas', 'editar.cajas']);

    expect(Permisos::puedeAccion($u, 'cajas', 'exportar'))->toBeFalse();
});

it('root pasa siempre, tenga o no las casillas', function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);

    $aracely = User::factory()->create();
    $aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    expect(Permisos::puedeAccion($aracely->fresh(), 'cajas', 'eliminar'))->toBeTrue();
});

it('el catálogo trae sólo las casillas que la sección cumple de verdad', function () {
    /*
     * Antes traía las cuatro para las 68 secciones: 272 casillas, de las que
     * el código consultaba 74. Las otras 198 se guardaban, se veían marcadas y
     * nadie las miraba —las 68 de «eliminar», ni una sola vez—.
     *
     * Una casilla que no hace nada hace creer que el candado está puesto, así
     * que ahora se pinta sólo lo que se cumple. `ACCIONES_QUE_SE_CUMPLEN` es
     * el contrato.
     */
    $catalogo = Permisos::catalogo();

    // Cajas SÍ distingue: su controlador pregunta por crear y por editar.
    $cajas = collect($catalogo)->flatten(1)->firstWhere('seccion', 'cajas');

    expect(collect($cajas['acciones'])->pluck('accion')->all())->toBe(['crear', 'editar'])
        // `permiso` sigue siendo el de ver: lo leen decenas de sitios viejos.
        ->and($cajas['permiso'])->toBe('ver.cajas');

    // Y una que no distingue no ofrece casillas de más.
    $bodega = collect($catalogo)->flatten(1)->firstWhere('seccion', 'bodega');

    expect($bodega['acciones'])->toBe([])
        ->and($bodega['permiso'])->toBe('ver.bodega');
});

it('los módulos de tesorería son configurables desde la pantalla', function () {
    $secciones = collect(Permisos::catalogo())->flatten(1)->pluck('seccion');

    // Si no están en el catálogo, Aracely no puede repartirlos y quedan
    // abiertos a todo el que entre a contabilidad.
    foreach (['cajas', 'arqueo_caja', 'flujo_caja', 'cuentas_por_pagar', 'programacion_pagos'] as $s) {
        expect($secciones)->toContain($s);
    }
});

// ───────── Lo que se ve en las pantallas de verdad ─────────

it('quien sólo ve las cajas no las puede crear', function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);

    $mirona = unaPersonaCon(['ver.cajas']);

    $this->actingAs($mirona)->get('/app/contabilidad/cajas')->assertOk();

    $this->actingAs($mirona)->post('/app/contabilidad/cajas', [
        'codigo' => 'CG-99', 'nombre' => 'Caja intrusa', 'tipo' => 'general',
        'cuenta_puc' => '11050501', 'saldo_inicial' => 0,
    ])->assertForbidden();

    expect(App\Modules\Contabilidad\Models\Caja::count())->toBe(0);
});

it('la pantalla no le ofrece botones que el servidor va a rechazar', function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);

    $mirona = unaPersonaCon(['ver.cajas']);

    $props = $this->actingAs($mirona)->get('/app/contabilidad/cajas')
        ->assertOk()->viewData('page')['props'];

    // Un botón que da 403 es peor que un botón que no está.
    expect($props['puedo']['crear'])->toBeFalse()
        ->and($props['puedo']['editar'])->toBeFalse();
});
