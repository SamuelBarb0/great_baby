<?php

/**
 * Tarjeta Dropi · 8. Sanciones / Alertas: los umbrales llegan como avisos de
 * la campana a quien le toca, una sola vez por pedido y nivel.
 */

use App\Models\NotificacionErp;
use App\Modules\Dropi\Actions\EnviarAlertasDropi;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiSancion;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    $this->aracely = App\Models\User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->vendedor = App\Models\User::factory()->create();
    $this->corte = App\Modules\Dropi\Models\DropiCorte::create(['fecha' => now()->toDateString(), 'numero' => 1]);
});

function alertaPedido(string $guia, EstadoPedidoDropi $estado, array $campos = []): DropiPedido
{
    $id = DB::table('dropi_pedidos')->insertGetId(array_merge([
        'guia' => $guia, 'dropi_orden_id' => 'O-'.$guia, 'estado' => $estado->value,
        'corte_id' => test()->corte->id, 'cliente_nombre' => 'Ana', 'monto_esperado_proveedor' => 50000,
        'created_at' => now(), 'updated_at' => now(),
    ], $campos));

    return DropiPedido::findOrFail($id);
}

function avisos(string $tipo): \Illuminate\Support\Collection
{
    return NotificacionErp::where('tipo', $tipo)->get();
}

it('8.2 · a las 48 h sin despachar avisa a Aracely (no al vendedor), una sola vez', function () {
    alertaPedido('A-1', EstadoPedidoDropi::Pending, ['dropi_creado_at' => now()->subHours(50)]);
    alertaPedido('A-2', EstadoPedidoDropi::Alistando, ['dropi_creado_at' => now()->subHours(10)]);

    $r = EnviarAlertasDropi::run();

    $a = avisos('dropi_plazo48');
    expect($r['plazo48'])->toBe(1)
        ->and($a)->toHaveCount(1)
        ->and($a->first()->user_id)->toBe($this->aracely->id)
        ->and($a->first()->mensaje)->toContain('A-1')
        ->and($a->first()->mensaje)->not->toContain('A-2');

    EnviarAlertasDropi::run();
    expect(avisos('dropi_plazo48'))->toHaveCount(1);
});

it('8.2 · pasadas las 72 h avisa el nivel grave y no además el de 48', function () {
    alertaPedido('A-3', EstadoPedidoDropi::Empacado, ['dropi_creado_at' => now()->subHours(80)]);

    EnviarAlertasDropi::run();

    expect(avisos('dropi_plazo72'))->toHaveCount(1)
        ->and(avisos('dropi_plazo48'))->toHaveCount(0);
});

it('cuenta el plazo desde la creación EN DROPI, no desde que el ERP lo trajo', function () {
    // Llegó al ERP hace un minuto, pero en Dropi nació hace 60 h.
    alertaPedido('A-4', EstadoPedidoDropi::Pending, ['dropi_creado_at' => now()->subHours(60), 'created_at' => now()]);

    expect(EnviarAlertasDropi::run()['plazo48'])->toBe(1);
});

it('no avisa por la historia vieja', function () {
    alertaPedido('A-5', EstadoPedidoDropi::Pending, ['dropi_creado_at' => now()->subDays(40)]);

    expect(EnviarAlertasDropi::run()['plazo72'])->toBe(0);
});

it('8.3 · paquetes por fuera a los 15 y a los 20 días', function () {
    alertaPedido('T-1', EstadoPedidoDropi::Despachado, ['despachado_at' => now()->subDays(16)]);
    alertaPedido('T-2', EstadoPedidoDropi::Despachado, ['despachado_at' => now()->subDays(22)]);
    alertaPedido('T-3', EstadoPedidoDropi::Despachado, ['despachado_at' => now()->subDays(22), 'ignorar_alerta_transito' => true]);

    $r = EnviarAlertasDropi::run();

    expect($r['transito15'])->toBe(1)->and($r['transito20'])->toBe(1)
        ->and(avisos('dropi_transito20')->first()->mensaje)->toContain('T-2')
        ->and(avisos('dropi_transito20')->first()->mensaje)->not->toContain('T-3');
});

it('8.4 · devoluciones en camino y las que nunca llegaron', function () {
    alertaPedido('D-1', EstadoPedidoDropi::DevolucionEnCamino, ['devuelto_at' => now()->subDays(2)]);
    alertaPedido('D-2', EstadoPedidoDropi::DevolucionEnCamino, ['devuelto_at' => now()->subDays(9)]);

    $r = EnviarAlertasDropi::run();

    expect($r['devolucion'])->toBe(1)->and($r['fantasma'])->toBe(1)
        ->and(avisos('dropi_fantasma')->first()->mensaje)->toContain('D-2');
});

it('8.4 · una diferencia de precio nueva se avisa una vez', function () {
    $p = alertaPedido('P-1', EstadoPedidoDropi::Pagado);
    DropiSancion::create([
        'pedido_id' => $p->id, 'tipo' => 'diferencia_precio',
        'monto_esperado' => 50000, 'monto_recibido' => 45000, 'diferencia' => 5000, 'detectada_at' => now(),
    ]);

    expect(EnviarAlertasDropi::run()['diferencias'])->toBe(1)
        ->and(EnviarAlertasDropi::run()['diferencias'])->toBe(0)
        ->and(avisos('dropi_diferencia')->first()->mensaje)->toContain('$5.000');
});

it('la campana no se llena con los cobros y facturas automáticas de Dropi', function () {
    $cliente = App\Models\Contacto::create(['numero_documento' => '222222222222', 'nombre_completo' => 'CONSUMIDOR FINAL']);
    $f = App\Modules\Cartera\Models\FacturaVenta::withoutEvents(fn () => App\Modules\Cartera\Models\FacturaVenta::create([
        'numero' => 'FV-DROPI-1', 'contacto_id' => $cliente->id, 'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->toDateString(), 'subtotal' => 1000, 'total' => 1000, 'saldo' => 1000,
        'estado' => 'pendiente', 'origen_type' => DropiPedido::class, 'origen_id' => 1,
    ]));
    App\Modules\Cartera\Models\PagoVenta::withoutEvents(fn () => App\Modules\Cartera\Models\PagoVenta::create([
        'factura_id' => $f->id, 'contacto_id' => $cliente->id, 'fecha' => now()->toDateString(),
        'monto_recibido' => 1000, 'monto_aplicado' => 1000, 'medio_pago' => 'wallet_dropi',
    ]));

    $eventos = $this->actingAs($this->aracely)->getJson('/app/notificaciones?desde='.urlencode(now()->subMinute()->toIso8601String()))
        ->assertOk()->json('eventos');

    expect(collect($eventos)->pluck('tipo')->intersect(['pago', 'factura']))->toBeEmpty();
});
