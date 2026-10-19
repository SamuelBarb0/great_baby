<?php

/**
 * F5 · Los dos controles de la reunión que el módulo se estaba saltando.
 *
 *   1 · «Solo ingresa la empresa realmente a un contrato si viene autorizado
 *        por la junta directiva. No es el líder el que toma la decisión final
 *        de la contratación.»
 *
 *   2 · «Ese período de prueba debe estar validado por el jefe del área y por
 *        gestión humana.»
 *
 * Los dos son separaciones de poder: si una sola persona pudiera hacer las dos
 * mitades, el control no existiría.
 */

use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Rrhh\Enums\EstadoRequisicion;
use App\Modules\Rrhh\Enums\EtapaCandidato;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\Contratacion;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\OrdenContratacion;
use App\Modules\Rrhh\Models\Requisicion;
use App\Modules\Rrhh\Models\Vacante;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->lider = User::factory()->create(['name' => 'Jorge Rojas']);
    $this->lider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    /*
     * La Junta Directiva. Es el rol `Gerente`, que es quien tiene el permiso.
     *
     * Hay que repartirle sus permisos a mano: `PermisosSeeder` los reparte
     * entre los roles QUE YA EXISTEN, y con `RefreshDatabase` la tabla de
     * roles nace vacía, así que el rol se crea después y sin nada. En la base
     * de verdad el rol ya está y sí los recibe.
     */
    $rolGerente = Role::firstOrCreate(['name' => 'Gerente', 'guard_name' => 'web']);
    $rolGerente->syncPermissions(collect(App\Auth\Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array('Gerente', $roles, true))
        ->keys()->map(fn ($s) => App\Auth\Permisos::permiso($s))->all());

    $this->junta = User::factory()->create(['name' => 'Junta Directiva']);
    $this->junta->assignRole($rolGerente);

    $this->requisicion = Requisicion::create([
        'numero' => Requisicion::siguienteNumero(),
        'cargo' => 'Auxiliar de bodega',
        'area' => 'Logística',
        'numero_vacantes' => 1,
        'salario_min' => 1500000,
        'solicitante_id' => $this->lider->id,
        'estado' => EstadoRequisicion::Aprobada->value,
    ]);

    $this->vacante = Vacante::create([
        'requisicion_id' => $this->requisicion->id,
        'titulo' => 'Auxiliar de bodega',
        'area' => 'Logística',
        'tipo_contrato' => 'indefinido',
        'estado' => 'abierta',
    ]);

    $this->elegida = Candidato::create([
        'vacante_id' => $this->vacante->id,
        'nombre' => 'Ana Pérez',
        'documento' => '1098765432',
        'etapa' => EtapaCandidato::Seleccionado->value,
        'antecedentes_revisados' => true,
        'elegido_at' => now(),
        'elegido_por_id' => $this->lider->id,
    ]);
});

// ───────────────────── 1 · La orden de la Junta ─────────────────────

it('sin la orden de la Junta no se abre la contratación', function () {
    /*
     * Éste era el control que faltaba: que el jefe eligiera de la terna
     * bastaba para arrancar a contratar.
     */
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/contratar")
        ->assertSessionHasErrors('orden');

    expect(Contratacion::count())->toBe(0);
});

it('Gestión Humana pide la orden y la Junta se entera', function () {
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/orden")
        ->assertRedirect();

    $o = OrdenContratacion::first();

    expect($o->numero)->toBe('ODC-'.now()->year.'-0001')
        ->and($o->estado)->toBe('pendiente')
        ->and($o->cargo)->toBe('Auxiliar de bodega')
        // Lo propuesto sale de la requisición: nadie lo vuelve a teclear.
        ->and((float) $o->salario_propuesto)->toBe(1500000.0)
        ->and(NotificacionErp::where('user_id', $this->junta->id)->where('tipo', 'rrhh_orden')->exists())
            ->toBeTrue();
});

it('el número de la orden no se confunde con el de una orden de compra', function () {
    // `OC-2026-000001` ya es una orden de COMPRA en este mismo sistema, y dos
    // documentos con el mismo prefijo se confunden en cuanto alguien dice
    // «la OC».
    expect(OrdenContratacion::siguienteNumero())->toStartWith('ODC-');
});

it('Gestión Humana no se autoriza a sí misma', function () {
    // Si pudiera, el control no existiría.
    $o = OrdenContratacion::create([
        'numero' => OrdenContratacion::siguienteNumero(),
        'candidato_id' => $this->elegida->id,
        'cargo' => 'Auxiliar de bodega',
        'salario_propuesto' => 1500000,
        'estado' => 'pendiente',
    ]);

    $this->actingAs($this->elena)->post("/app/rrhh/ordenes/{$o->id}/autorizar")->assertStatus(403);

    expect($o->fresh()->estado)->toBe('pendiente');
});

it('el líder que pidió el personal tampoco autoriza', function () {
    $o = OrdenContratacion::create([
        'numero' => OrdenContratacion::siguienteNumero(),
        'candidato_id' => $this->elegida->id,
        'cargo' => 'Auxiliar de bodega',
        'estado' => 'pendiente',
    ]);

    $this->actingAs($this->lider)->post("/app/rrhh/ordenes/{$o->id}/autorizar")->assertStatus(403);
});

it('con la orden autorizada ya se abre la contratación', function () {
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/orden");
    $o = OrdenContratacion::first();

    $this->actingAs($this->junta)->post("/app/rrhh/ordenes/{$o->id}/autorizar");
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/contratar");

    $x = Contratacion::first();

    expect($o->fresh()->estado)->toBe('autorizada')
        ->and($o->fresh()->decidida_por_id)->toBe($this->junta->id)
        ->and($x)->not->toBeNull()
        ->and($x->orden_id)->toBe($o->id)
        // El salario sale de lo AUTORIZADO, no de lo que pidió el área.
        ->and((float) $x->salario)->toBe(1500000.0);
});

it('la Junta puede autorizar por menos de lo que se pidió', function () {
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/orden");
    $o = OrdenContratacion::first();

    $this->actingAs($this->junta)->post("/app/rrhh/ordenes/{$o->id}/autorizar", [
        'salario_autorizado' => 1300000,
    ]);
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/contratar");

    expect((float) Contratacion::first()->salario)->toBe(1300000.0);
});

it('no se firma por más de lo que la Junta autorizó', function () {
    /*
     * Congelar el salario en la orden no sirve de nada si después se puede
     * acordar otro: la autorización es sobre el gasto.
     */
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/orden");
    $o = OrdenContratacion::first();
    $this->actingAs($this->junta)->post("/app/rrhh/ordenes/{$o->id}/autorizar", ['salario_autorizado' => 1500000]);
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/contratar");

    $x = Contratacion::first();

    $this->actingAs($this->elena)->post("/app/rrhh/contrataciones/{$x->id}/acuerdo", [
        'salario' => 2500000,
        'tipo_contrato' => 'indefinido',
        'fecha_ingreso' => now()->addWeek()->toDateString(),
    ])->assertSessionHasErrors('salario');

    expect((float) $x->fresh()->salario)->toBe(1500000.0);
});

it('negar la orden exige motivo y lo devuelve a Gestión Humana', function () {
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/orden");
    $o = OrdenContratacion::first();

    $this->actingAs($this->junta)->post("/app/rrhh/ordenes/{$o->id}/negar", ['motivo' => 'no'])
        ->assertSessionHasErrors('motivo');

    $this->actingAs($this->junta)->post("/app/rrhh/ordenes/{$o->id}/negar", [
        'motivo' => 'El presupuesto del trimestre no da para un cargo más',
    ]);

    expect($o->fresh()->estado)->toBe('negada')
        ->and(NotificacionErp::where('user_id', $this->elena->id)->where('titulo', 'like', '%NO autorizada%')->exists())
            ->toBeTrue();

    // Y con la orden negada, la contratación sigue sin poder abrirse.
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/contratar")
        ->assertSessionHasErrors('orden');
});

it('pedir la orden dos veces no deja dos órdenes', function () {
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/orden");
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/orden");

    expect(OrdenContratacion::count())->toBe(1);
});

// ───────────────── 2 · El doble concepto del período de prueba ─────────────────

function contratada(array $extra = []): Empleado
{
    return Empleado::create(array_merge([
        'vacante_id' => test()->vacante->id,
        'candidato_id' => test()->elegida->id,
        'nombre' => 'Ana Pérez', 'tipo_documento' => 'CC', 'numero_documento' => '1098765432',
        'cargo' => 'Auxiliar de bodega', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 1500000, 'fecha_ingreso' => now()->subMonth()->toDateString(),
        'estado' => 'activo', 'estado_induccion' => 'completada',
        'prueba_hasta' => now()->addDays(5)->toDateString(),
    ], $extra));
}

it('Gestión Humana no cierra la prueba sin el concepto del jefe', function () {
    // «Debe estar validado por el jefe del área Y por gestión humana».
    $e = contratada();

    $this->actingAs($this->elena)
        ->post("/app/rrhh/empleados/{$e->id}/prueba/evaluar", ['resultado' => 'continua'])
        ->assertSessionHasErrors('resultado');

    expect($e->fresh()->prueba_evaluada_at)->toBeNull();
});

it('el jefe da su concepto desde su propia pantalla', function () {
    // Sin entrar a Gestión Humana, donde están los salarios de la empresa.
    $e = contratada();

    $this->actingAs($this->lider)->get('/app/rrhh/mi-equipo')
        ->assertInertia(fn ($p) => $p
            ->has('equipo', 1)
            ->where('equipo.0.nombre', 'Ana Pérez')
            ->where('equipo.0.debo_conceptuar', true)
            // Lo que NO ve: el salario.
            ->missing('equipo.0.salario'));

    $this->actingAs($this->lider)->post("/app/rrhh/mi-equipo/{$e->id}/concepto", [
        'resultado' => 'continua',
        'observacion' => 'Aprendió rápido el alistamiento',
    ]);

    expect($e->fresh()->prueba_jefe_resultado)->toBe('continua')
        ->and($e->fresh()->prueba_jefe_por_id)->toBe($this->lider->id)
        // Y Gestión Humana se entera de que ya puede cerrar.
        ->and(NotificacionErp::where('user_id', $this->elena->id)->where('tipo', 'rrhh_prueba')->exists())
            ->toBeTrue();
});

it('con el concepto del jefe, Gestión Humana ya cierra', function () {
    $e = contratada();

    $this->actingAs($this->lider)->post("/app/rrhh/mi-equipo/{$e->id}/concepto", ['resultado' => 'continua']);
    $this->actingAs($this->elena)->post("/app/rrhh/empleados/{$e->id}/prueba/evaluar", ['resultado' => 'continua']);

    expect($e->fresh()->prueba_evaluada_at)->not->toBeNull()
        // Y queda escrito qué opinó cada uno.
        ->and($e->bitacora()->where('evento', 'prueba_evaluada')->first()->descripcion)
            ->toContain('el jefe conceptuó');
});

it('un jefe no opina sobre la gente de otra área', function () {
    $otro = User::factory()->create();
    $otro->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));
    $e = contratada();

    $this->actingAs($otro)->post("/app/rrhh/mi-equipo/{$e->id}/concepto", ['resultado' => 'continua'])
        ->assertStatus(403);

    // Y ni siquiera la ve en su pantalla.
    $this->actingAs($otro)->get('/app/rrhh/mi-equipo')
        ->assertInertia(fn ($p) => $p->has('equipo', 0));
});

it('el jefe no conceptúa dos veces', function () {
    $e = contratada();

    $this->actingAs($this->lider)->post("/app/rrhh/mi-equipo/{$e->id}/concepto", ['resultado' => 'continua']);
    $this->actingAs($this->lider)
        ->post("/app/rrhh/mi-equipo/{$e->id}/concepto", ['resultado' => 'no_continua', 'observacion' => 'me arrepentí'])
        ->assertStatus(422);

    expect($e->fresh()->prueba_jefe_resultado)->toBe('continua');
});

it('«no continúa» del jefe también exige explicación', function () {
    $e = contratada();

    $this->actingAs($this->lider)
        ->post("/app/rrhh/mi-equipo/{$e->id}/concepto", ['resultado' => 'no_continua'])
        ->assertSessionHasErrors('observacion');
});

it('quien ya estaba antes del módulo no se queda trabado sin jefe', function () {
    /*
     * La gente que no entró por una requisición no tiene a quién pedirle el
     * concepto. Exigirlo dejaría su período de prueba sin poder cerrarse nunca.
     */
    $viejo = Empleado::create([
        'nombre' => 'Quien ya estaba', 'tipo_documento' => 'CC', 'numero_documento' => '999',
        'cargo' => 'Auxiliar', 'area' => 'Bodega', 'tipo_contrato' => 'indefinido',
        'fecha_ingreso' => now()->subMonth(), 'estado' => 'activo',
        'prueba_hasta' => now()->addDays(5)->toDateString(),
    ]);

    $this->actingAs($this->elena)
        ->post("/app/rrhh/empleados/{$viejo->id}/prueba/evaluar", ['resultado' => 'continua'])
        ->assertSessionHasNoErrors();

    expect($viejo->fresh()->prueba_evaluada_at)->not->toBeNull();
});

it('el recordatorio también le llega al jefe, que es quien opina primero', function () {
    /*
     * Antes el aviso iba sólo a Gestión Humana: ella se enteraba de que el
     * plazo corría, pero quien tenía que dar el PRIMER paso no se enteraba
     * nunca y el período se vencía igual. La pantalla llegó a decirle a Elena
     * «ya se le pidió» cuando no se le había pedido nada.
     */
    $e = contratada();

    App\Http\Controllers\App\EmpleadoController::avisarPruebasPorVencer(15);

    $alJefe = NotificacionErp::where('user_id', $this->lider->id)->where('tipo', 'rrhh_prueba')->first();

    expect($alJefe)->not->toBeNull()
        ->and($alJefe->mensaje)->toContain('TU concepto')
        ->and($alJefe->url)->toBe('/app/rrhh/mi-equipo')
        ->and(NotificacionErp::where('user_id', $this->elena->id)->where('tipo', 'rrhh_prueba')->exists())
            ->toBeTrue();
});

it('al jefe que ya opinó no se le vuelve a pedir', function () {
    $e = contratada();
    $this->actingAs($this->lider)->post("/app/rrhh/mi-equipo/{$e->id}/concepto", ['resultado' => 'continua']);
    NotificacionErp::query()->delete();

    App\Http\Controllers\App\EmpleadoController::avisarPruebasPorVencer(15);

    expect(NotificacionErp::where('user_id', $this->lider->id)->count())->toBe(0)
        // Pero a Gestión Humana sí, que es la que todavía tiene que cerrar.
        ->and(NotificacionErp::where('user_id', $this->elena->id)->count())->toBeGreaterThan(0);
});
