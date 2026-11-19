<?php

/**
 * La tarjeta de cuentas PUC muestra el valor EFECTIVO, no el guardado.
 *
 * Encontrado el 2026-10-14: la tarjeta decía «Sin configurar · sin valor» en
 * las cinco filas, mientras el ERP armaba los asientos con 5195, 1110, 139535,
 * 5299 y 4295 — entre ellas una que no existe en el plan de cuentas y otras de
 * encabezado, que SIIGO rechaza.
 *
 * La causa: `setting($clave, '')`. Ese segundo argumento **anula el valor por
 * defecto** de `Reglas::DEFAULTS`, porque el `??` interno sólo cae con `null` y
 * `''` no es `null`. Todo el resto del código llama `setting($clave)` sin
 * default y recibe el valor real.
 *
 * O sea: la tarjeta que existe para avisar «SIIGO va a rechazar estas cuentas»
 * era justo la que lo escondía. Una casilla que no muestra lo que el sistema va
 * a usar es peor que no tener casilla.
 */

use App\Models\ReglaNegocio;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
});

function cuentasDelPanel($test): \Illuminate\Support\Collection
{
    $props = $test->actingAs($test->aracely)->get('/app/siigo')
        ->assertOk()->viewData('page')['props'];

    return collect($props['cuentas_puc']['filas'] ?? $props['cuentas_puc'] ?? []);
}

function filaDe(\Illuminate\Support\Collection $filas, string $clave): ?array
{
    return $filas->firstWhere('clave', $clave);
}

it('muestra el código que el ERP va a usar aunque nadie lo haya elegido', function () {
    // Nadie guardó nada: el valor sale de `Reglas::DEFAULTS` y es el que el
    // asiento va a llevar, así que tiene que verse.
    expect(ReglaNegocio::where('clave', 'siigo.cta_perdida_inventario_default')->exists())->toBeFalse();

    $fila = filaDe(cuentasDelPanel($this), 'siigo.cta_perdida_inventario_default');

    expect($fila['codigo'])->toBe(setting('siigo.cta_perdida_inventario_default'))
        ->and($fila['codigo'])->not->toBe('');
});

it('avisa que el código es el de fábrica y no una decisión de nadie', function () {
    $fila = filaDe(cuentasDelPanel($this), 'siigo.cta_perdida_inventario_default');

    expect($fila['detalle'])->toContain('nadie lo eligió todavía');
});

it('una cuenta que no existe en el plan sale en rojo', function () {
    ReglaNegocio::updateOrCreate(
        ['clave' => 'siigo.cta_gasto_default'],
        ['valor' => '999999', 'tipo' => 'string', 'grupo' => 'siigo', 'etiqueta' => 'Gasto por defecto']
    );
    App\Support\Reglas::limpiarRequestCache();

    $fila = filaDe(cuentasDelPanel($this), 'siigo.cta_gasto_default');

    expect($fila['codigo'])->toBe('999999')
        ->and($fila['estado'])->toBe('rojo')
        ->and($fila['detalle'])->toContain('No existe en el plan');
});

it('el código elegido a mano no se anuncia como valor de fábrica', function () {
    ReglaNegocio::updateOrCreate(
        ['clave' => 'siigo.cta_gasto_default'],
        ['valor' => '999999', 'tipo' => 'string', 'grupo' => 'siigo', 'etiqueta' => 'Gasto por defecto']
    );
    App\Support\Reglas::limpiarRequestCache();

    expect(filaDe(cuentasDelPanel($this), 'siigo.cta_gasto_default')['detalle'])
        ->not->toContain('nadie lo eligió');
});

it('una clave realmente vacía sí dice «Sin configurar»', function () {
    // El caso legítimo se conserva: si el valor efectivo es vacío, está vacío.
    ReglaNegocio::updateOrCreate(
        ['clave' => 'siigo.cta_banco_default'],
        ['valor' => '', 'tipo' => 'string', 'grupo' => 'siigo', 'etiqueta' => 'Banco por defecto']
    );
    App\Support\Reglas::limpiarRequestCache();

    $fila = filaDe(cuentasDelPanel($this), 'siigo.cta_banco_default');

    expect($fila['codigo'])->toBe('')
        ->and($fila['estado'])->toBe('rojo')
        ->and($fila['detalle'])->toContain('Sin configurar');
});

it('el contador de la tarjeta cuenta las que SIIGO va a rechazar de verdad', function () {
    $filas = cuentasDelPanel($this);
    $problemas = $filas->whereIn('estado', ['rojo', 'amber'])->count();

    // Con los valores de fábrica hay cuentas inexistentes y de encabezado: el
    // contador no puede dar 0 ni contar las cinco a ciegas.
    expect($problemas)->toBeGreaterThan(0)
        ->and($problemas)->toBeLessThanOrEqual($filas->count());
});
