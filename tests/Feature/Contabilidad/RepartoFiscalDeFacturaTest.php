<?php

/**
 * El reparto: una venta real de la que sólo se declara una parte.
 *
 * El caso que planteó Yeniffer: factura de $100 M, el cliente sólo acepta
 * factura electrónica por $20 M. El ERP conserva los $100 M —es la venta de
 * verdad, la que mueve inventario y la que el cliente debe— y a SIIGO se le
 * manda la porción declarada.
 *
 * Lo que se prueba acá es que de un MISMO asiento salgan los dos balances:
 *   · el fiscal, que tiene que cuadrar contra SIIGO
 *   · el real, que tiene que cuadrar contra la operación
 *
 * Si el reparto se calcula mal, los dos siguen «cuadrando» cada uno por su lado
 * —porque a cada línea le faltan las dos patas a la vez— y el error sólo
 * aparece cuando alguien compara el balance fiscal contra lo que de verdad
 * reporta SIIGO. O sea, en el cierre.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Actions\RegistrarAsientoContable;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->cliente = Contacto::create([
        'numero_documento' => '900111222',
        'nombre_completo' => 'Cliente del Reparto SAS',
        'es_cliente' => true,
    ]);
});

function facturaConDestino(Contacto $cliente, float $total, DestinoFiscal $destino, ?float $fiscal = null): FacturaVenta
{
    return FacturaVenta::create([
        'numero' => 'FV-RP-'.fake()->unique()->numberBetween(1000, 999999),
        'contacto_id' => $cliente->id,
        'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => $total, 'descuento' => 0, 'impuestos' => 0,
        'total' => $total, 'saldo' => $total,
        'estado' => EstadoFactura::Pendiente,
        'destino_fiscal' => $destino,
        'monto_fiscal' => $fiscal,
    ]);
}

function asentar(FacturaVenta $f): void
{
    (new RegistrarAsientoContable())->factura($f->fresh());
}

$soloDestino = fn (DestinoFiscal $d) => MovimientoContable::where('destino_fiscal', $d->value);

it('parte el asiento en la proporción declarada', function () use ($soloDestino) {
    // $100 M de venta real, $20 M declarados = 20 %.
    asentar(facturaConDestino($this->cliente, 100_000_000, DestinoFiscal::Reparto, 20_000_000));

    // La CxC al cliente: $20 M declarados y $80 M internos.
    $cxcFiscal = (float) MovimientoContable::where('cuenta_puc', cta('cta_cxc_default'))
        ->where('destino_fiscal', DestinoFiscal::Siigo->value)->sum('debe');
    $cxcInterna = (float) MovimientoContable::where('cuenta_puc', cta('cta_cxc_default'))
        ->where('destino_fiscal', DestinoFiscal::Interno->value)->sum('debe');

    expect($cxcFiscal)->toEqual(20_000_000.0)
        ->and($cxcInterna)->toEqual(80_000_000.0);
});

it('los dos balances salen del mismo asiento y cada uno cuadra', function () {
    asentar(facturaConDestino($this->cliente, 100_000_000, DestinoFiscal::Reparto, 20_000_000));

    $cuadra = fn ($q) => round((float) (clone $q)->sum('debe') - (float) (clone $q)->sum('haber'), 2);

    // Balance fiscal · lo que tiene que cuadrar contra SIIGO
    expect($cuadra(MovimientoContable::where('destino_fiscal', DestinoFiscal::Siigo->value)))->toEqual(0.0)
        // Balance real · todo junto
        ->and($cuadra(MovimientoContable::query()))->toEqual(0.0);
});

it('el ERP conserva la venta completa, no sólo lo declarado', function () {
    asentar(facturaConDestino($this->cliente, 100_000_000, DestinoFiscal::Reparto, 20_000_000));

    // El ingreso total del libro sigue siendo la venta real.
    expect((float) MovimientoContable::where('cuenta_puc', cta('cta_ingreso_default'))->sum('haber'))->toEqual(100_000_000.0);
});

it('una factura fiscal marca todas sus líneas como declaradas', function () {
    asentar(facturaConDestino($this->cliente, 5_000_000, DestinoFiscal::Siigo));

    expect(MovimientoContable::where('destino_fiscal', '!=', DestinoFiscal::Siigo->value)->count())->toBe(0)
        ->and(MovimientoContable::count())->toBeGreaterThan(0);
});

it('una factura interna no declara ni un peso', function () {
    asentar(facturaConDestino($this->cliente, 5_000_000, DestinoFiscal::Interno));

    expect(MovimientoContable::where('destino_fiscal', DestinoFiscal::Siigo->value)->count())->toBe(0);
});

it('un reparto sin monto declarado queda INTERNO, que es lo conservador', function () {
    // Marcar reparto y no decir cuánto no puede terminar declarando la venta
    // entera: eso sería declarar algo que nadie decidió declarar.
    asentar(facturaConDestino($this->cliente, 5_000_000, DestinoFiscal::Reparto, null));

    expect(MovimientoContable::where('destino_fiscal', DestinoFiscal::Siigo->value)->count())->toBe(0)
        ->and(MovimientoContable::where('destino_fiscal', DestinoFiscal::Interno->value)->count())->toBeGreaterThan(0);
});

it('el redondeo nunca descuadra el asiento', function () {
    // Una proporción que no da exacta: 1/3 de un total con decimales feos.
    asentar(facturaConDestino($this->cliente, 1_000_001, DestinoFiscal::Reparto, 333_333.33));

    $debe = (float) MovimientoContable::sum('debe');
    $haber = (float) MovimientoContable::sum('haber');

    expect(round($debe - $haber, 2))->toEqual(0.0);
});
