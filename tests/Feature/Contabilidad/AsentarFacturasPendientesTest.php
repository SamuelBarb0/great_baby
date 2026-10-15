<?php

/**
 * Una factura sin asiento deja el libro diciendo una verdad a medias.
 *
 * Encontrado el 2026-10-14 revisando contabilidad antes de una demo: había 15
 * facturas por $11.960.000 sin asentar, mientras el pago de UNA de ellas sí
 * estaba en el libro. Resultado: la cuenta **1305 Clientes en negativo**
 * ($−1.060.800) — el crédito del pago sin el débito de su factura—, o sea un
 * balance que afirma que la empresa le debe plata a sus clientes.
 *
 * El asiento lo dispara el modelo al emitir. Las que nacen por fuera de ese
 * camino —cargas iniciales, semillas, importaciones de datos viejos— se quedan
 * sin él y nadie se entera, porque el balance igual «cuadra»: le faltan las dos
 * patas a la vez.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
    User::factory()->create();

    $this->cliente = Contacto::create([
        'numero_documento' => '901777888', 'nombre_completo' => 'Pañalera Mundo Bebé SAS',
        'es_cliente' => true, 'ciudad' => 'Medellín',
    ]);
});

/** Factura creada por fuera del flujo, como la deja una carga inicial. */
function facturaSinAsiento($test, float $total = 320000): FacturaVenta
{
    $f = FacturaVenta::withoutEvents(fn () => FacturaVenta::create([
        'numero' => 'FV-'.fake()->unique()->numerify('######'),
        'contacto_id' => $test->cliente->id, 'estado' => 'pendiente',
        'fecha_emision' => now()->subMonths(3)->toDateString(),
        'fecha_vencimiento' => now()->subMonths(2)->toDateString(),
        'subtotal' => $total, 'impuestos' => 0, 'total' => $total,
    ]));

    return $f;
}

function movimientosDe(FacturaVenta $f)
{
    return MovimientoContable::where('origen_type', FacturaVenta::class)
        ->where('origen_id', $f->id);
}

it('el ensayo en seco no toca el libro', function () {
    $f = facturaSinAsiento($this);

    $this->artisan('contabilidad:asentar-facturas-pendientes --dry-run')->assertSuccessful();

    expect(movimientosDe($f)->count())->toBe(0);
});

it('asienta la factura que no tenía movimientos', function () {
    $f = facturaSinAsiento($this);
    expect(movimientosDe($f)->count())->toBe(0);

    $this->artisan('contabilidad:asentar-facturas-pendientes')->assertSuccessful();

    expect(movimientosDe($f)->count())->toBeGreaterThan(0);
});

it('deja la cuenta de clientes en debe, no en haber', function () {
    // Es el síntoma concreto que lo delató: 1305 en negativo.
    $f = facturaSinAsiento($this, 320000);

    $this->artisan('contabilidad:asentar-facturas-pendientes');

    $cxc = movimientosDe($f)->where('cuenta_puc', '1305')->first();

    expect((float) $cxc->debe)->toEqual(320000.0)
        ->and((float) $cxc->haber)->toEqual(0.0);
});

it('el asiento queda cuadrado', function () {
    facturaSinAsiento($this, 320000);

    $this->artisan('contabilidad:asentar-facturas-pendientes');

    expect((float) MovimientoContable::sum('debe'))
        ->toEqual((float) MovimientoContable::sum('haber'));
});

it('correrlo dos veces no duplica', function () {
    // `RegistrarAsientoContable` limpia los previos antes de rehacer; si eso
    // cambia, el libro se duplicaría cada vez que alguien reintente.
    $f = facturaSinAsiento($this);

    $this->artisan('contabilidad:asentar-facturas-pendientes');
    $primera = movimientosDe($f)->count();

    $this->artisan('contabilidad:asentar-facturas-pendientes --todas');

    expect(movimientosDe($f)->count())->toBe($primera);
});

it('no vuelve a tocar las que ya estaban asentadas', function () {
    $f = facturaSinAsiento($this);
    $this->artisan('contabilidad:asentar-facturas-pendientes');

    // Sin `--todas` ya no queda nada por hacer.
    $this->artisan('contabilidad:asentar-facturas-pendientes')
        ->expectsOutputToContain('No hay facturas pendientes de asentar.')
        ->assertSuccessful();
});
