<?php

/**
 * F11 · Los indicadores que Elena le presenta a la Junta Directiva.
 *
 * Ella los enumeró uno por uno: «la rotación del personal, el ausentismo, el
 * indicador de la cobertura de capacitaciones, el reporte de por qué la gente
 * se retira de la empresa, y cómo estamos creciendo».
 *
 * Lo que estas pruebas cuidan es que los números estén BIEN. Un indicador mal
 * calculado que se lleva a una junta directiva hace tomar decisiones sobre
 * algo que no pasó.
 */

use App\Models\User;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Support\IndicadoresRrhh;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->lider = User::factory()->create();
    $this->lider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));
});

function empleado(array $extra = []): Empleado
{
    static $n = 0;
    $n++;

    return Empleado::create(array_merge([
        'nombre' => "Persona {$n}", 'tipo_documento' => 'CC', 'numero_documento' => "doc{$n}",
        'cargo' => 'Auxiliar de bodega', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 1500000, 'fecha_ingreso' => '2026-01-15',
        'estado' => 'activo', 'estado_induccion' => 'pendiente',
    ], $extra));
}

it('la rotación se mide sobre el promedio mensual, no sobre dos puntos', function () {
    /*
     * 10 personas desde 2025, se van 2 en marzo, entran 10 en febrero.
     *
     * El promedio se toma al cierre de cada mes, no como «(inicio+final)/2»:
     * así la cifra aguanta que la empresa crezca rápido dentro del período.
     */
    for ($i = 0; $i < 10; $i++) {
        empleado(['fecha_ingreso' => '2025-06-01']);
    }
    Empleado::limit(2)->get()->each(fn ($e) => $e->update([
        'estado' => 'retirado', 'fecha_retiro' => '2026-03-10', 'tipo_retiro' => 'renuncia',
    ]));
    for ($i = 0; $i < 10; $i++) {
        empleado(['fecha_ingreso' => '2026-02-01']);
    }

    $r = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->rotacion();

    expect($r['al_inicio'])->toBe(10)
        ->and($r['al_final'])->toBe(18)
        ->and($r['ingresos'])->toBe(10)
        ->and($r['retiros'])->toBe(2)
        // Ene 10, feb 20, mar–dic 18 → (10+20+18×10)/12 = 17,5
        ->and($r['promedio'])->toBe(17.5)
        ->and($r['porcentaje'])->toBe(11.4)
        ->and($r['confiable'])->toBeTrue();
});

it('una plantilla que arranca de cero no dispara la rotación al 200%', function () {
    /*
     * Pasó en la PRIMERA pantalla con datos reales de Great Baby, que empezó
     * a usar el módulo este año: al 1 de enero no había nadie registrado, el
     * promedio de dos puntos daba 1,5 y la rotación salía 200%. Elena se lo
     * habría llevado así a la Junta.
     */
    empleado(['fecha_ingreso' => '2026-01-10']);
    empleado(['fecha_ingreso' => '2026-02-01']);
    empleado(['fecha_ingreso' => '2026-03-15']);
    empleado(['fecha_ingreso' => '2026-01-05', 'estado' => 'retirado', 'fecha_retiro' => '2026-06-30']);
    empleado(['fecha_ingreso' => '2026-04-01', 'estado' => 'retirado', 'fecha_retiro' => '2026-07-15']);
    empleado(['fecha_ingreso' => '2026-02-20', 'estado' => 'retirado', 'fecha_retiro' => '2026-08-10']);

    $r = IndicadoresRrhh::entre('2026-01-01', '2026-10-19')->rotacion();

    expect($r['al_inicio'])->toBe(0)
        ->and($r['retiros'])->toBe(3)
        ->and($r['porcentaje'])->toBeLessThan(100.0);
});

it('avisa cuando hay tan poca gente que el porcentaje no aguanta', function () {
    // Con tres personas, un retiro es un 33%: el número existe pero no
    // soporta que alguien decida sobre él sin mirar el detalle.
    empleado(['fecha_ingreso' => '2025-01-01']);
    empleado(['fecha_ingreso' => '2025-01-01']);
    empleado(['fecha_ingreso' => '2025-01-01', 'estado' => 'retirado', 'fecha_retiro' => '2026-05-01']);

    $r = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->rotacion();

    expect($r['confiable'])->toBeFalse()
        ->and($r['advertencia'])->toContain('un solo');
});

it('sin gente la rotación es cero y no revienta', function () {
    // Dividir por cero daría infinito, y eso es lo que se ve impreso.
    $r = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->rotacion();

    expect($r['porcentaje'])->toBe(0.0)
        ->and($r['promedio'])->toBe(0.0);
});

it('quien se fue antes del período no cuenta como plantilla inicial', function () {
    empleado(['fecha_ingreso' => '2024-01-01', 'estado' => 'retirado', 'fecha_retiro' => '2025-06-30']);
    empleado(['fecha_ingreso' => '2024-01-01']);

    $r = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->rotacion();

    expect($r['al_inicio'])->toBe(1)
        ->and($r['retiros'])->toBe(0);
});

it('el ausentismo se devuelve como NO disponible, con la razón', function () {
    /*
     * Necesita el registro de incapacidades y permisos, que todavía no existe.
     * Un número inventado en una junta directiva es peor que un indicador que
     * falta, porque el inventado se cree y se decide sobre él.
     */
    $a = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->ausentismo();

    expect($a['disponible'])->toBeFalse()
        ->and($a['motivo'])->toContain('incapacidades');
});

it('los motivos de retiro se cuentan y además se listan', function () {
    // En la junta siempre preguntan «¿y quién fue?»: el número solo no basta.
    empleado(['estado' => 'retirado', 'fecha_retiro' => '2026-05-01', 'tipo_retiro' => 'renuncia',
        'motivo_retiro' => 'Consiguió trabajo más cerca de la casa']);
    empleado(['estado' => 'retirado', 'fecha_retiro' => '2026-06-01', 'tipo_retiro' => 'renuncia',
        'motivo_retiro' => 'Se va a estudiar']);
    empleado(['estado' => 'retirado', 'fecha_retiro' => '2026-07-01', 'tipo_retiro' => 'no_supero_prueba',
        'motivo_retiro' => 'No alcanzó el ritmo']);

    $r = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->motivosDeRetiro();

    expect($r['total'])->toBe(3)
        ->and($r['por_tipo'][0]['label'])->toBe('Renuncia voluntaria')
        ->and($r['por_tipo'][0]['cantidad'])->toBe(2)
        ->and($r['detalle'])->toHaveCount(3)
        ->and($r['detalle'][0]['motivo'])->toBe('No alcanzó el ritmo');
});

it('un retiro sin motivo se muestra como tal, no se esconde', function () {
    // Que falten motivos ES el dato: dice que el proceso de egreso no se está
    // registrando bien.
    empleado(['estado' => 'retirado', 'fecha_retiro' => '2026-05-01']);

    $r = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->motivosDeRetiro();

    expect($r['por_tipo'][0]['tipo'])->toBe('sin_registrar')
        ->and($r['por_tipo'][0]['label'])->toBe('Sin motivo registrado');
});

it('la cobertura de inducción sólo mira a los activos', function () {
    // Un retirado con inducción pendiente no es un pendiente: ya no está.
    empleado(['estado_induccion' => 'completada']);
    empleado(['estado_induccion' => 'pendiente']);
    empleado(['estado' => 'retirado', 'fecha_retiro' => '2026-05-01', 'estado_induccion' => 'pendiente']);

    $c = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->coberturaDeInduccion();

    expect($c['activos'])->toBe(2)
        ->and($c['con_induccion'])->toBe(1)
        ->and($c['porcentaje'])->toBe(50.0);
});

it('se llama inducción y no capacitaciones, porque es lo que mide', function () {
    // Elena habló de «cobertura de capacitaciones», pero ella misma dijo que
    // las capacitaciones por cargo todavía no las tiene construidas.
    $c = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->coberturaDeInduccion();

    expect($c['nota'])->toContain('capacitaciones por cargo');
});

it('la estructura agrupa a los activos por área, cargo y contrato', function () {
    empleado(['area' => 'Logística']);
    empleado(['area' => 'Logística']);
    empleado(['area' => 'Contabilidad', 'cargo' => 'Auxiliar contable']);
    empleado(['area' => 'Logística', 'estado' => 'retirado', 'fecha_retiro' => '2026-05-01']);

    $e = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->estructura();

    expect($e['total'])->toBe(3)
        ->and($e['por_area'][0])->toBe(['nombre' => 'Logística', 'cantidad' => 2])
        ->and($e['por_area'][1])->toBe(['nombre' => 'Contabilidad', 'cantidad' => 1]);
});

it('el movimiento mes a mes muestra si se crece o se repone', function () {
    empleado(['fecha_ingreso' => '2026-03-05']);
    empleado(['fecha_ingreso' => '2026-03-20']);
    empleado(['fecha_ingreso' => '2025-01-01', 'estado' => 'retirado', 'fecha_retiro' => '2026-03-15']);

    $m = collect(IndicadoresRrhh::entre('2026-01-01', '2026-04-30')->movimientoPorMes());

    expect($m)->toHaveCount(4)
        ->and($m->firstWhere('mes', '2026-03'))->toMatchArray([
            'ingresos' => 2, 'retiros' => 1, 'saldo' => 1,
        ])
        ->and($m->firstWhere('mes', '2026-01')['saldo'])->toBe(0);
});

it('registrar un retiro lo deja contado y con su porqué', function () {
    $e = empleado();

    $this->actingAs($this->elena)->post("/app/rrhh/empleados/{$e->id}/retiro", [
        'fecha_retiro' => '2026-06-30',
        'tipo_retiro' => 'renuncia',
        'motivo_retiro' => 'Consiguió trabajo más cerca de la casa',
    ])->assertRedirect();

    expect($e->fresh()->estado)->toBe('retirado')
        ->and($e->fresh()->tipo_retiro)->toBe('renuncia')
        ->and($e->fresh()->retirado_por_id)->toBe($this->elena->id)
        ->and($e->bitacora()->first()->descripcion)->toContain('más cerca de la casa');
});

it('el retiro no puede ser anterior al ingreso', function () {
    $e = empleado(['fecha_ingreso' => '2026-05-01']);

    $this->actingAs($this->elena)->post("/app/rrhh/empleados/{$e->id}/retiro", [
        'fecha_retiro' => '2026-01-01', 'tipo_retiro' => 'renuncia', 'motivo_retiro' => 'cualquiera',
    ])->assertSessionHasErrors('fecha_retiro');
});

it('un retiro sin detalle no se guarda', function () {
    // El tipo sirve para contar; el detalle, para entender. En la junta
    // siempre preguntan por el caso concreto.
    $e = empleado();

    $this->actingAs($this->elena)->post("/app/rrhh/empleados/{$e->id}/retiro", [
        'fecha_retiro' => '2026-06-30', 'tipo_retiro' => 'renuncia', 'motivo_retiro' => '',
    ])->assertSessionHasErrors('motivo_retiro');

    expect($e->fresh()->estado)->toBe('activo');
});

it('no se retira dos veces a la misma persona', function () {
    $e = empleado(['estado' => 'retirado', 'fecha_retiro' => '2026-05-01']);

    $this->actingAs($this->elena)->post("/app/rrhh/empleados/{$e->id}/retiro", [
        'fecha_retiro' => '2026-06-30', 'tipo_retiro' => 'renuncia', 'motivo_retiro' => 'otra vez',
    ])->assertStatus(422);
});

it('el tablero se abre y por defecto mira el año corrido', function () {
    // La rotación de un solo mes no dice nada: con 20 personas, un retiro es
    // un 5% que parece una catástrofe.
    $this->actingAs($this->elena)->get('/app/rrhh/indicadores')
        ->assertInertia(fn ($p) => $p
            ->where('indicadores.rango.desde', now()->startOfYear()->toDateString())
            ->where('indicadores.rango.hasta', now()->toDateString())
            ->has('indicadores.rotacion')
            ->where('indicadores.ausentismo.disponible', false));
});

it('los indicadores salen en PDF para llevarlos impresos', function () {
    empleado(['estado' => 'retirado', 'fecha_retiro' => '2026-05-01', 'tipo_retiro' => 'renuncia',
        'motivo_retiro' => 'Se fue a estudiar']);

    $r = $this->actingAs($this->elena)->get('/app/rrhh/indicadores/pdf?desde=2026-01-01&hasta=2026-12-31');

    $r->assertOk();
    expect(substr($r->getContent(), 0, 4))->toBe('%PDF');
});

it('el líder de área no ve los números de toda la empresa', function () {
    $this->actingAs($this->lider)->get('/app/rrhh/indicadores')->assertStatus(403);
    $this->actingAs($this->lider)->get('/app/rrhh/indicadores/pdf')->assertStatus(403);
});
