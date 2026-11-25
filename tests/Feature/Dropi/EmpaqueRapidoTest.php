<?php

/**
 * Empaque con pocos clics (reunión con la señora Lahida, 24-nov-2026): la cola
 * va en el orden de la pila de stickers (el producto con más pedidos primero),
 * «Siguiente pedido» abre el siguiente libre, escanear un producto abre el
 * pedido que lo lleva, y al confirmar se abre el siguiente solo.
 */

use App\Models\User;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Pages\EstacionEmpaque;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\ImprimirGuiasDropi;
use App\Modules\Dropi\Support\ColaDeEmpaque;
use App\Support\Reglas;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
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

/** Un pedido listo para empacar; por defecto con la guía ya impresa, que es lo que entra a la cola. */
function erPedido(string $guia, string $ref, string $creado, bool $impreso = true): DropiPedido
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
    if ($impreso) {
        // Impreso sin posición en la pila, como lo que se imprime en Dropi.
        DropiPedido::whereKey($p->id)->update(['guia_impresa_at' => now()]);
    }

    return $p;
}

it('la cola va como la pila de stickers: el producto con más pedidos primero, el más viejo dentro', function () {
    $tapete = erPedido('ER-1', 'ER-TAP', '2026-11-24 08:00');
    $vol2 = erPedido('ER-2', 'ER-VOL', '2026-11-24 09:30');
    $vol1 = erPedido('ER-3', 'ER-VOL', '2026-11-24 09:00');

    $orden = app(ColaDeEmpaque::class)->pedidos()->pluck('id')->all();

    expect($orden)->toBe([$vol1->id, $vol2->id, $tapete->id]);
});

it('sólo se empaca lo que Aracely imprimió: lo del ERP en el orden de la pila, lote tras lote, y después lo impreso en Dropi', function () {
    config()->set('dropi.web.stickers_url', 'https://stickers.test');
    \Illuminate\Support\Facades\Storage::fake('local');
    // El lote se arma con sus propios trabajos: que corran; lo demás sigue simulado.
    Queue::fake([\App\Modules\Dropi\Jobs\GenerarGuiasEnBloqueJob::class]);
    $f = new \FPDF('P', 'mm', [100, 150]);
    $f->AddPage();
    $sticker = $f->Output('S');
    Http::fake(['stickers.test/*' => Http::response($sticker, 200, ['Content-Type' => 'application/pdf'])]);

    $a = erPedido('ER-50', 'ER-VOL', '2026-11-24 08:00', impreso: false);
    $b = erPedido('ER-51', 'ER-TAP', '2026-11-24 08:10', impreso: false);
    $sinImprimir = erPedido('ER-52', 'ER-VOL', '2026-11-24 08:20', impreso: false);
    $enDropi = erPedido('ER-53', 'ER-TAP', '2026-11-24 07:00');
    DropiPedido::whereIn('id', [$a->id, $b->id, $sinImprimir->id])->update(['sticker_path' => 'g.pdf']);

    $cola = app(ColaDeEmpaque::class);
    expect($cola->pedidos()->pluck('id')->all())->toBe([$enDropi->id])
        ->and($cola->sinImprimir())->toBe(3);

    // El lote de las 9 (sólo el tapete) y después el de las 11 (el volante).
    app(ImprimirGuiasDropi::class)->lanzar([$b->id], $this->empacador->id);
    app(ImprimirGuiasDropi::class)->lanzar([$a->id], $this->empacador->id);

    expect($cola->pedidos()->pluck('id')->all())->toBe([$b->id, $a->id, $enDropi->id])
        ->and($cola->sinImprimir())->toBe(1);

    // Un corte cerrado no deja tocar sus pedidos: abrirlo tumbaba la estación (prueba del 24-nov-2026).
    $corte = \App\Modules\Dropi\Actions\AsignarCorteACarga::run(CarbonImmutable::now());
    $enDropi->forceFill(['corte_id' => $corte->id])->save();
    \App\Modules\Dropi\Models\DropiCorte::whereKey($corte->id)->update(['estado' => \App\Modules\Dropi\Enums\EstadoCorte::Cerrado->value]);
    expect($cola->pedidos()->pluck('id')->all())->not->toContain($enDropi->id);
});

it('en turno, con la cola vacía la estación abre sola el pedido apenas Aracely imprime; sin turno no toma nada', function () {
    $p = erPedido('ER-60', 'ER-VOL', '2026-11-24 09:00', impreso: false);

    $this->actingAs($this->empacador);
    $estacion = Livewire::test(EstacionEmpaque::class)
        ->call('siguientePedido')
        ->assertSet('pedidoActivoId', null)
        ->assertSet('enTurno', true);

    // Quien sólo mira la estación no se queda con el pedido.
    session()->flush(); // cada usuario tiene su sesión
    $this->actingAs($this->otro);
    $mirando = Livewire::test(EstacionEmpaque::class)->assertSet('enTurno', false);

    DropiPedido::whereKey($p->id)->update(['guia_impresa_at' => now(), 'orden_empaque' => 1]);
    $mirando->call('esperarPedido')->assertSet('pedidoActivoId', null);

    $this->actingAs($this->empacador);
    $estacion->call('esperarPedido')->assertSet('pedidoActivoId', $p->id);
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

it('con dos empacadores al tiempo nadie recibe ni toca el pedido que tiene el otro (25-nov-2026)', function () {
    $x = erPedido('ER-50', 'ER-VOL', '2026-11-24 09:00');
    $y = erPedido('ER-51', 'ER-VOL', '2026-11-24 09:05');
    $z = erPedido('ER-52', 'ER-VOL', '2026-11-24 09:10');

    $this->actingAs($this->empacador);
    $estacionA = Livewire::test(EstacionEmpaque::class)->call('siguientePedido')->assertSet('pedidoActivoId', $x->id);

    // B escanea la guía del pedido de A: se rechaza y no lo abre.
    $this->actingAs($this->otro);
    $estacionB = Livewire::test(EstacionEmpaque::class)->set('codigo', 'ER-50')->call('escanear')->assertSet('pedidoActivoId', null);
    expect($estacionB->get('ultimoResultado')['mensaje'])->toContain('otro operario');

    // B escanea el producto: le abre el libre que sigue, no el de A, y a A no le marca nada.
    $estacionB->set('codigo', 'ER-VOL')->call('escanear')->assertSet('pedidoActivoId', $y->id);
    expect((int) $x->items()->first()->cantidad_pickeada)->toBe(0)
        ->and((int) $y->items()->first()->cantidad_pickeada)->toBe(1)
        ->and(collect($estacionB->instance()->proximosPedidos())->pluck('id')->all())->toBe([$z->id]);

    // La «Cola de corte» de A tampoco muestra el que tiene B.
    $this->actingAs($this->empacador);
    expect(collect($estacionA->instance()->proximosPedidos())->pluck('id')->all())->toBe([$z->id]);

    // A termina y le llega el que queda libre.
    $estacionA->set('codigo', 'ER-VOL')->call('escanear')->call('confirmarYSiguiente')->assertSet('pedidoActivoId', $z->id);
    expect(EmpaqueRegistro::where('pedido_id', $y->id)->where('estado', 'en_curso')->value('operario_id'))->toBe($this->otro->id);
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

it('lo impreso sin stock en el ERP también llega a empaque, marcado; con «Exigir stock del ERP» no (la señora Lahida, 46:50)', function () {
    $p = erPedido('ER-70', 'ER-VOL', '2026-11-24 09:00');
    \Illuminate\Support\Facades\DB::table('dropi_pedidos')->where('id', $p->id)->update(['estado' => EstadoPedidoDropi::PendienteInventario->value]);

    expect(app(ColaDeEmpaque::class)->pedidos()->pluck('id')->all())->toBe([$p->id]);
    // Un escaneo que quedó de antes no cuenta al abrirlo.
    $p->items()->update(['cantidad_pickeada' => 1, 'pickeado_at' => now()]);

    $this->actingAs($this->empacador);
    Livewire::test(EstacionEmpaque::class)->call('siguientePedido')
        ->assertSet('pedidoActivoId', $p->id)
        ->assertSee('Sin stock en el ERP');
    expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Alistando)
        ->and($p->fresh()->abiertoSinStockErp())->toBeTrue()
        ->and($p->items()->first()->pickeado_at)->toBeNull();

    // Con el inventario completo se exige stock: ni entra a la cola ni se deja abrir.
    $q = erPedido('ER-71', 'ER-VOL', '2026-11-24 09:05');
    \Illuminate\Support\Facades\DB::table('dropi_pedidos')->where('id', $q->id)->update(['estado' => EstadoPedidoDropi::PendienteInventario->value]);
    Reglas::set('dropi.exigir_stock_erp', '1');
    expect(app(ColaDeEmpaque::class)->pedidos()->pluck('id')->all())->not->toContain($q->id)
        ->and(\App\Modules\Dropi\Actions\ProcesarEscaneoEmpaque::run(codigo: 'ER-71', pedidoActivoId: null, operarioId: $this->otro->id)['tipo'])->toBe('error');
});
