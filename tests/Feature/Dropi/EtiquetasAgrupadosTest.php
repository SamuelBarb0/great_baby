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

/** La dirección del PDF que deja el aviso «🖨 Abrir etiquetas» de la acción del panel. */
function etiquetasDelAviso(): string
{
    // Como Notification::assertNotified: lo que recibe el componente de avisos.
    $avisos = new \Filament\Notifications\Livewire\Notifications;
    $avisos->mount();
    $url = $avisos->notifications->last()?->toArray()['actions'][0]['url'] ?? null;
    expect($url)->not->toBeNull();

    return $url;
}

it('«Mis productos en Dropi» saca una etiqueta por color del agrupado, todas con su referencia', function () {
    $this->actingAs($this->aracely)->get(route('catalogo.etiquetas.dropi', 7))->assertOk();

    $etiquetas = $this->vistas['catalogo.pdf.etiquetas-dropi'];
    expect(array_column($etiquetas, 'codigo'))->toBe(['202510-5', '202510-5', '202510-5'])
        ->and(collect($etiquetas)->pluck('color')->sort()->values()->all())->toBe(['Azul', 'Rojo', 'Verde'])
        // El renglón del color le quita alto a las barras para caber en el adhesivo.
        ->and(max(array_column(array_column($etiquetas, 'barras_dibujo'), 'alto')))->toBeLessThan(8.5);
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
    $barras = fn (string $c, bool $color) => \App\Http\Controllers\Catalogo\EtiquetasMasivasController::barrasDibujadas($c, $color);
    $html = view('catalogo.pdf.etiquetas-dropi', ['rollo' => $rollo, 'area' => ['ancho' => 26, 'alto' => 20], 'etiquetas' => [
        ['codigo' => '202510-5', 'barras_dibujo' => $barras('202510-5', true), 'nombre' => 'LAVA TETERO', 'color' => 'Rojo'],
        ['codigo' => 'BODY-1-AZU', 'barras_dibujo' => $barras('BODY-1-AZU', false), 'nombre' => 'Body'],
    ]])->render();

    expect(substr_count($html, 'class="color"'))->toBe(1)->and($html)->toContain('>Rojo</div>');
});

describe('el selector de etiquetas (bodega, 23-nov-2026)', function () {
    beforeEach(function () {
        $this->body = Producto::create(['referencia' => 'BODY-1', 'nombre' => 'Body', 'desglose_stock' => true, 'precio_proveedor' => 15000]);
        $this->azul = ProductoVariante::create(['producto_id' => $this->body->id, 'color_codigo' => 'AZU', 'color_nombre' => 'Azul', 'codigo_barras' => 'BODY-1-AZU']);
        $this->rosa = ProductoVariante::create(['producto_id' => $this->body->id, 'color_codigo' => 'ROS', 'color_nombre' => 'Rosa', 'codigo_barras' => 'BODY-1-ROS']);
    });

    it('ofrece el producto original, sus variantes y, en un agrupado, los colores de Dropi', function () {
        $this->actingAs($this->aracely);

        $this->getJson(route('catalogo.etiquetas.opciones', $this->body->id))->assertOk()
            ->assertJsonPath('producto.referencia', 'BODY-1')
            ->assertJsonPath('variantes.0.codigo', 'BODY-1-AZU')
            ->assertJsonCount(2, 'variantes')
            ->assertJsonCount(0, 'colores');

        $this->getJson(route('catalogo.etiquetas.opciones', $this->tetero->id))->assertOk()
            ->assertJsonCount(0, 'variantes')
            ->assertJsonPath('colores', ['Azul', 'Rojo', 'Verde']);
    });

    it('imprime sólo lo escogido: el original y una variante exacta, con copias', function () {
        $this->actingAs($this->aracely)->get(route('catalogo.etiquetas.lote', [
            'productos' => [$this->body->id], 'seleccion' => 1, 'original' => 1,
            'variantes' => [$this->rosa->id], 'copias' => 2,
        ]))->assertOk()->assertHeader('content-type', 'application/pdf');

        // Siempre en el rollo de la térmica: ya no se escoge formato.
        expect(array_column($this->vistas['catalogo.pdf.etiquetas-dropi'], 'codigo'))
            ->toBe(['BODY-1', 'BODY-1', 'BODY-1-ROS', 'BODY-1-ROS']);
    });

    it('de un agrupado imprime sólo los colores marcados, en el rollo de la térmica', function () {
        $this->actingAs($this->aracely)->get(route('catalogo.etiquetas.lote', [
            'productos' => [$this->tetero->id], 'seleccion' => 1, 'colores' => ['rojo'],
        ]))->assertOk();

        $e = $this->vistas['catalogo.pdf.etiquetas-dropi'];
        expect(array_column($e, 'color'))->toBe(['Rojo'])->and(array_column($e, 'codigo'))->toBe(['202510-5']);
    });

    it('cuántas de cada una: muchos rojos y pocos azules (la señora Lahida, 24-nov-2026)', function () {
        $this->actingAs($this->aracely)->get(route('catalogo.etiquetas.lote', [
            'productos' => [$this->tetero->id], 'seleccion' => 1, 'colores' => ['Rojo', 'Azul'],
            'copias' => 1, 'cant' => ['c:Rojo' => 3, 'c:Azul' => 1],
        ]))->assertOk();
        $porColor = array_count_values(array_column($this->vistas['catalogo.pdf.etiquetas-dropi'], 'color'));
        expect($porColor)->toBe(['Azul' => 1, 'Rojo' => 3]);

        // Por variante también; la que no trae su número usa «copias».
        $this->actingAs($this->aracely)->get(route('catalogo.etiquetas.lote', [
            'productos' => [$this->body->id], 'seleccion' => 1, 'variantes' => [$this->azul->id, $this->rosa->id],
            'copias' => 2, 'cant' => ["v:{$this->azul->id}" => 4],
        ]))->assertOk();
        expect(array_count_values(array_column($this->vistas['catalogo.pdf.etiquetas-dropi'], 'codigo')))
            ->toBe(['BODY-1-AZU' => 4, 'BODY-1-ROS' => 2]);
    });

    it('sin nada escogido no imprime una hoja vacía', function () {
        $this->actingAs($this->aracely)->get(route('catalogo.etiquetas.lote', [
            'productos' => [$this->body->id], 'seleccion' => 1,
        ]))->assertStatus(422);
    });

    it('en el panel Dropi, «Mis productos en Dropi» abre el selector del producto vinculado: 3 rojos y 1 azul (25-nov-2026)', function () {
        \Filament\Facades\Filament::setCurrentPanel('admin');
        $this->actingAs($this->aracely);

        // La tarjeta sabe con qué producto del ERP está vinculado el de Dropi.
        $tarjeta = collect(app(CatalogoDropi::class)->misProductos()['productos'])->firstWhere('id', 7);
        expect(\App\Modules\Dropi\Filament\Pages\MisProductosDropi::productoErp($tarjeta))->toBe($this->tetero->id);

        // Colores en orden: Azul (0), Rojo (1), Verde (2).
        \Livewire\Livewire::test(\App\Modules\Dropi\Filament\Pages\MisProductosDropi::class)
            ->mountAction('imprimirEtiquetas', ['producto' => $this->tetero->id])
            ->fillForm(['color_0' => true, 'cant_c_0' => 1, 'color_1' => true, 'cant_c_1' => 3, 'color_2' => false])
            ->callMountedAction()
            // La misma ventana pasa a la vista previa con «Imprimir» (27-nov-2026).
            ->assertActionMounted('verEtiquetas')
            ->assertMountedActionModalSee(['🖨 Imprimir', '4 etiqueta(s) de 202510-5']);

        $this->get(etiquetasDelAviso())->assertOk()->assertHeader('content-type', 'application/pdf');
        expect(array_count_values(array_column($this->vistas['catalogo.pdf.etiquetas-dropi'], 'color')))->toBe(['Azul' => 1, 'Rojo' => 3]);
    });

    it('en el panel Dropi, «Buscar producto» imprime las variantes marcadas con su cantidad y las muestra con «Imprimir»', function () {
        \Filament\Facades\Filament::setCurrentPanel('admin');
        $this->actingAs($this->aracely);

        \Livewire\Livewire::test(\App\Modules\Dropi\Filament\Pages\CatalogoProductosDropi::class)
            ->callAction(\Filament\Actions\Testing\TestAction::make('imprimirEtiquetas')->table($this->body), data: [
                'copias' => 1, "var_{$this->azul->id}" => true, "cant_v_{$this->azul->id}" => 3,
                "var_{$this->rosa->id}" => true, "cant_v_{$this->rosa->id}" => 1,
            ])
            ->assertActionMounted('verEtiquetas')
            ->assertMountedActionModalSee('🖨 Imprimir');

        $this->get(etiquetasDelAviso())->assertOk();
        expect(array_count_values(array_column($this->vistas['catalogo.pdf.etiquetas-dropi'], 'codigo')))
            ->toBe(['BODY-1-AZU' => 3, 'BODY-1-ROS' => 1]);
    });

    it('en el panel Dropi, sin nada marcado no prepara nada', function () {
        \Filament\Facades\Filament::setCurrentPanel('admin');
        $this->actingAs($this->aracely);

        \Livewire\Livewire::test(\App\Modules\Dropi\Filament\Pages\CatalogoProductosDropi::class)
            ->callAction(\Filament\Actions\Testing\TestAction::make('imprimirEtiquetas')->table($this->body), data: ["var_{$this->azul->id}" => false, "var_{$this->rosa->id}" => false])
            ->assertNotified('Escoge al menos una etiqueta para imprimir.');
    });

    it('la etiqueta no dice $0 cuando el producto no tiene precio', function () {
        $html = view('catalogo.pdf.etiquetas-lote', ['etiquetas' => [
            ['nombre' => 'LORO', 'detalle' => 'ROJO', 'precio' => 0, 'codigo' => 'AND2410-458', 'qr' => 'data:image/png;base64,AAA', 'barras' => 'data:image/png;base64,AAA'],
            ['nombre' => 'Body', 'detalle' => 'Azul', 'precio' => 15000, 'codigo' => 'BODY-1-AZU', 'qr' => 'data:image/png;base64,AAA', 'barras' => 'data:image/png;base64,AAA'],
        ]])->render();

        expect($html)->not->toContain('$0')->and($html)->toContain('$15.000');
    });
});

it('«Catálogo de productos» encuentra el producto por su nombre en Dropi y lo muestra debajo (27-nov-2026: «abejita» es «ABEJA BAILARINA»)', function () {
    \Filament\Facades\Filament::setCurrentPanel('admin');
    $this->actingAs($this->aracely);

    $abeja = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => '5907B', 'nombre' => 'ABEJA BAILARINA', 'desglose_stock' => false, 'activo' => true]));
    $otro = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => '5907C', 'nombre' => 'PATO', 'desglose_stock' => false, 'activo' => true]));
    DB::table('dropi_vinculos_producto')->insert([
        ['sku_dropi' => 'DV-2351083', 'producto_id' => $abeja->id, 'variante_id' => null, 'producto_nombre' => 'Abejita chiquitica', 'variacion' => 'Rojo', 'created_at' => now(), 'updated_at' => now()],
        ['sku_dropi' => 'DV-2351084', 'producto_id' => $abeja->id, 'variante_id' => null, 'producto_nombre' => 'Abejita chiquitica', 'variacion' => 'Negro', 'created_at' => now(), 'updated_at' => now()],
    ]);

    \Livewire\Livewire::test(\App\Modules\Dropi\Filament\Pages\CatalogoProductosDropi::class)
        ->searchTable('abejita')
        ->assertCanSeeTableRecords([$abeja])
        ->assertCanNotSeeTableRecords([$otro, $this->tetero])
        ->assertSee('En Dropi: Abejita chiquitica')
        // Por su nombre del ERP y su referencia sigue encontrándose igual.
        ->searchTable('bailarina')->assertCanSeeTableRecords([$abeja])->assertCanNotSeeTableRecords([$otro])
        ->searchTable('5907C')->assertCanSeeTableRecords([$otro])->assertCanNotSeeTableRecords([$abeja]);
});

it('«Buscar producto» encuentra por código de barras y por el código corto de la etiqueta (27-nov-2026)', function () {
    \Filament\Facades\Filament::setCurrentPanel('admin');
    $this->actingAs($this->aracely);

    $carro = Producto::create(['referencia' => 'CAR-01', 'nombre' => 'CARRO DE BOMBEROS', 'desglose_stock' => true, 'activo' => true]);
    ProductoVariante::create(['producto_id' => $carro->id, 'color_codigo' => 'ROJ', 'color_nombre' => 'Rojo', 'codigo_barras' => '7701234567890']);

    \Livewire\Livewire::test(\App\Modules\Dropi\Filament\Pages\CatalogoProductosDropi::class)
        ->assertSee('Buscar producto')
        ->searchTable('7701234567890')->assertCanSeeTableRecords([$carro])->assertCanNotSeeTableRecords([$this->tetero])
        ->searchTable(\App\Support\CodigoCortoEtiqueta::deProducto($this->tetero->id))
        ->assertCanSeeTableRecords([$this->tetero])->assertCanNotSeeTableRecords([$carro]);
});

it('«Buscar producto» avisa lo de Dropi que coincide y no está vinculado, con «Vincular ahora →» (27-nov-2026)', function () {
    \Filament\Facades\Filament::setCurrentPanel('admin');
    $this->actingAs($this->aracely);

    $foto = json_decode(Storage::disk('local')->get(CatalogoDropi::FOTO), true);
    $foto['productos'][] = CatalogoDropi::resumir(['id' => 8, 'name' => 'Mordedor jirafa', 'type' => 'VARIABLE', 'sku' => null, 'warehouse_product' => [],
        'variations' => [['id' => 801, 'sale_price' => '5000', 'attribute_values' => [['value' => 'Amarillo']], 'warehouse_product_variation' => [['stock' => 4]]]]]);
    Storage::disk('local')->put(CatalogoDropi::FOTO, json_encode($foto));

    \Livewire\Livewire::test(\App\Modules\Dropi\Filament\Pages\CatalogoProductosDropi::class)
        ->searchTable('jirafa')
        ->assertCanNotSeeTableRecords([$this->tetero])
        ->assertSee('que el ERP todavía no reconoce')
        ->assertSee('Mordedor jirafa')
        ->assertSee('Sin vincular')
        ->assertSee('Vincular ahora →')
        ->assertSee('No encontramos «jirafa» en el ERP');
});
