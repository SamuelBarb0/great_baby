<?php

/**
 * «Pedidos PDF» del corte (bodega, 23-nov-2026): con el corte abierto, todos
 * los pedidos del corte o uno solo, y nada de otros cortes.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Filament\Resources\DropiCorteResource\Pages\ListDropiCortes;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(\Database\Seeders\PermisosSeeder::class);

    // Un perfil creado en Roles, como el de la bodega: ver Cortes y Pedidos Dropi.
    $rol = Role::firstOrCreate(['name' => 'Operador Dropi PDF', 'guard_name' => 'web']);
    $rol->givePermissionTo([Permisos::permiso('dropi_cortes'), Permisos::permiso('dropi_pedidos')]);
    $this->gerente = User::factory()->create();
    $this->gerente->assignRole($rol);

    $this->corte = DropiCorte::create(['fecha' => now()->toDateString(), 'numero' => 1]);
    $this->otroCorte = DropiCorte::create(['fecha' => now()->toDateString(), 'numero' => 2]);

    // Lo que la vista recibe, para mirar los pedidos sin descomprimir el PDF.
    $this->vista = null;
    View::composer('dropi.pdf.pedidos-corte', fn ($v) => $this->vista = $v->getData());
});

function pedidoPdf(DropiCorte $corte, string $guia, array $items = []): DropiPedido
{
    $id = DB::table('dropi_pedidos')->insertGetId([
        'guia' => $guia, 'dropi_orden_id' => 'O-' . $guia, 'estado' => EstadoPedidoDropi::Pending->value,
        'corte_id' => $corte->id, 'cliente_nombre' => 'Cliente ' . $guia, 'cliente_ciudad' => 'Bucaramanga',
        'monto_esperado_proveedor' => 30000, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($items as [$sku, $cant]) {
        DB::table('dropi_pedido_items')->insert([
            'pedido_id' => $id, 'sku_dropi' => $sku, 'cantidad' => $cant, 'precio_proveedor_unit' => 10000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return DropiPedido::findOrFail($id);
}

function guiasDelPdf(): array
{
    return array_map(fn ($f) => $f['pedido']->guia, test()->vista['filas']);
}

it('baja todos los pedidos del corte abierto, con sus productos, y ninguno de otro corte', function () {
    pedidoPdf($this->corte, 'G-2', [['SKU-A', 2]]);
    pedidoPdf($this->corte, 'G-1', [['SKU-B', 1], ['SKU-C', 3]]);
    pedidoPdf($this->otroCorte, 'G-9');

    $this->actingAs($this->gerente)
        ->get(route('dropi.corte.pedidos.pdf', $this->corte))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(guiasDelPdf())->toBe(['G-1', 'G-2'])
        ->and($this->vista['soloUno'])->toBeFalse()
        ->and($this->vista['unidades'])->toBe(6)
        ->and(array_column($this->vista['filas'][0]['productos'], 'cantidad'))->toBe([1, 3]);
});

it('baja un solo pedido cuando se elige uno', function () {
    pedidoPdf($this->corte, 'G-1');
    $p2 = pedidoPdf($this->corte, 'G-2', [['SKU-A', 2]]);

    $this->actingAs($this->gerente)
        ->get(route('dropi.corte.pedidos.pdf', ['corte' => $this->corte, 'pedido' => $p2->id]))
        ->assertOk();

    expect(guiasDelPdf())->toBe(['G-2'])->and($this->vista['soloUno'])->toBeTrue();
});

it('un pedido de otro corte da 404, no un PDF vacío', function () {
    $ajeno = pedidoPdf($this->otroCorte, 'G-9');

    $this->actingAs($this->gerente)
        ->get(route('dropi.corte.pedidos.pdf', ['corte' => $this->corte, 'pedido' => $ajeno->id]))
        ->assertNotFound();
});

it('el botón «Pedidos PDF» de la fila abre todos, o el pedido elegido', function () {
    Filament::setCurrentPanel('admin');
    $p = pedidoPdf($this->corte, 'G-1');
    $this->actingAs($this->gerente);

    $todos = route('dropi.corte.pedidos.pdf', ['corte' => $this->corte->id]);
    $uno = route('dropi.corte.pedidos.pdf', ['corte' => $this->corte->id, 'pedido' => $p->id]);

    Livewire::test(ListDropiCortes::class)
        ->assertActionVisible(TestAction::make('pedidos_pdf')->table($this->corte))
        ->callAction(TestAction::make('pedidos_pdf')->table($this->corte), ['alcance' => 'todos'])
        ->assertHasNoFormErrors()
        ->assertJs('window.open(' . json_encode($todos) . ', "_blank")');

    Livewire::test(ListDropiCortes::class)
        ->callAction(TestAction::make('pedidos_pdf')->table($this->corte), ['alcance' => 'uno', 'pedido_id' => $p->id])
        ->assertHasNoFormErrors()
        ->assertJs('window.open(' . json_encode($uno) . ', "_blank")');

    // «Solo un pedido» sin elegir cuál no abre nada.
    Livewire::test(ListDropiCortes::class)
        ->callAction(TestAction::make('pedidos_pdf')->table($this->corte), ['alcance' => 'uno'])
        ->assertHasFormErrors(['pedido_id' => 'required'])
        ->assertNoJs();
});

it('sin los permisos de cortes y pedidos no se descarga', function () {
    pedidoPdf($this->corte, 'G-1');

    $this->actingAs(User::factory()->create())
        ->get(route('dropi.corte.pedidos.pdf', $this->corte))
        ->assertForbidden();

    // Solo Cortes (sin Pedidos, como el Contador) tampoco: el PDF trae clientes.
    $soloCortes = Role::firstOrCreate(['name' => 'Solo cortes PDF', 'guard_name' => 'web']);
    $soloCortes->givePermissionTo(Permisos::permiso('dropi_cortes'));
    $u = User::factory()->create();
    $u->assignRole($soloCortes);

    $this->actingAs($u)
        ->get(route('dropi.corte.pedidos.pdf', $this->corte))
        ->assertForbidden();
});
