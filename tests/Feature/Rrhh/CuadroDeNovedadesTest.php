<?php

/**
 * Fase B · El cuadro de novedades que Gestión Humana le manda al contador.
 *
 * Lo que estas pruebas cuidan son los NÚMEROS. De este cuadro sale la nómina:
 * un descuento mal calculado es plata de menos en el sueldo de alguien, y el
 * ausentismo que sale de acá se lleva a la Junta Directiva.
 *
 * Los valores con los que se comparan no son inventados: salen del Excel real
 * de octubre de 2026 que mandó el área. Quince días de incapacidad
 * descontaron 124.547,50; dos días de inasistencia sin justificación a quien
 * gana 2.000.000 descontaron 133.333,33. Si alguna de las dos cuentas cambia,
 * estas pruebas se ponen rojas antes de que el contador lo note.
 */

use App\Models\User;
use App\Modules\Rrhh\Enums\TipoNovedad;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Nomina;
use App\Modules\Rrhh\Models\Novedad;
use App\Modules\Rrhh\Support\CuadroDeNovedades;
use App\Modules\Rrhh\Support\IndicadoresRrhh;
use App\Support\Reglas;
use Database\Seeders\PermisosSeeder;
use Database\Seeders\RolesGestionHumanaSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(PermisosSeeder::class);
    $this->seed(RolesGestionHumanaSeeder::class);
    Reglas::limpiarRequestCache();

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->lider = User::factory()->create();
    $this->lider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));
});

function trabajador(array $extra = []): Empleado
{
    static $n = 0;
    $n++;

    return Empleado::create(array_merge([
        'nombre' => "Trabajador {$n}", 'tipo_documento' => 'CC', 'numero_documento' => "nov{$n}",
        'cargo' => 'Auxiliar', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 2_000_000, 'fecha_ingreso' => '2026-01-15',
        'estado' => 'activo', 'estado_induccion' => 'pendiente',
    ], $extra));
}

function novedad(Empleado $e, array $extra = []): Novedad
{
    $n = new Novedad(array_merge([
        'empleado_id' => $e->id,
        'tipo' => TipoNovedad::Ausencia->value,
        'clase' => 'SIN_JUSTIFICACION',
        'fecha_inicio' => '2026-10-05',
        'fecha_fin' => '2026-10-06',
        'dias' => 2,
        'remunerada' => false,
        'periodo' => '2026-10',
    ], $extra));

    $n->setRelation('empleado', $e);
    $n->recalcularDescuento();
    $n->save();

    return $n->fresh();
}

// ── El descuento ─────────────────────────────────────────────────────────────

it('una incapacidad descuenta el auxilio de transporte proporcional', function () {
    /*
     * El dato real: 15 días descontaron 124.547,50, que es el auxilio de
     * 249.095 dividido en 30 días por 15. No se descuenta el salario: a quien
     * está incapacitado la EPS le paga, lo que pierde es el auxilio porque no
     * se transporta.
     */
    $e = trabajador(['salario' => 2_000_000]);

    $n = novedad($e, [
        'tipo' => TipoNovedad::Incapacidad->value, 'clase' => 'MATERNIDAD',
        'fecha_inicio' => '2026-09-24', 'fecha_fin' => '2026-10-08', 'dias' => 15,
        'remunerada' => true,
    ]);

    expect(round((float) $n->descuento, 2))->toBe(124547.50);
});

it('una ausencia sin justificación descuenta el día de salario entero', function () {
    // 2 días contra 2.000.000 → 2.000.000 ÷ 30 × 2 = 133.333,33
    $n = novedad(trabajador(['salario' => 2_000_000]), ['dias' => 2]);

    expect(round((float) $n->descuento, 2))->toBe(133333.33);
});

it('quien gana más de dos mínimos no pierde auxilio al incapacitarse', function () {
    /*
     * El auxilio de transporte sólo lo devenga quien gana hasta dos salarios
     * mínimos. Descontárselo a quien gana más sería quitarle plata que nunca
     * recibió.
     */
    $e = trabajador(['salario' => 9_000_000]);

    $n = novedad($e, [
        'tipo' => TipoNovedad::Incapacidad->value, 'clase' => 'EG', 'dias' => 10, 'remunerada' => true,
    ]);

    expect((float) $n->descuento)->toBe(0.0)
        // Desde que el auxilio también se puede excluir por ficha, el aviso
        // dice CUÁL de los dos motivos es, no uno genérico.
        ->and($n->descuentoSugerido()['formula'])
        ->toContain('No devenga auxilio de transporte')
        ->toContain('dos salarios mínimos');
});

it('un permiso remunerado no descuenta nada', function () {
    $n = novedad(trabajador(), [
        'tipo' => TipoNovedad::Permiso->value, 'clase' => 'CITA_MEDICA',
        'dias' => null, 'horas' => 2, 'remunerada' => true,
    ]);

    expect((float) $n->descuento)->toBe(0.0);
});

it('las horas extra nunca descuentan: suman', function () {
    $n = novedad(trabajador(), [
        'tipo' => TipoNovedad::HorasExtra->value, 'clase' => 'ORDINARIA',
        'dias' => null, 'horas' => 12,
    ]);

    expect((float) $n->descuento)->toBe(0.0);
});

it('sin salario en la ficha no se inventa un descuento, se dice por qué', function () {
    $n = novedad(trabajador(['salario' => null]), ['dias' => 3]);

    expect((float) $n->descuento)->toBe(0.0)
        ->and($n->descuentoSugerido()['formula'])->toContain('no tiene salario registrado');
});

it('un descuento corregido a mano no se pisa al recalcular', function () {
    /*
     * Ésta es la mitad que importa. Gestión Humana corrige un descuento por
     * una razón que el sistema no conoce —un acuerdo, un error de la EPS—. Si
     * recalcular lo pisara, la corrección desaparecería en silencio y nadie
     * notaría nada hasta que al trabajador le llegue mal el sueldo.
     */
    $n = novedad(trabajador(), ['dias' => 2]);

    $n->descuento = 50_000;
    $n->descuento_manual = true;
    $n->save();

    $n->fresh()->recalcularDescuento();

    expect((float) $n->fresh()->descuento)->toBe(50000.00);
});

it('la fórmula del descuento se puede leer, no hay que creerle al sistema', function () {
    $n = novedad(trabajador(['salario' => 2_000_000]), ['dias' => 2]);

    expect($n->descuentoSugerido()['formula'])
        ->toContain('$2.000.000')
        ->toContain('÷ 30 días')
        ->toContain('$133.333');
});

// ── Días equivalentes ────────────────────────────────────────────────────────

it('un permiso de dos horas vale 0,25 días, no uno', function () {
    /*
     * Contar cada cita médica como día completo duplicaba el ausentismo. Con
     * dos permisos de dos horas el indicador decía «2 días perdidos» cuando
     * fueron medio día.
     */
    $n = novedad(trabajador(), [
        'tipo' => TipoNovedad::Permiso->value, 'clase' => 'CITA_MEDICA',
        'dias' => null, 'horas' => 2,
    ]);

    expect($n->diasEquivalentes())->toBe(0.25);
});

it('sin días ni horas se cuentan las fechas, contando los dos extremos', function () {
    /*
     * En producción había dos incapacidades con la casilla de días en blanco.
     * El indicador de la Junta decía «Enfermedad general: 0 días» con ocho días
     * de incapacidad cargados, porque cero y «no la llenaron» eran el mismo
     * número. Del 5 al 9 son CINCO días: también se falta el día que empieza.
     */
    $n = novedad(trabajador(), [
        'tipo' => TipoNovedad::Incapacidad->value, 'clase' => 'EG',
        'fecha_inicio' => '2026-10-05', 'fecha_fin' => '2026-10-09',
        'dias' => null, 'horas' => null, 'remunerada' => true,
    ]);

    expect($n->diasEquivalentes())->toBe(5.0);
});

it('una novedad de un solo día no cuenta cero', function () {
    $n = novedad(trabajador(), [
        'fecha_inicio' => '2026-10-22', 'fecha_fin' => '2026-10-22',
        'dias' => null, 'horas' => null,
    ]);

    expect($n->diasEquivalentes())->toBe(1.0);
});

it('lo que se teclea manda sobre lo que dicen las fechas', function () {
    /*
     * Una incapacidad de quince días calendario puede descontar menos si parte
     * de ellos caen en el corte anterior. Ese criterio es de Gestión Humana y
     * se escribe a mano: deducirlo otra vez de las fechas lo pisaría.
     */
    $n = novedad(trabajador(), [
        'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-30', 'dias' => 3,
    ]);

    expect($n->diasEquivalentes())->toBe(3.0);
});

it('la pantalla deduce los días y el descuento sale completo', function () {
    // Sin esto la novedad entraba con descuento cero y nadie lo notaba.
    $e = trabajador(['salario' => 2_000_000]);

    $this->actingAs($this->elena)->post('/app/rrhh/novedades', [
        'empleado_id' => $e->id,
        'tipo' => 'ausencia', 'clase' => 'SIN_JUSTIFICACION',
        'fecha_inicio' => '2026-10-05', 'fecha_fin' => '2026-10-06',
        'remunerada' => false, 'periodo' => '2026-10',
    ])->assertRedirect();

    $n = Novedad::first();

    expect((float) $n->dias)->toBe(2.0)
        ->and(round((float) $n->descuento, 2))->toBe(133333.33);
});

it('una novedad sin forma de medir cuánto duró se rechaza', function () {
    /*
     * Un día sin fecha de terminación, sin días y sin horas no se puede medir,
     * y lo que no se mide pesa cero en el descuento y en el ausentismo. Se pide
     * en vez de inventarlo.
     */
    $e = trabajador();

    $this->actingAs($this->elena)->post('/app/rrhh/novedades', [
        'empleado_id' => $e->id,
        'tipo' => 'ausencia', 'clase' => 'SIN_JUSTIFICACION',
        'fecha_inicio' => '2026-10-05', 'periodo' => '2026-10',
    ])->assertSessionHasErrors('dias');

    expect(Novedad::count())->toBe(0);
});

// ── El corte cerrado ─────────────────────────────────────────────────────────

it('no se le agregan novedades a un mes cuya nómina ya se pagó', function () {
    /*
     * Pasó en producción: la nómina de octubre estaba liquidada, aprobada y
     * pagada, y el cuadro aceptó una incapacidad de ese mismo corte sin decir
     * nada. El contador queda con un soporte que no cuadra con lo que salió del
     * banco, y nadie se entera hasta que alguien compara los dos papeles.
     */
    $e = trabajador();
    Nomina::create(['periodo' => '2026-10', 'estado' => 'pagada']);

    $this->actingAs($this->elena)->post('/app/rrhh/novedades', [
        'empleado_id' => $e->id,
        'tipo' => 'incapacidad', 'clase' => 'EG',
        'fecha_inicio' => '2026-10-26', 'fecha_fin' => '2026-10-28',
        'periodo' => '2026-10',
    ])->assertSessionHasErrors('periodo');

    expect(Novedad::count())->toBe(0);
});

it('con la nómina en borrador sí se puede: la incapacidad que llega tarde se rehace', function () {
    // El mes se cierra al aprobar, no al liquidar: `Nomina::sePuedeRehacer()`.
    $e = trabajador();
    Nomina::create(['periodo' => '2026-10', 'estado' => 'borrador']);

    $this->actingAs($this->elena)->post('/app/rrhh/novedades', [
        'empleado_id' => $e->id,
        'tipo' => 'incapacidad', 'clase' => 'EG',
        'fecha_inicio' => '2026-10-26', 'fecha_fin' => '2026-10-28',
        'periodo' => '2026-10',
    ])->assertRedirect();

    expect(Novedad::count())->toBe(1);
});

it('tampoco se saca una novedad de un corte ya pagado cambiándole el mes', function () {
    /*
     * Mover la novedad a noviembre deja el cuadro de octubre tan desalineado
     * como meterle una: es la forma fácil de hacerlo sin darse cuenta, porque
     * basta cambiar el desplegable del corte.
     */
    $n = novedad(trabajador());
    Nomina::create(['periodo' => '2026-10', 'estado' => 'aprobada']);

    $this->actingAs($this->elena)->put("/app/rrhh/novedades/{$n->id}", [
        'empleado_id' => $n->empleado_id,
        'tipo' => 'ausencia', 'clase' => 'SIN_JUSTIFICACION',
        'fecha_inicio' => '2026-10-05', 'fecha_fin' => '2026-10-06',
        'dias' => 2, 'periodo' => '2026-11',
    ])->assertSessionHasErrors('periodo');

    expect($n->fresh()->periodo)->toBe('2026-10');
});

it('cuando el descuento guardado no cuadra con la cuenta, el cuadro lo dice', function () {
    /*
     * Las novedades que se guardaron sin días quedaron con descuento cero. Al
     * deducir los días, la cuenta pasa a dar otra cosa — y la pantalla mostraba
     * el cero arriba y la cuenta nueva abajo, sin decir cuál creer. No se
     * corrige solo: si el corte ya se pagó, el cero es lo que se consignó.
     */
    $e = trabajador(['salario' => 2_000_000]);

    $n = novedad($e, ['dias' => 2, 'remunerada' => false]);
    $n->forceFill(['descuento' => 0, 'descuento_manual' => false])->save();

    $props = $this->actingAs($this->elena)->get('/app/rrhh/novedades?periodo=2026-10')
        ->assertOk()->viewData('page')['props'];

    $fila = collect($props['bloques']['ausencia'])->firstWhere('id', $n->id);

    expect($fila['descuento_formula'])->toContain('No coincide con lo registrado');
});

it('un descuento corregido a mano no lleva el aviso: ya tiene el suyo', function () {
    $e = trabajador(['salario' => 2_000_000]);

    $n = novedad($e, ['dias' => 2, 'remunerada' => false]);
    $n->forceFill(['descuento' => 100_000, 'descuento_manual' => true])->save();

    $props = $this->actingAs($this->elena)->get('/app/rrhh/novedades?periodo=2026-10')
        ->assertOk()->viewData('page')['props'];

    $fila = collect($props['bloques']['ausencia'])->firstWhere('id', $n->id);

    expect($fila['descuento_formula'])->not->toContain('No coincide');
});

it('una novedad de un corte pagado tampoco se borra', function () {
    $n = novedad(trabajador());
    Nomina::create(['periodo' => '2026-10', 'estado' => 'pagada']);

    $this->actingAs($this->elena)
        ->delete("/app/rrhh/novedades/{$n->id}")
        ->assertSessionHasErrors('periodo');

    expect(Novedad::count())->toBe(1);
});

// ── El cuadro ────────────────────────────────────────────────────────────────

it('los ingresos y los retiros se derivan de la ficha, no se registran aparte', function () {
    /*
     * Es la decisión de diseño del módulo. Si el ingreso fuera además una fila
     * de novedad, corregir la fecha en la ficha dejaría la novedad vieja y el
     * cuadro contradiría al indicador de rotación.
     */
    trabajador(['fecha_ingreso' => '2026-10-03']);
    trabajador(['fecha_ingreso' => '2026-09-01', 'fecha_retiro' => '2026-10-20',
        'estado' => 'retirado', 'tipo_retiro' => 'renuncia']);
    trabajador(['fecha_ingreso' => '2026-08-01']);   // ni entró ni salió en octubre

    $cuadro = CuadroDeNovedades::de('2026-10');

    expect($cuadro->ingresos())->toHaveCount(1)
        ->and($cuadro->retiros())->toHaveCount(1)
        ->and(Novedad::count())->toBe(0);
});

it('el corte lo decide quien registra, no la fecha de la novedad', function () {
    /*
     * Una incapacidad que arranca el 29 de octubre puede reportarse en el
     * corte de noviembre porque llegó tarde. Por eso `periodo` es un campo y
     * no un `MONTH(fecha_inicio)`.
     */
    novedad(trabajador(), ['fecha_inicio' => '2026-10-29', 'periodo' => '2026-11']);

    expect(CuadroDeNovedades::de('2026-10')->novedadesDe(TipoNovedad::Ausencia))->toHaveCount(0)
        ->and(CuadroDeNovedades::de('2026-11')->novedadesDe(TipoNovedad::Ausencia))->toHaveCount(1);
});

it('el resumen avisa cuántas fichas están incompletas', function () {
    // Es lo que hace que el Excel le llegue al contador con celdas vacías.
    trabajador(['fecha_ingreso' => '2026-10-02']);

    expect(CuadroDeNovedades::de('2026-10')->resumen()['fichas_incompletas'])->toBe(1);
});

it('una ficha completa no aparece como incompleta', function () {
    trabajador([
        'fecha_ingreso' => '2026-10-02', 'fecha_nacimiento' => '1995-04-10',
        'grupo_sanguineo' => 'O+', 'direccion' => 'Calle 17 # 24-31', 'telefono' => '3017445363',
        'empresa_afiliadora' => 'MARTINAS', 'cuenta_bancaria' => '91252557488',
        'eps' => 'NUEVA EPS', 'pension' => 'PORVENIR', 'cesantias' => 'NA', 'arl_nivel_riesgo' => 'I',
    ]);

    expect(CuadroDeNovedades::de('2026-10')->resumen()['fichas_incompletas'])->toBe(0);
});

// ── Ausentismo ───────────────────────────────────────────────────────────────

it('sin novedades registradas el ausentismo sigue diciendo «no disponible»', function () {
    /*
     * Un 0,0 % y un «nadie ha registrado nada» se ven igual en una
     * diapositiva y significan lo contrario. El módulo prefiere no mostrar el
     * número antes que mostrar uno que se va a creer.
     */
    trabajador();

    $a = IndicadoresRrhh::entre('2026-10-01', '2026-10-31')->ausentismo();

    expect($a['disponible'])->toBeFalse();
});

it('con novedades el ausentismo ya sale: era lo que faltaba para la Junta', function () {
    /*
     * Dos personas, 30 días cada una = 60 días disponibles. Una falta 3 días.
     * 3 / 60 = 5 %.
     */
    $a1 = trabajador(['fecha_ingreso' => '2026-01-01']);
    trabajador(['fecha_ingreso' => '2026-01-01']);

    novedad($a1, ['dias' => 3, 'fecha_fin' => '2026-10-07']);

    $a = IndicadoresRrhh::entre('2026-10-01', '2026-10-31')->ausentismo();

    expect($a['disponible'])->toBeTrue()
        ->and($a['dias_perdidos'])->toBe(3.0)
        ->and($a['dias_disponibles'])->toBe(60.0)
        ->and($a['porcentaje'])->toBe(5.0);
});

it('las horas extra no cuentan como ausentismo', function () {
    $e = trabajador(['fecha_ingreso' => '2026-01-01']);

    novedad($e, ['tipo' => TipoNovedad::HorasExtra->value, 'clase' => 'ORDINARIA',
        'dias' => null, 'horas' => 20]);

    $a = IndicadoresRrhh::entre('2026-10-01', '2026-10-31')->ausentismo();

    expect($a['disponible'])->toBeTrue()
        ->and($a['dias_perdidos'])->toBe(0.0);
});

it('el ausentismo dice por qué falta la gente, no sólo cuánto', function () {
    $e = trabajador(['fecha_ingreso' => '2026-01-01']);

    novedad($e, ['tipo' => TipoNovedad::Incapacidad->value, 'clase' => 'EG', 'dias' => 2, 'remunerada' => true]);
    novedad($e, ['dias' => 1]);

    $a = IndicadoresRrhh::entre('2026-10-01', '2026-10-31')->ausentismo();

    expect($a['por_clase'])->toHaveKey('Enfermedad general')
        ->and($a['por_clase'])->toHaveKey('Inasistencia sin justificación')
        ->and($a['por_clase']['Enfermedad general'])->toBe(2.0);
});

// ── La pantalla ──────────────────────────────────────────────────────────────

it('Gestión Humana abre el cuadro', function () {
    $this->actingAs($this->elena)
        ->get('/app/rrhh/novedades?periodo=2026-10')
        ->assertOk();
});

it('un líder de área no entra: acá se ven salarios y descuentos de toda la empresa', function () {
    $this->actingAs($this->lider)->get('/app/rrhh/novedades')->assertForbidden();
});

it('se registra una novedad desde la pantalla y el descuento sale solo', function () {
    $e = trabajador(['salario' => 2_000_000]);

    $this->actingAs($this->elena)->post('/app/rrhh/novedades', [
        'empleado_id' => $e->id,
        'tipo' => 'ausencia', 'clase' => 'SIN_JUSTIFICACION',
        'fecha_inicio' => '2026-10-05', 'fecha_fin' => '2026-10-06',
        'dias' => 2, 'remunerada' => false, 'periodo' => '2026-10',
    ])->assertRedirect();

    expect(round((float) Novedad::first()->descuento, 2))->toBe(133333.33);
});

it('una clase que no pertenece al tipo se rechaza', function () {
    /*
     * «CITA_MEDICA» dentro de una incapacidad rompería el agrupado del
     * ausentismo sin avisar: la clase existe, pero en otro bloque.
     */
    $e = trabajador();

    $this->actingAs($this->elena)->post('/app/rrhh/novedades', [
        'empleado_id' => $e->id,
        'tipo' => 'incapacidad', 'clase' => 'CITA_MEDICA',
        'fecha_inicio' => '2026-10-05', 'dias' => 1, 'periodo' => '2026-10',
    ])->assertStatus(422);

    expect(Novedad::count())->toBe(0);
});

it('una novedad que termina antes de empezar se rechaza', function () {
    $e = trabajador();

    $this->actingAs($this->elena)->post('/app/rrhh/novedades', [
        'empleado_id' => $e->id,
        'tipo' => 'ausencia', 'clase' => 'SIN_JUSTIFICACION',
        'fecha_inicio' => '2026-10-10', 'fecha_fin' => '2026-10-05',
        'dias' => 2, 'periodo' => '2026-10',
    ])->assertSessionHasErrors('fecha_fin');
});

it('escribir un descuento distinto lo marca como corregido a mano', function () {
    $e = trabajador(['salario' => 2_000_000]);

    $this->actingAs($this->elena)->post('/app/rrhh/novedades', [
        'empleado_id' => $e->id,
        'tipo' => 'ausencia', 'clase' => 'SIN_JUSTIFICACION',
        'fecha_inicio' => '2026-10-05', 'dias' => 2, 'remunerada' => false,
        'periodo' => '2026-10', 'descuento' => 100000,
    ])->assertRedirect();

    $n = Novedad::first();

    expect((float) $n->descuento)->toBe(100000.00)
        ->and($n->descuento_manual)->toBeTrue();
});

it('registrar una novedad queda escrito en la bitácora de la persona', function () {
    $e = trabajador();

    $this->actingAs($this->elena)->post('/app/rrhh/novedades', [
        'empleado_id' => $e->id,
        'tipo' => 'permiso', 'clase' => 'CITA_MEDICA',
        'fecha_inicio' => '2026-10-05', 'horas' => 2, 'periodo' => '2026-10',
    ])->assertRedirect();

    expect($e->fresh()->bitacora()->where('evento', 'novedad')->exists())->toBeTrue();
});

it('el export trae los seis bloques del Excel que recibe el contador', function () {
    $e = trabajador(['fecha_ingreso' => '2026-10-02']);
    novedad($e, ['dias' => 2]);

    $r = $this->actingAs($this->elena)->get('/app/rrhh/novedades/exportar?periodo=2026-10');
    $r->assertOk();

    $csv = $r->streamedContent();

    expect($csv)->toContain('INGRESOS')
        ->toContain('RETIROS')
        ->toContain('INCAPACIDADES Y LICENCIAS')
        ->toContain('HORAS EXTRA')
        ->toContain('PERMISOS')
        ->toContain('AUSENCIAS')
        // El encabezado tal cual lo trae el formato del área.
        ->toContain('GRUPO SANGUÍNEO Y RH')
        ->toContain('NIVEL DE RIESGO EN ARL');
});

// ── Fase A · la ficha laboral ────────────────────────────────────────────────

it('guardar la ficha no borra lo que no se editó', function () {
    /*
     * «Lo que ves es lo que hay». Si el formulario manda sólo tres campos, los
     * otros no se tocan. Sin esto, abrir la ficha para corregir un teléfono
     * borraría la EPS, el banco y el nivel de riesgo.
     */
    $e = trabajador(['eps' => 'SURA', 'banco' => 'BANCOLOMBIA', 'arl_nivel_riesgo' => 'I']);

    $this->actingAs($this->elena)
        ->put("/app/rrhh/empleados/{$e->id}/ficha", ['telefono' => '3001234567'])
        ->assertRedirect();

    $e = $e->fresh();

    expect($e->telefono)->toBe('3001234567')
        ->and($e->eps)->toBe('SURA')
        ->and($e->banco)->toBe('BANCOLOMBIA')
        ->and($e->arl_nivel_riesgo)->toBe('I');
});

it('un campo que se manda vacío sí se limpia', function () {
    // La contracara: borrar a propósito tiene que funcionar.
    $e = trabajador(['eps' => 'SURA']);

    $this->actingAs($this->elena)
        ->put("/app/rrhh/empleados/{$e->id}/ficha", ['eps' => ''])
        ->assertRedirect();

    expect($e->fresh()->eps)->toBeNull();
});

it('la ficha avisa qué falta para el cuadro', function () {
    $e = trabajador();

    $this->actingAs($this->elena)
        ->put("/app/rrhh/empleados/{$e->id}/ficha", ['grupo_sanguineo' => 'O+'])
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'Todavía falta'));
});

it('un nivel de riesgo inventado se rechaza', function () {
    // I a V los fija el decreto 1072; de ahí sale el aporte a la ARL.
    $e = trabajador();

    $this->actingAs($this->elena)
        ->put("/app/rrhh/empleados/{$e->id}/ficha", ['arl_nivel_riesgo' => 'VIII'])
        ->assertSessionHasErrors('arl_nivel_riesgo');
});

it('un líder de área no edita fichas: ahí están los salarios', function () {
    $e = trabajador();

    $this->actingAs($this->lider)
        ->put("/app/rrhh/empleados/{$e->id}/ficha", ['telefono' => '300'])
        ->assertForbidden();
});

it('quien gana hasta dos mínimos devenga auxilio de transporte', function () {
    expect(trabajador(['salario' => 2_000_000])->devengaAuxilioTransporte())->toBeTrue()
        ->and(trabajador(['salario' => 9_000_000])->devengaAuxilioTransporte())->toBeFalse();
});
