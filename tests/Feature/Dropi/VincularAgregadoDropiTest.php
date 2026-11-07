<?php

/**
 * Un SKU de Dropi vinculado a un producto AGREGADO (sin variantes).
 *
 * Casi todo el catálogo está en ese modo y antes no se podía vincular: el
 * vínculo exigía variante. Estas pruebas cuidan que el pedido tome el producto,
 * que el stock se mire y se descuente del total del producto, y que un
 * producto con desglose NO se vincule entero.
 */

use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiPedidoItem;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\SalidaInventarioDropi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Illuminate\Support\Facades\Queue::fake();

    $this->venta = InventarioUbicacion::create([
        'codigo' => 'AGR-VENTA', 'nombre' => 'Bodega venta', 'activa' => true, 'categoria' => 'venta',
    ]);
    $this->agregado = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => '2801-16', 'nombre' => 'Bloques suaves sensorial', 'desglose_stock' => false,
    ]));
    $this->operario = App\Models\User::factory()->create();
});

function agrStock(Producto $p, InventarioUbicacion $u, int $cantidad): void
{
    InventarioMovimiento::create([
        'producto_id' => $p->id, 'variante_id' => null, 'ubicacion_id' => $u->id,
        'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => 10000,
    ]);
}

function agrPedido(string $guia, string $sku, int $cantidad = 1): PedidoDropiDTO
{
    return new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: 'O-' . $guia, transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 30000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO($sku, $cantidad, 30000, 'BLOQUES DE CONSTRUCCION SUAVES', '40 PZS')],
        creadoAt: CarbonImmutable::now(),
    );
}

it('vincula una variación de Dropi a un producto agregado y libera el pedido que esperaba', function () {
    agrStock($this->agregado, $this->venta, 10);
    app(SincronizarPedidosDropi::class)->importarPedidos([agrPedido('GA-1', 'DV-2508490', 2)]);
    expect(DropiPedido::where('guia', 'GA-1')->value('estado'))->toBe(EstadoPedidoDropi::PendienteInventario);

    $r = VincularProductoDropi::make()->aProducto('DV-2508490', $this->agregado->id);

    expect($r)->toBe(['items' => 1, 'pedidos_liberados' => 1]);
    $item = DropiPedidoItem::sole();
    expect($item->variante_id)->toBeNull()
        ->and($item->producto_id)->toBe($this->agregado->id)
        ->and($item->esAgregado())->toBeTrue()
        ->and(DropiPedido::where('guia', 'GA-1')->value('estado'))->toBe(EstadoPedidoDropi::Pending);

    $v = DropiVinculoProducto::sole();
    expect($v->variante_id)->toBeNull()->and($v->producto_id)->toBe($this->agregado->id);
});

it('sin stock en la bodega de venta vincula pero el pedido sigue esperando inventario', function () {
    app(SincronizarPedidosDropi::class)->importarPedidos([agrPedido('GA-2', 'DV-1', 3)]);

    $r = VincularProductoDropi::make()->aProducto('DV-1', $this->agregado->id);

    expect($r)->toBe(['items' => 1, 'pedidos_liberados' => 0])
        ->and(DropiPedidoItem::sole()->producto_id)->toBe($this->agregado->id)
        ->and(DropiPedido::where('guia', 'GA-2')->value('estado'))->toBe(EstadoPedidoDropi::PendienteInventario);
});

it('un pedido nuevo de un SKU ya vinculado entra con el producto y, con stock, en pending', function () {
    agrStock($this->agregado, $this->venta, 5);
    VincularProductoDropi::make()->aProducto('DV-77', $this->agregado->id);

    app(SincronizarPedidosDropi::class)->importarPedidos([agrPedido('GA-3', 'DV-77', 2)]);

    expect(DropiPedidoItem::sole()->producto_id)->toBe($this->agregado->id)
        ->and(DropiPedido::where('guia', 'GA-3')->value('estado'))->toBe(EstadoPedidoDropi::Pending);
});

it('no vincula entero un producto que lleva el stock por variante', function () {
    $conDesglose = Producto::create(['referencia' => 'COCHE-02', 'nombre' => 'Coche', 'desglose_stock' => true]);

    expect(fn () => VincularProductoDropi::make()->aProducto('DV-9', $conDesglose->id))
        ->toThrow(DomainException::class);
    expect(DropiVinculoProducto::count())->toBe(0);
});

it('al empacar descuenta del total del producto agregado', function () {
    agrStock($this->agregado, $this->venta, 10);
    VincularProductoDropi::make()->aProducto('DV-55', $this->agregado->id);
    app(SincronizarPedidosDropi::class)->importarPedidos([agrPedido('GA-4', 'DV-55', 2)]);

    $p = DropiPedido::where('guia', 'GA-4')->sole();
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->operario->id);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->operario->id);

    $egreso = InventarioMovimiento::where('referencia_tipo', SalidaInventarioDropi::EGRESO)->sole();
    expect((int) $egreso->cantidad)->toBe(-2)
        ->and($egreso->variante_id)->toBeNull()
        ->and($egreso->producto_id)->toBe($this->agregado->id)
        ->and((int) InventarioMovimiento::where('producto_id', $this->agregado->id)->whereNull('variante_id')->sum('cantidad'))->toBe(8);
});
