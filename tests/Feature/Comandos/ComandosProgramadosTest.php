<?php

/**
 * Dos comandos programados que fallaban cada 15 minutos en producción
 * (laravel.log del 23-nov-2026):
 *   · inventario:barrer      — «Array to string conversion» desde el 6-nov.
 *   · empaque:cerrar-huerfanos — «"abandonado" is not a valid backing value».
 */

use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\EstadoEmpaque;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Queue::fake();
});

it('inventario:barrer termina bien y dice cuántas alertas revisó', function () {
    $this->artisan('inventario:barrer')
        ->expectsOutputToContain('Alertas nuevas: 0')
        ->assertSuccessful();
});

it('empaque:cerrar-huerfanos anula el empaque abandonado y devuelve el pedido a la cola', function () {
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: 'HUERF-1', dropiOrdenId: 'O-HUERF-1', transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 30000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO('SKU-HUERF', 1, 30000, 'Juguete', null)],
        creadoAt: CarbonImmutable::now(),
    )]);
    $pedido = DropiPedido::where('guia', 'HUERF-1')->sole();
    DB::table('dropi_pedidos')->where('id', $pedido->id)->update(['estado' => EstadoPedidoDropi::Alistando->value]);
    $user = App\Models\User::factory()->create();
    $r = EmpaqueRegistro::create([
        'pedido_id' => $pedido->id, 'operario_id' => $user->id, 'inicio_at' => now()->subHours(2),
        'items_totales' => 1, 'items_escaneados' => 0, 'estado' => 'en_curso',
    ]);

    $this->artisan('empaque:cerrar-huerfanos')->assertSuccessful();

    expect($r->fresh()->estado)->toBe(EstadoEmpaque::Anulado)
        ->and($r->fresh()->notas)->toContain('inactividad')
        ->and($pedido->fresh()->estado?->value)->toBe('pending');
});

/** Un pedido en «Alistando» con su único ítem escaneado completo. */
function cpPedidoEscaneado(string $guia, int $cantidad, int $escaneadas): DropiPedido
{
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: "O-{$guia}", transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 30000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO("SKU-{$guia}", $cantidad, 30000, 'Juguete', null)],
        creadoAt: CarbonImmutable::now(),
    )]);
    $pedido = DropiPedido::where('guia', $guia)->sole();
    DB::table('dropi_pedidos')->where('id', $pedido->id)->update(['estado' => EstadoPedidoDropi::Alistando->value]);
    $pedido->items()->update(['cantidad_pickeada' => $escaneadas] + ($escaneadas >= $cantidad ? ['pickeado_at' => now(), 'pickeado_por' => App\Models\User::factory()->create()->id] : []));

    return $pedido->fresh();
}

it('un empaque abandonado con el producto ya escaneado vuelve a la cola sin el escaneo (prueba del 24-nov-2026)', function () {
    $pedido = cpPedidoEscaneado('HUERF-2', 1, 1);
    EmpaqueRegistro::create([
        'pedido_id' => $pedido->id, 'operario_id' => App\Models\User::factory()->create()->id, 'inicio_at' => now()->subHours(2),
        'items_totales' => 1, 'items_escaneados' => 1, 'estado' => 'en_curso',
    ]);

    $this->artisan('empaque:cerrar-huerfanos')->assertSuccessful();

    // Antes quedaba en «Alistando» con el ítem en ✔: el siguiente lo confirmaba sin escanear.
    $item = $pedido->items()->first();
    expect($pedido->fresh()->estado)->toBe(EstadoPedidoDropi::Pending)
        ->and($item->pickeado_at)->toBeNull()
        ->and($item->cantidad_pickeada)->toBe(0);
});

it('al vencer el bloqueo del alistador el pedido vuelve sin lo escaneado, también un parcial (1 de 2)', function () {
    $pedido = cpPedidoEscaneado('LOCK-1', 2, 1);
    $user = App\Models\User::factory()->create();
    App\Modules\Dropi\Models\DropiAlistadorLock::create(['pedido_id' => $pedido->id, 'alistador_id' => $user->id,
        'locked_at' => now()->subHour(), 'heartbeat_at' => now()->subHour()]);

    $this->artisan('dropi:liberar-locks')->assertSuccessful();

    expect($pedido->fresh()->estado)->toBe(EstadoPedidoDropi::Pending)
        ->and($pedido->items()->first()->cantidad_pickeada)->toBe(0)
        ->and(App\Modules\Dropi\Models\DropiAlistadorLock::count())->toBe(0);
});
