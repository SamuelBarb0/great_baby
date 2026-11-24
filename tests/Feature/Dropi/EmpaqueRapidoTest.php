<?php

/**
 * Empaque con pocos clics (reunión con la señora Lahida, 24-nov-2026): la cola
 * va en el orden de la pila de stickers (el producto con más pedidos primero),
 * «Siguiente pedido» abre el siguiente libre, escanear un producto abre el
 * pedido que lo lleva, y al confirmar se abre el siguiente solo.
 */

use App\Models\User;
use App\Modules\Dropi\Actions\CortarEntradaCorte;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Pages\EstacionEmpaque;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Support\ColaDeEmpaque;
use App\Support\Reglas;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Queue::fake();
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    Filament::setCurrentPanel('admin');
    Reglas::set('empaque.foto_obligatoria', '0');

    $this->empacador = User::factory()->create();
    $this->empacador->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->otro = User::factory()->create();
    $this->otro->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    foreach (['ER-VOL' => 'Volante', 'ER-TAP' => 'Tapete'] as $ref => $nombre) {
        $p = Producto::create(['referencia' => $ref, 'nombre' => $nombre, 'desglose_stock' => false, 'activo' => true]);
        app(VincularProductoDropi::class)->aProducto("SKU-{$ref}", $p->id);
    }
});

function erPedido(string $guia, string $ref, string $creado): DropiPedido
{
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: 'O-'.$guia, transportadora: 'X', tienda: 'T', vendedorNombre: 'Vendedora',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: 'CALI', clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 30000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO("SKU-{$ref}", 1, 30000, 'x', 'x')],
        creadoAt: CarbonImmutable::parse($creado),
    )]);
    $p = DropiPedido::where('guia', $guia)->firstOrFail();
    // Con stock o sin él, para la cola cuenta que esté listo para empacar.
    $p->estado = EstadoPedidoDropi::Pending;
    $p->save();

    return $p;
}

it('la cola va como la pila de stickers: el producto con más pedidos primero, el más viejo dentro', function () {
    $tapete = erPedido('ER-1', 'ER-TAP', '2026-11-24 08:00');
    $vol2 = erPedido('ER-2', 'ER-VOL', '2026-11-24 09:30');
    $vol1 = erPedido('ER-3', 'ER-VOL', '2026-11-24 09:00');

    $orden = app(ColaDeEmpaque::class)->pedidos()->pluck('id')->all();

    expect($orden)->toBe([$vol1->id, $vol2->id, $tapete->id]);
});

it('con un corte cerrado («Cerrar corte») sólo se empaca ese', function () {
    $delCerrado = erPedido('ER-10', 'ER-VOL', '2026-11-24 09:00');
    CortarEntradaCorte::run($delCerrado->corte_id, $this->empacador->id);
    $nuevo = erPedido('ER-11', 'ER-VOL', '2026-11-24 09:10');

    expect($nuevo->corte_id)->not->toBe($delCerrado->corte_id)
        ->and(app(ColaDeEmpaque::class)->pedidos()->pluck('id')->all())->toBe([$delCerrado->id]);
});

it('«Siguiente pedido» abre el siguiente libre, y otro empacador recibe el que sigue', function () {
    $a = erPedido('ER-20', 'ER-VOL', '2026-11-24 09:00');
    $b = erPedido('ER-21', 'ER-VOL', '2026-11-24 09:05');

    $this->actingAs($this->empacador);
    Livewire::test(EstacionEmpaque::class)->call('siguientePedido')->assertSet('pedidoActivoId', $a->id);

    $this->actingAs($this->otro);
    Livewire::test(EstacionEmpaque::class)->call('siguientePedido')->assertSet('pedidoActivoId', $b->id);

    expect(EmpaqueRegistro::where('pedido_id', $a->id)->value('operario_id'))->toBe($this->empacador->id)
        ->and(EmpaqueRegistro::where('pedido_id', $b->id)->value('operario_id'))->toBe($this->otro->id);
});

it('escanear un producto sin pedido abierto abre el pedido que lo lleva y lo marca', function () {
    erPedido('ER-30', 'ER-VOL', '2026-11-24 08:00');
    $tapete = erPedido('ER-31', 'ER-TAP', '2026-11-24 09:00');

    $this->actingAs($this->empacador);
    Livewire::test(EstacionEmpaque::class)
        ->set('codigo', 'er-tap')
        ->call('escanear')
        ->assertSet('pedidoActivoId', $tapete->id);

    expect($tapete->fresh()->estado)->toBe(EstadoPedidoDropi::Alistando)
        ->and((int) $tapete->items()->first()->cantidad_pickeada)->toBe(1);
});

it('al confirmar se abre solo el siguiente de la cola', function () {
    $a = erPedido('ER-40', 'ER-VOL', '2026-11-24 09:00');
    $b = erPedido('ER-41', 'ER-VOL', '2026-11-24 09:05');

    $this->actingAs($this->empacador);
    Livewire::test(EstacionEmpaque::class)
        ->call('siguientePedido')
        ->set('codigo', 'ER-VOL')->call('escanear')
        ->call('confirmarYSiguiente')
        ->assertSet('pedidoActivoId', $b->id);

    expect($a->fresh()->estado)->toBe(EstadoPedidoDropi::Empacado);
});
