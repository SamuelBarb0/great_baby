<?php

/**
 * Crear las cajas donde está la plata.
 *
 * Las tablas `cajas` y `arqueos` existían desde el 2026-09-05 marcadas «TO-BE
 * Contabilidad P1» y nunca se construyó nada encima: no había modelo, ni
 * ruta, ni pantalla. O sea que el módulo de caja no se podía ni empezar,
 * porque no había forma de crear la primera caja.
 *
 * El diseño sale de cómo lo explicó Aracely:
 *
 *   «hay cajas que las dejamos así, o varios bancos que siempre tienen ahí
 *    hasta que se retira el dinero, pero hay una caja que se mueve, que es la
 *    caja menor, esa es la caja del día… siempre que se cierra se va a una
 *    caja cualquiera, normalmente la general, pero puede ser que se vaya a
 *    otra… y cómo podemos crear más cajas, ejemplo si tenemos una caja en
 *    Bogotá»
 *
 * De ahí las dos reglas que se protegen acá: una caja menor no existe sin
 * decir contra qué cierra, y la cuenta contra la que contabiliza tiene que
 * admitir movimiento.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Contabilidad\Models\Caja;
use App\Modules\Contabilidad\Models\PlanCuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->seed(Database\Seeders\PermisosSeeder::class);

    $contador = Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']);
    $contador->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array('Contador', $roles, true))
        ->keys()->flatMap(fn ($s) => Permisos::permisosDe($s))->all());

    $this->silvia = User::factory()->create(['name' => 'Silvia Contadora']);
    $this->silvia->assignRole($contador);

    $this->vendedor = User::factory()->create(['name' => 'Vendedor Demo']);
    $this->vendedor->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    // La agrupadora y la auxiliar que cuelga de ella.
    PlanCuenta::create(['codigo' => '1105', 'nombre' => 'Caja', 'clase' => '1',
        'nivel' => 4, 'naturaleza' => 'debito', 'permite_movimiento' => false, 'activa' => true]);
    PlanCuenta::create(['codigo' => '11050501', 'nombre' => 'Caja general', 'clase' => '1',
        'nivel' => 5, 'naturaleza' => 'debito', 'permite_movimiento' => true, 'activa' => true]);
});

/** Nombre propio: en Pest las funciones del archivo son globales. */
function guardarCaja(object $caso, array $extra = [])
{
    return $caso->actingAs($caso->silvia)->post('/app/contabilidad/cajas', array_merge([
        'codigo' => 'CG-01',
        'nombre' => 'Caja general',
        'tipo' => 'general',
        'cuenta_puc' => '11050501',
        'saldo_inicial' => 0,
        'activa' => true,
    ], $extra));
}

it('se crea una caja general', function () {
    guardarCaja($this)->assertRedirect();

    $c = Caja::firstOrFail();

    expect($c->codigo)->toBe('CG-01')
        ->and($c->tipo)->toBe('general')
        ->and($c->cuenta_puc)->toBe('11050501')
        ->and($c->activa)->toBeTrue()
        // Una general no se arquea: el conteo diario es de la caja menor.
        ->and($c->seArquea())->toBeFalse();
});

it('la caja menor exige contra qué cierra', function () {
    guardarCaja($this, ['codigo' => 'CM-01', 'nombre' => 'Caja menor', 'tipo' => 'menor'])
        ->assertSessionHasErrors('caja_destino_cierre_id');

    // Sin destino, el arqueo del día cuadra y después el saldo no tiene a
    // dónde ir: la caja queda imposible de cerrar.
    expect(Caja::count())->toBe(0);
});

it('con destino, la caja menor sí entra y se marca como arqueable', function () {
    guardarCaja($this);
    $general = Caja::firstOrFail();

    guardarCaja($this, [
        'codigo' => 'CM-01', 'nombre' => 'Caja menor Bogotá', 'tipo' => 'menor',
        'caja_destino_cierre_id' => $general->id,
    ])->assertRedirect();

    $menor = Caja::where('codigo', 'CM-01')->firstOrFail();

    expect($menor->destinoCierre->codigo)->toBe('CG-01')
        ->and($menor->seArquea())->toBeTrue();
});

it('no se puede contabilizar contra una cuenta agrupadora', function () {
    guardarCaja($this, ['cuenta_puc' => '1105'])
        ->assertSessionHasErrors('cuenta_puc');

    expect(Caja::count())->toBe(0);
});

it('dos cajas no pueden compartir el código', function () {
    guardarCaja($this)->assertRedirect();
    guardarCaja($this, ['nombre' => 'Otra con el mismo código'])
        ->assertSessionHasErrors('codigo');

    expect(Caja::count())->toBe(1);
});

it('una caja no puede cerrarse contra sí misma', function () {
    guardarCaja($this);
    $c = Caja::firstOrFail();

    // Suena absurdo hasta que alguien edita con la lista abierta y se elige.
    guardarCaja($this, ['id' => $c->id, 'tipo' => 'menor', 'caja_destino_cierre_id' => $c->id])
        ->assertSessionHasErrors('caja_destino_cierre_id');

    expect($c->fresh()->tipo)->toBe('general');
});

it('la caja se apaga en vez de borrarse', function () {
    guardarCaja($this);
    $c = Caja::firstOrFail();

    $this->actingAs($this->silvia)
        ->post("/app/contabilidad/cajas/{$c->id}/activa")->assertRedirect();

    // Sigue existiendo: una caja con arqueos detrás es historia contable.
    expect($c->fresh()->activa)->toBeFalse()
        ->and(Caja::count())->toBe(1);
});

it('no se apaga una caja contra la que otra cierra', function () {
    guardarCaja($this);
    $general = Caja::firstOrFail();
    guardarCaja($this, [
        'codigo' => 'CM-01', 'nombre' => 'Caja menor', 'tipo' => 'menor',
        'caja_destino_cierre_id' => $general->id,
    ]);

    $this->actingAs($this->silvia)
        ->post("/app/contabilidad/cajas/{$general->id}/activa")->assertRedirect();

    // Apagarla dejaría a la menor sin dónde descargar su cierre.
    expect($general->fresh()->activa)->toBeTrue();
});

it('la pantalla sólo ofrece cuentas que admiten movimiento', function () {
    $props = $this->actingAs($this->silvia)->get('/app/contabilidad/cajas')
        ->assertOk()->viewData('page')['props'];

    $codigos = collect($props['cuentas'])->pluck('codigo');

    expect($codigos)->toContain('11050501')
        ->and($codigos)->not->toContain('1105');
});

it('un vendedor no entra a las cajas', function () {
    $this->actingAs($this->vendedor)->get('/app/contabilidad/cajas')->assertForbidden();
});
