<?php

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
use App\Modules\Dropi\Models\ProductoVariante;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL: DB_CONNECTION=mysql DB_DATABASE=great_baby_test');
    }
    $bodega = InventarioUbicacion::firstOrCreate(
        ['codigo' => 'TEST-BOD'],
        ['nombre' => 'Bodega test', 'activa' => true, 'categoria' => 'venta'],
    );
    $producto = Producto::create(['referencia' => 'VOL-01', 'nombre' => 'Volante con música', 'desglose_stock' => true]);
    $this->variante = ProductoVariante::create(['producto_id' => $producto->id, 'color_codigo' => 'NEG', 'color_nombre' => 'Negro', 'codigo_barras' => '7700000000011']);
    InventarioMovimiento::create([
        'variante_id' => $this->variante->id, 'ubicacion_id' => $bodega->id,
        'tipo' => 'ajuste_test', 'cantidad' => 10,
    ]);
});

function pedidoDropi(string $guia, string $sku, int $cantidad = 1): PedidoDropiDTO
{
    return new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: 'O-' . $guia, transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 1000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO($sku, $cantidad, 1000, 'Volante de Juguete con Música', 'Negro-amarillo')],
        creadoAt: CarbonImmutable::now(),
    );
}

it('deja el pedido pendiente por inventario si la llave de Dropi no está vinculada', function () {
    app(SincronizarPedidosDropi::class)->importarPedidos([pedidoDropi('G1', 'DV-1843712')]);

    expect(DropiPedido::where('guia', 'G1')->value('estado'))->toBe(EstadoPedidoDropi::PendienteInventario)
        ->and(DropiPedidoItem::first()->variante_id)->toBeNull();
});

it('al vincular asigna la variante a lo que esperaba y libera el pedido', function () {
    app(SincronizarPedidosDropi::class)->importarPedidos([pedidoDropi('G1', 'DV-1843712')]);

    $r = VincularProductoDropi::run('DV-1843712', $this->variante->id);

    expect($r)->toBe(['items' => 1, 'pedidos_liberados' => 1]);
    $item = DropiPedidoItem::first();
    expect($item->variante_id)->toBe($this->variante->id)
        ->and($item->producto_id)->toBe($this->variante->producto_id)
        ->and(DropiPedido::where('guia', 'G1')->value('estado'))->toBe(EstadoPedidoDropi::Pending)
        ->and(DropiVinculoProducto::first()->producto_nombre)->toBe('Volante de Juguete con Música');
    expect(DB::table('dropi_estados_bitacora')->where('estado_hasta', 'pending')->where('fuente', 'sistema')->exists())->toBeTrue();
});

it('los pedidos nuevos con una llave vinculada entran solos', function () {
    VincularProductoDropi::run('DV-1843712', $this->variante->id);

    app(SincronizarPedidosDropi::class)->importarPedidos([pedidoDropi('G2', 'DV-1843712')]);

    expect(DropiPedidoItem::first()->variante_id)->toBe($this->variante->id)
        ->and(DropiPedido::where('guia', 'G2')->value('estado'))->toBe(EstadoPedidoDropi::Pending);
});

it('vincular sin stock suficiente asigna la variante pero no libera el pedido', function () {
    app(SincronizarPedidosDropi::class)->importarPedidos([pedidoDropi('G3', 'DV-1843712', 50)]);

    $r = VincularProductoDropi::run('DV-1843712', $this->variante->id);

    expect($r['pedidos_liberados'])->toBe(0)
        ->and(DropiPedidoItem::first()->variante_id)->toBe($this->variante->id)
        ->and(DropiPedido::where('guia', 'G3')->value('estado'))->toBe(EstadoPedidoDropi::PendienteInventario);
});

it('cambiar el vínculo no toca pedidos que ya se alistaron', function () {
    app(SincronizarPedidosDropi::class)->importarPedidos([pedidoDropi('G4', 'DV-1843712')]);
    VincularProductoDropi::run('DV-1843712', $this->variante->id);
    DropiPedido::where('guia', 'G4')->first()->transicionar(EstadoPedidoDropi::Alistando, 'sistema');

    $otra = ProductoVariante::create(['producto_id' => $this->variante->producto_id, 'color_codigo' => 'ROS', 'color_nombre' => 'Rosado', 'codigo_barras' => '7700000000028']);
    $r = VincularProductoDropi::run('DV-1843712', $otra->id);

    expect($r['items'])->toBe(0)
        ->and(DropiPedidoItem::first()->variante_id)->toBe($this->variante->id)
        ->and(DropiVinculoProducto::first()->variante_id)->toBe($otra->id);
});

it('la pantalla lista lo pendiente y vincula desde el modal', function () {
    app(SincronizarPedidosDropi::class)->importarPedidos([pedidoDropi('G5', 'DV-1843712')]);
    \Spatie\Permission\Models\Role::findOrCreate('Aracely', 'web');
    $user = \App\Models\User::factory()->create();
    $user->assignRole('Aracely');
    $this->actingAs($user);
    \Filament\Facades\Filament::setCurrentPanel('admin');

    expect(\App\Modules\Dropi\Filament\Pages\ProductosSinVincular::buscarVariantes('volante'))
        ->toHaveKey($this->variante->id);

    \Livewire\Livewire::test(\App\Modules\Dropi\Filament\Pages\ProductosSinVincular::class)
        ->assertSee('Volante de Juguete con Música')
        ->assertSee('DV-1843712')
        ->callAction('vincular', ['variante_id' => $this->variante->id], ['sku' => 'DV-1843712', 'nombre' => 'Volante'])
        ->assertHasNoActionErrors()
        ->assertSee('No hay productos sin vincular');

    expect(DropiPedido::where('guia', 'G5')->value('estado'))->toBe(EstadoPedidoDropi::Pending);
});
