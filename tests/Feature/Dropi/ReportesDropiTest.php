<?php

/**
 * «Reportes Dropi»: cada tarjeta lleva a una pantalla que existe y, si filtra,
 * el filtro se aplica de verdad. Antes las tarjetas filtradas mandaban
 * `tableFilters` (Filament 3) y Filament 5 lo ignoraba: abrían la tabla
 * completa. Bitácora, Cambios financieros y Comparativo eran `#` o repetían el
 * Dashboard.
 */

use App\Models\User;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Pages\BitacoraEstadosDropi;
use App\Modules\Dropi\Filament\Pages\CambiosFinancierosDropi;
use App\Modules\Dropi\Filament\Pages\ComparativoPeriodoDropi;
use App\Modules\Dropi\Filament\Pages\ReportesDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiRemision;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function repUsuario(string $rol): User
{
    $u = User::factory()->create();
    $u->assignRole(Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']));

    return $u;
}

function repPedido(string $guia, float $monto = 12500): DropiPedido
{
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: 'O-'.$guia, transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: $monto, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO('SKU-'.$guia, 1, $monto, 'Producto', null)],
        creadoAt: CarbonImmutable::now(),
    )]);

    return DropiPedido::where('guia', $guia)->firstOrFail();
}

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Queue::fake();
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    $this->aracely = repUsuario('Aracely');
    Filament::setCurrentPanel('admin');
});

it('ninguna tarjeta de Reportes queda en «#» ni repite destino, y las filtradas por etapa del ERP usan `etapa`', function () {
    $this->actingAs($this->aracely);
    $reportes = collect((new ReportesDropi())->getBloques())->flatMap(fn ($b) => $b['reportes']);

    $urls = $reportes->pluck('url', 'nombre');
    expect($urls->filter(fn ($u) => $u === '#'))->toBeEmpty()
        ->and($urls['Bitácora de estados'])->toBe(BitacoraEstadosDropi::getUrl())
        ->and($urls['Cambios financieros'])->toBe(CambiosFinancierosDropi::getUrl())
        ->and($urls['Comparativo periodo'])->toBe(ComparativoPeriodoDropi::getUrl())
        ->and($urls['Comparativo periodo'])->not->toBe($urls['Dashboard consolidado'])
        ->and(urldecode($urls['Pendientes por inventario']))->toContain('etapa=pendiente_inventario')
        ->and($urls->filter(fn ($u) => str_contains(urldecode($u), 'tableFilters')))->toBeEmpty();

    // Y cada destino abre (200) para quien ve Reportes.
    foreach ($urls as $nombre => $url) {
        $this->get($url)->assertOk();
    }
});

it('el filtro del enlace «Pendientes por inventario» sí filtra la tabla de pedidos', function () {
    $pend = repPedido('REP-PEND');
    $otro = repPedido('REP-OTRO');
    DB::table('dropi_pedidos')->where('id', $pend->id)->update(['estado' => 'pendiente_inventario']);
    DB::table('dropi_pedidos')->where('id', $otro->id)->update(['estado' => 'despachado']);
    $this->actingAs($this->aracely);

    $this->get(collect((new ReportesDropi())->getBloques()['operativos']['reportes'])
        ->firstWhere('nombre', 'Pendientes por inventario')['url'])
        ->assertOk()
        ->assertSee('REP-PEND')
        ->assertDontSee('REP-OTRO');
});

it('la Bitácora de estados muestra quién cambió qué y filtra por guía', function () {
    $p = repPedido('REP-BIT-1');
    repPedido('REP-BIT-2');
    $this->actingAs($this->aracely);
    $p->refresh()->transicionar(EstadoPedidoDropi::CanceladoGb, 'manual', $this->aracely->id);   // transición válida desde pendiente_inventario

    Livewire::test(BitacoraEstadosDropi::class)
        ->assertSee('REP-BIT-1')
        ->assertSee('REP-BIT-2')
        ->assertSee('Cancelado gb')
        ->assertSee($this->aracely->name)
        ->set('guia', 'REP-BIT-1')
        ->assertSee('REP-BIT-1')
        ->assertDontSee('REP-BIT-2')
        ->set('fuente', 'manual')
        ->assertSee('REP-BIT-1');
});

it('Cambios financieros muestra la remisión creada por una persona, con su valor', function () {
    config()->set('audit.console', true);   // los tests corren en consola; en la web se audita solo
    $p = repPedido('REP-AUD');
    $this->actingAs($this->aracely);
    DropiRemision::create(['pedido_id' => $p->id, 'consecutivo' => 'REM-TEST-1', 'valor_proveedor' => 64000]);

    Livewire::test(CambiosFinancierosDropi::class)
        ->assertSee('Remisión')
        ->assertSee('REM-TEST-1')
        ->assertSee('64000')
        ->assertSee($this->aracely->name)
        ->set('tipo', \App\Modules\Dropi\Models\DropiSancion::class)
        ->assertDontSee('REM-TEST-1');
});

it('el Comparativo compara este mes con los mismos días del anterior', function () {
    $hoy = CarbonImmutable::create(2026, 11, 7, 12, 0, 0, 'America/Bogota');
    $a = repPedido('REP-CMP-A', 30000);
    $b = repPedido('REP-CMP-B', 10000);
    $c = repPedido('REP-CMP-C', 99000);
    DB::table('dropi_pedidos')->where('id', $a->id)->update(['estado' => 'pagado', 'pagado_at' => '2026-11-03 10:00:00']);
    DB::table('dropi_pedidos')->where('id', $b->id)->update(['estado' => 'pagado', 'pagado_at' => '2026-10-05 10:00:00']);
    DB::table('dropi_pedidos')->where('id', $c->id)->update(['estado' => 'pagado', 'pagado_at' => '2026-10-20 10:00:00']);   // fuera de los mismos días

    $filas = collect((new ComparativoPeriodoDropi())->getComparativo($hoy)['filas'])->keyBy('nombre');
    $ventas = $filas['Ventas (pedidos pagados)'];

    expect($ventas['actual'])->toBe(30000.0)
        ->and($ventas['anterior'])->toBe(10000.0)              // 1–7 oct
        ->and($ventas['anterior_completo'])->toBe(109000.0)    // octubre entero
        ->and($ventas['variacion'])->toBe(200.0)
        ->and($filas['Sanciones de Dropi']['variacion'])->toBeNull();   // sin base no hay porcentaje

    $this->actingAs($this->aracely)->get(ComparativoPeriodoDropi::getUrl())->assertOk()->assertSee('Ventas (pedidos pagados)');
});
