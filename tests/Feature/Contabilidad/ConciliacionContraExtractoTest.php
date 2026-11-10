<?php

/**
 * Conciliar el extracto del banco contra los pagos del ERP.
 *
 * `conciliaciones_bancarias` existía desde septiembre y ningún controlador la
 * nombraba: no había ruta por donde llegarle. Y aun enganchada habría servido
 * a medias, porque guardaba un saldo escrito a mano y la resta contra el
 * sistema. Eso dice CUÁNTO sobra; no dice cuál movimiento sobra, que es el
 * trabajo que de verdad se hace a mano con el extracto impreso al lado.
 *
 * Lo que se protege acá:
 *   · que la lectura del extracto se compruebe contra el propio extracto
 *     —saldo anterior + abonos − cargos = saldo actual— y que uno que no
 *     cuadre NO se pueda guardar: una conciliación que parece completa y no lo
 *     está es peor que ninguna;
 *   · que cada pago del ERP se use UNA vez, porque dos consignaciones iguales
 *     el mismo día son dos movimientos y no uno contado dos veces;
 *   · que lo que el banco cobra solo (4x1000, cuota de manejo) salga con
 *     cuenta sugerida en vez de quedar como diferencia sin explicar;
 *   · que los pagos registrados que el banco nunca vio queden guardados
 *     también, porque dejarlos fuera haría mentir a la conciliación.
 *
 * Los números de los extractos de acá son inventados. El formato es el real de
 * Bancolombia, que es el que usa Great Baby: fecha `D/MM` sin año, valor con
 * signo, saldo corrido, y el encabezado repetido en cada página.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Contabilidad\Actions\ConciliarExtracto;
use App\Modules\Contabilidad\Actions\LeerExtractoBancario;
use App\Modules\Contabilidad\Models\ConciliacionBancaria;
use App\Modules\Contabilidad\Models\ConciliacionLinea;
use App\Support\Reglas;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
    Reglas::limpiarRequestCache();

    $this->cliente = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900777666',
        'nombre_completo' => 'Pañalera del Centro', 'es_cliente' => true,
    ]);

    $this->proveedor = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '901555444',
        'nombre_completo' => 'Transportes del Norte', 'es_proveedor' => true,
    ]);

    Role::findOrCreate('Gerencia', 'web');
    $this->contadora = User::factory()->create();
    $this->contadora->assignRole('Gerencia');
});

/** Un extracto con el formato real del banco y números inventados. */
function extractoDe(array $lineas, float $anterior, float $actual, string $desde = '2026/08/31', string $hasta = '2026/09/30'): string
{
    return implode("\n", array_merge([
        'ESTADO DE CUENTA',
        "DESDE: {$desde} HASTA: {$hasta}",
        'CUENTA DE AHORROS',
        'NÚMERO 30300002664',
        'RESUMEN',
        'SALDO ANTERIOR $ '.number_format($anterior, 2),
        'SALDO ACTUAL $ '.number_format($actual, 2),
        'FECHA DESCRIPCIÓN SUCURSAL DCTO. VALOR SALDO',
    ], $lineas));
}

/** `1/09 TRANSFERENCIA DESDE NEQUI 355,000.00 227,920,668.05` */
function movimiento(string $fecha, string $texto, float $valor, float $saldo): string
{
    return $fecha.' '.$texto.' '.number_format($valor, 2).' '.number_format($saldo, 2);
}

function pagoDeCliente(string $fecha, float $monto, ?string $medio = 'transferencia'): PagoVenta
{
    return PagoVenta::create([
        'contacto_id' => test()->cliente->id,
        'fecha' => $fecha,
        'monto_recibido' => $monto,
        'monto_aplicado' => $monto,
        'medio_pago' => $medio,
    ]);
}

function pagoAProveedor(string $fecha, float $monto, string $estado = 'confirmado', string $metodo = 'transferencia'): PagoProveedor
{
    return PagoProveedor::create([
        'contacto_id' => test()->proveedor->id,
        'fecha' => $fecha,
        'monto_bruto' => $monto, 'iva' => 0, 'monto_retenciones' => 0, 'monto_neto' => $monto,
        'estado' => $estado, 'metodo' => $metodo,
        'user_id' => test()->contadora->id,
    ]);
}

// ------------------------------------------------------------------- lectura

it('lee los movimientos y les pone el año del período', function () {
    $txt = extractoDe([
        movimiento('1/09', 'TRANSFERENCIA DESDE NEQUI', 355000, 1355000),
        movimiento('2/09', 'PAGO A PROVE TRANSPORTES', -155000, 1200000),
    ], 1000000, 1200000);

    $r = LeerExtractoBancario::run($txt);

    expect($r['movimientos'])->toHaveCount(2)
        ->and($r['movimientos'][0]['fecha'])->toBe('2026-09-01')
        ->and($r['movimientos'][0]['descripcion'])->toBe('TRANSFERENCIA DESDE NEQUI')
        ->and($r['movimientos'][0]['valor'])->toBe(355000.0)
        // El valor ya viene con signo: no hay que adivinar por la descripción.
        ->and($r['movimientos'][1]['valor'])->toBe(-155000.0);
});

it('comprueba el extracto contra sí mismo', function () {
    $txt = extractoDe([
        movimiento('1/09', 'CONSIGNACION', 500000, 1500000),
        movimiento('2/09', 'IMPTO GOBIERNO 4X1000', -2000, 1498000),
    ], 1000000, 1498000);

    $r = LeerExtractoBancario::run($txt);

    expect($r['cuadra'])->toBeTrue()
        ->and($r['descuadre'])->toBe(0.0)
        ->and($r['total_abonos'])->toBe(500000.0)
        ->and($r['total_cargos'])->toBe(2000.0);
});

it('avisa cuando el extracto no cuadra: se perdió una línea al leer', function () {
    // El saldo actual dice 1.498.000 pero los movimientos sólo dan 1.500.000.
    $txt = extractoDe([
        movimiento('1/09', 'CONSIGNACION', 500000, 1500000),
    ], 1000000, 1498000);

    $r = LeerExtractoBancario::run($txt);

    expect($r['cuadra'])->toBeFalse()
        ->and($r['descuadre'])->toBe(-2000.0);
});

it('descarta los encabezados que el banco repite en cada página', function () {
    $txt = extractoDe([
        movimiento('1/09', 'CONSIGNACION', 500000, 1500000),
        'DCF:defensor@bancolombia.com.co;www.bancolombia.com/personas/defensor-financiero',
        'PÁGINA: 2',
        'ESTADO DE CUENTA',
        'FECHA DESCRIPCIÓN SUCURSAL DCTO. VALOR SALDO',
        movimiento('2/09', 'CONSIGNACION', 300000, 1800000),
        'FIN ESTADO DE CUENTA',
    ], 1000000, 1800000);

    $r = LeerExtractoBancario::run($txt);

    expect($r['movimientos'])->toHaveCount(2)
        ->and($r['cuadra'])->toBeTrue();
});

it('un extracto que cruza el año pone cada mes en su año', function () {
    $txt = extractoDe([
        movimiento('28/12', 'CONSIGNACION', 100000, 1100000),
        movimiento('3/01', 'CONSIGNACION', 200000, 1300000),
    ], 1000000, 1300000, '2026/12/26', '2027/01/25');

    $r = LeerExtractoBancario::run($txt);

    expect($r['movimientos'][0]['fecha'])->toBe('2026-12-28')
        // El año del segundo es el del `hasta`, no el del `desde`.
        ->and($r['movimientos'][1]['fecha'])->toBe('2027-01-03');
});

it('sin saldos en el encabezado avisa que no se pudo verificar', function () {
    $txt = "FECHA DESCRIPCIÓN VALOR SALDO\n".movimiento('1/09', 'CONSIGNACION', 500000, 1500000);

    $r = LeerExtractoBancario::run($txt);

    expect($r['movimientos'])->toHaveCount(1)
        ->and($r['verificable'])->toBeFalse()
        ->and($r['cuadra'])->toBeFalse();
});

// -------------------------------------------------------------------- cruce

it('casa un abono con el pago del cliente', function () {
    $pago = pagoDeCliente('2026-09-01', 355000);

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-01', 'descripcion' => 'TRANSFERENCIA', 'valor' => 355000.0, 'saldo' => 0],
    ]);

    expect($r['conciliados'])->toHaveCount(1)
        ->and($r['conciliados'][0]['pago_tipo'])->toBe('venta')
        ->and($r['conciliados'][0]['pago_id'])->toBe($pago->id)
        ->and($r['solo_banco'])->toBeEmpty();
});

it('casa un cargo con el pago al proveedor', function () {
    $pago = pagoAProveedor('2026-09-02', 155000);

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-02', 'descripcion' => 'PAGO A PROVE', 'valor' => -155000.0, 'saldo' => 0],
    ]);

    expect($r['conciliados'][0]['pago_tipo'])->toBe('proveedor')
        ->and($r['conciliados'][0]['pago_id'])->toBe($pago->id);
});

it('un pago del viernes que el banco registró el lunes todavía casa', function () {
    pagoDeCliente('2026-09-04', 355000);   // viernes

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-07', 'descripcion' => 'TRANSFERENCIA', 'valor' => 355000.0, 'saldo' => 0],
    ], 3);

    expect($r['conciliados'])->toHaveCount(1)
        ->and($r['conciliados'][0]['dias_desfase'])->toBe(3);
});

it('dentro del periodo pero fuera de la holgura: no casa y queda a la vista', function () {
    // El pago cae dentro del mes del extracto, asi que SI es un pago que este
    // extracto deberia explicar. Pero esta a quince dias del unico movimiento
    // del mismo valor, y eso no es «el banco lo registro al dia siguiente»:
    // es otra cosa. No se casa, y los dos lados quedan a la vista.
    pagoDeCliente('2026-09-15', 355000);

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-01', 'descripcion' => 'CUOTA PLAN', 'valor' => -78000.0, 'saldo' => 0],
        ['fecha' => '2026-09-30', 'descripcion' => 'TRANSFERENCIA', 'valor' => 355000.0, 'saldo' => 0],
    ], 3);

    expect($r['conciliados'])->toBeEmpty()
        ->and($r['solo_banco'])->toHaveCount(2)
        ->and($r['solo_erp'])->toHaveCount(1);
});

it('un pago de otro mes no cuenta como faltante: estara en SU extracto', function () {
    // La ventana de candidatos es la del extracto mas la holgura. Un pago de
    // tres semanas antes no es un pago que «le falte» a este extracto: aparece
    // en el del mes pasado, y listarlo aca inventaria una diferencia.
    pagoDeCliente('2026-09-01', 355000);

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-20', 'descripcion' => 'TRANSFERENCIA', 'valor' => 355000.0, 'saldo' => 0],
    ], 3);

    expect($r['conciliados'])->toBeEmpty()
        ->and($r['solo_banco'])->toHaveCount(1)
        ->and($r['solo_erp'])->toBeEmpty();
});

it('cada pago se usa una sola vez', function () {
    // Un solo pago de 355.000 contra DOS consignaciones iguales el mismo día.
    pagoDeCliente('2026-09-01', 355000);

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-01', 'descripcion' => 'TRANSFERENCIA', 'valor' => 355000.0, 'saldo' => 0],
        ['fecha' => '2026-09-01', 'descripcion' => 'TRANSFERENCIA', 'valor' => 355000.0, 'saldo' => 0],
    ]);

    expect($r['conciliados'])->toHaveCount(1)
        ->and($r['solo_banco'])->toHaveCount(1);
});

it('entre dos candidatos gana el de fecha más cercana', function () {
    $lejos = pagoDeCliente('2026-09-01', 355000);
    $cerca = pagoDeCliente('2026-09-03', 355000);

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-03', 'descripcion' => 'TRANSFERENCIA', 'valor' => 355000.0, 'saldo' => 0],
    ], 3);

    expect($r['conciliados'][0]['pago_id'])->toBe($cerca->id)
        ->and($r['solo_erp'][0]['pago_id'])->toBe($lejos->id);
});

it('un pago anulado no debería estar en el banco, y no se usa', function () {
    pagoAProveedor('2026-09-02', 155000, 'anulado');

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-02', 'descripcion' => 'PAGO A PROVE', 'valor' => -155000.0, 'saldo' => 0],
    ]);

    expect($r['conciliados'])->toBeEmpty()
        ->and($r['solo_banco'])->toHaveCount(1);
});

it('lo que el banco cobra solo sale con cuenta sugerida', function () {
    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-01', 'descripcion' => 'IMPTO GOBIERNO 4X1000', 'valor' => -2000.0, 'saldo' => 0],
        ['fecha' => '2026-09-01', 'descripcion' => 'ABONO INTERESES AHORROS', 'valor' => 663.0, 'saldo' => 0],
        ['fecha' => '2026-09-01', 'descripcion' => 'PAGO A FULANO DE TAL', 'valor' => -90000.0, 'saldo' => 0],
    ]);

    $porDescripcion = collect($r['solo_banco'])->keyBy('descripcion');

    expect($porDescripcion['IMPTO GOBIERNO 4X1000']['cuenta_sugerida']['cuenta'])->toBe('530525')
        ->and($porDescripcion['ABONO INTERESES AHORROS']['cuenta_sugerida']['cuenta'])->toBe('421005')
        // Un pago a un tercero no es un cobro del banco: no se le inventa cuenta.
        ->and($porDescripcion['PAGO A FULANO DE TAL']['cuenta_sugerida'])->toBeNull();
});

it('los centavos del banco no impiden que case con un pago redondo', function () {
    pagoDeCliente('2026-09-01', 355000);

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-01', 'descripcion' => 'TRANSFERENCIA', 'valor' => 355000.004, 'saldo' => 0],
    ]);

    expect($r['conciliados'])->toHaveCount(1);
});

// --------------------------------------------------------------- la pantalla

it('previsualizar no guarda nada', function () {
    pagoDeCliente('2026-09-01', 500000);

    $txt = extractoDe([movimiento('1/09', 'CONSIGNACION', 500000, 1500000)], 1000000, 1500000);

    $this->actingAs($this->contadora)
        ->postJson('/app/contabilidad/conciliacion-bancaria/previsualizar', ['texto' => $txt])
        ->assertOk()
        ->assertJsonPath('cruce.resumen.conciliados', 1);

    // Un extracto mal leído metería cuatrocientas líneas falsas en la contabilidad.
    expect(ConciliacionBancaria::count())->toBe(0)
        ->and(ConciliacionLinea::count())->toBe(0);
});

it('guarda la cabecera y las líneas en los tres montones', function () {
    pagoDeCliente('2026-09-01', 500000);        // casa
    pagoAProveedor('2026-09-05', 90000);        // el banco nunca lo vio

    $txt = extractoDe([
        movimiento('1/09', 'CONSIGNACION', 500000, 1500000),
        movimiento('2/09', 'IMPTO GOBIERNO 4X1000', -2000, 1498000),
    ], 1000000, 1498000);

    $this->actingAs($this->contadora)
        ->post('/app/contabilidad/conciliacion-bancaria', [
            'texto' => $txt, 'banco' => 'Bancolombia', 'cuenta_puc' => '1110',
        ])
        ->assertRedirect();

    $c = ConciliacionBancaria::firstOrFail();

    expect($c->periodo_desde->toDateString())->toBe('2026-08-31')
        ->and((float) $c->saldo_extracto)->toBe(1498000.0)
        ->and($c->cuadraConsigoMisma())->toBeTrue()
        ->and($c->lineas()->where('estado', 'conciliada')->count())->toBe(1)
        ->and($c->lineas()->where('estado', 'solo_banco')->count())->toBe(1)
        // El pago que el banco nunca vio también se guarda: dejarlo fuera
        // haría que la conciliación mintiera por omisión.
        ->and($c->lineas()->where('estado', 'solo_erp')->count())->toBe(1);
});

it('al 4x1000 se le guarda la cuenta sugerida', function () {
    $txt = extractoDe([movimiento('1/09', 'IMPTO GOBIERNO 4X1000', -2000, 998000)], 1000000, 998000);

    $this->actingAs($this->contadora)
        ->post('/app/contabilidad/conciliacion-bancaria', ['texto' => $txt, 'banco' => 'Bancolombia']);

    expect(ConciliacionLinea::where('estado', 'solo_banco')->value('cuenta_puc'))->toBe('530525');
});

it('un extracto que no cuadra consigo mismo no se guarda', function () {
    $txt = extractoDe([movimiento('1/09', 'CONSIGNACION', 500000, 1500000)], 1000000, 1498000);

    $this->actingAs($this->contadora)
        ->post('/app/contabilidad/conciliacion-bancaria', ['texto' => $txt, 'banco' => 'Bancolombia'])
        ->assertSessionHas('error');

    // Una conciliación que parece completa y no lo está es peor que ninguna.
    expect(ConciliacionBancaria::count())->toBe(0);
});

it('se le puede asignar cuenta a una línea que nadie registró', function () {
    $txt = extractoDe([movimiento('1/09', 'PAGO A FULANO', -90000, 910000)], 1000000, 910000);

    $this->actingAs($this->contadora)
        ->post('/app/contabilidad/conciliacion-bancaria', ['texto' => $txt, 'banco' => 'Bancolombia']);

    $c = ConciliacionBancaria::firstOrFail();
    $linea = $c->lineas()->firstOrFail();

    expect($c->sinExplicar())->toBe(1);

    $this->actingAs($this->contadora)
        ->post("/app/contabilidad/conciliacion-bancaria/{$c->id}/lineas/{$linea->id}",
            ['cuenta_puc' => '519595', 'nota' => 'Honorarios sin registrar'])
        ->assertRedirect();

    expect($c->fresh()->sinExplicar())->toBe(0)
        ->and($linea->fresh()->cuenta_puc)->toBe('519595');
});

it('quien no lleva contabilidad no entra', function () {
    $cualquiera = User::factory()->create();

    $this->actingAs($cualquiera)
        ->get('/app/contabilidad/conciliacion-bancaria')
        ->assertForbidden();
});

it('un texto que no es un extracto no rompe nada', function () {
    $this->actingAs($this->contadora)
        ->postJson('/app/contabilidad/conciliacion-bancaria/previsualizar', [
            'texto' => str_repeat('esto no es un extracto bancario de ninguna parte. ', 3),
        ])
        ->assertStatus(422);
});

// ------------------------------------------- qué pasa de verdad por el banco

it('el efectivo no compite por un movimiento del extracto', function () {
    $this->seed(\Database\Seeders\MetodosPagoSeeder::class);

    /*
     * Dos pagos iguales el mismo día: uno en efectivo —que quedó en la caja de
     * la oficina— y una transferencia. El banco vio UNO solo.
     *
     * Antes la bolsa de candidatos los traía a los dos y el cruce se quedaba
     * con el primero que encontrara: podía casar la línea del banco con el
     * pago en efectivo y dejar la transferencia real listada como «plata que
     * el banco nunca vio».
     */
    $efectivo = pagoDeCliente('2026-09-11', 1200000, 'efectivo');
    $transfer = pagoDeCliente('2026-09-11', 1200000, 'transferencia');

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-11', 'descripcion' => 'CONSIGNACION CLIENTE', 'valor' => 1200000.0],
    ]);

    expect($r['conciliados'])->toHaveCount(1)
        ->and($r['conciliados'][0]['pago_id'])->toBe($transfer->id)
        // Y el de efectivo ni siquiera aparece como pendiente: nunca pasó por acá.
        ->and(collect($r['solo_erp'])->pluck('pago_id')->all())->not->toContain($efectivo->id)
        ->and($r['solo_erp'])->toHaveCount(0);
});

it('los cobros de la wallet de Dropi no ensucian el montón de «sólo ERP»', function () {
    $this->seed(\Database\Seeders\MetodosPagoSeeder::class);

    /*
     * La sincronización de la wallet crea un PagoVenta por CADA guía cobrada.
     * En un mes son el grueso de los pagos y ninguno puede estar en el extracto
     * de Bancolombia: si entraran, cada conciliación arrancaría con decenas de
     * líneas falsas y la pantalla diría que no se puede asentar.
     */
    foreach ([120000, 95000, 230000] as $i => $monto) {
        pagoDeCliente('2026-09-0'.($i + 2), $monto, 'wallet_dropi');
    }

    // Y el cruce de cuentas y el NEC tampoco: no mueven un peso.
    pagoDeCliente('2026-09-05', 500000, 'cruce_cuentas');
    pagoDeCliente('2026-09-06', 400000, 'nec');

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-10', 'descripcion' => 'CONSIGNACION', 'valor' => 777000.0],
    ]);

    expect($r['solo_erp'])->toHaveCount(0)
        ->and($r['resumen']['monto_solo_erp'])->toBe(0.0);
});

it('un medio que no está en el maestro igual se tiene en cuenta', function () {
    $this->seed(\Database\Seeders\MetodosPagoSeeder::class);

    /*
     * Un cheque sí pasa por el banco y no está en el maestro. Por eso se lista
     * lo que se descarta y no lo que se acepta: dejarlo fuera escondería un
     * pago real y su línea del banco quedaría como «plata que nadie registró».
     */
    $viejo = pagoDeCliente('2026-09-14', 640000, 'cheque');

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-14', 'descripcion' => 'CONSIGNACION', 'valor' => 640000.0],
    ]);

    expect($r['conciliados'])->toHaveCount(1)
        ->and($r['conciliados'][0]['pago_id'])->toBe($viejo->id);
});

it('al proveedor también: lo que se pagó en efectivo no sale del banco', function () {
    $this->seed(\Database\Seeders\MetodosPagoSeeder::class);

    pagoAProveedor('2026-09-18', 300000, 'confirmado', 'efectivo');
    $giro = pagoAProveedor('2026-09-18', 300000, 'confirmado', 'transferencia');

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-18', 'descripcion' => 'PAGO A PROVEEDOR', 'valor' => -300000.0],
    ]);

    expect($r['conciliados'])->toHaveCount(1)
        ->and($r['conciliados'][0]['pago_id'])->toBe($giro->id)
        ->and($r['solo_erp'])->toHaveCount(0);
});

it('si el maestro de métodos está vacío igual concilia', function () {
    // Instalación nueva, seeder sin correr: sin maestro no se descarta nada y
    // el cruce se comporta como antes. Vale más de más que no conciliar.
    DB::table('metodos_pago')->delete();

    $pago = pagoDeCliente('2026-09-20', 150000, 'transferencia');

    $r = ConciliarExtracto::run([
        ['fecha' => '2026-09-20', 'descripcion' => 'CONSIGNACION', 'valor' => 150000.0],
    ]);

    expect($r['conciliados'])->toHaveCount(1)
        ->and($r['conciliados'][0]['pago_id'])->toBe($pago->id);
});

// ------------------------------------------------- subir el archivo del banco

/** El mismo extracto de siempre, como contenido de archivo. */
function extractoDeArchivo(): string
{
    return extractoDe([
        movimiento('3/09', 'PAGO DE PROV PEXTO COLOMBIA', 47808000, 147808000),
        movimiento('4/09', 'TRANSFERENCIA DESDE NEQUI', 120000, 147928000),
    ], 100000000, 147928000);
}

it('lee el extracto subido como archivo de texto', function () {
    /*
     * Pegar 439 renglones sin saltarse ninguno es el paso donde se equivoca
     * cualquiera: la línea que falta no rompe nada a la vista, el descuadre
     * aparece al final y hay que empezar de nuevo.
     */
    $r = $this->actingAs($this->contadora)
        ->post('/app/contabilidad/conciliacion-bancaria/previsualizar', [
            'archivo' => \Illuminate\Http\UploadedFile::fake()
                ->createWithContent('extracto.txt', extractoDeArchivo()),
            'banco' => 'Bancolombia',
        ]);

    $r->assertOk();
    expect($r->json('extracto.cuadra'))->toBeTrue()
        ->and($r->json('extracto.cuenta'))->toBe('30300002664')
        ->and(count($r->json('solo_banco')) + count($r->json('conciliados')))->toBe(2);
});

it('lee el extracto subido como Excel', function () {
    // Algunos bancos sólo entregan la hoja de cálculo.
    $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $hoja = $libro->getActiveSheet();

    foreach (preg_split('/\R/u', extractoDeArchivo()) as $i => $linea) {
        $hoja->setCellValueExplicit([1, $i + 1], $linea,
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }

    $ruta = sys_get_temp_dir().'/extracto-prueba-'.getmypid().'.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($ruta);

    $r = $this->actingAs($this->contadora)
        ->post('/app/contabilidad/conciliacion-bancaria/previsualizar', [
            'archivo' => new \Illuminate\Http\UploadedFile($ruta, 'extracto.xlsx', null, null, true),
            'banco' => 'Bancolombia',
        ]);

    @unlink($ruta);

    $r->assertOk();
    expect($r->json('extracto.cuadra'))->toBeTrue();
});

it('un archivo que no sabemos leer se rechaza diciendo cuáles sí', function () {
    $r = $this->actingAs($this->contadora)
        ->postJson('/app/contabilidad/conciliacion-bancaria/previsualizar', [
            'archivo' => \Illuminate\Http\UploadedFile::fake()->create('contrato.docx', 10),
            'banco' => 'Bancolombia',
        ]);

    // Mejor que avise a que lea cualquier cosa e invente movimientos.
    $r->assertStatus(422);
});

it('el archivo manda sobre el texto que haya quedado pegado', function () {
    $r = $this->actingAs($this->contadora)
        ->post('/app/contabilidad/conciliacion-bancaria/previsualizar', [
            'archivo' => \Illuminate\Http\UploadedFile::fake()
                ->createWithContent('extracto.txt', extractoDeArchivo()),
            // Texto viejo de otro mes: si se leyera éste, el período saldría mal.
            'texto' => extractoDe([movimiento('1/07', 'OTRA COSA', 5000, 5000)], 0, 5000,
                '2026/07/01', '2026/07/31'),
            'banco' => 'Bancolombia',
        ]);

    expect($r->json('extracto.desde'))->toBe('2026-08-31');
});

it('al guardar se vuelve a leer el archivo, no lo que diga el navegador', function () {
    $this->actingAs($this->contadora)
        ->post('/app/contabilidad/conciliacion-bancaria', [
            'archivo' => \Illuminate\Http\UploadedFile::fake()
                ->createWithContent('extracto.txt', extractoDeArchivo()),
            'banco' => 'Bancolombia',
            'cuenta_puc' => '1110',
        ])->assertRedirect();

    /*
     * Si se guardara lo que manda la pantalla, bastaría con cambiarlo en el
     * camino para asentar una conciliación que nunca existió.
     */
    $c = ConciliacionBancaria::latest('id')->first();

    expect($c)->not->toBeNull()
        ->and($c->cuenta_numero)->toBe('30300002664')
        ->and(ConciliacionLinea::where('conciliacion_id', $c->id)->count())->toBe(2);
});

it('un archivo vacío no pasa por bueno', function () {
    $r = $this->actingAs($this->contadora)
        ->postJson('/app/contabilidad/conciliacion-bancaria/previsualizar', [
            'archivo' => \Illuminate\Http\UploadedFile::fake()->createWithContent('extracto.txt', '   '),
            'banco' => 'Bancolombia',
        ]);

    $r->assertStatus(422);
});

it('las reglas de la conciliación las puede tocar el Contador', function () {
    /*
     * La pantalla de Reglas filtra por el PREFIJO DE LA CLAVE, no por el grupo:
     * `contable.*` es lo del Contador y `contabilidad.*` son los candados que
     * sólo abre Gerencia. Estas dos nacieron con el prefijo equivocado y
     * quedaban invisibles justo para quien concilia, que es quien sabe cuántos
     * días de holgura da su banco y a qué cuenta va cada concepto.
     */
    foreach (['contable.dias_match_extracto', 'contable.conceptos_banco'] as $clave) {
        expect(Reglas::DEFAULTS)->toHaveKey($clave);
    }

    // Y que no haya quedado ninguna con el prefijo que las escondía.
    $escondidas = array_filter(
        array_keys(Reglas::DEFAULTS),
        fn ($k) => str_contains($k, 'match_extracto') || str_contains($k, 'conceptos_banco')
    );

    foreach ($escondidas as $k) {
        expect($k)->toStartWith('contable.');
    }
});

it('la pantalla le pasa a las pestañas el nombre de prop que existe', function () {
    /*
     * `PestanasSeccion` declara `pestanas` y la pantalla le pasaba `items`. Vue
     * no avisa en producción: la barra de pestañas sale vacía y la persona se
     * queda sin forma de moverse entre las pantallas contables.
     */
    foreach (['Contabilidad/ConciliacionBancaria/Index.vue', 'Contabilidad/Cajas.vue'] as $vista) {
        $html = file_get_contents(resource_path('js/Pages/'.$vista));

        expect($html)->toContain('<PestanasSeccion :pestanas=')
            ->and($html)->not->toContain('<PestanasSeccion :items=');
    }
});
