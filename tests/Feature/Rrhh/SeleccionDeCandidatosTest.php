<?php

/**
 * F2 · El proceso de selección, desde que llega la hoja de vida.
 *
 * Lo que estas pruebas cuidan son las dos reglas que hoy viven en la cabeza de
 * Elena y que por eso se saltan el día que hay afán:
 *
 *   · No se cita a entrevista sin haber revisado antecedentes judiciales.
 *   · Una terna es de tres.
 *
 * Y lo que la reunión pidió a gritos: que el jefe vea en qué va su gente sin
 * llamar a nadie, y que su elección quede escrita.
 */

use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Rrhh\Enums\EstadoRequisicion;
use App\Modules\Rrhh\Enums\EtapaCandidato;
use App\Modules\Rrhh\Models\Candidato;
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

    $this->requisicion = Requisicion::create([
        'numero' => Requisicion::siguienteNumero(),
        'cargo' => 'Auxiliar de bodega',
        'area' => 'Logística',
        'numero_vacantes' => 1,
        'solicitante_id' => $this->lider->id,
        'estado' => EstadoRequisicion::Aprobada->value,
    ]);

    $this->vacante = Vacante::create([
        'requisicion_id' => $this->requisicion->id,
        'titulo' => 'Auxiliar de bodega',
        'area' => 'Logística',
        'estado' => 'abierta',
    ]);
});

function candidato(array $extra = []): Candidato
{
    return Candidato::create(array_merge([
        'vacante_id' => test()->vacante->id,
        'nombre' => 'Ana Pérez',
        'documento' => '1098765432',
        'etapa' => EtapaCandidato::Recibido->value,
    ], $extra));
}

it('la hoja de vida que llega queda registrada con su fuente', function () {
    Storage::fake();

    $this->actingAs($this->elena)->post("/app/rrhh/vacantes/{$this->vacante->id}/candidatos", [
        'nombre' => 'Ana Pérez',
        'documento' => '1098765432',
        'telefono' => '3001234567',
        // La publicación en la agencia pública de empleo es obligatoria por
        // normativa, así que de dónde llegó cada quien importa.
        'fuente' => 'caja_compensacion',
        'cv' => UploadedFile::fake()->create('hv-ana.pdf', 120, 'application/pdf'),
    ])->assertRedirect();

    $c = Candidato::first();

    expect($c->etapa)->toBe(EtapaCandidato::Recibido)
        ->and($c->fuente)->toBe('caja_compensacion')
        ->and($c->cv_path)->not->toBeNull()
        ->and(Storage::exists($c->cv_path))->toBeTrue()
        ->and($c->bitacora()->count())->toBe(1);
});

it('la hoja de vida no queda en el disco público', function () {
    /*
     * Lleva cédula, dirección y teléfono de alguien que ni siquiera trabaja
     * acá. En el disco público —donde sí van las fotos de producto— bastaría
     * con tener el enlace para leerla.
     */
    Storage::fake();
    Storage::fake('public');

    $this->actingAs($this->elena)->post("/app/rrhh/vacantes/{$this->vacante->id}/candidatos", [
        'nombre' => 'Ana Pérez',
        'cv' => UploadedFile::fake()->create('hv.pdf', 50, 'application/pdf'),
    ]);

    expect(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(Storage::exists(Candidato::first()->cv_path))->toBeTrue();
});

it('no se cita a entrevista sin revisar antecedentes', function () {
    // Es el paso que se salta solo el día que hay afán, que es justo el día
    // en que importa.
    $c = candidato();

    $this->actingAs($this->elena)
        ->post("/app/rrhh/candidatos/{$c->id}/mover", ['etapa' => 'entrevista_jefe'])
        ->assertSessionHasErrors('etapa');

    expect($c->fresh()->etapa)->toBe(EtapaCandidato::Recibido);
});

it('preseleccionar sí se puede sin antecedentes', function () {
    // Preseleccionar es sólo leer la hoja de vida; la consulta se hace antes
    // de CITAR, no antes de mirar.
    $c = candidato();

    $this->actingAs($this->elena)
        ->post("/app/rrhh/candidatos/{$c->id}/mover", ['etapa' => 'preseleccionado'])
        ->assertSessionHasNoErrors();

    expect($c->fresh()->etapa)->toBe(EtapaCandidato::Preseleccionado);
});

it('con los antecedentes revisados ya se puede citar', function () {
    $c = candidato();

    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$c->id}/antecedentes", [
        'resultado' => 'Sin antecedentes judiciales',
    ]);
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$c->id}/mover", ['etapa' => 'entrevista_jefe']);

    expect($c->fresh()->antecedentes_revisados)->toBeTrue()
        ->and($c->fresh()->etapa)->toBe(EtapaCandidato::EntrevistaJefe)
        ->and($c->bitacora()->where('evento', 'antecedentes')->exists())->toBeTrue();
});

it('una terna es de tres: el cuarto no entra', function () {
    $hechos = collect(range(1, 3))->map(fn ($i) => candidato([
        'nombre' => "Candidato {$i}",
        'etapa' => EtapaCandidato::Terna->value,
        'antecedentes_revisados' => true,
    ]));

    $cuarto = candidato(['nombre' => 'El cuarto', 'antecedentes_revisados' => true]);

    $this->actingAs($this->elena)
        ->post("/app/rrhh/candidatos/{$cuarto->id}/mover", ['etapa' => 'terna'])
        ->assertSessionHasErrors('etapa');

    expect($cuarto->fresh()->etapa)->toBe(EtapaCandidato::Recibido)
        ->and($hechos)->toHaveCount(3);
});

it('con el primero que llega a la terna, el jefe ya se entera', function () {
    /*
     * Al principio esto avisaba sólo al llegar a tres, y se vio en pantalla
     * lo que estaba mal: una vacante de UNA persona con UNA finalista no
     * generaba aviso nunca. O sea, el jefe seguía teniendo que preguntar —el
     * problema exacto que el módulo vino a resolver—.
     */
    $uno = candidato(['nombre' => 'Uno', 'antecedentes_revisados' => true]);

    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$uno->id}/mover", ['etapa' => 'terna']);

    $aviso = NotificacionErp::where('user_id', $this->lider->id)->where('tipo', 'rrhh_terna')->first();

    expect($aviso)->not->toBeNull()
        ->and($aviso->titulo)->toContain('Auxiliar de bodega');
});

it('los que entran después no vuelven a avisar', function () {
    // Un aviso por cada candidato que entra sería ruido.
    $uno = candidato(['nombre' => 'Uno', 'antecedentes_revisados' => true]);
    $dos = candidato(['nombre' => 'Dos', 'antecedentes_revisados' => true]);

    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$uno->id}/mover", ['etapa' => 'terna']);
    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$dos->id}/mover", ['etapa' => 'terna']);

    expect(NotificacionErp::where('tipo', 'rrhh_terna')->count())->toBe(1);
});

it('descartar sin motivo no se puede', function () {
    $c = candidato();

    $this->actingAs($this->elena)
        ->post("/app/rrhh/candidatos/{$c->id}/descartar", ['motivo' => ''])
        ->assertSessionHasErrors('motivo');

    expect($c->fresh()->etapa)->toBe(EtapaCandidato::Recibido);
});

it('el motivo del descarte queda para cuando haya otra vacante', function () {
    $c = candidato();

    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$c->id}/descartar", [
        'motivo' => 'No se presentó a la entrevista',
    ]);

    expect($c->fresh()->etapa)->toBe(EtapaCandidato::Descartado)
        ->and($c->fresh()->motivo_descarte)->toBe('No se presentó a la entrevista')
        ->and($c->bitacora()->first()->descripcion)->toContain('No se presentó');
});

it('un candidato descartado ya no se mueve', function () {
    $c = candidato(['etapa' => EtapaCandidato::Descartado->value, 'antecedentes_revisados' => true]);

    $this->actingAs($this->elena)
        ->post("/app/rrhh/candidatos/{$c->id}/mover", ['etapa' => 'pruebas'])
        ->assertSessionHasErrors('etapa');
});

it('«apto con restricción» sin explicar no se guarda', function () {
    // La salvedad sin decir cuál no le sirve al jefe que elige.
    $c = candidato();

    $this->actingAs($this->elena)
        ->post("/app/rrhh/candidatos/{$c->id}/valorar", ['valoracion' => 'apto_con_restriccion'])
        ->assertSessionHasErrors('observacion');

    expect($c->fresh()->valoracion)->toBeNull();
});

it('«apto» a secas sí se guarda', function () {
    $c = candidato();

    $this->actingAs($this->elena)->post("/app/rrhh/candidatos/{$c->id}/valorar", ['valoracion' => 'apto']);

    expect($c->fresh()->valoracion->value)->toBe('apto');
});

it('el jefe ve en qué va su gente sin entrar a Gestión Humana', function () {
    // Es la frase textual de Elena: que abra el móvil y lo vea sin preguntar.
    candidato(['nombre' => 'Ana Pérez', 'etapa' => EtapaCandidato::EntrevistaJefe->value]);
    candidato(['nombre' => 'Descartada', 'etapa' => EtapaCandidato::Descartado->value]);

    $this->actingAs($this->lider)->get("/app/rrhh/requisiciones/{$this->requisicion->id}")
        ->assertInertia(fn ($p) => $p
            // Los descartados no se listan: le interesa a quién tiene.
            ->has('candidatos', 1)
            ->where('candidatos.0.nombre', 'Ana Pérez')
            ->where('candidatos.0.etapa_label', 'Entrevista con el jefe'));

    // Y sigue sin poder entrar al tablero, donde están las hojas de vida.
    $this->actingAs($this->lider)->get("/app/rrhh/vacantes/{$this->vacante->id}")->assertStatus(403);
});

it('la elección del jefe queda escrita con nombre y hora', function () {
    // Hoy se decide por teléfono: ni Elena puede demostrar quién pidió a
    // quién, ni el jefe que ya respondió.
    $c = candidato(['etapa' => EtapaCandidato::Terna->value, 'antecedentes_revisados' => true]);

    $this->actingAs($this->lider)->post("/app/rrhh/candidatos/{$c->id}/elegir");

    expect($c->fresh()->etapa)->toBe(EtapaCandidato::Seleccionado)
        ->and($c->fresh()->elegido_por_id)->toBe($this->lider->id)
        ->and($c->fresh()->elegido_at)->not->toBeNull()
        ->and(NotificacionErp::where('user_id', $this->elena->id)->where('tipo', 'rrhh_terna')->exists())->toBeTrue();
});

it('un jefe de otra área no elige en un proceso ajeno', function () {
    $otro = User::factory()->create();
    $otro->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));
    $c = candidato(['etapa' => EtapaCandidato::Terna->value, 'antecedentes_revisados' => true]);

    $this->actingAs($otro)->post("/app/rrhh/candidatos/{$c->id}/elegir")->assertStatus(403);
});

it('sólo se elige entre los de la terna', function () {
    $c = candidato(['etapa' => EtapaCandidato::Pruebas->value, 'antecedentes_revisados' => true]);

    $this->actingAs($this->lider)->post("/app/rrhh/candidatos/{$c->id}/elegir")->assertStatus(422);
});

it('la hoja de vida sólo la sirve quien puede verla', function () {
    Storage::fake();
    $c = candidato(['cv_path' => 'rrhh/hojas-de-vida/1/hv.pdf']);
    Storage::put($c->cv_path, 'contenido');

    $this->actingAs($this->elena)->get("/app/rrhh/candidatos/{$c->id}/hoja-de-vida")->assertOk();
    $this->actingAs($this->lider)->get("/app/rrhh/candidatos/{$c->id}/hoja-de-vida")->assertStatus(403);
});
