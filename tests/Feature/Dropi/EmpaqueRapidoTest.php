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

    // La estación sólo trabaja el corte puesto en el alistador (27-nov-2026):
    //   el del último reporte que generó Aracely. Los pedidos de prueba van ahí.
    $this->corteEnTrabajo = \App\Modules\Dropi\Models\DropiCorte::create(['fecha' => '2026-11-20', 'numero' => 1]);
    \App\Modules\Dropi\Models\DropiLoteImpresion::create([
        'corte_id' => $this->corteEnTrabajo->id, 'pedidos' => [], 'total' => 0, 'guias_pendientes' => 0,
        'estado' => \App\Modules\Dropi\Models\DropiLoteImpresion::LISTO,
    ]);
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
    DropiPedido::whereKey($p->id)->update(['corte_id' => test()->corteEnTrabajo->id]);
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

it('la estación sólo da los pedidos del corte puesto en el alistador: los de cortes anteriores no se cuelan (27-nov-2026)', function () {
    $viejo = erPedido('ER-80', 'ER-VOL', '2026-11-24 08:00');
    $deHoy1 = erPedido('ER-81', 'ER-VOL', '2026-11-24 09:00');
    $deHoy2 = erPedido('ER-82', 'ER-TAP', '2026-11-24 09:30');

    // Aracely genera el corte de hoy: su reporte es el último, el alistador lo pone.
    $hoy = \App\Modules\Dropi\Models\DropiCorte::create(['fecha' => '2026-11-21', 'numero' => 1]);
    \App\Modules\Dropi\Models\DropiLoteImpresion::create([
        'corte_id' => $hoy->id, 'pedidos' => [], 'total' => 0, 'guias_pendientes' => 0,
        'estado' => \App\Modules\Dropi\Models\DropiLoteImpresion::LISTO,
    ]);
    DropiPedido::whereIn('id', [$deHoy1->id, $deHoy2->id])->update(['corte_id' => $hoy->id]);

    $cola = app(ColaDeEmpaque::class);
    expect(app(\App\Modules\Dropi\Support\RecoleccionDelCorte::class)->cortesEnTrabajo()->first()?->id)->toBe($hoy->id)
        // Qué pedidos, no en qué orden: con un pedido por producto, el empate de la pila depende de los ids.
        ->and($cola->pedidos()->pluck('id')->sort()->values()->all())->toBe([$deHoy1->id, $deHoy2->id])
        ->and($cola->contar())->toBe(2)
        ->and($cola->siguienteCon($this->empacador->id, null, Producto::where('referencia', 'ER-VOL')->value('id'))?->id)->toBe($deHoy1->id);

    // «Siguiente pedido» abre el primero de esa cola, nunca el del corte anterior.
    $primero = $cola->pedidos()->first()->id;
    $this->actingAs($this->empacador);
    Livewire::test(EstacionEmpaque::class)->call('siguientePedido')->assertSet('pedidoActivoId', $primero)
        ->call('siguientePedido'); // con uno abierto no toma otro

    expect(EmpaqueRegistro::where('pedido_id', $viejo->id)->exists())->toBeFalse();
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

it('el código corto de la etiqueta (GBP/GBV) abre el pedido y marca el producto, como su código real (26-nov-2026)', function () {
    $tapete = erPedido('ER-35', 'ER-TAP', '2026-11-24 09:00');
    $producto = Producto::where('referencia', 'ER-TAP')->firstOrFail();

    $this->actingAs($this->empacador);
    Livewire::test(EstacionEmpaque::class)
        ->set('codigo', 'gbp' . $producto->id)
        ->call('escanear')
        ->assertSet('pedidoActivoId', $tapete->id);

    expect((int) $tapete->items()->first()->cantidad_pickeada)->toBe(1);

    // El de una variante también, y uno que no existe no se confunde con nada.
    $body = Producto::create(['referencia' => 'ER-BODY', 'nombre' => 'Body', 'desglose_stock' => true, 'activo' => true]);
    $azul = \App\Modules\Dropi\Models\ProductoVariante::create(['producto_id' => $body->id, 'color_nombre' => 'Azul', 'codigo_barras' => 'AND2507-94/800-05ELF-18M']);
    expect(\App\Modules\Dropi\Actions\ProcesarEscaneoEmpaque::productoDelCodigo('GBV' . $azul->id)[0]?->id)->toBe($azul->id)
        ->and(\App\Modules\Dropi\Actions\ProcesarEscaneoEmpaque::productoDelCodigo('GBV999999'))->toBe([null, null])
        ->and(\App\Modules\Dropi\Actions\ProcesarEscaneoEmpaque::productoDelCodigo('GBP' . $body->id))->toBe([null, null]);
});

it('el código corto numérico de la etiqueta (1/2 + id) abre el pedido y marca el producto; el código real gana si coinciden (27-nov-2026)', function () {
    $tapete = erPedido('ER-36', 'ER-TAP', '2026-11-24 09:00');
    $producto = Producto::where('referencia', 'ER-TAP')->firstOrFail();

    $this->actingAs($this->empacador);
    Livewire::test(EstacionEmpaque::class)
        ->set('codigo', \App\Support\CodigoCortoEtiqueta::deProducto($producto->id))
        ->call('escanear')
        ->assertSet('pedidoActivoId', $tapete->id);

    expect((int) $tapete->items()->first()->cantidad_pickeada)->toBe(1);

    $body = Producto::create(['referencia' => 'ER-BODY', 'nombre' => 'Body', 'desglose_stock' => true, 'activo' => true]);
    $azul = \App\Modules\Dropi\Models\ProductoVariante::create(['producto_id' => $body->id, 'color_nombre' => 'Azul', 'codigo_barras' => 'AND2505-917']);
    $corto = \App\Support\CodigoCortoEtiqueta::deVariante($azul->id);
    expect($corto)->toMatch('/^1\d{7}$/')
        ->and(\App\Modules\Dropi\Actions\ProcesarEscaneoEmpaque::productoDelCodigo($corto)[0]?->id)->toBe($azul->id)
        ->and(\App\Modules\Dropi\Actions\ProcesarEscaneoEmpaque::productoDelCodigo('19999999'))->toBe([null, null])
        ->and(\App\Modules\Dropi\Actions\ProcesarEscaneoEmpaque::productoDelCodigo(\App\Support\CodigoCortoEtiqueta::deProducto($body->id)))->toBe([null, null]);

    // Una variante cuyo código real es igual a un corto: se queda con el real.
    $pijama = Producto::create(['referencia' => 'ER-PIJ', 'nombre' => 'Pijama', 'desglose_stock' => true, 'activo' => true]);
    $rosa = \App\Modules\Dropi\Models\ProductoVariante::create(['producto_id' => $pijama->id, 'color_nombre' => 'Rosa', 'codigo_barras' => $corto === '10000001' ? '10000002' : '10000001']);
    expect(\App\Modules\Dropi\Actions\ProcesarEscaneoEmpaque::productoDelCodigo($rosa->codigo_barras)[0]?->id)->toBe($rosa->id);
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

it('al escanear lo último sale el sticker desde el ERP y, sin foto obligatoria, se confirma solo (la señora Lahida, 25-nov-2026)', function () {
    $p = erPedido('ER-60', 'ER-VOL', '2026-11-24 09:00');
    DropiPedido::whereKey($p->id)->update(['sticker_path' => 'colombia/guias/er-60.pdf']);

    $this->actingAs($this->empacador);
    Livewire::test(EstacionEmpaque::class)
        ->set('codigo', 'ER-VOL')->call('escanear')
        ->assertSet('pedidoActivoId', $p->id)
        ->assertDispatched('empaque-completo', sticker: route('dropi.pedido.sticker', $p), confirmar: true);

    // Con foto obligatoria sale el sticker, pero se espera la foto para confirmar.
    Reglas::set('empaque.foto_obligatoria', '1');
    $q = erPedido('ER-61', 'ER-TAP', '2026-11-24 09:10');
    $this->actingAs($this->otro);
    Livewire::test(EstacionEmpaque::class)
        ->set('codigo', 'ER-TAP')->call('escanear')
        ->assertDispatched('empaque-completo', sticker: null, confirmar: false);
    expect($q->fresh()->estado)->toBe(EstadoPedidoDropi::Alistando);
});

it('mientras falte algo del pedido no se imprime ni se confirma', function () {
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: 'ER-70', dropiOrdenId: 'O-ER-70', transportadora: 'X', tienda: 'T', vendedorNombre: 'Vendedora',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: 'CALI', clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 60000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO('SKU-ER-VOL', 1, 30000, 'x', 'x'), new ItemDropiDTO('SKU-ER-TAP', 1, 30000, 'x', 'x')],
        creadoAt: CarbonImmutable::parse('2026-11-24 09:00'),
    )]);
    $p = DropiPedido::where('guia', 'ER-70')->firstOrFail();
    $p->estado = EstadoPedidoDropi::Pending;
    $p->save();
    DropiPedido::whereKey($p->id)->update(['guia_impresa_at' => now(), 'sticker_path' => 'colombia/guias/er-70.pdf', 'corte_id' => $this->corteEnTrabajo->id]);

    $this->actingAs($this->empacador);
    $lw = Livewire::test(EstacionEmpaque::class)
        ->set('codigo', 'ER-VOL')->call('escanear')
        ->assertSet('pedidoActivoId', $p->id)
        ->assertNotDispatched('empaque-completo');

    $lw->set('codigo', 'ER-TAP')->call('escanear')
        ->assertDispatched('empaque-completo', confirmar: true);
});

it('«Confirmar empaque» da por empacado lo que falte, imprime la guía y abre el siguiente (27-nov-2026)', function () {
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: 'ER-90', dropiOrdenId: 'O-ER-90', transportadora: 'X', tienda: 'T', vendedorNombre: 'Vendedora',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: 'CALI', clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 60000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO('SKU-ER-VOL', 1, 30000, 'x', 'x'), new ItemDropiDTO('SKU-ER-TAP', 2, 30000, 'x', 'x')],
        creadoAt: CarbonImmutable::parse('2026-11-24 08:00'),
    )]);
    $p = DropiPedido::where('guia', 'ER-90')->firstOrFail();
    $p->estado = EstadoPedidoDropi::Pending;
    $p->save();
    DropiPedido::whereKey($p->id)->update(['guia_impresa_at' => now(), 'sticker_path' => 'colombia/guias/er-90.pdf', 'corte_id' => $this->corteEnTrabajo->id]);
    $siguiente = erPedido('ER-91', 'ER-VOL', '2026-11-24 09:00');

    $this->actingAs($this->empacador);
    Livewire::test(EstacionEmpaque::class)
        ->set('codigo', 'ER-90')->call('escanear')->assertSet('pedidoActivoId', $p->id)
        ->set('codigo', 'ER-VOL')->call('escanear')          // falta escanear los 2 tapetes
        ->call('confirmarYSiguiente')
        ->assertDispatched('empaque-imprimir', sticker: route('dropi.pedido.sticker', $p))
        ->assertSet('pedidoActivoId', $siguiente->id);

    $p->refresh()->load('items');
    $registro = EmpaqueRegistro::where('pedido_id', $p->id)->where('estado', 'completado')->firstOrFail();
    $bitacora = \Illuminate\Support\Facades\DB::table('dropi_estados_bitacora')->where('pedido_id', $p->id)
        ->where('estado_hasta', EstadoPedidoDropi::Empacado->value)->value('payload');

    expect($p->estado)->toBe(EstadoPedidoDropi::Empacado)
        ->and($p->items->every(fn ($it) => (int) $it->cantidad_pickeada === (int) $it->cantidad && $it->pickeado_at))->toBeTrue()
        // Rastro: 1 de 3 unidades escaneada, 2 confirmadas a mano.
        ->and($registro->items_escaneados)->toBe(1)->and($registro->items_totales)->toBe(3)
        ->and(json_decode($bitacora, true)['confirmado_a_mano'] ?? null)->toBe(2);
});

it('«Dejar para después» manda el pedido al final de la fila y abre el siguiente (27-nov-2026)', function () {
    $a = erPedido('ER-A1', 'ER-VOL', '2026-11-24 08:00');
    $b = erPedido('ER-A2', 'ER-TAP', '2026-11-24 08:10');
    $c = erPedido('ER-A3', 'ER-VOL', '2026-11-24 08:20');
    foreach ([$a, $b, $c] as $i => $p) {
        DropiPedido::whereKey($p->id)->update(['orden_empaque' => 100 + $i]);
    }

    $this->actingAs($this->empacador);
    $lw = Livewire::test(EstacionEmpaque::class)
        ->call('siguientePedido')->assertSet('pedidoActivoId', $a->id)
        ->set('codigo', 'ER-VOL')->call('escanear')   // alcanzó a escanear algo
        ->call('dejarParaDespues')
        ->assertSet('pedidoActivoId', $b->id);       // sigue con el siguiente

    expect(app(ColaDeEmpaque::class)->pedidos($this->otro->id)->pluck('id')->all())->toBe([$c->id, $a->id])
        ->and(EmpaqueRegistro::where('pedido_id', $a->id)->value('estado')?->value ?? EmpaqueRegistro::where('pedido_id', $a->id)->value('estado'))->toBe('anulado')
        ->and((int) $a->items()->sum('cantidad_pickeada'))->toBe(0);

    // Vuelve a salir al final: o antes, escaneando su guía.
    $lw->call('cancelarPedido')->set('codigo', 'ER-A1')->call('escanear')->assertSet('pedidoActivoId', $a->id);
});

it('con todo escaneado, confirmar no vuelve a imprimir la guía (ya salió con el último escaneo)', function () {
    $p = erPedido('ER-95', 'ER-VOL', '2026-11-24 09:00');
    DropiPedido::whereKey($p->id)->update(['sticker_path' => 'colombia/guias/er-95.pdf']);

    $this->actingAs($this->empacador);
    Livewire::test(EstacionEmpaque::class)
        ->set('codigo', 'ER-95')->call('escanear')
        ->set('codigo', 'ER-VOL')->call('escanear')
        ->call('confirmarYSiguiente')
        ->assertNotDispatched('empaque-imprimir');

    expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Empacado)
        ->and(EmpaqueRegistro::where('pedido_id', $p->id)->value('items_escaneados'))->toBe(1);
});

it('el sticker de la guía se sirve desde el ERP, se baja una sola vez y sólo para quien empaca', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    Http::fake(['*' => Http::response('%PDF-1.4 sticker', 200)]);
    $p = erPedido('ER-80', 'ER-VOL', '2026-11-24 09:00');
    $sin = erPedido('ER-81', 'ER-TAP', '2026-11-24 09:05');
    DropiPedido::whereKey($p->id)->update(['sticker_path' => 'colombia/guias/er-80.pdf']);

    $this->actingAs($this->empacador);
    $this->get(route('dropi.pedido.sticker', $p))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        // La estación lo enmarca; el resto del ERP sigue sin dejarse enmarcar.
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $this->get(route('dropi.pedido.sticker', $p))->assertOk();
    Http::assertSentCount(1);
    Http::assertSent(fn ($req) => str_ends_with($req->url(), '/colombia/guias/er-80.pdf'));

    $this->get(route('dropi.pedido.sticker', $sin))->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');

    $this->actingAs(User::factory()->create());
    $this->get(route('dropi.pedido.sticker', $p))->assertForbidden();
});

it('si Dropi todavía no publica el sticker no se guarda nada y se puede reintentar', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    Http::fake(['*' => Http::sequence()->push('<Error>NoSuchKey</Error>', 403)->push('%PDF-1.4 ya', 200)]);
    $p = erPedido('ER-90', 'ER-VOL', '2026-11-24 09:00');
    DropiPedido::whereKey($p->id)->update(['sticker_path' => 'colombia/guias/er-90.pdf']);

    $this->actingAs($this->empacador);
    $this->get(route('dropi.pedido.sticker', $p))->assertNotFound();
    $this->get(route('dropi.pedido.sticker', $p))->assertOk();
});
