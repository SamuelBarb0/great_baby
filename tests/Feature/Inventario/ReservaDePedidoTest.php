<?php

/**
 * Aprobar un pedido aparta la mercancía; rechazarlo o facturarlo la suelta.
 *
 * La maquinaria de reservas estaba completa desde hacía meses —`ReservarStock`,
 * `LiberarReserva`, la expiración programada y `StockService` calculando
 * disponible = físico − reservado por ubicación— y el 2026-11-06 resultó que
 * **el único sitio del código que la invocaba era un comando de humo**. Desde
 * el flujo de ventas no la llamaba nadie.
 *
 * O sea que dos vendedores podían prometer la misma caja y el segundo se
 * enteraba el día del alistamiento. El contrato lo pide como «reserva de
 * inventario al confirmar», y es lo que hace que el stock apartado deje de
 * ofrecerse.
 *
 * Lo que se protege acá es el ciclo completo, porque una reserva que no se
 * suelta es peor que no reservar: deja el disponible más bajo para siempre.
 */

use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Actions\ReservarPedido;
use App\Modules\Inventario\Models\ReservaInventario;
use App\Modules\Inventario\Services\StockService;
use App\Modules\Portal\Models\PedidoCliente;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->bodega = InventarioUbicacion::firstOrCreate(
        ['codigo' => 'RES-TEST'],
        ['nombre' => 'Bodega de prueba', 'categoria' => 'venta', 'activa' => true, 'disponible_para_venta' => true]
    );

    $this->producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'RES-001', 'nombre' => 'Producto reservable',
        'desglose_stock' => false,
    ]));

    // 10 unidades físicas en esa bodega.
    \App\Modules\Dropi\Models\InventarioMovimiento::create([
        'producto_id' => $this->producto->id,
        'ubicacion_id' => $this->bodega->id,
        'tipo' => 'ajuste_toma_fisica',
        'cantidad' => 10,
        'motivo' => 'Saldo inicial de la prueba',
    ]);

    $this->cliente = \App\Models\Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900777888',
        'nombre_completo' => 'Cliente reservas', 'es_cliente' => true,
    ]);

    $this->stock = app(StockService::class);
});

/** Un pedido con una línea del producto de prueba. */
function pedidoReserva(int $cantidad, string $estado = 'enviado'): PedidoCliente
{
    static $n = 0;
    $n++;

    $p = PedidoCliente::create([
        'numero' => 'PB2B-RES-'.$n,
        'contacto_id' => test()->cliente->id,
        'estado' => $estado,
        'ubicacion_origen_id' => test()->bodega->id,
        'subtotal' => 1000 * $cantidad, 'iva' => 0, 'total' => 1000 * $cantidad,
    ]);
    $p->items()->create([
        'producto_id' => test()->producto->id,
        'sku_snapshot' => 'RES-001', 'descripcion_snapshot' => 'Producto reservable',
        'cantidad' => $cantidad, 'precio_unitario' => 1000,
        'iva_porcentaje' => 0, 'subtotal' => 1000 * $cantidad, 'total' => 1000 * $cantidad,
    ]);

    return $p->fresh('items');
}

it('arranca con las 10 unidades disponibles', function () {
    expect($this->stock->saldoDisponibleSujeto($this->producto, $this->bodega->id))->toBe(10);
});

it('aprobar un pedido aparta su mercancía y baja el disponible', function () {
    $p = pedidoReserva(4);

    $r = app(ReservarPedido::class)->handle($p);

    expect($r['reservadas'])->toBe(1)
        ->and($r['faltantes'])->toBe([])
        ->and($this->stock->saldoDisponibleSujeto($this->producto, $this->bodega->id))->toBe(6)
        // El físico NO se toca: la mercancía sigue en la bodega.
        ->and($this->stock->saldoFisicoSujeto($this->producto, $this->bodega->id))->toBe(10);
});

it('lo apartado por un pedido no se le ofrece al siguiente', function () {
    app(ReservarPedido::class)->handle(pedidoReserva(7));

    // El segundo pide 7 pero ya sólo quedan 3 libres.
    $r = app(ReservarPedido::class)->handle(pedidoReserva(7));

    expect($r['faltantes'])->toHaveCount(1)
        ->and($r['faltantes'][0]['pedido'])->toBe(7)
        ->and($r['faltantes'][0]['disponible'])->toBe(3)
        ->and($this->stock->saldoDisponibleSujeto($this->producto, $this->bodega->id))->toBe(0);
});

it('cuando no alcanza, aparta lo que hay en vez de no apartar nada', function () {
    app(ReservarPedido::class)->handle(pedidoReserva(8));
    app(ReservarPedido::class)->handle(pedidoReserva(5));

    // 8 + 2 = 10. Las 2 del segundo pedido quedaron apartadas.
    expect((int) ReservaInventario::where('activa', true)->sum('cantidad'))->toBe(10);
});

it('liberar devuelve el disponible', function () {
    $p = pedidoReserva(4);
    app(ReservarPedido::class)->handle($p);

    app(ReservarPedido::class)->liberar($p);

    expect($this->stock->saldoDisponibleSujeto($this->producto, $this->bodega->id))->toBe(10)
        ->and(ReservaInventario::where('activa', true)->count())->toBe(0);
});

it('reaprobar no duplica la reserva', function () {
    $p = pedidoReserva(4);

    app(ReservarPedido::class)->handle($p);
    app(ReservarPedido::class)->handle($p);

    expect((int) ReservaInventario::where('activa', true)->sum('cantidad'))->toBe(4)
        ->and($this->stock->saldoDisponibleSujeto($this->producto, $this->bodega->id))->toBe(6);
});

it('un pedido sin bodega de origen no revienta: no aparta nada', function () {
    $p = pedidoReserva(4);
    $p->update(['ubicacion_origen_id' => null]);

    $r = app(ReservarPedido::class)->handle($p->fresh('items'));

    expect($r['reservadas'])->toBe(0)
        ->and($this->stock->saldoDisponibleSujeto($this->producto, $this->bodega->id))->toBe(10);
});

it('la reserva expira sola a los 15 días', function () {
    app(ReservarPedido::class)->handle(pedidoReserva(4));

    $r = ReservaInventario::where('activa', true)->first();

    expect($r->expira_at)->not->toBeNull()
        ->and($r->expira_at->isAfter(now()->addDays(14)))->toBeTrue()
        ->and($r->expira_at->isBefore(now()->addDays(16)))->toBeTrue();
});

it('la reserva de una variante descuenta en la disponibilidad masiva del portal', function () {
    /*
     * El kardex denormaliza `producto_id` en las filas de variante desde
     * siempre; la reserva lo dejaba en nulo. La disponibilidad masiva suma las
     * dos tablas con el MISMO filtro por `producto_id`, asi que encontraba el
     * fisico y no encontraba lo reservado: el portal ofrecia mercancia ya
     * apartada, y recien al aprobar aparecia que no alcanzaba.
     */
    $granular = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'RES-GRAN', 'nombre' => 'Body reservable',
        'desglose_stock' => true, 'activo' => true,
    ]));

    $variante = \App\Modules\Dropi\Models\ProductoVariante::create([
        'producto_id' => $granular->id, 'color_nombre' => 'Azul',
        'talla' => '6M', 'combo_key' => 'res-gran-azul-6m',
    ]);

    \App\Modules\Dropi\Models\InventarioMovimiento::create([
        'producto_id' => $granular->id,
        'variante_id' => $variante->id,
        'ubicacion_id' => $this->bodega->id,
        'tipo' => 'ajuste_toma_fisica',
        'cantidad' => 10,
        'motivo' => 'Saldo inicial de la prueba',
    ]);

    expect($this->stock->disponibleParaVentaDeProductos([$granular->id]))
        ->toBe([$granular->id => 10]);

    $pedido = pedidoReserva(4);
    app(\App\Modules\Inventario\Actions\ReservarStock::class)
        ->handleSujeto($variante, $this->bodega->id, 4, $pedido);

    expect($this->stock->disponibleParaVentaDeProductos([$granular->id]))
        ->toBe([$granular->id => 6]);

    // Y la fila guarda el producto, que es lo que hace posible esa suma.
    expect(ReservaInventario::latest('id')->first()->producto_id)->toBe($granular->id);
});
