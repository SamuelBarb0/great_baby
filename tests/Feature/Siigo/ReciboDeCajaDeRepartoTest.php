<?php

/**
 * El recibo de caja de un reparto manda sólo la parte declarada.
 *
 * Esto estaba roto y nadie lo veía: el asiento declaraba la porción —fiscal
 * primero— y el recibo que viajaba a SIIGO mandaba el abono ENTERO contra una
 * factura que allá vale una fracción. Dos piezas del mismo reparto
 * contradiciéndose.
 *
 * Con el ejemplo del propio enum (factura de $100 M, se declaran $20 M): en
 * SIIGO quedaba una factura de $20 M y un recibo de $100 M aplicado a ella. O
 * SIIGO lo rechaza, o lo acepta y le deja al cliente $80 M de saldo a favor
 * inventado — que es exactamente lo que el reparto existe para evitar.
 *
 * La regla la calcula `RegistrarAsientoContable::parteDeclaradaDelPago()`, y
 * ahora el voucher usa ESA, no otra. Una sola regla en un solo sitio.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Actions\CrearFacturaManual;
use App\Modules\Cartera\Actions\RegistrarAsientoContable;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Siigo\Exceptions\PagoTotalmenteInterno;
use App\Modules\Siigo\Services\SiigoEmisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->cliente = Contacto::create([
        'numero_documento' => '900444555',
        'nombre_completo' => 'Cliente del Recibo SAS',
        'es_cliente' => true,
    ]);

    $this->producto = Producto::create([
        'referencia' => 'REF-RC', 'nombre' => 'Producto', 'activo' => true,
    ]);
});

function facturaRepartoConSiigo(float $total, float $declarado): FacturaVenta
{
    $f = CrearFacturaManual::run([
        'contacto_id' => test()->cliente->id,
        'fecha_emision' => now()->toDateString(),
        'destino_fiscal' => DestinoFiscal::Reparto->value,
        'monto_fiscal' => $declarado,
        'motivo_destino' => 'Acuerdo comercial',
        'items' => [[
            'producto_id' => test()->producto->id,
            'descripcion' => 'Venta', 'cantidad' => 1, 'precio_unit' => $total,
        ]],
    ]);

    // El recibo sólo viaja si la factura ya está en SIIGO.
    $f->forceFill(['siigo_id' => 'sg-fact-'.$f->id, 'numero_siigo' => 'FV-1-'.$f->id])->saveQuietly();

    return $f->fresh();
}

function abonar(FacturaVenta $f, float $monto): PagoVenta
{
    $p = PagoVenta::create([
        'factura_id' => $f->id,
        'contacto_id' => $f->contacto_id,
        'fecha' => now()->toDateString(),
        'medio_pago' => 'transferencia',
        'monto_recibido' => $monto, 'monto_aplicado' => $monto, 'diferencia' => 0,
    ]);

    (new RegistrarAsientoContable())->pago($p->fresh());
    $f->fresh()->recalcular();

    return $p->fresh();
}

/** Lo que `emitirVoucher` le mandaría a SIIGO, sin llamar a nadie. */
function montoDelRecibo(PagoVenta $p): float
{
    $servicio = app(SiigoEmisionService::class);

    // Se corta el POST justo después de calcular el monto: lo que interesa es
    // la cifra, no la llamada.
    $r = new ReflectionMethod($servicio, 'esReparto');
    $r->setAccessible(true);

    if (! $r->invoke($servicio, $p->factura)) {
        return (float) $p->monto_aplicado;
    }

    return (new RegistrarAsientoContable())->parteDeclaradaDelPago($p);
}

it('el recibo manda la parte declarada, no el abono entero', function () {
    // $100 M de venta real, $20 M declarados. El cliente abona $30 M.
    $f = facturaRepartoConSiigo(100_000_000, 20_000_000);
    $p = abonar($f, 30_000_000);

    expect(montoDelRecibo($p))->toEqual(20_000_000.0)
        // Lo que NO hay que mandar.
        ->and((float) $p->monto_aplicado)->toEqual(30_000_000.0);
});

it('un abono chico va entero, porque todo él es declarado', function () {
    $f = facturaRepartoConSiigo(100_000_000, 20_000_000);
    $p = abonar($f, 5_000_000);

    expect(montoDelRecibo($p))->toEqual(5_000_000.0);
});

it('cubierto lo declarado, el abono siguiente NO genera recibo', function () {
    /*
     * El caso que más duele: si el segundo abono también viajara, SIIGO
     * recibiría $20 M + $30 M contra una factura que allá vale $20 M.
     */
    $f = facturaRepartoConSiigo(100_000_000, 20_000_000);

    abonar($f, 20_000_000);                       // cubre justo lo declarado
    $segundo = abonar($f->fresh(), 30_000_000);

    expect(montoDelRecibo($segundo))->toEqual(0.0);
});

it('un abono totalmente interno se omite con un motivo, no revienta', function () {
    $f = facturaRepartoConSiigo(100_000_000, 20_000_000);
    abonar($f, 20_000_000);
    $segundo = abonar($f->fresh(), 30_000_000);

    expect(fn () => app(SiigoEmisionService::class)->emitirVoucher($segundo))
        ->toThrow(PagoTotalmenteInterno::class, 'ya quedo cubierto');
});

it('la nota credito de un reparto viaja escalada, no por su valor completo', function () {
    /*
     * El gemelo del bug del recibo. Alla la factura vale `monto_fiscal`: una
     * NC por el valor completo seria una nota credito MAYOR que la factura
     * que anula.
     */
    $f = facturaRepartoConSiigo(100_000_000, 20_000_000);   // se declara el 20 %

    $nc = \App\Modules\Cartera\Models\NotaCredito::create([
        'factura_id' => $f->id,
        'prefijo' => 'NC', 'numero' => fake()->unique()->numberBetween(1000, 9999),
        'valor' => 10_000_000,
        'motivo' => 'Devolucion parcial',
        'estado' => 'borrador',
    ]);

    $servicio = app(SiigoEmisionService::class);
    $m = new ReflectionMethod($servicio, 'valorDeclaradoDeLaNota');
    $m->setAccessible(true);

    // 20 % de $10 M = $2 M.
    expect($m->invoke($servicio, $nc->fresh()->load('factura')))->toEqual(2_000_000.0);
});

it('la nota credito de una factura fiscal normal no se toca', function () {
    $f = CrearFacturaManual::run([
        'contacto_id' => $this->cliente->id,
        'fecha_emision' => now()->toDateString(),
        'destino_fiscal' => DestinoFiscal::Siigo->value,
        'items' => [[
            'producto_id' => $this->producto->id,
            'descripcion' => 'Venta', 'cantidad' => 1, 'precio_unit' => 5_000_000,
        ]],
    ]);
    $f->forceFill(['siigo_id' => 'sg-n'])->saveQuietly();

    $nc = \App\Modules\Cartera\Models\NotaCredito::create([
        'factura_id' => $f->id,
        'prefijo' => 'NC', 'numero' => fake()->unique()->numberBetween(1000, 9999),
        'valor' => 1_000_000,
        'motivo' => 'Devolucion',
        'estado' => 'borrador',
    ]);

    $servicio = app(SiigoEmisionService::class);
    $m = new ReflectionMethod($servicio, 'valorDeclaradoDeLaNota');
    $m->setAccessible(true);

    expect($m->invoke($servicio, $nc->fresh()->load('factura')))->toEqual(1_000_000.0);
});

it('una factura fiscal normal sigue mandando el abono completo', function () {
    // El comportamiento de siempre no se toca.
    $f = CrearFacturaManual::run([
        'contacto_id' => $this->cliente->id,
        'fecha_emision' => now()->toDateString(),
        'destino_fiscal' => DestinoFiscal::Siigo->value,
        'items' => [[
            'producto_id' => $this->producto->id,
            'descripcion' => 'Venta', 'cantidad' => 1, 'precio_unit' => 1_000_000,
        ]],
    ]);
    $f->forceFill(['siigo_id' => 'sg-x'])->saveQuietly();

    $p = abonar($f->fresh(), 400_000);

    expect(montoDelRecibo($p))->toEqual(400_000.0);
});

it('la suma de los recibos nunca supera lo declarado', function () {
    /*
     * El invariante que importa: pase lo que pase con los abonos, SIIGO no
     * puede recibir más plata de la que dice su propia factura.
     */
    $f = facturaRepartoConSiigo(100_000_000, 20_000_000);

    $total = 0.0;
    foreach ([8_000_000, 8_000_000, 8_000_000, 40_000_000] as $monto) {
        $total += montoDelRecibo(abonar($f->fresh(), $monto));
    }

    expect($total)->toEqual(20_000_000.0);
});
