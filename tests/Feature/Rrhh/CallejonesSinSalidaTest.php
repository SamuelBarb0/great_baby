<?php

/**
 * Los dos callejones sin salida de selección.
 *
 * No son errores de cálculo: son caminos que el sistema abre y después no
 * tiene cómo cerrar. Los dos salieron del mismo sitio —la negativa de la
 * Junta— y los dos dejaban un registro vivo para siempre.
 *
 *   1 · Una vacante abierta lo quedaba para siempre. La columna `estado`
 *       admitía `cerrada` desde el principio y hasta había un color
 *       preparado en la pantalla, pero no existía ruta ni botón que lo
 *       escribiera.
 *
 *   2 · Un candidato con la orden negada no se podía contratar —la orden no
 *       autoriza—, ni descartar —`esFinal()` lo daba por fuera del proceso—,
 *       ni volver a presentar a la Junta —el índice único de `candidato_id`
 *       no dejaba crear la segunda orden, y `solicitar()` igual respondía
 *       «enviada a la Junta Directiva» sobre algo que nunca salió.
 *
 * El propio módulo da por supuesto el camino que no existía: pide motivo al
 * negar «para que Gestión Humana sepa si insistir, bajar el salario o cerrar
 * la vacante». Las tres estaban cerradas.
 */

use App\Models\User;
use App\Modules\Rrhh\Enums\EstadoRequisicion;
use App\Modules\Rrhh\Enums\EtapaCandidato;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\Contratacion;
use App\Modules\Rrhh\Models\OrdenContratacion;
use App\Modules\Rrhh\Models\Requisicion;
use App\Modules\Rrhh\Models\Vacante;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->jefa = User::factory()->create(['name' => 'Elena']);
    $this->jefa->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    // La Junta es el rol `Gerente`. Con RefreshDatabase el rol nace vacío
    // porque el seeder reparte entre los que ya existen, así que se le dan
    // sus permisos a mano.
    $rolGerente = Role::firstOrCreate(['name' => 'Gerente', 'guard_name' => 'web']);
    $rolGerente->syncPermissions(collect(App\Auth\Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array('Gerente', $roles, true))
        ->keys()->flatMap(fn ($s) => App\Auth\Permisos::permisosDe($s))->all());

    $this->junta = User::factory()->create(['name' => 'Junta']);
    $this->junta->assignRole($rolGerente);

    $this->lider = User::factory()->create(['name' => 'Jorge']);
    $this->lider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));

    $this->solicitud = Requisicion::create([
        'numero' => Requisicion::siguienteNumero(),
        'cargo' => 'Auxiliar de bodega',
        'area' => 'Logística',
        'numero_vacantes' => 1,
        'salario_min' => 1_500_000,
        'solicitante_id' => $this->lider->id,
        'estado' => EstadoRequisicion::Aprobada->value,
    ]);

    $this->puesto = Vacante::create([
        'requisicion_id' => $this->solicitud->id,
        'titulo' => 'Auxiliar de bodega',
        'area' => 'Logística',
        'tipo_contrato' => 'indefinido',
        'modalidad' => 'presencial',
        'estado' => 'abierta',
        'fecha_apertura' => now()->toDateString(),
    ]);

    $this->aspirante = Candidato::create([
        'vacante_id' => $this->puesto->id,
        'nombre' => 'Ana Pérez',
        'documento' => '1098765432',
        'etapa' => EtapaCandidato::Seleccionado->value,
        'antecedentes_revisados' => true,
        'elegido_at' => now(),
        'elegido_por_id' => $this->lider->id,
    ]);
});

/** La Junta niega la orden que Gestión Humana acaba de pedir. */
function laJuntaNiega(object $caso): OrdenContratacion
{
    $caso->actingAs($caso->jefa)->post("/app/rrhh/candidatos/{$caso->aspirante->id}/orden");

    $o = OrdenContratacion::where('candidato_id', $caso->aspirante->id)->orderByDesc('id')->firstOrFail();

    $caso->actingAs($caso->junta)->post("/app/rrhh/ordenes/{$o->id}/negar", [
        'motivo' => 'El presupuesto del área no da para este salario.',
    ])->assertRedirect();

    return $o->fresh();
}

// ───────────────────── 1 · La vacante que no cerraba ─────────────────────

it('la vacante se puede cerrar, y queda dicho por qué', function () {
    expect($this->puesto->estado)->toBe('abierta');

    $this->actingAs($this->jefa)
        ->post("/app/rrhh/vacantes/{$this->puesto->id}/cerrar", [
            'motivo' => 'El cargo se canceló por recorte de presupuesto.',
        ])->assertRedirect();

    $v = $this->puesto->fresh();

    expect($v->estado)->toBe('cerrada')
        ->and($v->fecha_cierre?->toDateString())->toBe(now()->toDateString())
        // El motivo no se pierde: dentro de seis meses «cerrada» a secas no
        // distingue entre se contrató a alguien y se canceló el cargo.
        ->and($v->bitacora()->where('evento', 'cerrada')->value('descripcion'))
            ->toContain('recorte de presupuesto');
});

it('cerrar sin decir por qué no se acepta', function () {
    $this->actingAs($this->jefa)
        ->post("/app/rrhh/vacantes/{$this->puesto->id}/cerrar", ['motivo' => ''])
        ->assertSessionHasErrors('motivo');

    expect($this->puesto->fresh()->estado)->toBe('abierta');
});

it('una vacante cerrada no recibe más hojas de vida', function () {
    $this->actingAs($this->jefa)->post("/app/rrhh/vacantes/{$this->puesto->id}/cerrar", [
        'motivo' => 'Ya se contrató a alguien.',
    ]);

    // Cerrarla no serviría de nada si se le siguieran cargando candidatos:
    // la persona quedaría esperando una llamada de un proceso terminado.
    $this->actingAs($this->jefa)
        ->post("/app/rrhh/vacantes/{$this->puesto->id}/candidatos", ['nombre' => 'Quien llegó tarde'])
        ->assertStatus(422);

    expect(Candidato::where('nombre', 'Quien llegó tarde')->exists())->toBeFalse();
});

it('cerrarla por error se deshace', function () {
    $this->actingAs($this->jefa)->post("/app/rrhh/vacantes/{$this->puesto->id}/cerrar", [
        'motivo' => 'Me equivoqué de fila.',
    ]);

    $this->actingAs($this->jefa)
        ->post("/app/rrhh/vacantes/{$this->puesto->id}/reabrir")->assertRedirect();

    $v = $this->puesto->fresh();

    // Sin esto, cerrar sería el mismo callejón que se vino a quitar: un clic
    // de más y había que crear otra vacante perdiendo todos los candidatos.
    expect($v->estado)->toBe('abierta')
        ->and($v->fecha_cierre)->toBeNull();
});

// ──────────────── 2 · El candidato que quedaba congelado ────────────────

it('después de una negativa se puede volver a la Junta con otra orden', function () {
    $negada = laJuntaNiega($this);

    expect($negada->estado)->toBe('negada');

    // Ésta es la que no se podía: el índice único de `candidato_id` lo
    // impedía y el método respondía «enviada» sin crear ni avisar nada.
    $this->actingAs($this->jefa)
        ->post("/app/rrhh/candidatos/{$this->aspirante->id}/orden", ['salario_propuesto' => 1_300_000])
        ->assertRedirect();

    $ordenes = OrdenContratacion::where('candidato_id', $this->aspirante->id)
        ->orderBy('id')->get();

    expect($ordenes)->toHaveCount(2)
        ->and($ordenes[0]->estado)->toBe('negada')
        ->and($ordenes[1]->estado)->toBe('pendiente')
        // Cada presentación es su propio documento con su propio consecutivo:
        // lo que la Junta firmó no se reescribe encima.
        ->and($ordenes[1]->numero)->not->toBe($ordenes[0]->numero)
        ->and((float) $ordenes[1]->salario_propuesto)->toBe(1_300_000.0);
});

it('mientras la orden esté esperando, pedirla otra vez no duplica nada', function () {
    $this->actingAs($this->jefa)->post("/app/rrhh/candidatos/{$this->aspirante->id}/orden");
    $this->actingAs($this->jefa)->post("/app/rrhh/candidatos/{$this->aspirante->id}/orden");

    expect(OrdenContratacion::where('candidato_id', $this->aspirante->id)->count())->toBe(1);
});

it('contratar lee la ÚLTIMA orden, no la primera', function () {
    laJuntaNiega($this);

    $this->actingAs($this->jefa)->post("/app/rrhh/candidatos/{$this->aspirante->id}/orden");
    $segunda = OrdenContratacion::where('candidato_id', $this->aspirante->id)->orderByDesc('id')->first();

    $this->actingAs($this->junta)->post("/app/rrhh/ordenes/{$segunda->id}/autorizar")->assertRedirect();

    // Con `first()` se leería siempre la negada de octubre y quien fue
    // autorizado en noviembre seguiría sin poder contratarse.
    $this->actingAs($this->jefa)
        ->post("/app/rrhh/candidatos/{$this->aspirante->id}/contratar")
        ->assertRedirect();

    expect(Contratacion::where('candidato_id', $this->aspirante->id)->exists())->toBeTrue();
});

it('a quien la Junta no autorizó se le puede cerrar el proceso', function () {
    laJuntaNiega($this);

    // Antes: ni contratable —la orden no autoriza— ni descartable —`esFinal()`
    // metía `seleccionado` en la misma bolsa que `descartado`—. La persona
    // quedaba ocupando el puesto de elegido de una vacante que seguía abierta.
    $this->actingAs($this->jefa)
        ->post("/app/rrhh/candidatos/{$this->aspirante->id}/descartar", [
            'motivo' => 'La Junta no autorizó el salario y no acepta menos.',
        ])->assertRedirect();

    expect($this->aspirante->fresh()->etapa)->toBe(EtapaCandidato::Descartado);
});

it('a quien ya tiene contratación abierta no se le descarta desde selección', function () {
    $this->actingAs($this->jefa)->post("/app/rrhh/candidatos/{$this->aspirante->id}/orden");
    $o = OrdenContratacion::where('candidato_id', $this->aspirante->id)->first();
    $this->actingAs($this->junta)->post("/app/rrhh/ordenes/{$o->id}/autorizar");
    $this->actingAs($this->jefa)->post("/app/rrhh/candidatos/{$this->aspirante->id}/contratar");

    // Abrir el descarte no puede volverse una puerta de atrás para deshacer
    // un contrato en curso.
    $this->actingAs($this->jefa)
        ->post("/app/rrhh/candidatos/{$this->aspirante->id}/descartar", ['motivo' => 'Cambié de idea.'])
        ->assertStatus(422);

    expect($this->aspirante->fresh()->etapa)->toBe(EtapaCandidato::Seleccionado);
});

it('descartar dos veces a la misma persona no pasa', function () {
    laJuntaNiega($this);

    $this->actingAs($this->jefa)->post("/app/rrhh/candidatos/{$this->aspirante->id}/descartar", [
        'motivo' => 'No acepta el salario que autorizan.',
    ]);

    $this->actingAs($this->jefa)
        ->post("/app/rrhh/candidatos/{$this->aspirante->id}/descartar", ['motivo' => 'Otra vez.'])
        ->assertStatus(422);
});
