<?php

/**
 * F8 · La inducción, con temario.
 *
 * El ERP ya registraba que la inducción se dio, quién la dio y cuándo, y los
 * temas en un campo de texto libre. El resultado estaba a la vista: de siete
 * fichas, una sola tenía temas escritos. Un campo que nadie llena no es un
 * registro, es una casilla.
 *
 * Y lo que se pierde ahí no es información: la inducción es lo que la empresa
 * tiene para demostrar que le explicó a alguien cómo se trabaja y qué riesgos
 * corre. Si pasa algo y lo único que hay es «se le dio la inducción», no hay
 * con qué responder qué se le explicó.
 */

use App\Auth\Permisos;
use App\Models\ReglaNegocio;
use App\Models\User;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Induccion;
use App\Support\Reglas;
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

function reciénEntrado(array $extra = []): Empleado
{
    static $n = 0;
    $n++;

    return Empleado::create(array_merge([
        'nombre' => "Nuevo {$n}", 'tipo_documento' => 'CC', 'numero_documento' => "ind{$n}",
        'cargo' => 'Auxiliar de bodega', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 1_500_000, 'fecha_ingreso' => '2026-10-01',
        'estado' => 'activo', 'estado_induccion' => 'pendiente',
    ], $extra));
}

/** Marca los primeros `$cuantos` temas del temario vigente como vistos. */
function temarioCon(int $cuantos): array
{
    $temas = Induccion::temarioEnBlanco();

    foreach ($temas as $i => $t) {
        $temas[$i]['cubierto'] = $i < $cuantos;
    }

    return $temas;
}

function darInduccion(Empleado $e, array $extra = [])
{
    return test()->actingAs(test()->elena)
        ->post("/app/rrhh/empleados/{$e->id}/induccion-temario", array_merge([
            'fecha' => '2026-10-05',
            'responsable_nombre' => 'Elena Gestión Humana',
            'temas' => temarioCon(count(Induccion::temarioVigente())),
        ], $extra));
}

// ── El temario ───────────────────────────────────────────────────────────────

it('el temario sale de una regla que el área puede editar', function () {
    /*
     * Agregar «manejo del escáner» no debería esperar a un programador.
     */
    expect(Induccion::temarioVigente())->not->toBeEmpty();

    ReglaNegocio::updateOrCreate(
        ['clave' => 'rrhh.temario_induccion'],
        ['valor' => 'Reglamento|Riesgos del puesto|Manejo del escáner',
            'tipo' => 'string', 'grupo' => 'rrhh', 'etiqueta' => 'Temario'],
    );
    Reglas::limpiarRequestCache();

    expect(Induccion::temarioVigente())->toBe(['Reglamento', 'Riesgos del puesto', 'Manejo del escáner']);
});

it('cada inducción guarda SU temario y no el de hoy', function () {
    /*
     * Lo que evita que las inducciones del año pasado aparezcan incompletas
     * por un tema que no existía cuando se dieron.
     */
    ReglaNegocio::updateOrCreate(
        ['clave' => 'rrhh.temario_induccion'],
        ['valor' => 'Reglamento|Riesgos del puesto', 'tipo' => 'string', 'grupo' => 'rrhh', 'etiqueta' => 'T'],
    );
    Reglas::limpiarRequestCache();

    $e = reciénEntrado();
    darInduccion($e);

    expect(Induccion::where('empleado_id', $e->id)->first()->completa())->toBeTrue();

    // El área agrega un tema DESPUÉS.
    ReglaNegocio::where('clave', 'rrhh.temario_induccion')
        ->update(['valor' => 'Reglamento|Riesgos del puesto|Manejo del escáner']);
    Reglas::limpiarRequestCache();

    $i = Induccion::where('empleado_id', $e->id)->first();

    expect($i->temario())->toHaveCount(2)
        ->and($i->completa())->toBeTrue();
});

// ── Lo que se vio y lo que no ────────────────────────────────────────────────

it('cuenta los temas vistos y nombra los que faltan', function () {
    $e = reciénEntrado();

    darInduccion($e, ['temas' => temarioCon(3)])->assertRedirect();

    $i = Induccion::where('empleado_id', $e->id)->firstOrFail();

    expect($i->cubiertos())->toBe(3)
        ->and($i->completa())->toBeFalse()
        ->and($i->pendientes())->not->toBeEmpty();
});

it('deja guardarla a medias, porque una inducción se termina al otro día', function () {
    /*
     * Frenarla haría que nadie la registre hasta el final, y entonces el día
     * que hace falta no existe. Lo que no puede pasar es que nadie sepa qué
     * quedó pendiente.
     */
    $e = reciénEntrado();

    darInduccion($e, ['temas' => temarioCon(1)])->assertSessionHasNoErrors();

    expect($e->fresh()->estado_induccion)->toBe('pendiente');
});

it('la ficha sólo queda completada cuando no falta ningún tema', function () {
    /*
     * El resto del sistema —el menú, los avisos, el cierre de la
     * contratación— mira `estado_induccion`, así que las dos cosas tienen que
     * decir lo mismo.
     */
    $e = reciénEntrado();

    darInduccion($e, ['temas' => temarioCon(2)]);
    expect($e->fresh()->estado_induccion)->toBe('pendiente');

    darInduccion($e);
    expect($e->fresh()->estado_induccion)->toBe('completada')
        ->and($e->fresh()->induccion_at)->not->toBeNull();
});

it('deja en la ficha los temas que sí se vieron', function () {
    // El campo viejo sigue existiendo y lo leen otras pantallas.
    $e = reciénEntrado();

    darInduccion($e, ['temas' => temarioCon(2)]);

    expect($e->fresh()->induccion_temas)->toContain(Induccion::temarioVigente()[0]);
});

// ── La firma ─────────────────────────────────────────────────────────────────

it('distingue haberla dado de haberla firmado', function () {
    /*
     * Una inducción completa sin firmar demuestra que la empresa dice que la
     * dio. Firmada demuestra que la persona dice que la recibió, que es lo
     * que vale el día que haya que mostrarla.
     */
    $e = reciénEntrado();

    darInduccion($e)->assertSessionHas('success',
        fn (string $m) => str_contains($m, 'Falta que firme'));

    expect(Induccion::where('empleado_id', $e->id)->first()->firmada())->toBeFalse();

    darInduccion($e, ['firmada_at' => '2026-10-06']);

    expect(Induccion::where('empleado_id', $e->id)->first()->firmada())->toBeTrue();
});

it('no acepta una inducción con fecha futura', function () {
    $e = reciénEntrado();

    darInduccion($e, ['fecha' => now()->addWeek()->toDateString()])
        ->assertSessionHasErrors('fecha');
});

it('pide saber quién la dio', function () {
    $e = reciénEntrado();

    darInduccion($e, ['responsable_nombre' => ''])
        ->assertSessionHasErrors('responsable_nombre');
});

it('corrige la inducción en vez de dejar dos', function () {
    $e = reciénEntrado();

    darInduccion($e, ['temas' => temarioCon(2)]);
    darInduccion($e, ['temas' => temarioCon(5)]);

    expect(Induccion::where('empleado_id', $e->id)->count())->toBe(1)
        ->and(Induccion::where('empleado_id', $e->id)->first()->cubiertos())->toBe(5);
});

// ── Quién puede ──────────────────────────────────────────────────────────────

it('la inducción la registra Gestión Humana', function () {
    $e = reciénEntrado();

    $this->actingAs($this->jorge)
        ->post("/app/rrhh/empleados/{$e->id}/induccion-temario", [
            'fecha' => '2026-10-05',
            'responsable_nombre' => 'Quien sea',
            'temas' => temarioCon(1),
        ])->assertForbidden();
});

// ── La constancia ────────────────────────────────────────────────────────────

it('la constancia muestra lo que NO se vio, no sólo lo que sí', function () {
    /*
     * Una constancia que sólo muestra lo visto hace creer que el temario era
     * ése. Los pendientes van marcados.
     */
    $e = reciénEntrado();
    darInduccion($e, ['temas' => temarioCon(3)]);

    $i = Induccion::where('empleado_id', $e->id)->firstOrFail();

    $this->actingAs($this->elena)
        ->get("/app/rrhh/empleados/{$e->id}/induccion-temario/pdf")->assertOk();

    $html = view('rrhh.pdf.constancia-induccion', [
        'e' => $e, 'i' => $i,
        'empresa' => App\Models\EmpresaConfig::current(), 'generadoPor' => 'Elena',
    ])->render();

    expect($html)
        ->toContain('CONSTANCIA DE INDUCCIÓN')
        ->toContain('Inducción incompleta')
        ->toContain($i->pendientes()[0])
        // La declaración es la razón de ser del papel.
        ->toContain('Declaro que recibí la inducción')
        ->toContain('Sin firmar');
});

it('la constancia firmada ya no dice «sin firmar»', function () {
    $e = reciénEntrado();
    darInduccion($e, ['firmada_at' => '2026-10-06']);

    $i = Induccion::where('empleado_id', $e->id)->firstOrFail();

    $html = view('rrhh.pdf.constancia-induccion', [
        'e' => $e, 'i' => $i,
        'empresa' => App\Models\EmpresaConfig::current(), 'generadoPor' => 'Elena',
    ])->render();

    expect($html)
        ->toContain('Firmada el 06/10/2026')
        ->not->toContain('Inducción incompleta');
});
