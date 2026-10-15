<?php

/**
 * Un recibo de una factura que no es electrónica no va a SIIGO, y eso no es
 * un fallo: es que no aplica.
 *
 * Con la credencial ya viva, el 2026-10-09 la cola dejó ver el bucle: 3 pagos
 * morían con «apunta a factura FV-xxxx que aún no está en SIIGO», caían en
 * `failed_jobs`, y al día siguiente `siigo:empujar-pendientes` los reencolaba
 * para que murieran igual. Al mirar los datos, **las 23 facturas sin
 * `siigo_id` eran todas `es_electronica = 0`** —demo y semilla—: SIIGO sólo
 * recibe las de la DIAN, así que esos recibos no tenían a qué aplicarse allá
 * ni ese día ni nunca.
 *
 * Lo que se protege:
 *   · factura no electrónica → omitido, sin gastar intentos;
 *   · factura electrónica todavía sin subir → sigue siendo error, porque ahí
 *     sí es cuestión de esperar el orden correcto;
 *   · la red diaria deja de reencolar los que no aplican.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Siigo\Exceptions\PagoSinFacturaEnSiigo;
use App\Modules\Siigo\Jobs\PushVoucherASiigo;
use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Models\SiigoSyncLog;
use App\Modules\Siigo\Services\SiigoEmisionService;

beforeEach(function () {
    // El observer de pagos encola el push al crearlos, y en tests la conexión
    // es `sync`: sin esto, el `create()` del montaje ejecuta el job de verdad.
    Illuminate\Support\Facades\Queue::fake();
    config()->set('siigo.driver', 'fake');
    SiigoConfig::current()->forceFill(['username' => 'sandbox@siigoapi.com', 'access_key' => 'llave-de-prueba', 'push_auto' => true, 'activo' => true])->save();
    SiigoConfig::invalidarPushAutoCache();

    $this->cliente = Contacto::create([
        'numero_documento' => '901222333',
        'nombre_completo' => 'Pañalera de Prueba SAS',
        'es_cliente' => true,
    ]);
});

function facturaCon(bool $electronica, ?string $siigoId, $cliente): FacturaVenta
{
    $f = FacturaVenta::create([
        'numero' => $electronica ? 'FV-261009-0001' : 'FV-DEMO-4198',
        'contacto_id' => $cliente->id,
        'es_electronica' => $electronica,
        'estado' => 'abonada',
        'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        // El modelo exige que cuadre: subtotal - descuento + impuestos = total.
        'subtotal' => 100000, 'impuestos' => 19000, 'total' => 119000,
    ]);

    if ($siigoId) {
        $f->forceFill(['siigo_id' => $siigoId])->save();
    }

    return $f->refresh();
}

function pagoDe(FacturaVenta $f, $cliente): PagoVenta
{
    return PagoVenta::create([
        'factura_id' => $f->id,
        'contacto_id' => $cliente->id,
        'monto_recibido' => 50000,
        'monto_aplicado' => 50000,
        'fecha' => now()->toDateString(),
    ]);
}

it('distingue «no aplica» de «todavía no»', function () {
    $noElectronica = facturaCon(false, null, $this->cliente);
    $pago = pagoDe($noElectronica, $this->cliente);

    expect(fn () => app(SiigoEmisionService::class)->emitirVoucher($pago))
        ->toThrow(PagoSinFacturaEnSiigo::class);
});

it('la factura electrónica sin subir sigue siendo un error que se reintenta', function () {
    // Acá sí es cuestión de orden: la factura va a subir, y el recibo tiene
    // que esperarla. Si esto se volviera «omitido», el pago se perdería.
    $electronica = facturaCon(true, null, $this->cliente);
    $pago = pagoDe($electronica, $this->cliente);

    expect(fn () => app(SiigoEmisionService::class)->emitirVoucher($pago))
        ->toThrow(RuntimeException::class)
        ->and(fn () => app(SiigoEmisionService::class)->emitirVoucher($pago))
        ->not->toThrow(PagoSinFacturaEnSiigo::class);
});

it('el job lo registra como omitido en vez de reventar', function () {
    $pago = pagoDe(facturaCon(false, null, $this->cliente), $this->cliente);

    // Que no lance es media prueba; la otra media es que quede anotado, para
    // que no sea un silencio.
    (new PushVoucherASiigo($pago->id))->handle(app(SiigoEmisionService::class));

    $log = SiigoSyncLog::where('recurso', 'pagos')->latest('id')->first();

    expect($log->estado)->toBe('omitido')
        ->and($log->mensaje)->toContain('no es electrónica');
});

it('la red diaria no reencola pagos de facturas que no van a SIIGO', function () {
    pagoDe(facturaCon(false, null, $this->cliente), $this->cliente);

    // Re-fake: limpia lo que encoló el observer durante el montaje.
    Illuminate\Support\Facades\Queue::fake();
    $this->artisan('siigo:empujar-pendientes', ['--tipo' => 'pagos-cliente'])->assertSuccessful();

    Illuminate\Support\Facades\Queue::assertNotPushed(PushVoucherASiigo::class);
});

it('la red diaria sí reencola el pago de una factura que ya está en SIIGO', function () {
    pagoDe(facturaCon(true, 'siigo-abc-123', $this->cliente), $this->cliente);

    // Re-fake: limpia lo que encoló el observer durante el montaje.
    Illuminate\Support\Facades\Queue::fake();
    $this->artisan('siigo:empujar-pendientes', ['--tipo' => 'pagos-cliente'])->assertSuccessful();

    Illuminate\Support\Facades\Queue::assertPushed(PushVoucherASiigo::class);
});
