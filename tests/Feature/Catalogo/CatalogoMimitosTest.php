<?php

/**
 * Traer el catálogo comercial sin romper el diseño de producto de SIIGO.
 *
 * Los 336 productos del catálogo web no están en el ERP: hay que teclearlos a
 * mano para poder venderlos. Pero el producto del ERP tiene ochenta columnas,
 * la mitad de ellas de SIIGO —cuentas contables, clasificación fiscal,
 * impuestos—, y el catálogo no sabe nada de eso.
 *
 * Lo que se protege acá:
 *   · que NO se escriba una sola columna de SIIGO, y que `siigo_id` quede nulo,
 *     que es lo que permite que el sync de SIIGO lo adopte por referencia en
 *     vez de crear un duplicado;
 *   · que el precio de VENTA no termine en el producto —ahí vive el COSTO— sino
 *     en una lista de precios;
 *   · que la posición de bodega salga del nombre, porque de ahí iría a las
 *     facturas y a los correos del cliente, a quien el rack no le dice nada;
 *   · y que lo que no calza en el patrón se marque para una persona en vez de
 *     adivinarle, porque una posición inventada le hace perder el viaje a bodega.
 */

use App\Modules\Catalogo\Actions\LeerCatalogoMimitos;
use App\Modules\Catalogo\Models\ListaPrecios;
use App\Modules\Catalogo\Models\PrecioProducto;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Support\Facades\Http;

/** El catálogo de afuera, fingido: estos tests no salen a internet. */
function fingirCatalogo(array $productos, array $categorias = [['id' => 3, 'nombre' => 'Alimentación']]): void
{
    Http::fake([
        LeerCatalogoMimitos::API.'categorias_productos/*' => Http::response(['results' => $categorias]),
        LeerCatalogoMimitos::API.'productos/*' => Http::response([
            'count' => count($productos), 'next' => null, 'results' => $productos,
        ]),
    ]);
}

function productoDelCatalogo(string $nombre, array $extra = []): array
{
    static $n = 0;
    $n++;

    return array_merge([
        'id' => 1000 + $n,
        'nombre' => $nombre,
        'referencia' => 'CAT-'.$n,
        'descripcion' => 'Material resistente',
        'categoria' => 3,
        'price' => 7600,
        'urls_imagenes' => ['https://ejemplo.test/foto.jpg'],
        'cantidadMinima' => 1,
        'paquetesDe' => 1,
        'visibilidad' => true,
    ], $extra);
}

it('saca la posición de bodega del nombre y la guarda aparte', function () {
    fingirCatalogo([productoDelCatalogo('BABERO (R6-S2-N1)')]);

    $p = LeerCatalogoMimitos::run()['productos'][0];

    expect($p['nombre'])->toBe('BABERO')
        ->and($p['posicion_bodega'])->toBe('R6-S2-N1')
        ->and($p['nombre_original'])->toBe('BABERO (R6-S2-N1)')
        ->and($p['revisar'])->toBeNull();
});

it('acepta el producto que ocupa varios estantes', function () {
    // Un producto en dos estantes es corriente: 37 de los 336. Se puede aceptar
    // porque el texto se guarda tal cual y no se parte en tres columnas.
    fingirCatalogo([
        productoDelCatalogo('PAÑALERA (R1-S3, 4-N1)'),
        productoDelCatalogo('NAVE ESPACIAL BAILARIN (R10.5-S1, 2, 3 - N3)'),
    ]);

    $ps = LeerCatalogoMimitos::run()['productos'];

    expect($ps[0]['nombre'])->toBe('PAÑALERA')
        ->and($ps[1]['nombre'])->toBe('NAVE ESPACIAL BAILARIN')
        ->and($ps[0]['revisar'])->toBeNull()
        ->and($ps[1]['revisar'])->toBeNull();
});

it('el LOCAL del final se va con la ubicación, no se queda en el nombre', function () {
    fingirCatalogo([productoDelCatalogo('PAÑALERA (R1-S3, 4-N1)(LOCAL)')]);

    expect(LeerCatalogoMimitos::run()['productos'][0]['nombre'])->toBe('PAÑALERA');
});

it('lo que no calza se marca para una persona en vez de adivinarle', function () {
    /*
     * Dos posiciones, o una zona en vez de un rack. Inventarle una le hace
     * perder el viaje a bodega, que es peor que no tener el dato.
     */
    fingirCatalogo([
        productoDelCatalogo('PELUCHE PALMADAS (ZP)'),
        productoDelCatalogo('BACINILLA (R11-S1-N2, 3)(S2-N2)'),
    ]);

    foreach (LeerCatalogoMimitos::run()['productos'] as $p) {
        expect($p['revisar'])->not->toBeNull()
            ->and($p['posicion_bodega'])->toBeNull()
            // Y el nombre se deja intacto: que la persona vea lo que decía.
            ->and($p['nombre'])->toBe($p['nombre_original']);
    }
});

it('el producto sin paréntesis pasa derecho', function () {
    fingirCatalogo([productoDelCatalogo('COMBO VAJILLA')]);

    $p = LeerCatalogoMimitos::run()['productos'][0];

    expect($p['nombre'])->toBe('COMBO VAJILLA')
        ->and($p['posicion_bodega'])->toBeNull()
        ->and($p['revisar'])->toBeNull();
});

it('sin referencia no se importa: no hay con qué casarlo contra SIIGO', function () {
    fingirCatalogo([productoDelCatalogo('ALGO (R1-S1-N1)', ['referencia' => '  '])]);

    expect(LeerCatalogoMimitos::run()['productos'][0]['revisar'])->toContain('Sin referencia');
});

it('importar NO escribe una sola columna de SIIGO', function () {
    fingirCatalogo([productoDelCatalogo('BABERO (R6-S2-N1)', ['referencia' => 'CAT-SIIGO-1'])]);

    $this->artisan('catalogo:importar-mimitos --aplicar')->assertSuccessful();

    $p = Producto::where('referencia', 'CAT-SIIGO-1')->firstOrFail();

    /*
     * `siigo_id` NULO es lo que permite que el sync de SIIGO lo adopte: su
     * propio código busca un local sin `siigo_id` cuya referencia coincida y lo
     * enlaza en vez de duplicarlo.
     */
    expect($p->siigo_id)->toBeNull()
        ->and($p->siigo_code)->toBeNull()
        ->and($p->cta_ingreso)->toBeNull()
        ->and($p->cta_iva_venta)->toBeNull()
        ->and($p->impuesto_id)->toBeNull()
        /*
         * `tipo_siigo` y `tax_classification` traen DEFAULT en la tabla
         * ('Product' y 'Taxed'): los pone MySQL, no el importador. Se verifica
         * que sigan en su default y no en algo inventado por nosotros.
         */
        ->and($p->tipo_siigo)->toBe('Product')
        ->and($p->tax_classification)->toBe('Taxed')
        // Y lo que sí tiene que traer:
        ->and($p->nombre)->toBe('BABERO')
        ->and($p->posicion_bodega)->toBe('R6-S2-N1')
        ->and((bool) $p->desglose_stock)->toBeFalse();
});

it('el precio de venta va a la lista, nunca al producto', function () {
    $lista = ListaPrecios::create([
        'codigo' => 'MIMITOS', 'nombre' => 'Mimitos', 'canal' => 'mayorista', 'activa' => true,
    ]);

    fingirCatalogo([productoDelCatalogo('CHUPO (R7-S3-N2)', ['referencia' => 'CAT-PRE-1', 'price' => 5800])]);

    $this->artisan('catalogo:importar-mimitos --aplicar --lista=MIMITOS')->assertSuccessful();

    $p = Producto::where('referencia', 'CAT-PRE-1')->firstOrFail();

    // `precio_proveedor` es el COSTO. Meter ahí el precio de venta le vendería
    // al mayorista a precio de compra.
    expect((float) $p->precio_proveedor)->toBe(0.0)
        ->and((float) PrecioProducto::where('producto_id', $p->id)->where('lista_id', $lista->id)->value('precio'))
        ->toBe(5800.0);
});

it('no duplica lo que ya existe por referencia', function () {
    Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'CAT-YA-1', 'nombre' => 'El que ya estaba', 'desglose_stock' => false,
    ]));

    fingirCatalogo([productoDelCatalogo('OTRO NOMBRE (R1-S1-N1)', ['referencia' => 'CAT-YA-1'])]);

    $this->artisan('catalogo:importar-mimitos --aplicar')->assertSuccessful();

    // Ni lo duplica ni le pisa el nombre: lo que ya está en el ERP manda.
    expect(Producto::where('referencia', 'CAT-YA-1')->count())->toBe(1)
        ->and(Producto::where('referencia', 'CAT-YA-1')->value('nombre'))->toBe('El que ya estaba');
});

it('sin --aplicar no escribe una sola fila', function () {
    fingirCatalogo([productoDelCatalogo('BABERO (R6-S2-N1)', ['referencia' => 'CAT-SECO-1'])]);

    $antes = Producto::count();

    $this->artisan('catalogo:importar-mimitos')->assertSuccessful();

    // Trescientos productos mal importados se deshacen a mano, uno por uno.
    expect(Producto::count())->toBe($antes)
        ->and(Producto::where('referencia', 'CAT-SECO-1')->exists())->toBeFalse();
});

it('una lista que no existe se avisa en vez de importar sin precio', function () {
    fingirCatalogo([productoDelCatalogo('BABERO (R6-S2-N1)')]);

    $this->artisan('catalogo:importar-mimitos --aplicar --lista=NO-EXISTE')->assertFailed();

    expect(Producto::where('referencia', 'like', 'CAT-%')->count())->toBe(0);
});

it('si el catálogo no responde, se dice y no se importa a medias', function () {
    Http::fake([
        LeerCatalogoMimitos::API.'categorias_productos/*' => Http::response(['results' => []]),
        LeerCatalogoMimitos::API.'productos/*' => Http::response('', 503),
    ]);

    expect(fn () => LeerCatalogoMimitos::run())
        ->toThrow(RuntimeException::class);
});
