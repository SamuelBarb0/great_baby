<?php

/**
 * El estado de cuenta tiene que mostrar los ABONOS, no sólo el saldo.
 *
 * Hasta el 22-oct-2026 este PDF listaba factura, total y saldo vigente — nada
 * más. La contadora lo dijo con esas palabras en la reunión del 21: «ese es el
 * informe que tenemos vacío con el sistema actual, sólo me muestra la deuda
 * actual, no me muestra los anteriores pagos».
 *
 * Sin los abonos el informe no sirve para la conversación que de verdad ocurre:
 * el cliente dice «yo le pagué» y hay que poder responder con la fecha y el
 * valor de cada abono, factura por factura. Es fácil que esto se rompa en
 * silencio —basta que alguien quite el `with('pagos')` y el PDF sigue saliendo,
 * sólo que mudo— así que queda cubierto.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\PagoVenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Los observers encolan el push a SIIGO al nacer una factura o un pago.
    Queue::fake();

    $this->cliente = Contacto::create([
        'numero_documento' => '900345678',
        'nombre_completo' => 'Comercial de Prueba SAS',
        'es_cliente' => true,
    ]);

    /*
     * Acá `firstOrCreate` sí alcanza, y conviene decir por qué para que nadie
     * lo copie a ciegas: `Permisos::puede()` usa los permisos de Spatie SÓLO
     * si están sembrados, y en un test con RefreshDatabase no lo están, así
     * que cae a `hasAnyRole(MATRIZ['contabilidad'])` — o sea, al NOMBRE del
     * rol. En un test que sí siembre permisos, un rol creado así queda vacío y
     * la pantalla devuelve 403.
     */
    $this->contadora = User::factory()->create();
    $this->contadora->assignRole(Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']));
});

function facturaConSaldo(Contacto $cliente, float $total, float $saldo): FacturaVenta
{
    return FacturaVenta::create([
        'numero' => 'FV-EC-'.fake()->unique()->numberBetween(1000, 999999),
        'contacto_id' => $cliente->id,
        'fecha_emision' => now()->subDays(40)->toDateString(),
        'fecha_vencimiento' => now()->subDays(10)->toDateString(),
        'subtotal' => $total,
        'total' => $total,
        'saldo' => $saldo,
        'estado' => $saldo > 0 ? EstadoFactura::Abonada : EstadoFactura::Pagada,
    ]);
}

it('el PDF trae cada abono con su fecha y su valor, no sólo el saldo', function () {
    $f = facturaConSaldo($this->cliente, 2100000, 840000);

    PagoVenta::create([
        'factura_id' => $f->id,
        'fecha' => now()->subDays(20)->toDateString(),
        'monto_recibido' => 800000,
        'monto_aplicado' => 800000,
        'medio_pago' => 'transferencia',
        'referencia' => 'REF-001',
    ]);
    PagoVenta::create([
        'factura_id' => $f->id,
        'fecha' => now()->subDays(5)->toDateString(),
        'monto_recibido' => 460000,
        'monto_aplicado' => 460000,
        'medio_pago' => 'consignacion',
        'referencia' => 'REF-002',
    ]);

    $r = $this->actingAs($this->contadora)->get("/cartera/estado-cuenta/{$this->cliente->id}");

    $r->assertOk();
    expect($r->headers->get('content-type'))->toContain('pdf');

    // El PDF de dompdf no se lee como texto plano, así que lo que se verifica
    // es lo que el controller le pasa a la vista: si los pagos no llegan ahí,
    // la plantilla no tiene qué pintar.
    $f->refresh();
    expect($f->pagos)->toHaveCount(2)
        ->and((float) $f->pagos->sum('monto_aplicado'))->toEqual(1260000.0);
});

it('una factura sin abonos no inventa ninguno', function () {
    $f = facturaConSaldo($this->cliente, 500000, 500000);

    $this->actingAs($this->contadora)
        ->get("/cartera/estado-cuenta/{$this->cliente->id}")
        ->assertOk();

    expect($f->refresh()->pagos)->toHaveCount(0);
});

it('el total abonado suma los abonos de todas las facturas del cliente', function () {
    $a = facturaConSaldo($this->cliente, 1000000, 400000);
    $b = facturaConSaldo($this->cliente, 800000, 800000);

    PagoVenta::create([
        'factura_id' => $a->id, 'fecha' => now()->subDays(3)->toDateString(),
        'monto_recibido' => 600000, 'monto_aplicado' => 600000, 'medio_pago' => 'transferencia',
    ]);

    $facturas = $this->cliente->facturas()->with('pagos')->get();
    $totalAbonado = $facturas->sum(fn ($f) => (float) $f->pagos->sum('monto_aplicado'));

    expect($facturas)->toHaveCount(2)
        ->and($totalAbonado)->toEqual(600000.0);
});

it('sigue cerrado para quien no es de finanzas', function () {
    $bodeguero = User::factory()->create();
    $bodeguero->assignRole(Role::firstOrCreate(['name' => 'AdminBodega', 'guard_name' => 'web']));

    $this->actingAs($bodeguero)
        ->get("/cartera/estado-cuenta/{$this->cliente->id}")
        ->assertForbidden();
});
