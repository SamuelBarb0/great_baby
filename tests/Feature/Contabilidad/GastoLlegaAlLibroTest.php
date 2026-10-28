<?php

/**
 * Un gasto aprobado y pagado tiene que aparecer en el libro.
 *
 * Esto no pasaba. La pantalla de gastos tenía el flujo completo —crear,
 * aprobar, marcar pagado— y no generaba ni un movimiento contable. Se aprobaba
 * un gasto, se pagaba, la pantalla confirmaba que todo salió bien, y el balance
 * general y el estado de resultados no se enteraban nunca. Quien mirara la
 * utilidad del mes la veía más alta de lo real por todo lo gastado.
 *
 * Son dos asientos y no uno, porque son dos hechos distintos: la obligación
 * nace al aprobar y se cancela al pagar. Entre medio, el pasivo existe — y es
 * justo lo que alguien necesita ver para saber cuánto debe la empresa.
 */

use App\Models\User;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Actions\RegistrarAsientoGasto;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Gerencia\Models\GastoOperativo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    \App\Support\Reglas::limpiarRequestCache();

    // La pantalla valida la cuenta contra el PUC, para que un error de dedo no
    // cree movimientos contra una cuenta que no existe. En la base de tests el
    // PUC esta vacio, asi que se siembran las que usan estas pruebas.
    // Las cuentas por defecto salen de las reglas, no escritas a mano: si
    // Silvia cambia la de bancos, esta siembra la sigue y la prueba no se cae
    // por un número que ya no se usa.
    foreach ([[cta('cta_gasto_default'), 'Diversos'], ['5105', 'Gastos de personal'],
              [cta('cta_gastos_por_pagar'), 'Costos y gastos por pagar'],
              [cta('cta_caja_default'), 'Caja'], [cta('cta_bancos_default'), 'Bancos']] as [$codigo, $nombre]) {
        \App\Modules\Contabilidad\Models\PlanCuenta::firstOrCreate(
            ['codigo' => $codigo],
            ['nombre' => $nombre, 'nivel' => strlen($codigo) / 2, 'activa' => true],
        );
    }
});

function gastoDe(float $monto, array $extra = []): GastoOperativo
{
    return GastoOperativo::create(array_merge([
        'numero' => 'GO-'.fake()->unique()->numberBetween(1000, 99999),
        'fecha' => now()->toDateString(),
        'categoria' => 'Papelería',
        'descripcion' => 'Resmas de papel',
        'monto' => $monto,
        'metodo_pago' => 'transferencia',
        'tipo' => 'gasto',
        'estado' => 'pendiente',
        // Quien lo pidio es obligatorio en la tabla.
        'solicita_id' => User::factory()->create()->id,
        'destino_fiscal' => DestinoFiscal::Siigo,
        'linea_negocio' => LineaNegocio::GreatBaby,
    ], $extra));
}

/** Los movimientos de un gasto, por cuenta. */
function movsDe(GastoOperativo $g)
{
    return MovimientoContable::where('origen_type', GastoOperativo::class)->where('origen_id', $g->id);
}

// ── El asiento ───────────────────────────────────────────────────────────────

it('al causar, el gasto entra al libro como gasto y como pasivo', function () {
    $g = gastoDe(500_000);

    app(RegistrarAsientoGasto::class)->causar($g);

    expect((float) movsDe($g)->where('cuenta_puc', cta('cta_gasto_default'))->sum('debe'))->toEqual(500_000.0)
        ->and((float) movsDe($g)->where('cuenta_puc', cta('cta_gastos_por_pagar'))->sum('haber'))->toEqual(500_000.0);
});

it('al pagar se cancela el pasivo contra el banco', function () {
    $g = gastoDe(500_000);

    app(RegistrarAsientoGasto::class)->causar($g);
    app(RegistrarAsientoGasto::class)->pagar($g);

    // El pasivo queda en cero: se acreditó al causar y se debitó al pagar.
    $pasivo = movsDe($g)->where('cuenta_puc', cta('cta_gastos_por_pagar'));
    expect(round((float) (clone $pasivo)->sum('debe') - (float) (clone $pasivo)->sum('haber'), 2))->toEqual(0.0)
        // Y la plata salió del banco, porque el medio es transferencia.
        ->and((float) movsDe($g)->where('cuenta_puc', cta('cta_bancos_default'))->sum('haber'))->toEqual(500_000.0);
});

it('un gasto en efectivo sale de caja, no del banco', function () {
    $g = gastoDe(80_000, ['metodo_pago' => 'efectivo']);

    app(RegistrarAsientoGasto::class)->pagar($g);

    expect((float) movsDe($g)->where('cuenta_puc', cta('cta_caja_default'))->sum('haber'))->toEqual(80_000.0)
        ->and(movsDe($g)->where('cuenta_puc', cta('cta_bancos_default'))->count())->toBe(0);
});

it('pagar un gasto que nunca se causó lo causa primero', function () {
    /*
     * Los gastos de antes de que esto existiera no tienen causación. Si el pago
     * debitara un pasivo que nunca se acreditó, 2335 quedaría en negativo para
     * siempre y nadie sabría de dónde salió.
     */
    $g = gastoDe(300_000);

    app(RegistrarAsientoGasto::class)->pagar($g);

    $pasivo = movsDe($g)->where('cuenta_puc', cta('cta_gastos_por_pagar'));
    expect((float) (clone $pasivo)->sum('haber'))->toEqual(300_000.0)
        ->and(round((float) (clone $pasivo)->sum('debe') - (float) (clone $pasivo)->sum('haber'), 2))->toEqual(0.0);
});

it('el asiento cuadra y no se duplica al repetir', function () {
    $g = gastoDe(500_000);

    // Re-aprobar o reintentar el job no puede duplicar el gasto en el estado
    // de resultados.
    app(RegistrarAsientoGasto::class)->causar($g);
    app(RegistrarAsientoGasto::class)->causar($g);
    app(RegistrarAsientoGasto::class)->pagar($g);
    app(RegistrarAsientoGasto::class)->pagar($g);

    expect((float) movsDe($g)->where('cuenta_puc', cta('cta_gasto_default'))->sum('debe'))->toEqual(500_000.0)
        ->and(round((float) movsDe($g)->sum('debe') - (float) movsDe($g)->sum('haber'), 2))->toEqual(0.0);
});

it('usa la cuenta que puso el contador, no la de por defecto', function () {
    $g = gastoDe(200_000, ['cuenta_puc' => '5105']);   // Gastos de personal

    app(RegistrarAsientoGasto::class)->causar($g);

    expect((float) movsDe($g)->where('cuenta_puc', '5105')->sum('debe'))->toEqual(200_000.0)
        ->and(movsDe($g)->where('cuenta_puc', cta('cta_gasto_default'))->count())->toBe(0);
});

it('un gasto interno NO ensucia el libro fiscal', function () {
    $g = gastoDe(400_000, ['destino_fiscal' => DestinoFiscal::Interno, 'motivo_destino' => 'Sin factura']);

    app(RegistrarAsientoGasto::class)->pagar($g);

    expect(movsDe($g)->where('destino_fiscal', DestinoFiscal::Siigo->value)->count())->toBe(0)
        ->and(movsDe($g)->where('destino_fiscal', DestinoFiscal::Interno->value)->count())->toBeGreaterThan(0);
});

it('un gasto de Dropi asienta en la línea Dropi', function () {
    $g = gastoDe(150_000, ['linea_negocio' => LineaNegocio::Dropi]);

    app(RegistrarAsientoGasto::class)->causar($g);

    expect(movsDe($g)->where('linea_negocio', LineaNegocio::GreatBaby->value)->count())->toBe(0)
        ->and(movsDe($g)->where('linea_negocio', LineaNegocio::Dropi->value)->count())->toBeGreaterThan(0);
});

it('rehacer reclasifica el asiento sin duplicarlo', function () {
    $g = gastoDe(500_000);
    app(RegistrarAsientoGasto::class)->causar($g);

    $g->update(['cuenta_puc' => '5105', 'estado' => 'aprobado']);
    app(RegistrarAsientoGasto::class)->rehacer($g->fresh());

    expect((float) movsDe($g)->where('cuenta_puc', '5105')->sum('debe'))->toEqual(500_000.0)
        ->and(movsDe($g)->where('cuenta_puc', cta('cta_gasto_default'))->count())->toBe(0)
        ->and(movsDe($g)->count())->toBe(2);
});

// ── El flujo de la pantalla ──────────────────────────────────────────────────

it('aprobar desde la pantalla deja el gasto en el libro', function () {
    // Ésta es la prueba que importa: el camino que de verdad usa la gente.
    $g = gastoDe(750_000);

    $gerente = User::factory()->create();
    $gerente->assignRole(Role::firstOrCreate(['name' => 'Gerente', 'guard_name' => 'web']));

    $this->actingAs($gerente)->post("/app/gastos/{$g->id}/aprobar")->assertRedirect();

    expect($g->fresh()->estado)->toBe('aprobado')
        ->and((float) movsDe($g)->where('cuenta_puc', cta('cta_gasto_default'))->sum('debe'))->toEqual(750_000.0);
});

it('marcar pagado desde la pantalla cancela el pasivo', function () {
    $g = gastoDe(750_000, ['estado' => 'aprobado']);
    app(RegistrarAsientoGasto::class)->causar($g);

    $contador = User::factory()->create();
    $contador->assignRole(Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']));

    $this->actingAs($contador)->post("/app/gastos/{$g->id}/pagado")->assertRedirect();

    $pasivo = movsDe($g)->where('cuenta_puc', cta('cta_gastos_por_pagar'));
    expect(round((float) (clone $pasivo)->sum('debe') - (float) (clone $pasivo)->sum('haber'), 2))->toEqual(0.0);
});

it('un gasto sin factura del proveedor nace interno', function () {
    /*
     * No hay con qué soportar el costo ni el IVA ante la DIAN. Se marca interno
     * por defecto —lo conservador— y queda visible para cambiarlo si la factura
     * aparece después.
     */
    $u = User::factory()->create();

    $this->actingAs($u)->post('/app/gastos', [
        'fecha' => now()->toDateString(),
        'categoria' => 'Papelería',
        'descripcion' => 'Resmas',
        'monto' => 50_000,
        'metodo_pago' => 'efectivo',
        'tipo' => 'gasto',
    ])->assertRedirect();

    $g = GastoOperativo::latest('id')->first();

    expect($g->destino_fiscal)->toBe(DestinoFiscal::Interno)
        ->and($g->motivo_destino)->toBe('Sin factura del proveedor');
});

it('un gasto CON factura del proveedor nace fiscal', function () {
    $u = User::factory()->create();

    $this->actingAs($u)->post('/app/gastos', [
        'fecha' => now()->toDateString(),
        'categoria' => 'Arriendo',
        'descripcion' => 'Bodega octubre',
        'monto' => 3_000_000,
        'metodo_pago' => 'transferencia',
        'tipo' => 'gasto',
        'factura_ref' => 'FV-8821',
    ])->assertRedirect();

    expect(GastoOperativo::latest('id')->first()->destino_fiscal)->toBe(DestinoFiscal::Siigo);
});

it('el contador reclasifica y el asiento se rehace', function () {
    $g = gastoDe(500_000, ['estado' => 'aprobado']);
    app(RegistrarAsientoGasto::class)->causar($g);

    $contador = User::factory()->create();
    $contador->assignRole(Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']));

    $this->actingAs($contador)->post("/app/gastos/{$g->id}/contabilizar", [
        'cuenta_puc' => '5105',
        'destino' => DestinoFiscal::Interno->value,
        'motivo' => 'El proveedor no factura',
    ])->assertRedirect();

    expect((float) movsDe($g)->where('cuenta_puc', '5105')->sum('debe'))->toEqual(500_000.0)
        ->and(movsDe($g)->where('destino_fiscal', DestinoFiscal::Siigo->value)->count())->toBe(0);
});

it('sacar un gasto de lo fiscal sin decir por qué no pasa', function () {
    $g = gastoDe(500_000, ['estado' => 'aprobado']);

    $contador = User::factory()->create();
    $contador->assignRole(Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']));

    $this->actingAs($contador)->post("/app/gastos/{$g->id}/contabilizar", [
        'destino' => DestinoFiscal::Interno->value,
    ])->assertSessionHasErrors('motivo');

    expect($g->fresh()->destino_fiscal)->toBe(DestinoFiscal::Siigo);
});
