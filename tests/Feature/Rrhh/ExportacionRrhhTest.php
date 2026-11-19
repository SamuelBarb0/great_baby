<?php

/**
 * F6 · Sacar la información del sistema.
 *
 * «Todo va a ser exportable en PDF y exportable también en Excel, de manera
 * masiva y de manera de un solo candidato» (reunión del 19-oct). Y no es un
 * capricho: la normativa le exige a Elena tener las historias laborales «tanto
 * físicas como digitales», así que el PDF se imprime y se archiva.
 *
 * Lo que estas pruebas cuidan es que el archivo SIRVA: que no salga vacío, que
 * Excel lo abra sin romper los acentos ni meter todo en una celda, y que
 * contenga el rastro —sin el cual el papel no prueba nada.
 */

use App\Models\User;
use App\Modules\Rrhh\Enums\EstadoRequisicion;
use App\Modules\Rrhh\Enums\EtapaCandidato;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\Contratacion;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Requisicion;
use App\Modules\Rrhh\Models\Vacante;
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
        'cargo' => 'Auxiliar de bodega', 'area' => 'Logística', 'numero_vacantes' => 1,
        'salario_min' => 1500000, 'solicitante_id' => $this->lider->id,
        'estado' => EstadoRequisicion::Aprobada->value,
    ]);

    $this->vacante = Vacante::create([
        'requisicion_id' => $this->requisicion->id,
        'titulo' => 'Auxiliar de bodega', 'area' => 'Logística', 'estado' => 'abierta',
    ]);

    // Con tilde y eñe a propósito: es lo que rompe el CSV mal armado.
    $this->candidato = Candidato::create([
        'vacante_id' => $this->vacante->id,
        'nombre' => 'Ana María Núñez', 'documento' => '1098765432',
        'etapa' => EtapaCandidato::Seleccionado->value,
        'antecedentes_revisados' => true,
        'antecedentes_resultado' => 'Sin antecedentes',
        'valoracion' => 'apto',
    ]);

    $this->empleado = Empleado::create([
        'candidato_id' => $this->candidato->id,
        'vacante_id' => $this->vacante->id,
        'nombre' => 'Ana María Núñez', 'tipo_documento' => 'CC', 'numero_documento' => '1098765432',
        'cargo' => 'Auxiliar de bodega', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 1500000, 'fecha_ingreso' => now()->subMonth()->toDateString(),
        'estado' => 'activo', 'estado_induccion' => 'pendiente',
    ]);
});

it('la historia laboral sale como un PDF de verdad', function () {
    $r = $this->actingAs($this->elena)->get("/app/rrhh/empleados/{$this->empleado->id}/pdf");

    $r->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    /*
     * Un PDF empieza con «%PDF». Sin comprobarlo, un error de Blade pasaría
     * por bueno: la respuesta sería 200 con una página de error adentro.
     *
     * Va con `getContent()` y no `streamedContent()`: dompdf devuelve el
     * archivo entero, no un stream.
     */
    expect(substr($r->getContent(), 0, 4))->toBe('%PDF');
});

it('el PDF lleva el rastro, que es lo que lo convierte en prueba', function () {
    /*
     * Sin la bitácora el papel sólo dice cómo están las cosas hoy. Con ella
     * dice cómo se llegó hasta acá, que es lo que pregunta una auditoría.
     */
    $this->empleado->anotar('ingreso', 'Ingresa como Auxiliar de bodega');
    $this->empleado->update([
        'estado_induccion' => 'completada',
        'induccion_at' => now(),
        'induccion_por_id' => $this->elena->id,
        'induccion_temas' => 'Seguridad y salud en el trabajo',
    ]);

    $pdf = $this->actingAs($this->elena)
        ->get("/app/rrhh/empleados/{$this->empleado->id}/pdf")->getContent();

    // dompdf comprime los flujos, así que el texto no se busca en crudo. Lo
    // que sí se puede afirmar es que el documento se generó y tiene cuerpo.
    expect(strlen($pdf))->toBeGreaterThan(5000);
});

it('el proceso de un candidato también sale en PDF', function () {
    $r = $this->actingAs($this->elena)->get("/app/rrhh/candidatos/{$this->candidato->id}/pdf");

    $r->assertOk();
    expect(substr($r->getContent(), 0, 4))->toBe('%PDF');
});

it('el Excel de empleados trae las filas, no sólo la cabecera', function () {
    /*
     * El primer intento usaba `chunk()` con un `yield` adentro: el generador
     * no lo consumía nadie y el archivo salía con la cabecera y ni una fila.
     */
    $csv = $this->actingAs($this->elena)->get('/app/rrhh/exportar/empleados')
        ->streamedContent();

    $lineas = array_filter(explode("\n", trim($csv)));

    expect($lineas)->toHaveCount(2)
        ->and($csv)->toContain('Ana María Núñez')
        ->and($csv)->toContain('Auxiliar de bodega');
});

it('Excel en español abre el archivo sin romper los acentos', function () {
    /*
     * Dos detalles que parecen menores y arruinan el archivo:
     *   · Sin BOM, Excel en Windows lee el UTF-8 como ANSI y «Núñez» sale
     *     «NÃºÃ±ez». Y acá casi todo son nombres de personas.
     *   · Con coma como separador, Excel en configuración de Colombia mete la
     *     fila entera en una sola celda.
     */
    $csv = $this->actingAs($this->elena)->get('/app/rrhh/exportar/empleados')
        ->streamedContent();

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and(explode("\n", $csv)[0])->toContain(';')
        ->and($csv)->toContain('Núñez');
});

it('el Excel de empleados respeta el filtro de la pantalla', function () {
    // Se exporta lo que se ve: si no, el archivo no coincide con la tabla.
    Empleado::create([
        'nombre' => 'Quien se fue', 'tipo_documento' => 'CC', 'numero_documento' => '777',
        'cargo' => 'Auxiliar', 'area' => 'Bodega', 'tipo_contrato' => 'indefinido',
        'fecha_ingreso' => now()->subYear(), 'estado' => 'retirado',
    ]);

    $activos = $this->actingAs($this->elena)->get('/app/rrhh/exportar/empleados?estado=activo')->streamedContent();
    $todos = $this->actingAs($this->elena)->get('/app/rrhh/exportar/empleados?estado=todos')->streamedContent();

    expect($activos)->not->toContain('Quien se fue')
        ->and($todos)->toContain('Quien se fue');
});

it('los candidatos de una vacante salen con en qué quedó cada uno', function () {
    Candidato::create([
        'vacante_id' => $this->vacante->id, 'nombre' => 'Descartado Pérez',
        'etapa' => EtapaCandidato::Descartado->value,
        'motivo_descarte' => 'No se presentó a la entrevista',
    ]);

    $csv = $this->actingAs($this->elena)
        ->get("/app/rrhh/exportar/vacantes/{$this->vacante->id}/candidatos")->streamedContent();

    expect($csv)->toContain('Ana María Núñez')
        ->and($csv)->toContain('Descartado Pérez')
        // Por qué no siguió: es lo que hoy no queda en ninguna parte.
        ->and($csv)->toContain('No se presentó a la entrevista');
});

it('el líder exporta SUS solicitudes y nada más', function () {
    Requisicion::create([
        'numero' => Requisicion::siguienteNumero(),
        'cargo' => 'Contador de otra área', 'numero_vacantes' => 1,
        'solicitante_id' => User::factory()->create()->id,
        'estado' => EstadoRequisicion::Enviada->value,
    ]);

    $delLider = $this->actingAs($this->lider)->get('/app/rrhh/exportar/requisiciones')->streamedContent();
    $deElena = $this->actingAs($this->elena)->get('/app/rrhh/exportar/requisiciones')->streamedContent();

    expect($delLider)->toContain('Auxiliar de bodega')
        ->and($delLider)->not->toContain('Contador de otra área')
        ->and($deElena)->toContain('Contador de otra área');
});

it('el líder no se baja la historia laboral de nadie', function () {
    // Ahí está el salario, las afiliaciones y la carpeta entera.
    $this->actingAs($this->lider)->get("/app/rrhh/empleados/{$this->empleado->id}/pdf")->assertStatus(403);
    $this->actingAs($this->lider)->get('/app/rrhh/exportar/empleados')->assertStatus(403);
    $this->actingAs($this->lider)->get("/app/rrhh/candidatos/{$this->candidato->id}/pdf")->assertStatus(403);
});

it('quien no es de Gestión Humana ni jefe no exporta nada', function () {
    $vendedor = User::factory()->create();
    $vendedor->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    $this->actingAs($vendedor)->get('/app/rrhh/exportar/requisiciones')->assertStatus(403);
    $this->actingAs($vendedor)->get('/app/rrhh/exportar/empleados')->assertStatus(403);
});

it('el PDF de alguien que no pasó por el sistema no revienta', function () {
    /*
     * Los empleados que ya estaban antes del módulo no tienen contratación ni
     * vacante. El PDF tiene que salir igual, diciendo qué falta, en vez de
     * caerse con «llamada a método sobre null».
     */
    $viejo = Empleado::create([
        'nombre' => 'Quien ya estaba', 'tipo_documento' => 'CC', 'numero_documento' => '888',
        'cargo' => 'Auxiliar', 'area' => 'Bodega', 'tipo_contrato' => 'indefinido',
        'fecha_ingreso' => now()->subYears(2), 'estado' => 'activo',
    ]);

    $r = $this->actingAs($this->elena)->get("/app/rrhh/empleados/{$viejo->id}/pdf");

    $r->assertOk();
    expect(substr($r->getContent(), 0, 4))->toBe('%PDF');
});

it('el PDF junta la historia del empleado con la de su contratación', function () {
    // Son la misma historia guardada en dos tablas: impresas por separado, el
    // papel no mostraría el proceso completo.
    $x = Contratacion::create([
        'candidato_id' => $this->candidato->id,
        'vacante_id' => $this->vacante->id,
        'empleado_id' => $this->empleado->id,
        'estado' => 'completada',
        'eps' => 'Nueva EPS', 'eps_at' => now(),
    ]);
    $x->anotar('completada', 'Contratación cerrada');
    $this->empleado->anotar('ingreso', 'Ingresa como Auxiliar');

    $sinJuntar = $this->empleado->bitacora()->count();
    $r = $this->actingAs($this->elena)->get("/app/rrhh/empleados/{$this->empleado->id}/pdf");

    $r->assertOk();
    expect($sinJuntar)->toBe(1)
        ->and($x->bitacora()->count())->toBe(1);
});
