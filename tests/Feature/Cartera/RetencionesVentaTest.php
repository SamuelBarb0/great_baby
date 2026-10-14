<?php

/**
 * Retenciones automáticas al facturar.
 *
 * En una venta retiene el CLIENTE: paga total − retenciones y lo retenido
 * queda a favor de Great Baby. Hasta el 2026-10-13 las reglas existían sólo
 * para compras y la factura de venta las ignoraba: un cliente agente de
 * retención pagaba el neto y la cartera lo mostraba debiendo la diferencia.
 *
 * Lo que se protege acá:
 *   · sólo retiene el cliente marcado en su ficha, y sólo lo marcado;
 *   · las tarifas y bases mínimas son las de las reglas;
 *   · el saldo es el neto, y un pago completo del neto la deja pagada;
 *   · el asiento va a 1355 (a favor), no a la 2365 de la regla de compras,
 *     y cuadra;
 *   · aplicar dos veces no duplica;
 *   · a SIIGO viaja el impuesto de retención y el pago neto, y una regla sin
 *     su id de SIIGO frena la emisión en vez de mandar otro total.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Actions\AplicarRetencionesVenta;
use App\Modules\Cartera\Actions\RegistrarAsientoContable;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\FacturaVentaItem;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Cartera\Models\RetencionAplicada;
use App\Modules\Cartera\Models\RetencionConfig;
use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Services\SiigoEmisionService;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    // Reglas de compras tal como las configura Cartera (cuentas 2365/2368).
    $this->rf = RetencionConfig::create([
        'tipo' => 'retefuente', 'concepto' => 'compras_generales', 'base_minima' => 1138950,
        'tarifa_pct' => 2.5, 'cuenta_puc' => '236540', 'siigo_tax_id' => 7001, 'activa' => true,
    ]);
    $this->ri = RetencionConfig::create([
        'tipo' => 'reteica', 'concepto' => 'compras_generales', 'ciudad' => 'Bogotá', 'base_minima' => 0,
        'tarifa_pct' => 0.414, 'cuenta_puc' => '236805', 'siigo_tax_id' => 7002, 'activa' => true,
    ]);
    $this->riva = RetencionConfig::create([
        'tipo' => 'reteiva', 'concepto' => 'general', 'base_minima' => 0,
        'tarifa_pct' => 15, 'cuenta_puc' => '236701', 'siigo_tax_id' => 7003, 'activa' => true,
    ]);

    $this->cliente = Contacto::create([
        'numero_documento' => '900777888',
        'nombre_completo' => 'Almacenes Grandes SAS',
        'ciudad' => 'Bogotá',
    ]);
});

/** Factura de $2.000.000 + IVA 19%, como sale de facturar un pedido B2B. */
function facturaRetenible(Contacto $cliente, float $subtotal = 2000000, float $ivaPct = 19): FacturaVenta
{
    $iva = round($subtotal * $ivaPct / 100, 2);
    $f = FacturaVenta::create([
        'numero' => 'FV-RET-'.fake()->unique()->numberBetween(1000, 999999),
        'contacto_id' => $cliente->id,
        'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => $subtotal,
        'descuento' => 0,
        'impuestos' => $iva,
        'total' => $subtotal + $iva,
        'saldo' => $subtotal + $iva,
        'estado' => EstadoFactura::Pendiente,
    ]);
    // Ya sincronizado con SIIGO, para que el payload tenga con qué código salir.
    $producto = App\Modules\Dropi\Models\Producto::sinKardexAutomatico(fn () => App\Modules\Dropi\Models\Producto::create([
        'referencia' => 'PAN-'.fake()->unique()->numberBetween(100, 99999), 'nombre' => 'Pañales x 100',
        'desglose_stock' => false,
    ]));
    $producto->forceFill(['siigo_id' => (string) fake()->uuid()])->saveQuietly();
    FacturaVentaItem::create([
        'factura_id' => $f->id, 'producto_id' => $producto->id, 'descripcion' => 'Pañales x 100',
        'cantidad' => 1, 'precio_unit' => $subtotal, 'impuesto_pct' => $ivaPct, 'subtotal' => $subtotal,
    ]);

    return $f;
}

function retiene(Contacto $c, array $marcadas): void
{
    $c->forceFill(['retenciones_default' => $marcadas])->save();
}

it('un cliente sin marcar no retiene nada', function () {
    $f = facturaRetenible($this->cliente);

    expect(app(AplicarRetencionesVenta::class)->ejecutar($f))->toBe(0.0)
        ->and((float) $f->fresh()->saldo)->toBe(2380000.0)
        ->and(RetencionAplicada::count())->toBe(0);
});

it('calcula retefuente, reteICA y reteIVA y deja el saldo en el neto', function () {
    retiene($this->cliente, ['fuente' => true, 'ica' => true, 'iva' => true]);
    $f = facturaRetenible($this->cliente);

    $total = app(AplicarRetencionesVenta::class)->ejecutar($f);

    // 2,5% de 2.000.000 + 0,414% de 2.000.000 + 15% de 380.000
    expect($total)->toBe(50000.0 + 8280.0 + 57000.0);

    $f->refresh();
    expect((float) $f->retenciones)->toBe(115280.0)
        ->and((float) $f->saldo)->toBe(2380000.0 - 115280.0);

    $porTipo = RetencionAplicada::where('origen_id', $f->id)->get()->keyBy('tipo');
    expect((float) $porTipo['retefuente']->valor)->toBe(50000.0)
        ->and((float) $porTipo['reteica']->valor)->toBe(8280.0)
        ->and((float) $porTipo['reteiva']->valor)->toBe(57000.0)
        // A favor (1355), no la cuenta por pagar de la regla de compras.
        ->and($porTipo['retefuente']->cuenta_puc)->toBe('135515')
        ->and($porTipo['reteiva']->cuenta_puc)->toBe('135517')
        ->and($porTipo['reteica']->cuenta_puc)->toBe('135518');
});

it('sólo aplica lo que el cliente tiene marcado', function () {
    retiene($this->cliente, ['fuente' => true]);
    $f = facturaRetenible($this->cliente);

    app(AplicarRetencionesVenta::class)->ejecutar($f);

    expect(RetencionAplicada::pluck('tipo')->all())->toBe(['retefuente']);
});

it('respeta la base mínima de la regla', function () {
    // 1.000.000 < 27 UVT: no hay retefuente, pero el reteICA no tiene base.
    retiene($this->cliente, ['fuente' => true, 'ica' => true]);
    $f = facturaRetenible($this->cliente, 1000000);

    app(AplicarRetencionesVenta::class)->ejecutar($f);

    expect(RetencionAplicada::pluck('tipo')->all())->toBe(['reteica']);
});

it('reteICA sólo con regla para la ciudad del cliente', function () {
    retiene($this->cliente, ['ica' => true]);
    $this->cliente->update(['ciudad' => 'Cali']);
    $f = facturaRetenible($this->cliente);

    expect(app(AplicarRetencionesVenta::class)->ejecutar($f))->toBe(0.0);
});

it('aplicar dos veces no duplica', function () {
    retiene($this->cliente, ['fuente' => true]);
    $f = facturaRetenible($this->cliente);

    app(AplicarRetencionesVenta::class)->ejecutar($f);
    app(AplicarRetencionesVenta::class)->ejecutar($f->fresh());

    expect(RetencionAplicada::count())->toBe(1)
        ->and((float) $f->fresh()->saldo)->toBe(2380000.0 - 50000.0);
});

it('el asiento lleva lo retenido a 1355 y cuadra', function () {
    retiene($this->cliente, ['fuente' => true, 'iva' => true]);
    $f = facturaRetenible($this->cliente);
    app(AplicarRetencionesVenta::class)->ejecutar($f);

    (new RegistrarAsientoContable())->factura($f->fresh());

    $movs = MovimientoContable::where('origen_type', FacturaVenta::class)->where('origen_id', $f->id)->get();
    expect((float) $movs->firstWhere('cuenta_puc', '1305')->debe)->toBe(2380000.0 - 107000.0)
        ->and((float) $movs->firstWhere('cuenta_puc', '135515')->debe)->toBe(50000.0)
        ->and((float) $movs->firstWhere('cuenta_puc', '135517')->debe)->toBe(57000.0)
        ->and(round((float) $movs->sum('debe'), 2))->toBe(round((float) $movs->sum('haber'), 2));
});

it('un pago del neto deja la factura pagada', function () {
    retiene($this->cliente, ['fuente' => true]);
    $f = facturaRetenible($this->cliente);
    app(AplicarRetencionesVenta::class)->ejecutar($f);

    PagoVenta::create([
        'factura_id' => $f->id, 'contacto_id' => $this->cliente->id, 'fecha' => now()->toDateString(),
        'monto_recibido' => 2330000, 'monto_aplicado' => 2330000, 'medio_pago' => 'transferencia',
    ]);
    $f->refresh()->recalcular();

    expect((float) $f->saldo)->toBe(0.0)
        ->and($f->estado)->toBe(EstadoFactura::Pagada);
});

/** Payload de /v1/invoices sin llamar a SIIGO. */
function payloadSiigo(FacturaVenta $f): array
{
    $config = SiigoConfig::current();
    $config->forceFill(['tipo_documento_id' => 24446, 'seller_id' => 629, 'payment_type_id' => 5636])->save();

    $svc = app(SiigoEmisionService::class);
    $m = new ReflectionMethod($svc, 'construirPayload');

    return $m->invoke($svc, $f->fresh(), $config);
}

it('a SIIGO viaja el impuesto de retención y el pago neto', function () {
    retiene($this->cliente, ['fuente' => true]);
    $f = facturaRetenible($this->cliente, 2000000, 0);
    app(AplicarRetencionesVenta::class)->ejecutar($f);

    $payload = payloadSiigo($f);

    expect($payload['items'][0]['taxes'])->toBe([['id' => 7001]])
        ->and($payload['payments'][0]['value'])->toBe(2000000.0 - 50000.0);
});

it('una retención sin id de SIIGO frena la emisión', function () {
    $this->rf->update(['siigo_tax_id' => null]);
    retiene($this->cliente, ['fuente' => true]);
    $f = facturaRetenible($this->cliente, 2000000, 0);
    app(AplicarRetencionesVenta::class)->ejecutar($f);

    expect(fn () => payloadSiigo($f))->toThrow(RuntimeException::class, 'ID impuesto SIIGO');
});
