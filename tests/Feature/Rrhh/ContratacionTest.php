<?php

/**
 * F3 + F4 · De «lo elegimos» a «ya trabaja acá», y sus dos primeros meses.
 *
 * El tramo más frágil del proceso: hoy el examen médico, los papeles, la firma
 * y las cuatro afiliaciones de ley viven en carpetas. Si falta una afiliación
 * nadie se entera hasta que pasa algo, y entonces es tarde y es plata.
 *
 * Y después, el período de prueba, que hoy se vence solo: pasada esa fecha,
 * terminar el contrato ya no se puede sin justa causa ni indemnización.
 */

use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Rrhh\Enums\EstadoRequisicion;
use App\Modules\Rrhh\Enums\EtapaCandidato;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\Contratacion;
use App\Modules\Rrhh\Models\DocumentoEmpleado;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Requisicion;
use App\Modules\Rrhh\Models\Vacante;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->lider = User::factory()->create(['name' => 'Jorge Rojas']);
    $this->lider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $req = Requisicion::create([
        'numero' => Requisicion::siguienteNumero(),
        'cargo' => 'Auxiliar de bodega',
        'area' => 'Logística',
        'numero_vacantes' => 1,
        'salario_min' => 1500000,
        'solicitante_id' => $this->lider->id,
        'estado' => EstadoRequisicion::Aprobada->value,
    ]);

    $this->vacante = Vacante::create([
        'requisicion_id' => $req->id,
        'titulo' => 'Auxiliar de bodega',
        'area' => 'Logística',
        'tipo_contrato' => 'indefinido',
        'estado' => 'abierta',
    ]);

    $this->elegida = Candidato::create([
        'vacante_id' => $this->vacante->id,
        'nombre' => 'Ana Pérez',
        'documento' => '1098765432',
        'telefono' => '3001234567',
        'email' => 'ana@correo.test',
        'etapa' => EtapaCandidato::Seleccionado->value,
        'antecedentes_revisados' => true,
        'elegido_at' => now(),
        'elegido_por_id' => $this->lider->id,
    ]);
});

/** La orden de la Junta ya autorizada, que es el paso previo desde F5. */
function autorizada(float $salario = 1500000): App\Modules\Rrhh\Models\OrdenContratacion
{
    return App\Modules\Rrhh\Models\OrdenContratacion::create([
        'numero' => App\Modules\Rrhh\Models\OrdenContratacion::siguienteNumero(),
        'candidato_id' => test()->elegida->id,
        'vacante_id' => test()->vacante->id,
        'cargo' => 'Auxiliar de bodega',
        'salario_propuesto' => $salario,
        'tipo_contrato' => 'indefinido',
        'estado' => 'autorizada',
        'decidida_at' => now(),
    ]);
}

/** Deja una contratación con todo hecho menos lo que se indique. */
function contratacionLista(array $sin = []): Contratacion
{
    $campos = [
        'estado' => 'en_proceso',
        'vacante_id' => test()->vacante->id,
        'salario' => 1500000,
        'tipo_contrato' => 'indefinido',
        'fecha_ingreso' => now()->addWeek()->toDateString(),
        'examen_at' => now(),
        'examen_resultado' => 'apto',
        'contrato_firmado_at' => now(),
        'eps' => 'Sura', 'eps_at' => now(),
        'arl' => 'Positiva', 'arl_at' => now(),
        'pension' => 'Porvenir', 'pension_at' => now(),
        'caja' => 'Compensar', 'caja_at' => now(),
    ];

    foreach ($sin as $c) {
        $campos[$c] = null;
    }

    return Contratacion::create(['candidato_id' => test()->elegida->id] + $campos);
}

it('la contratación sólo arranca con el candidato ya elegido', function () {
    $otro = Candidato::create([
        'vacante_id' => $this->vacante->id,
        'nombre' => 'Todavía en pruebas',
        'etapa' => EtapaCandidato::Pruebas->value,
    ]);

    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$otro->id}/contratar")
        ->assertStatus(422);

    expect(Contratacion::count())->toBe(0);
});

it('apretar dos veces no abre dos expedientes de la misma persona', function () {
    // Desde F5 hace falta la orden autorizada por la Junta para llegar acá.
    autorizada();

    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/contratar");
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$this->elegida->id}/contratar");

    expect(Contratacion::count())->toBe(1)
        // Lo acordado se precarga de lo AUTORIZADO, sin volver a teclearlo.
        ->and((float) Contratacion::first()->salario)->toBe(1500000.0);
});

it('no se cierra la contratación con pendientes', function () {
    // Sin ARL, si la persona se accidenta el primer día responde la empresa.
    $x = contratacionLista(sin: ['arl_at']);

    $this->actingAs($this->elena)->post("/app/rrhh/contrataciones/{$x->id}/completar")
        ->assertSessionHasErrors('cierre');

    expect(Empleado::count())->toBe(0);
});

it('la lista de pendientes es la misma que frena el cierre', function () {
    /*
     * Si fueran dos cálculos distintos, un día la pantalla diría «ya está» y
     * el botón diría otra cosa. Es el mismo método.
     */
    $x = contratacionLista(sin: ['examen_at', 'eps_at', 'caja_at']);

    expect($x->loQueFalta())->toHaveCount(3)
        ->and($x->loQueFalta())->toContain('Examen médico de ingreso')
        ->and($x->loQueFalta())->toContain('EPS (salud)')
        ->and($x->loQueFalta())->toContain('Caja de compensación');
});

it('un examen médico NO APTO frena la contratación entera', function () {
    // No es un pendiente más: seguir sería exponer a la persona y a la empresa.
    $x = contratacionLista();
    $x->update(['examen_resultado' => 'no_apto', 'examen_observacion' => 'No apto para carga física']);

    $this->actingAs($this->elena)->post("/app/rrhh/contrataciones/{$x->id}/completar")
        ->assertSessionHasErrors('cierre');

    expect(Empleado::count())->toBe(0)
        ->and($x->fresh()->examenLoFrena())->toBeTrue();
});

it('un examen con restricción exige decir cuál', function () {
    $x = contratacionLista(sin: ['examen_at', 'examen_resultado']);

    $this->actingAs($this->elena)
        ->post("/app/rrhh/contrataciones/{$x->id}/examen", ['resultado' => 'apto_con_restriccion'])
        ->assertSessionHasErrors('observacion');

    expect($x->fresh()->examen_at)->toBeNull();
});

it('no se firma un contrato sin saber qué se firma', function () {
    // Es lo que pasa hoy cuando el contrato se imprime con los datos que
    // alguien recordaba.
    $x = contratacionLista(sin: ['salario', 'fecha_ingreso', 'contrato_firmado_at']);

    /*
     * Va como error de formulario y no como 422 porque este botón SÍ está
     * visible en ese estado: se vio en pantalla que al apretarlo no pasaba
     * nada y la usuaria no tenía cómo saber por qué.
     */
    $this->actingAs($this->elena)->post("/app/rrhh/contrataciones/{$x->id}/firmar")
        ->assertSessionHasErrors('cierre');

    expect($x->fresh()->contrato_firmado_at)->toBeNull();
});

it('una afiliación sin entidad no sirve', function () {
    // «Afiliado» sin saber a cuál no le dice nada a nadie después.
    $x = contratacionLista(sin: ['eps', 'eps_at']);

    $this->actingAs($this->elena)
        ->post("/app/rrhh/contrataciones/{$x->id}/afiliacion", ['campo' => 'eps', 'entidad' => ''])
        ->assertSessionHasErrors('entidad');

    expect($x->fresh()->eps_at)->toBeNull();
});

it('al cerrar, el empleado nace con los datos que ya dio como candidata', function () {
    // Volver a digitarlos es donde se cuelan los errores.
    $x = contratacionLista();

    $this->actingAs($this->elena)->post("/app/rrhh/contrataciones/{$x->id}/completar")
        ->assertRedirect();

    $e = Empleado::first();

    expect($e->nombre)->toBe('Ana Pérez')
        ->and($e->numero_documento)->toBe('1098765432')
        ->and($e->telefono)->toBe('3001234567')
        ->and($e->cargo)->toBe('Auxiliar de bodega')
        ->and($e->area)->toBe('Logística')
        ->and((float) $e->salario)->toBe(1500000.0)
        ->and($e->estado)->toBe('activo')
        ->and($e->estado_induccion)->toBe('pendiente')
        // Y la contratación queda amarrada al empleado que produjo.
        ->and($x->fresh()->estado)->toBe('completada')
        ->and($x->fresh()->empleado_id)->toBe($e->id)
        ->and($e->bitacora()->count())->toBe(1);
});

it('los papeles del candidato siguen a la persona al ser contratada', function () {
    // Los trae cuando todavía es candidata; no se los vuelven a pedir.
    Storage::fake();
    $x = contratacionLista();

    $this->actingAs($this->elena)->post("/app/rrhh/contrataciones/{$x->id}/documento", [
        'tipo' => 'cedula',
        'archivo' => UploadedFile::fake()->create('cc.pdf', 60, 'application/pdf'),
    ]);

    expect(DocumentoEmpleado::first()->empleado_id)->toBeNull();

    $this->actingAs($this->elena)->post("/app/rrhh/contrataciones/{$x->id}/completar");

    expect(DocumentoEmpleado::first()->empleado_id)->toBe(Empleado::first()->id);
});

it('no se contrata dos veces a la misma cédula', function () {
    Empleado::create([
        'nombre' => 'Ana Pérez', 'tipo_documento' => 'CC', 'numero_documento' => '1098765432',
        'cargo' => 'Otra cosa', 'area' => 'Otra', 'tipo_contrato' => 'indefinido',
        'fecha_ingreso' => now()->subYear(), 'estado' => 'activo',
    ]);

    $x = contratacionLista();

    $this->actingAs($this->elena)->post("/app/rrhh/contrataciones/{$x->id}/completar")
        ->assertSessionHasErrors('cierre');

    expect(Empleado::count())->toBe(1);
});

it('cancelar devuelve al candidato a la terna, no lo descarta', function () {
    /*
     * Que esta contratación no haya salido —porque no aceptó la oferta, por
     * ejemplo— no lo hace mal candidato, y puede que haya que volver a él.
     */
    $x = contratacionLista();

    $this->actingAs($this->elena)->post("/app/rrhh/contrataciones/{$x->id}/cancelar", [
        'motivo' => 'Consiguió otro trabajo antes de firmar',
    ]);

    expect($x->fresh()->estado)->toBe('cancelada')
        ->and($this->elegida->fresh()->etapa)->toBe(EtapaCandidato::Terna)
        ->and(NotificacionErp::where('user_id', $this->lider->id)->where('tipo', 'rrhh_contratacion')->exists())
            ->toBeTrue();
});

// ───────────────────────────── F4 ─────────────────────────────

function empleadaActiva(array $extra = []): Empleado
{
    return Empleado::create(array_merge([
        'nombre' => 'Ana Pérez', 'tipo_documento' => 'CC', 'numero_documento' => '1098765432',
        'cargo' => 'Auxiliar de bodega', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 1500000, 'fecha_ingreso' => now()->subMonth()->toDateString(),
        'estado' => 'activo', 'estado_induccion' => 'pendiente',
    ], $extra));
}

it('la inducción queda con fecha, responsable y qué se vio', function () {
    // Antes sólo se podía marcar «completada». Para una inducción en seguridad
    // y salud en el trabajo, eso es justo lo que hay que poder demostrar.
    $e = empleadaActiva();

    $this->actingAs($this->elena)->post("/app/rrhh/empleados/{$e->id}/induccion", [
        'temas' => 'Reglamento interno, seguridad y salud en el trabajo, recorrido por bodega',
    ]);

    expect($e->fresh()->estado_induccion)->toBe('completada')
        ->and($e->fresh()->induccion_at)->not->toBeNull()
        ->and($e->fresh()->induccion_por_id)->toBe($this->elena->id)
        ->and($e->fresh()->induccion_temas)->toContain('seguridad y salud');
});

it('el período de prueba no puede terminar antes de que la persona entre', function () {
    $e = empleadaActiva(['fecha_ingreso' => now()->addWeek()->toDateString()]);

    $this->actingAs($this->elena)
        ->post("/app/rrhh/empleados/{$e->id}/prueba", ['hasta' => now()->toDateString()])
        ->assertSessionHasErrors('hasta');
});

it('el sistema propone dos meses, que es el tope legal del indefinido', function () {
    $e = empleadaActiva(['fecha_ingreso' => '2026-10-01']);

    expect($e->pruebaSugerida()->toDateString())->toBe('2026-12-01');
});

it('avisa de los períodos de prueba que están por vencerse', function () {
    // Hoy no existe nada parecido: el plazo se pasa solo.
    $pronto = empleadaActiva(['prueba_hasta' => now()->addDays(5)->toDateString()]);
    empleadaActiva(['numero_documento' => '222', 'prueba_hasta' => now()->addMonths(3)->toDateString()]);

    $avisados = \App\Http\Controllers\App\EmpleadoController::avisarPruebasPorVencer(15);

    $aviso = NotificacionErp::where('tipo', 'rrhh_prueba')->where('user_id', $this->elena->id)->first();

    expect($avisados)->toBe(1)
        ->and($aviso->titulo)->toContain('Ana Pérez')
        ->and($aviso->url)->toBe("/app/rrhh/empleados/{$pronto->id}");
});

it('un período ya evaluado deja de avisar', function () {
    empleadaActiva([
        'prueba_hasta' => now()->addDays(3)->toDateString(),
        'prueba_evaluada_at' => now(),
        'prueba_resultado' => 'continua',
    ]);

    expect(\App\Http\Controllers\App\EmpleadoController::avisarPruebasPorVencer(15))->toBe(0);
});

it('«no continúa» sin decir por qué no se guarda', function () {
    // Sin motivo, la empresa queda sin con qué sustentar la decisión.
    $e = empleadaActiva(['prueba_hasta' => now()->addDays(5)->toDateString()]);

    $this->actingAs($this->elena)
        ->post("/app/rrhh/empleados/{$e->id}/prueba/evaluar", ['resultado' => 'no_continua'])
        ->assertSessionHasErrors('observacion');

    expect($e->fresh()->prueba_evaluada_at)->toBeNull()
        ->and($e->fresh()->estado)->toBe('activo');
});

it('no continuar deja el retiro registrado', function () {
    $e = empleadaActiva(['prueba_hasta' => now()->addDays(5)->toDateString()]);

    $this->actingAs($this->elena)->post("/app/rrhh/empleados/{$e->id}/prueba/evaluar", [
        'resultado' => 'no_continua',
        'observacion' => 'No alcanzó el ritmo de alistamiento esperado',
    ]);

    expect($e->fresh()->estado)->toBe('retirado')
        ->and($e->fresh()->fecha_retiro?->toDateString())->toBe(now()->toDateString())
        ->and($e->bitacora()->first()->descripcion)->toContain('ritmo de alistamiento');
});

it('el período de prueba no se evalúa dos veces', function () {
    $e = empleadaActiva([
        'prueba_hasta' => now()->addDays(5)->toDateString(),
        'prueba_evaluada_at' => now(),
        'prueba_resultado' => 'continua',
    ]);

    $this->actingAs($this->elena)
        ->post("/app/rrhh/empleados/{$e->id}/prueba/evaluar", ['resultado' => 'no_continua', 'observacion' => 'me arrepentí'])
        ->assertStatus(422);

    expect($e->fresh()->estado)->toBe('activo');
});

it('los días que quedan se cuentan bien, y en negativo si ya venció', function () {
    expect(empleadaActiva(['prueba_hasta' => now()->addDays(10)->toDateString()])->diasDePrueba())->toBe(10)
        ->and(empleadaActiva(['numero_documento' => '333', 'prueba_hasta' => now()->subDays(4)->toDateString()])->diasDePrueba())->toBe(-4);
});

it('el líder de área no entra a las fichas de empleados', function () {
    // Ahí está el salario de todo el mundo.
    $e = empleadaActiva();

    $this->actingAs($this->lider)->get("/app/rrhh/empleados/{$e->id}")->assertStatus(403);
    $this->actingAs($this->lider)->get('/app/rrhh/contrataciones')->assertStatus(403);
});
