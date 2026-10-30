<?php

/**
 * Compras slice 1 · la orden de importación como la arma Alejandro.
 *
 * Los números esperados salen de su propio Excel («NUEVO PEDIDO TOPWIN
 * 24-10-26»), hoja NUEVA ORDEN: si la fórmula del sistema se aparta un peso
 * de la de él, la pre-liquidación deja de servirle.
 */

use App\Models\Contacto;
use App\Models\EmpresaConfig;
use App\Models\User;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\LectorOrdenProveedor;
use App\Modules\Compras\Services\PreliquidarOrden;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'BOD-1', 'nombre' => 'Bodega principal',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $this->proveedor = Contacto::create([
        'numero_documento' => '000-TOPWIN', 'nombre_completo' => 'Topwin', 'es_proveedor' => true,
    ]);

    $this->greatBaby = EmpresaConfig::query()->updateOrCreate(['codigo' => 'great_baby'], [
        'razon_social' => 'GREAT BABY S.A.S.', 'nombre_comercial' => 'Great Baby', 'nit' => '900',
        'es_principal' => true, 'activa' => true,
    ]);
    $this->ecommerce = EmpresaConfig::query()->updateOrCreate(['codigo' => 'ecommerce'], [
        'razon_social' => 'E-COMMERCE (por definir)', 'nombre_comercial' => 'E-commerce · Dropi', 'nit' => '',
        'es_principal' => false, 'activa' => false,
    ]);
});

/** Las dos líneas del Excel que se usan de referencia (fila 4 y fila 8). */
function lineasTopwin(array $extra = []): array
{
    return [
        // CA-0311: 30 cajas × 6, 79 RMB, caja 76×47×72 cm → CBM 0,257184
        ['descripcion' => 'CA-0311', 'cajas' => 30, 'unidades_por_caja' => 6, 'cantidad' => 180,
            'precio_unit' => 79, 'iva_pct' => 0,
            'caja_largo_cm' => 76, 'caja_ancho_cm' => 47, 'caja_alto_cm' => 72] + $extra,
        // 2509-50: 200 cajas × 1, 75 RMB, caja 78×28×19 cm → CBM 0,041496
        ['descripcion' => '2509-50', 'cajas' => 200, 'unidades_por_caja' => 1, 'cantidad' => 200,
            'precio_unit' => 75, 'iva_pct' => 0,
            'caja_largo_cm' => 78, 'caja_ancho_cm' => 28, 'caja_alto_cm' => 19],
    ];
}

function ocImportacion($test, array $items, array $extra = []): OrdenCompra
{
    $test->actingAs($test->aracely);

    return CrearOrdenCompra::run([
        'proveedor_id' => $test->proveedor->id,
        'bodega_id' => $test->bodega->id,
        'tipo' => 'importacion',
        'moneda' => 'CNY',
        'tasa_cambio' => 520,
        'items' => $items,
    ] + $extra);
}

it('reproduce el costo y los precios de su Excel, peso a peso', function () {
    $pre = app(PreliquidarOrden::class)->handle(ocImportacion($this, lineasTopwin()));

    [$ca, $ref2509] = $pre['lineas'];

    // Fila 4 de NUEVA ORDEN.
    expect($ca['unidad']['costo_china_comision'])->toBe(46009.6)
        ->and($ca['unidad']['flete'])->toBe(15345.31)
        ->and($ca['unidad']['arancel'])->toBe(4231.9)
        ->and($ca['unidad']['iva'])->toBe(5762.43)
        ->and($ca['unidad']['gasto_colombia'])->toBe(20574.72)
        ->and($ca['unidad']['costo'])->toBe(91923.97)
        ->and($ca['unidad']['precio_1'])->toBe(131319.95)
        ->and($ca['unidad']['precio_2'])->toBe(164149.94);

    // Fila 8.
    expect($ref2509['unidad']['costo'])->toBe(87992.82)
        ->and($ref2509['unidad']['precio_1'])->toBe(125704.02)
        ->and($ref2509['unidad']['precio_2'])->toBe(157130.03);
});

it('el CBM escrito manda sobre las medidas, como en su Excel', function () {
    $oc = ocImportacion($this, [
        ['descripcion' => 'AND2412-03A', 'cajas' => 5, 'unidades_por_caja' => 60, 'cantidad' => 300, 'precio_unit' => 11, 'iva_pct' => 0,
            'caja_largo_cm' => 27, 'caja_ancho_cm' => 33, 'caja_alto_cm' => 13, 'cbm_caja' => 0.3],
    ]);

    expect((float) $oc->items->first()->cbm_caja)->toBe(0.3);
});

it('pide por cajas: las unidades y el CBM salen solos', function () {
    $oc = ocImportacion($this, lineasTopwin());
    $item = $oc->items->firstWhere('descripcion', 'CA-0311');

    expect((float) $item->cantidad)->toBe(180.0)
        ->and((float) $item->cbm_caja)->toBe(0.257184)
        ->and($item->cbmTotal())->toEqualWithDelta(7.71552, 0.000001)
        // Nace con los parámetros por defecto y con la tasa de la OC.
        ->and($oc->parametros_preliquidacion['flete_m3'])->toEqual(358000);
});

it('avisa (sin bloquear) cuando el pedido se pasa de 68 m³ y separa Great Baby de e-commerce', function () {
    // 150 cajas de CA-0311 = 38,58 m³ para Great Baby; 150 más para e-commerce = 77,16 m³.
    $oc = ocImportacion($this, [
        ['descripcion' => 'CA-0311', 'cajas' => 150, 'unidades_por_caja' => 6, 'cantidad' => 900, 'precio_unit' => 79, 'iva_pct' => 0, 'cbm_caja' => 0.257184],
        ['descripcion' => 'CA-0311', 'cajas' => 150, 'unidades_por_caja' => 6, 'cantidad' => 900, 'precio_unit' => 79, 'iva_pct' => 0, 'cbm_caja' => 0.257184,
            'empresa_destino_id' => $this->ecommerce->id],
    ]);

    $pre = app(PreliquidarOrden::class)->handle($oc);

    expect($pre['totales']['cbm'])->toBe(77.1552)
        ->and($pre['avisos'][0])->toContain('se pasa 9.16 m³')
        ->and($pre['por_destino'])->toHaveCount(2)
        ->and(collect($pre['por_destino'])->firstWhere('empresa_id', $this->ecommerce->id)['cajas'])->toBe(150)
        ->and(collect($pre['por_destino'])->firstWhere('empresa_id', $this->greatBaby->id)['cbm'])->toBe(38.5776);
});

it('la OC nueva hereda los parámetros de la última importación', function () {
    ocImportacion($this, lineasTopwin(), [
        'parametros_preliquidacion' => ['flete_m3' => 400000, 'comision_pct' => 5] + PreliquidarOrden::DEFAULTS,
    ]);

    expect(PreliquidarOrden::parametrosIniciales()['flete_m3'])->toEqual(400000.0)
        ->and(PreliquidarOrden::parametrosIniciales()['comision_pct'])->toEqual(5.0)
        ->and(PreliquidarOrden::tasaInicial('CNY'))->toEqual(520.0);
});

it('la tasa heredada es la de la misma moneda, no la de otra importación', function () {
    ocImportacion($this, lineasTopwin());                                   // CNY @ 520
    $this->actingAs($this->aracely);
    CrearOrdenCompra::run([
        'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
        'tipo' => 'importacion', 'moneda' => 'USD', 'tasa_cambio' => 4000, 'items' => lineasTopwin(),
    ]);

    expect(PreliquidarOrden::tasaInicial('CNY'))->toEqual(520.0)
        ->and(PreliquidarOrden::tasaInicial('USD'))->toEqual(4000.0)
        ->and(PreliquidarOrden::tasaInicial('EUR'))->toBeNull();
});

it('cambia parámetros sólo mientras la OC es borrador', function () {
    $oc = ocImportacion($this, lineasTopwin());
    $nuevos = ['tasa_cambio' => 510, 'flete_m3' => 360000] + PreliquidarOrden::DEFAULTS;

    $this->actingAs($this->aracely)
        ->post("/app/compras/oc/{$oc->id}/parametros", $nuevos)
        ->assertRedirect();

    $oc->refresh();
    expect((float) $oc->tasa_cambio)->toBe(510.0)
        ->and($oc->parametros_preliquidacion['flete_m3'])->toEqual(360000);

    $oc->estado = EstadoOrdenCompra::Aprobada;
    $oc->save();

    $this->actingAs($this->aracely)
        ->post("/app/compras/oc/{$oc->id}/parametros", $nuevos)
        ->assertStatus(422);
});

it('crea la OC por la ruta con cajas, CBM y destino', function () {
    $this->actingAs($this->aracely)->post('/app/compras/oc', [
        'proveedor_id' => $this->proveedor->id,
        'bodega_id' => $this->bodega->id,
        'tipo' => 'importacion', 'moneda' => 'CNY', 'tasa_cambio' => 520,
        'parametros_preliquidacion' => PreliquidarOrden::DEFAULTS,
        'items' => [
            ['descripcion' => 'AZ-103', 'cajas' => 50, 'unidades_por_caja' => 10, 'cantidad' => 500,
                'precio_unit' => 25.5, 'iva_pct' => 0, 'cbm_caja' => 0.33, 'empresa_destino_id' => $this->ecommerce->id],
        ],
    ])->assertRedirect();

    $item = OrdenCompra::latest('id')->first()->items->first();
    expect((float) $item->cantidad)->toBe(500.0)
        ->and((float) $item->cbm_caja)->toBe(0.33)
        ->and($item->empresa_destino_id)->toBe($this->ecommerce->id);
});

it('la calculadora rápida da el mismo costo que la OC', function () {
    $this->actingAs($this->aracely)
        ->postJson('/app/compras/calculadora', [
            'tasa_cambio' => 520, 'precio' => 79, 'unidades_por_caja' => 6,
            'caja_largo_cm' => 76, 'caja_ancho_cm' => 47, 'caja_alto_cm' => 72,
        ] + PreliquidarOrden::DEFAULTS)
        ->assertOk()
        ->assertJsonPath('cbm_caja', 0.257184)
        ->assertJsonPath('unidad.costo', 91923.97)
        ->assertJsonPath('unidad.precio_2', 164149.94);
});

it('lee su Excel: busca los encabezados, usa el valor de las fórmulas y la hoja DROP va a e-commerce', function () {
    Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'CA-0311', 'nombre' => 'Coche paseador', 'activo' => true, 'desglose_stock' => false,
    ]));

    $ruta = tempnam(sys_get_temp_dir(), 'oc') . '.xlsx';
    $writer = new Writer();
    $writer->openToFile($ruta);
    $encabezados = [null, 'REF', 'FOTO', 'CONTACTO ', 'DESCRIPCION', 'CAJAS', 'CTN/CAJA', 'TOTAL CTN', 'PRECIO', 'TOTAL RMB', 'M1', 'M2', 'M3', 'CBM'];

    $writer->getCurrentSheet()->setName('NUEVA ORDEN');
    $writer->addRow(Row::fromValues([]));
    $writer->addRow(Row::fromValues([null, 'ORDEN DE PEDIDO ']));
    $writer->addRow(Row::fromValues($encabezados));
    $writer->addRow(Row::fromValues([null, 'CA-0311', null, null, null, 30, 6, 180, 79, 14220, 76, 47, 72, 0.257184]));
    // Medidas de 0,0116 m³ con un CBM escrito encima: manda el CBM (fila 5 de su archivo).
    $writer->addRow(Row::fromValues([null, 'AND2412-03A', null, null, null, 5, 60, 300, 11, 3300, 27, 33, 13, 0.3]));
    $writer->addRow(Row::fromValues([null, null, null, 'DESARMADO', 'con pilas', 75, 4, 300, 149, 44700, 0, 0, 0, 0.21]));
    $writer->addRow(Row::fromValues([null, null, null, null, null, null, null, null, null, 58920, null, null, null, null]));

    $writer->addNewSheetAndMakeItCurrent()->setName('DROP');
    $writer->addRow(Row::fromValues($encabezados));
    $writer->addRow(Row::fromValues([null, 'AZ-103', null, null, null, 50, 10, 500, 25.5, 12750, 0, 0, 0, 0.33]));
    $writer->close();

    $leido = app(LectorOrdenProveedor::class)->leer($ruta);
    @unlink($ruta);

    [$ca, $cbmEscrito, $sinRef, $drop] = $leido['lineas'];

    expect($leido['lineas'])->toHaveCount(4)
        ->and($ca['producto_id'])->not->toBeNull()
        ->and($ca['descripcion'])->toBe('CA-0311 · Coche paseador')
        ->and($ca['cantidad'])->toBe(180)
        ->and($ca['cbm_caja'])->toBe(0.257184)
        ->and($ca['empresa_destino_id'])->toBeNull()
        ->and($cbmEscrito['cbm_caja'])->toBe(0.3)
        ->and($cbmEscrito['caja_largo_cm'])->toEqual(27)
        ->and($sinRef['referencia'])->toBeNull()
        ->and($sinRef['descripcion'])->toBe('Sin REF (NUEVA ORDEN fila 6) · con pilas · DESARMADO')
        ->and($sinRef['cbm_caja'])->toBe(0.21)
        ->and($drop['empresa_destino_id'])->toBe($this->ecommerce->id)
        ->and($drop['producto_id'])->toBeNull()
        ->and($leido['avisos'])->toHaveCount(2);
});

it('rechaza un Excel sin la fila de encabezados', function () {
    $ruta = tempnam(sys_get_temp_dir(), 'oc') . '.xlsx';
    $writer = new Writer();
    $writer->openToFile($ruta);
    $writer->addRow(Row::fromValues(['cualquier', 'cosa']));
    $writer->close();

    expect(fn () => app(LectorOrdenProveedor::class)->leer($ruta))
        ->toThrow(RuntimeException::class, 'No encontré la fila de encabezados');
    @unlink($ruta);
});
