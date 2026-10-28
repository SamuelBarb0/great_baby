<?php

/**
 * La autorretención se practica al EMITIR la factura, no al cobrarla.
 *
 * Lo pidió la contadora en la reunión del 21-oct: «nosotros hacemos una
 * autorrenta por cada facturación que se emita; ese movimiento debe ir amarrado
 * ahí también, así como el IVA». No es lo que el cliente nos retiene —eso ya se
 * contabilizaba contra 1355— sino lo que Great Baby le declara a la DIAN.
 *
 * Son dos patas que se cancelan entre sí (13 activo contra 23 pasivo), así que
 * el riesgo no es que el asiento se descuadre: es que se practique de más, de
 * menos, o sobre la base equivocada. Y nada de eso se nota hasta el cierre.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->cliente = Contacto::create([
        'numero_documento' => '900777888',
        'nombre_completo' => 'Cliente de Prueba SAS',
        'es_cliente' => true,
    ]);
});

function reglaAuto(array $extra = []): void
{
    DB::table('retenciones_config')->insert(array_merge([
        'tipo' => 'autorreteica',
        'ambito' => 'ventas',
        'concepto' => 'Autorretención ICA Bucaramanga 9x1000',
        'base_minima' => 0,
        'tarifa_pct' => 0.9,
        'cuenta_puc' => '23657601',               // pasivo · se le debe a la DIAN
        'cuenta_puc_contrapartida' => '13551816', // activo · anticipo a favor
        'activa' => true,
        'created_at' => now(), 'updated_at' => now(),
    ], $extra));
}

function facturaEmitida(Contacto $cliente, float $subtotal, float $iva = 0): FacturaVenta
{
    return FacturaVenta::create([
        'numero' => 'FV-AR-'.fake()->unique()->numberBetween(1000, 999999),
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
}

it('mueve las dos cuentas al emitir la factura, y por el mismo valor', function () {
    reglaAuto();
    $f = facturaEmitida($this->cliente, 10_000_000, 1_900_000);

    (new App\Modules\Cartera\Actions\RegistrarAsientoContable())->factura($f->fresh());

    // 0,9 % sobre el ingreso (subtotal − descuento), NO sobre el total con IVA.
    $esperado = 90_000.0;

    $activo = MovimientoContable::where('cuenta_puc', '13551816')->first();
    $pasivo = MovimientoContable::where('cuenta_puc', '23657601')->first();

    expect((float) $activo->debe)->toEqual($esperado)
        ->and((float) $activo->haber)->toEqual(0.0)
        ->and((float) $pasivo->haber)->toEqual($esperado)
        ->and((float) $pasivo->debe)->toEqual(0.0);
});

it('no descuadra el asiento de la factura', function () {
    reglaAuto();
    $f = facturaEmitida($this->cliente, 10_000_000, 1_900_000);

    (new App\Modules\Cartera\Actions\RegistrarAsientoContable())->factura($f->fresh());

    $debe = (float) MovimientoContable::sum('debe');
    $haber = (float) MovimientoContable::sum('haber');

    expect(round($debe - $haber, 2))->toEqual(0.0);
});

it('no se practica si la venta no llega a la base mínima', function () {
    reglaAuto(['base_minima' => 5_000_000]);
    $f = facturaEmitida($this->cliente, 1_000_000);

    (new App\Modules\Cartera\Actions\RegistrarAsientoContable())->factura($f->fresh());

    expect(MovimientoContable::where('cuenta_puc', '23657601')->count())->toBe(0);
});

it('una regla inactiva no toca la factura', function () {
    reglaAuto(['activa' => false]);
    $f = facturaEmitida($this->cliente, 10_000_000);

    (new App\Modules\Cartera\Actions\RegistrarAsientoContable())->factura($f->fresh());

    expect(MovimientoContable::where('cuenta_puc', '23657601')->count())->toBe(0);
});

it('una regla a medias —sin la cuenta de activo— se ignora en vez de asentar cojo', function () {
    reglaAuto(['cuenta_puc_contrapartida' => null]);
    $f = facturaEmitida($this->cliente, 10_000_000);

    (new App\Modules\Cartera\Actions\RegistrarAsientoContable())->factura($f->fresh());

    expect(MovimientoContable::where('cuenta_puc', '23657601')->count())->toBe(0);
});

it('las reglas de compras no se disparan al facturar una venta', function () {
    // Una retefuente de compras tiene ámbito «compras»: se practica al pagarle
    // al proveedor, no al emitirle una factura al cliente.
    reglaAuto(['tipo' => 'retefuente', 'ambito' => 'compras', 'cuenta_puc' => '236540']);
    $f = facturaEmitida($this->cliente, 10_000_000);

    (new App\Modules\Cartera\Actions\RegistrarAsientoContable())->factura($f->fresh());

    expect(MovimientoContable::where('cuenta_puc', '236540')->count())->toBe(0);
});

it('acumula varias autorretenciones sobre la misma factura', function () {
    reglaAuto();
    reglaAuto([
        'concepto' => 'Autorretención de renta sobre ventas',
        'tipo' => 'autorrenta', 'tarifa_pct' => 0.4,
        'cuenta_puc' => '23657501', 'cuenta_puc_contrapartida' => '13559501',
    ]);

    $f = facturaEmitida($this->cliente, 10_000_000);
    (new App\Modules\Cartera\Actions\RegistrarAsientoContable())->factura($f->fresh());

    expect((float) MovimientoContable::where('cuenta_puc', '23657601')->sum('haber'))->toEqual(90_000.0)
        ->and((float) MovimientoContable::where('cuenta_puc', '23657501')->sum('haber'))->toEqual(40_000.0);
});
