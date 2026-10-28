<?php

/**
 * Fase C · Las dos entrevistas del proceso de selección.
 *
 * Formatos 3 y 4 del área. El tablero tenía las dos etapas —«entrevista con
 * el jefe» y «entrevista de psicología»— y movía la tarjeta de una a otra sin
 * guardar una sola palabra de lo que se preguntó.
 *
 * El daño no es que falte información: es que la terna llega al jefe que pidió
 * el personal con tres nombres y una calificación, y él elige sin ver por qué
 * alguien valoró así. Y si tres meses después preguntan por qué se contrató a
 * esta persona y no a la otra, la respuesta no existe en ninguna parte.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\EntrevistaCandidato;
use App\Modules\Rrhh\Models\Vacante;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);
    $this->seed(Database\Seeders\FormatosControladosSeeder::class);

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->jorge = User::factory()->create(['name' => 'Jorge Líder']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));
});

function unAspirante(): Candidato
{
    static $n = 0;
    $n++;

    $v = Vacante::create([
        'titulo' => "Auxiliar {$n}", 'area' => 'Logística',
        'estado' => 'abierta', 'fuente' => 'caja_compensacion',
    ]);

    return Candidato::create([
        'vacante_id' => $v->id, 'nombre' => "Aspirante {$n}",
        'documento' => "ent{$n}", 'etapa' => 'entrevista_jefe',
    ]);
}

function entrevistarAspirante(Candidato $c, array $datos = [])
{
    return test()->actingAs(test()->elena)
        ->post("/app/rrhh/candidatos/{$c->id}/entrevista", array_merge([
            'tipo' => EntrevistaCandidato::SABER_HACER,
            'fecha' => '2026-10-20',
            'entrevistador_nombre' => 'Elena Gestión Humana',
        ], $datos));
}

// ── Lo que se preguntó queda escrito ─────────────────────────────────────────

it('guarda las respuestas de la entrevista de saber hacer', function () {
    $c = unAspirante();

    entrevistarAspirante($c, [
        'respuestas' => [
            'g1' => 'Sí, compró pañales de la marca.',
            'e10' => 'Un millón seiscientos.',
        ],
    ])->assertRedirect();

    $e = EntrevistaCandidato::where('candidato_id', $c->id)->firstOrFail();

    expect($e->respuesta('g1'))->toBe('Sí, compró pañales de la marca.')
        ->and($e->respuesta('e10'))->toBe('Un millón seiscientos.')
        ->and($e->respuesta('e1'))->toBeNull();
});

it('avisa cuántas preguntas quedaron sin contestar, sin obligarlas', function () {
    /*
     * Una entrevista es una conversación, no un formulario: hay preguntas que
     * no se alcanzan a hacer. Obligarlas llevaría a llenarlas con cualquier
     * cosa, que es peor que dejarlas vacías.
     */
    $c = unAspirante();

    entrevistarAspirante($c, ['respuestas' => ['g1' => 'Sí.', 'a1' => 'Bachiller.']])
        ->assertSessionHasNoErrors();

    // Quince preguntas en total (2 + 3 + 10), dos contestadas.
    expect(EntrevistaCandidato::where('candidato_id', $c->id)->first()->sinContestar())->toBe(13);
});

it('no guarda las respuestas en blanco', function () {
    $c = unAspirante();

    entrevistarAspirante($c, ['respuestas' => ['g1' => 'Sí.', 'g2' => '', 'a1' => '   ']]);

    expect(EntrevistaCandidato::where('candidato_id', $c->id)->first()->respuestas)
        ->toHaveKey('g1')
        ->not->toHaveKey('g2')
        ->not->toHaveKey('a1');
});

// ── La evaluación ────────────────────────────────────────────────────────────

it('suma el total de los seis ítems en el servidor', function () {
    /*
     * El total se calcula acá y no se cree el que manda el cliente: si
     * alguien corrige un ítem y el total viniera de la pantalla, el papel
     * saldría con un número que no suma.
     */
    $c = unAspirante();

    entrevistarAspirante($c, [
        'calificaciones' => ['estudios' => 5, 'estabilidad' => 4, 'potencial' => 4,
            'ajuste' => 5, 'salario' => 3, 'interes' => 5],
        // Un total mentido a propósito.
        'total' => 99,
    ]);

    $e = EntrevistaCandidato::where('candidato_id', $c->id)->firstOrFail();

    expect($e->total)->toBe(26)
        ->and($e->totalCalculado())->toBe(26);
});

it('no acepta una calificación fuera de uno a cinco', function () {
    /*
     * Un 0 o un 6 no son «una nota rara»: son un error de digitación que
     * después se promedia con las demás y ensucia la comparación.
     */
    $c = unAspirante();

    entrevistarAspirante($c, ['calificaciones' => ['estudios' => 7]])
        ->assertSessionHasErrors('calificaciones.estudios');

    entrevistarAspirante($c, ['calificaciones' => ['estudios' => 0]])
        ->assertSessionHasErrors('calificaciones.estudios');
});

it('el total queda en nulo mientras no haya calificado nada', function () {
    $c = unAspirante();

    entrevistarAspirante($c, ['respuestas' => ['g1' => 'Sí.']]);

    expect(EntrevistaCandidato::where('candidato_id', $c->id)->first()->total)->toBeNull();
});

// ── La psicológica ───────────────────────────────────────────────────────────

it('guarda las tres tablas que crecen', function () {
    $c = unAspirante();

    entrevistarAspirante($c, [
        'tipo' => EntrevistaCandidato::PSICOLOGICA,
        'respuestas' => [
            'familia_filas' => [
                ['nombre' => 'Rosa Pérez', 'edad' => '58', 'parentesco' => 'Madre',
                    'escolaridad' => 'Bachiller', 'ocupacion' => 'Comerciante'],
                ['nombre' => '', 'edad' => '', 'parentesco' => ''],  // fila en blanco
            ],
            'laboral_filas' => [
                ['empresa' => 'Distribuidora X', 'cargo' => 'Auxiliar', 'tiempo' => '2 años',
                    'motivo_retiro' => 'Cierre de la bodega'],
            ],
            'autoconcepto' => 'Se describe como organizada.',
        ],
    ])->assertRedirect();

    $e = EntrevistaCandidato::where('candidato_id', $c->id)->firstOrFail();

    expect($e->filasDe('familia'))->toHaveCount(1)      // la vacía se descartó
        ->and($e->filasDe('familia')[0]['nombre'])->toBe('Rosa Pérez')
        ->and($e->filasDe('laboral')[0]['motivo_retiro'])->toBe('Cierre de la bodega')
        ->and($e->respuesta('autoconcepto'))->toBe('Se describe como organizada.');
});

it('la psicológica no cuenta preguntas sin contestar', function () {
    // Sus bloques son abiertos: no hay un cuestionario fijo que completar.
    $c = unAspirante();

    entrevistarAspirante($c, ['tipo' => EntrevistaCandidato::PSICOLOGICA]);

    expect(EntrevistaCandidato::where('candidato_id', $c->id)->first()->sinContestar())->toBe(0);
});

// ── Una de cada tipo ─────────────────────────────────────────────────────────

it('corrige la entrevista en vez de dejar dos versiones', function () {
    $c = unAspirante();

    entrevistarAspirante($c, ['respuestas' => ['g1' => 'Primera versión.']]);
    entrevistarAspirante($c, ['respuestas' => ['g1' => 'Corregido en la revisión.']]);

    expect(EntrevistaCandidato::where('candidato_id', $c->id)->count())->toBe(1)
        ->and(EntrevistaCandidato::where('candidato_id', $c->id)->first()->respuesta('g1'))
        ->toBe('Corregido en la revisión.');
});

it('deja hacerle las dos entrevistas a la misma persona', function () {
    $c = unAspirante();

    entrevistarAspirante($c);
    entrevistarAspirante($c, ['tipo' => EntrevistaCandidato::PSICOLOGICA]);

    expect(EntrevistaCandidato::where('candidato_id', $c->id)->count())->toBe(2);
});

it('no acepta una entrevista con fecha futura ni un tipo inventado', function () {
    $c = unAspirante();

    entrevistarAspirante($c, ['fecha' => now()->addWeek()->toDateString()])
        ->assertSessionHasErrors('fecha');

    entrevistarAspirante($c, ['tipo' => 'entrevista_inventada'])
        ->assertSessionHasErrors('tipo');
});

it('pide saber quién entrevistó', function () {
    $c = unAspirante();

    entrevistarAspirante($c, ['entrevistador_nombre' => ''])
        ->assertSessionHasErrors('entrevistador_nombre');
});

// ── Quién puede ──────────────────────────────────────────────────────────────

it('las entrevistas son del proceso de selección', function () {
    $c = unAspirante();

    $this->actingAs($this->jorge)
        ->post("/app/rrhh/candidatos/{$c->id}/entrevista", [
            'tipo' => EntrevistaCandidato::SABER_HACER,
            'fecha' => '2026-10-20',
            'entrevistador_nombre' => 'Quien sea',
        ])->assertForbidden();
});

// ── Los impresos ─────────────────────────────────────────────────────────────

it('el impreso de saber hacer trae las preguntas aunque no se contesten', function () {
    /*
     * Es el guion de la entrevista: sirve para imprimirlo antes y llenarlo a
     * mano, y después para imprimirlo con lo que se registró.
     */
    $c = unAspirante();

    entrevistarAspirante($c, [
        'respuestas' => ['g1' => 'Sí, compró pañales.'],
        'calificaciones' => ['estudios' => 5, 'estabilidad' => 4, 'potencial' => 4,
            'ajuste' => 5, 'salario' => 3, 'interes' => 5],
    ]);

    $e = EntrevistaCandidato::where('candidato_id', $c->id)->firstOrFail();

    $this->actingAs($this->elena)->get("/app/rrhh/entrevistas/{$e->id}/pdf")->assertOk();

    $html = view('rrhh.pdf.entrevista-saber-hacer', [
        'e' => $e, 'c' => $c->load('vacante'),
        'empresa' => App\Models\EmpresaConfig::current(), 'generadoPor' => 'Elena',
    ])->render();

    expect($html)
        ->toContain('FORMATO ENTREVISTA DE SABER HACER')
        ->toContain('¿Cuál es su aspiración salarial?')   // una que no se contestó
        ->toContain('Sí, compró pañales.')
        ->toContain('Sin respuesta registrada')
        ->toContain('26 de 30')
        // Todavía sin código: no se le inventa uno.
        ->toContain('sin asignar');
});

it('el impreso de la psicológica lleva el código del formato y el aviso de ley', function () {
    $c = unAspirante();

    entrevistarAspirante($c, [
        'tipo' => EntrevistaCandidato::PSICOLOGICA,
        'respuestas' => [
            'laboral_filas' => [['empresa' => 'Distribuidora X', 'cargo' => 'Auxiliar',
                'tiempo' => '2 años', 'motivo_retiro' => 'Cierre de la bodega']],
        ],
    ]);

    $e = EntrevistaCandidato::where('candidato_id', $c->id)->firstOrFail();

    $html = view('rrhh.pdf.entrevista-psicologica', [
        'e' => $e, 'c' => $c->load('vacante'),
        'empresa' => App\Models\EmpresaConfig::current(), 'generadoPor' => 'Elena',
    ])->render();

    expect($html)
        ->toContain('GH-F-004')
        // El motivo de retiro de cada empleo es lo que más valor tiene del
        // formato: tres salidas por lo mismo dicen más que cualquier respuesta.
        ->toContain('Motivo del retiro')
        ->toContain('Cierre de la bodega')
        // Lleva datos de la familia de alguien que ni siquiera trabaja acá.
        ->toContain('Ley 1581 de 2012');
});
