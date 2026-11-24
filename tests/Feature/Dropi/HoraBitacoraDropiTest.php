<?php

/**
 * En producción MySQL corre en UTC y la app en Bogotá: lo que dejaba la hora a
 * MySQL (DEFAULT CURRENT_TIMESTAMP) quedaba 5 h adelantado (24-nov-2026). La
 * bitácora de estados y los candados del alistador ahora toman la hora de PHP.
 */

use App\Models\User;
use App\Modules\Dropi\Actions\AsignarCorteACarga;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiAlistadorLock;
use App\Modules\Dropi\Models\DropiEstadoBitacora;
use App\Modules\Dropi\Models\DropiPedido;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $corte = AsignarCorteACarga::run(CarbonImmutable::now());
    $this->pedido = DropiPedido::create([
        'corte_id' => $corte->id, 'guia' => 'SIN-GUIA-777', 'dropi_orden_id' => '777',
        'cliente_nombre' => 'Ana', 'monto_esperado_proveedor' => 1000,
    ]);

    // MySQL con otro reloj, como en producción.
    DB::statement("SET time_zone = '+05:00'");
});

afterEach(function () {
    DB::statement("SET time_zone = 'SYSTEM'");
});

it('la bitácora de estados guarda la hora de la app, no la de MySQL', function () {
    $this->pedido->transicionar(EstadoPedidoDropi::Alistando, 'sistema');

    $fila = DropiEstadoBitacora::where('pedido_id', $this->pedido->id)->latest('id')->first();

    expect(abs($fila->created_at->diffInMinutes(now())))->toBeLessThan(2);
});

it('el candado del alistador nace con la hora de la app', function () {
    $lock = DropiAlistadorLock::firstOrCreate(['pedido_id' => $this->pedido->id], ['alistador_id' => User::factory()->create()->id]);

    expect(abs($lock->fresh()->locked_at->diffInMinutes(now())))->toBeLessThan(2)
        ->and(abs($lock->fresh()->heartbeat_at->diffInMinutes(now())))->toBeLessThan(2);
});
