<?php

/**
 * El endpoint de facturas pendientes alimenta DOS pantallas: registrar un pago
 * y amarrar una línea de asiento manual a su factura.
 *
 * La segunda necesita el `nit` para llenar el tercero sin que nadie lo teclee —
 * que es justo lo que pidió la contadora: «elijo el NIT del cliente y me
 * despliega las facturas que él me debe». Si alguien quita ese campo del JSON,
 * la pantalla de pagos sigue funcionando y la de asientos deja de llenar el
 * tercero en silencio.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->cliente = Contacto::create([
        'numero_documento' => '900345678',
        'nombre_completo' => 'Comercial Cali SAS',
        'es_cliente' => true,
    ]);

    $this->contadora = User::factory()->create();
    $this->contadora->assignRole(Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']));
});

function facturaPendiente(Contacto $cliente, float $saldo): FacturaVenta
{
    return FacturaVenta::create([
        'numero' => 'FV-FP-'.fake()->unique()->numberBetween(1000, 999999),
        'contacto_id' => $cliente->id,
        'fecha_emision' => now()->subDays(20)->toDateString(),
        'fecha_vencimiento' => now()->addDays(10)->toDateString(),
        'subtotal' => $saldo, 'total' => $saldo, 'saldo' => $saldo,
        'estado' => EstadoFactura::Pendiente,
    ]);
}

it('devuelve el NIT del cliente, que es lo que llena el tercero del asiento', function () {
    $f = facturaPendiente($this->cliente, 840000);

    $r = $this->actingAs($this->contadora)->getJson('/app/pagos/facturas-pendientes');

    $r->assertOk()
        ->assertJsonFragment([
            'numero' => $f->numero,
            'nit' => '900345678',
            'cliente' => 'Comercial Cali SAS',
            'saldo' => 840000.0,
        ]);
});

it('no ofrece facturas ya pagadas ni anuladas', function () {
    facturaPendiente($this->cliente, 500000);

    FacturaVenta::create([
        'numero' => 'FV-PAGADA', 'contacto_id' => $this->cliente->id,
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'subtotal' => 100, 'total' => 100, 'saldo' => 0, 'estado' => EstadoFactura::Pagada,
    ]);

    $r = $this->actingAs($this->contadora)->getJson('/app/pagos/facturas-pendientes');

    expect($r->json())->toHaveCount(1);
});

it('busca por NIT, no sólo por número o nombre', function () {
    facturaPendiente($this->cliente, 300000);

    $r = $this->actingAs($this->contadora)->getJson('/app/pagos/facturas-pendientes?q=900345678');

    expect($r->json())->toHaveCount(1);
});
