<?php

/**
 * Fase D3 · La solicitud de documentos para contratación (GH-F-007).
 *
 * El ERP tenía una lista propia de diez renglones genéricos —«Certificados de
 * estudio»— y el formato del área pide cosas concretas. La diferencia no es
 * cosmética: «certificados de estudio» no le dice a nadie que hacen falta el
 * diploma Y el acta de grado, ni que el de estudios superiores sólo aplica a
 * algunos cargos. El aspirante vuelve dos veces.
 *
 * Lo que estas pruebas cuidan es que haya UNA sola lista: la que se imprime en
 * la carta, la que ofrece el formulario de carga y la que calcula lo que
 * falta. Si se separaran, la carta pediría una cosa y la pantalla esperaría
 * otra — que es como estaba.
 */

use App\Models\User;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\Contratacion;
use App\Modules\Rrhh\Models\DocumentoEmpleado;
use App\Modules\Rrhh\Models\Vacante;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->lider = User::factory()->create();
    $this->lider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));

    $v = Vacante::create(['titulo' => 'Auxiliar de bodega', 'area' => 'Logística', 'estado' => 'abierta']);
    $this->candidata = Candidato::create([
        'vacante_id' => $v->id, 'nombre' => 'Diana Moreno', 'documento' => '1055667788',
        'etapa' => 'seleccionado',
    ]);
    $this->contratacion = Contratacion::create([
        'candidato_id' => $this->candidata->id, 'vacante_id' => $v->id,
        'estado' => 'en_proceso', 'salario' => 1_500_000, 'tipo_contrato' => 'indefinido',
    ]);
});

function entregar(string $tipo): DocumentoEmpleado
{
    return DocumentoEmpleado::create([
        'candidato_id' => test()->candidata->id,
        'tipo' => $tipo, 'nombre' => "{$tipo}.pdf", 'archivo_path' => "x/{$tipo}.pdf",
    ]);
}

// ── Una sola lista ───────────────────────────────────────────────────────────

it('la lista de la carta y la del formulario de carga son la misma', function () {
    /*
     * El punto de toda la fase. Si fueran dos listas, la carta pediría una
     * cosa y la pantalla esperaría otra.
     */
    $deLaCarta = array_keys(DocumentoEmpleado::loQueTraeElAspirante());
    $delFormulario = array_keys(DocumentoEmpleado::TIPOS);

    expect(array_diff($deLaCarta, $delFormulario))->toBe([]);
});

it('la carta NO le pide al aspirante lo que produce la empresa', function () {
    /*
     * Los antecedentes los consulta Gestión Humana y el contrato lo hace la
     * empresa. Pedírselos en la carta lo manda a buscar algo que no le
     * corresponde, y vuelve preguntando.
     */
    $deLaCarta = array_keys(DocumentoEmpleado::loQueTraeElAspirante());

    expect($deLaCarta)->not->toContain('antecedentes')
        ->not->toContain('contrato')
        ->not->toContain('otro');
});

it('cada renglón dice si es obligatorio o si sólo aplica a algunos', function () {
    $catalogo = DocumentoEmpleado::loQueTraeElAspirante();

    expect($catalogo['cedula']['exigencia'])->toBe('siempre')
        // La tarjeta profesional depende del cargo; la libreta, de la persona.
        ->and($catalogo['tarjeta_profesional']['exigencia'])->toBe('si_aplica')
        ->and($catalogo['libreta_militar']['exigencia'])->toBe('si_aplica');
});

it('la letra chica del formato no se pierde', function () {
    // «ampliada al 150%» o «en hoja membretada» es la diferencia entre que el
    // papel sirva y que haya que volver a pedirlo.
    $catalogo = DocumentoEmpleado::loQueTraeElAspirante();

    expect($catalogo['cedula']['nota'])->toContain('150%')
        ->and($catalogo['certificado_laboral']['nota'])->toContain('membretada')
        ->and($catalogo['beneficiarios']['nota'])->toContain('registro civil de matrimonio');
});

// ── La carta ─────────────────────────────────────────────────────────────────

it('la carta sale en PDF desde la contratación', function () {
    $this->actingAs($this->elena)
        ->get("/app/rrhh/contrataciones/{$this->contratacion->id}/documentos-solicitud")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('queda escrito que se entregó la solicitud', function () {
    $this->actingAs($this->elena)
        ->get("/app/rrhh/contrataciones/{$this->contratacion->id}/documentos-solicitud");

    expect($this->contratacion->fresh()->bitacora()
        ->where('evento', 'documentos_solicitados')->exists())->toBeTrue();
});

it('la carta marca lo que la persona ya entregó', function () {
    /*
     * En la segunda entrega no tiene que volver a traer lo que ya trajo. Sin
     * esto, la carta reimpresa pide todo otra vez.
     */
    entregar('cedula');
    entregar('hoja_vida');

    $html = view('rrhh.pdf.solicitud-documentos', [
        'persona' => ['nombre' => 'Diana Moreno', 'documento' => '1055667788', 'cargo' => 'Auxiliar'],
        'documentos' => DocumentoEmpleado::loQueTraeElAspirante(),
        'yaEntregados' => ['cedula', 'hoja_vida'],
        'empresa' => App\Models\EmpresaConfig::current(),
        'generadoPor' => 'Elena',
    ])->render();

    expect(substr_count($html, 'Ya recibido'))->toBe(2);
});

it('la carta lleva su código y la advertencia de exclusión', function () {
    $html = view('rrhh.pdf.solicitud-documentos', [
        'persona' => ['nombre' => 'Diana Moreno', 'documento' => '1', 'cargo' => 'Auxiliar'],
        'documentos' => DocumentoEmpleado::loQueTraeElAspirante(),
        'yaEntregados' => [],
        'empresa' => App\Models\EmpresaConfig::current(),
        'generadoPor' => 'Elena',
    ])->render();

    expect($html)->toContain('GH-F-007')
        ->toContain('objeto de exclusión del proceso de selección')
        ->toContain('COPIA NO CONTROLADA');
});

it('un líder de área no genera la solicitud', function () {
    $this->actingAs($this->lider)
        ->get("/app/rrhh/contrataciones/{$this->contratacion->id}/documentos-solicitud")
        ->assertForbidden();
});

// ── Lo que falta ─────────────────────────────────────────────────────────────

it('la pantalla avisa qué obligatorio no ha llegado', function () {
    $r = $this->actingAs($this->elena)->get("/app/rrhh/contrataciones/{$this->contratacion->id}");

    $faltan = $r->viewData('page')['props']['documentos_faltantes'];

    expect($faltan)->toContain('documento de identidad')
        ->toContain('hoja de vida')
        ->toContain('certificación bancaria de la cuenta de nómina');
});

it('lo que ya llegó deja de aparecer como faltante', function () {
    entregar('cedula');

    $r = $this->actingAs($this->elena)->get("/app/rrhh/contrataciones/{$this->contratacion->id}");

    expect($r->viewData('page')['props']['documentos_faltantes'])
        ->not->toContain('documento de identidad');
});

it('lo que sólo aplica a algunos NO se reporta como faltante', function () {
    /*
     * La libreta militar y la tarjeta profesional dependen del cargo o de la
     * persona. Marcarlas como faltantes llenaría la pantalla de avisos que no
     * son avisos, y los avisos que no son avisos se dejan de leer.
     */
    $r = $this->actingAs($this->elena)->get("/app/rrhh/contrataciones/{$this->contratacion->id}");

    $faltan = $r->viewData('page')['props']['documentos_faltantes'];

    expect($faltan)->not->toContain('libreta militar')
        ->not->toContain('registro o tarjeta profesional');
});

it('avisar NO frena el cierre de la contratación', function () {
    /*
     * A propósito. Hoy nada lo frenaba, y convertirlo en bloqueo de un día
     * para otro dejaría trabada a toda la gente que está a mitad de proceso.
     * Primero se avisa; si el área quiere que frene, es una línea más.
     */
    expect($this->contratacion->loQueFalta())
        ->not->toContain('documento de identidad');
});
