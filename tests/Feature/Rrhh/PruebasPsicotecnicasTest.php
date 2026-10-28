<?php

/**
 * Fase C · Las pruebas psicotécnicas del proceso de selección.
 *
 * El área aplica MINIMULT y VALANTI y eso vivía entero por fuera: el tablero
 * tenía una etapa «Pruebas» que sólo decía que el candidato pasó por ahí. Ni
 * el concepto, ni quién lo firmó, ni el soporte.
 *
 * ── Lo que estas pruebas defienden ──────────────────────────────────────────
 *
 * La decisión de qué NO se guarda. Las respuestas de un MINIMULT son dato
 * sensible de salud mental (Ley 1581 de 2012, categoría especial) y los dos
 * instrumentos tienen derechos de autor. Para decidir una contratación la
 * empresa no necesita las 71 respuestas: necesita saber si el profesional
 * conceptúa apto. Guardar más de lo que se usa es asumir un riesgo a cambio
 * de nada, y una fuga de eso no se repara.
 *
 * Por eso hay una prueba que mira la forma de la tabla: el día que alguien
 * agregue una columna de respuestas, falla.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Rrhh\Enums\ConceptoPsicotecnico;
use App\Modules\Rrhh\Enums\InstrumentoPsicotecnico;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\PruebaPsicotecnica;
use App\Modules\Rrhh\Models\Vacante;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    // El jefe que pidió el personal NO maneja selección.
    $this->jorge = User::factory()->create(['name' => 'Jorge Líder']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));
});

function unCandidato(): Candidato
{
    static $n = 0;
    $n++;

    $v = Vacante::create([
        'titulo' => "Auxiliar de bodega {$n}", 'area' => 'Logística',
        'estado' => 'abierta', 'fuente' => 'caja_compensacion',
    ]);

    return Candidato::create([
        'vacante_id' => $v->id, 'nombre' => "Aspirante {$n}",
        'documento' => "asp{$n}", 'etapa' => 'pruebas',
    ]);
}

function registrarPrueba(Candidato $c, array $extra = [])
{
    return test()->actingAs(test()->elena)
        ->post("/app/rrhh/candidatos/{$c->id}/psicotecnica", array_merge([
            'instrumento' => InstrumentoPsicotecnico::Valanti->value,
            'fecha_aplicacion' => '2026-10-20',
            'profesional_nombre' => 'Diana Castro',
            'concepto' => ConceptoPsicotecnico::Apto->value,
        ], $extra));
}

// ── Lo que el sistema NO guarda ──────────────────────────────────────────────

it('no tiene dónde guardar las respuestas de la prueba', function () {
    /*
     * La guarda de la decisión entera. Si mañana alguien agrega una columna
     * para las respuestas, esta prueba lo frena y obliga a volver a discutirlo
     * —que es lo correcto: es una decisión legal, no de diseño—.
     */
    $columnas = Schema::getColumnListing('rrhh_pruebas_psicotecnicas');

    foreach (['respuestas', 'items', 'puntajes', 'escalas', 'resultados_crudos', 'perfil'] as $prohibida) {
        expect($columnas)->not->toContain($prohibida);
    }

    expect($columnas)->toContain('concepto')
        ->toContain('archivo_path')
        ->toContain('profesional_tarjeta');
});

it('ignora las respuestas aunque alguien las mande por el formulario', function () {
    $c = unCandidato();

    registrarPrueba($c, ['respuestas' => ['1' => 'V', '2' => 'F'], 'puntajes' => [60, 72]]);

    $p = PruebaPsicotecnica::where('candidato_id', $c->id)->firstOrFail();

    expect($p->getAttributes())->not->toHaveKey('respuestas')
        ->and($p->getAttributes())->not->toHaveKey('puntajes');
});

// ── El concepto ──────────────────────────────────────────────────────────────

it('guarda el concepto con quién lo firma', function () {
    $c = unCandidato();

    registrarPrueba($c, ['profesional_tarjeta' => 'PSI-45821'])->assertRedirect();

    $p = PruebaPsicotecnica::where('candidato_id', $c->id)->firstOrFail();

    expect($p->comoSeLlama())->toBe('VALANTI')
        ->and($p->concepto)->toBe(ConceptoPsicotecnico::Apto)
        ->and($p->quienFirma())->toBe('Diana Castro · T.P. PSI-45821')
        ->and($p->registrado_por_id)->toBe($this->elena->id);
});

it('exige tarjeta profesional para el MINIMULT y no para el VALANTI', function () {
    /*
     * El MINIMULT es instrumento clínico: la Ley 1090 de 2006 reserva su
     * aplicación a psicólogos con tarjeta. Sin ella el concepto no tiene
     * respaldo de nadie. El VALANTI mide valores, no salud.
     */
    $c = unCandidato();

    registrarPrueba($c, ['instrumento' => InstrumentoPsicotecnico::Minimult->value])
        ->assertSessionHasErrors('profesional_tarjeta');

    registrarPrueba($c, ['instrumento' => InstrumentoPsicotecnico::Valanti->value])
        ->assertSessionHasNoErrors();
});

it('no acepta un concepto que no sea «apto» sin decir por qué', function () {
    /*
     * Un «no apto» sin sustento no se le puede explicar a nadie —ni al
     * candidato, ni al jefe, ni a un juez—. Y unas observaciones vacías no son
     * observaciones.
     */
    $c = unCandidato();

    registrarPrueba($c, ['concepto' => ConceptoPsicotecnico::NoApto->value])
        ->assertSessionHasErrors('observaciones');

    registrarPrueba($c, ['concepto' => ConceptoPsicotecnico::AptoConObservaciones->value])
        ->assertSessionHasErrors('observaciones');

    registrarPrueba($c, [
        'concepto' => ConceptoPsicotecnico::NoApto->value,
        'observaciones' => 'No se ajusta al nivel de presión del cargo.',
    ])->assertSessionHasNoErrors();
});

it('«apto con observaciones» deja seguir el proceso y «no apto» no', function () {
    expect(ConceptoPsicotecnico::AptoConObservaciones->continuaElProceso())->toBeTrue()
        ->and(ConceptoPsicotecnico::Apto->continuaElProceso())->toBeTrue()
        ->and(ConceptoPsicotecnico::NoApto->continuaElProceso())->toBeFalse();
});

it('corrige la prueba en vez de dejar dos conceptos del mismo examen', function () {
    $c = unCandidato();

    registrarPrueba($c, ['concepto' => ConceptoPsicotecnico::Apto->value]);
    registrarPrueba($c, [
        'concepto' => ConceptoPsicotecnico::NoApto->value,
        'observaciones' => 'Se revisó con el profesional y se corrigió el concepto.',
    ]);

    expect(PruebaPsicotecnica::where('candidato_id', $c->id)->count())->toBe(1)
        ->and(PruebaPsicotecnica::where('candidato_id', $c->id)->first()->concepto)
        ->toBe(ConceptoPsicotecnico::NoApto);
});

it('deja registrar MINIMULT y VALANTI a la misma persona', function () {
    $c = unCandidato();

    registrarPrueba($c, ['instrumento' => InstrumentoPsicotecnico::Valanti->value]);
    registrarPrueba($c, [
        'instrumento' => InstrumentoPsicotecnico::Minimult->value,
        'profesional_tarjeta' => 'PSI-45821',
    ]);

    expect(PruebaPsicotecnica::where('candidato_id', $c->id)->count())->toBe(2);
});

it('pide el nombre cuando la prueba es «otra»', function () {
    $c = unCandidato();

    registrarPrueba($c, ['instrumento' => InstrumentoPsicotecnico::Otro->value])
        ->assertSessionHasErrors('instrumento_otro');

    registrarPrueba($c, [
        'instrumento' => InstrumentoPsicotecnico::Otro->value,
        'instrumento_otro' => 'Prueba técnica de alistamiento',
    ])->assertSessionHasNoErrors();

    expect(PruebaPsicotecnica::where('candidato_id', $c->id)->first()->comoSeLlama())
        ->toBe('Prueba técnica de alistamiento');
});

it('no acepta una prueba aplicada en el futuro', function () {
    $c = unCandidato();

    registrarPrueba($c, ['fecha_aplicacion' => now()->addWeek()->toDateString()])
        ->assertSessionHasErrors('fecha_aplicacion');
});

// ── El informe firmado ───────────────────────────────────────────────────────

it('guarda el informe en el disco privado, no en el público', function () {
    Storage::fake();

    $c = unCandidato();

    registrarPrueba($c, ['informe' => UploadedFile::fake()->create('informe.pdf', 200, 'application/pdf')]);

    $p = PruebaPsicotecnica::where('candidato_id', $c->id)->firstOrFail();

    expect($p->tieneSoporte())->toBeTrue()
        ->and($p->archivo_path)->toStartWith("rrhh/psicotecnicas/{$c->id}")
        // En el disco público bastaría con tener el enlace, y esto es un
        // informe psicológico.
        ->and($p->archivo_path)->not->toStartWith('public/');

    Storage::assertExists($p->archivo_path);
});

it('sólo acepta PDF como informe', function () {
    Storage::fake();

    $c = unCandidato();

    registrarPrueba($c, ['informe' => UploadedFile::fake()->image('foto.jpg')])
        ->assertSessionHasErrors('informe');
});

it('avisa qué falta sin frenar el registro', function () {
    /*
     * El concepto puede llegar por teléfono y el informe firmado un día
     * después. Exigirlo todo de una terminaría con la gente registrando la
     * prueba cuando ya no sirve para decidir.
     */
    $c = unCandidato();

    registrarPrueba($c)->assertSessionHasNoErrors();

    $p = PruebaPsicotecnica::where('candidato_id', $c->id)->firstOrFail();

    expect($p->loQueFalta())->toBe(['el informe firmado']);
});

it('deja anotado quién abrió el informe', function () {
    /*
     * No es desconfianza del área: con dato sensible de salud, «quién vio
     * esto» es una pregunta que se hace, y hay que poder responderla.
     */
    Storage::fake();

    $c = unCandidato();
    registrarPrueba($c, ['informe' => UploadedFile::fake()->create('informe.pdf', 100, 'application/pdf')]);

    $p = PruebaPsicotecnica::where('candidato_id', $c->id)->firstOrFail();

    $this->actingAs($this->elena)->get("/app/rrhh/psicotecnicas/{$p->id}/informe")->assertOk();

    expect($c->bitacora()->where('evento', 'psicotecnica_consultada')->first()?->descripcion)
        ->toContain('Elena');
});

it('devuelve 404 cuando la prueba no tiene informe', function () {
    $c = unCandidato();
    registrarPrueba($c);

    $p = PruebaPsicotecnica::where('candidato_id', $c->id)->firstOrFail();

    $this->actingAs($this->elena)->get("/app/rrhh/psicotecnicas/{$p->id}/informe")->assertNotFound();
});

// ── Quién puede ──────────────────────────────────────────────────────────────

it('el jefe del área no registra ni lee las psicotécnicas', function () {
    /*
     * El jefe decide a quién contrata y para eso le basta el concepto, que ve
     * en el tablero. El informe psicológico no es suyo.
     */
    Storage::fake();

    $c = unCandidato();
    registrarPrueba($c, ['informe' => UploadedFile::fake()->create('informe.pdf', 100, 'application/pdf')]);
    $p = PruebaPsicotecnica::where('candidato_id', $c->id)->firstOrFail();

    $this->actingAs($this->jorge)
        ->post("/app/rrhh/candidatos/{$c->id}/psicotecnica", [
            'instrumento' => InstrumentoPsicotecnico::Valanti->value,
            'fecha_aplicacion' => '2026-10-20',
            'profesional_nombre' => 'Quien sea',
            'concepto' => ConceptoPsicotecnico::Apto->value,
        ])->assertForbidden();

    $this->actingAs($this->jorge)->get("/app/rrhh/psicotecnicas/{$p->id}/informe")->assertForbidden();
});

it('el impreso del proceso lleva el concepto, nunca el informe', function () {
    /*
     * Este PDF se imprime y circula: es el sustento de por qué se eligió a
     * quien se eligió. Un informe psicológico no circula —queda en el
     * sistema, bajo permiso y con rastro de quién lo abrió—.
     */
    Storage::fake();

    $c = unCandidato();
    registrarPrueba($c, [
        'instrumento' => InstrumentoPsicotecnico::Minimult->value,
        'profesional_tarjeta' => 'PSI-45821',
        'concepto' => ConceptoPsicotecnico::AptoConObservaciones->value,
        'observaciones' => 'Conviene acompañarlo las primeras semanas.',
        'informe' => UploadedFile::fake()->create('informe.pdf', 100, 'application/pdf'),
    ]);

    $p = PruebaPsicotecnica::where('candidato_id', $c->id)->firstOrFail();

    $html = view('rrhh.pdf.proceso-candidato', [
        'c' => $c->load('pruebasPsicotecnicas', 'vacante'),
        'fuente' => null, 'orden' => null,
        'bitacora' => $c->bitacora()->with('usuario:id,name')->get(),
        'generadoPor' => 'Elena',
    ])->render();

    expect($html)
        ->toContain('MINIMULT')
        ->toContain('Apto con observaciones')
        ->toContain('Diana Castro · T.P. PSI-45821')
        ->toContain('Conviene acompañarlo las primeras semanas.')
        // Ni la ruta del archivo ni un enlace al informe.
        ->not->toContain($p->archivo_path)
        ->not->toContain('/informe');
});

it('lo que llega al jefe es el concepto, no el informe', function () {
    $c = unCandidato();
    registrarPrueba($c, [
        'concepto' => ConceptoPsicotecnico::AptoConObservaciones->value,
        'observaciones' => 'Conviene acompañarlo las primeras semanas.',
    ]);

    $resumen = PruebaPsicotecnica::where('candidato_id', $c->id)->firstOrFail()->paraElJefe();

    expect($resumen)->toHaveKeys(['prueba', 'concepto', 'observaciones', 'continua'])
        ->and($resumen)->not->toHaveKey('archivo_path')
        ->and($resumen['continua'])->toBeTrue();
});
