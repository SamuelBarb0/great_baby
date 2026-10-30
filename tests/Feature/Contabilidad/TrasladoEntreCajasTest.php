<?php

/**
 * Pasar plata de una caja a otra.
 *
 * Es lo que hacen al cerrar la semana: la caja menor descarga en la general.
 * Son DOS movimientos, no uno — una salida acá y una entrada allá — y tienen
 * que quedar amarrados: si sólo se registrara una punta, el arqueo de la caja
 * que recibe cerraría con un sobrante que nadie sabe explicar, y el de la que
 * entrega con un faltante.
 *
 * El tope es lo que hay. Trasladar más deja la caja en negativo, que es un
 * estado que no existe: significa que alguien tecleó mal, y el error se va a
 * la otra caja antes de que nadie lo note.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Contabilidad\Models\Caja;
use App\Modules\Contabilidad\Models\MovimientoCaja;
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
        ->keys()->map(fn ($s) => Permisos::permiso($s))->all());

    $this->silvia = User::factory()->create(['name' => 'Silvia Contadora']);
    $this->silvia->assignRole($contador);

    PlanCuenta::create(['codigo' => '11050501', 'nombre' => 'Caja general', 'clase' => '1',
        'nivel' => 5, 'naturaleza' => 'debito', 'permite_movimiento' => true, 'activa' => true]);

    $this->general = Caja::create([
        'codigo' => 'CG-01', 'nombre' => 'Caja general', 'tipo' => 'general',
        'cuenta_puc' => '11050501', 'saldo_inicial' => 0, 'activa' => true,
    ]);

    $this->menor = Caja::create([
        'codigo' => 'CM-01', 'nombre' => 'Caja menor', 'tipo' => 'menor',
        'cuenta_puc' => '11050501', 'saldo_inicial' => 500_000, 'activa' => true,
        'caja_destino_cierre_id' => $this->general->id,
    ]);

    $this->dia = '2026-10-05';
});

/** Nombre propio: en Pest las funciones del archivo son globales. */
function pasarPlata(object $caso, array $datos = [])
{
    return $caso->actingAs($caso->silvia)
        ->post("/app/contabilidad/cajas/{$caso->menor->id}/trasladar", array_merge([
            'fecha' => $caso->dia,
            'destino_id' => $caso->general->id,
            'valor' => 300_000,
            'motivo' => 'Cierre de semana',
        ], $datos));
}

it('el traslado deja movimiento en las dos cajas', function () {
    pasarPlata($this)->assertRedirect();

    $salida = MovimientoCaja::where('caja_id', $this->menor->id)->firstOrFail();
    $entrada = MovimientoCaja::where('caja_id', $this->general->id)->firstOrFail();

    expect($salida->tipo)->toBe('gasto')
        ->and((float) $salida->valor)->toBe(300_000.0)
        ->and($salida->descripcion)->toContain('CG-01')
        ->and($entrada->tipo)->toBe('ingreso')
        ->and((float) $entrada->valor)->toBe(300_000.0)
        ->and($entrada->descripcion)->toContain('CM-01');
});

it('las dos puntas se conocen entre sí', function () {
    pasarPlata($this);

    $salida = MovimientoCaja::where('caja_id', $this->menor->id)->firstOrFail();

    // Sin esto, a los dos meses son un gasto y un ingreso sueltos y nadie
    // sabe que eran el mismo hecho.
    expect($salida->esTraslado())->toBeTrue()
        ->and($salida->contraparte->caja_id)->toBe($this->general->id)
        ->and($salida->contraparte->contraparte_id)->toBe($salida->id);
});

it('no se puede pasar más de lo que hay', function () {
    pasarPlata($this, ['valor' => 800_000])->assertSessionHasErrors('valor');

    // Ni una punta ni la otra: o entran las dos o no entra ninguna.
    expect(MovimientoCaja::count())->toBe(0);
});

it('se puede pasar exactamente todo el saldo', function () {
    pasarPlata($this, ['valor' => 500_000])->assertRedirect();

    expect(MovimientoCaja::count())->toBe(2);
});

it('no se traslada a la misma caja', function () {
    pasarPlata($this, ['destino_id' => $this->menor->id])
        ->assertSessionHasErrors('destino_id');

    expect(MovimientoCaja::count())->toBe(0);
});

it('no se traslada a una caja inactiva', function () {
    $this->general->update(['activa' => false]);

    pasarPlata($this)->assertSessionHasErrors('destino_id');

    expect(MovimientoCaja::count())->toBe(0);
});

it('el traslado baja el saldo de la que entrega y sube el de la que recibe', function () {
    pasarPlata($this, ['valor' => 200_000]);

    $verDia = fn (Caja $c) => $this->actingAs($this->silvia)
        ->get("/app/contabilidad/cajas/{$c->id}/dia?fecha={$this->dia}")
        ->assertOk()->viewData('page')['props']['totales'];

    expect($verDia($this->menor)['esperado'])->toBe(300_000.0)
        ->and($verDia($this->general)['esperado'])->toBe(200_000.0);
});
