<?php

/**
 * Al subir el inventario, cada producto tiene que quedar parado en su sitio.
 *
 * El importador del Excel del cliente mandaba las 134 referencias a la bodega
 * como un bulto único: el kardex decía «Bodega Principal Bogotá» y nada más, así
 * que después nadie sabía en qué pasillo estaban los pañales de recién nacido y
 * el conteo había que hacerlo caminando la bodega entera.
 *
 * Ahora el Excel puede traer columnas de pasillo/estante/nivel —reconocidas por
 * el nombre del encabezado, no por su posición, porque el REPORTE del cliente
 * trae columnas extra— y el formulario ofrece una posición por defecto para las
 * filas que no las traigan.
 *
 * Lo que se verifica acá:
 *   · la posición del Excel crea la ubicación adentro de la bodega elegida;
 *   · el movimiento de kardex queda en esa posición, no en la bodega;
 *   · dos filas en la misma posición la reutilizan (no se duplica el estante);
 *   · sin columnas de posición ni defaults, todo entra a la bodega (como antes);
 *   · el dry-run cuenta las posiciones que harían falta y no escribe nada.
 */

use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

beforeEach(function () {
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    $rol = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']);
    $this->operador = \App\Models\User::factory()->create();
    $this->operador->assignRole($rol);
    $this->actingAs($this->operador);

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'BOG', 'nombre' => 'Bodega Principal Bogotá',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
});

/**
 * Arma un .xlsx con la forma del REPORTE del cliente: fila 1 título, fila 2
 * encabezado, y después filas de categoría (solo columna A) y de producto.
 *
 * @param  list<list<string|int>>  $filas
 */
function excelCliente(array $encabezado, array $filas): string
{
    $path = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
    $writer = new XlsxWriter();
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Hoja1');
    $writer->addRow(Row::fromValues(['INVENTARIO DR REPORTE']));
    $writer->addRow(Row::fromValues($encabezado));
    foreach ($filas as $fila) {
        $writer->addRow(Row::fromValues($fila));
    }
    $writer->close();

    return $path;
}

function subirExcel(string $path, array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->post('/app/inventario/importar-cliente', array_merge([
        'archivo' => new \Illuminate\Http\UploadedFile($path, 'inventario.xlsx', null, null, true),
        'bodega_id' => test()->bodega->id,
        'hoja' => 'Hoja1',
        // Toda carga dice por qué entra; acá no es lo que se prueba.
        'motivo' => 'saldo_inicial',
        'observacion' => 'Inventario inicial de prueba',
    ], $extra));
}

it('deja cada producto en el pasillo, estante y nivel que dice el Excel', function () {
    $path = excelCliente(
        ['REFERENCIA', 'DESCRIPCION', 'EXISTENCIA', 'VARIACION', 'PASILLO', 'ESTANTE', 'NIVEL'],
        [
            ['PAÑALES RECIEN NACIDO'],
            ['GB-PAN-RN', 'Pañal recién nacido x40', 120, 'NA', '4', '6', '3'],
        ]
    );

    subirExcel($path)->assertSessionHasNoErrors();

    $posicion = InventarioUbicacion::where('bodega_id', $this->bodega->id)->first();

    expect($posicion)->not->toBeNull()
        ->and($posicion->pasillo)->toBe('4')
        ->and($posicion->estante)->toBe('6')
        ->and($posicion->nivel)->toBe('3')
        ->and($posicion->nombre)->toBe('Pasillo 4 · Estante 6 · Nivel 3')
        ->and($posicion->codigo)->toBe('BOG-P4-E6-N3')
        // Hereda la categoría de la bodega: un estante dentro de una bodega de
        // venta es stock de venta, no se asume nada.
        ->and($posicion->categoria)->toBe(CategoriaUbicacion::Venta);

    $producto = Producto::where('referencia', 'GB-PAN-RN')->firstOrFail();
    $mov = InventarioMovimiento::where('producto_id', $producto->id)->firstOrFail();

    // Lo importante: el kardex apunta a la posición, no a la bodega.
    expect((int) $mov->ubicacion_id)->toBe((int) $posicion->id)
        ->and((int) $mov->cantidad)->toBe(120);
});

it('no duplica el stock de una referencia nueva', function () {
    // `Producto::created` también escribe kardex para los productos agregados,
    // en «la primera ubicación activa». Como el stock de un agregado se calcula
    // sumando el kardex, eso metía la mercancía dos veces y la mitad en una
    // bodega que nadie eligió. El importador apaga ese automático.
    $path = excelCliente(
        ['REFERENCIA', 'DESCRIPCION', 'EXISTENCIA', 'VARIACION', 'PASILLO', 'ESTANTE', 'NIVEL'],
        [
            ['PAÑALES RECIEN NACIDO'],
            ['GB-PAN-RN', 'Pañal recién nacido x40', 120, 'NA', '4', '6', '3'],
        ]
    );

    subirExcel($path)->assertSessionHasNoErrors();

    $producto = Producto::where('referencia', 'GB-PAN-RN')->firstOrFail();
    $posicion = InventarioUbicacion::where('bodega_id', $this->bodega->id)->firstOrFail();

    expect(InventarioMovimiento::where('producto_id', $producto->id)->count())->toBe(1)
        ->and((int) InventarioMovimiento::where('producto_id', $producto->id)->sum('cantidad'))->toBe(120)
        // Y todo el saldo está en la posición, no repartido con la bodega.
        ->and(app(\App\Modules\Inventario\Services\StockService::class)
            ->saldoFisicoProducto($producto->id))->toBe(120)
        ->and(app(\App\Modules\Inventario\Services\StockService::class)
            ->saldoFisicoProducto($producto->id, $posicion->id))->toBe(120);
});

it('reutiliza la posición cuando dos productos van al mismo estante', function () {
    $path = excelCliente(
        ['REFERENCIA', 'DESCRIPCION', 'EXISTENCIA', 'VARIACION', 'PASILLO', 'ESTANTE', 'NIVEL'],
        [
            ['PAÑALES RECIEN NACIDO'],
            ['GB-PAN-RN', 'Pañal recién nacido x40', 120, 'NA', '4', '6', '3'],
            // El mismo sitio escrito a mano con espacios de más: no puede
            // terminar siendo un segundo estante fantasma.
            ['GB-PAN-RN2', 'Pañal recién nacido x80', 60, 'NA', ' 4', ' 6 ', '3'],
            // Este sí es otro estante.
            ['GB-PAN-RN3', 'Pañal premium x40', 30, 'NA', '4', '7', '3'],
        ]
    );

    subirExcel($path)->assertSessionHasNoErrors();

    expect(InventarioUbicacion::where('bodega_id', $this->bodega->id)->count())->toBe(2);

    $ubic = fn (string $ref) => (int) InventarioMovimiento::where(
        'producto_id',
        Producto::where('referencia', $ref)->firstOrFail()->id
    )->firstOrFail()->ubicacion_id;

    expect($ubic('GB-PAN-RN2'))->toBe($ubic('GB-PAN-RN'))
        ->and($ubic('GB-PAN-RN3'))->not->toBe($ubic('GB-PAN-RN'));
});

it('usa la posición del formulario para las filas que no la traen', function () {
    $path = excelCliente(
        ['REFERENCIA', 'DESCRIPCION', 'EXISTENCIA', 'VARIACION'],
        [
            ['COCHES'],
            ['GB-COC-01', 'Coche paseador', 8, 'NA'],
        ]
    );

    subirExcel($path, ['pasillo' => '1', 'estante' => 'A'])->assertSessionHasNoErrors();

    $posicion = InventarioUbicacion::where('bodega_id', $this->bodega->id)->firstOrFail();

    expect($posicion->pasillo)->toBe('1')
        ->and($posicion->estante)->toBe('A')
        ->and($posicion->nivel)->toBeNull()
        ->and($posicion->nombre)->toBe('Pasillo 1 · Estante A')
        ->and($posicion->codigo)->toBe('BOG-P1-EA');

    $producto = Producto::where('referencia', 'GB-COC-01')->firstOrFail();
    expect((int) InventarioMovimiento::where('producto_id', $producto->id)->firstOrFail()->ubicacion_id)
        ->toBe((int) $posicion->id);
});

it('manda la columna del Excel por encima de la posición del formulario', function () {
    $path = excelCliente(
        ['REFERENCIA', 'DESCRIPCION', 'EXISTENCIA', 'VARIACION', 'PASILLO', 'ESTANTE', 'NIVEL'],
        [
            ['COCHES'],
            ['GB-COC-01', 'Coche paseador', 8, 'NA', '9', '9', '9'],
        ]
    );

    subirExcel($path, ['pasillo' => '1', 'estante' => 'A', 'nivel' => '2'])->assertSessionHasNoErrors();

    $posicion = InventarioUbicacion::where('bodega_id', $this->bodega->id)->firstOrFail();
    expect($posicion->pasillo)->toBe('9')->and($posicion->estante)->toBe('9');
});

it('sin posición en ningún lado deja el stock en la bodega, como antes', function () {
    $path = excelCliente(
        ['REFERENCIA', 'DESCRIPCION', 'EXISTENCIA', 'VARIACION'],
        [
            ['COCHES'],
            ['GB-COC-01', 'Coche paseador', 8, 'NA'],
        ]
    );

    subirExcel($path)->assertSessionHasNoErrors();

    expect(InventarioUbicacion::where('bodega_id', $this->bodega->id)->count())->toBe(0);

    $producto = Producto::where('referencia', 'GB-COC-01')->firstOrFail();
    expect((int) InventarioMovimiento::where('producto_id', $producto->id)->firstOrFail()->ubicacion_id)
        ->toBe((int) $this->bodega->id);
});

it('no confunde una columna extra del reporte con el pasillo', function () {
    // El REPORTE del cliente trae columnas de más (totales, fechas). Tomar «la
    // quinta a ciegas» habría creado una ubicación llamada «Pasillo 15/03/2026».
    $path = excelCliente(
        ['REFERENCIA', 'DESCRIPCION', 'EXISTENCIA', 'VARIACION', 'COSTO PROMEDIO', 'TOTAL'],
        [
            ['COCHES'],
            ['GB-COC-01', 'Coche paseador', 8, 'NA', '45000', '360000'],
        ]
    );

    subirExcel($path)->assertSessionHasNoErrors();

    expect(InventarioUbicacion::where('bodega_id', $this->bodega->id)->count())->toBe(0);
});

it('el dry-run cuenta las posiciones que harían falta y no escribe nada', function () {
    // Categoría que el seeder ya conoce: el dry-run no crea categorías (no
    // escribe nada), así que una categoría nueva dejaría las filas sin clasificar
    // y no se probaría lo que interesa.
    $path = excelCliente(
        ['REFERENCIA', 'DESCRIPCION', 'EXISTENCIA', 'VARIACION', 'PASILLO', 'ESTANTE', 'NIVEL'],
        [
            ['PROTECCION'],
            ['GB-PAN-RN', 'Pañal recién nacido x40', 120, 'NA', '4', '6', '3'],
            ['GB-PAN-RN2', 'Pañal recién nacido x80', 60, 'NA', '4', '6', '4'],
        ]
    );

    subirExcel($path, ['dry_run' => true])->assertSessionHasNoErrors();

    $resumen = session('importResumen');

    expect($resumen['ubicaciones_creadas'])->toBe(2)
        ->and($resumen['columnas_posicion'])->toContain('pasillo', 'estante', 'nivel')
        // La pantalla promete que la simulación dice cuántos se crean.
        ->and($resumen['creados'])->toBe(2)
        ->and($resumen['actualizados'])->toBe(0)
        // Simulación: ni ubicaciones, ni productos, ni kardex.
        ->and(InventarioUbicacion::where('bodega_id', $this->bodega->id)->count())->toBe(0)
        ->and(Producto::where('referencia', 'GB-PAN-RN')->exists())->toBeFalse()
        ->and(InventarioMovimiento::count())->toBe(0);
});

it('no inventa un nivel dentro de otro nivel si el destino ya es una posición', function () {
    $posicion = InventarioUbicacion::create([
        'bodega_id' => $this->bodega->id,
        'codigo' => 'BOG-P1-E1', 'nombre' => 'Pasillo 1 · Estante 1',
        'pasillo' => '1', 'estante' => '1',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $path = excelCliente(
        ['REFERENCIA', 'DESCRIPCION', 'EXISTENCIA', 'VARIACION', 'PASILLO', 'ESTANTE', 'NIVEL'],
        [
            ['COCHES'],
            ['GB-COC-01', 'Coche paseador', 8, 'NA', '9', '9', '9'],
        ]
    );

    subirExcel($path, ['bodega_id' => $posicion->id])->assertSessionHasNoErrors();

    expect(session('importResumen')['pos_ignoradas'])->toBe(1)
        ->and(InventarioUbicacion::where('bodega_id', $posicion->id)->count())->toBe(0);

    $producto = Producto::where('referencia', 'GB-COC-01')->firstOrFail();
    expect((int) InventarioMovimiento::where('producto_id', $producto->id)->firstOrFail()->ubicacion_id)
        ->toBe((int) $posicion->id);
});
