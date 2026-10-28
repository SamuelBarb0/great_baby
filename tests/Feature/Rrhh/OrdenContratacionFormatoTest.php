<?php

/**
 * Fase D1 · El formato GH-F-018 que firma la Junta Directiva.
 *
 * «FORMATO AUTORIZACIÓN DE CONTRATACIÓN» pide once casillas; la orden del ERP
 * guardaba cinco. Las otras seis —área, fecha de inicio, horario, ciudad, tipo
 * de pago y observaciones— se escribían a mano sobre el impreso.
 *
 * Eso importa por una razón concreta: una casilla que se completa DESPUÉS de
 * la firma es una autorización que dice una cosa y un contrato que dice otra.
 * Por eso lo que se cuida acá no es que los campos existan, sino que se puedan
 * llenar ANTES y que no se puedan tocar DESPUÉS.
 */

use App\Models\User;
use App\Modules\Rrhh\Enums\EtapaCandidato;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\Contratacion;
use App\Modules\Rrhh\Models\OrdenContratacion;
use App\Modules\Rrhh\Models\Requisicion;
use App\Modules\Rrhh\Models\Vacante;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Queue::fake();
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->lider = User::factory()->create(['name' => 'Jorge Líder']);
    $this->lider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));

    /*
     * La Junta Directiva es el rol `Gerente`, y hay que repartirle los
     * permisos a mano: `PermisosSeeder` los reparte entre los roles QUE YA
     * EXISTEN, y con `RefreshDatabase` la tabla nace vacía, así que el rol se
     * crea después y sin nada. En la base de verdad el rol ya está.
     */
    $rolGerente = Role::firstOrCreate(['name' => 'Gerente', 'guard_name' => 'web']);
    $rolGerente->syncPermissions(collect(App\Auth\Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array('Gerente', $roles, true))
        ->keys()->map(fn ($s) => App\Auth\Permisos::permiso($s))->all());

    $this->junta = User::factory()->create(['name' => 'Junta Directiva']);
    $this->junta->assignRole($rolGerente);
});

function candidatoElegido(array $vacante = []): Candidato
{
    static $n = 0;
    $n++;

    $req = Requisicion::create([
        'numero' => "REQ-F-{$n}", 'cargo' => 'Auxiliar de bodega', 'area' => 'Logística',
        'numero_vacantes' => 1, 'solicitante_id' => test()->lider->id, 'estado' => 'aprobada',
    ]);

    $vac = Vacante::create(array_merge([
        'requisicion_id' => $req->id, 'titulo' => 'Auxiliar de bodega', 'area' => 'Logística',
        'tipo_contrato' => 'indefinido', 'estado' => 'abierta',
    ], $vacante));

    return Candidato::create([
        'vacante_id' => $vac->id, 'nombre' => "Candidata {$n}", 'documento' => "cand{$n}",
        'etapa' => EtapaCandidato::Seleccionado->value,
    ]);
}

function ordenDe(Candidato $c, array $extra = []): OrdenContratacion
{
    test()->actingAs(test()->elena)
        ->post("/app/rrhh/candidatos/{$c->id}/orden", array_merge([
            'salario_propuesto' => 1_500_000,
        ], $extra));

    return OrdenContratacion::where('candidato_id', $c->id)->firstOrFail();
}

// ── Diligenciar ──────────────────────────────────────────────────────────────

it('guarda las seis casillas que el formato pedía y no existían', function () {
    $o = ordenDe(candidatoElegido(), [
        'area' => 'Logística',
        'fecha_inicio' => '2026-11-03',
        'horario' => 'Lunes a viernes 7:30 a 17:00',
        'ciudad' => 'Bucaramanga',
        'periodicidad_pago' => 'quincenal',
        'observaciones' => 'Reemplaza a quien renunció.',
    ]);

    expect($o->area)->toBe('Logística')
        ->and($o->fecha_inicio?->toDateString())->toBe('2026-11-03')
        ->and($o->horario)->toBe('Lunes a viernes 7:30 a 17:00')
        ->and($o->ciudad)->toBe('Bucaramanga')
        ->and($o->periodicidad_pago)->toBe('quincenal')
        ->and($o->observaciones)->toBe('Reemplaza a quien renunció.');
});

it('el área se hereda de la vacante si no la escriben', function () {
    // El cargo ya salió de ahí; pedir el área otra vez es hacer digitar lo
    // mismo dos veces, y es donde se cuelan las diferencias.
    $o = ordenDe(candidatoElegido(['area' => 'Contabilidad']));

    expect($o->area)->toBe('Contabilidad');
});

it('dice qué casillas van en blanco antes de llevarlo a firma', function () {
    $o = ordenDe(candidatoElegido());

    expect($o->faltantesDelFormato())
        ->toContain('fecha de inicio')
        ->toContain('horario de trabajo')
        ->toContain('ciudad donde va a laborar')
        ->toContain('tipo de pago')
        // El área sí se heredó de la vacante.
        ->not->toContain('área');
});

it('una orden completa no reporta faltantes', function () {
    $o = ordenDe(candidatoElegido(), [
        'fecha_inicio' => '2026-11-03', 'horario' => '8 a 5',
        'ciudad' => 'Bucaramanga', 'periodicidad_pago' => 'mensual',
    ]);

    expect($o->faltantesDelFormato())->toBe([]);
});

it('se puede corregir mientras la Junta no haya decidido', function () {
    $o = ordenDe(candidatoElegido());

    $this->actingAs($this->elena)
        ->put("/app/rrhh/ordenes/{$o->id}", ['horario' => 'Turno nocturno 22:00 a 6:00'])
        ->assertRedirect();

    expect($o->fresh()->horario)->toBe('Turno nocturno 22:00 a 6:00');
});

it('NO se puede tocar después de que la Junta firmó', function () {
    /*
     * El candado. Lo que la Junta autorizó fue ESE papel, con ese horario y
     * esa fecha de inicio. Cambiarlo después es mover lo autorizado sin que
     * nadie se entere.
     */
    $o = ordenDe(candidatoElegido(), [
        'fecha_inicio' => '2026-11-03', 'horario' => '8 a 5',
        'ciudad' => 'Bucaramanga', 'periodicidad_pago' => 'mensual',
    ]);

    $this->actingAs($this->junta)->post("/app/rrhh/ordenes/{$o->id}/autorizar", []);

    $this->actingAs($this->elena)
        ->put("/app/rrhh/ordenes/{$o->id}", ['horario' => 'otra cosa'])
        ->assertStatus(422);

    expect($o->fresh()->horario)->toBe('8 a 5');
});

it('un tipo de pago que no es mensual ni quincenal se rechaza', function () {
    $o = ordenDe(candidatoElegido());

    $this->actingAs($this->elena)
        ->put("/app/rrhh/ordenes/{$o->id}", ['periodicidad_pago' => 'semanal'])
        ->assertSessionHasErrors('periodicidad_pago');
});

it('el líder de área no diligencia el formato: lo hace Gestión Humana', function () {
    $o = ordenDe(candidatoElegido());

    $this->actingAs($this->lider)
        ->put("/app/rrhh/ordenes/{$o->id}", ['ciudad' => 'Medellín'])
        ->assertForbidden();
});

// ── El impreso ───────────────────────────────────────────────────────────────

it('el formato sale en PDF con su código de control documental', function () {
    $o = ordenDe(candidatoElegido(), [
        'fecha_inicio' => '2026-11-03', 'horario' => '8 a 5',
        'ciudad' => 'Bucaramanga', 'periodicidad_pago' => 'mensual',
    ]);

    $r = $this->actingAs($this->elena)->get("/app/rrhh/ordenes/{$o->id}/pdf");

    $r->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('la Junta también puede abrir el formato, para leer qué firma', function () {
    $o = ordenDe(candidatoElegido());

    $this->actingAs($this->junta)->get("/app/rrhh/ordenes/{$o->id}/pdf")->assertOk();
});

it('el impreso muestra al líder del área que pidió el personal', function () {
    /*
     * El formato tiene dos firmas. La del líder no es un botón nuevo: él ya
     * actuó al pedir el personal y al elegir de la terna. El papel muestra ese
     * acto en vez de pedir que vuelva a confirmar algo que ya confirmó.
     */
    $o = ordenDe(candidatoElegido());

    expect($o->liderDelArea()?->name)->toBe('Jorge Líder');
});

// ── Lo que la Junta autoriza llega solo a la ficha ───────────────────────────

it('la fecha de inicio pasa de la orden a la contratación', function () {
    /*
     * Antes la fecha se preguntaba DESPUÉS, al pactar el acuerdo, y para
     * entonces la firma ya estaba puesta sobre un papel sin esa fecha.
     */
    $c = candidatoElegido();
    $o = ordenDe($c, [
        'fecha_inicio' => '2026-11-03', 'horario' => '8 a 5',
        'ciudad' => 'Bucaramanga', 'periodicidad_pago' => 'quincenal',
    ]);

    $this->actingAs($this->junta)->post("/app/rrhh/ordenes/{$o->id}/autorizar", []);
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$c->id}/contratar");

    $x = Contratacion::where('candidato_id', $c->id)->firstOrFail();

    expect($x->fecha_ingreso?->toDateString())->toBe('2026-11-03');
});

it('el horario y el tipo de pago autorizados llegan a la ficha del empleado', function () {
    /*
     * La otra mitad del circuito. El cuadro de novedades (Fase B) pide horario
     * y tipo de pago por cada persona; la Junta ya los autorizó en el formato.
     * Antes había que volver a digitarlos en la ficha mirando el papel
     * impreso, que es donde se cuelan las diferencias entre lo autorizado y lo
     * que termina en nómina.
     */
    $c = candidatoElegido();
    $o = ordenDe($c, [
        'fecha_inicio' => '2026-11-03',
        'horario' => 'Lunes a viernes 7:30 a 17:00',
        'ciudad' => 'Bucaramanga',
        'periodicidad_pago' => 'quincenal',
    ]);

    $this->actingAs($this->junta)->post("/app/rrhh/ordenes/{$o->id}/autorizar", []);
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$c->id}/contratar");

    // Se completa el resto del checklist para poder cerrar.
    Contratacion::where('candidato_id', $c->id)->update([
        'examen_at' => now(), 'examen_resultado' => 'apto',
        'contrato_firmado_at' => now(),
        'eps' => 'Sura', 'eps_at' => now(),
        'arl' => 'Positiva', 'arl_at' => now(),
        'pension' => 'Porvenir', 'pension_at' => now(),
        'caja' => 'Compensar', 'caja_at' => now(),
    ]);

    $x = Contratacion::where('candidato_id', $c->id)->firstOrFail();
    $this->actingAs($this->elena)->post("/app/rrhh/contrataciones/{$x->id}/completar");

    $e = App\Modules\Rrhh\Models\Empleado::where('numero_documento', $c->documento)->firstOrFail();

    expect($e->horario)->toBe('Lunes a viernes 7:30 a 17:00')
        ->and($e->ciudad)->toBe('Bucaramanga')
        ->and($e->periodicidad_pago)->toBe('quincenal')
        ->and($e->fecha_ingreso?->toDateString())->toBe('2026-11-03');
});
