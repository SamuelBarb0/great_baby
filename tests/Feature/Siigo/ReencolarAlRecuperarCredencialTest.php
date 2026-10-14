<?php

/**
 * Apenas la llave de SIIGO vuelve a servir, lo pendiente se reencola solo.
 *
 * Pasó de verdad entre el 9 y el 10 de octubre de 2026: la llave del sandbox
 * se cayó por la tarde, los 29 trabajos encolados aguantaron sus 12 horas de
 * `retryUntil` esperándola, se vencieron de madrugada, y a la mañana
 * siguiente el ERP seguía sin mandar nada. No se perdió trabajo —el barrido
 * diario encuentra lo que no tiene `siigo_id`—, pero se perdía un día
 * completo esperando la pasada de las 04:15.
 *
 * Ahora el barrido se dispara en el momento en que alguien prueba la
 * conexión con éxito, que es exactamente cuando se acaba de pegar la llave
 * nueva.
 */

use App\Models\User;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Siigo\Jobs\PushProductoASiigo;
use App\Modules\Siigo\Models\SiigoConfig;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Queue::fake();
    config()->set('siigo.driver', 'fake');

    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    SiigoConfig::current()->forceFill([
        'activo' => true, 'push_auto' => true,
        'username' => 'sandbox@siigoapi.com', 'access_key' => 'llave-de-prueba',
        'ultimo_auth_ok' => false,
        'ultimo_auth_error' => 'HTTP 401 · Incorrect username or password',
    ])->save();
    SiigoConfig::invalidarPushAutoCache();
});

function productoPendiente(): Producto
{
    return Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'REF-'.fake()->unique()->numerify('#####'),
        'nombre' => 'Producto pendiente de subir',
        'desglose_stock' => false,
        'activo' => true,
    ]));
}

function probarConexion($test)
{
    Queue::fake();   // limpia lo que encoló el observer al crear el producto

    return $test->actingAs($test->aracely)->post('/app/siigo/probar-conexion');
}

it('reencola lo pendiente cuando la conexión vuelve a responder', function () {
    $p = productoPendiente();

    probarConexion($this)->assertRedirect();

    Queue::assertPushed(PushProductoASiigo::class, fn ($job) => $job->productoId === $p->id);
});

it('le dice a la persona cuántos documentos salieron', function () {
    productoPendiente();

    $r = probarConexion($this);

    expect(session('success'))
        ->toContain('la integración está viva')
        ->and(session('success'))->toMatch('/Se reencolaron \d+ documento/');
});

it('sin nada pendiente lo dice y no inventa trabajo', function () {
    // Sin productos ni documentos sueltos, el barrido no encuentra nada.
    probarConexion($this)->assertRedirect();

    expect(session('success'))->toContain('No había nada pendiente');
    Queue::assertNotPushed(PushProductoASiigo::class);
});

it('deja la credencial marcada como viva', function () {
    // Es lo que `EsperarCredencialSiigo` mira para dejar pasar los push: si
    // no se actualizara, los jobs recién encolados se aplazarían de nuevo.
    productoPendiente();

    probarConexion($this);

    expect(SiigoConfig::current()->fresh()->credencialMuerta())->toBeFalse();
});

it('sólo Aracely puede probar la conexión', function () {
    $vendedor = User::factory()->create();
    $vendedor->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    $this->actingAs($vendedor)->post('/app/siigo/probar-conexion')->assertForbidden();
});
