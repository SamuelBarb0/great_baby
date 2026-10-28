<?php

use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Clients\DropiClientInterface;
use App\Modules\Dropi\Clients\DropiMockClient;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Reforzamos que el driver activo sea el mock aunque .env cambie
    app()->bind(
        DropiClientInterface::class,
        fn () => new DropiMockClient(storage_path('app/dropi-fixtures')),
    );
});

it('sincroniza pedidos desde el fixture', function () {
    $result = app(SincronizarPedidosDropi::class)->handle(CarbonImmutable::now()->subYear());

    expect($result['driver'])->toBe('mock')
        ->and($result['procesados'])->toBe(4)
        ->and($result['nuevos'])->toBe(4);

    expect(DropiPedido::where('guia', 'GUI-100001')->exists())->toBeTrue();
});

it('es idempotente: correr dos veces no duplica', function () {
    /*
     * La segunda corrida no trae nada nuevo NI nada actualizado: los cuatro
     * pedidos llegan idénticos, así que `guardarPedido()` los clasifica como
     * `sin_cambios`.
     *
     * La prueba esperaba `actualizados => 4` y eso describía un sistema peor:
     * contar como «actualizado» una fila que no cambió haría que el resumen
     * del sync dijera que hubo movimiento todos los días, y el día que de
     * verdad cambiara algo nadie lo notaría entre el ruido.
     */
    $action = app(SincronizarPedidosDropi::class);
    $desde = CarbonImmutable::now()->subYear();

    $primera = $action->handle($desde);
    $segunda = $action->handle($desde);

    expect($primera['nuevos'])->toBe(4)
        ->and($segunda['nuevos'])->toBe(0)
        ->and($segunda['actualizados'])->toBe(0)
        ->and($segunda['sin_cambios'])->toBe(4)
        ->and($segunda['procesados'])->toBe(4)
        ->and(DropiPedido::count())->toBe(4);
});

it('sí cuenta como actualizado lo que de verdad cambió', function () {
    /*
     * La otra mitad: si `sin_cambios` se tragara también los cambios reales,
     * el sync quedaría mudo.
     *
     * Se ensucia un dato de cabecera y no el estado: el estado no sirve para
     * esta prueba porque la máquina de estados RECHAZA ir hacia atrás —poner
     * el pedido en «entregado» y que el fixture lo traiga en «pending» no es
     * un cambio, es una transición bloqueada, y se registra como tal—.
     */
    $action = app(SincronizarPedidosDropi::class);
    $desde = CarbonImmutable::now()->subYear();

    $action->handle($desde);

    DropiPedido::where('guia', 'GUI-100001')->update(['cliente_nombre' => 'Nombre viejo que Dropi ya corrigió']);

    $segunda = $action->handle($desde);

    expect($segunda['nuevos'])->toBe(0)
        ->and($segunda['actualizados'])->toBe(1)
        ->and($segunda['sin_cambios'])->toBe(3);
});

it('marca los pedidos como pendiente_inventario cuando no hay stock', function () {
    app(SincronizarPedidosDropi::class)->handle(CarbonImmutable::now()->subYear());

    $pedido = DropiPedido::where('guia', 'GUI-100001')->first();

    expect($pedido->estado)->toBe(EstadoPedidoDropi::PendienteInventario);
});
