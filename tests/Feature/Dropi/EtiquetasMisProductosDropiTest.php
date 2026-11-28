<?php

/**
 * «Mis productos en Dropi» imprime las etiquetas con código de barras de cada
 * producto. La etiqueta lleva el código del ERP vinculado (variante o referencia
 * del agregado), que es lo que reconoce la pistola de empaque; sin vínculo no
 * hay qué imprimir.
 */

use App\Models\User;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\Filament\Pages\MisProductosDropi;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Dropi\Services\CatalogoDropi;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function etqDropiUsuario(string $rol): User
{
    $u = User::factory()->create();
    $u->assignRole(Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']));

    return $u;
}

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Queue::fake();
    Storage::fake('local');
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    $this->aracely = etqDropiUsuario('Aracely');
    Filament::setCurrentPanel('admin');

    // 1 · variable con 2 variaciones: una vinculada a una variante, otra sin vincular.
    // 2 · simple vinculado a un producto agregado.  3 · sin vincular.
    Storage::disk('local')->put(CatalogoDropi::FOTO, json_encode([
        'leido_at' => now()->toIso8601String(),
        'productos' => array_map(fn ($p) => CatalogoDropi::resumir($p), [
            ['id' => 1, 'name' => 'Body león', 'type' => 'VARIABLE', 'sku' => null, 'warehouse_product' => [],
             'variations' => [
                 ['id' => 501, 'sale_price' => '12500', 'attribute_values' => [['value' => 'Azul 6M']], 'warehouse_product_variation' => [['stock' => 4]]],
                 ['id' => 502, 'sale_price' => '12500', 'attribute_values' => [['value' => 'Rosa 6M']], 'warehouse_product_variation' => [['stock' => 2]]],
             ]],
            ['id' => 2, 'name' => 'Volante', 'type' => 'SIMPLE', 'sku' => 'VOL899', 'sale_price' => '30000',
             'variations' => [], 'warehouse_product' => [['stock' => 9]]],
            ['id' => 3, 'name' => 'Casco', 'type' => 'SIMPLE', 'sku' => 'CASCO', 'sale_price' => '45000',
             'variations' => [], 'warehouse_product' => [['stock' => 1]]],
        ]),
    ]));

    $body = Producto::create(['referencia' => 'AND2512-79/154', 'nombre' => 'Body león manga larga', 'desglose_stock' => true]);
    $this->variante = ProductoVariante::create(['producto_id' => $body->id, 'color_nombre' => 'azul', 'talla' => '6M', 'codigo_barras' => 'AND2512-79/154-02LEÓ-6M']);
    $volante = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => '899', 'nombre' => 'VOLANTE CON SONIDO', 'desglose_stock' => false]));

    $llaves = collect(app(CatalogoDropi::class)->misProductos()['productos'])
        ->mapWithKeys(fn ($p) => [$p['id'] => array_column($p['variaciones'], 'llave')]);
    VincularProductoDropi::run($llaves[1][0], $this->variante->id);
    VincularProductoDropi::make()->aProducto($llaves[2][0], $volante->id);
});

it('la tarjeta ofrece las etiquetas sólo si hay algo vinculado al ERP', function () {
    $this->actingAs($this->aracely);

    Livewire::test(MisProductosDropi::class)
        ->assertSee('Imprimir etiquetas')
        ->assertSee('Falta en parte (1 de 2)')
        ->assertSee("mountAction('imprimirEtiquetas'", false)                    // el 1: un solo producto del ERP, la ventana
        ->assertDontSee(route('catalogo.etiquetas.dropi', 3), false)
        ->assertSee('Solo salen las variaciones vinculadas al ERP.')                 // el 1 está vinculado en parte
        ->assertSee('Para imprimir su etiqueta hay que vincularlo con el ERP.')    // el 3
        ->assertSee('Vincular →');
});

it('el PDF lleva el código del ERP: el de la variante o la referencia del agregado', function () {
    $this->actingAs($this->aracely);

    $vistas = [];
    $impresos = [];
    $textos = [];
    $conQr = [];
    \Illuminate\Support\Facades\View::composer('catalogo.pdf.etiquetas-dropi', function ($v) use (&$vistas, &$impresos, &$textos, &$conQr) {
        $vistas[] = array_column($v->getData()['etiquetas'], 'codigo');
        $impresos[] = array_column($v->getData()['etiquetas'], 'impreso');
        $textos[] = array_column($v->getData()['etiquetas'], 'texto');
        $conQr[] = collect($v->getData()['etiquetas'])->contains(fn ($e) => isset($e['qr']));
    });

    $pdf = $this->get(route('catalogo.etiquetas.dropi', 1))->assertOk()->assertHeader('content-type', 'application/pdf');
    // Cada página es una fila completa del rollo de la SAT TT460: 100 × 25 mm
    // (3 columnas de 32,49 mm sin separación, como la plantilla de BarTender de la bodega).
    expect($pdf->getContent())->toContain('/MediaBox [0.000 0.000 283.465 70.866]');
    $this->get(route('catalogo.etiquetas.dropi', 2))->assertOk();

    // La variación sin vincular (Rosa 6M) no sale; la vinculada lleva el código de la variante.
    expect($vistas[0])->toBe(['AND2512-79/154-02LEÓ-6M'])
        ->and($vistas[1])->toBe(['899'])
        // Las de Dropi llevan el nombre y el código de barras: ni QR se genera.
        ->and($conQr)->toBe([false, false]);

    // En las barras: el de la variante (23 letras) no cabe con rayas de 2 puntos y
    // va su código corto numérico, que la estación de empaque también reconoce;
    // «899» sí cabe. Debajo, el real cuando entra en el renglón (no es el caso).
    $corto = \App\Support\CodigoCortoEtiqueta::deVariante($this->variante->id);
    expect($impresos)->toBe([[$corto], ['899']])
        ->and($textos)->toBe([[$corto], ['899']]);
});

it('las etiquetas de Dropi llevan el nombre y el código de barras; las del catálogo siguen completas', function () {
    $rollo = ['ancho' => 100, 'alto' => 25, 'columnas' => 3, 'etiqueta' => 32.49, 'separacion' => 0];
    $area = ['ancho' => 26, 'alto' => 20];
    $barras = fn (string $c) => \App\Http\Controllers\Catalogo\EtiquetasMasivasController::barrasDibujadas($c, false);
    $dropi = view('catalogo.pdf.etiquetas-dropi', ['rollo' => $rollo, 'area' => $area, 'etiquetas' => [['codigo' => '899', 'barras_dibujo' => $barras('899'), 'nombre' => 'Carro lanzador', 'detalle' => 'Azul', 'precio' => 12000]]])->render();
    // Como la plantilla de BarTender: nombre arriba (en mayúsculas por CSS), barras y el código; sin QR, variación ni precio.
    expect($dropi)->toContain('>899<')->toContain('Carro lanzador')->toContain('width: 32.49mm')->toContain('margin-left: 1.265mm')
        // Todo dentro del adhesivo, y las barras dibujadas (no una imagen que la térmica punteaba).
        ->toContain('width: 26mm')->not->toContain('<img')->not->toContain('class="separacion"')
        ->not->toContain('QR')->not->toContain('Azul')->not->toContain('12.000');

    // Un nombre muy largo se corta para no empujar las barras fuera de la etiqueta; sin nombre, solo barras.
    $largo = view('catalogo.pdf.etiquetas-dropi', ['rollo' => $rollo, 'area' => $area, 'etiquetas' => [
        ['codigo' => '900', 'barras_dibujo' => $barras('900'), 'nombre' => str_repeat('Gimnasio musical ', 5)],
        ['codigo' => '901', 'barras_dibujo' => $barras('901'), 'nombre' => null],
    ]])->render();
    expect($largo)->toContain('Gimnasio musical<br>Gimnasio musi...')->not->toContain(str_repeat('Gimnasio musical ', 2))
        ->and(substr_count($largo, 'class="nombre"'))->toBe(1);

    $catalogo = view('catalogo.pdf.etiquetas-lote', ['etiquetas' => [['codigo' => '899', 'barras' => 'data:image/png;base64,AAA', 'qr' => 'data:image/png;base64,BBB', 'nombre' => 'VOLANTE', 'detalle' => 'Azul', 'precio' => 12000]]])->render();
    expect($catalogo)->toContain('alt="QR"')->toContain('VOLANTE')->toContain('12.000');
});

it('las barras van en la cuadrícula de la térmica: un módulo = un punto de 0,125 mm, con blanco a los lados', function () {
    $b = \App\Http\Controllers\Catalogo\EtiquetasMasivasController::barrasDibujadas('AND2505-917', false);
    $modulos = count(\App\Support\Code128::modulos('AND2505-917'));

    expect(array_sum(array_column($b['tramos'], 1)))->toEqualWithDelta($modulos * 0.125, 0.0001)
        ->and(collect($b['tramos'])->every(fn ($t) => fmod($t[1] / 0.125, 1) < 0.0001))->toBeTrue()
        ->and($b['silencio'])->toBe(1.25)
        ->and($b['ancho'])->toBeLessThanOrEqual(26.0);

    // Uno larguísimo se angosta lo justo para caber en el adhesivo.
    expect(\App\Http\Controllers\Catalogo\EtiquetasMasivasController::barrasDibujadas(str_repeat('AB', 20), false)['ancho'])->toEqualWithDelta(26.0, 0.001);
});

it('las barras usan el ancho que les cabe: rayas de 2 puntos si caben; si ni con 1 cabe, va el código corto (26-nov-2026)', function () {
    $C = \App\Http\Controllers\Catalogo\EtiquetasMasivasController::class;

    // «5907B» (la abeja del video): con el juego C caben rayas de 2 puntos (0,25 mm).
    $abeja = $C::barrasDibujadas('5907B', false);
    expect(min(array_column($abeja['tramos'], 1)))->toBe(0.25)
        ->and($abeja['ancho'])->toBe(24.75)
        ->and($abeja['silencio'])->toBe(2.5)
        ->and($abeja['alto'])->toBe(10);

    // «Cabe» es con rayas de 2 puntos: el lector de mesa de empaque no leía las
    // de 1 (27-nov-2026). «AND2505-917» (el del video) ya no cabe y va el corto.
    expect($C::cabe('5907B'))->toBeTrue()
        ->and($C::cabe('899'))->toBeTrue()
        ->and($C::cabe('AND2505-917'))->toBeFalse()
        ->and($C::cabe('7701234567890'))->toBeFalse()
        ->and($C::cabe('AND2507-94/800-05ELF-18M'))->toBeFalse()
        ->and($C::barrasDibujadas('GBV123', true)['alto'])->toBe(7);
});

it('el código corto numérico siempre sale con rayas de 2 puntos, hasta con ids de 7 cifras (27-nov-2026)', function () {
    $C = \App\Http\Controllers\Catalogo\EtiquetasMasivasController::class;

    foreach ([1, 917, 65432, 9999999] as $id) {
        foreach ([\App\Support\CodigoCortoEtiqueta::deVariante($id), \App\Support\CodigoCortoEtiqueta::deProducto($id)] as $corto) {
            $b = $C::barrasDibujadas($corto, true);
            expect($corto)->toHaveLength(8)
                ->and($C::cabe($corto))->toBeTrue()
                ->and(min(array_column($b['tramos'], 1)))->toBeGreaterThanOrEqual(0.25)
                ->and($b['ancho'])->toBeLessThanOrEqual(26.0)
                ->and(\App\Support\CodigoCortoEtiqueta::leer($corto)['id'])->toBe($id);
        }
    }
});

it('debajo de las barras va el código del ERP si entra en el renglón, aunque las barras lleven el corto', function () {
    $this->actingAs($this->aracely);
    $this->variante->update(['codigo_barras' => 'AND2505-917']);

    $datos = [];
    \Illuminate\Support\Facades\View::composer('catalogo.pdf.etiquetas-dropi', function ($v) use (&$datos) {
        $datos = $v->getData()['etiquetas'][0];
    });
    $this->get(route('catalogo.etiquetas.dropi', 1))->assertOk();

    expect($datos['impreso'])->toBe(\App\Support\CodigoCortoEtiqueta::deVariante($this->variante->id))
        ->and($datos['texto'])->toBe('AND2505-917');
});

it('sin vínculo no hay etiqueta, y sin permiso no se imprime', function () {
    $this->actingAs($this->aracely)->get(route('catalogo.etiquetas.dropi', 3))->assertNotFound();
    $this->actingAs($this->aracely)->get(route('catalogo.etiquetas.dropi', 999))->assertNotFound();

    $this->actingAs(etqDropiUsuario('Alistador'))->get(route('catalogo.etiquetas.dropi', 1))->assertForbidden();
});
