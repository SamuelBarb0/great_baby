<?php

/**
 * Un pago va a donde va su factura.
 *
 * Es la regla entera: el destino se decide UNA vez, al emitir la factura, y el
 * pago lo hereda. Darle al pago un selector propio permitiría un pago «fiscal»
 * de una factura interna, que no significa nada y deja la CxC fiscal en
 * negativo: se abonaría en el libro fiscal una deuda que ahí nunca se cargó.
 *
 * El reparto es el único caso con decisión de verdad, y tampoco se pregunta:
 * se cubre PRIMERO lo declarado. Es lo que pasa en la realidad — en SIIGO hay
 * una factura por el monto declarado y el cliente la paga como cualquier otra,
 * nadie registra el 26,8 % de cada abono.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Actions\CrearFacturaManual;
use App\Modules\Cartera\Actions\RegistrarAsientoContable;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->cliente = Contacto::create([
        'numero_documento' => '900333222',
        'nombre_completo' => 'Cliente que paga SAS',
        'es_cliente' => true,
    ]);

    $this->producto = Producto::create([
        'referencia' => 'REF-P', 'nombre' => 'Producto', 'activo' => true,
    ]);
});

function facturaParaPagar(DestinoFiscal $destino, float $total, ?float $fiscal = null, ?LineaNegocio $linea = null): FacturaVenta
{
    return CrearFacturaManual::run([
        'contacto_id' => test()->cliente->id,
        'fecha_emision' => now()->toDateString(),
        'destino_fiscal' => $destino->value,
        'linea_negocio' => ($linea ?? LineaNegocio::GreatBaby)->value,
        'monto_fiscal' => $fiscal,
        'motivo_destino' => $destino === DestinoFiscal::Siigo ? null : 'Motivo',
        'items' => [[
            'producto_id' => test()->producto->id,
            'descripcion' => 'Venta', 'cantidad' => 1, 'precio_unit' => $total,
        ]],
    ]);
}

function pagar(FacturaVenta $f, float $monto): PagoVenta
{
    $p = PagoVenta::create([
        'factura_id' => $f->id,
        'contacto_id' => $f->contacto_id,
        'fecha' => now()->toDateString(),
        'medio_pago' => 'transferencia',
        'monto_recibido' => $monto,
        'monto_aplicado' => $monto,
        'diferencia' => 0,
    ]);

    (new RegistrarAsientoContable())->pago($p->fresh());

    return $p->fresh();
}

/** Las líneas del asiento de un pago, por destino. */
function lineasDelPago(PagoVenta $p, DestinoFiscal $d)
{
    return MovimientoContable::where('origen_type', PagoVenta::class)
        ->where('origen_id', $p->id)
        ->where('destino_fiscal', $d->value);
}

it('un pago a una factura interna NO asienta en lo fiscal', function () {
    /*
     * Esto estaba mal: `PagoVenta` no tiene los ejes y el estampado caía al
     * default, que es «fiscal». Resultado: se abonaba en el libro fiscal una
     * deuda que ahí nunca se había cargado, y la CxC fiscal quedaba negativa.
     */
    $f = facturaParaPagar(DestinoFiscal::Interno, 100_000);
    $p = pagar($f, 40_000);

    expect(lineasDelPago($p, DestinoFiscal::Siigo)->count())->toBe(0)
        ->and(lineasDelPago($p, DestinoFiscal::Interno)->count())->toBeGreaterThan(0);
});

it('un pago a una factura fiscal sí asienta en lo fiscal', function () {
    $f = facturaParaPagar(DestinoFiscal::Siigo, 100_000);
    $p = pagar($f, 40_000);

    expect(lineasDelPago($p, DestinoFiscal::Interno)->count())->toBe(0)
        ->and((float) lineasDelPago($p, DestinoFiscal::Siigo)->sum('haber'))->toEqual(40_000.0);
});

it('un pago hereda la línea de negocio de su factura', function () {
    // Un pago a una factura de Dropi caía en Great Baby, porque la línea se
    // deducía del namespace del modelo y PagoVenta vive en Cartera.
    $f = facturaParaPagar(DestinoFiscal::Siigo, 100_000, null, LineaNegocio::Dropi);
    $p = pagar($f, 40_000);

    $deLinea = fn (LineaNegocio $l) => MovimientoContable::where('origen_type', PagoVenta::class)
        ->where('origen_id', $p->id)->where('linea_negocio', $l->value)->count();

    expect($deLinea(LineaNegocio::GreatBaby))->toBe(0)
        ->and($deLinea(LineaNegocio::Dropi))->toBeGreaterThan(0);
});

it('en un reparto se cubre primero lo declarado', function () {
    // Factura de $100.000 con $20.000 declarados. El cliente abona $30.000:
    // los primeros $20.000 cierran la parte declarada y $10.000 van a interno.
    $f = facturaParaPagar(DestinoFiscal::Reparto, 100_000, 20_000);
    $p = pagar($f, 30_000);

    expect((float) lineasDelPago($p, DestinoFiscal::Siigo)->where('cuenta_puc', cta('cta_cxc_default'))->sum('haber'))
        ->toEqual(20_000.0)
        ->and((float) lineasDelPago($p, DestinoFiscal::Interno)->where('cuenta_puc', cta('cta_cxc_default'))->sum('haber'))
        ->toEqual(10_000.0);
});

it('un abono chico en un reparto es todo declarado', function () {
    // $5.000 sobre $20.000 declarados: todavía no se llega a cubrirlos.
    $f = facturaParaPagar(DestinoFiscal::Reparto, 100_000, 20_000);
    $p = pagar($f, 5_000);

    expect(lineasDelPago($p, DestinoFiscal::Interno)->count())->toBe(0)
        ->and((float) lineasDelPago($p, DestinoFiscal::Siigo)->where('cuenta_puc', cta('cta_cxc_default'))->sum('haber'))
        ->toEqual(5_000.0);
});

it('el segundo abono de un reparto ya no suma a lo declarado', function () {
    /*
     * El caso que importa: dos abonos. El primero cubre lo declarado entero,
     * así que el segundo tiene que ir TODO a interno. Si cada pago mirara sólo
     * su propio monto, los $20.000 declarados se cobrarían dos veces en el
     * libro fiscal.
     */
    $f = facturaParaPagar(DestinoFiscal::Reparto, 100_000, 20_000);

    pagar($f, 20_000);              // cubre justo lo declarado
    $segundo = pagar($f->fresh(), 30_000);

    expect(lineasDelPago($segundo, DestinoFiscal::Siigo)->count())->toBe(0)
        ->and((float) lineasDelPago($segundo, DestinoFiscal::Interno)->where('cuenta_puc', cta('cta_cxc_default'))->sum('haber'))
        ->toEqual(30_000.0);
});

it('los dos balances siguen cuadrando después de pagar un reparto', function () {
    $f = facturaParaPagar(DestinoFiscal::Reparto, 100_000, 20_000);
    pagar($f, 30_000);

    $cuadra = fn ($q) => round((float) (clone $q)->sum('debe') - (float) (clone $q)->sum('haber'), 2);

    expect($cuadra(MovimientoContable::where('destino_fiscal', DestinoFiscal::Siigo->value)))->toEqual(0.0)
        ->and($cuadra(MovimientoContable::where('destino_fiscal', DestinoFiscal::Interno->value)))->toEqual(0.0)
        ->and($cuadra(MovimientoContable::query()))->toEqual(0.0);
});

it('la CxC fiscal de un reparto queda en cero al cubrir lo declarado', function () {
    // Lo que tiene que cuadrar contra SIIGO: allá hay una factura de $20.000
    // y un recibo de caja de $20.000.
    $f = facturaParaPagar(DestinoFiscal::Reparto, 100_000, 20_000);
    pagar($f, 20_000);

    $cxcFiscal = MovimientoContable::where('cuenta_puc', cta('cta_cxc_default'))
        ->where('destino_fiscal', DestinoFiscal::Siigo->value);

    expect(round((float) (clone $cxcFiscal)->sum('debe') - (float) (clone $cxcFiscal)->sum('haber'), 2))
        ->toEqual(0.0);
});
