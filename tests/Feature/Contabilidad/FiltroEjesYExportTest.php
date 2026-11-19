<?php

/**
 * F2c · El filtro de los dos ejes y el export que lo respeta.
 *
 * Lo que se prueba acá es lo que hace que el filtro sirva para conciliar:
 *   · que «internas» incluya la parte interna de los repartos
 *   · que un pago se filtre por los ejes de SU FACTURA
 *   · que el archivo que se baja traiga exactamente lo que se está viendo
 *
 * Ese último punto era el reclamo original: se filtraba un mes, se exportaba y
 * salía el archivo entero. Si el export y la pantalla pueden diferir, alguien
 * va a trabajar sobre el archivo equivocado sin notarlo.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Actions\CrearFacturaManual;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Contabilidad\Support\FiltroEjes;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->cliente = Contacto::create([
        'numero_documento' => '900777888',
        'nombre_completo' => 'Cliente del Filtro SAS',
        'es_cliente' => true,
    ]);

    $this->producto = Producto::create([
        'referencia' => 'REF-F', 'nombre' => 'Producto del filtro', 'activo' => true,
    ]);
});

/*
 * Una factura con destino y línea, por el mismo camino que usa la pantalla.
 *
 * El nombre lleva sufijo porque las funciones de los tests de Pest viven todas
 * en el mismo espacio global y `SaldoFacturaTest` ya tiene su propio
 * `facturaDe`: correr las dos suites juntas moría con «Cannot redeclare».
 */
function facturaConEjes(DestinoFiscal $destino, LineaNegocio $linea, float $total, ?float $fiscal = null)
{
    return CrearFacturaManual::run([
        'contacto_id' => test()->cliente->id,
        'fecha_emision' => now()->toDateString(),
        'destino_fiscal' => $destino->value,
        'linea_negocio' => $linea->value,
        'monto_fiscal' => $fiscal,
        'motivo_destino' => $destino === DestinoFiscal::Siigo ? null : 'Motivo de prueba',
        'items' => [[
            'producto_id' => test()->producto->id,
            'descripcion' => 'Venta', 'cantidad' => 1, 'precio_unit' => $total,
        ]],
    ]);
}

/** El Contador, que es quien puede entrar a estas pantallas. */
function contador(): User
{
    $u = User::factory()->create();
    $u->assignRole(Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']));

    return $u;
}

// ── El filtro ────────────────────────────────────────────────────────────────

it('«internas» incluye la parte interna de un reparto', function () {
    /*
     * Si «internas» fuera `destino = interno` a secas, la mitad interna de los
     * repartos desaparecería del balance interno y nadie lo notaría hasta el
     * cierre. Un reparto tiene las dos mitades y tiene que estar en las dos
     * vistas.
     */
    facturaConEjes(DestinoFiscal::Reparto, LineaNegocio::GreatBaby, 100_000, 20_000);

    $internas = FiltroEjes::de(null, 'interna')->aplicar(MovimientoContable::query())->count();
    $fiscales = FiltroEjes::de(null, 'siigo')->aplicar(MovimientoContable::query())->count();

    expect($internas)->toBeGreaterThan(0)
        ->and($fiscales)->toBeGreaterThan(0);
});

it('separa las dos líneas de negocio', function () {
    facturaConEjes(DestinoFiscal::Siigo, LineaNegocio::GreatBaby, 100_000);
    facturaConEjes(DestinoFiscal::Siigo, LineaNegocio::Dropi, 50_000);

    $gb = FiltroEjes::de('great_baby', null)->aplicar(MovimientoContable::query())->sum('debe');
    $dropi = FiltroEjes::de('dropi', null)->aplicar(MovimientoContable::query())->sum('debe');

    expect((float) $gb)->toEqual(100_000.0)
        ->and((float) $dropi)->toEqual(50_000.0);
});

it('cualquier valor raro en la URL cae en «todas», no revienta', function () {
    $f = FiltroEjes::deRequest(Request::create('/', 'GET', ['linea' => "'; DROP TABLE", 'destino' => 'xyz']));

    expect($f->linea)->toBe('todas')
        ->and($f->destino)->toBe('todas')
        ->and($f->activo())->toBeFalse();
});

// ── La pantalla ──────────────────────────────────────────────────────────────

it('el libro filtra por destino', function () {
    facturaConEjes(DestinoFiscal::Siigo, LineaNegocio::GreatBaby, 100_000);
    facturaConEjes(DestinoFiscal::Interno, LineaNegocio::GreatBaby, 70_000);

    $r = $this->actingAs(contador())->get('/app/cartera/movimientos?destino=interna');

    $r->assertOk();
    $totales = $r->viewData('page')['props']['totales'];

    // Sólo la interna: $70.000 de CxC.
    expect((float) $totales['suma_debe'])->toEqual(70_000.0);
});

it('un pago se filtra por los ejes de su factura', function () {
    $interna = facturaConEjes(DestinoFiscal::Interno, LineaNegocio::GreatBaby, 70_000);
    $fiscal = facturaConEjes(DestinoFiscal::Siigo, LineaNegocio::GreatBaby, 100_000);

    foreach ([$interna, $fiscal] as $f) {
        \App\Modules\Cartera\Models\PagoVenta::create([
            'factura_id' => $f->id,
            'fecha' => now()->toDateString(),
            'medio_pago' => 'transferencia',
            'monto_recibido' => 10_000,
            'monto_aplicado' => 10_000,
            'diferencia' => 0,
        ]);
    }

    $r = $this->actingAs(contador())->get('/app/pagos?destino=interna');

    $r->assertOk();
    $pagos = $r->viewData('page')['props']['pagos']['data'];

    expect($pagos)->toHaveCount(1)
        ->and($pagos[0]['factura_numero'])->toBe($interna->numero);
});

// ── El export ────────────────────────────────────────────────────────────────

it('el export del libro trae sólo lo filtrado, no todo', function () {
    facturaConEjes(DestinoFiscal::Siigo, LineaNegocio::GreatBaby, 100_000);
    facturaConEjes(DestinoFiscal::Interno, LineaNegocio::GreatBaby, 70_000);

    $csv = $this->actingAs(contador())
        ->get('/app/cartera/movimientos/csv?destino=interna')
        ->streamedContent();

    expect($csv)->toContain('70000,00')
        // Lo fiscal NO puede aparecer: es justo lo que se filtró.
        ->and($csv)->not->toContain('100000,00')
        // Y el pie dice con qué filtro se bajó, para que el archivo se explique
        // solo dentro de tres meses.
        ->and($csv)->toContain('sólo lo que no va a SIIGO');
});

it('el export sale con BOM y punto y coma, que es lo que abre Excel en español', function () {
    facturaConEjes(DestinoFiscal::Siigo, LineaNegocio::GreatBaby, 100_000);

    $csv = $this->actingAs(contador())->get('/app/cartera/movimientos/csv')->streamedContent();

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        // fputcsv entrecomilla lo que lleva espacio, de ahí las comillas.
        ->and(explode("\n", $csv)[0])->toContain('Fecha;"Cuenta PUC";Débito');
});

it('los números del export llevan coma decimal, para que Excel los sume', function () {
    facturaConEjes(DestinoFiscal::Siigo, LineaNegocio::GreatBaby, 1234.56);

    $csv = $this->actingAs(contador())->get('/app/cartera/movimientos/csv')->streamedContent();

    expect($csv)->toContain('1234,56');
});

it('el export de pagos respeta el filtro de destino', function () {
    $interna = facturaConEjes(DestinoFiscal::Interno, LineaNegocio::GreatBaby, 70_000);
    $fiscal = facturaConEjes(DestinoFiscal::Siigo, LineaNegocio::GreatBaby, 100_000);

    foreach ([[$interna, 11_111], [$fiscal, 22_222]] as [$f, $monto]) {
        \App\Modules\Cartera\Models\PagoVenta::create([
            'factura_id' => $f->id,
            'fecha' => now()->toDateString(),
            'medio_pago' => 'transferencia',
            'monto_recibido' => $monto,
            'monto_aplicado' => $monto,
            'diferencia' => 0,
            // Columna CASTEADA a enum. Sin esto el test pasaba y la pantalla
            // devolvia un 500 con medio CSV ya escrito, porque un enum no
            // castea a string.
            'clasificacion_diferencia' => \App\Modules\Cartera\Enums\ClasificacionDiferencia::cases()[0],
        ]);
    }

    $csv = $this->actingAs(contador())->get('/app/pagos/csv?destino=interna')->streamedContent();

    expect($csv)->toContain('11111,00')
        ->and($csv)->not->toContain('22222,00');
});

it('una descripción que empieza por = no se ejecuta como fórmula en Excel', function () {
    // Sin el apóstrofo delante, Excel interpreta la celda como fórmula al abrir
    // el archivo. Una descripción puede empezar por = sin que nadie lo planee.
    $f = facturaConEjes(DestinoFiscal::Siigo, LineaNegocio::GreatBaby, 100_000);
    MovimientoContable::where('origen_id', $f->id)->limit(1)
        ->update(['descripcion' => '=HYPERLINK("http://ejemplo","clic")']);

    $csv = $this->actingAs(contador())->get('/app/cartera/movimientos/csv')->streamedContent();

    expect($csv)->toContain("'=HYPERLINK");
});

it('una columna casteada a enum no tumba el archivo', function () {
    /*
     * El modo de fallar es feo: el encabezado ya salió, así que el navegador
     * recibe medio CSV con la página de error de Laravel pegada al final.
     * Pasó con `clasificacion_diferencia` en el export de pagos.
     */
    $f = facturaConEjes(DestinoFiscal::Siigo, LineaNegocio::GreatBaby, 100_000);
    $clasificacion = \App\Modules\Cartera\Enums\ClasificacionDiferencia::cases()[0];

    \App\Modules\Cartera\Models\PagoVenta::create([
        'factura_id' => $f->id,
        'fecha' => now()->toDateString(),
        'medio_pago' => 'transferencia',
        'monto_recibido' => 10_000,
        'monto_aplicado' => 10_000,
        'diferencia' => 0,
        'clasificacion_diferencia' => $clasificacion,
    ]);

    $r = $this->actingAs(contador())->get('/app/pagos/csv');

    $r->assertOk();
    // Sale la etiqueta, que es lo que una persona entiende, no el valor crudo.
    expect($r->streamedContent())->toContain($clasificacion->label());
});

it('no se pierde ni una fila cuando el export pasa de una tanda', function () {
    /*
     * Este es el modo de fallar que no se nota: el archivo sale, se abre bien,
     * y le faltan filas.
     *
     * Pasaba con `chunkById`, que pagina con `WHERE id > último` sin borrar el
     * resto del ordenamiento. Con `ORDER BY fecha, id` —que es como se lee un
     * libro— el «último id» de una tanda no es el id más alto de esa tanda, y
     * la consulta siguiente se saltea todo lo que tenga id menor.
     *
     * Para que se vea, las fechas van al REVÉS de los ids: así el orden por
     * fecha no coincide con el orden por id, que es justo el caso que rompe.
     */
    $filas = 1200;  // más de las 500 de una tanda
    $base = [
        'cuenta_puc' => cta('cta_cxc_default'), 'haber' => 0, 'descripcion' => 'fila de prueba',
        'linea_negocio' => LineaNegocio::GreatBaby->value,
        'destino_fiscal' => DestinoFiscal::Siigo->value,
        // El origen es obligatorio: todo asiento nace de un documento.
        'origen_type' => \App\Modules\Cartera\Models\FacturaVenta::class,
        'origen_id' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ];

    $lote = [];
    for ($i = 0; $i < $filas; $i++) {
        $lote[] = $base + ['fecha' => now()->subDays($i)->toDateString(), 'debe' => 1];
    }
    foreach (array_chunk($lote, 300) as $parte) {
        \Illuminate\Support\Facades\DB::table('movimientos_contables')->insert($parte);
    }

    $r = $this->actingAs(contador())
        ->get('/app/cartera/movimientos/csv?desde='.now()->subDays($filas + 5)->toDateString()
            .'&hasta='.now()->addDay()->toDateString());

    $r->assertOk();
    $csv = $r->streamedContent();

    // Se cuentan las filas de datos por un campo que solo aparece en ellas,
    // sin depender de como haya quedado el salto de linea.
    expect(substr_count($csv, ';"fila de prueba";'))->toBe($filas);
});

it('el export de compras responde con el filtro puesto', function () {
    $csv = $this->actingAs(contador())
        ->get('/app/compras/csv?tab=ordenes&estado=borrador')
        ->streamedContent();

    expect(explode("\n", $csv)[0])->toContain('Número;Proveedor')
        ->and($csv)->toContain('estado borrador');
});
