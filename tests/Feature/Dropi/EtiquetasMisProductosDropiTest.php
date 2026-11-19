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
        ->assertSee('Etiquetas con código de barras')
        ->assertSee(route('catalogo.etiquetas.dropi', 1), false)
        ->assertSee(route('catalogo.etiquetas.dropi', 2), false)
        ->assertDontSee(route('catalogo.etiquetas.dropi', 3), false)
        ->assertSee('Solo salen las variaciones vinculadas al ERP.')                 // el 1 está vinculado en parte
        ->assertSee('Para imprimir su código de barras, vincúlalo');                 // el 3
});

it('el PDF lleva el código del ERP: el de la variante o la referencia del agregado', function () {
    $this->actingAs($this->aracely);

    $vistas = [];
    $conQr = [];
    \Illuminate\Support\Facades\View::composer('catalogo.pdf.etiquetas-dropi', function ($v) use (&$vistas, &$conQr) {
        $vistas[] = array_column($v->getData()['etiquetas'], 'codigo');
        $conQr[] = collect($v->getData()['etiquetas'])->contains(fn ($e) => isset($e['qr']));
    });

    $pdf = $this->get(route('catalogo.etiquetas.dropi', 1))->assertOk()->assertHeader('content-type', 'application/pdf');
    // Una etiqueta por página, del rollo de 30 × 25 mm de la SAT TT460.
    expect($pdf->getContent())->toContain('/MediaBox [0.000 0.000 85.040 70.870]');
    $this->get(route('catalogo.etiquetas.dropi', 2))->assertOk();

    // La variación sin vincular (Rosa 6M) no sale; la vinculada lleva el código de la variante.
    expect($vistas[0])->toBe(['AND2512-79/154-02LEÓ-6M'])
        ->and($vistas[1])->toBe(['899'])
        // Las de Dropi llevan SOLO el código de barras: ni QR se genera.
        ->and($conQr)->toBe([false, false]);
});

it('las etiquetas de Dropi son solo el código de barras; las del catálogo siguen completas', function () {
    $dropi = view('catalogo.pdf.etiquetas-dropi', ['etiquetas' => [['codigo' => '899', 'barras' => 'data:image/png;base64,AAA', 'nombre' => 'VOLANTE', 'detalle' => 'Azul', 'precio' => 12000]]])->render();
    expect($dropi)->toContain('alt="899"')->not->toContain('QR')->not->toContain('VOLANTE')->not->toContain('12.000');

    $catalogo = view('catalogo.pdf.etiquetas-lote', ['etiquetas' => [['codigo' => '899', 'barras' => 'data:image/png;base64,AAA', 'qr' => 'data:image/png;base64,BBB', 'nombre' => 'VOLANTE', 'detalle' => 'Azul', 'precio' => 12000]]])->render();
    expect($catalogo)->toContain('alt="QR"')->toContain('VOLANTE')->toContain('12.000');
});

it('sin vínculo no hay etiqueta, y sin permiso no se imprime', function () {
    $this->actingAs($this->aracely)->get(route('catalogo.etiquetas.dropi', 3))->assertNotFound();
    $this->actingAs($this->aracely)->get(route('catalogo.etiquetas.dropi', 999))->assertNotFound();

    $this->actingAs(etqDropiUsuario('Alistador'))->get(route('catalogo.etiquetas.dropi', 1))->assertForbidden();
});
