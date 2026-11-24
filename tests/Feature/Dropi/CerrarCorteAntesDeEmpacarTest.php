<?php

/**
 * «Desde cerrar corte poder hacer todo el proceso de empacado» (24-nov-2026).
 *
 * «Cerrar corte» es la hora de corte de la bodega: el corte deja de recibir
 * pedidos (los nuevos van al siguiente) y los suyos se siguen recolectando y
 * empacando. «Finalizar corte» lo congela con el manifiesto cuando todo está
 * empacado, como antes.
 */

use App\Models\User;
use App\Modules\Dropi\Actions\CerrarCorte;
use App\Modules\Dropi\Actions\CortarEntradaCorte;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\EstadoCorte;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Pages\EstacionEmpaque;
use App\Modules\Dropi\Filament\Pages\VistaAlistador;
use App\Modules\Dropi\Filament\Resources\DropiCorteResource\Pages\ListDropiCortes;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use App\Modules\Dropi\Models\Producto;
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

    $this->aracely = User::factory()->create(['name' => 'Aracely']);
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $volante = Producto::create(['referencia' => 'CC-899', 'nombre' => 'Volante', 'desglose_stock' => false]);
    app(VincularProductoDropi::class)->aProducto('CC-SKU-899', $volante->id);
});

/** Como llega de Dropi: el importador le asigna el corte (AsignarCorteACarga). */
function ccPedido(string $guia, ?CarbonImmutable $creado = null): DropiPedido
{
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: 'O-'.$guia, transportadora: 'X', tienda: 'T', vendedorNombre: 'Vendedora',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 30000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO('CC-SKU-899', 1, 30000, 'x', 'x')],
        creadoAt: $creado ?? CarbonImmutable::parse('2026-11-24 09:00'),
    )]);

    return DropiPedido::where('guia', $guia)->firstOrFail();
}

it('«Cerrar corte» deja de recibir pedidos: el nuevo cae en otro corte y lo del cerrado se sigue empacando en la estación', function () {
    $p = ccPedido('CC-1');
    $corte = $p->corte;
    expect(EstadoCorte::de($corte->estado))->toBe(EstadoCorte::Abierto);

    CortarEntradaCorte::run($corte->id, $this->aracely->id);
    $corte->refresh();
    expect($corte->estado)->toBe(EstadoCorte::EnDespacho)
        ->and($corte->cortado_por)->toBe($this->aracely->id)
        ->and($corte->cortado_at)->not->toBeNull()
        ->and($corte->cerrado_at)->toBeNull()
        ->and($corte->manifiesto_hash)->toBeNull();

    // Un pedido que entra después, en el mismo horario, ya no cae en el cerrado.
    $nuevo = ccPedido('CC-2', CarbonImmutable::parse('2026-11-24 10:00'));
    expect($nuevo->corte_id)->not->toBe($corte->id)
        ->and(EstadoCorte::de($nuevo->corte->estado))->toBe(EstadoCorte::Abierto);

    // La vista del alistador trabaja el corte cerrado, no el nuevo.
    $this->actingAs($this->aracely);
    expect(Livewire::test(VistaAlistador::class)->instance()->corteId)->toBe($corte->id)
        ->and(DropiCorte::enTrabajo()->id)->toBe($corte->id);

    // Y se empaca en la estación como siempre.
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->aracely->id);
    $p->items()->update(['cantidad_pickeada' => 1]);
    EmpaqueRegistro::create([
        'pedido_id' => $p->id, 'operario_id' => $this->aracely->id, 'inicio_at' => now(),
        'items_totales' => 1, 'items_escaneados' => 1, 'estado' => 'en_curso',
    ]);
    Reglas::set('empaque.foto_obligatoria', false);
    Livewire::test(EstacionEmpaque::class)->call('confirmarYSiguiente');

    expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Empacado);
});

it('«Finalizar corte» se niega con pedidos sin empacar y congela cuando todo está empacado', function () {
    $p = ccPedido('CC-3');
    $corte = $p->corte;
    CortarEntradaCorte::run($corte->id, $this->aracely->id);

    expect(fn () => CerrarCorte::run($corte->id, $this->aracely->id))
        ->toThrow(RuntimeException::class, 'No se puede cerrar');
    expect(EstadoCorte::de($corte->fresh()->estado))->toBe(EstadoCorte::EnDespacho);

    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->aracely->id);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->aracely->id);

    $r = CerrarCorte::run($corte->id, $this->aracely->id);
    $corte->refresh();
    expect($corte->estado)->toBe(EstadoCorte::Cerrado)
        ->and($corte->manifiesto_hash)->toBe($r['hash'])
        ->and($corte->cortado_por)->toBe($this->aracely->id);

    // Finalizado, nadie lo cambia a mano.
    expect(fn () => $p->fresh()->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->aracely->id))
        ->toThrow(RuntimeException::class, 'está cerrado');
});

it('en la lista de cortes, «Cerrar corte» sólo aparece en uno abierto y lo deja en despacho', function () {
    $corte = ccPedido('CC-4')->corte;
    $this->actingAs($this->aracely);

    Livewire::test(ListDropiCortes::class)
        ->assertTableActionVisible('cerrar_entrada', $corte)
        ->callTableAction('cerrar_entrada', $corte)
        ->assertNotified('Corte cerrado: ya no recibe pedidos');

    expect($corte->fresh()->estado)->toBe(EstadoCorte::EnDespacho);

    Livewire::test(ListDropiCortes::class)
        ->assertTableActionHidden('cerrar_entrada', $corte)
        ->assertTableActionVisible('cerrar_corte', $corte)
        ->assertSee('Cerrado · empacando');

    expect(fn () => CortarEntradaCorte::run($corte->id, $this->aracely->id))
        ->toThrow(RuntimeException::class, 'ya está cerrado');
});
