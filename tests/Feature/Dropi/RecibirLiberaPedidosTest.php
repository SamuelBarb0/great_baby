<?php

/**
 * Al recibir mercancía, los pedidos de Dropi frenados por falta de stock vuelven
 * a Pending si ya les alcanza (24-nov-2026: el pedido que sólo esperaba el
 * DIDA-004 seguía en «Pendiente por inventario» después de recibir SA383 y no
 * aparecía en las colas). El más viejo primero: dos pedidos no se quedan con la
 * misma unidad.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Actions\RegistrarRecepcion;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Queue::fake();
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->actingAs($this->aracely);

    $this->bodega = InventarioUbicacion::create(['codigo' => 'RL-RA1', 'nombre' => 'Rack A · Nivel 01', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true, 'disponible_para_venta' => true]);
    $this->proveedor = Contacto::create(['tipo_documento' => 'NIT', 'numero_documento' => '901738354', 'nombre_completo' => 'GREAT BABY', 'es_proveedor' => true, 'en_dropi' => true, 'activo' => true]);
    $this->tablero = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => 'RL-DIDA-004', 'nombre' => 'Tablero', 'activo' => true, 'desglose_stock' => false]));
    app(VincularProductoDropi::class)->aProducto('RL-DIDA-004', $this->tablero->id);
});

function rlPedido(string $guia, string $creado, int $cantidad = 1): DropiPedido
{
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: 'O-'.$guia, transportadora: 'ENVIA', tienda: 'T', vendedorNombre: 'Vendedora',
        vendedorIdentificacion: null, clienteNombre: 'Julio', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: 'CALI', clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 30000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO('RL-DIDA-004', $cantidad, 12064, 'x', 'x')],
        creadoAt: CarbonImmutable::parse($creado),
    )]);

    return DropiPedido::where('guia', $guia)->firstOrFail();
}

/** Una compra de Dropi del tablero, aprobada y recibida con $llega unidades. */
function rlRecibir(int $llega, string $clasificacion = 'apto'): void
{
    $oc = CrearOrdenCompra::run([
        'proveedor_id' => test()->proveedor->id, 'bodega_id' => test()->bodega->id, 'tipo' => 'nacional',
        'moneda' => 'COP', 'tasa_cambio' => 1, 'linea_negocio' => LineaNegocio::Dropi,
        'items' => [['producto_id' => test()->tablero->id, 'variante_id' => null, 'descripcion' => 'Tablero',
            'cantidad' => $llega, 'precio_unit' => 12064, 'descuento_pct' => 0, 'iva_pct' => 0]],
    ]);
    AprobarOrdenCompra::run($oc);
    RegistrarRecepcion::run([
        'orden_id' => $oc->id, 'factura_proveedor' => 'SA383',
        'items' => [['orden_item_id' => $oc->items->first()->id, 'cantidad_recibida' => $llega, 'clasificacion' => $clasificacion,
            'motivo_clasificacion' => $clasificacion === 'apto' ? null : 'Llegó golpeado en la caja']],
    ]);
}

it('al recibir, el pedido que esperaba ese producto vuelve a Pending', function () {
    $p = rlPedido('RL-1', '2026-11-20 21:20');
    expect($p->estado)->toBe(EstadoPedidoDropi::PendienteInventario);

    rlRecibir(168);

    expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Pending);
});

it('si llega menos de lo que piden, se libera primero el pedido más viejo', function () {
    $viejo = rlPedido('RL-VIEJO', '2026-11-20 09:00');
    $nuevo = rlPedido('RL-NUEVO', '2026-11-21 09:00');

    rlRecibir(1);

    expect($viejo->fresh()->estado)->toBe(EstadoPedidoDropi::Pending)
        ->and($nuevo->fresh()->estado)->toBe(EstadoPedidoDropi::PendienteInventario);
});

it('lo que llega en avería no libera pedidos', function () {
    $p = rlPedido('RL-2', '2026-11-20 21:20');

    rlRecibir(5, 'averia');

    expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::PendienteInventario);
});
