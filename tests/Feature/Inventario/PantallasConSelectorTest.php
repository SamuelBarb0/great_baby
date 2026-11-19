<?php

/**
 * Una pantalla que ofrece un desplegable tiene que mandarle sus opciones.
 *
 * Encontrado el 2026-10-13 haciendo el recorrido completo a mano: el botón
 * «Nuevo conteo» abría el formulario con el desplegable de ubicaciones
 * **vacío**, así que no se podía crear ni una sola toma física. La pantalla
 * declaraba la prop `ubicaciones` y el controlador nunca se la enviaba.
 *
 * No salía en ninguna prueba porque nadie miraba esa pantalla, y a simple
 * vista no se nota: el botón existe, el modal abre, todo parece bien hasta
 * que uno va a elegir y no hay nada.
 */

use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'BOG-01', 'nombre' => 'Bodega Bogotá',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
});

function props($test, string $url): array
{
    return $test->actingAs($test->admin)->get($url)->assertOk()->viewData('page')['props'];
}

it('la pantalla de conteos manda las ubicaciones del desplegable', function () {
    $p = props($this, '/app/inventario/conteos');

    expect($p)->toHaveKey('ubicaciones')
        ->and($p['ubicaciones'])->not->toBeEmpty()
        ->and(collect($p['ubicaciones'])->pluck('codigo'))->toContain('BOG-01');
});

it('cada opción del conteo trae lo que el desplegable pinta', function () {
    // La vista muestra «codigo · nombre»; sin esos campos saldrían en blanco.
    foreach (props($this, '/app/inventario/conteos')['ubicaciones'] as $u) {
        expect($u)->toHaveKeys(['id', 'codigo', 'nombre']);
    }
});

it('el conteo no ofrece las bodegas importadas de SIIGO', function () {
    // Son destino contable, no se cuentan. Ofrecerlas sólo ensucia la lista:
    // hay 43 contra 21 propias.
    InventarioUbicacion::create([
        'codigo' => 'SIIGO-16', 'nombre' => 'Bodega ajena del catálogo',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $codigos = collect(props($this, '/app/inventario/conteos')['ubicaciones'])->pluck('codigo');

    expect($codigos)->toContain('BOG-01')
        ->and($codigos)->not->toContain('SIIGO-16');
});

it('una ubicación inactiva no se puede contar', function () {
    InventarioUbicacion::create([
        'codigo' => 'VIEJA-01', 'nombre' => 'Bodega cerrada',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => false,
    ]);

    expect(collect(props($this, '/app/inventario/conteos')['ubicaciones'])->pluck('codigo'))
        ->not->toContain('VIEJA-01');
});

it('se puede crear la toma física con una de las ubicaciones ofrecidas', function () {
    // La prueba de verdad: que lo que ofrece el desplegable lo acepte el
    // servidor. Un selector que ofrece algo que después da error es peor que
    // uno vacío.
    $ofrecida = collect(props($this, '/app/inventario/conteos')['ubicaciones'])->first();

    // El `get` de arriba abre sesión con su token CSRF; el POST de la prueba
    // no lo lleva y el middleware responde 419. Lo que acá importa es que el
    // servidor acepte la ubicación, no el token.
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

    $this->actingAs($this->admin)
        ->post('/app/inventario/conteos', [
            'ubicacion_id' => $ofrecida['id'],
            'tipo' => 'total',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');
});

it('la pantalla de ubicaciones manda las bodegas para colgar una posición', function () {
    // Acá vive el desplegable «¿Dónde está?». El front lo refresca al guardar
    // —antes no, y la bodega recién creada no aparecía hasta recargar—, pero
    // eso sólo sirve si el controlador manda la lista de entrada.
    $p = props($this, '/app/inventario/ubicaciones');

    expect($p)->toHaveKey('bodegas')
        ->and(collect($p['bodegas'])->pluck('codigo'))->toContain('BOG-01');
});
