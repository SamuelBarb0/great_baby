<?php

/**
 * La nómina del mes.
 *
 * Lo que el ERP sabía hasta acá era quién trabaja, cuánto gana y qué novedades
 * tuvo. El cálculo de lo que de verdad se le consigna a cada persona se hacía
 * por fuera, en un Excel, y entraba al sistema sólo como un asiento por el
 * total.
 *
 * ── Lo que estas pruebas defienden ──────────────────────────────────────────
 *
 * Tres cosas, y ninguna es «que sume bien»:
 *
 *  1. **Una nómina pagada no cambia.** La línea guarda su propio retrato, así
 *     que subirle el sueldo a alguien en diciembre no reescribe lo que se le
 *     pagó en marzo.
 *  2. **El descuento por ausentismo se lee, no se recalcula.** Es lo que
 *     Gestión Humana revisó y pudo corregir a mano. Repetir la fórmula acá
 *     haría que el cuadro del mes y el desprendible dijeran cosas distintas.
 *  3. **Tres manos.** Liquida Gestión Humana, aprueba gerencia, paga
 *     tesorería. Quien calcula lo que se paga no autoriza pagarlo.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Rrhh\Enums\TipoNovedad;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Nomina;
use App\Modules\Rrhh\Models\NominaLinea;
use App\Modules\Rrhh\Models\Novedad;
use App\Modules\Rrhh\Support\LiquidacionNomina;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->junta = User::factory()->create(['name' => 'Junta Directiva']);
    $this->junta->assignRole(rolDeNomina('Gerente'));

    $this->silvia = User::factory()->create(['name' => 'Silvia']);
    $this->silvia->assignRole(rolDeNomina('Contador'));

    $this->jorge = User::factory()->create(['name' => 'Jorge Líder']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));
});

function rolDeNomina(string $nombre): Role
{
    $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

    $rol->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($nombre, $roles, true))
        ->keys()->flatMap(fn ($s) => Permisos::permisosDe($s))->all());

    return $rol;
}

function enNomina(array $extra = []): Empleado
{
    static $n = 0;
    $n++;

    return Empleado::create(array_merge([
        'nombre' => "Trabajador {$n}", 'tipo_documento' => 'CC', 'numero_documento' => "nom{$n}",
        'cargo' => 'Auxiliar', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 1_500_000, 'fecha_ingreso' => '2026-01-15',
        'estado' => 'activo', 'estado_induccion' => 'completada',
    ], $extra));
}

function liquidarMes(string $periodo = '2026-10')
{
    return test()->actingAs(test()->elena)
        ->post('/app/rrhh/nomina/liquidar', ['periodo' => $periodo]);
}

function laNominaDe(string $periodo = '2026-10'): Nomina
{
    return Nomina::with('lineas')->where('periodo', $periodo)->firstOrFail();
}

// ── El cálculo ───────────────────────────────────────────────────────────────

it('paga el mes comercial completo a quien estuvo todo el mes', function () {
    /*
     * Treinta días y no los 31 de octubre: la liquidación colombiana corre
     * sobre el mes comercial, y es el mismo con el que se calculan los
     * descuentos. Si uno usara 30 y el otro el calendario, un descuento de
     * dos días no coincidiría con dos días de salario.
     */
    $e = enNomina(['salario' => 3_000_000]);

    $linea = LiquidacionNomina::de('2026-10')->liquidar($e);

    expect($linea['dias_trabajados'])->toBe(30.0)
        ->and($linea['devengado_salario'])->toBe(3_000_000.0);
});

it('le paga sus días a quien entró o se retiró a mitad de mes', function () {
    /*
     * Si sólo se liquidara a los activos de hoy, quien se fue el 20 no
     * cobraría el mes que trabajó.
     */
    $entró = enNomina(['salario' => 3_000_000, 'fecha_ingreso' => '2026-10-16']);
    $salió = enNomina(['salario' => 3_000_000, 'fecha_ingreso' => '2026-01-10',
        'fecha_retiro' => '2026-10-20', 'estado' => 'retirado']);

    $calculo = LiquidacionNomina::de('2026-10');

    expect($calculo->diasDe($entró))->toBe(16.0)   // del 16 al 31
        ->and($calculo->diasDe($salió))->toBe(20.0) // del 1 al 20
        ->and($calculo->empleados()->pluck('id'))->toContain($salió->id);
});

it('no mete el auxilio de transporte en la base de cotización', function () {
    /*
     * La ley lo excluye expresamente (art. 17 Ley 344 de 1996). Meterlo haría
     * cotizar de más a toda la gente de salario mínimo, mes tras mes.
     */
    $e = enNomina(['salario' => 1_500_000]);

    $l = LiquidacionNomina::de('2026-10')->liquidar($e);

    expect($l['devengado_auxilio'])->toBeGreaterThan(0)
        // 4% de 1.500.000 y no de 1.749.095.
        ->and($l['deduccion_salud'])->toBe(60_000.0)
        ->and($l['deduccion_pension'])->toBe(60_000.0);
});

it('no le da auxilio de transporte a quien gana más de dos mínimos', function () {
    $e = enNomina(['salario' => 5_000_000]);

    $l = LiquidacionNomina::de('2026-10')->liquidar($e);

    expect($l['devengado_auxilio'])->toBe(0.0)
        ->and($l['detalle']['auxilio'])->toContain('dos salarios mínimos');
});

it('tampoco se lo da a quien no se transporta al trabajo', function () {
    /*
     * La otra mitad de la regla, que el sueldo no puede ver: el auxilio
     * compensa el transporte AL TRABAJO. El celador que duerme en la bodega y
     * quien tiene ruta de la empresa no lo devengan, aunque ganen el mínimo.
     */
    $e = enNomina([
        'salario' => 1_500_000,
        'auxilio_excluido' => true,
        'auxilio_excluido_motivo' => 'Vive en la bodega',
    ]);

    $l = LiquidacionNomina::de('2026-10')->liquidar($e);

    expect($e->devengaAuxilioTransporte())->toBeFalse()
        ->and($l['devengado_auxilio'])->toBe(0.0);
});

it('dice el motivo verdadero y no siempre el del sueldo', function () {
    /*
     * El desprendible decía «gana más de 2 salarios mínimos» a todo el que no
     * devengara auxilio, inventándole una razón a quien ganaba el mínimo. Un
     * papel que explica mal es peor que uno que no explica.
     */
    $porSitio = enNomina(['salario' => 1_500_000, 'auxilio_excluido' => true,
        'auxilio_excluido_motivo' => 'Ruta de la empresa']);
    $porSueldo = enNomina(['salario' => 5_000_000]);

    $calculo = LiquidacionNomina::de('2026-10');

    expect($calculo->liquidar($porSitio)['detalle']['auxilio'])
        ->toContain('ruta de la empresa')
        ->not->toContain('salarios mínimos');

    expect($calculo->liquidar($porSueldo)['detalle']['auxilio'])
        ->toContain('dos salarios mínimos');
});

it('el tope de dos mínimos sale de la regla, no de un número escrito', function () {
    /*
     * El salario mínimo cambia cada enero. Si el tope estuviera escrito en el
     * código, cada enero habría que esperar a un programador para que la
     * gente de la franja volviera a devengar auxilio.
     */
    $e = enNomina(['salario' => 3_000_000]);

    $minimo = function (int $valor) {
        App\Models\ReglaNegocio::updateOrCreate(
            ['clave' => 'rrhh.salario_minimo'],
            ['valor' => (string) $valor, 'tipo' => 'float', 'grupo' => 'rrhh',
                'etiqueta' => 'Salario mínimo mensual (SMMLV)'],
        );

        // `Reglas` memoriza las reglas por request; en una prueba el request
        // es uno solo, así que hay que soltarlas para leer el valor nuevo.
        App\Support\Reglas::limpiarRequestCache();
    };

    // Con el mínimo de 2025 ($1.423.500) el tope era $2.847.000: no devengaba.
    $minimo(1_423_500);
    expect($e->devengaAuxilioTransporte())->toBeFalse();

    // Con el de 2026 el tope es $3.501.810: sí devenga.
    $minimo(1_750_905);
    expect($e->fresh()->devengaAuxilioTransporte())->toBeTrue();
});

it('paga las horas extra con el recargo que les toca', function () {
    /*
     * Cuatro factores y una hora ordinaria: salario ÷ (30 × 8). Con 2.400.000
     * la hora vale $10.000, así que una extra nocturna vale $17.500.
     */
    $e = enNomina(['salario' => 2_400_000]);

    Novedad::create([
        'empleado_id' => $e->id, 'tipo' => TipoNovedad::HorasExtra, 'clase' => 'ORDINARIA',
        'fecha_inicio' => '2026-10-10', 'horas' => 4, 'nocturna' => true,
        'periodo' => '2026-10', 'descuento' => 0,
    ]);

    $l = LiquidacionNomina::de('2026-10')->liquidar($e);

    expect($l['devengado_extras'])->toBe(70_000.0)   // 10.000 × 1,75 × 4
        ->and($l['detalle']['extras'])->toContain('1,75')
        // Y sí cotizan: son remuneración del trabajo.
        ->and($l['deduccion_salud'])->toBe(round((2_400_000 + 70_000) * 0.04, 2));
});

it('el dominical nocturno paga más que el ordinario diurno', function () {
    $e = enNomina(['salario' => 2_400_000]);

    Novedad::create([
        'empleado_id' => $e->id, 'tipo' => TipoNovedad::HorasExtra, 'clase' => 'DOMINICAL',
        'fecha_inicio' => '2026-10-11', 'horas' => 4, 'nocturna' => true,
        'periodo' => '2026-10', 'descuento' => 0,
    ]);

    // 10.000 × 2,5 × 4
    expect(LiquidacionNomina::de('2026-10')->liquidar($e)['devengado_extras'])->toBe(100_000.0);
});

it('lee el descuento de la novedad en vez de volver a calcularlo', function () {
    /*
     * La regla de oro. `descuento` es lo que Gestión Humana revisó y, si hizo
     * falta, corrigió a mano. Si la nómina lo recalculara, el cuadro del mes y
     * el desprendible dirían cosas distintas y nadie sabría cuál creer.
     */
    $e = enNomina(['salario' => 2_000_000]);

    Novedad::create([
        'empleado_id' => $e->id, 'tipo' => TipoNovedad::Ausencia, 'clase' => 'INJUSTIFICADA',
        'fecha_inicio' => '2026-10-05', 'dias' => 2, 'periodo' => '2026-10',
        // Un valor que NINGUNA fórmula daría: se acordó con la persona.
        'descuento' => 50_000, 'descuento_manual' => true,
    ]);

    $l = LiquidacionNomina::de('2026-10')->liquidar($e);

    expect($l['deduccion_ausentismo'])->toBe(50_000.0)
        ->and($l['detalle']['ausentismo'])->toContain('ajustado a mano');
});

it('las horas extra no se cuentan como ausentismo', function () {
    $e = enNomina();

    Novedad::create([
        'empleado_id' => $e->id, 'tipo' => TipoNovedad::HorasExtra, 'clase' => 'ORDINARIA',
        'fecha_inicio' => '2026-10-10', 'horas' => 3, 'periodo' => '2026-10', 'descuento' => 0,
    ]);

    expect(LiquidacionNomina::de('2026-10')->liquidar($e)['deduccion_ausentismo'])->toBe(0.0);
});

// ── Lo que pone la empresa ───────────────────────────────────────────────────

/** Cambia una regla y suelta el memo que `Reglas` guarda por request. */
function regla(string $clave, string $valor, string $tipo = 'float'): void
{
    App\Models\ReglaNegocio::updateOrCreate(
        ['clave' => $clave],
        ['valor' => $valor, 'tipo' => $tipo, 'grupo' => 'rrhh', 'etiqueta' => $clave],
    );

    App\Support\Reglas::limpiarRequestCache();
}

it('con exoneración no paga salud patronal, SENA ni ICBF', function () {
    /*
     * La regla que más plata mueve: son 13,5 puntos. Una empresa que declara
     * renta no los paga por quien gane menos de 10 salarios mínimos
     * (Ley 1607/2012 art. 25).
     */
    regla('rrhh.empresa_exonerada_parafiscales', '1', 'bool');

    $e = enNomina(['salario' => 2_000_000, 'arl_nivel_riesgo' => 'I']);

    $l = LiquidacionNomina::de('2026-10')->liquidar($e);

    expect($l['patronal_salud'])->toBe(0.0)
        ->and($l['patronal_sena'])->toBe(0.0)
        ->and($l['patronal_icbf'])->toBe(0.0)
        // Pensión y caja NO se exoneran nunca.
        ->and($l['patronal_pension'])->toBe(240_000.0)
        ->and($l['patronal_caja'])->toBe(80_000.0)
        ->and($l['detalle']['exoneracion'])->toContain('Exonerada');
});

it('sin exoneración los paga completos', function () {
    regla('rrhh.empresa_exonerada_parafiscales', '0', 'bool');

    $e = enNomina(['salario' => 2_000_000, 'arl_nivel_riesgo' => 'I']);

    $l = LiquidacionNomina::de('2026-10')->liquidar($e);

    expect($l['patronal_salud'])->toBe(170_000.0)   // 8,5%
        ->and($l['patronal_sena'])->toBe(40_000.0)  // 2%
        ->and($l['patronal_icbf'])->toBe(60_000.0)  // 3%
        ->and($l['detalle']['exoneracion'])->toContain('Sin exoneración');
});

it('la exoneración no cubre a quien gana diez mínimos o más', function () {
    /*
     * El tope sale de la regla del salario mínimo, así que sube solo cada
     * enero. Con $1.750.905 el tope es $17.509.050.
     */
    regla('rrhh.empresa_exonerada_parafiscales', '1', 'bool');

    $bajo = enNomina(['salario' => 17_000_000, 'arl_nivel_riesgo' => 'I']);
    $alto = enNomina(['salario' => 18_000_000, 'arl_nivel_riesgo' => 'I']);

    $calculo = LiquidacionNomina::de('2026-10');

    expect($calculo->liquidar($bajo)['patronal_salud'])->toBe(0.0)
        ->and($calculo->liquidar($alto)['patronal_salud'])->toBeGreaterThan(0.0);
});

it('cobra la ARL según el nivel de riesgo de la ficha', function () {
    $oficina = enNomina(['salario' => 2_000_000, 'arl_nivel_riesgo' => 'I']);
    $bodega = enNomina(['salario' => 2_000_000, 'arl_nivel_riesgo' => 'III']);

    $calculo = LiquidacionNomina::de('2026-10');

    expect($calculo->liquidar($oficina)['patronal_arl'])->toBe(10_440.0)  // 0,522%
        ->and($calculo->liquidar($bodega)['patronal_arl'])->toBe(48_720.0) // 2,436%
        ->and($calculo->liquidar($bodega)['detalle']['arl'])->toContain('Riesgo III');
});

it('sin nivel de riesgo asume el más bajo y lo dice', function () {
    /*
     * Suponer el más alto inflaría el costo de toda la nómina por un dato que
     * falta; suponerlo en silencio escondería que falta.
     */
    $e = enNomina(['salario' => 2_000_000, 'arl_nivel_riesgo' => null]);

    $l = LiquidacionNomina::de('2026-10')->liquidar($e);

    expect($l['patronal_arl'])->toBe(10_440.0)
        ->and($l['detalle']['arl'])->toContain('no tiene nivel de riesgo');
});

it('provisiona sobre la misma base con la que cotiza', function () {
    /*
     * Si lo pactado aparte cotizara pero no se provisionara, el sistema
     * estaría diciendo que es salario para pagarle a la EPS y no lo es para
     * la prima de la persona. Una de las dos tendría que estar mal.
     */
    $e = enNomina([
        'salario' => 2_000_000,
        'valor_adicional' => 500_000,
        'concepto_adicional' => 'SSI',
        'arl_nivel_riesgo' => 'I',
    ]);

    $l = LiquidacionNomina::de('2026-10')->liquidar($e);

    $ibc = 2_500_000.0;
    $baseProv = $ibc + $l['devengado_auxilio'];

    expect($l['deduccion_salud'])->toBe(round($ibc * 0.04, 2))
        ->and($l['provision_cesantias'])->toBe(round($baseProv * 0.0833, 2))
        ->and($l['provision_prima'])->toBe(round($baseProv * 0.0833, 2))
        // Las vacaciones, sin auxilio: igual que se liquidan al final.
        ->and($l['provision_vacaciones'])->toBe(round($ibc * 0.0417, 2));
});

it('separa lo que sale este mes de lo que se guarda', function () {
    /*
     * Los aportes van a la PILA ya; las provisiones esperan a junio y a
     * febrero. Un solo total haría creer que todo se paga ahora.
     */
    $e = enNomina(['salario' => 2_000_000, 'arl_nivel_riesgo' => 'I']);

    $l = LiquidacionNomina::de('2026-10')->liquidar($e);

    expect($l['total_aportes'])->toBe(round(
        $l['patronal_salud'] + $l['patronal_pension'] + $l['patronal_arl']
        + $l['patronal_caja'] + $l['patronal_sena'] + $l['patronal_icbf'], 2,
    ))->and($l['total_provisiones'])->toBe(round(
        $l['provision_cesantias'] + $l['provision_intereses']
        + $l['provision_prima'] + $l['provision_vacaciones'], 2,
    ));
});

it('el costo del mes es el neto más lo que pone la empresa', function () {
    enNomina(['salario' => 2_000_000, 'arl_nivel_riesgo' => 'I']);
    enNomina(['salario' => 3_000_000, 'arl_nivel_riesgo' => 'III']);

    liquidarMes();
    $n = laNominaDe();

    expect((float) $n->total_costo_empresa)->toBe(round(
        (float) $n->total_neto + (float) $n->total_aportes + (float) $n->total_provisiones, 2,
    ));

    foreach ($n->lineas as $l) {
        expect((float) $l->costo_empresa)->toBe(round(
            (float) $l->neto + (float) $l->total_aportes + (float) $l->total_provisiones, 2,
        ));
    }

    // Y cuesta bastante más que el neto: ese es justo el punto.
    expect((float) $n->total_costo_empresa)->toBeGreaterThan((float) $n->total_neto * 1.2);
});

// ── El retrato ───────────────────────────────────────────────────────────────

it('la línea no cambia cuando a la persona le suben el sueldo después', function () {
    /*
     * Es la razón de que la nómina se guarde y no se calcule al vuelo: el
     * soporte de un pago que ya salió del banco tiene que seguir cuadrando
     * con el banco.
     */
    $e = enNomina(['salario' => 2_000_000, 'cargo' => 'Auxiliar']);

    liquidarMes();

    $e->update(['salario' => 9_000_000, 'cargo' => 'Jefe de bodega', 'nombre' => 'Otro nombre']);

    $l = NominaLinea::where('empleado_id', $e->id)->firstOrFail();

    expect((float) $l->salario_base)->toBe(2_000_000.0)
        ->and($l->cargo)->toBe('Auxiliar')
        ->and($l->nombre)->not->toBe('Otro nombre');
});

// ── Las tres manos ───────────────────────────────────────────────────────────

it('la liquida Gestión Humana y no el líder de área', function () {
    enNomina();

    $this->actingAs($this->jorge)
        ->post('/app/rrhh/nomina/liquidar', ['periodo' => '2026-10'])
        ->assertForbidden();

    liquidarMes()->assertRedirect();

    expect(laNominaDe()->estado)->toBe(Nomina::BORRADOR);
});

it('la aprueba gerencia, no quien la liquidó', function () {
    /*
     * Por la misma razón que la orden de contratación: si Gestión Humana
     * liquidara y aprobara, se estaría aprobando a sí misma.
     */
    enNomina();
    liquidarMes();
    $n = laNominaDe();

    $this->actingAs($this->elena)->post("/app/rrhh/nomina/{$n->id}/aprobar")->assertForbidden();
    $this->actingAs($this->silvia)->post("/app/rrhh/nomina/{$n->id}/aprobar")->assertForbidden();
    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar")->assertRedirect();

    expect($n->refresh()->estado)->toBe(Nomina::APROBADA)
        ->and($n->aprobada_por_id)->toBe($this->junta->id);
});

it('la paga tesorería y sólo después de aprobada', function () {
    enNomina();
    liquidarMes();
    $n = laNominaDe();

    // Sin aprobar todavía.
    $this->actingAs($this->silvia)
        ->post("/app/rrhh/nomina/{$n->id}/pagar", ['fecha_pago' => '2026-10-30'])
        ->assertStatus(422);

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");

    $this->actingAs($this->elena)
        ->post("/app/rrhh/nomina/{$n->id}/pagar", ['fecha_pago' => '2026-10-30'])
        ->assertForbidden();

    $this->actingAs($this->silvia)
        ->post("/app/rrhh/nomina/{$n->id}/pagar", ['fecha_pago' => '2026-10-30'])
        ->assertRedirect();

    expect($n->refresh()->estado)->toBe(Nomina::PAGADA)
        ->and($n->fecha_pago->toDateString())->toBe('2026-10-30');
});

it('no deja entrar a la pantalla a quien no es de nómina', function () {
    $this->actingAs($this->jorge)->get('/app/rrhh/nomina')->assertForbidden();
    $this->actingAs($this->elena)->get('/app/rrhh/nomina')->assertOk();
    $this->actingAs($this->silvia)->get('/app/rrhh/nomina')->assertOk();
});

// ── Rehacer y congelar ───────────────────────────────────────────────────────

it('se puede rehacer en borrador y reemplaza las líneas viejas', function () {
    /*
     * Llega una incapacidad tarde y hay que rehacer el mes: pasa todos los
     * meses. Si las líneas viejas sobrevivieran, quedaría pagándole a alguien
     * que ya no está en la nómina.
     */
    $e = enNomina();
    $otro = enNomina();

    liquidarMes();
    expect(laNominaDe()->lineas)->toHaveCount(2);

    $otro->update(['fecha_retiro' => '2026-09-30', 'estado' => 'retirado']);

    liquidarMes();

    $n = laNominaDe();
    expect($n->lineas)->toHaveCount(1)
        ->and($n->lineas->first()->empleado_id)->toBe($e->id);
});

it('una nómina aprobada ya no se vuelve a liquidar', function () {
    enNomina();
    liquidarMes();
    $n = laNominaDe();

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");

    liquidarMes()->assertStatus(422);
});

it('no se aprueba una nómina con alguien en cero', function () {
    /*
     * Casi siempre es una ficha sin salario. Aprobarla así es dejar a alguien
     * sin sueldo con la firma de gerencia encima.
     */
    enNomina(['salario' => 2_000_000]);
    enNomina(['salario' => 0, 'nombre' => 'Ficha incompleta']);

    liquidarMes();
    $n = laNominaDe();

    expect($n->enCero()->pluck('nombre'))->toContain('Ficha incompleta');

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar")
        ->assertSessionHasErrors('aprobar');

    expect($n->refresh()->estado)->toBe(Nomina::BORRADOR);
});

it('no arma una nómina de un mes sin nadie vinculado', function () {
    enNomina(['fecha_ingreso' => '2026-12-01']);

    liquidarMes('2026-10')->assertSessionHasErrors('periodo');

    expect(Nomina::where('periodo', '2026-10')->exists())->toBeFalse();
});

// ── El desprendible ──────────────────────────────────────────────────────────

it('el desprendible muestra la cuenta, no sólo el número', function () {
    $e = enNomina(['salario' => 2_000_000]);

    Novedad::create([
        'empleado_id' => $e->id, 'tipo' => TipoNovedad::Ausencia, 'clase' => 'INJUSTIFICADA',
        'fecha_inicio' => '2026-10-05', 'dias' => 2, 'periodo' => '2026-10',
        'descuento' => 133_333.33,
    ]);

    liquidarMes();
    $l = NominaLinea::where('empleado_id', $e->id)->firstOrFail();

    $this->actingAs($this->elena)
        ->get("/app/rrhh/nomina/linea/{$l->id}/desprendible")->assertOk();

    $html = view('rrhh.pdf.desprendible-nomina', [
        'l' => $l, 'nomina' => $l->nomina,
        'empresa' => App\Models\EmpresaConfig::current(), 'generadoPor' => 'Elena',
    ])->render();

    expect($html)
        ->toContain('DESPRENDIBLE DE PAGO')
        ->toContain('$2.000.000,00 ÷ 30 × 30')
        ->toContain('133.333,33')
        // Mientras no esté pagada, el papel lo dice.
        ->toContain('Borrador');
});

it('el desprendible de una nómina pagada ya no dice borrador', function () {
    enNomina();
    liquidarMes();
    $n = laNominaDe();

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");
    $this->actingAs($this->silvia)->post("/app/rrhh/nomina/{$n->id}/pagar", ['fecha_pago' => '2026-10-30']);

    $l = $n->lineas()->first();

    $html = view('rrhh.pdf.desprendible-nomina', [
        'l' => $l, 'nomina' => $n->refresh(),
        'empresa' => App\Models\EmpresaConfig::current(), 'generadoPor' => 'Elena',
    ])->render();

    expect($html)->not->toContain('todavía no está pagada');
});

// ── Totales ──────────────────────────────────────────────────────────────────

it('el total del mes es la suma de las líneas', function () {
    enNomina(['salario' => 2_000_000]);
    enNomina(['salario' => 3_000_000]);

    liquidarMes();
    $n = laNominaDe();

    expect((float) $n->total_devengado)->toBe(round($n->lineas->sum('total_devengado'), 2))
        ->and((float) $n->total_neto)->toBe(round((float) $n->total_devengado - (float) $n->total_deducido, 2));
});
