<?php

/**
 * Una solicitud de compra pagada en efectivo aterriza en el arqueo del día.
 *
 * El ciclo de gastos ya existía completo —cualquiera solicita, Gerencia
 * aprueba y se causa en el libro, Contabilidad marca pagado y se cancela el
 * pasivo— pero el pago en efectivo golpeaba la cuenta contable de caja por
 * defecto sin decir de cuál cajón salió la plata.
 *
 * El efecto se ve al cerrar el día: la persona cuenta los billetes, le faltan
 * los $395.000 que le entregó a alguien, y en la pantalla del arqueo no hay
 * nada que lo explique porque el gasto vivía en otro módulo. Con la caja
 * elegida, el movimiento nace en el día correcto y el conteo cuadra solo.
 *
 * Por transferencia no se pregunta nada: esa plata no sale de ninguna caja.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Contabilidad\Models\Caja;
use App\Modules\Contabilidad\Models\MovimientoCaja;
use App\Modules\Contabilidad\Models\PlanCuenta;
use App\Modules\Gerencia\Models\GastoOperativo;
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

    // Quien solicita no necesita ser nadie en particular.
    $this->operaria = User::factory()->create(['name' => 'Dayana Bodega']);

    PlanCuenta::create(['codigo' => '11050501', 'nombre' => 'Caja general', 'clase' => '1',
        'nivel' => 5, 'naturaleza' => 'debito', 'permite_movimiento' => true, 'activa' => true]);

    $this->caja = Caja::create([
        'codigo' => 'CM-01', 'nombre' => 'Caja menor', 'tipo' => 'menor',
        'cuenta_puc' => '11050501', 'saldo_inicial' => 2_000_000, 'activa' => true,
        'caja_destino_cierre_id' => null,
    ]);
});

/** Nombre propio: en Pest las funciones del archivo son globales. */
function unGastoAprobado(object $caso, string $metodo = 'efectivo'): GastoOperativo
{
    return GastoOperativo::create([
        'numero' => 'GA-'.fake()->unique()->numerify('####'),
        'fecha' => now()->toDateString(),
        'categoria' => 'Aseo',
        'descripcion' => 'Pago de insumos de aseo',
        'monto' => 454_200,
        'proveedor' => 'Full Company',
        'metodo_pago' => $metodo,
        'tipo' => 'gasto',
        'estado' => 'aprobado',
        'solicita_id' => $caso->operaria->id,
    ]);
}

it('un gasto en efectivo no se paga sin decir de qué caja', function () {
    $g = unGastoAprobado($this);

    $this->actingAs($this->silvia)
        ->post("/app/gastos/{$g->id}/pagado")
        ->assertSessionHasErrors('caja_id');

    // Ni se marcó pagado ni se movió plata de ningún lado.
    expect($g->fresh()->estado)->toBe('aprobado')
        ->and(MovimientoCaja::count())->toBe(0);
});

it('con la caja elegida, el gasto aparece en el día de esa caja', function () {
    $g = unGastoAprobado($this);

    $this->actingAs($this->silvia)
        ->post("/app/gastos/{$g->id}/pagado", ['caja_id' => $this->caja->id])
        ->assertRedirect();

    $mov = MovimientoCaja::firstOrFail();

    expect($g->fresh()->estado)->toBe('pagado')
        ->and($g->fresh()->caja_id)->toBe($this->caja->id)
        ->and($mov->tipo)->toBe('gasto')
        ->and((float) $mov->valor)->toBe(454_200.0)
        ->and($mov->descripcion)->toContain($g->numero)
        // A quién se le entregó: el proveedor, que es quien recibió la plata.
        ->and($mov->entregado_a)->toBe('Full Company');
});

it('y baja el saldo esperado del arqueo', function () {
    $g = unGastoAprobado($this);
    $this->actingAs($this->silvia)->post("/app/gastos/{$g->id}/pagado", ['caja_id' => $this->caja->id]);

    $hoy = now('America/Bogota')->toDateString();
    $totales = $this->actingAs($this->silvia)
        ->get("/app/contabilidad/cajas/{$this->caja->id}/dia?fecha={$hoy}")
        ->assertOk()->viewData('page')['props']['totales'];

    // 2.000.000 − 454.200. Antes el conteo daba de menos y nadie sabía por qué.
    expect($totales['gastos'])->toBe(454_200.0)
        ->and($totales['esperado'])->toBe(1_545_800.0);
});

it('sin proveedor queda registrado quien lo solicitó', function () {
    $g = unGastoAprobado($this);
    $g->update(['proveedor' => null]);

    $this->actingAs($this->silvia)->post("/app/gastos/{$g->id}/pagado", ['caja_id' => $this->caja->id]);

    expect(MovimientoCaja::firstOrFail()->entregado_a)->toBe('Dayana Bodega');
});

it('por transferencia no se toca ninguna caja', function () {
    $g = unGastoAprobado($this, 'transferencia');

    $this->actingAs($this->silvia)
        ->post("/app/gastos/{$g->id}/pagado")
        ->assertRedirect();

    expect($g->fresh()->estado)->toBe('pagado')
        ->and($g->fresh()->caja_id)->toBeNull()
        ->and(MovimientoCaja::count())->toBe(0);
});
