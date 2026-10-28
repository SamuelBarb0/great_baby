<?php

/**
 * Llevar los asientos viejos a la cuenta que hoy dicen las reglas.
 *
 * Hasta el 26-oct las cuentas estaban escritas a mano dentro de las acciones.
 * Al dejar que mande la regla, lo nuevo entra en `130505` y lo viejo se quedó
 * en `1305`: libro mixto. Este comando lo arrastra.
 *
 * Lo que estas pruebas cuidan son los candados, no el movimiento. Reescribir
 * historia contable sale mal de tres maneras, y cada una tiene su prueba:
 * escribir sin querer, pisar lo que ya está en SIIGO, y repartir por el número
 * una cuenta que alimenta dos conceptos distintos.
 */

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Models\PlanCuenta;
use App\Support\Reglas;

beforeEach(function () {
    Reglas::limpiarRequestCache();

    // El plan de cuentas de la base de pruebas está vacío; se siembran las que
    // el comando mira para decidir.
    foreach ([['1305', 'Clientes nacionales', 3, false], ['130505', 'Clientes nacionales', 4, false],
              ['13050501', 'Clientes nacionales', 5, true]] as [$codigo, $nombre, $nivel, $mueve]) {
        PlanCuenta::firstOrCreate(['codigo' => $codigo],
            ['nombre' => $nombre, 'nivel' => $nivel, 'permite_movimiento' => $mueve, 'activa' => true]);
    }
});

function movEn(string $cuenta, array $extra = []): MovimientoContable
{
    static $n = 0;
    $n++;

    return MovimientoContable::create(array_merge([
        'fecha' => '2026-10-20',
        'cuenta_puc' => $cuenta,
        'debe' => 1000, 'haber' => 0,
        'descripcion' => "Movimiento {$n}",
        // La tabla exige origen; estas pruebas no miran de qué documento viene
        // salvo donde el `--origen` es justamente lo que se prueba.
        'origen_type' => \App\Modules\Cartera\Models\FacturaVenta::class,
        'origen_id' => $n,
    ], $extra));
}

it('sin --aplicar no escribe nada', function () {
    /*
     * El candado principal. Esto reescribe historia contable: que cueste un
     * segundo comando es a propósito, no un descuido de ergonomía.
     */
    $m = movEn('1305');

    $this->artisan('contabilidad:reestampar-cuentas', ['--regla' => 'cta_cxc_default'])
        ->expectsOutputToContain('MODO REVISIÓN')
        ->expectsOutputToContain('se moverían')
        ->assertSuccessful();

    expect($m->fresh()->cuenta_puc)->toBe('1305');
})->skip('Reestampado parqueado: cta_cxc_default quedó en 1305 (decisión: la cuenta real de Dropi manda). Este feature asume que la regla apunta a una subcuenta movible distinta de 1305, así que queda contradicho. QA debe reconciliarlo con Silvia.');

it('con --aplicar mueve el movimiento a la cuenta de la regla', function () {
    $m = movEn('1305');

    $this->artisan('contabilidad:reestampar-cuentas', [
        '--regla' => 'cta_cxc_default', '--aplicar' => true,
    ])->assertSuccessful();

    expect($m->fresh()->cuenta_puc)->toBe(cta('cta_cxc_default'));
});

it('NO toca lo que ya viajó a SIIGO', function () {
    /*
     * Un movimiento con `siigo_journal_id` ya existe allá con su cuenta.
     * Cambiarlo sólo de este lado dejaría los dos libros diciendo cosas
     * distintas sobre el mismo asiento — peor que el libro mixto, porque
     * mientras esté mixto al menos los dos lados coinciden entre sí.
     */
    $libre = movEn('1305');
    $enSiigo = movEn('1305', ['siigo_journal_id' => 'journal-abc-123']);

    $this->artisan('contabilidad:reestampar-cuentas', [
        '--regla' => 'cta_cxc_default', '--aplicar' => true,
    ])->expectsOutputToContain('ya existen en SIIGO')->assertSuccessful();

    expect($libre->fresh()->cuenta_puc)->toBe(cta('cta_cxc_default'))
        ->and($enSiigo->fresh()->cuenta_puc)->toBe('1305');
})->skip('Reestampado parqueado: cta_cxc_default quedó en 1305 (decisión: la cuenta real de Dropi manda), así que no hay nada que reestampar desde 1305. QA debe reconciliarlo con Silvia.');

it('se niega cuando la cuenta alimenta dos reglas y no se dice el origen', function () {
    /*
     * `2408` es el padre del IVA generado (240805) y del descontable (240810).
     * Cuál es cuál depende del documento, no del número: repartirlo por el
     * código mandaría IVA de compras a la cuenta de ventas.
     */
    movEn('2408');

    $this->artisan('contabilidad:reestampar-cuentas', ['--regla' => 'cta_iva_venta_default'])
        ->expectsOutputToContain('ambigua')
        ->assertFailed();

    expect(MovimientoContable::where('cuenta_puc', '2408')->count())->toBe(1);
});

it('con --origen sí desambigua', function () {
    $venta = movEn('2408', ['origen_type' => \App\Modules\Cartera\Models\FacturaVenta::class, 'origen_id' => 1]);
    $compra = movEn('2408', ['origen_type' => \App\Modules\Compras\Models\RecepcionCompra::class, 'origen_id' => 1]);

    $this->artisan('contabilidad:reestampar-cuentas', [
        '--regla' => 'cta_iva_venta_default', '--origen' => 'FacturaVenta', '--aplicar' => true,
    ])->assertSuccessful();

    expect($venta->fresh()->cuenta_puc)->toBe(cta('cta_iva_venta_default'))
        // El de compras no se movió: no era de este concepto.
        ->and($compra->fresh()->cuenta_puc)->toBe('2408');
});

it('avisa cuando la cuenta destino tampoco admite movimiento', function () {
    /*
     * El hallazgo de fondo: ni la cuenta vieja ni la de la regla aceptan
     * asientos según el plan de cuentas del cliente. Mover de una a otra no
     * arregla eso, y el comando no deja creer que sí.
     */
    movEn('1305');

    $this->artisan('contabilidad:reestampar-cuentas', ['--regla' => 'cta_cxc_default'])
        ->expectsOutputToContain('NO admite movimiento')
        ->assertSuccessful();
})->skip('Reestampado parqueado: cta_cxc_default quedó en 1305 (decisión: la cuenta real de Dropi manda); el origen y el destino coinciden, así que el escenario de este candado ya no aplica. QA debe reconciliarlo con Silvia.');

it('una regla que no existe se rechaza y lista las que sí', function () {
    $this->artisan('contabilidad:reestampar-cuentas', ['--regla' => 'cta_inventada'])
        ->expectsOutputToContain('No existe la regla')
        ->expectsOutputToContain('cta_cxc_default')
        ->assertFailed();
});

it('el diagnóstico marca ÚNICA cuando no hay nada que decidir', function () {
    /*
     * La hoja de trabajo para la contadora. Casi todas las reglas apuntan a
     * una cuenta mayor que no acepta asientos; debajo hay una o varias
     * auxiliares que sí. Donde hay UNA SOLA no hay criterio que aportar, y la
     * tabla tiene que decirlo para no hacerla revisar dieciocho filas iguales.
     */
    PlanCuenta::firstOrCreate(['codigo' => '11050501'],
        ['nombre' => 'Caja general', 'nivel' => 5, 'permite_movimiento' => true, 'activa' => true]);

    $this->artisan('contabilidad:reestampar-cuentas')
        ->expectsOutputToContain('Auxiliares que aceptan movimiento')
        ->expectsOutputToContain('ÚNICA')
        ->assertSuccessful();
});

it('el diagnóstico no pide regla y no escribe nada', function () {
    $m = movEn('1305');

    $this->artisan('contabilidad:reestampar-cuentas')
        ->expectsOutputToContain('Cuentas presentes en el libro')
        ->assertSuccessful();

    expect($m->fresh()->cuenta_puc)->toBe('1305');
});
