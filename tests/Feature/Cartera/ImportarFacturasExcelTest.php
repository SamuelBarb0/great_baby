<?php

/**
 * F2b · Subir facturas de venta desde el Excel del cliente.
 *
 * Lo que se prueba acá es lo que hace que esto sea usable con un archivo real:
 *   · que la previsualización NO cree nada
 *   · que una fila mala no se lleve puesta a las buenas
 *   · que entienda números y fechas como los escribe Colombia
 *   · que las filas de una misma factura se junten en una sola factura
 *
 * El archivo que va a llegar no es el de la plantilla: va a tener el nombre de
 * la empresa arriba, la columna «NIT» en vez de «documento_cliente» y «1.234,50»
 * donde esperábamos 1234.5. Cada caso de acá sale de eso.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Services\ImportadorFacturasVenta;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->cliente = Contacto::create([
        'numero_documento' => '900123456',
        'nombre_completo' => 'Pañalera Mundo Bebé SAS',
        'es_cliente' => true,
    ]);

    $this->producto = Producto::create([
        'referencia' => 'REF-001', 'nombre' => 'Pañal etapa 2', 'activo' => true,
    ]);
    $this->flete = Producto::create([
        'referencia' => 'SERV-FLETE', 'nombre' => 'Flete', 'activo' => true, 'stock_control' => false,
    ]);

    $this->importador = new ImportadorFacturasVenta();
});

/**
 * Escribe un CSV temporal con `;`, como el que exporta Excel en español.
 *
 * El nombre lleva sufijo porque las funciones de los tests de Pest viven todas
 * en el mismo espacio global: `ImportarPucDelClienteTest` ya tiene su propio
 * `csvTemporal`, y correr las dos suites juntas moría con «Cannot redeclare».
 */
function csvFacturasTemporal(array $lineas): string
{
    $ruta = tempnam(sys_get_temp_dir(), 'facturas_').'.csv';
    file_put_contents($ruta, "\xEF\xBB\xBF".implode("\n", $lineas)."\n");

    return $ruta;
}

$ENCABEZADO = 'factura;documento_cliente;fecha_emision;fecha_vencimiento;referencia_producto;'
    .'descripcion;cantidad;precio_unitario;descuento_pct;iva_pct;destino;monto_fiscal;linea;motivo;observaciones';

it('junta en una sola factura las filas que comparten la clave', function () use ($ENCABEZADO) {
    $ruta = csvFacturasTemporal([
        $ENCABEZADO,
        '1;900123456;01/10/2026;31/10/2026;REF-001;Pañal etapa 2;10;45000;0;0;fiscal;;great_baby;;',
        '1;900123456;01/10/2026;31/10/2026;SERV-FLETE;Flete Bogotá;1;20000;0;0;fiscal;;great_baby;;',
    ]);

    $previa = $this->importador->previsualizar($ruta);

    expect($previa['resumen']['facturas'])->toBe(1)
        ->and($previa['facturas'][0]['cantidad_items'])->toBe(2)
        ->and($previa['facturas'][0]['total'])->toEqual(470000.0)
        ->and($previa['facturas'][0]['errores'])->toBeEmpty();

    unlink($ruta);
});

it('la previsualización no crea nada', function () use ($ENCABEZADO) {
    $ruta = csvFacturasTemporal([
        $ENCABEZADO,
        '1;900123456;01/10/2026;;REF-001;Pañal etapa 2;10;45000;0;19;fiscal;;great_baby;;',
    ]);

    $this->importador->previsualizar($ruta);

    expect(FacturaVenta::count())->toBe(0)
        ->and(MovimientoContable::count())->toBe(0);

    unlink($ruta);
});

it('importa y deja la factura con asiento', function () use ($ENCABEZADO) {
    $ruta = csvFacturasTemporal([
        $ENCABEZADO,
        '1;900123456;01/10/2026;31/10/2026;REF-001;Pañal etapa 2;10;45000;0;19;fiscal;;great_baby;;Histórico',
    ]);

    $r = $this->importador->importar($ruta);

    $f = FacturaVenta::first();

    expect($r['creadas'])->toBe(1)
        ->and($r['omitidas'])->toBe(0)
        ->and((float) $f->total)->toEqual(535500.0)   // 450.000 + 19 %
        ->and($f->observaciones)->toBe('Histórico')
        // Sin asiento la factura no aparece en ningún balance.
        ->and(MovimientoContable::where('origen_id', $f->id)->count())->toBeGreaterThan(0);

    unlink($ruta);
});

it('una factura mala no se lleva puestas a las buenas', function () use ($ENCABEZADO) {
    $ruta = csvFacturasTemporal([
        $ENCABEZADO,
        '1;900123456;01/10/2026;;REF-001;Pañal etapa 2;10;45000;0;0;fiscal;;great_baby;;',
        // Cliente que no existe en el ERP.
        '2;999999999;01/10/2026;;REF-001;Pañal etapa 2;5;45000;0;0;fiscal;;great_baby;;',
        '3;900123456;02/10/2026;;SERV-FLETE;Flete;1;20000;0;0;fiscal;;great_baby;;',
    ]);

    $r = $this->importador->importar($ruta);

    expect($r['creadas'])->toBe(2)
        ->and($r['omitidas'])->toBe(1)
        ->and(FacturaVenta::count())->toBe(2);

    unlink($ruta);
});

it('entiende números y fechas como los escribe Colombia', function () use ($ENCABEZADO) {
    $ruta = csvFacturasTemporal([
        $ENCABEZADO,
        // 1.234.567,89 con separador de miles en punto y decimal en coma.
        '1;900123456;05/03/2026;;REF-001;Pañal etapa 2;2;"1.234.567,89";0;0;fiscal;;great_baby;;',
    ]);

    $previa = $this->importador->previsualizar($ruta);
    $f = $previa['facturas'][0];

    expect($f['errores'])->toBeEmpty()
        ->and($f['total'])->toEqual(2469135.78)
        // 05/03 es 5 de MARZO, no 3 de mayo: Carbon lo leería al revés.
        ->and($f['fecha_emision'])->toBe('2026-03-05');

    unlink($ruta);
});

it('45.000 son cuarenta y cinco mil, no cuarenta y cinco', function () use ($ENCABEZADO) {
    /*
     * Esto se vio subiendo un archivo real: con la regla vieja «el punto es el
     * decimal», «45.000» entraba como 45 pesos y la factura quedaba mil veces
     * mas chica SIN avisar nada, y «1.000.000» ni siquiera se leia.
     */
    $ruta = csvFacturasTemporal([
        $ENCABEZADO,
        '1;900123456;01/10/2026;;REF-001;Diez unidades;10;"45.000";0;0;fiscal;;great_baby;;',
        '2;900123456;01/10/2026;;REF-001;Un millon;1;"1.000.000";0;0;fiscal;;great_baby;;',
        // Con decimal de verdad: una coma y dos digitos.
        '3;900123456;01/10/2026;;REF-001;Con centavos;1;"1.234,56";0;0;fiscal;;great_baby;;',
        // Punto con un solo digito: eso SI es decimal.
        '4;900123456;01/10/2026;;REF-001;Medio peso;1;"45.5";0;0;fiscal;;great_baby;;',
    ]);

    $porClave = collect($this->importador->previsualizar($ruta)['facturas'])->keyBy('clave');

    expect($porClave['1']['total'])->toEqual(450000.0)
        ->and($porClave['2']['total'])->toEqual(1000000.0)
        ->and($porClave['3']['total'])->toEqual(1234.56)
        ->and($porClave['4']['total'])->toEqual(45.5);

    unlink($ruta);
});

it('encuentra el encabezado aunque el archivo traiga preámbulo', function () use ($ENCABEZADO) {
    $ruta = csvFacturasTemporal([
        'GREAT BABY S.A.S.;;;;;;;;;;;;;;',
        'NIT 900.000.000-1;;;;;;;;;;;;;;',
        ';;;;;;;;;;;;;;',
        $ENCABEZADO,
        '1;900123456;01/10/2026;;REF-001;Pañal etapa 2;1;45000;0;0;fiscal;;great_baby;;',
    ]);

    $previa = $this->importador->previsualizar($ruta);

    expect($previa['resumen']['facturas'])->toBe(1)
        ->and($previa['facturas'][0]['errores'])->toBeEmpty();

    unlink($ruta);
});

it('acepta los encabezados como los escribe el cliente', function () {
    // «NIT», «Fecha», «Precio», «IVA», «Ref» — y con tildes y mayúsculas.
    $ruta = csvFacturasTemporal([
        'Factura;NIT;Fecha;Ref;Descripción;Cantidad;Precio;IVA',
        '1;900123456;01/10/2026;REF-001;Pañal etapa 2;2;45000;19',
    ]);

    $previa = $this->importador->previsualizar($ruta);

    expect($previa['errores'])->toBeEmpty()
        ->and($previa['facturas'][0]['total'])->toEqual(107100.0);

    unlink($ruta);
});

it('un reparto sin monto declarado queda marcado con error', function () use ($ENCABEZADO) {
    $ruta = csvFacturasTemporal([
        $ENCABEZADO,
        '1;900123456;01/10/2026;;REF-001;Venta acuerdo;1;100000000;0;0;reparto;;great_baby;Acuerdo comercial;',
    ]);

    $previa = $this->importador->previsualizar($ruta);

    expect($previa['facturas'][0]['errores'])->not->toBeEmpty()
        ->and($previa['resumen']['listas'])->toBe(0);

    // Y no se crea.
    $this->importador->importar($ruta);
    expect(FacturaVenta::count())->toBe(0);

    unlink($ruta);
});

it('importa un reparto y lo deja partido en el asiento', function () use ($ENCABEZADO) {
    $ruta = csvFacturasTemporal([
        $ENCABEZADO,
        '1;900123456;01/10/2026;;REF-001;Venta acuerdo;1;100000000;0;0;reparto;20000000;great_baby;Se declara el 20 %;',
    ]);

    expect($this->importador->importar($ruta)['creadas'])->toBe(1);

    $cxc = fn (DestinoFiscal $d) => (float) MovimientoContable::where('cuenta_puc', cta('cta_cxc_default'))
        ->where('destino_fiscal', $d->value)->sum('debe');

    expect($cxc(DestinoFiscal::Siigo))->toEqual(20_000_000.0)
        ->and($cxc(DestinoFiscal::Interno))->toEqual(80_000_000.0);

    unlink($ruta);
});

it('respeta la línea de negocio del archivo', function () use ($ENCABEZADO) {
    $ruta = csvFacturasTemporal([
        $ENCABEZADO,
        '1;900123456;01/10/2026;;REF-001;Pañal etapa 2;1;45000;0;0;interno;;dropi;Venta sin factura;',
    ]);

    $this->importador->importar($ruta);

    expect(MovimientoContable::where('linea_negocio', LineaNegocio::Dropi->value)->count())->toBeGreaterThan(0)
        ->and(MovimientoContable::where('linea_negocio', LineaNegocio::GreatBaby->value)->count())->toBe(0)
        ->and(MovimientoContable::where('destino_fiscal', DestinoFiscal::Siigo->value)->count())->toBe(0);

    unlink($ruta);
});

it('avisa cuando falta la referencia del producto', function () use ($ENCABEZADO) {
    $ruta = csvFacturasTemporal([
        $ENCABEZADO,
        '1;900123456;01/10/2026;;;Flete suelto;1;20000;0;0;fiscal;;great_baby;;',
    ]);

    $previa = $this->importador->previsualizar($ruta);

    expect($previa['facturas'][0]['errores'])->not->toBeEmpty()
        ->and(implode(' ', $previa['facturas'][0]['errores']))->toContain('referencia');

    unlink($ruta);
});

it('lee un CSV subido por formulario, que en disco es un .tmp sin extension', function () use ($ENCABEZADO) {
    /*
     * Esto se rompio en pantalla y no en los tests: un archivo subido vive en
     * disco como `php3B7.tmp`, el lector miraba ESA extension y mandaba todo
     * CSV al lector de XLSX, que respondia «Could not open ... for reading» —
     * un mensaje que no le dice a nadie que el archivo estaba bien.
     */
    $csv = csvFacturasTemporal([
        $ENCABEZADO,
        '1;900123456;01/10/2026;;REF-001;Panal etapa 2;1;45000;0;0;fiscal;;great_baby;;',
    ]);

    $comoLoGuardaPhp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'php'.uniqid().'.tmp';
    rename($csv, $comoLoGuardaPhp);

    // Con el nombre original, que es lo que pasa el controlador.
    expect($this->importador->previsualizar($comoLoGuardaPhp, 'facturas-del-cliente.csv')['resumen']['listas'])->toBe(1);

    // Y sin el: la firma del archivo alcanza para no mandarlo al lector de XLSX.
    expect($this->importador->previsualizar($comoLoGuardaPhp)['resumen']['listas'])->toBe(1);

    unlink($comoLoGuardaPhp);
});

it('avisa cuando el encabezado no se encuentra', function () {
    $ruta = csvFacturasTemporal(['hola;que;tal', 'esto;no;es una factura']);

    $previa = $this->importador->previsualizar($ruta);

    expect($previa['errores'])->not->toBeEmpty()
        ->and($previa['facturas'])->toBeEmpty();

    unlink($ruta);
});
