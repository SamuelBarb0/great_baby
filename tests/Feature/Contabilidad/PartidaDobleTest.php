<?php

/**
 * La partida doble no se puede descuadrar nunca.
 *
 * Todo lo contable del ERP pasa por `MovimientoContable::registrarAsientoAtomico`:
 * facturas, pagos, recepciones, devoluciones y ajustes de inventario. Si esa
 * puerta deja pasar un asiento descuadrado, el libro queda roto y no hay forma
 * de saber en qué documento empezó. Contabilidad no tenía ni un test, así que
 * la única garantía era que nadie tocara ese método.
 *
 * Lo que se verifica acá:
 *   · un asiento cuadrado se guarda completo;
 *   · uno descuadrado revienta y NO deja ni una línea escrita;
 *   · no se aceptan importes negativos ni debe y haber en la misma línea;
 *   · se tolera la diferencia de redondeo (1 centavo), no más;
 *   · el asiento de una factura cuadra y re-generarlo no duplica el libro.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Actions\RegistrarAsientoContable;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
});

/** Línea de asiento con lo mínimo que exige la tabla. */
function linea(string $cuenta, float $debe, float $haber, int $origenId = 1): array
{
    return [
        'fecha' => now()->toDateString(),
        'cuenta_puc' => $cuenta,
        'debe' => $debe,
        'haber' => $haber,
        'origen_type' => FacturaVenta::class,
        'origen_id' => $origenId,
        'descripcion' => 'Asiento de prueba',
    ];
}

it('guarda un asiento cuadrado', function () {
    $n = MovimientoContable::registrarAsientoAtomico([
        linea('1305', 1190000, 0),
        linea('4135', 0, 1000000),
        linea('2408', 0, 190000),
    ]);

    expect($n)->toBe(3)
        ->and(MovimientoContable::count())->toBe(3)
        ->and((float) MovimientoContable::sum('debe'))->toBe((float) MovimientoContable::sum('haber'));
});

it('rechaza un asiento descuadrado y no escribe nada', function () {
    expect(fn () => MovimientoContable::registrarAsientoAtomico([
        linea('1305', 1190000, 0),
        linea('4135', 0, 1000000),
        // Faltan los 190.000 de IVA: el libro quedaría torcido.
    ]))->toThrow(RuntimeException::class, 'desbalanceado');

    expect(MovimientoContable::count())->toBe(0);
});

it('rechaza importes negativos', function () {
    expect(fn () => MovimientoContable::registrarAsientoAtomico([
        linea('1305', -1000, 0),
        linea('4135', 0, -1000),
    ]))->toThrow(RuntimeException::class, 'negativos');

    expect(MovimientoContable::count())->toBe(0);
});

it('rechaza debe y haber en la misma línea', function () {
    // Una línea con las dos patas no es un asiento: es una resta disfrazada, y
    // deja de poder leerse en el libro auxiliar de la cuenta.
    expect(fn () => MovimientoContable::registrarAsientoAtomico([
        linea('1305', 1000, 1000),
    ]))->toThrow(RuntimeException::class, 'misma línea');

    expect(MovimientoContable::count())->toBe(0);
});

it('tolera un centavo de redondeo pero no diez', function () {
    MovimientoContable::registrarAsientoAtomico([
        linea('1305', 1000.00, 0),
        linea('4135', 0, 999.99),
    ]);
    expect(MovimientoContable::count())->toBe(2);

    expect(fn () => MovimientoContable::registrarAsientoAtomico([
        linea('1305', 1000.00, 0, 2),
        linea('4135', 0, 999.90, 2),
    ]))->toThrow(RuntimeException::class);
});

it('un asiento vacío no hace nada', function () {
    expect(MovimientoContable::registrarAsientoAtomico([]))->toBe(0)
        ->and(MovimientoContable::count())->toBe(0);
});

it('el asiento de una factura cuadra y no se duplica al re-generarlo', function () {
    $cliente = Contacto::create([
        'numero_documento' => '900999888',
        'nombre_completo' => 'Mayorista de Prueba SAS',
    ]);

    $factura = FacturaVenta::create([
        'numero' => 'FV-CONTA-001',
        'contacto_id' => $cliente->id,
        'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => 1000000,
        'descuento' => 0,
        'impuestos' => 190000,
        'total' => 1190000,
        'saldo' => 1190000,
        'estado' => EstadoFactura::Pendiente,
    ]);

    RegistrarAsientoContable::make()->factura($factura);

    $lineas = MovimientoContable::where('origen_id', $factura->id)
        ->where('origen_type', FacturaVenta::class)->get();

    expect($lineas)->not->toBeEmpty()
        ->and(round($lineas->sum('debe'), 2))->toBe(round($lineas->sum('haber'), 2))
        ->and((float) $lineas->firstWhere('cuenta_puc', cta('cta_cxc_default'))->debe)->toBe(1190000.0);

    // Re-generar tiene que reemplazar, no acumular: si no, el mayor de la
    // cuenta 1305 muestra la misma venta dos veces.
    $antes = $lineas->count();
    RegistrarAsientoContable::make()->factura($factura);

    expect(MovimientoContable::where('origen_id', $factura->id)
        ->where('origen_type', FacturaVenta::class)->count())->toBe($antes);
});
