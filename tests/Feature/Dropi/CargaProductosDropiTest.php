<?php

use App\Modules\Dropi\Actions\EjecutarOperacionDropi;
use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Models\DropiCargaOperacion as Op;
use App\Modules\Dropi\Models\DropiCargaProducto as Carga;
use App\Modules\Dropi\Models\DropiWebSesion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\DropiWebAuth;
use App\Modules\Dropi\Services\PlanificarCargaDropi;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * Crear productos y mover stock en Dropi desde el ERP. El Dropi simulado
 * guarda estado: aplica las ENTRADAS/SALIDAS que le llegan y exige el mismo
 * formato que el real (capturado en la web el 18-nov-2026), así que si el
 * cuerpo del PUT se arma mal el test falla igual que fallaría Dropi.
 */

const DC_BODEGA = 10548;
const DC_USUARIO = 1004954;

beforeEach(function () {
    config()->set('dropi.web', [
        'email' => 'c@e.co', 'password' => 'x', 'white_brand_id' => 1, 'timeout' => 5,
        'bff_url' => 'https://bff.test', 'legacy_url' => 'https://legacy.test', 'bodega_id' => DC_BODEGA,
    ]);
    app()->forgetInstance(DropiWebAuth::class);
    app()->singleton(DropiWebAuth::class, fn ($app) => new DropiWebAuth($app['config']->get('dropi.web')));
    $jwt = 'h.' . rtrim(strtr(base64_encode(json_encode(['sub' => (string) DC_USUARIO, 'exp' => time() + 3600])), '+/', '-_'), '=') . '.f';
    DropiWebSesion::create(['email' => 'c@e.co', 'estado' => DropiWebSesion::CONECTADO, 'token' => $jwt, 'expira_at' => now()->addHour()]);
    Cache::flush();
    Cache::forever('dropi.web.bodega.' . DC_BODEGA . '.nombre', 'Mimitos(calle 37)');

    $this->dropi = new DcDropiFalso();
    $this->dropi->instalar();
});

/** Dropi en memoria: productos, movimientos de stock y productos creados. */
class DcDropiFalso
{
    public array $productos = [];
    public array $puts = [];
    public array $creados = [];
    public int $siguienteId = 900;
    /** Si se pone, Dropi «acepta» el PUT pero no aplica este producto (para probar la verificación). */
    public ?int $ignorarPutDe = null;

    public function __construct()
    {
        $this->productos = [
            100 => self::simple(100, 'Patito Juan', '8899-303', 432),
            200 => self::variable(200, 'GUITARRA INFANTIL ANIMALITOS', 'DROP2606-59', [[201, 'ROSADO', 'DROP2606-59', 105], [202, 'AZUL', '-1', 105]]),
            300 => self::variable(300, 'Procesador de alimentos', null, [[301, 'ROSADO', '-0', 42], [302, 'VERDE', '-1', 46], [303, 'BLANCO', '-2', 48]]),
            400 => self::variable(400, 'TETERO SILICONA CON VÁLVULA', 'AND2410-441', [[401, '5oz', 'AND2411-04', 0], [402, '9oz', 'AND2410-441-4', 0], [403, 'Chupo', '2507-36-7', 3]]),
            500 => self::simple(500, 'GUSANOS MAGICOS MOVING', 'AND2606-41', 10),
            600 => self::simple(600, 'Libro de estudio', 'AND2410-526', 5),
        ];
    }

    public static function simple(int $id, string $nombre, ?string $sku, int $stock): array
    {
        return ['id' => $id, 'name' => $nombre, 'sku' => $sku, 'type' => 'SIMPLE', 'user_id' => 151057, 'sale_price' => '1000', 'suggested_price' => '2000',
            'privated_product' => false, 'active' => true, 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'categories' => [['id' => 17, 'name' => 'Bebé', 'parent_category' => 0]],
            'attributes' => [], 'variations' => [], 'warehouse_product' => [['warehouse_id' => DC_BODEGA, 'stock' => $stock]]];
    }

    public static function variable(int $id, string $nombre, ?string $sku, array $vars): array
    {
        return ['id' => $id, 'name' => $nombre, 'sku' => $sku, 'type' => 'VARIABLE', 'user_id' => 151057, 'sale_price' => null, 'suggested_price' => null,
            'privated_product' => false, 'active' => true, 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'categories' => [['id' => 26, 'name' => 'Jugueteria', 'parent_category' => 0]],
            'attributes' => [['id' => $id * 10, 'description' => 'COLOR', 'isVariation' => null, 'values' => array_map(fn ($v) => ['id' => $v[0] * 10, 'value' => $v[1], 'attribute_id' => $id * 10, 'attribute_name' => 'COLOR'], $vars)]],
            'variations' => array_map(fn ($v) => ['id' => $v[0], 'sku' => $v[2], 'sale_price' => '1000', 'suggested_price' => '2000', 'product_id' => $id, 'stock' => (string) $v[3],
                'attribute_values' => [['id' => $v[0] * 10, 'value' => $v[1], 'attribute_id' => $id * 10, 'attribute_name' => 'COLOR']],
                'warehouse_product_variation' => [['warehouse_id' => DC_BODEGA, 'variation_id' => $v[0], 'stock' => $v[3]]]], $vars),
            'warehouse_product' => []];
    }

    public function stock(int $productoId, ?int $variacionId = null): int
    {
        return EjecutarOperacionDropi::stockDe($this->productos[$productoId], $variacionId, DC_BODEGA);
    }

    public function instalar(): void
    {
        Http::fake(function (Request $q) {
            $url = $q->url();
            $ok = fn ($objects = [], $extra = []) => Http::response(['isSuccess' => true, 'status' => 200, 'message' => 'ok', 'objects' => $objects] + $extra);

            if (str_ends_with($url, '/products/index')) {
                // Como el real: con privated_product=null devuelve CERO productos.
                $pv = $q->data()['privated_product'] ?? null;
                if ($pv === null) {
                    return $ok([]);
                }
                $lista = array_values(array_filter($this->productos, fn ($p) => (bool) $p['privated_product'] === (bool) $pv));
                // Como el real: la palabra busca en el nombre y el SKU del producto (no en los de variación).
                $kw = mb_strtolower(trim((string) ($q->data()['keywords'] ?? '')));
                if ($kw !== '') {
                    $lista = array_values(array_filter($lista, fn ($p) => str_contains(mb_strtolower($p['name']), $kw) || str_contains(mb_strtolower((string) $p['sku']), $kw)));
                }

                return $ok(array_slice($lista, (int) $q['startData'], (int) $q['pageSize']));
            }
            if (str_ends_with($url, '/products/get_warehouses_stock')) {
                $vid = $q->data()['variation_id'] ?? null;
                $p = $vid ? collect($this->productos)->first(fn ($p) => collect($p['variations'])->contains('id', $vid)) : $this->productos[$q['product_id']];
                $filas = $vid
                    ? collect($p['variations'])->firstWhere('id', $vid)['warehouse_product_variation']
                    : $p['warehouse_product'];

                return $ok(array_map(fn ($w) => $w + ['fullName' => 'Mimitos(calle 37)', 'warehouse_white_brand' => false], $filas));
            }
            if (str_ends_with($url, '/productwarranty/create')) {
                $this->creados[$q['id']]['garantias'] = $q['warranties'];

                return $ok();
            }
            if ($q->method() === 'POST' && str_ends_with($url, '/products')) {
                $id = $this->siguienteId++;
                $vars = [];
                foreach ($q['variations'] as $k => $v) {
                    $vars[] = [$id * 10 + $k, $v['attribute_values'][0]['value'], $v['sku'], 0];
                }
                $this->productos[$id] = $vars ? self::variable($id, $q['name'], $q['sku'], $vars) : self::simple($id, $q['name'], $q['sku'], 0);
                $this->productos[$id]['warehouse_product'] = [];
                $this->creados[$id] = ['cuerpo' => $q->data()];

                return Http::response(['isSuccess' => true, 'message' => 'El item ha sido creado con exito!.', 'objects' => ['id' => $id, 'name' => $q['name'], 'variations' => []]]);
            }
            if (preg_match('#/products/(\d+)$#', $url, $m)) {
                $id = (int) $m[1];
                if ($q->method() === 'GET') {
                    return $ok($this->productos[$id]);
                }
                // PUT: valida el formato como el Dropi real y aplica los movimientos.
                $this->puts[] = ['id' => $id, 'cuerpo' => $q->data()];
                $p = &$this->productos[$id];
                $sel = $q['warehouselected'];
                if ($p['type'] === 'VARIABLE') {
                    if (! $sel || ! is_array($sel[0] ?? null) || isset($sel[0]['warehouse_id'])) {
                        return Http::response(['isSuccess' => false, 'message' => 'Trying to access array offset on value of type int'], 500);
                    }
                    $sel = array_merge(...$sel);
                }
                if ($this->ignorarPutDe === $id) {
                    return $ok();
                }
                foreach ($sel as $mv) {
                    if (! isset($mv['fullName']) && ! isset($mv['stock'])) {
                        return Http::response(['isSuccess' => false, 'message' => 'Undefined array key "fullName"'], 500);
                    }
                    $signo = $mv['type_movement'] === 'ENTRADA' ? 1 : -1;
                    $n = $signo * (int) $mv['quantity'];
                    if (! empty($mv['variation_id'])) {
                        foreach ($p['variations'] as &$v) {
                            if ($v['id'] === $mv['variation_id']) {
                                $v['warehouse_product_variation'] = $v['warehouse_product_variation'] ?: [['warehouse_id' => DC_BODEGA, 'variation_id' => $v['id'], 'stock' => 0]];
                                $v['warehouse_product_variation'][0]['stock'] += $n;
                            }
                        }
                        unset($v);
                    } else {
                        $p['warehouse_product'] = $p['warehouse_product'] ?: [['warehouse_id' => DC_BODEGA, 'stock' => 0]];
                        $p['warehouse_product'][0]['stock'] += $n;
                    }
                }

                return Http::response(['isSuccess' => true, 'status' => 200, 'message' => 'EL producto se ha actualizado con exito!.', 'objects' => []]);
            }

            return Http::response(['isSuccess' => false, 'message' => "ruta no simulada: {$q->method()} {$url}"], 404);
        });
    }
}

function dcFila(string $ref, string $nombre, float $cant, ?string $var = null, int $n = 2): array
{
    return ['fila' => $n, 'ref' => $ref, 'nombre' => $nombre, 'cantidad' => $cant, 'variaciones' => $var];
}

function dcPlan(array $filas): array
{
    return app(PlanificarCargaDropi::class)->planificar($filas, array_values(test()->dropi->productos), DC_BODEGA);
}

function dcExcel(array $filas): UploadedFile
{
    $libro = new Spreadsheet();
    $libro->getActiveSheet()->fromArray(array_merge([['PRODUCTO', 'CANT', 'COSTO', 'PRECIO DROPI', 'VARIACIONES']], $filas));
    $ruta = tempnam(sys_get_temp_dir(), 'dc') . '.xlsx';
    (new Xlsx($libro))->save($ruta);

    return new UploadedFile($ruta, 'inventario.xlsx', null, null, true);
}

/** La pantalla pide el catálogo de a una página hasta que la carga queda en borrador. */
function dcLeer(Carga $carga): Carga
{
    for ($i = 0; $i < 20 && $carga->refresh()->estado === Carga::LEYENDO; $i++) {
        test()->postJson("/app/dropi/carga-productos/{$carga->id}/leer")->assertOk();
    }

    return $carga->refresh();
}

function dcGerente(): \App\Models\User
{
    return \App\Models\User::factory()->create()->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Aracely', 'web'));
}

// ───────────────────────── cliente ─────────────────────────

it('mueve stock de variaciones con el formato de la web: una lista por variación y el usuario de la sesión', function () {
    app(DropiWebClient::class)->moverStock(200, [
        ['variation_id' => 201, 'warehouse_id' => DC_BODEGA, 'quantity' => 5, 'type_movement' => 'SALIDA', 'concept' => 'x'],
        ['variation_id' => 202, 'warehouse_id' => DC_BODEGA, 'quantity' => 3, 'type_movement' => 'ENTRADA', 'concept' => 'x'],
    ]);

    $cuerpo = $this->dropi->puts[0]['cuerpo'];
    expect($cuerpo['user_id'])->toBe(DC_USUARIO)
        ->and($cuerpo['warehouselected'])->toHaveCount(2)
        ->and($cuerpo['warehouselected'][0][0])->toMatchArray(['variation_id' => 201, 'quantity' => '5', 'type_movement' => 'SALIDA'])
        ->and($cuerpo['variations'])->toHaveCount(2)
        ->and($cuerpo['attribute'][0]['isVariation'])->toBeNull()
        ->and($this->dropi->stock(200, 201))->toBe(100)
        ->and($this->dropi->stock(200, 202))->toBe(108);
});

it('mueve stock de un producto simple con lista plana y sin variaciones', function () {
    app(DropiWebClient::class)->moverStock(100, [['variation_id' => null, 'warehouse_id' => DC_BODEGA, 'quantity' => 1, 'type_movement' => 'ENTRADA', 'concept' => 'x']]);

    $cuerpo = $this->dropi->puts[0]['cuerpo'];
    expect($cuerpo['warehouselected'][0])->toMatchArray(['warehouse_id' => DC_BODEGA, 'quantity' => '1'])
        ->and($cuerpo['variations'])->toBe([])
        ->and($this->dropi->stock(100))->toBe(433);
});

it('no deja mandar un movimiento sin variación a un producto con variaciones', function () {
    app(DropiWebClient::class)->moverStock(200, [['variation_id' => null, 'warehouse_id' => DC_BODEGA, 'quantity' => 1, 'type_movement' => 'ENTRADA', 'concept' => 'x']]);
})->throws(InvalidArgumentException::class, 'necesita variation_id');

it('crea el producto con variaciones y sus cuatro garantías', function () {
    $r = app(DropiWebClient::class)->crearProducto([
        'nombre' => 'Nuevo', 'sku' => 'N-1', 'peso' => 1, 'largo' => 2, 'ancho' => 3, 'alto' => 4,
        'categorias' => [['id' => 17, 'name' => 'Bebé']],
        'variaciones' => [['valor' => 'Rojo', 'sku' => 'N-1-0', 'precio' => 1000, 'sugerido' => 2000], ['valor' => 'Azul', 'sku' => 'N-1-1', 'precio' => 1000, 'sugerido' => 2000]],
        'garantia_texto' => 'Con video',
    ]);

    $cuerpo = $this->dropi->creados[$r['id']]['cuerpo'];
    expect($cuerpo)->toMatchArray(['type' => 'VARIABLE', 'id' => null, 'user_id' => DC_USUARIO, 'sale_price' => null])
        ->and($cuerpo['attribute'][0]['values'][1])->toMatchArray(['id' => 0, 'value' => 'Azul'])
        ->and($cuerpo['variations'][0]['attribute_values'][0]['id'])->toBe('Rojo')
        ->and($this->dropi->creados[$r['id']]['garantias'])->toHaveCount(4)
        ->and($r['variaciones'])->toHaveKeys(['Rojo', 'Azul']);
});

it('el catálogo trae públicos y privados (Dropi no devuelve nada si no se dice cuáles)', function () {
    $this->dropi->productos[600]['privated_product'] = true;

    $ids = array_column(app(DropiWebClient::class)->catalogo(), 'id');

    expect($ids)->toHaveCount(6)->toContain(600)->toContain(100);
});

it('la vista previa lee el catálogo de a una página e incluye los privados', function () {
    $this->dropi->productos[600]['privated_product'] = true; // «Libro de estudio» privado
    $this->actingAs(dcGerente());
    $this->post('/app/dropi/carga-productos/excel', ['archivo' => dcExcel([['AND2410-526 LIBRO DE ESTUDIO', 2, 1, null, 'NO HAY VARIABLE']]), 'modo' => 'reemplazar', 'concepto' => 'Inventario noviembre']);
    $carga = Carga::latest('id')->first();

    $llamadas = 0;
    while ($carga->refresh()->estado === Carga::LEYENDO && $llamadas < 10) {
        $r = $this->postJson("/app/dropi/carga-productos/{$carga->id}/leer")->assertOk()->json();
        $llamadas++;
    }

    expect($llamadas)->toBe(2) // una página de públicos (5 < 50) y una de privados
        ->and($r)->toMatchArray(['estado' => 'borrador', 'leidos' => 6])
        ->and($carga->operaciones->first()->dropi_producto_id)->toBe(600);
});

it('procesar corta por tiempo y la pantalla vuelve a llamar hasta terminar', function () {
    $carga = Carga::create(['numero' => Carga::siguienteNumero(), 'origen' => 'excel', 'modo' => 'reemplazar', 'concepto' => 'Inventario prueba',
        'bodega_dropi_id' => DC_BODEGA, 'estado' => Carga::PROCESANDO, 'defaults_crear' => []]);
    foreach ([[100, null, 400], [500, null, 9]] as $k => [$id, $var, $cant]) {
        $carga->operaciones()->create(['fila' => $k + 2, 'tipo' => Op::ACTUALIZAR, 'estado' => Op::LISTO, 'dropi_producto_id' => $id,
            'detalle' => ['lineas' => [['variation_id' => $var, 'etiqueta' => 'P', 'cantidad' => $cant]]]]);
    }
    $this->actingAs(dcGerente());

    $r = $this->postJson("/app/dropi/carga-productos/{$carga->id}/procesar")->assertOk()->json();

    expect($r)->toMatchArray(['estado' => 'terminado', 'ok' => 2, 'pendientes' => 0])
        ->and($this->dropi->stock(100))->toBe(400)
        ->and($this->dropi->stock(500))->toBe(9);
});

// ───────────────────────── planificador ─────────────────────────

it('empareja por SKU, por la base del SKU de una variación y cambiando el prefijo DROP/AND', function () {
    $ops = collect(dcPlan([
        dcFila('8899-303', 'PATITO JUAN', 332),
        dcFila('2507-36', 'TETERO CON VÁLVULA 9OZ', 2, 'NO HAY VARIABLE'),
        dcFila('DROP2606-41', 'GUSANOS MÁGICOS', 178),
    ]))->keyBy('ref_excel');

    expect($ops['8899-303'])->toMatchArray(['tipo' => 'actualizar', 'estado' => 'listo', 'dropi_producto_id' => 100])
        ->and($ops['8899-303']['detalle']['lineas'][0])->toMatchArray(['stock_plan' => 432, 'cantidad' => 332.0])
        ->and($ops['2507-36']['dropi_producto_id'])->toBe(400)
        ->and(collect($ops['2507-36']['detalle']['lineas'])->firstWhere('variation_id', 403)['cantidad'])->toBe(2.0)
        ->and($ops['DROP2606-41']['dropi_producto_id'])->toBe(500)
        ->and($ops['DROP2606-41']['detalle']['como'])->toContain('otro prefijo');
});

it('reparte por color, avisa los colores que no existen y que la suma no cuadra', function () {
    $ops = collect(dcPlan([
        dcFila('DROP2606-59', 'GUITARRA ANIMALITOS', 216, '108 ROSADO- 108 AZUL'),
        dcFila('AND2605-25', 'PROCESADOR DE ALIMENTOS', 75, '19 BLACO- 19 AZUL- 19 VERDE- 18 ROSADO'),
    ]))->keyBy('ref_excel');

    expect($ops['DROP2606-59']['estado'])->toBe('listo')
        ->and(collect($ops['DROP2606-59']['detalle']['lineas'])->pluck('cantidad', 'variation_id')->all())->toBe([201 => 108.0, 202 => 108.0]);

    // Procesador: sin SKU en Dropi, casa por nombre; BLACO→BLANCO; AZUL no existe.
    expect($ops['AND2605-25'])->toMatchArray(['estado' => 'revisar', 'dropi_producto_id' => 300])
        ->and($ops['AND2605-25']['motivo'])->toContain('AZUL')
        ->and(collect($ops['AND2605-25']['detalle']['lineas'])->firstWhere('variation_id', 303)['cantidad'])->toBe(19.0);
});

it('deja para revisar lo parecido y propone crear lo que no existe', function () {
    $ops = collect(dcPlan([
        dcFila('CCA-64015', 'LIBRO DE ESTUDIO', 1),
        dcFila('557-6', 'PISTOLA DE BALONES', 43, '28 ROJO- 15 VERDE'),
    ]))->keyBy('ref_excel');

    expect($ops['CCA-64015'])->toMatchArray(['tipo' => 'actualizar', 'estado' => 'revisar', 'dropi_producto_id' => null])
        ->and($ops['CCA-64015']['candidato']['id'])->toBe(600)
        ->and($ops['557-6'])->toMatchArray(['tipo' => 'crear', 'estado' => 'revisar'])
        ->and($ops['557-6']['detalle']['variaciones'])->toHaveCount(2)
        ->and($ops['557-6']['detalle']['variaciones'][0])->toMatchArray(['valor' => 'Rojo', 'sku' => '557-6-0', 'cantidad' => 28.0]);
});

it('marca para revisar dos filas que cambian la misma variación', function () {
    $ops = dcPlan([dcFila('8899-303', 'PATITO JUAN', 10, null, 2), dcFila('8899-303', 'PATITO JUAN', 20, null, 3)]);

    expect(collect($ops)->pluck('estado')->unique()->all())->toBe(['revisar'])
        ->and($ops[0]['motivo'])->toContain('misma variación');
});

// ───────────────────────── ejecutar ─────────────────────────

function dcCarga(array $op, string $modo = 'reemplazar', array $defaults = []): Op
{
    $carga = Carga::create(['numero' => Carga::siguienteNumero(), 'origen' => 'excel', 'modo' => $modo, 'concepto' => 'Inventario prueba',
        'bodega_dropi_id' => DC_BODEGA, 'estado' => Carga::PROCESANDO, 'defaults_crear' => $defaults]);

    return $carga->operaciones()->create($op + ['estado' => Op::LISTO, 'fila' => 2]);
}

it('manda la diferencia contra el stock vivo y verifica releyendo', function () {
    $op = dcCarga(['tipo' => Op::ACTUALIZAR, 'ref_excel' => 'DROP2606-59', 'dropi_producto_id' => 200, 'detalle' => ['lineas' => [
        ['variation_id' => 201, 'etiqueta' => 'ROSADO', 'cantidad' => 100], ['variation_id' => 202, 'etiqueta' => 'AZUL', 'cantidad' => 110],
    ]]]);

    $op = app(EjecutarOperacionDropi::class)->handle($op);

    expect($op->error)->toBeNull();
    expect($op->estado)->toBe(Op::OK)
        ->and($this->dropi->stock(200, 201))->toBe(100)
        ->and($this->dropi->stock(200, 202))->toBe(110)
        ->and($op->detalle['lineas'][0])->toMatchArray(['antes' => 105, 'movimiento' => 'SALIDA', 'unidades' => 5, 'despues' => 100])
        ->and($op->detalle['lineas'][1])->toMatchArray(['movimiento' => 'ENTRADA', 'unidades' => 5]);
    $mov = $this->dropi->puts[0]['cuerpo']['warehouselected'][0][0];
    expect($mov['concept'])->toContain('Inventario prueba')->toContain('DC-');
});

it('en modo sumar entra la cantidad del Excel tal cual', function () {
    $op = dcCarga(['tipo' => Op::ACTUALIZAR, 'dropi_producto_id' => 100, 'detalle' => ['lineas' => [['variation_id' => null, 'etiqueta' => 'P', 'cantidad' => 8]]]], 'sumar');

    app(EjecutarOperacionDropi::class)->handle($op);

    expect($this->dropi->stock(100))->toBe(440);
});

it('no manda nada si ya tenía el stock pedido', function () {
    $op = dcCarga(['tipo' => Op::ACTUALIZAR, 'dropi_producto_id' => 100, 'detalle' => ['lineas' => [['variation_id' => null, 'etiqueta' => 'P', 'cantidad' => 432]]]]);

    $op = app(EjecutarOperacionDropi::class)->handle($op);

    expect($op->estado)->toBe(Op::OK)->and($this->dropi->puts)->toBe([])->and($op->motivo)->toContain('no se movió');
});

it('queda en error si Dropi dice OK pero el stock no cambió', function () {
    $this->dropi->ignorarPutDe = 100;
    $op = dcCarga(['tipo' => Op::ACTUALIZAR, 'dropi_producto_id' => 100, 'detalle' => ['lineas' => [['variation_id' => null, 'etiqueta' => 'P', 'cantidad' => 300]]]]);

    $op = app(EjecutarOperacionDropi::class)->handle($op);

    expect($op->estado)->toBe(Op::ERROR)->and($op->error)->toContain('se pidió 300 y quedó 432');
});

it('crea el producto, carga su stock inicial y lo enlaza al producto del ERP', function () {
    $erp = Producto::create(['referencia' => '557-6', 'nombre' => 'Pistola de balones', 'desglose_stock' => false]);
    $op = dcCarga(['tipo' => Op::CREAR, 'ref_excel' => '557-6', 'detalle' => [
        'nombre' => 'Pistola De Balones', 'sku' => '557-6', 'descripcion' => '', 'precio' => 16000, 'sugerido' => 35000, 'cantidad' => 43,
        'variaciones' => [['valor' => 'Rojo', 'sku' => '557-6-0', 'cantidad' => 28], ['valor' => 'Verde', 'sku' => '557-6-1', 'cantidad' => 15]],
    ]], 'reemplazar', ['categoria' => ['id' => 26, 'name' => 'Jugueteria'], 'peso' => 0.5, 'largo' => 20, 'ancho' => 10, 'alto' => 10, 'privado' => false, 'garantia_dias' => 10, 'garantia_texto' => 'Con video']);

    $op = app(EjecutarOperacionDropi::class)->handle($op);

    $id = $op->dropi_producto_id;
    expect($op->estado)->toBe(Op::OK)
        ->and($this->dropi->productos[$id]['name'])->toBe('Pistola De Balones')
        ->and($this->dropi->stock($id, $id * 10))->toBe(28)
        ->and($this->dropi->stock($id, $id * 10 + 1))->toBe(15)
        ->and($erp->fresh()->dropi_sku)->toBe((string) $id);
});

// ───────────────────────── pantalla ─────────────────────────

it('solo quien tiene el permiso entra', function () {
    $this->actingAs(\App\Models\User::factory()->create())->get('/app/dropi/carga-productos')->assertForbidden();
    $this->actingAs(dcGerente())->get('/app/dropi/carga-productos')->assertOk()->assertInertia(fn ($p) => $p->component('Dropi/CargaProductos/Index')->has('cargas'));
    expect($this->getJson('/app/dropi/carga-productos/buscar?q=patito')->json())->toHaveCount(1)
        ->and($this->getJson('/app/dropi/carga-productos/buscar?q=AND2410-441')->json('0.id'))->toBe(400)
        ->and($this->getJson('/app/dropi/carga-productos/buscar?q=600')->json('0.nombre'))->toBe('Libro de estudio');
});

it('del Excel a Dropi: vista previa, corregir una fila, confirmar y procesar por tandas', function () {
    $this->actingAs(dcGerente());

    $this->post('/app/dropi/carga-productos/excel', [
        'archivo' => dcExcel([
            ['8899-303 PATITO JUAN', 332, 11661, null, 'NO HAY VARIABLE'],
            ['DROP2606-59 GUITARRA ANIMALITOS', 216, 10758, null, '108 ROSADO- 100 AZUL'],
            ['557-6 PISTOLA DE BALONES', 43, 16102, null, '28 ROJO- 15 VERDE'],
        ]),
        'modo' => 'reemplazar',
        'concepto' => 'Inventario noviembre',
    ])->assertRedirect();

    $carga = Carga::latest('id')->first();
    // Subir no lee Dropi (en Hostinger se cortaba): nace «leyendo», sin filas planeadas.
    expect($carga->estado)->toBe(Carga::LEYENDO)->and($carga->operaciones)->toHaveCount(0);
    $this->get("/app/dropi/carga-productos/{$carga->id}")->assertOk()->assertInertia(fn ($p) => $p->where('carga.estado', 'leyendo')->where('carga.filas_excel', 3));

    $carga = dcLeer($carga);
    $ops = $carga->operaciones->keyBy('ref_excel');
    expect(\Illuminate\Support\Facades\Storage::disk('local')->exists("dropi-cargas/catalogo-{$carga->id}.json"))->toBeFalse();

    // La vista previa arma lo que la pantalla necesita.
    $this->get("/app/dropi/carga-productos/{$carga->id}")->assertOk()->assertInertia(fn ($p) => $p
        ->component('Dropi/CargaProductos/Show')
        ->where('carga.editable', true)
        ->has('operaciones', 3)
        ->where('resumen.listo', 1)
        ->has('categorias', 2)
        ->where('operaciones.1.detalle.lineas.0.stock_plan', 105));

    expect($carga->estado)->toBe(Carga::BORRADOR)
        ->and($ops['8899-303']->estado)->toBe(Op::LISTO)
        ->and($ops['DROP2606-59']->estado)->toBe(Op::REVISAR) // suma 208 ≠ 216
        ->and($ops['557-6']->tipo)->toBe(Op::CREAR);
    expect($this->dropi->puts)->toBe([]); // la vista previa no escribe en Dropi

    // Corrige la guitarra y le pone precio a la pistola.
    $this->put("/app/dropi/carga-productos/{$carga->id}/operaciones/{$ops['DROP2606-59']->id}", ['accion' => 'guardar', 'lineas' => [['cantidad' => 108], ['cantidad' => 108]]]);
    $this->put("/app/dropi/carga-productos/{$carga->id}/operaciones/{$ops['557-6']->id}", ['accion' => 'guardar', 'detalle' => ['precio' => 16000, 'sugerido' => 35000]]);
    expect($ops['DROP2606-59']->fresh()->estado)->toBe(Op::LISTO)->and($ops['557-6']->fresh()->estado)->toBe(Op::LISTO);

    // Sin los datos para crear, no deja confirmar.
    $this->post("/app/dropi/carga-productos/{$carga->id}/confirmar")->assertSessionHasErrors('confirmar');

    $this->put("/app/dropi/carga-productos/{$carga->id}", ['concepto' => 'Inventario noviembre', 'modo' => 'reemplazar', 'defaults' => [
        'categoria_id' => 26, 'peso' => 0.5, 'largo' => 20, 'ancho' => 10, 'alto' => 10, 'privado' => false, 'garantia_dias' => 10, 'garantia_texto' => 'Con video',
    ]])->assertSessionHasNoErrors();
    $this->post("/app/dropi/carga-productos/{$carga->id}/confirmar")->assertSessionHasNoErrors();

    $r = $this->postJson("/app/dropi/carga-productos/{$carga->id}/procesar")->assertOk()->json();

    expect($r)->toMatchArray(['estado' => 'terminado', 'ok' => 3, 'errores' => 0, 'pendientes' => 0])
        ->and($this->dropi->stock(100))->toBe(332)
        ->and($this->dropi->stock(200, 202))->toBe(108);
});

it('una fila sin emparejar se resuelve eligiendo el candidato', function () {
    $this->actingAs(dcGerente());
    $this->post('/app/dropi/carga-productos/excel', ['archivo' => dcExcel([['CCA-64015 LIBRO DE ESTUDIO', 1, 11078, null, 'NO HAY VARIABLE']]), 'modo' => 'reemplazar', 'concepto' => 'Inventario noviembre']);
    $op = dcLeer(Carga::latest('id')->first())->operaciones->first();
    expect($op->estado)->toBe(Op::REVISAR);

    $this->put("/app/dropi/carga-productos/{$op->carga_id}/operaciones/{$op->id}", ['accion' => 'usar_producto', 'dropi_producto_id' => 600]);

    expect($op->fresh())->estado->toBe(Op::LISTO)->dropi_producto_id->toBe(600);
});

it('tras confirmar no se puede editar la carga', function () {
    $op = dcCarga(['tipo' => Op::ACTUALIZAR, 'dropi_producto_id' => 100, 'detalle' => ['lineas' => [['variation_id' => null, 'etiqueta' => 'P', 'cantidad' => 1]]]]);
    $this->actingAs(dcGerente())
        ->put("/app/dropi/carga-productos/{$op->carga_id}/operaciones/{$op->id}", ['accion' => 'omitir'])
        ->assertStatus(422);
});

// ───────────────────────── razón por fila y Excel ─────────────────────────

it('cada fila manda su propia razón a Dropi y la razón se puede editar después', function () {
    $op = dcCarga(['tipo' => Op::ACTUALIZAR, 'dropi_producto_id' => 100, 'razon' => 'Sobrante del conteo de bodega',
        'detalle' => ['lineas' => [['variation_id' => null, 'etiqueta' => 'P', 'cantidad' => 2]]]], 'sumar');

    app(EjecutarOperacionDropi::class)->handle($op);

    expect($this->dropi->puts[0]['cuerpo']['warehouselected'][0]['concept'])->toStartWith('Sobrante del conteo de bodega · DC-')
        ->and($op->fresh()->razon_enviada)->toBe('Sobrante del conteo de bodega');

    // Ya terminada, se edita en el ERP; lo enviado a Dropi queda como estaba.
    $op->carga->forceFill(['estado' => Carga::TERMINADO])->save();
    $this->actingAs(dcGerente())
        ->put("/app/dropi/carga-productos/{$op->carga_id}/operaciones/{$op->id}/razon", ['razon' => 'Devolución de cliente'])
        ->assertRedirect();
    expect($op->fresh())->razon->toBe('Devolución de cliente')->razon_enviada->toBe('Sobrante del conteo de bodega');

    // Vacía = vuelve al concepto de la carga.
    $this->put("/app/dropi/carga-productos/{$op->carga_id}/operaciones/{$op->id}/razon", ['razon' => '']);
    expect($op->fresh()->razon)->toBeNull()
        ->and(EjecutarOperacionDropi::razon($op->carga, $op->fresh()))->toBe('Inventario prueba');
});

it('el Excel del resultado dice qué se actualizó, qué se creó y qué no, con su razón', function () {
    $hecha = dcCarga(['tipo' => Op::ACTUALIZAR, 'ref_excel' => '8899-303', 'nombre_excel' => 'PATITO JUAN', 'dropi_producto_id' => 100,
        'detalle' => ['lineas' => [['variation_id' => null, 'etiqueta' => 'Producto', 'cantidad' => 8]]]], 'sumar');
    app(EjecutarOperacionDropi::class)->handle($hecha);
    $carga = $hecha->carga;
    $carga->operaciones()->create(['fila' => 3, 'ref_excel' => '557-6', 'nombre_excel' => 'PISTOLA', 'tipo' => Op::CREAR, 'estado' => Op::REVISAR,
        'motivo' => 'Falta el precio y el precio sugerido.', 'razon' => 'Producto nuevo de importación', 'detalle' => ['nombre' => 'Pistola', 'variaciones' => []]]);

    $r = $this->actingAs(dcGerente())->get("/app/dropi/carga-productos/{$carga->id}/excel")->assertOk();
    $ruta = tempnam(sys_get_temp_dir(), 'dcx') . '.xlsx';
    file_put_contents($ruta, $r->streamedContent());
    $libro = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta);
    $filas = $libro->getSheetByName('Productos')->toArray(null, true, false, false);

    expect($libro->getSheetNames())->toBe(['Resumen', 'Productos'])
        ->and($filas[1])->toMatchArray([1 => '8899-303', 5 => 'Actualizado', 7 => 100, 10 => 'Inventario prueba'])
        ->and($filas[1][9])->toContain('ENTRADA 8 (432 → 440)')
        ->and($filas[2])->toMatchArray([1 => '557-6', 5 => 'Pendiente: crear', 10 => 'Producto nuevo de importación'])
        ->and($filas[2][6])->toContain('Falta el precio');
});

// ───────────────────────── primero el inventario del ERP (CI-), luego Dropi ─────────────────────────

function dcBodegaErp(): \App\Modules\Dropi\Models\InventarioUbicacion
{
    return \App\Modules\Dropi\Models\InventarioUbicacion::firstOrCreate(['codigo' => 'DC-ERP'], ['nombre' => 'Bodega ERP', 'activa' => true, 'categoria' => 'venta']);
}

function dcCargaConIngreso(array $filas, string $modo = 'sumar', array $ingreso = []): Carga
{
    test()->post('/app/dropi/carga-productos/excel', ['archivo' => dcExcel($filas), 'modo' => $modo, 'concepto' => 'INTRODUCCION DE PRODUCTOS DROPI ERP']);
    $carga = dcLeer(Carga::latest('id')->first());
    test()->put("/app/dropi/carga-productos/{$carga->id}", ['concepto' => 'INTRODUCCION DE PRODUCTOS DROPI ERP', 'modo' => $modo, 'ingreso_erp' => $ingreso + [
        'activo' => true, 'motivo' => 'saldo_inicial', 'observacion' => 'Inventario recibido en bodega', 'ubicacion_id' => dcBodegaErp()->id,
    ]])->assertSessionHasNoErrors();

    return $carga->refresh();
}

it('al confirmar registra primero el ingreso al ERP (CI- con kardex, costo y asiento) y después manda a Dropi', function () {
    \Illuminate\Support\Facades\Queue::fake(); // los productos nuevos se envían a SIIGO por cola
    $this->actingAs(dcGerente());
    $existente = Producto::create(['referencia' => '8899-303', 'nombre' => 'Patito Juan', 'desglose_stock' => false, 'precio_proveedor' => 0]);

    $carga = dcCargaConIngreso([
        ['8899-303 PATITO JUAN', 10, 11661, null, 'NO HAY VARIABLE'],
        ['NUEVO-1 PRODUCTO QUE EL ERP NO TIENE', 3, 2000, null, 'NO HAY VARIABLE'],
    ]);
    expect($this->dropi->puts)->toBe([]);

    $this->post("/app/dropi/carga-productos/{$carga->id}/confirmar")->assertSessionHasNoErrors();
    $carga->refresh();

    // 1 · el ERP: un CI- con las dos filas, el producto nuevo creado y el costo del Excel
    $ci = \App\Modules\Inventario\Models\CargaInventario::findOrFail($carga->carga_inventario_id);
    $nuevo = Producto::where('referencia', 'NUEVO-1')->sole();
    expect($ci->motivo->value)->toBe('saldo_inicial')
        ->and($ci->canal)->toBe('dropi')
        ->and((float) $ci->total_unidades)->toBe(13.0)
        ->and((float) $ci->total_valor)->toBe(10 * 11661 + 3 * 2000.0)
        ->and((float) $existente->fresh()->precio_proveedor)->toBe(11661.0) // estaba en 0: lo llena
        ->and($nuevo->desglose_stock)->toBeFalse()
        ->and((float) $nuevo->precio_proveedor)->toBe(2000.0)
        ->and(InventarioMovimiento::where('referencia_tipo', \App\Modules\Inventario\Models\CargaInventario::class)->where('referencia_id', $ci->id)->count())->toBe(2)
        ->and($carga->ingreso_erp['resultado'])->toMatchArray(['creados' => 1, 'actualizados' => 1, 'movimientos' => 2])
        ->and($carga->ingreso_erp['resultado']['filas']['NUEVO-1'])->toBe('creado en el ERP');
    $asiento = \App\Modules\Cartera\Models\MovimientoContable::where('origen_type', \App\Modules\Inventario\Models\CargaInventario::class)->where('origen_id', $ci->id)->get();
    expect((float) $asiento->sum('debe'))->toBe(122610.0)->and((float) $asiento->sum('haber'))->toBe(122610.0);

    // 2 · después Dropi: el Patito suma sus 10
    $this->postJson("/app/dropi/carga-productos/{$carga->id}/procesar")->assertOk();
    expect($this->dropi->stock(100))->toBe(442);

    // Confirmar de nuevo (otra carga) no duplica el ingreso de ésta.
    expect(fn () => app(\App\Modules\Dropi\Actions\RegistrarIngresoErpDesdeCarga::class)->handle($carga->fresh()))->not->toThrow(Throwable::class);
    expect(InventarioMovimiento::where('notas', 'like', "{$carga->numero} · Ingreso al ERP%")->count())->toBe(2);
});

it('no registra ingreso al ERP si el Excel dice cuánto debe quedar, ni manda nada a Dropi', function () {
    $this->actingAs(dcGerente());
    $carga = dcCargaConIngreso([['8899-303 PATITO JUAN', 10, 11661, null, 'NO HAY VARIABLE']], 'reemplazar');

    $this->post("/app/dropi/carga-productos/{$carga->id}/confirmar")->assertSessionHasErrors('confirmar');

    expect($carga->fresh()->estado)->toBe(Carga::BORRADOR)
        ->and($carga->fresh()->carga_inventario_id)->toBeNull()
        ->and($this->dropi->puts)->toBe([]);
});

it('una compra al ERP exige el proveedor antes de confirmar', function () {
    $this->actingAs(dcGerente());
    $carga = dcCargaConIngreso([['8899-303 PATITO JUAN', 10, 11661, null, 'NO HAY VARIABLE']], 'sumar', ['motivo' => 'compra']);

    $this->post("/app/dropi/carga-productos/{$carga->id}/confirmar")->assertSessionHasErrors(['confirmar' => 'Para registrar el ingreso al inventario del ERP falta: el proveedor.']);
});

it('el ingreso al ERP usa el producto traído de Dropi aunque la referencia del Excel sea otra', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $this->actingAs(dcGerente());
    // Así quedó el ERP tras reimportar desde Dropi: referencia de Dropi y su id guardado.
    $erp = Producto::create(['referencia' => 'AND2606-41', 'nombre' => 'Gusanos magicos moving', 'desglose_stock' => false, 'precio_proveedor' => 0]);
    $erp->forceFill(['dropi_sku' => '500'])->save(); // no es asignable en masa

    $carga = dcCargaConIngreso([['DROP2606-41 GUSANOS MÁGICOS', 178, 4754, null, 'NO HAY VARIABLE']]);
    $this->post("/app/dropi/carga-productos/{$carga->id}/confirmar")->assertSessionHasNoErrors();

    expect(Producto::where('referencia', 'DROP2606-41')->exists())->toBeFalse() // no se duplicó
        ->and((int) InventarioMovimiento::where('producto_id', $erp->id)->sum('cantidad'))->toBe(178)
        ->and((float) $erp->fresh()->precio_proveedor)->toBe(4754.0)
        ->and($carga->fresh()->ingreso_erp['resultado'])->toMatchArray(['creados' => 0, 'actualizados' => 1]);
});

/*
 * «Vincular con el ERP» en el otro sentido y el stock que sube al recibir
 * (reunión con la señora Lahida, 24-nov-2026: de 16 productos del SA383, 15 no
 * existían en Dropi y la recepción no tenía a dónde mandar el stock).
 */
describe('lo recibido en el ERP llega a Dropi (24-nov-2026)', function () {
    beforeEach(function () {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->seed(\Database\Seeders\PermisosSeeder::class);
        $u = \App\Models\User::factory()->create();
        $u->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
        $this->actingAs($u);
        $this->bodegaErp = \App\Modules\Dropi\Models\InventarioUbicacion::create(['codigo' => 'VD-RA1', 'nombre' => 'Rack A · Nivel 01',
            'categoria' => \App\Modules\Dropi\Enums\CategoriaUbicacion::Venta, 'activa' => true, 'disponible_para_venta' => true]);
        $this->proveedor = \App\Models\Contacto::create(['tipo_documento' => 'NIT', 'numero_documento' => '901738354', 'nombre_completo' => 'GREAT BABY', 'es_proveedor' => true, 'en_dropi' => true, 'activo' => true]);
        // La foto del catálogo de Dropi, como la deja «Leer de Dropi».
        \Illuminate\Support\Facades\Storage::disk('local')->put(\App\Modules\Dropi\Services\CatalogoDropi::FOTO, json_encode([
            'leido_at' => now()->toIso8601String(),
            'productos' => array_map(fn ($p) => \App\Modules\Dropi\Services\CatalogoDropi::resumir($p), array_values($this->dropi->productos)),
        ]));
    });

    it('lo recibido que Dropi no conoce aparece en «Del ERP, no están en Dropi»; lo vinculado no', function () {
        $nuevo = vdProducto('VD-TIB', 'TIBURON');
        $conDropi = vdProducto('VD-PAT', 'Patito');
        $conDropi->forceFill(['dropi_sku' => '100'])->save();
        vdRecibir([[$nuevo, null, 192], [$conDropi, null, 10]]);

        $lista = app(\App\Modules\Dropi\Services\ProductosSinDropi::class)->lista();

        expect(array_column($lista, 'referencia'))->toBe(['VD-TIB'])
            ->and($lista[0]['stock'])->toBe(192);
    });

    it('«Asociar» vincula cada llave de Dropi con la ficha y «Igualar» deja en Dropi el stock del ERP', function () {
        $p = vdProducto('VD-PAT', 'Pato de hule');
        vdRecibir([[$p, null, 20]]);
        $servicio = app(\App\Modules\Dropi\Services\ProductosSinDropi::class);

        $servicio->asociar($p, 100, auth()->id());

        expect($p->fresh()->dropi_sku)->toBe('100')
            ->and(\App\Modules\Dropi\Models\DropiVinculoProducto::where('sku_dropi', '8899-303')->value('producto_id'))->toBe($p->id)
            ->and($servicio->lista())->toBe([]);

        // Igualar: borrador con el antes y el después; nada sube hasta ejecutarlo.
        $carga = $servicio->cargaIgualar($p->fresh(), auth()->id());
        $op = $carga->operaciones()->first();
        expect($carga->estado)->toBe(Carga::BORRADOR)
            ->and($op->estado)->toBe(Op::LISTO)
            ->and($this->dropi->stock(100))->toBe(432);

        app(EjecutarOperacionDropi::class)->handle($op);

        expect($op->fresh()->estado)->toBe(Op::OK)
            ->and($this->dropi->stock(100))->toBe(20);
    });

    it('«Asociar» no vincula nada si a la ficha le falta un color de Dropi', function () {
        $p = vdProducto('VD-GUI', 'Guitarra', ['ROSADO']);

        expect(fn () => app(\App\Modules\Dropi\Services\ProductosSinDropi::class)->asociar($p, 200, auth()->id()))
            ->toThrow(\DomainException::class, 'AZUL');

        expect(\App\Modules\Dropi\Models\DropiVinculoProducto::count())->toBe(0)
            ->and($p->fresh()->dropi_sku)->toBeNull();
    });

    it('«Crear en Dropi» arma el producto con los colores y el stock del ERP y nace vinculado por color', function () {
        $p = vdProducto('VD-CAR', 'Carro didáctico', ['ROJO', 'AZUL']);
        [$rojo, $azul] = [$p->variantes->firstWhere('color_nombre', 'ROJO'), $p->variantes->firstWhere('color_nombre', 'AZUL')];
        vdRecibir([[$p, $rojo, 5], [$p, $azul, 3]]);

        $carga = app(\App\Modules\Dropi\Services\ProductosSinDropi::class)->cargaCrear($p, auth()->id());
        $op = $carga->operaciones()->first();
        expect($op->tipo)->toBe(Op::CREAR)
            ->and(collect($op->detalle['variaciones'])->pluck('cantidad', 'valor')->all())->toBe(['Rojo' => 5, 'Azul' => 3]);

        // Lo que bodega llena en la carga antes de confirmar.
        $carga->forceFill(['defaults_crear' => ['categoria' => ['id' => 26, 'name' => 'Jugueteria'], 'peso' => 1, 'largo' => 10, 'ancho' => 10, 'alto' => 10,
            'privado' => false, 'garantia_dias' => 10, 'garantia_texto' => 'Garantía por defectos de fábrica']])->save();
        $op->forceFill(['estado' => Op::LISTO, 'detalle' => ['precio' => 20000, 'sugerido' => 40000] + $op->detalle])->save();

        app(EjecutarOperacionDropi::class)->handle($op);

        $op->refresh();
        $id = $op->dropi_producto_id;
        $vars = collect($this->dropi->productos[$id]['variations'])->mapWithKeys(fn ($v) => [$v['attribute_values'][0]['value'] => $v['id']]);
        expect($op->estado)->toBe(Op::OK)
            ->and($p->fresh()->dropi_sku)->toBe((string) $id)
            ->and($this->dropi->stock($id, $vars['Rojo']))->toBe(5)
            ->and($this->dropi->stock($id, $vars['Azul']))->toBe(3)
            ->and(\App\Modules\Dropi\Models\DropiVinculoProducto::where('sku_dropi', 'DV-' . $vars['Rojo'])->value('variante_id'))->toBe($rojo->id)
            ->and(\App\Modules\Dropi\Models\DropiVinculoProducto::where('sku_dropi', 'DV-' . $vars['Azul'])->value('variante_id'))->toBe($azul->id);
    });

    it('un segundo clic en «Crear en Dropi» reabre la carga abierta en vez de armar otra (dos serían dos productos en Dropi)', function () {
        $p = vdProducto('VD-DOS', 'Tambor');
        vdRecibir([[$p, null, 4]]);
        $servicio = app(\App\Modules\Dropi\Services\ProductosSinDropi::class);

        $primera = $servicio->cargaCrear($p, auth()->id());
        expect($servicio->cargaCrear($p, auth()->id())->id)->toBe($primera->id)
            ->and(\App\Modules\Dropi\Models\DropiCargaProducto::whereHas('operaciones', fn ($q) => $q->where('tipo', Op::CREAR))->count())->toBe(1);

        // Ya terminada, sí se puede armar otra (por ejemplo, si falló en Dropi y se reintenta).
        $primera->forceFill(['estado' => \App\Modules\Dropi\Models\DropiCargaProducto::TERMINADO])->save();
        expect($servicio->cargaCrear($p, auth()->id())->id)->not->toBe($primera->id);
    });

    it('al recibir, lo apto se SUMA al stock de Dropi y queda la carga como constancia; lo que Dropi no conoce queda omitido', function () {
        $guitarra = vdProducto('VD-GUI', 'Guitarra', ['ROSADO', 'AZUL']);
        $guitarra->forceFill(['dropi_sku' => '200'])->save();
        $sinDropi = vdProducto('VD-TIB', 'TIBURON');
        $rec = vdRecibir([
            [$guitarra, $guitarra->variantes->firstWhere('color_nombre', 'ROSADO'), 4],
            [$guitarra, $guitarra->variantes->firstWhere('color_nombre', 'AZUL'), 6],
            [$sinDropi, null, 192],
        ]);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Modules\Dropi\Jobs\EnviarStockRecepcionADropiJob::class);
        app()->call([new \App\Modules\Dropi\Jobs\EnviarStockRecepcionADropiJob($rec->id), 'handle']);

        $carga = Carga::where('recepcion_id', $rec->id)->firstOrFail();
        $ops = $carga->operaciones->keyBy('ref_excel');
        expect($carga->estado)->toBe(Carga::TERMINADO)
            ->and($carga->modo)->toBe('sumar')
            ->and($ops['VD-GUI']->estado)->toBe(Op::OK)
            ->and($ops['VD-TIB']->estado)->toBe(Op::OMITIDO)
            ->and($this->dropi->stock(200, 201))->toBe(105 + 4)
            ->and($this->dropi->stock(200, 202))->toBe(105 + 6);

        // Si el job corre otra vez no suma dos veces.
        app()->call([new \App\Modules\Dropi\Jobs\EnviarStockRecepcionADropiJob($rec->id), 'handle']);
        expect($this->dropi->stock(200, 201))->toBe(109)
            ->and(Carga::where('recepcion_id', $rec->id)->count())->toBe(1);
    });

    it('con la regla apagada no sube nada', function () {
        \App\Support\Reglas::set('dropi.stock_al_recibir', '0');
        $p = vdProducto('VD-PAT', 'Pato');
        $p->forceFill(['dropi_sku' => '100'])->save();
        $rec = vdRecibir([[$p, null, 10]]);

        app()->call([new \App\Modules\Dropi\Jobs\EnviarStockRecepcionADropiJob($rec->id), 'handle']);

        expect(Carga::where('recepcion_id', $rec->id)->exists())->toBeFalse()
            ->and($this->dropi->stock(100))->toBe(432);
    });
});

/** Compra de Dropi aprobada y recibida apta: [[producto, variante|null, cantidad], …]. */
function vdRecibir(array $lineas): \App\Modules\Compras\Models\RecepcionCompra
{
    $oc = \App\Modules\Compras\Actions\CrearOrdenCompra::run([
        'proveedor_id' => test()->proveedor->id, 'bodega_id' => test()->bodegaErp->id, 'tipo' => 'nacional',
        'moneda' => 'COP', 'tasa_cambio' => 1, 'linea_negocio' => \App\Modules\Contabilidad\Enums\LineaNegocio::Dropi,
        'items' => array_map(fn ($l) => ['producto_id' => $l[0]->id, 'variante_id' => $l[1]?->id, 'descripcion' => $l[0]->nombre,
            'cantidad' => $l[2], 'precio_unit' => 1000, 'descuento_pct' => 0, 'iva_pct' => 0], $lineas),
    ]);
    \App\Modules\Compras\Actions\AprobarOrdenCompra::run($oc);

    return \App\Modules\Compras\Actions\RegistrarRecepcion::run([
        'orden_id' => $oc->id, 'factura_proveedor' => 'SA383',
        'items' => $oc->items->map(fn ($i) => ['orden_item_id' => $i->id, 'cantidad_recibida' => $i->cantidad, 'clasificacion' => 'apto'])->all(),
    ]);
}

function vdProducto(string $ref, string $nombre, array $colores = []): Producto
{
    $p = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => $ref, 'nombre' => $nombre, 'activo' => true, 'desglose_stock' => $colores !== []]));
    foreach ($colores as $c) {
        \App\Modules\Dropi\Models\ProductoVariante::create(['producto_id' => $p->id, 'color_nombre' => $c, 'color_codigo' => $c, 'codigo_barras' => "{$ref}-{$c}"]);
    }

    return $p->load('variantes');
}
