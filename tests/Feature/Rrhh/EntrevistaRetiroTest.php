<?php

/**
 * Fase F1 · La entrevista de retiro.
 *
 * Al salir alguien, el ERP guardaba fecha, tipo de retiro de una lista cerrada
 * y un detalle en texto. Con eso se cuenta cuánta gente se fue; no por qué.
 *
 * La diferencia que justifica todo esto: `tipo_retiro` dice cómo clasifica la
 * EMPRESA la salida —veinte renuncias se cuentan igual— y el motivo de la
 * entrevista dice qué contestó LA PERSONA. Ahí se separa lo que es mercado de
 * lo que la empresa puede arreglar, que es la pregunta que Elena lleva a la
 * Junta.
 */

use App\Models\User;
use App\Modules\Rrhh\Enums\MotivoSalida;
use App\Modules\Rrhh\Models\Asignacion;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\EntrevistaRetiro;
use App\Modules\Rrhh\Support\IndicadoresRrhh;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->lider = User::factory()->create();
    $this->lider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));
});

function quienSeVa(array $extra = []): Empleado
{
    static $n = 0;
    $n++;

    return Empleado::create(array_merge([
        'nombre' => "Quien se va {$n}", 'tipo_documento' => 'CC', 'numero_documento' => "sale{$n}",
        'cargo' => 'Auxiliar de bodega', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 1_500_000, 'fecha_ingreso' => '2026-01-15',
        'estado' => 'retirado', 'estado_induccion' => 'completada',
        'fecha_retiro' => '2026-06-30', 'tipo_retiro' => 'renuncia',
        'motivo_retiro' => 'Renuncia voluntaria.',
    ], $extra));
}

function entrevistar(Empleado $e, array $extra = []): EntrevistaRetiro
{
    test()->actingAs(test()->elena)
        ->post("/app/rrhh/empleados/{$e->id}/entrevista-retiro", array_merge([
            'fecha_entrevista' => '2026-06-28',
            'salida' => 'voluntaria',
            'motivo_principal' => MotivoSalida::JefeInmediato->value,
        ], $extra));

    return EntrevistaRetiro::where('empleado_id', $e->id)->firstOrFail();
}

// ── Guardar ──────────────────────────────────────────────────────────────────

it('guarda el motivo que da la persona, aparte del tipo de retiro', function () {
    /*
     * Los dos ejes. La empresa lo clasificó como «renuncia»; la persona dice
     * que se fue por el jefe. Lo primero sirve para la nómina, lo segundo para
     * hacer algo al respecto.
     */
    $e = quienSeVa(['tipo_retiro' => 'renuncia']);

    $ev = entrevistar($e, ['motivo_principal' => MotivoSalida::ClimaLaboral->value]);

    expect($ev->motivo_principal)->toBe(MotivoSalida::ClimaLaboral)
        // El tipo administrativo no se tocó.
        ->and($e->fresh()->tipo_retiro)->toBe('renuncia');
});

it('guarda las ocho notas con su comentario', function () {
    $e = quienSeVa();

    $ev = entrevistar($e, [
        'n_jefe_inmediato' => 1, 'c_jefe_inmediato' => 'Instrucciones contradictorias.',
        'n_equipo_trabajo' => 5,
        'n_carga_laboral' => 2, 'c_carga_laboral' => 'Cubría dos puestos.',
    ]);

    expect($ev->n_jefe_inmediato)->toBe(1)
        ->and($ev->c_jefe_inmediato)->toBe('Instrucciones contradictorias.')
        ->and($ev->n_equipo_trabajo)->toBe(5);
});

it('una nota fuera de 1 a 5 se rechaza', function () {
    $e = quienSeVa();

    $this->actingAs($this->elena)
        ->post("/app/rrhh/empleados/{$e->id}/entrevista-retiro", [
            'fecha_entrevista' => '2026-06-28', 'n_jefe_inmediato' => 9,
        ])->assertSessionHasErrors('n_jefe_inmediato');

    expect(EntrevistaRetiro::count())->toBe(0);
});

it('«otro» sin decir cuál no pasa', function () {
    // No sirve ni para contar ni para entender.
    $e = quienSeVa();

    $this->actingAs($this->elena)
        ->post("/app/rrhh/empleados/{$e->id}/entrevista-retiro", [
            'fecha_entrevista' => '2026-06-28',
            'motivo_principal' => MotivoSalida::Otro->value,
        ])->assertSessionHasErrors('motivo_otro');
});

it('«otro» con el detalle sí pasa y el motivo se lee de ahí', function () {
    $e = quienSeVa();

    $ev = entrevistar($e, [
        'motivo_principal' => MotivoSalida::Otro->value,
        'motivo_otro' => 'Se va a estudiar fuera del país.',
    ]);

    expect($ev->motivoEnPalabras())->toBe('Se va a estudiar fuera del país.');
});

it('se puede corregir: la entrevista se conversa', function () {
    /*
     * Es normal volver a abrirla para completar lo que quedó suelto. Una por
     * persona, no un historial de intentos.
     */
    $e = quienSeVa();
    entrevistar($e, ['que_valoro' => 'Primera versión.']);
    entrevistar($e, ['que_valoro' => 'Versión corregida.']);

    expect(EntrevistaRetiro::where('empleado_id', $e->id)->count())->toBe(1)
        ->and(EntrevistaRetiro::first()->que_valoro)->toBe('Versión corregida.');
});

it('se puede hacer ANTES de registrar el retiro', function () {
    /*
     * La entrevista suele hacerse antes del último día. Bloquearla hasta que
     * el retiro esté registrado obligaría a hacerla de memoria o en un papel
     * suelto, que es lo que se quiere dejar de hacer.
     */
    $e = quienSeVa(['estado' => 'activo', 'fecha_retiro' => null, 'tipo_retiro' => null]);

    entrevistar($e);

    expect(EntrevistaRetiro::where('empleado_id', $e->id)->exists())->toBeTrue();
});

it('un líder de área no la hace: la mitad de las preguntas son sobre él', function () {
    $e = quienSeVa();

    $this->actingAs($this->lider)
        ->post("/app/rrhh/empleados/{$e->id}/entrevista-retiro", ['fecha_entrevista' => '2026-06-28'])
        ->assertForbidden();
});

// ── Lo que se lee después ────────────────────────────────────────────────────

it('señala los criterios peor calificados, que es el motivo de hacer esto', function () {
    /*
     * Un promedio de 3,5 no dice nada; «herramientas: 1» con su comentario sí.
     * Devuelve los que están en 1 o 2, que es donde hay algo que arreglar.
     */
    $ev = entrevistar(quienSeVa(), [
        'n_jefe_inmediato' => 1, 'c_jefe_inmediato' => 'Nunca hubo retroalimentación.',
        'n_equipo_trabajo' => 5,
        'n_carga_laboral' => 2,
        'n_ambiente' => 4,
    ]);

    $bajos = $ev->puntosBajos();

    expect($bajos)->toHaveCount(2)
        ->and($bajos[0]['nota'])->toBe(1)
        ->and($bajos[0]['comentario'])->toBe('Nunca hubo retroalimentación.');
});

it('el promedio no cuenta lo que quedó en blanco', function () {
    // Tres notas que suman 9: 9 / 3 = 3. Con los cinco nulos como cero daría 1,13.
    $ev = entrevistar(quienSeVa(), [
        'n_jefe_inmediato' => 2, 'n_equipo_trabajo' => 4, 'n_carga_laboral' => 3,
    ]);

    expect($ev->promedio())->toBe(3.0);
});

// ── El indicador de la Junta ────────────────────────────────────────────────

it('el indicador separa lo que la empresa puede arreglar de lo que no', function () {
    /*
     * El número que cambia una conversación de Junta. Tres renuncias se
     * cuentan igual en `tipo_retiro`; acá se ve que dos fueron por el jefe y
     * el clima —que se pueden atacar— y una por mudanza, que no.
     */
    entrevistar(quienSeVa(), ['motivo_principal' => MotivoSalida::JefeInmediato->value]);
    entrevistar(quienSeVa(), ['motivo_principal' => MotivoSalida::ClimaLaboral->value]);
    entrevistar(quienSeVa(), ['motivo_principal' => MotivoSalida::CambioResidencia->value]);

    $r = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->motivosDeRetiro();

    expect($r['entrevistas']['disponible'])->toBeTrue()
        ->and($r['entrevistas']['hechas'])->toBe(3)
        ->and($r['entrevistas']['accionables'])->toBe(2);
});

it('dice sobre cuántas salidas se alcanzó a entrevistar', function () {
    /*
     * Sin la cobertura, un «0 % por clima laboral» puede significar que nadie
     * se quejó o que no se entrevistó a nadie, que es lo contrario.
     */
    entrevistar(quienSeVa());
    quienSeVa();   // se fue y nadie la entrevistó
    quienSeVa();

    $r = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->motivosDeRetiro();

    expect($r['entrevistas']['cobertura'])->toBe(33.3);
});

it('cuenta a quién NO nos recomendaría', function () {
    entrevistar(quienSeVa(), ['recomendaria' => false, 'recomendaria_por_que' => 'Por el jefe.']);
    entrevistar(quienSeVa(), ['recomendaria' => true]);

    $r = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->motivosDeRetiro();

    expect($r['entrevistas']['no_recomiendan'])->toBe(1);
});

it('sin entrevistas el indicador dice que no hay, no cero', function () {
    // Cero sería «nadie se quejó»; «no hay» es «no preguntamos».
    quienSeVa();

    $r = IndicadoresRrhh::entre('2026-01-01', '2026-12-31')->motivosDeRetiro();

    expect($r['entrevistas']['disponible'])->toBeFalse();
});

// ── El impreso ───────────────────────────────────────────────────────────────

it('el formato sale en PDF', function () {
    $e = quienSeVa();
    entrevistar($e);

    $this->actingAs($this->elena)
        ->get("/app/rrhh/empleados/{$e->id}/entrevista-retiro/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('el paz y salvo del impreso sale de las asignaciones, no se escribe a mano', function () {
    /*
     * El bloque 5 del formato lista equipo, móvil y dotación. Eso ya vive en
     * `rrhh_asignaciones` CON SERIAL. Copiarlo a la entrevista sería tener el
     * mismo hecho en dos tablas, y el día que difieran nadie sabría cuál creer.
     */
    $e = quienSeVa();
    Asignacion::create([
        'empleado_id' => $e->id, 'tipo' => 'equipo',
        'descripcion' => 'Tablet Samsung', 'identificador' => 'SN-4471',
        'cantidad' => 1, 'fecha_entrega' => '2026-02-01', 'estado' => 'asignada',
    ]);
    $ev = entrevistar($e);

    $html = view('rrhh.pdf.entrevista-retiro', [
        'e' => $e->fresh(), 'ev' => $ev,
        'asignaciones' => $e->asignaciones()->get(),
        'pendientes' => $e->pendientesDeDevolver()->get(),
        'motivos' => MotivoSalida::catalogo(),
        'empresa' => App\Models\EmpresaConfig::current(), 'generadoPor' => 'Elena',
    ])->render();

    expect($html)->toContain('Tablet Samsung')
        // Y avisa que no se puede firmar el paz y salvo.
        ->toContain('No se puede firmar el paz y salvo');
});

it('el impreso marca que el código del formato está sin asignar', function () {
    /*
     * El formato del área llegó con «GH-F-XX»: el código todavía no existe.
     * Inventarle uno rompería la numeración del sistema de calidad.
     *
     * Desde la Fase G el recuadro sale del listado maestro, así que ya no se
     * imprime el «GH-F-XX» del papel —que es un marcador de posición, no un
     * código— sino que se dice sin rodeos que está sin asignar. El día que el
     * área lo asigne en su pantalla, aparece acá sin tocar código.
     */
    $e = quienSeVa();
    entrevistar($e);

    $html = view('rrhh.pdf.entrevista-retiro', [
        'e' => $e, 'ev' => EntrevistaRetiro::first(),
        'asignaciones' => collect(), 'pendientes' => collect(),
        'motivos' => MotivoSalida::catalogo(),
        'empresa' => App\Models\EmpresaConfig::current(), 'generadoPor' => 'Elena',
    ])->render();

    expect($html)->toContain('sin asignar')
        ->not->toContain('GH-F-XX')
        ->toContain('COPIA NO CONTROLADA');
});
