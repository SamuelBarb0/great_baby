<?php

/**
 * El importador del PUC tiene que tragarse el archivo del cliente TAL CUAL.
 *
 * En el documento de insumos les prometimos: «no reescriban nada, mándennos el
 * Excel como lo usan hoy». El PUC real de Great Baby llegó el 26-oct con cuatro
 * líneas de preámbulo —título, razón social, NIT y una vacía—, el encabezado
 * escrito «Código» con tilde, y un pie de página que dice «Procesado en
 * septiembre 23 2026 12:57».
 *
 * Con la versión anterior eso daba «faltan columnas obligatorias» y no entraba
 * ni una cuenta. Si alguien vuelve a asumir que el encabezado es la fila 1,
 * la promesa se rompe y el cliente tiene que editar un archivo de mil filas a
 * mano.
 */

use App\Modules\Contabilidad\Models\PlanCuenta;
use App\Modules\Contabilidad\Services\ImportadorPlanCuentas;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Escribe un CSV temporal y devuelve su ruta. */
function csvTemporal(string $contenido): string
{
    $ruta = tempnam(sys_get_temp_dir(), 'puc').'.csv';
    file_put_contents($ruta, $contenido);

    return $ruta;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/puc*.csv') as $f) {
        @unlink($f);
    }
});

it('encuentra el encabezado aunque venga después del preámbulo del export', function () {
    $ruta = csvTemporal(<<<CSV
    Cuentas contables
    GREAT BABY SAS
    901738354

    codigo,nombre
    1105,Caja
    110505,Caja general
    CSV);

    $r = app(ImportadorPlanCuentas::class)->importar($ruta);

    expect($r['errores'])->toBe([])
        ->and($r['creados'])->toBe(2)
        ->and(PlanCuenta::where('codigo', '110505')->value('nombre'))->toBe('Caja general');
});

it('acepta el encabezado con tilde, que es como lo escribe el cliente', function () {
    $ruta = csvTemporal("Código,Nombre\n1105,Caja\n");

    $r = app(ImportadorPlanCuentas::class)->importar($ruta);

    expect($r['errores'])->toBe([])
        ->and($r['creados'])->toBe(1);
});

it('ignora el pie de página sin contarlo como error', function () {
    // «Procesado en septiembre 23 2026 12:57» dejaba el código «2320261257»
    // al quitarle los no-dígitos, y la importación reportaba un error por una
    // línea que ni siquiera es una cuenta.
    $ruta = csvTemporal(<<<CSV
    codigo,nombre
    1105,Caja
    Procesado en septiembre 23 2026 12:57,
    CSV);

    $r = app(ImportadorPlanCuentas::class)->importar($ruta);

    expect($r['errores'])->toBe([])
        ->and($r['creados'])->toBe(1);
});

it('una cuenta de verdad sin nombre SÍ sigue siendo un error', function () {
    $ruta = csvTemporal("codigo,nombre\n1105,Caja\n110510,\n");

    $r = app(ImportadorPlanCuentas::class)->importar($ruta);

    expect($r['creados'])->toBe(1)
        ->and($r['errores'])->toHaveCount(1)
        ->and($r['errores'][0])->toContain('110510');
});

it('avisa cuando de verdad no hay encabezado en ninguna parte', function () {
    $ruta = csvTemporal("uno,dos\n1105,Caja\n");

    $r = app(ImportadorPlanCuentas::class)->importar($ruta);

    expect($r['creados'])->toBe(0)
        ->and($r['errores'])->toHaveCount(1)
        ->and($r['errores'][0])->toContain('encabezado');
});

it('deduce que una cuenta es de movimiento cuando no tiene hijas', function () {
    $ruta = csvTemporal("codigo,nombre\n1,Activo\n11,Efectivo\n1105,Caja\n110505,Caja general\n");

    app(ImportadorPlanCuentas::class)->importar($ruta);

    // Sólo la hoja mueve: las de arriba agrupan.
    expect(PlanCuenta::where('permite_movimiento', true)->pluck('codigo')->all())->toBe(['110505'])
        ->and(PlanCuenta::where('permite_movimiento', false)->count())->toBe(3);
});

it('re-subir el mismo archivo actualiza y no duplica', function () {
    $ruta = csvTemporal("codigo,nombre\n1105,Caja\n");
    $imp = app(ImportadorPlanCuentas::class);

    $imp->importar($ruta);
    $segunda = $imp->importar($ruta);

    expect($segunda['creados'])->toBe(0)
        ->and($segunda['actualizados'])->toBe(1)
        ->and(PlanCuenta::count())->toBe(1);
});
