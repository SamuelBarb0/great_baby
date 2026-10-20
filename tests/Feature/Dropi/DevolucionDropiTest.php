<?php

/**
 * Tarjeta Dropi · 5. Devoluciones.
 *
 *   5.2 · reintegro de stock: vuelve lo que llegó, a la bodega de la que salió,
 *         con su costo, incluidos los productos agregados;
 *   5.3 · nota crédito: cuelga de la factura del corte y va al envío real a SIIGO;
 *   5.4 · re-escaneo: sólo lo escaneado vuelve; el faltante queda anotado.
 */

use App\Modules\Cartera\Actions\GenerarFacturasB2BDeCorte;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\NotaCredito;
use App\Modules\Dropi\Actions\RegistrarDevolucion;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\DestinoDevolucion;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiDevolucion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Siigo\Jobs\PushNotaCreditoASiigo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Queue::fake();

    $this->user = App\Models\User::factory()->create();

    // Dos bodegas de venta: la mercancía está en la segunda, así se ve que la
    // devolución vuelve a la de salida y no a «la primera de la categoría».
    InventarioUbicacion::create(['codigo' => 'BOD-A', 'nombre' => 'A', 'activa' => true, 'categoria' => 'venta']);
    $this->bodega = InventarioUbicacion::create(['codigo' => 'BOD-B', 'nombre' => 'B', 'activa' => true, 'categoria' => 'venta']);

    $p = Producto::create(['referencia' => 'COCHE-01', 'nombre' => 'Coche', 'desglose_stock' => true]);
    $this->variante = ProductoVariante::create([
        'producto_id' => $p->id, 'color_codigo' => 'AZU', 'color_nombre' => 'Azul', 'codigo_barras' => '7700000000999',
    ]);
    VincularProductoDropi::run('DV-555', $this->variante->id);
    InventarioMovimiento::create([
        'variante_id' => $this->variante->id, 'ubicacion_id' => $this->bodega->id,
        'tipo' => 'entrada_compra', 'cantidad' => 20, 'costo_unit' => 15000,
    ]);
});

function devPedido(string $guia, int $cantidad, array $extra = []): DropiPedido
{
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: 'O-'.$guia, transportadora: 'X', tienda: 'T', vendedorNombre: 'Vendedora',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 30000 * $cantidad, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO('DV-555', $cantidad, 30000, 'Coche', 'Azul')],
        creadoAt: CarbonImmutable::now(),
    )]);
    $p = DropiPedido::where('guia', $guia)->firstOrFail();
    if ($extra) {
        DB::table('dropi_pedidos')->where('id', $p->id)->update($extra);
    }

    return $p->fresh();
}

function devDespachado(string $guia, int $cantidad, int $userId, array $extra = []): DropiPedido
{
    $p = devPedido($guia, $cantidad, $extra);
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $userId);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $userId);
    $p->transicionar(EstadoPedidoDropi::Despachado, 'manual', $userId);

    return $p->fresh();
}

it('re-escaneo parcial: vuelve sólo lo escaneado, a la bodega de salida y con su costo', function () {
    $p = devDespachado('D-1', 3, $this->user->id);
    $clave = 'v:'.$this->variante->id;

    $r = RegistrarDevolucion::run('D-1', DestinoDevolucion::Reingreso, $this->user->id, null, [$clave => 2]);

    $ingreso = InventarioMovimiento::where('referencia_tipo', 'devolucion')->sole();
    expect($ingreso->cantidad)->toBe(2)
        ->and($ingreso->ubicacion_id)->toBe($this->bodega->id)
        ->and((float) $ingreso->costo_unit)->toBe(15000.0)
        ->and($r['faltantes'])->toBe([$clave => 1]);

    $dev = DropiDevolucion::sole();
    expect($dev->reescaneada)->toBeTrue()
        ->and($dev->unidades_esperadas)->toBe(3)
        ->and($dev->unidades_recibidas)->toBe(2)
        ->and($dev->notas)->toContain('COCHE-01 faltan 1')
        ->and($p->fresh()->estado)->toBe(EstadoPedidoDropi::Devuelto);

    // El asiento reversa el costo de las 2 que volvieron, no de las 3.
    $reverso = MovimientoContable::where('descripcion', 'like', 'Reverso costo%')->sum('haber');
    expect((float) $reverso)->toBe(30000.0);
});

it('no deja registrar un reingreso sin escanear nada', function () {
    devDespachado('D-2', 1, $this->user->id);

    expect(fn () => RegistrarDevolucion::run('D-2', DestinoDevolucion::Reingreso, $this->user->id, null, []))
        ->toThrow(RuntimeException::class, 'No se escaneó ninguna unidad');
    expect(DropiDevolucion::count())->toBe(0);
});

it('«no llegó físicamente» se registra sin escanear y no mueve inventario', function () {
    devDespachado('D-3', 2, $this->user->id);

    RegistrarDevolucion::run('D-3', DestinoDevolucion::NoLlegoFisicamente, $this->user->id, 'Caja vacía al abrir', []);

    expect(InventarioMovimiento::where('referencia_tipo', 'devolucion')->exists())->toBeFalse()
        ->and(DropiDevolucion::sole()->unidades_recibidas)->toBe(0);
});

it('reingresa también los productos agregados (sin variante)', function () {
    $agregado = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'PANAL-AG', 'nombre' => 'Pañal surtido', 'desglose_stock' => false, 'stock_directo' => 0,
    ]));
    InventarioMovimiento::create([
        'producto_id' => $agregado->id, 'ubicacion_id' => $this->bodega->id,
        'tipo' => 'entrada_compra', 'cantidad' => 10, 'costo_unit' => 5000,
    ]);
    $p = devPedido('D-4', 2);
    DB::table('dropi_pedido_items')->where('pedido_id', $p->id)->update(['variante_id' => null, 'producto_id' => $agregado->id]);
    $p = $p->fresh();
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->user->id);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->user->id);
    $p->transicionar(EstadoPedidoDropi::Despachado, 'manual', $this->user->id);

    RegistrarDevolucion::run('D-4', DestinoDevolucion::Reingreso, $this->user->id, null, ['p:'.$agregado->id => 2]);

    $ingreso = InventarioMovimiento::where('referencia_tipo', 'devolucion')->sole();
    expect($ingreso->producto_id)->toBe($agregado->id)
        ->and($ingreso->variante_id)->toBeNull()
        ->and($ingreso->cantidad)->toBe(2)
        ->and((float) $ingreso->costo_unit)->toBe(5000.0);
});

it('sin re-escaneo (llamada vieja) asume el pedido completo', function () {
    devDespachado('D-5', 2, $this->user->id);

    RegistrarDevolucion::run('D-5', DestinoDevolucion::Reingreso, $this->user->id);

    expect(InventarioMovimiento::where('referencia_tipo', 'devolucion')->sole()->cantidad)->toBe(2)
        ->and(DropiDevolucion::sole()->reescaneada)->toBeFalse();
});

it('la nota crédito cuelga de la factura del corte y va al envío real a SIIGO', function () {
    $corte = DropiCorte::create(['fecha' => now()->toDateString(), 'numero' => 1]);
    $p = devDespachado('D-6', 2, $this->user->id, [
        'corte_id' => $corte->id, 'requiere_factura_b2b' => true, 'vendedor_identificacion' => '900123456',
    ]);

    app(GenerarFacturasB2BDeCorte::class)->handle($corte->id);
    $p->refresh();
    expect($p->factura_venta_id)->not->toBeNull();

    RegistrarDevolucion::run('D-6', DestinoDevolucion::Reingreso, $this->user->id, null, ['v:'.$this->variante->id => 2]);

    $nc = NotaCredito::sole();
    expect($nc->factura_id)->toBe($p->factura_venta_id)
        ->and((float) $nc->valor)->toBe(60000.0)
        ->and(DropiDevolucion::sole()->genero_nota_credito)->toBeTrue();
    Queue::assertPushed(PushNotaCreditoASiigo::class, fn ($job) => $job->notaCreditoId === $nc->id);
});

it('sin factura no hay nota crédito (y queda dicho por qué)', function () {
    devDespachado('D-7', 1, $this->user->id);

    RegistrarDevolucion::run('D-7', DestinoDevolucion::Reingreso, $this->user->id, null, ['v:'.$this->variante->id => 1]);

    expect(NotaCredito::count())->toBe(0)
        ->and(DropiDevolucion::sole()->notas)->toContain('sin factura previa');
});
