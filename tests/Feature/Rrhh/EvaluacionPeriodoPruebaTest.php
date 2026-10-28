<?php

/**
 * Fase E · El formato GH-F-015 de evaluación de período de prueba.
 *
 * El ERP guardaba dos cosas: «continúa / no continúa» y una nota libre de 500
 * caracteres. El formato del área pide NUEVE aspectos de 1 a 5, qué resaltar y
 * qué mejorar.
 *
 * La diferencia aparece el día que alguien reclama la decisión. Un «no
 * continúa · no se adaptó» no sustenta nada; nueve notas y dos párrafos dicen
 * qué se miró y qué se le había señalado. Y al revés: con los nueve aspectos
 * se ve si a la persona la calificaron bien en todo y aun así no sigue.
 */

use App\Models\User;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\EvaluacionPrueba;
use App\Modules\Rrhh\Models\Requisicion;
use App\Modules\Rrhh\Models\Vacante;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Queue::fake();
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->jorge = User::factory()->create(['name' => 'Jorge Rojas']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->ajeno = User::factory()->create(['name' => 'Otro líder']);
    $this->ajeno->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));

    $req = Requisicion::create([
        'numero' => 'REQ-E-1', 'cargo' => 'Auxiliar de bodega', 'area' => 'Logística',
        'numero_vacantes' => 1, 'solicitante_id' => $this->jorge->id, 'estado' => 'aprobada',
    ]);
    $vac = Vacante::create([
        'requisicion_id' => $req->id, 'titulo' => 'Auxiliar de bodega',
        'area' => 'Logística', 'estado' => 'abierta',
    ]);

    $this->ana = Empleado::create([
        'vacante_id' => $vac->id,
        'nombre' => 'Ana María Pérez', 'tipo_documento' => 'CC', 'numero_documento' => '1098765432',
        'cargo' => 'Auxiliar de bodega', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 1_500_000, 'fecha_ingreso' => '2026-11-03',
        'prueba_hasta' => '2027-01-03',
        'estado' => 'activo', 'estado_induccion' => 'pendiente',
    ]);
});

/** Las nueve notas, con «manejo de personal» sin calificar por defecto. */
function notas(array $extra = []): array
{
    return array_merge([
        'cumplimiento_normas' => 5,
        'cumplimiento_horario' => 5,
        'cumplimiento_tareas' => 4,
        'relaciones_interpersonales' => 4,
        'recibe_retroalimentacion' => 5,
        'capacidad_aprendizaje' => 4,
        'resolucion_problemas' => 3,
        'actitud_trabajo' => 5,
    ], $extra);
}

// ── Guardar el formato ───────────────────────────────────────────────────────

it('el concepto del jefe guarda las nueve notas, no sólo el veredicto', function () {
    $this->actingAs($this->jorge)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto", notas([
            'resultado' => 'continua',
            'observacion' => 'Cumplió lo esperado.',
            'aspectos_resaltar' => 'Muy puntual y ordenada.',
            'aspectos_mejorar' => 'Le cuesta decidir sola.',
            'jefe_cargo' => 'Coordinador de Logística',
        ]))->assertRedirect();

    $ev = EvaluacionPrueba::where('empleado_id', $this->ana->id)->firstOrFail();

    expect($ev->cumplimiento_normas)->toBe(5)
        ->and($ev->resolucion_problemas)->toBe(3)
        ->and($ev->aspectos_resaltar)->toBe('Muy puntual y ordenada.')
        ->and($ev->aspectos_mejorar)->toBe('Le cuesta decidir sola.')
        ->and($ev->jefe_cargo)->toBe('Coordinador de Logística')
        ->and($ev->jefe_id)->toBe($this->jorge->id);
});

it('el veredicto sigue donde la pantalla y los avisos ya lo leen', function () {
    /*
     * El detalle va en tabla aparte, pero el veredicto se queda en el
     * empleado: es lo que miran la lista de pendientes, el aviso a Gestión
     * Humana y su decisión. Moverlo habría roto las tres.
     */
    $this->actingAs($this->jorge)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto",
            notas(['resultado' => 'continua']));

    expect($this->ana->fresh()->prueba_jefe_resultado)->toBe('continua')
        ->and($this->ana->fresh()->prueba_jefe_at)->not->toBeNull();
});

it('una nota fuera de la escala de 1 a 5 se rechaza', function () {
    /*
     * Un 7 no es un criterio distinto, es un error de dedo. Si entra, el
     * promedio que alguien lea después miente.
     */
    $this->actingAs($this->jorge)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto",
            notas(['cumplimiento_normas' => 7, 'resultado' => 'continua']))
        ->assertSessionHasErrors('cumplimiento_normas');

    expect(EvaluacionPrueba::count())->toBe(0);
});

it('se puede guardar sin calificar todo: el formato se llena de a poco', function () {
    $this->actingAs($this->jorge)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto", [
            'resultado' => 'continua', 'cumplimiento_normas' => 4,
        ])->assertRedirect();

    expect(EvaluacionPrueba::count())->toBe(1);
});

// ── El promedio ──────────────────────────────────────────────────────────────

it('«manejo de personal» en blanco NO hunde el promedio', function () {
    /*
     * El formato dice «si aplica». A un auxiliar sin gente a cargo no se le
     * puede exigir una nota ahí, y contarlo como cero sería castigarlo por el
     * cargo que ocupa.
     *
     * Ocho notas que suman 35: 35 / 8 = 4,375. Si el nulo contara como cero,
     * daría 3,89.
     */
    $this->actingAs($this->jorge)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto", notas(['resultado' => 'continua']));

    expect(EvaluacionPrueba::first()->promedio())->toBe(4.38);
});

it('«manejo de personal» tampoco se reporta como pendiente de calificar', function () {
    $this->actingAs($this->jorge)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto", notas(['resultado' => 'continua']));

    expect(EvaluacionPrueba::first()->sinCalificar())->toBe([]);
});

it('lo que el jefe sí debía calificar y dejó en blanco sí se reporta', function () {
    $this->actingAs($this->jorge)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto", [
            'resultado' => 'continua', 'cumplimiento_normas' => 4,
        ]);

    expect(EvaluacionPrueba::first()->sinCalificar())
        ->toContain('cumplimiento en el horario de trabajo')
        ->not->toContain('manejo de personal');
});

it('sin ninguna nota el promedio es nulo, no cero', function () {
    // Cero sería «lo calificaron pésimo»; nulo es «no lo calificaron».
    $ev = new EvaluacionPrueba();

    expect($ev->promedio())->toBeNull();
});

// ── Más de una evaluación ────────────────────────────────────────────────────

it('una segunda evaluación no pisa a la primera', function () {
    /*
     * El período de prueba se prorroga. Si el detalle viviera en columnas del
     * empleado, la segunda evaluación borraría la primera — y el historial es
     * justo lo que sustenta la decisión si después alguien la reclama.
     */
    $this->actingAs($this->jorge)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto",
            notas(['resultado' => 'continua', 'aspectos_mejorar' => 'Primera revisión.']));

    // Se reabre el concepto, como cuando el período se prorroga.
    $this->ana->update(['prueba_jefe_at' => null]);

    $this->actingAs($this->jorge)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto",
            notas(['resultado' => 'continua', 'aspectos_mejorar' => 'Segunda revisión.']));

    $todas = EvaluacionPrueba::where('empleado_id', $this->ana->id)->get();

    expect($todas)->toHaveCount(2)
        ->and($todas->pluck('aspectos_mejorar')->all())
        ->toContain('Primera revisión.')
        ->toContain('Segunda revisión.');
});

// ── Quién puede ──────────────────────────────────────────────────────────────

it('un líder de otra área no evalúa gente que no es suya', function () {
    $this->actingAs($this->ajeno)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto", notas(['resultado' => 'continua']))
        ->assertForbidden();
});

it('decir «no continúa» sin explicar no pasa', function () {
    // Sin el porqué, la empresa queda sin con qué sustentar la decisión.
    $this->actingAs($this->jorge)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto",
            notas(['resultado' => 'no_continua', 'observacion' => '']))
        ->assertSessionHasErrors('observacion');

    expect(EvaluacionPrueba::count())->toBe(0);
});

// ── El impreso ───────────────────────────────────────────────────────────────

it('el formato sale en PDF', function () {
    $this->actingAs($this->jorge)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto", notas(['resultado' => 'continua']));

    $ev = EvaluacionPrueba::first();

    $this->actingAs($this->elena)
        ->get("/app/rrhh/empleados/{$this->ana->id}/prueba/{$ev->id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('el impreso lleva su código y marca lo que no aplica', function () {
    $this->actingAs($this->jorge)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto",
            notas(['resultado' => 'continua', 'jefe_cargo' => 'Coordinador de Logística']));

    $ev = EvaluacionPrueba::with('jefe:id,name')->first();

    $html = view('rrhh.pdf.evaluacion-prueba', [
        'e' => $this->ana, 'ev' => $ev,
        'empresa' => App\Models\EmpresaConfig::current(), 'generadoPor' => 'Elena',
    ])->render();

    expect($html)->toContain('GH-F-015')
        ->toContain('Coordinador de Logística')
        ->toContain('no aplica')
        ->toContain('Firma del jefe inmediato')
        ->toContain('COPIA NO CONTROLADA');
});

it('no se puede imprimir la evaluación de OTRO empleado por la URL', function () {
    /*
     * La ruta lleva empleado y evaluación. Sin atar los dos, cambiar el número
     * en la barra mostraría el desempeño de cualquiera.
     */
    $this->actingAs($this->jorge)
        ->post("/app/rrhh/mi-equipo/{$this->ana->id}/concepto", notas(['resultado' => 'continua']));

    $ev = EvaluacionPrueba::first();

    $otro = Empleado::create([
        'nombre' => 'Otro', 'tipo_documento' => 'CC', 'numero_documento' => '999',
        'cargo' => 'x', 'area' => 'y', 'fecha_ingreso' => '2026-01-01',
        'estado' => 'activo', 'estado_induccion' => 'pendiente',
    ]);

    $this->actingAs($this->elena)
        ->get("/app/rrhh/empleados/{$otro->id}/prueba/{$ev->id}/pdf")
        ->assertNotFound();
});
