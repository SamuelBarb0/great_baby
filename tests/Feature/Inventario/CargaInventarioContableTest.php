<?php

/**
 * Toda carga masiva de inventario dice por qué entra y queda en el libro.
 *
 * Pedido de la contadora (reunión 2026-11-17): «no que se suba porque se
 * subió; si me ingresa un inventario es porque se lo debo a alguien o porque
 * lo pagué de algún lado». Lo que se verifica:
 *   · sin motivo/observación la carga se rechaza y no entra nada;
 *   · compra y aporte exigen tercero;
 *   · la carga deja un documento CI-… del que cuelga el kardex;
 *   · el asiento cuadra: débito 1435 = crédito a la cuenta del motivo, con tercero;
 *   · las filas sin costo mueven stock pero no entran al asiento;
 *   · re-subir el mismo Excel no duplica ni el kardex ni el asiento;
 *   · el detalle contable y el documento muestran el porqué.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Models\CargaInventario;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

beforeEach(function () {
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    $rol = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']);
    $this->operador = \App\Models\User::factory()->create();
    $this->operador->assignRole($rol);
    $this->actingAs($this->operador);

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'DRP', 'nombre' => 'Bodega Dropi',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $this->proveedor = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900555111',
        'nombre_completo' => 'Importadora China SAS', 'es_proveedor' => true,
    ]);

    // Producto que ya existe con costo: su fila vale en el libro.
    Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GB-TET-01', 'nombre' => 'Tetero 250ml',
        'desglose_stock' => false, 'stock_directo' => 0,
        'precio_proveedor' => 12000, 'activo' => true,
    ]));
});

function excelCargaContable(): string
{
    $path = tempnam(sys_get_temp_dir(), 'cic').'.xlsx';
    $writer = new XlsxWriter();
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Hoja1');
    $writer->addRow(Row::fromValues(['INVENTARIO DR REPORTE']));
    $writer->addRow(Row::fromValues(['REFERENCIA', 'DESCRIPCION', 'EXISTENCIA', 'VARIACION']));
    $writer->addRow(Row::fromValues(['ALIMENTACION']));
    $writer->addRow(Row::fromValues(['GB-TET-01', 'Tetero 250ml', 10, 'NA']));      // 10 × 12.000
    $writer->addRow(Row::fromValues(['GB-NUEVO-01', 'Babero nuevo', 5, 'NA']));    // nuevo, sin costo
    $writer->close();

    return $path;
}

function subirCargaContable(array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->post('/app/inventario/importar-cliente', array_merge([
        'archivo' => new \Illuminate\Http\UploadedFile(excelCargaContable(), 'inventario.xlsx', null, null, true),
        'bodega_id' => test()->bodega->id,
        'hoja' => 'Hoja1',
        'motivo' => 'compra',
        'observacion' => 'Contenedor China noviembre',
        'soporte' => 'FC-8891',
        'tercero_id' => test()->proveedor->id,
    ], $extra));
}

it('rechaza la carga sin motivo ni observación y no mueve stock', function () {
    subirCargaContable(['motivo' => null, 'observacion' => null])
        ->assertSessionHasErrors(['motivo', 'observacion']);

    expect(InventarioMovimiento::count())->toBe(0)
        ->and(CargaInventario::count())->toBe(0)
        ->and(MovimientoContable::count())->toBe(0);
});

it('exige el proveedor cuando la mercancía es una compra', function () {
    subirCargaContable(['tercero_id' => null])->assertSessionHasErrors(['tercero_id']);

    expect(InventarioMovimiento::count())->toBe(0);
});

it('deja un documento con su porqué y el kardex colgando de él', function () {
    subirCargaContable()->assertSessionHasNoErrors();

    $carga = CargaInventario::sole();

    expect($carga->numero)->toStartWith('CI-')
        ->and($carga->motivo->value)->toBe('compra')
        ->and($carga->observacion)->toBe('Contenedor China noviembre')
        ->and($carga->soporte)->toBe('FC-8891')
        ->and((int) $carga->tercero_id)->toBe($this->proveedor->id)
        ->and($carga->cuenta_contrapartida)->toBe('220505')
        ->and($carga->referencias)->toBe(2)
        ->and($carga->referencias_sin_costo)->toBe(1)
        ->and((float) $carga->total_valor)->toBe(120000.0)
        ->and(InventarioMovimiento::where('referencia_tipo', CargaInventario::class)
            ->where('referencia_id', $carga->id)->count())->toBe(2);
});

it('escribe un asiento que cuadra: 1435 contra la cuenta por pagar del proveedor', function () {
    subirCargaContable()->assertSessionHasNoErrors();

    $carga = CargaInventario::sole();
    $lineas = MovimientoContable::where('origen_type', CargaInventario::class)
        ->where('origen_id', $carga->id)->get();

    $debito = $lineas->firstWhere('debe', '>', 0);
    $credito = $lineas->firstWhere('haber', '>', 0);

    expect($lineas)->toHaveCount(2)
        ->and((float) $lineas->sum('debe'))->toBe((float) $lineas->sum('haber'))
        ->and($debito->cuenta_puc)->toBe('1435')
        ->and((float) $debito->debe)->toBe(120000.0)
        ->and($credito->cuenta_puc)->toBe('220505')
        ->and((float) $credito->haber)->toBe(120000.0)
        // El mayor de proveedores se puede conciliar: el crédito lleva el tercero.
        ->and($credito->tercero_type)->toBe(Contacto::class)
        ->and((int) $credito->tercero_id)->toBe($this->proveedor->id)
        ->and($credito->descripcion)->toContain('FC-8891');
});

it('usa la cuenta del motivo: el inventario inicial no se le debe a un proveedor', function () {
    subirCargaContable(['motivo' => 'saldo_inicial', 'tercero_id' => null])->assertSessionHasNoErrors();

    $credito = MovimientoContable::where('haber', '>', 0)->sole();

    expect($credito->cuenta_puc)->toBe('3705')
        ->and($credito->tercero_id)->toBeNull();
});

it('re-subir el mismo Excel no duplica ni el kardex ni el asiento', function () {
    subirCargaContable()->assertSessionHasNoErrors();
    subirCargaContable()->assertSessionHasNoErrors();

    expect(CargaInventario::count())->toBe(1)
        ->and(InventarioMovimiento::count())->toBe(2)
        ->and(MovimientoContable::count())->toBe(2)
        ->and(session('importResumen')['ya_cargados'])->toBe(2);
});

it('el dry-run dice cuánto valdría y cuántas filas no tienen costo, sin escribir', function () {
    subirCargaContable(['dry_run' => true])->assertSessionHasNoErrors();

    $r = session('importResumen');

    expect($r['valor'])->toEqual(120000)
        ->and($r['sin_costo'])->toBe(1)
        ->and($r['sin_costo_detalle'])->toBe(['GB-NUEVO-01'])
        ->and($r['cuenta_contrapartida'])->toBe('220505')
        ->and(CargaInventario::count())->toBe(0)
        ->and(MovimientoContable::count())->toBe(0);
});

it('desde contabilidad se llega al porqué de la carga', function () {
    subirCargaContable()->assertSessionHasNoErrors();
    $carga = CargaInventario::sole();

    $this->get("/app/contabilidad/reporte-detalle?tipo=carga_inventario&id={$carga->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Contabilidad/ReporteDetalle')
            ->where('meta.numero', $carga->numero)
            ->where('meta.origen', 'Carga de inventario')
            ->where('meta.va_siigo', false)
            ->where('meta.url_documento', "/app/inventario/cargas/{$carga->id}")
            ->where('meta.detalle', fn ($d) => str_contains($d, 'Contenedor China noviembre'))
            ->has('movimientos', 2)
            ->where('totales.diff', 0));

    $this->get("/app/inventario/cargas/{$carga->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Inventario/Cargas/Show')
            ->where('carga.motivo', 'Compra de mercancía')
            ->has('kardex', 2)
            ->has('asiento', 2));
});

// ── Columna COSTO ───────────────────────────────────────────────────────────
// Sin ella todo producto nuevo nacía a costo 0 y la carga no valía nada en el
// libro. Con ella, el Excel dice cuánto vale cada referencia de ESTE lote.

/** @param  list<list<string|int|float>>  $filas */
function excelConCosto(array $encabezado, array $filas): string
{
    $path = tempnam(sys_get_temp_dir(), 'cco').'.xlsx';
    $writer = new XlsxWriter();
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Hoja1');
    $writer->addRow(Row::fromValues(['INVENTARIO DR REPORTE']));
    $writer->addRow(Row::fromValues($encabezado));
    $writer->addRow(Row::fromValues(['ALIMENTACION']));
    foreach ($filas as $f) $writer->addRow(Row::fromValues($f));
    $writer->close();

    return $path;
}

function subirConCosto(string $path, array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->post('/app/inventario/importar-cliente', array_merge([
        'archivo' => new \Illuminate\Http\UploadedFile($path, 'inventario.xlsx', null, null, true),
        'bodega_id' => test()->bodega->id,
        'hoja' => 'Hoja1',
        'motivo' => 'saldo_inicial',
        'observacion' => 'Inventario inicial con costos',
    ], $extra));
}

it('entiende el costo escrito como número o en formato colombiano', function (string $crudo, ?float $esperado) {
    expect(\App\Modules\Inventario\Actions\RegistrarCargaInventario::parsearCosto($crudo))->toBe($esperado);
})->with([
    ['45000', 45000.0],
    ['45000.5', 45000.5],
    ['$ 45.000', 45000.0],
    ['1.250.000', 1250000.0],
    ['12.500,50', 12500.5],
    ['12,5', 12.5],
    ['45,000', 45000.0],
    ['', null],
    ['NA', null],
    ['0', null],
    ['-500', null],
]);

it('valora los productos nuevos con la columna COSTO y la deja en el catálogo', function () {
    $path = excelConCosto(
        ['REFERENCIA', 'DESCRIPCION', 'EXISTENCIA', 'VARIACION', 'COSTO UNITARIO'],
        [
            ['GB-BAB-01', 'Babero', 10, 'NA', 3500],           // celda numérica
            ['GB-CHU-01', 'Chupo', 4, 'NA', '$ 12.500'],       // texto con miles
            ['GB-SIN-01', 'Sin costo', 2, 'NA', ''],           // vacía → catálogo (0)
        ]
    );

    subirConCosto($path)->assertSessionHasNoErrors();

    $carga = CargaInventario::sole();
    $r = session('importResumen');

    expect((float) $carga->total_valor)->toBe(85000.0)               // 35.000 + 50.000
        ->and($carga->referencias_sin_costo)->toBe(1)
        ->and($r['columna_costo'])->toBe('COSTO UNITARIO')
        ->and($r['con_costo_excel'])->toBe(2)
        ->and((float) MovimientoContable::where('cuenta_puc', '1435')->sum('debe'))->toBe(85000.0)
        ->and((float) Producto::where('referencia', 'GB-BAB-01')->value('precio_proveedor'))->toBe(3500.0)
        ->and((float) Producto::where('referencia', 'GB-CHU-01')->value('precio_proveedor'))->toBe(12500.0);
});

it('usa el costo del Excel para el lote sin pisar el costo real del catálogo', function () {
    // GB-TET-01 ya existe con costo 12.000 (beforeEach).
    $path = excelConCosto(
        ['REFERENCIA', 'DESCRIPCION', 'EXISTENCIA', 'VARIACION', 'COSTO'],
        [['GB-TET-01', 'Tetero 250ml', 10, 'NA', 15000]]
    );

    subirConCosto($path)->assertSessionHasNoErrors();

    $mov = InventarioMovimiento::sole();

    expect((float) $mov->costo_unit)->toBe(15000.0)
        ->and((float) CargaInventario::sole()->total_valor)->toBe(150000.0)
        ->and((float) Producto::where('referencia', 'GB-TET-01')->value('precio_proveedor'))->toBe(12000.0);
});

it('ignora una columna de costo total y avisa los costos que no entiende', function () {
    $path = excelConCosto(
        ['REFERENCIA', 'DESCRIPCION', 'EXISTENCIA', 'VARIACION', 'COSTO TOTAL', 'COSTO'],
        [
            ['GB-BAB-01', 'Babero', 10, 'NA', 35000, 3500],
            ['GB-RAR-01', 'Raro', 3, 'NA', 0, 'consultar'],
        ]
    );

    subirConCosto($path, ['dry_run' => true])->assertSessionHasNoErrors();

    $r = session('importResumen');

    // Toma «COSTO» (unitario), no «COSTO TOTAL»: si no, el babero valdría 350.000.
    expect($r['columna_costo'])->toBe('COSTO')
        ->and($r['valor'])->toEqual(35000)
        ->and($r['costo_ilegible'])->toBe(['GB-RAR-01: «consultar»'])
        ->and($r['sin_costo'])->toBe(1);
});

it('el balance se puede filtrar y agrupar por tercero', function () {
    subirCargaContable()->assertSessionHasNoErrors();

    $this->get('/app/contabilidad?acumulado=1&agrupar_tercero=1')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('porCuenta', fn ($filas) => collect($filas)->contains(fn ($f) => $f['cuenta_puc'] === '220505'
                && str_contains($f['tercero'], 'Importadora China SAS')
                && (float) $f['haber'] === 120000.0)));

    $otro = Contacto::create(['numero_documento' => '1010', 'nombre_completo' => 'Otro tercero']);
    $this->get("/app/contabilidad?acumulado=1&tercero_id={$otro->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('porCuenta', []));
});
