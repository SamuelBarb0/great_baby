<?php

/**
 * 27-nov-2026: en Dropi «FICHAS MAGNÉTICAS MINECRAFT» se vende «X 1 CAJA»,
 * «X 2 CAJAS» y «X 3 CAJAS», y las tres apuntan a la misma caja del ERP. La
 * guía decía dos cajas y la lista del corte una. Cada vínculo sabe cuántas
 * unidades del ERP trae su variación, y eso es lo que se recoge, se escanea y
 * sale del inventario.
 */

use App\Modules\Dropi\Actions\ResolverEstadoInicial;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\SalidaInventarioDropi;
use App\Modules\Dropi\Support\RecoleccionDelCorte;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->caja = Producto::create(['referencia' => 'CH1327', 'nombre' => 'SET BLOQUES MINECRAFT', 'desglose_stock' => false, 'activo' => true]);
});

function packPedido(string $orden, string $sku, string $variacion, int $cantidad = 1): DropiPedido
{
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: "G-{$orden}", dropiOrdenId: $orden, transportadora: 'ENVIA', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Dayanna', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: 'BOGOTA', clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 50000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO($sku, $cantidad, 50000, 'FICHAS MAGNETICAS MINECRAFT', $variacion)],
        creadoAt: CarbonImmutable::parse('2026-11-27 09:00'),
    )]);

    return DropiPedido::where('dropi_orden_id', $orden)->with('items')->firstOrFail();
}

it('lee las unidades del nombre de la variación, sin confundir tallas', function (string $variacion, int $unidades) {
    expect(DropiVinculoProducto::detectarUnidades($variacion))->toBe($unidades);
})->with([
    ['X 1 CAJA', 1], ['X 2 CAJAS', 2], ['x3', 3], ['PACK 2', 2], ['KIT X 4', 4], ['2 UNIDADES', 2], ['3 CAJAS', 3],
    ['ROJO', 1], ['18-20', 1], ['TALLA 2', 1], ['SALMÓN / 20-21', 1], ['', 1],
]);

it('al vincular, «X 2 CAJAS» queda de 2 unidades y lo que se corrige a mano se respeta', function () {
    packPedido('801', 'DV-2372762', 'X 2 CAJAS');
    app(VincularProductoDropi::class)->aProducto('DV-2372762', $this->caja->id);
    expect(DropiVinculoProducto::where('sku_dropi', 'DV-2372762')->value('unidades'))->toBe(2);

    DropiVinculoProducto::where('sku_dropi', 'DV-2372762')->update(['unidades' => 5]);
    app(VincularProductoDropi::class)->aProducto('DV-2372762', $this->caja->id);
    expect(DropiVinculoProducto::where('sku_dropi', 'DV-2372762')->value('unidades'))->toBe(5);
});

it('un pack pide, cuenta y saca del inventario sus unidades del ERP; la cantidad de Dropi no cambia', function () {
    app(VincularProductoDropi::class)->aProducto('DV-2372762', $this->caja->id, null, ['variacion' => 'X 2 CAJAS']);
    $p = packPedido('802', 'DV-2372762', 'X 2 CAJAS', 2); // 2 packs de 2 cajas
    $item = $p->items->first();

    expect($item->cantidad)->toBe(2)                          // lo que dice Dropi
        ->and($item->unidadesPorUnidad())->toBe(2)
        ->and($item->unidadesErp())->toBe(4)                  // lo que va en la caja
        ->and(SalidaInventarioDropi::unidadesDescontables($p))->toBe(4)
        ->and(RecoleccionDelCorte::resumenProductos($p))->toBe('4× SET BLOQUES MINECRAFT (X 2 CAJAS)')
        ->and($item->variacionConUnidades())->toBe('X 2 CAJAS · 2 u. c/u');
});

it('con stock para una caja, un pack de dos queda pendiente por inventario', function () {
    app(VincularProductoDropi::class)->aProducto('DV-2372762', $this->caja->id, null, ['variacion' => 'X 2 CAJAS']);
    app(VincularProductoDropi::class)->aProducto('DV-2372761', $this->caja->id, null, ['variacion' => 'X 1 CAJA']);
    $resolver = Mockery::mock(ResolverEstadoInicial::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $resolver->shouldReceive('stockDisponibleAgregado')->andReturn(1);

    expect($resolver->porSku([['sku' => 'DV-2372761', 'cantidad' => 1]]))->toBe(EstadoPedidoDropi::Pending)
        ->and($resolver->porSku([['sku' => 'DV-2372762', 'cantidad' => 1]]))->toBe(EstadoPedidoDropi::PendienteInventario);
});

it('el comando propone los packs según el nombre y con --aplicar los guarda; --sku corrige uno a mano', function () {
    packPedido('803', 'DV-2372763', 'X 3 CAJAS');
    DropiVinculoProducto::create(['sku_dropi' => 'DV-2372763', 'producto_id' => $this->caja->id, 'unidades' => 1]);

    $this->artisan('dropi:packs-por-variacion')->expectsOutputToContain('X 3 CAJAS')->assertSuccessful();
    expect(DropiVinculoProducto::where('sku_dropi', 'DV-2372763')->value('unidades'))->toBe(1);

    $this->artisan('dropi:packs-por-variacion --aplicar')->assertSuccessful();
    expect(DropiVinculoProducto::where('sku_dropi', 'DV-2372763')->value('unidades'))->toBe(3);

    $this->artisan('dropi:packs-por-variacion --sku=DV-2372763 --unidades=6')->assertSuccessful();
    expect(DropiVinculoProducto::where('sku_dropi', 'DV-2372763')->value('unidades'))->toBe(6);
});
