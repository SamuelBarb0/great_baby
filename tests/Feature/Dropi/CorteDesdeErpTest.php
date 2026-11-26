<?php

/**
 * El corte desde el ERP, como Aracely lo hace en Dropi (reunión con la señora
 * Lahida, 24-nov-2026): filtrar lo PENDIENTE en Dropi, «Generar guías» en
 * bloque e «Imprimir guías» en un solo PDF, sin repetir lo ya impreso.
 */

use App\Modules\Dropi\Actions\AsignarCorteACarga;
use App\Modules\Dropi\Actions\GenerarGuiaDropi;
use App\Modules\Dropi\Actions\SincronizarDropiWeb;
use App\Modules\Dropi\Jobs\GenerarGuiasEnBloqueJob;
use App\Modules\Dropi\Models\DropiLoteImpresion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Services\ImprimirGuiasDropi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('dropi.web.stickers_url', 'https://stickers.test');
    $this->corte = AsignarCorteACarga::run(CarbonImmutable::now());
});

/** Un pedido de Dropi con su estatus allá y su producto. */
function cdePedido(string $orden, ?string $enDropi, string $sku = 'TP', array $cambios = []): DropiPedido
{
    $p = DropiPedido::create($cambios + [
        'corte_id' => test()->corte->id, 'guia' => "SIN-GUIA-{$orden}", 'dropi_orden_id' => $orden,
        'cliente_nombre' => 'Ana', 'monto_esperado_proveedor' => 1000,
    ]);
    $p->items()->create(['sku_dropi' => $sku, 'producto_nombre' => "Producto {$sku}", 'cantidad' => 1, 'precio_proveedor_unit' => 1000]);
    if ($enDropi !== null) {
        DB::table('dropi_web_huellas')->insert(['dropi_orden_id' => $orden, 'huella' => 'x', 'estatus_dropi' => $enDropi]);
    }

    return $p;
}

/**
 * Lo que Dropi responde HOY por cada orden (26-nov-2026: el job ya no confía en
 * la huella): el estatus que dice la huella del pedido, o PENDIENTE.
 */
function cdeDropiDice(): void
{
    test()->partialMock(\App\Modules\Dropi\Clients\DropiWebClient::class, fn ($m) => $m->shouldReceive('pedidoDetalle')->andReturnUsing(fn (int $id) => [
        'id' => $id, 'name' => 'Ana', 'surname' => '', 'orderdetails' => [],
        'status' => DB::table('dropi_web_huellas')->where('dropi_orden_id', $id)->value('estatus_dropi') ?? 'PENDIENTE',
    ]));
}

/** Un PDF de una página, como el sticker que publica Dropi. */
function cdeStickerPdf(string $texto): string
{
    $pdf = new \setasign\Fpdi\Fpdi();
    $pdf->AddPage('P', [100, 150]);
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Cell(0, 10, $texto);

    return $pdf->Output('S');
}

function cdePaginas(string $pdf): int
{
    return (new \setasign\Fpdi\Fpdi())->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($pdf));
}

it('«Generar guías» sólo le pide guía a lo que en Dropi sigue PENDIENTE y deja el avance', function () {
    $pendiente = cdePedido('101', 'PENDIENTE');
    $yaGenerada = cdePedido('102', 'GUIA_GENERADA');
    $conGuia = cdePedido('103', 'PENDIENTE', cambios: ['guia' => '240099990001']);

    cdeDropiDice();
    $generar = $this->mock(GenerarGuiaDropi::class);
    // Al pendiente le pide guía; al que ya la tiene, sólo su sticker (entra al reporte).
    $generar->shouldReceive('handle')->twice()->withArgs(fn (DropiPedido $p) => in_array($p->id, [$pendiente->id, $conGuia->id], true))->andReturn([]);

    GenerarGuiasEnBloqueJob::lanzar([$pendiente->id, $yaGenerada->id, $conGuia->id], 7);

    expect(GenerarGuiasEnBloqueJob::avance(7))->toMatchArray(['total' => 3, 'hechas' => 2, 'saltadas' => 1, 'errores' => []]);
});

it('sin stock en el ERP también se genera la guía mientras no se exija; con la regla prendida se salta (la señora Lahida, 46:50)', function () {
    $conStock = cdePedido('111', 'PENDIENTE');
    $sinStock = cdePedido('112', 'PENDIENTE');
    DB::table('dropi_pedidos')->where('id', $sinStock->id)->update(['estado' => \App\Modules\Dropi\Enums\EstadoPedidoDropi::PendienteInventario->value]);

    // Mientras se carga el inventario del ERP: «que podamos hacer todo».
    cdeDropiDice();
    $generar = $this->mock(GenerarGuiaDropi::class);
    $generar->shouldReceive('handle')->twice()->andReturn([]);
    GenerarGuiasEnBloqueJob::lanzar([$conStock->id, $sinStock->id], 8);
    expect(GenerarGuiasEnBloqueJob::avance(8))->toMatchArray(['total' => 2, 'hechas' => 2, 'saltadas' => 0]);

    // Con el inventario completo, Aracely prende «Exigir stock del ERP».
    \App\Support\Reglas::set('dropi.exigir_stock_erp', '1');
    $generar = $this->mock(GenerarGuiaDropi::class);
    $generar->shouldReceive('handle')->once()->withArgs(fn (DropiPedido $p) => $p->id === $conStock->id)->andReturn([]);
    GenerarGuiasEnBloqueJob::lanzar([$conStock->id, $sinStock->id], 9);
    expect(GenerarGuiasEnBloqueJob::avance(9))->toMatchArray(['total' => 2, 'hechas' => 1, 'saltadas' => 1]);
});

it('si Dropi rechaza una guía, las demás siguen y el error queda en el avance', function () {
    $malo = cdePedido('201', 'PENDIENTE');
    $bueno = cdePedido('202', 'PENDIENTE');

    cdeDropiDice();
    $generar = $this->mock(GenerarGuiaDropi::class);
    $generar->shouldReceive('handle')->andReturnUsing(function (DropiPedido $p) use ($malo) {
        if ($p->id === $malo->id) {
            throw new RuntimeException('La dirección no tiene cobertura');
        }

        return [];
    });

    GenerarGuiasEnBloqueJob::lanzar([$malo->id, $bueno->id], 7);

    $a = GenerarGuiasEnBloqueJob::avance(7);
    expect($a['hechas'])->toBe(1)
        ->and($a['errores'])->toBe(['Orden 201: La dirección no tiene cobertura']);
});

it('«impresa» es la marca de Dropi o la del ERP; el sync guarda la de Dropi', function () {
    $dropi = cdePedido('301', 'GUIA_GENERADA');
    $erp = cdePedido('302', 'GUIA_GENERADA', cambios: ['guia_impresa_at' => now()]);
    $nada = cdePedido('303', 'GUIA_GENERADA');

    SincronizarDropiWeb::guardarGuiaDescargada([
        ['id' => 301, 'guide_was_downloaded' => true],
        ['id' => 303, 'guide_was_downloaded' => false],
    ]);

    expect(DropiPedido::query()->guiaImpresa()->pluck('id')->sort()->values()->all())->toBe([$dropi->id, $erp->id])
        ->and(DropiPedido::query()->guiaImpresa(false)->pluck('id')->all())->toBe([$nada->id])
        ->and($dropi->fresh()->tieneGuiaImpresa())->toBeTrue();
});

it('la lista para bodega usa el nombre de Dropi cuando el producto no está vinculado al ERP', function () {
    cdePedido('501', 'GUIA_GENERADA', 'DV-1799321');

    $fila = collect(app(\App\Modules\Dropi\Support\RecoleccionDelCorte::class)->totalesDe(DropiPedido::with('items.variante.producto', 'items.producto')->get()))->first();
    expect($fila['producto'])->toBe('Producto DV-1799321')->and($fila['vinculado'])->toBeFalse();

    $html = view('dropi.pdf.recoleccion', ['titulo' => 'Recolección', 'filas' => [$fila + ['marcada' => false, 'faltan' => 0]],
        'corte' => null, 'generado' => now(), 'unidades' => 1])->render();
    expect($html)->toContain('Producto DV-1799321')->toContain('sin vincular al ERP')->not->toContain('SKU sin match');
});

it('ordena las guías para empacar: el producto con más pedidos primero, y dentro el más viejo', function () {
    $tapete = cdePedido('401', 'GUIA_GENERADA', 'TAPETE');
    $cel1 = cdePedido('402', 'GUIA_GENERADA', 'CELULAR');
    $cel2 = cdePedido('403', 'GUIA_GENERADA', 'CELULAR');
    DropiPedido::whereKey($cel2->id)->update(['created_at' => now()->subHour()]);

    $orden = ImprimirGuiasDropi::ordenar(DropiPedido::with('items')->get())->pluck('id')->all();

    expect($orden)->toBe([$cel2->id, $cel1->id, $tapete->id]);
});

it('la pila va de mayor a menor cantidad y los colores de un estilo quedan seguidos (la señora Lahida, 26-nov-2026)', function () {
    $carrito = \App\Modules\Dropi\Models\Producto::create(['referencia' => 'CAR-1', 'nombre' => 'Carrito', 'desglose_stock' => true]);
    $rojo = \App\Modules\Dropi\Models\ProductoVariante::create(['producto_id' => $carrito->id, 'color_codigo' => 'ROJ', 'color_nombre' => 'Rojo', 'codigo_barras' => 'CAR-1-ROJ']);
    $azul = \App\Modules\Dropi\Models\ProductoVariante::create(['producto_id' => $carrito->id, 'color_codigo' => 'AZU', 'color_nombre' => 'Azul', 'codigo_barras' => 'CAR-1-AZU']);
    $deColor = function (string $orden, int $varianteId, int $cantidad = 1) {
        $p = cdePedido($orden, 'GUIA_GENERADA', "SKU-{$varianteId}");
        $p->items()->update(['variante_id' => $varianteId, 'cantidad' => $cantidad]);

        return $p;
    };
    // El tapete suma 3 unidades en un pedido; el carrito, 4 entre rojos y azules.
    $tapete = cdePedido('411', 'GUIA_GENERADA', 'TAPETE');
    $tapete->items()->update(['cantidad' => 3]);
    $azul1 = $deColor('412', $azul->id);
    $rojo1 = $deColor('413', $rojo->id);
    $rojo2 = $deColor('414', $rojo->id, 2);

    $orden = ImprimirGuiasDropi::ordenar(DropiPedido::with('items')->get())->pluck('id')->all();

    // Carrito (4) antes que tapete (3); dentro, el rojo (3) antes que el azul (1), seguidos.
    expect($orden)->toBe([$rojo1->id, $rojo2->id, $azul1->id, $tapete->id]);
});

it('«Imprimir guías» arma un lote en segundo plano (lista + stickers), marca impresas y avisa lo que no tiene sticker', function () {
    Storage::fake('local');
    $a = cdePedido('501', 'GUIA_GENERADA', cambios: ['guia' => 'G-501', 'sticker_path' => 'g/501.pdf']);
    $b = cdePedido('502', 'GUIA_GENERADA', cambios: ['guia' => 'G-502', 'sticker_path' => 'g/502.pdf']);
    $sinPublicar = cdePedido('503', 'GUIA_GENERADA', cambios: ['guia' => 'G-503', 'sticker_path' => 'g/503.pdf']);
    $sinGuia = cdePedido('504', 'PENDIENTE');
    Http::fake([
        'stickers.test/g/501.pdf' => Http::response(cdeStickerPdf('501')),
        'stickers.test/g/502.pdf' => Http::response(cdeStickerPdf('502')),
        'stickers.test/*' => Http::response('', 404),
    ]);

    $lote = app(ImprimirGuiasDropi::class)->lanzar([$a->id, $b->id, $sinPublicar->id, $sinGuia->id], null)->fresh();

    expect($lote->estado)->toBe(DropiLoteImpresion::LISTO)
        ->and($lote->total)->toBe(3) // los que tienen guía; el 504 no entra a la pila
        ->and($lote->sin_sticker)->toBe(['504', '503']) // el 504 no tenía guía; el 503, sticker
        ->and($lote->partes)->toHaveCount(1)
        // 1 página de lista + 2 stickers + 1 de «sin sticker».
        ->and(cdePaginas(Storage::disk('local')->get($lote->partes[0])))->toBe(4)
        ->and($a->fresh()->guia_impresa_at)->not->toBeNull()
        ->and($a->fresh()->lote_impresion_id)->toBe($lote->id)
        ->and($sinPublicar->fresh()->guia_impresa_at)->toBeNull();
});

it('un reporte masivo sale en un solo PDF, en el orden de la pila («que haga un montón y ya»)', function () {
    Storage::fake('local');
    Http::fake(['stickers.test/*' => Http::response(cdeStickerPdf('x'))]);
    $ids = [];
    foreach (range(1, 205) as $i) {
        $ids[] = cdePedido((string) (7000 + $i), 'GUIA_GENERADA', cambios: ['guia' => "G-{$i}", 'sticker_path' => "g/{$i}.pdf"])->id;
    }

    $lote = app(ImprimirGuiasDropi::class)->lanzar($ids, null)->fresh();

    expect($lote->estado)->toBe(DropiLoteImpresion::LISTO)
        ->and($lote->partes)->toHaveCount(1)
        ->and(cdePaginas(Storage::disk('local')->get($lote->partes[0])))->toBe(1 + 205) // la hoja abre el PDF
        ->and(DropiPedido::whereIn('id', $ids)->orderBy('orden_empaque')->pluck('id')->all())->toBe($lote->pedidos);
});

it('las partes del lote sólo las baja quien cambia pedidos en Dropi', function () {
    Storage::fake('local');
    Http::fake(['stickers.test/*' => Http::response(cdeStickerPdf('x'))]);
    $p = cdePedido('601', 'GUIA_GENERADA', cambios: ['guia' => 'G-601', 'sticker_path' => 'g/601.pdf']);
    $lote = app(ImprimirGuiasDropi::class)->lanzar([$p->id], null);

    $this->actingAs(\App\Models\User::factory()->create());
    $this->get(route('dropi.guias.parte', [$lote, 1]))->assertForbidden();
});

/*
 * «Generar corte» (25-nov-2026): el corte nace cuando Aracely genera; la hora
 * de ese momento dice si es el Corte 1 o el 2, y de ahí sale el reporte.
 */

/** GenerarGuiaDropi falso: Dropi asigna la guía y su sticker. */
function cdeGuiasDeDropi(): void
{
    cdeDropiDice();
    test()->mock(GenerarGuiaDropi::class)->shouldReceive('handle')->andReturnUsing(function (DropiPedido $p) {
        $p->forceFill(['guia' => "G-{$p->dropi_orden_id}", 'sticker_path' => "g/{$p->dropi_orden_id}.pdf"])->save();

        return [];
    });
    Http::fake(['stickers.test/*' => Http::response(cdeStickerPdf('x'))]);
}

it('«Generar corte» numera el corte por la hora en que Aracely lo genera, pide las guías y saca el reporte', function () {
    Storage::fake('local');
    cdeGuiasDeDropi();
    $sinCorte = ['corte_id' => null];
    $pendiente = cdePedido('801', 'PENDIENTE', cambios: $sinCorte);
    $yaConGuia = cdePedido('802', 'GUIA_GENERADA', cambios: $sinCorte + ['guia' => 'G-802', 'sticker_path' => 'g/802.pdf']);
    $cancelado = cdePedido('803', 'CANCELADO', cambios: $sinCorte);

    $r = \App\Modules\Dropi\Actions\GenerarCorteDropi::run([$pendiente->id, $yaConGuia->id, $cancelado->id], null, CarbonImmutable::parse('2026-11-25 09:00'));

    $reporte = $r['reporte']->fresh();
    expect($r['corte']->numero)->toBe(1)
        ->and($r['corte']->fecha->toDateString())->toBe('2026-11-25')
        ->and($r['a_guia'])->toBe(1)->and($r['con_guia'])->toBe(1)->and($r['saltados'])->toBe(1)
        ->and($pendiente->fresh()->corte_id)->toBe($r['corte']->id)
        ->and($yaConGuia->fresh()->corte_id)->toBe($r['corte']->id)
        ->and($cancelado->fresh()->corte_id)->toBeNull()
        // Terminaron las guías → salió el reporte en un solo PDF: hoja + 2 stickers.
        ->and($reporte->estado)->toBe(DropiLoteImpresion::LISTO)
        ->and($reporte->partes)->toHaveCount(1)
        ->and(cdePaginas(Storage::disk('local')->get($reporte->partes[0])))->toBe(3)
        ->and($pendiente->fresh()->guia_impresa_at)->not->toBeNull()
        ->and($pendiente->fresh()->lote_impresion_id)->toBe($reporte->id);
});

it('otra vez en la misma franja suma al mismo corte con su propio reporte; después de las 2 p. m. es el Corte 2', function () {
    Storage::fake('local');
    cdeGuiasDeDropi();
    $a = cdePedido('811', 'PENDIENTE', cambios: ['corte_id' => null]);
    $b = cdePedido('812', 'PENDIENTE', cambios: ['corte_id' => null]);
    $c = cdePedido('813', 'PENDIENTE', cambios: ['corte_id' => null]);

    $nueve = \App\Modules\Dropi\Actions\GenerarCorteDropi::run([$a->id], null, CarbonImmutable::parse('2026-11-25 09:00'));
    $once = \App\Modules\Dropi\Actions\GenerarCorteDropi::run([$b->id, $a->id], null, CarbonImmutable::parse('2026-11-25 11:00'));
    $tres = \App\Modules\Dropi\Actions\GenerarCorteDropi::run([$c->id], null, CarbonImmutable::parse('2026-11-25 15:00'));

    expect($once['corte']->id)->toBe($nueve['corte']->id)
        ->and($once['reporte']->id)->not->toBe($nueve['reporte']->id)
        ->and($once['saltados'])->toBe(1) // el de las 9 ya estaba en un reporte
        ->and($once['reporte']->fresh()->pedidos)->toBe([$b->id])
        ->and($tres['corte']->numero)->toBe(2);
});

it('si Dropi rechaza una guía, el reporte sale igual con lo demás y el rechazado queda «sin guía»', function () {
    Storage::fake('local');
    Http::fake(['stickers.test/*' => Http::response(cdeStickerPdf('x'))]);
    $bien = cdePedido('821', 'PENDIENTE', cambios: ['corte_id' => null]);
    $mal = cdePedido('822', 'PENDIENTE', cambios: ['corte_id' => null]);
    cdeDropiDice();
    $this->mock(GenerarGuiaDropi::class)->shouldReceive('handle')->andReturnUsing(function (DropiPedido $p) {
        throw_if($p->dropi_orden_id === '822', new \RuntimeException('La dirección no tiene cobertura'));
        $p->forceFill(['guia' => 'G-821', 'sticker_path' => 'g/821.pdf'])->save();

        return [];
    });

    $r = \App\Modules\Dropi\Actions\GenerarCorteDropi::run([$bien->id, $mal->id], null, CarbonImmutable::parse('2026-11-25 09:00'));

    $reporte = $r['reporte']->fresh();
    expect($reporte->estado)->toBe(DropiLoteImpresion::LISTO)
        ->and($reporte->pedidos)->toBe([$bien->id])
        ->and($reporte->sin_sticker)->toBe(['822'])
        ->and($mal->fresh()->lote_impresion_id)->toBeNull(); // se puede volver a generar
});

it('el pedido que llega por despachar nace sin corte; el que ya llega despachado entra al corte de su hora', function () {
    $dto = fn (string $orden, string $estado) => new \App\Modules\Dropi\DTOs\PedidoDropiDTO(
        guia: "G-{$orden}", dropiOrdenId: $orden, transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: $estado,
        montoEsperadoProveedor: 30000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new \App\Modules\Dropi\DTOs\ItemDropiDTO('TP', 1, 30000, 'x', null)],
        creadoAt: CarbonImmutable::parse('2026-11-25 08:00'),
    );
    app(\App\Modules\Dropi\Actions\SincronizarPedidosDropi::class)->importarPedidos([$dto('901', 'PENDIENTE'), $dto('902', 'ENTREGADO')]);

    expect(DropiPedido::where('dropi_orden_id', '901')->value('corte_id'))->toBeNull()
        ->and(DropiPedido::where('dropi_orden_id', '902')->value('corte_id'))->not->toBeNull();
});

it('lo empacado o despachado sin corte sale como alerta en Cortes y entra al corte abierto con un clic', function () {
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    $aracely = \App\Models\User::factory()->create();
    $aracely->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $p = cdePedido('831', 'DESPACHADA', cambios: ['corte_id' => null]);
    DB::table('dropi_pedidos')->where('id', $p->id)->update(['estado' => \App\Modules\Dropi\Enums\EstadoPedidoDropi::Despachado->value]);
    $this->actingAs($aracely);
    \Filament\Facades\Filament::setCurrentPanel('admin');

    \Livewire\Livewire::test(\App\Modules\Dropi\Filament\Resources\DropiCorteResource\Pages\ListDropiCortes::class)
        ->assertSee('1 pedido(s) empacados o despachados sin corte')
        ->callAction('meterSinCorte');

    expect($p->fresh()->corte_id)->not->toBeNull();
});
