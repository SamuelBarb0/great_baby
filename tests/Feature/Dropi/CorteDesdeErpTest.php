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
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Services\ImprimirGuiasDropi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

    $generar = $this->mock(GenerarGuiaDropi::class);
    $generar->shouldReceive('handle')->once()->withArgs(fn (DropiPedido $p) => $p->id === $pendiente->id)->andReturn([]);

    GenerarGuiasEnBloqueJob::lanzar([$pendiente->id, $yaGenerada->id, $conGuia->id], 7);

    expect(GenerarGuiasEnBloqueJob::avance(7))->toMatchArray(['total' => 3, 'hechas' => 1, 'saltadas' => 2, 'errores' => []]);
});

it('si Dropi rechaza una guía, las demás siguen y el error queda en el avance', function () {
    $malo = cdePedido('201', 'PENDIENTE');
    $bueno = cdePedido('202', 'PENDIENTE');

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

it('ordena las guías para empacar: el producto con más pedidos primero, y dentro el más viejo', function () {
    $tapete = cdePedido('401', 'GUIA_GENERADA', 'TAPETE');
    $cel1 = cdePedido('402', 'GUIA_GENERADA', 'CELULAR');
    $cel2 = cdePedido('403', 'GUIA_GENERADA', 'CELULAR');
    DropiPedido::whereKey($cel2->id)->update(['created_at' => now()->subHour()]);

    $orden = ImprimirGuiasDropi::ordenar(DropiPedido::with('items')->get())->pluck('id')->all();

    expect($orden)->toBe([$cel2->id, $cel1->id, $tapete->id]);
});

it('«Imprimir guías» arma un solo PDF (lista + stickers), marca impresas y avisa lo que no tiene sticker', function () {
    $a = cdePedido('501', 'GUIA_GENERADA', cambios: ['guia' => 'G-501', 'sticker_path' => 'g/501.pdf']);
    $b = cdePedido('502', 'GUIA_GENERADA', cambios: ['guia' => 'G-502', 'sticker_path' => 'g/502.pdf']);
    $sinPublicar = cdePedido('503', 'GUIA_GENERADA', cambios: ['guia' => 'G-503', 'sticker_path' => 'g/503.pdf']);
    Http::fake([
        'stickers.test/g/501.pdf' => Http::response(cdeStickerPdf('501')),
        'stickers.test/g/502.pdf' => Http::response(cdeStickerPdf('502')),
        'stickers.test/*' => Http::response('', 404),
    ]);

    $r = app(ImprimirGuiasDropi::class)->generar([$a->id, $b->id, $sinPublicar->id], null);

    expect($r['pdf'])->toStartWith('%PDF')
        ->and($r['impresos'])->toBe(2)
        ->and($r['sin_sticker'])->toBe(['503'])
        // 1 página de lista + 2 stickers + 1 de «sin sticker».
        ->and(cdePaginas($r['pdf']))->toBe(4)
        ->and($a->fresh()->guia_impresa_at)->not->toBeNull()
        ->and($sinPublicar->fresh()->guia_impresa_at)->toBeNull();
});

it('la impresión sólo la abre quien cambia pedidos en Dropi, y vence', function () {
    $token = ImprimirGuiasDropi::preparar([1]);
    $this->actingAs(\App\Models\User::factory()->create());

    $this->get(route('dropi.guias.imprimir', $token))->assertForbidden();
});
