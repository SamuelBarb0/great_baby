<?php

/**
 * Lo que el ERP tiene sin despachar se revisa contra Dropi pedido por pedido
 * (26-nov-2026). Un pedido que el dropshipper edita queda «Orden no
 * encontrada» en Dropi (lo reemplaza por otro) y ya no sale en la lista que lee
 * el sync: el ERP lo dejaba pendiente para siempre y se colaba en el alistador,
 * los cortes y las guías.
 */

use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use Illuminate\Support\Facades\DB;

function rpdPedido(string $orden, array $cambios = []): DropiPedido
{
    $estado = $cambios['estado'] ?? EstadoPedidoDropi::Pending->value;
    unset($cambios['estado']);
    $p = DropiPedido::create($cambios + [
        'guia' => "SIN-GUIA-{$orden}", 'dropi_orden_id' => $orden,
        'cliente_nombre' => 'Ana Pérez', 'cliente_telefono' => '3001112233',
        'monto_esperado_proveedor' => 1000, 'dropi_creado_at' => now()->subDays(2),
    ]);
    // `estado` no se asigna en masa: va aparte.
    DropiPedido::whereKey($p->id)->update(['estado' => $estado]);
    $p->refresh();
    $p->items()->create(['sku_dropi' => 'TP', 'producto_nombre' => 'Producto TP', 'cantidad' => 1, 'precio_proveedor_unit' => 1000]);

    return $p;
}

function rpdDetalle(string $orden, string $status, ?string $creado = null, string $telefono = '3001112233'): array
{
    return [
        'id' => (int) $orden, 'status' => $status, 'shipping_guide' => null, 'shipping_company' => null,
        'name' => 'Ana', 'surname' => 'Pérez', 'phone' => $telefono, 'created_at' => $creado ?? now()->subDays(2)->toIso8601String(),
        'orderdetails' => [['quantity' => 1, 'supplier_price' => 1000, 'product' => ['sku' => 'TP', 'name' => 'Producto TP']]],
    ];
}

/** Dropi responde así por orden; una Throwable = Dropi respondió con ese error. Anota a quién se le preguntó. */
function rpdDropiDice(array $porOrden): ArrayObject
{
    $preguntados = new ArrayObject;
    test()->partialMock(DropiWebClient::class, fn ($m) => $m->shouldReceive('pedidoDetalle')->andReturnUsing(function (int $id) use ($porOrden, $preguntados) {
        $preguntados[] = (string) $id;
        $r = $porOrden[(string) $id];
        if ($r instanceof Throwable) {
            throw $r;
        }

        return $r;
    }));

    return $preguntados;
}

it('el pedido que Dropi reemplazó sale del ERP como cancelado por Dropi, y dice cuál es el nuevo', function () {
    $viejo = rpdPedido('800');
    rpdPedido('801', ['guia' => '064109446023', 'estado' => EstadoPedidoDropi::Despachado->value, 'dropi_creado_at' => now()->subDays(2)->addHour()]);
    rpdDropiDice(['800' => new RuntimeException('Dropi no dejó leer el pedido 800: Orden no encontrada')]);

    $this->artisan('dropi:revisar-pendientes')
        ->expectsOutputToContain('#' . $viejo->id . ' orden 800 (Ana Pérez): Dropi ya no tiene la orden. · la nueva es la orden 801 (Despachado) → ERP: Cancelado por Dropi')
        ->expectsOutputToContain('revisados 1 · siguen pendientes 0 · ya no existen 1')
        ->assertSuccessful();

    expect($viejo->fresh()->estado)->toBe(EstadoPedidoDropi::CanceladoDropi);
});

it('cancelado o rechazado en Dropi: el ERP lo trae así; lo que sigue pendiente no se toca', function () {
    $rechazado = rpdPedido('810');
    $vivo = rpdPedido('811', ['estado' => EstadoPedidoDropi::PendienteInventario->value]);
    rpdDropiDice(['810' => rpdDetalle('810', 'RECHAZADO'), '811' => rpdDetalle('811', 'PENDIENTE')]);

    $this->artisan('dropi:revisar-pendientes')->expectsOutputToContain('siguen pendientes 1')->assertSuccessful();

    expect($rechazado->fresh()->estado)->toBe(EstadoPedidoDropi::CanceladoDropi)
        ->and($vivo->fresh()->estado)->toBe(EstadoPedidoDropi::PendienteInventario)
        ->and(DB::table('dropi_web_huellas')->where('dropi_orden_id', 811)->value('estatus_dropi'))->toBe('PENDIENTE');
});

it('si Dropi no responde no cancela nada; lo despachado ni se pregunta', function () {
    $p = rpdPedido('820', ['estado' => EstadoPedidoDropi::Alistando->value]);
    rpdPedido('821', ['estado' => EstadoPedidoDropi::Despachado->value, 'guia' => '240055550001']);
    $preguntados = rpdDropiDice(['820' => new RuntimeException('cURL error 28: Operation timed out')]);

    $this->artisan('dropi:revisar-pendientes')->expectsOutputToContain('sin respuesta 1')->assertSuccessful();

    expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Alistando)
        ->and($preguntados->getArrayCopy())->toBe(['820']);
});

it('cada vuelta empieza por lo que lleva más tiempo sin mirarse', function () {
    $a = rpdPedido('830');
    $b = rpdPedido('831');
    $c = rpdPedido('832');
    // 830 se miró hace poco, 831 hace mucho, 832 nunca.
    DB::table('dropi_web_huellas')->insert([
        ['dropi_orden_id' => 830, 'huella' => 'x', 'estatus_dropi' => 'PENDIENTE', 'sincronizado_at' => now()->subMinutes(5)],
        ['dropi_orden_id' => 831, 'huella' => 'x', 'estatus_dropi' => 'PENDIENTE', 'sincronizado_at' => now()->subDays(3)],
    ]);
    $preguntados = rpdDropiDice(['830' => rpdDetalle('830', 'PENDIENTE'), '831' => rpdDetalle('831', 'PENDIENTE'), '832' => rpdDetalle('832', 'PENDIENTE')]);

    $this->artisan('dropi:revisar-pendientes', ['--max' => 2])->assertSuccessful();
    expect($preguntados->getArrayCopy())->toBe(['832', '831']);

    // La siguiente vuelta sigue con el que faltaba.
    $this->artisan('dropi:revisar-pendientes', ['--max' => 1])->assertSuccessful();
    expect($preguntados->getArrayCopy())->toBe(['832', '831', '830']);
});

it('--simular cuenta lo que pasaría sin cambiar nada', function () {
    $p = rpdPedido('840');
    rpdDropiDice(['840' => new RuntimeException('Dropi no dejó leer el pedido 840: Orden no encontrada')]);

    $this->artisan('dropi:revisar-pendientes', ['--simular' => true])
        ->expectsOutputToContain('#' . $p->id . ' orden 840')
        ->expectsOutputToContain('[simulación] revisados 1 · siguen pendientes 0 · ya no existen 1')
        ->assertSuccessful();

    expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Pending)
        ->and(DB::table('dropi_web_huellas')->where('dropi_orden_id', 840)->exists())->toBeFalse();
});

it('corre cada 10 minutos con el sync web de Dropi', function () {
    $evento = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'dropi:revisar-pendientes'));

    expect($evento)->not->toBeNull()
        ->and($evento->expression)->toBe('*/10 * * * *');
});

describe('al llegar un pedido nuevo se revisan los del mismo cliente (edición en Dropi = orden nueva)', function () {
    /** El sync trae la orden nueva, como lo hace cada 15 s. */
    function rpdLlegaNueva(string $orden, string $telefono = '3001112233'): void
    {
        app(\App\Modules\Dropi\Actions\SincronizarPedidosDropi::class)->importarPedidos([
            app(\App\Modules\Dropi\Services\MapearPedidoDropiWeb::class)->desdeDetalle(rpdDetalle($orden, 'PENDIENTE', now()->toIso8601String(), $telefono)),
        ]);
    }

    it('la vieja que Dropi ya no tiene se cancela ahí mismo; la que sigue viva no se toca', function () {
        config(['dropi.web.sync_activo' => true]);
        $editada = rpdPedido('850', ['dropi_creado_at' => now()->subHours(3)]);
        $otraViva = rpdPedido('852', ['dropi_creado_at' => now()->subHours(5)]);
        $otroCliente = rpdPedido('853', ['cliente_telefono' => '3109998877', 'dropi_creado_at' => now()->subHours(1)]);
        $preguntados = rpdDropiDice([
            '850' => new RuntimeException('Dropi no dejó leer el pedido 850: Orden no encontrada'),
            '852' => rpdDetalle('852', 'PENDIENTE'),
        ]);

        rpdLlegaNueva('851');

        expect($editada->fresh()->estado)->toBe(EstadoPedidoDropi::CanceladoDropi)
            ->and($otraViva->fresh()->estado)->toBe(EstadoPedidoDropi::Pending)
            ->and($otroCliente->fresh()->estado)->toBe(EstadoPedidoDropi::Pending)
            ->and(collect($preguntados->getArrayCopy())->sort()->values()->all())->toBe(['850', '852']);
    });

    it('va en la cola de las guías, y sin el sync web prendido no pregunta nada', function () {
        rpdPedido('860', ['dropi_creado_at' => now()->subHours(3)]);
        \Illuminate\Support\Facades\Queue::fake();

        config(['dropi.web.sync_activo' => false]);
        rpdLlegaNueva('861');
        \Illuminate\Support\Facades\Queue::assertNothingPushed();

        config(['dropi.web.sync_activo' => true]);
        rpdLlegaNueva('862');
        \Illuminate\Support\Facades\Queue::assertPushedOn('guias', \App\Modules\Dropi\Jobs\RevisarReemplazadosJob::class);
    });

    it('sólo mira hasta dos días atrás y lo que no ha salido', function () {
        $nueva = rpdPedido('870', ['dropi_creado_at' => now()]);
        rpdPedido('871', ['dropi_creado_at' => now()->subDays(3)]);
        rpdPedido('872', ['estado' => EstadoPedidoDropi::Despachado->value, 'dropi_creado_at' => now()->subHour()]);
        $si = rpdPedido('873', ['estado' => EstadoPedidoDropi::Alistando->value, 'dropi_creado_at' => now()->subDay()]);

        expect(\App\Modules\Dropi\Jobs\RevisarReemplazadosJob::candidatos($nueva)->pluck('id')->all())->toBe([$si->id]);
    });
});
