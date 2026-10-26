<?php

use App\Modules\Dropi\Actions\AsignarCorteACarga;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiSancion;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use App\Modules\Dropi\Models\DropiWebSesion;
use App\Modules\Dropi\Services\DropiWebAuth;
use App\Modules\Dropi\Services\MapearMovimientoWalletDropi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL: DB_CONNECTION=mysql DB_DATABASE=great_baby_test');
    }
    config()->set('dropi.web', [
        'email' => 'c@e.co', 'password' => 'x', 'white_brand_id' => 1, 'timeout' => 5,
        'bff_url' => 'https://bff.test', 'legacy_url' => 'https://legacy.test', 'wallet_desde' => '2026-09-01',
    ]);
    app()->forgetInstance(DropiWebAuth::class);
    app()->singleton(DropiWebAuth::class, fn ($app) => new DropiWebAuth($app['config']->get('dropi.web')));
    DropiWebSesion::create(['email' => 'c@e.co', 'estado' => DropiWebSesion::CONECTADO, 'token' => 'tok', 'expira_at' => now()->addHour()]);

    $this->corte = AsignarCorteACarga::run(CarbonImmutable::now());
});

function pedidoWallet(string $orden, float $esperado): DropiPedido
{
    return DropiPedido::create([
        'corte_id' => test()->corte->id, 'guia' => 'G' . $orden, 'dropi_orden_id' => $orden,
        'cliente_nombre' => 'Cliente ' . $orden, 'monto_esperado_proveedor' => $esperado,
        'estado' => EstadoPedidoDropi::Despachado,
    ]);
}

/** Movimiento tal como lo devuelve GET /historywallet. */
function movWallet(int $id, string $codigo, string $tipo, float $monto, ?int $orden, string $fecha = '2026-09-10T15:00:00.000000Z'): array
{
    $texto = [
        '1006' => "ENTRADA POR GANANCIA EN LA ORDEN COMO PROVEEDOR: {$orden}* GUIA: *G{$orden}*",
        '1009' => "SALIDA POR CORRECCION DE ESTADO DE GUIA COMO PROVEEDOR: {$orden}",
        '1020' => 'SALIDA POR PETICION DE RETIRO DE SALDO EN CARTERA',
    ][$codigo];

    return [
        'id' => $id, 'wallet_id' => 1, 'amount' => number_format($monto, 2, '.', ''), 'type' => $tipo,
        'created_at' => $fecha, 'description' => $texto, 'order_id' => $orden, 'identification_code' => $codigo,
    ];
}

/** La wallet de Dropi simulada: del más nuevo al más viejo, como la real. */
function walletDropi(array $movimientos): void
{
    usort($movimientos, fn ($a, $b) => $b['id'] <=> $a['id']);
    Http::fake(['legacy.test/historywallet*' => Http::response([
        'isSuccess' => true, 'count' => count($movimientos), 'objects' => $movimientos,
    ])]);
}

it('mapea un movimiento real de Dropi', function () {
    $pago = MapearMovimientoWalletDropi::map(movWallet(265724011, '1006', 'ENTRADA', 17900, 90794435));
    expect($pago->tipo)->toBe('pago_guia')
        ->and($pago->monto)->toBe(17900.0)
        ->and($pago->ordenId)->toBe('90794435')
        ->and($pago->guia)->toBe('G90794435')
        ->and($pago->dropiMovimientoId)->toBe('web:265724011');

    $retiro = MapearMovimientoWalletDropi::map(movWallet(1, '1020', 'SALIDA', 500000, null));
    expect($retiro->tipo)->toBe('retiro_banco')->and($retiro->monto)->toBe(-500000.0)->and($retiro->ordenId)->toBeNull();
});

it('importa desde la fecha de corte, enlaza por orden y compara la SUMA de las líneas', function () {
    $partido = pedidoWallet('100', 50000);
    $subpago = pedidoWallet('200', 40000);
    walletDropi([
        movWallet(1, '1006', 'ENTRADA', 30000, 100),
        movWallet(2, '1006', 'ENTRADA', 20000, 100), // mismo pedido, segunda línea
        movWallet(3, '1006', 'ENTRADA', 35000, 200),
        movWallet(4, '1006', 'ENTRADA', 25000, 400), // su pedido todavía no está en el ERP
        movWallet(5, '1020', 'SALIDA', 1000000, null),
        movWallet(0, '1006', 'ENTRADA', 9999, 100, '2026-08-20T15:00:00.000000Z'), // antes del corte
    ]);

    $this->artisan('dropi:web-wallet-sync', ['--desde' => '2026-09-01'])->assertSuccessful();

    expect(DropiWalletMovimiento::count())->toBe(5)
        ->and(DropiWalletMovimiento::where('dropi_movimiento_id', 'web:0')->exists())->toBeFalse();

    // Pago partido en dos líneas que suman lo esperado: Pagado y SIN sanción.
    expect($partido->fresh()->estado)->toBe(EstadoPedidoDropi::Pagado)
        ->and(DropiSancion::where('pedido_id', $partido->id)->exists())->toBeFalse();

    // Subpago real: 35 000 de 40 000.
    $sancion = DropiSancion::where('pedido_id', $subpago->id)->sole();
    expect($subpago->fresh()->estado)->toBe(EstadoPedidoDropi::Pagado)
        ->and($sancion->tipo->value)->toBe('diferencia_precio')
        ->and((float) $sancion->diferencia)->toBe(5000.0);

    expect((float) DropiWalletMovimiento::where('tipo', 'retiro_banco')->value('monto'))->toBe(-1000000.0)
        ->and(DropiWalletMovimiento::where('dropi_movimiento_id', 'web:4')->value('pedido_id'))->toBeNull();

    // Correr de nuevo no duplica.
    $this->artisan('dropi:web-wallet-sync', ['--desde' => '2026-09-01'])->assertSuccessful();
    expect(DropiWalletMovimiento::count())->toBe(5);
});

it('enlaza el pago que llegó antes que su pedido', function () {
    walletDropi([movWallet(4, '1006', 'ENTRADA', 25000, 400)]);
    $this->artisan('dropi:web-wallet-sync', ['--desde' => '2026-09-01'])->assertSuccessful();

    $pedido = pedidoWallet('400', 25000);
    $this->artisan('dropi:reconciliar-huerfanos')->assertSuccessful();

    expect(DropiWalletMovimiento::where('dropi_movimiento_id', 'web:4')->value('pedido_id'))->toBe($pedido->id)
        ->and($pedido->fresh()->estado)->toBe(EstadoPedidoDropi::Pagado)
        ->and(DropiSancion::where('pedido_id', $pedido->id)->exists())->toBeFalse();
});

it('un reverso de Dropi resta del pago y deja la diferencia a la vista', function () {
    $pedido = pedidoWallet('100', 50000);
    walletDropi([movWallet(1, '1006', 'ENTRADA', 50000, 100)]);
    $this->artisan('dropi:web-wallet-sync', ['--desde' => '2026-09-01'])->assertSuccessful();
    expect(DropiSancion::where('pedido_id', $pedido->id)->exists())->toBeFalse();

    Http::swap(new \Illuminate\Http\Client\Factory);
    walletDropi([movWallet(1, '1006', 'ENTRADA', 50000, 100), movWallet(2, '1009', 'SALIDA', 20000, 100)]);
    $this->artisan('dropi:web-wallet-sync', ['--desde' => '2026-09-01'])->assertSuccessful();

    $sancion = DropiSancion::where('pedido_id', $pedido->id)->sole();
    expect($sancion->tipo->value)->toBe('diferencia_precio')
        ->and((float) $sancion->monto_recibido)->toBe(30000.0)
        ->and($pedido->fresh()->estado)->toBe(EstadoPedidoDropi::Pagado);
});

it('un sobrepago de Dropi queda como sanción legible', function () {
    $pedido = pedidoWallet('100', 50000);
    walletDropi([movWallet(1, '1006', 'ENTRADA', 60000, 100)]);
    $this->artisan('dropi:web-wallet-sync', ['--desde' => '2026-09-01'])->assertSuccessful();

    $sancion = DropiSancion::where('pedido_id', $pedido->id)->sole();
    expect($sancion->tipo->value)->toBe('sobrepago')
        ->and($sancion->tipo->label())->toBe('Sobrepago de Dropi')
        ->and((float) $sancion->diferencia)->toBe(-10000.0);
});

it('el asiento de un movimiento de Dropi no toma el usuario de Dropi', function () {
    // El payload real trae user_id = un usuario DE DROPI (992001, un empleado suyo).
    $m = movWallet(9, '1020', 'SALIDA', 1000, null);
    $m['user_id'] = 992001;
    walletDropi([$m]);

    $this->artisan('dropi:web-wallet-sync', ['--desde' => '2026-09-01'])->assertSuccessful();

    $mov = DropiWalletMovimiento::where('dropi_movimiento_id', 'web:9')->sole();
    expect(\App\Modules\Dropi\Listeners\RegistrarAsientoWalletDropi::usuarioDelAsiento($mov))->toBeNull();

    $manual = new DropiWalletMovimiento(['fuente' => ['origen' => 'ajuste_manual', 'user_id' => 7]]);
    expect(\App\Modules\Dropi\Listeners\RegistrarAsientoWalletDropi::usuarioDelAsiento($manual))->toBe(7);
});

it('el sync de pedidos no devuelve a Entregado un pedido que la wallet ya marcó Pagado', function () {
    $pedido = pedidoWallet('100', 50000);
    walletDropi([movWallet(1, '1006', 'ENTRADA', 50000, 100)]);
    $this->artisan('dropi:web-wallet-sync', ['--desde' => '2026-09-01'])->assertSuccessful();
    expect($pedido->fresh()->estado)->toBe(EstadoPedidoDropi::Pagado);

    // Dropi sigue diciendo ENTREGADO: no tiene un estado «pagado».
    $dto = new \App\Modules\Dropi\DTOs\PedidoDropiDTO(
        guia: 'G100', dropiOrdenId: '100', transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Cliente 100', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'entregado',
        montoEsperadoProveedor: 50000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [], creadoAt: CarbonImmutable::now(),
    );
    app(\App\Modules\Dropi\Actions\SincronizarPedidosDropi::class)->importarPedidos([$dto]);

    expect($pedido->fresh()->estado)->toBe(EstadoPedidoDropi::Pagado);
});

it('el reverso de un pago anterior al corte en un pedido devuelto no es diferencia de precio', function () {
    // Dropi pagó en agosto (antes del corte) y en septiembre reversa porque se devolvió.
    $pedido = pedidoWallet('100', 13500);
    $pedido->transicionar(EstadoPedidoDropi::DevolucionEnCamino, 'sistema');
    walletDropi([movWallet(1, '1009', 'SALIDA', 13500, 100)]);

    $this->artisan('dropi:web-wallet-sync', ['--desde' => '2026-09-01'])->assertSuccessful();

    expect(DropiWalletMovimiento::where('pedido_id', $pedido->id)->count())->toBe(1)
        ->and(DropiSancion::where('pedido_id', $pedido->id)->exists())->toBeFalse();
});

it('el cruce separa atrasos, entregados en plazo y en camino', function () {
    $cruce = app(\App\Modules\Dropi\Services\CruceWalletPedidos::class);
    $base = ['despachado_at' => now()->subDays(8)];

    $atraso = pedidoWallet('1', 10000);
    $atraso->forceFill($base + ['estado' => EstadoPedidoDropi::Entregado, 'entregado_at' => now()->subDays(5)])->save();
    $enPlazo = pedidoWallet('2', 20000);
    $enPlazo->forceFill($base + ['estado' => EstadoPedidoDropi::Entregado, 'entregado_at' => now()->subDay()])->save();
    $enCamino = pedidoWallet('3', 30000);
    $enCamino->forceFill($base + ['estado' => EstadoPedidoDropi::Despachado])->save();
    $pagado = pedidoWallet('4', 40000);
    $pagado->forceFill($base + ['estado' => EstadoPedidoDropi::Entregado, 'entregado_at' => now()->subDays(5)])->save();
    walletDropi([movWallet(1, '1006', 'ENTRADA', 40000, 4, now()->subDays(4)->toIso8601String())]);
    $this->artisan('dropi:web-wallet-sync', ['--desde' => now()->subDays(30)->toDateString()])->assertSuccessful();
    config()->set('dropi.web.wallet_desde', now()->subDays(30)->toDateString());

    expect($cruce->atrasos()->pluck('dropi_orden_id')->all())->toBe(['1'])
        ->and($cruce->entregadosEnPlazo()->pluck('dropi_orden_id')->all())->toBe(['2'])
        ->and($cruce->enCamino()->pluck('dropi_orden_id')->all())->toBe(['3'])
        ->and($cruce->resumen()['pagados']['n'])->toBe(1);

    $this->actingAs(\App\Models\User::factory()->create()->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Aracely', 'web')));
    \Filament\Facades\Filament::setCurrentPanel('admin');
    \Livewire\Livewire::test(\App\Modules\Dropi\Filament\Pages\CruceWalletPedidos::class)->assertOk()->assertSee('Atrasos');
});

it('sin sesión web la wallet se salta sin fallar: los pagos siguen por la API', function () {
    // Con el driver api la sesión web solo hace falta para la wallet completa;
    //   con 2FA vence cada 4 h y esperaba el código de la app → el cron fallaba
    //   cada 30 min.
    DropiWebSesion::query()->update(['estado' => DropiWebSesion::ESPERANDO_APP, 'token' => null]);
    Http::fake();

    $this->artisan('dropi:web-wallet-sync', ['--desde' => '2026-09-01'])
        ->expectsOutputToContain('siguen entrando por la API')
        ->assertSuccessful();

    Http::assertNothingSent();
});
