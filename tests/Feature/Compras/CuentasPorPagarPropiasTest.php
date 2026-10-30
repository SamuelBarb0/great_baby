<?php

/**
 * Las cuentas por pagar, calculadas con datos del ERP.
 *
 * Ya había una pantalla de cuentas por pagar, pero lee de la API de SIIGO:
 * con SIIGO en sandbox —como está producción— no muestra nada. Mientras
 * tanto, la lista con la que deciden a quién pagar es una hoja impresa que se
 * marca con resaltador y se firma a lápiz al margen. Esta es esa hoja.
 *
 * Tres reglas que no son obvias:
 *
 *  · El saldo es la recepción menos sus pagos, y un pago anulado no cuenta —
 *    si contara, una factura pagada por error quedaría saldada para siempre.
 *  · Sin condición de crédito pactada, vence el mismo día. Suponer un plazo
 *    que nadie acordó haría ver con tiempo una deuda ya vencida.
 *  · El resumen de arriba se calcula sobre TODO, no sobre lo filtrado: si al
 *    filtrar «vencidas» el total bajara, dejaría de ser la deuda.
 */

use App\Auth\Permisos;
use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\CondicionCredito;
use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->seed(Database\Seeders\PermisosSeeder::class);

    $contador = Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']);
    $contador->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array('Contador', $roles, true))
        ->keys()->map(fn ($s) => Permisos::permiso($s))->all());

    $this->silvia = User::factory()->create(['name' => 'Silvia Contadora']);
    $this->silvia->assignRole($contador);

    $this->proveedor = Contacto::create([
        'numero_documento' => '901000111',
        'nombre_completo' => 'Textiles Andinos',
        'es_proveedor' => true, 'ciudad' => 'Bogotá',
    ]);

    // La recepción aterriza en algún lado: `bodega_id` no admite nulo.
    $this->bodega = \App\Modules\Dropi\Models\InventarioUbicacion::create([
        'codigo' => 'R-A-01', 'nombre' => 'Rack A · Nivel 01',
        'categoria' => 'venta', 'activa' => true,
    ]);
});

/** Nombre propio: en Pest las funciones del archivo son globales. */
function unaFacturaDeProveedor(object $caso, array $extra = []): RecepcionCompra
{
    $condicion = ! empty($extra['plazo_dias'])
        ? CondicionCredito::create([
            'contacto_id' => $caso->proveedor->id,
            'cupo' => 50_000_000,
            'plazo_dias' => $extra['plazo_dias'],
            'activa' => true,
            'vigente_desde' => now()->subYear()->toDateString(),
        ])
        : null;

    $oc = OrdenCompra::create([
        'numero' => 'OC-'.fake()->unique()->numerify('####'),
        'proveedor_id' => $caso->proveedor->id,
        'condicion_credito_id' => $condicion?->id,
        'tipo' => 'nacional', 'moneda' => 'COP',
        'fecha_emision' => now()->subDays(40)->toDateString(),
        'subtotal' => $extra['valor'] ?? 1_000_000,
        'total' => $extra['valor'] ?? 1_000_000,
        'estado' => 'aprobada',
    ]);

    return RecepcionCompra::create([
        'numero' => 'RC-'.fake()->unique()->numerify('####'),
        'orden_id' => $oc->id,
        'bodega_id' => $caso->bodega->id,
        'fecha_recepcion' => $extra['fecha'] ?? now()->subDays(30)->toDateString(),
        // `array_key_exists` y no `??`: una recepción SIN factura es un caso a
        // propósito, y el modelo no deja quitársela después de confirmada.
        'factura_proveedor' => array_key_exists('factura', $extra)
            ? $extra['factura']
            : 'FP-'.fake()->unique()->numerify('####'),
        'estado' => $extra['estado'] ?? 'confirmada',
        'total_recibido' => $extra['valor'] ?? 1_000_000,
        'confirmada_at' => now()->subDays(30),
    ]);
}

function verCuentasPorPagar(object $caso, array $query = []): array
{
    return $caso->actingAs($caso->silvia)
        ->get('/app/compras/cuentas-por-pagar?'.http_build_query($query))
        ->assertOk()->viewData('page')['props'];
}

it('una recepción confirmada con factura aparece como deuda', function () {
    unaFacturaDeProveedor($this, ['valor' => 2_500_000]);

    $p = verCuentasPorPagar($this);

    expect($p['filas'])->toHaveCount(1)
        ->and($p['filas'][0]['saldo'])->toBe(2_500_000.0)
        ->and($p['filas'][0]['proveedor'])->toBe('Textiles Andinos')
        ->and($p['resumen']['total'])->toBe(2_500_000.0);
});

it('una recepción en borrador todavía no se debe', function () {
    unaFacturaDeProveedor($this, ['estado' => 'borrador']);

    // Mercancía que no se confirmó no generó obligación.
    expect(verCuentasPorPagar($this)['filas'])->toHaveCount(0);
});

it('sin número de factura no entra: no hay a qué pagarle', function () {
    unaFacturaDeProveedor($this, ['factura' => null]);

    expect(verCuentasPorPagar($this)['filas'])->toHaveCount(0);
});

it('los abonos bajan el saldo', function () {
    $r = unaFacturaDeProveedor($this, ['valor' => 3_000_000]);

    PagoProveedor::create([
        'fecha' => now()->toDateString(), 'contacto_id' => $this->proveedor->id,
        'recepcion_id' => $r->id, 'user_id' => $this->silvia->id, 'monto_bruto' => 1_000_000, 'iva' => 0,
        'monto_retenciones' => 0, 'monto_neto' => 1_000_000,
        'metodo' => 'transferencia', 'estado' => 'confirmado',
    ]);

    $f = verCuentasPorPagar($this)['filas'][0];

    expect($f['abonado'])->toBe(1_000_000.0)
        ->and($f['saldo'])->toBe(2_000_000.0);
});

it('un pago anulado no cuenta como abono', function () {
    $r = unaFacturaDeProveedor($this, ['valor' => 3_000_000]);

    PagoProveedor::create([
        'fecha' => now()->toDateString(), 'contacto_id' => $this->proveedor->id,
        'recepcion_id' => $r->id, 'user_id' => $this->silvia->id, 'monto_bruto' => 3_000_000, 'iva' => 0,
        'monto_retenciones' => 0, 'monto_neto' => 3_000_000,
        'metodo' => 'transferencia', 'estado' => 'anulado',
        'anulado_at' => now(), 'motivo_anulacion' => 'Se pagó al proveedor equivocado.',
    ]);

    // Si el anulado contara, la factura quedaría saldada para siempre y nadie
    // volvería a verla en la lista.
    expect(verCuentasPorPagar($this)['filas'][0]['saldo'])->toBe(3_000_000.0);
});

it('saldada por completo, desaparece de la lista', function () {
    $r = unaFacturaDeProveedor($this, ['valor' => 1_000_000]);

    PagoProveedor::create([
        'fecha' => now()->toDateString(), 'contacto_id' => $this->proveedor->id,
        'recepcion_id' => $r->id, 'user_id' => $this->silvia->id, 'monto_bruto' => 1_000_000, 'iva' => 0,
        'monto_retenciones' => 0, 'monto_neto' => 1_000_000,
        'metodo' => 'transferencia', 'estado' => 'confirmado',
    ]);

    expect(verCuentasPorPagar($this)['filas'])->toHaveCount(0);
});

it('el vencimiento sale del plazo pactado', function () {
    unaFacturaDeProveedor($this, [
        'fecha' => now()->subDays(10)->toDateString(),
        'plazo_dias' => 30,
    ]);

    $f = verCuentasPorPagar($this)['filas'][0];

    // Recibida hace 10 días a 30 días: faltan 20.
    expect($f['vencimiento'])->toBe(now()->addDays(20)->toDateString())
        ->and($f['dias_mora'])->toBe(-20);
});

it('sin condición de crédito, vence el mismo día', function () {
    unaFacturaDeProveedor($this, ['fecha' => now()->subDays(15)->toDateString()]);

    // Suponer un plazo que nadie pactó mostraría con tiempo una deuda que ya
    // lleva quince días vencida.
    expect(verCuentasPorPagar($this)['filas'][0]['dias_mora'])->toBe(15);
});

it('el resumen separa lo vencido de lo que está por caer', function () {
    unaFacturaDeProveedor($this, ['valor' => 1_000_000, 'fecha' => now()->subDays(20)->toDateString()]);
    unaFacturaDeProveedor($this, ['valor' => 4_000_000, 'fecha' => now()->toDateString(), 'plazo_dias' => 45]);

    $r = verCuentasPorPagar($this)['resumen'];

    expect($r['total'])->toBe(5_000_000.0)
        ->and($r['vencido'])->toBe(1_000_000.0)
        ->and($r['por_vencer'])->toBe(4_000_000.0);
});

it('el total de arriba no cambia al filtrar', function () {
    unaFacturaDeProveedor($this, ['valor' => 1_000_000, 'fecha' => now()->subDays(20)->toDateString()]);
    unaFacturaDeProveedor($this, ['valor' => 4_000_000, 'fecha' => now()->toDateString(), 'plazo_dias' => 45]);

    $p = verCuentasPorPagar($this, ['estado' => 'vencidas']);

    // La lista se achica, la deuda no: si el número de arriba bajara al
    // filtrar, dejaría de ser la deuda sin avisarle a nadie.
    expect($p['filas'])->toHaveCount(1)
        ->and($p['resumen']['total'])->toBe(5_000_000.0);
});

it('un vendedor no ve lo que se le debe a los proveedores', function () {
    $v = User::factory()->create();
    $v->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    $this->actingAs($v)->get('/app/compras/cuentas-por-pagar')->assertForbidden();
});
