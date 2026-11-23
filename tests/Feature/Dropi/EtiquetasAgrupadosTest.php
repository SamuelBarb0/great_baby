<?php

/**
 * Productos agrupados (sin variantes en el ERP) también imprimen etiquetas
 * (bodega, 23-nov-2026: «no puedo imprimir las variaciones con los códigos»).
 * Sale una por cada color de Dropi, con el color escrito, porque el código de
 * barras es la referencia y es el mismo para todos los colores.
 */

use App\Models\User;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Dropi\Services\CatalogoDropi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Queue::fake();
    Storage::fake('local');
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    // En Dropi el tetero tiene tres colores; en el ERP es un solo producto agrupado.
    Storage::disk('local')->put(CatalogoDropi::FOTO, json_encode([
        'leido_at' => now()->toIso8601String(),
        'productos' => array_map(fn ($p) => CatalogoDropi::resumir($p), [
            ['id' => 7, 'name' => 'Lava tetero', 'type' => 'VARIABLE', 'sku' => null, 'warehouse_product' => [],
             'variations' => [
                 ['id' => 701, 'sale_price' => '9000', 'attribute_values' => [['value' => 'Rojo']], 'warehouse_product_variation' => [['stock' => 3]]],
                 ['id' => 702, 'sale_price' => '9000', 'attribute_values' => [['value' => 'Azul']], 'warehouse_product_variation' => [['stock' => 2]]],
                 ['id' => 703, 'sale_price' => '9000', 'attribute_values' => [['value' => 'Verde']], 'warehouse_product_variation' => [['stock' => 0]]],
             ]],
        ]),
    ]));

    $this->tetero = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => '202510-5', 'nombre' => 'LAVA TETERO', 'desglose_stock' => false]));
    $llaves = array_column(collect(app(CatalogoDropi::class)->misProductos()['productos'])->firstWhere('id', 7)['variaciones'], 'llave');
    foreach ($llaves as $llave) {
        VincularProductoDropi::make()->aProducto($llave, $this->tetero->id);
    }

    // Lo que recibe cada vista, para mirar las etiquetas sin descomprimir el PDF.
    $this->vistas = [];
    foreach (['catalogo.pdf.etiquetas-dropi', 'catalogo.pdf.etiquetas-lote'] as $vista) {
        View::composer($vista, fn ($v) => $this->vistas[$vista] = $v->getData()['etiquetas']);
    }
});

it('«Mis productos en Dropi» saca una etiqueta por color del agrupado, todas con su referencia', function () {
    $this->actingAs($this->aracely)->get(route('catalogo.etiquetas.dropi', 7))->assertOk();

    $etiquetas = $this->vistas['catalogo.pdf.etiquetas-dropi'];
    expect(array_column($etiquetas, 'codigo'))->toBe(['202510-5', '202510-5', '202510-5'])
        ->and(collect($etiquetas)->pluck('color')->sort()->values()->all())->toBe(['Azul', 'Rojo', 'Verde'])
        // El renglón del color le quita alto a las barras para caber en los 25 mm.
        ->and(max(array_column($etiquetas, 'barras_alto')))->toBeLessThanOrEqual(11.0);
});

it('el catálogo ya no dice «Sin variantes» para un agrupado: imprime una por color', function () {
    $this->actingAs($this->aracely)
        ->get(route('catalogo.etiquetas.lote', ['productos' => [$this->tetero->id]]))
        ->assertOk()->assertHeader('content-type', 'application/pdf');

    $etiquetas = $this->vistas['catalogo.pdf.etiquetas-lote'];
    expect(array_column($etiquetas, 'detalle'))->toBe(['Azul', 'Rojo', 'Verde'])
        ->and(array_unique(array_column($etiquetas, 'codigo')))->toBe(['202510-5']);
});

it('un agrupado sin colores en Dropi sale con una sola etiqueta, y las variantes siguen igual', function () {
    $solo = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => '899', 'nombre' => 'VOLANTE', 'desglose_stock' => false]));
    $body = Producto::create(['referencia' => 'BODY-1', 'nombre' => 'Body', 'desglose_stock' => true]);
    ProductoVariante::create(['producto_id' => $body->id, 'color_codigo' => 'AZU', 'color_nombre' => 'Azul', 'codigo_barras' => 'BODY-1-AZU']);
    ProductoVariante::create(['producto_id' => $body->id, 'color_codigo' => 'ROS', 'color_nombre' => 'Rosa', 'codigo_barras' => 'BODY-1-ROS']);

    $this->actingAs($this->aracely)
        ->get(route('catalogo.etiquetas.lote', ['productos' => [$solo->id, $body->id]]))
        ->assertOk();

    expect(array_column($this->vistas['catalogo.pdf.etiquetas-lote'], 'codigo'))->toBe(['899', 'BODY-1-AZU', 'BODY-1-ROS']);
});

it('la etiqueta de Dropi escribe el color solo cuando lo trae (agrupado)', function () {
    $rollo = ['ancho' => 100, 'alto' => 25, 'columnas' => 3, 'etiqueta' => 32.49, 'separacion' => 0];
    $html = view('catalogo.pdf.etiquetas-dropi', ['rollo' => $rollo, 'etiquetas' => [
        ['codigo' => '202510-5', 'barras' => 'data:image/png;base64,AAA', 'nombre' => 'LAVA TETERO', 'color' => 'Rojo'],
        ['codigo' => 'BODY-1-AZU', 'barras' => 'data:image/png;base64,AAA', 'nombre' => 'Body'],
    ]])->render();

    expect(substr_count($html, 'class="color"'))->toBe(1)->and($html)->toContain('>Rojo</div>');
});
