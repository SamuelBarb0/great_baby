<?php

/**
 * F0 de Gestión Humana · los cimientos sobre los que se apoya todo el módulo.
 *
 * Son tres cosas que las nueve tarjetas del panel dan por hechas:
 *
 *   · El consecutivo de requisiciones, que no puede repetirse nunca — es el
 *     número con el que el líder va a preguntar «¿en qué va la RQ-2026-0007?».
 *   · La bitácora, que se escribe sola al cambiar de estado. Elena lo pidió
 *     textual: que el jefe abra el móvil y vea en qué etapa van sus candidatos
 *     sin preguntarle a nadie. Si anotar fuera un paso aparte, alguien lo
 *     olvidaría y el rastro valdría poco.
 *   · Los roles: un líder de área pide personal, pero NO entra a Gestión
 *     Humana, donde se ven salarios y hojas de vida de toda la empresa.
 */

use App\Models\User;
use App\Modules\Rrhh\Enums\EstadoRequisicion;
use App\Modules\Rrhh\Enums\EtapaCandidato;
use App\Modules\Rrhh\Enums\ValoracionCandidato;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\Requisicion;
use App\Modules\Rrhh\Models\Vacante;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Con `RefreshDatabase` la base de pruebas nace vacía: el rol se crea pero
    // sin permisos, y entonces «el líder no entra a Gestión Humana» pasaría
    // por la razón equivocada —no entra a NADA—. Se siembran de verdad.
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->lider = User::factory()->create();
    $this->lider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));
    $this->actingAs($this->lider);
});

function requisicion(array $extra = []): Requisicion
{
    return Requisicion::create(array_merge([
        'numero' => Requisicion::siguienteNumero(),
        'cargo' => 'Auxiliar de logística',
        'area' => 'Logística',
        'numero_vacantes' => 3,
        'solicitante_id' => User::first()->id,
        'estado' => EstadoRequisicion::Borrador->value,
    ], $extra));
}

it('el consecutivo arranca en uno y no se repite', function () {
    expect(Requisicion::siguienteNumero())->toBe('RQ-'.now()->year.'-0001');

    requisicion();

    expect(Requisicion::siguienteNumero())->toBe('RQ-'.now()->year.'-0002');
});

it('borrar una requisición no hace que el número se repita', function () {
    /*
     * Si el consecutivo se calculara contando filas, borrar la última haría
     * que la siguiente naciera con un número ya usado. Se calcula sobre el
     * máximo, incluidas las borradas.
     */
    $r = requisicion();
    $segunda = requisicion();
    $segunda->delete();

    expect(Requisicion::siguienteNumero())->toBe('RQ-'.now()->year.'-0003');
});

it('cambiar de estado deja su línea en la bitácora, sin pedirlo', function () {
    $r = requisicion();

    $r->cambiarEstado(EstadoRequisicion::Enviada->value, 'El líder la envió');

    expect($r->bitacora()->count())->toBe(1)
        ->and($r->bitacora()->first()->de_estado)->toBe('borrador')
        ->and($r->bitacora()->first()->a_estado)->toBe('enviada')
        ->and($r->bitacora()->first()->user_id)->toBe($this->lider->id);
});

it('el estado casteado a enum no rompe la bitácora', function () {
    // `estado` está casteado a enum, y un enum no se vuelve texto solo. El
    // primer intento reventó con «could not be converted to string».
    $r = requisicion();

    $r->cambiarEstado(EstadoRequisicion::Enviada->value);

    expect($r->fresh()->estado)->toBe(EstadoRequisicion::Enviada);
});

it('un clic repetido no ensucia la bitácora', function () {
    $r = requisicion(['estado' => EstadoRequisicion::Enviada->value]);

    $repetido = $r->cambiarEstado(EstadoRequisicion::Enviada->value, 'mismo estado');

    expect($repetido)->toBeFalse()
        ->and($r->bitacora()->count())->toBe(0);
});

it('sólo una requisición aprobada permite abrir la vacante', function () {
    // Es el control que hoy se pierde con «la hojita» que a veces llega.
    expect(EstadoRequisicion::Aprobada->permiteVacante())->toBeTrue()
        ->and(EstadoRequisicion::Enviada->permiteVacante())->toBeFalse()
        ->and(EstadoRequisicion::Borrador->permiteVacante())->toBeFalse()
        ->and(EstadoRequisicion::Rechazada->permiteVacante())->toBeFalse();
});

it('el líder sólo ve sus propias solicitudes', function () {
    $otro = User::factory()->create();
    requisicion(['solicitante_id' => $this->lider->id, 'cargo' => 'La mía']);
    requisicion(['solicitante_id' => $otro->id, 'cargo' => 'La de otra área']);

    $suyas = Requisicion::deSolicitante($this->lider->id)->pluck('cargo');

    expect($suyas)->toContain('La mía')
        ->and($suyas)->not->toContain('La de otra área');
});

it('el candidato se mueve de etapa dejando rastro', function () {
    $v = Vacante::create(['titulo' => 'Auxiliar', 'area' => 'Logística', 'estado' => 'abierta']);
    $c = Candidato::create(['vacante_id' => $v->id, 'nombre' => 'Ana', 'etapa' => EtapaCandidato::Recibido->value]);

    $c->moverA(EtapaCandidato::EntrevistaJefe, 'Pasó el filtro inicial');

    expect($c->fresh()->etapa)->toBe(EtapaCandidato::EntrevistaJefe)
        ->and($c->bitacora()->first()->a_estado)->toBe('entrevista_jefe');
});

it('el tablero separa las dos entrevistas', function () {
    /*
     * El enum que traía la tabla colapsaba en una sola «entrevista» la del
     * jefe inmediato y la de psicología. Son dos momentos distintos, con
     * responsables y formatos distintos, y el jefe necesita ver en cuál va.
     */
    $etapas = collect(EtapaCandidato::delTablero())->map(fn ($e) => $e->value);

    expect($etapas)->toContain('entrevista_jefe')
        ->and($etapas)->toContain('entrevista_psicologia')
        // Descartado no es un paso del camino: es una salida.
        ->and($etapas)->not->toContain('descartado');
});

it('una valoración con reparos exige explicarla', function () {
    // «Apto con restricción» sin decir cuál no le sirve al jefe que elige.
    expect(ValoracionCandidato::AptoConRestriccion->exigeObservacion())->toBeTrue()
        ->and(ValoracionCandidato::NoApto->exigeObservacion())->toBeTrue()
        ->and(ValoracionCandidato::Apto->exigeObservacion())->toBeFalse();
});

it('el líder de área no entra a Gestión Humana', function () {
    // Ahí se ven salarios y hojas de vida de toda la empresa.
    expect($this->lider->can('ver.rrhh_requisiciones'))->toBeTrue()
        ->and($this->lider->can('ver.rrhh'))->toBeFalse()
        ->and($this->lider->can('ver.rrhh_seleccion'))->toBeFalse();
});
