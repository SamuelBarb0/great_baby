<?php

/**
 * El formato impreso de la requisición de personal · formato 1 del área.
 *
 * Era el ÚNICO de los doce formatos que no se podía imprimir: el flujo estaba
 * completo —solicitar, aprobar, abrir la vacante— pero el papel no existía, y
 * el líder que pide el personal firma papel.
 *
 * Al cruzar el formato con la pantalla faltaban cuatro casillas. Tres son de
 * información (ciudad, razón comercial, horario) y una cambia lo que se puede
 * responder: el **motivo de la selección** en lista cerrada. Con texto libre
 * nunca se va a poder contestar «cuántas contrataciones del año fueron
 * ampliación de planta», que es lo que pregunta una junta.
 *
 * ── Lo que este formato NO captura, a propósito ─────────────────────────────
 *
 * El papel pide sexo y rango de edad. La Ley 931 de 2004 prohíbe fijar
 * límites de edad en una oferta de empleo. Mientras el área no decida, el ERP
 * no los guarda ni los imprime — y hay una prueba que lo fija, para que no se
 * cuelen sin que nadie lo note.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Rrhh\Models\Requisicion;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);
    $this->seed(Database\Seeders\FormatosControladosSeeder::class);

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->jorge = User::factory()->create(['name' => 'Jorge Líder']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));

    $this->otroLider = User::factory()->create(['name' => 'Otra Líder']);
    $this->otroLider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));
});

function pedirPersonal(array $extra = [])
{
    return test()->actingAs(test()->jorge)
        ->post('/app/rrhh/requisiciones', array_merge([
            'cargo' => 'Auxiliar de bodega',
            'area' => 'Logística',
            'numero_vacantes' => 2,
        ], $extra));
}

function laRequisicion(): Requisicion
{
    return Requisicion::latest('id')->firstOrFail();
}

function impresoDeRequisicion(Requisicion $x): string
{
    return view('rrhh.pdf.requisicion', [
        'x' => $x->load('solicitante:id,name'),
        'empresa' => App\Models\EmpresaConfig::current(),
        'generadoPor' => 'Elena',
    ])->render();
}

// ── Las casillas nuevas ──────────────────────────────────────────────────────

it('guarda las casillas que pide el formato del área', function () {
    pedirPersonal([
        'ciudad' => 'Bucaramanga',
        'razon_comercial' => 'MARTINAS',
        'tipo_seleccion' => 'externa',
        'motivo_seleccion' => 'ampliacion_planta',
        'horario' => 'Lunes a viernes 7:30 a.m. a 5:30 p.m.',
    ])->assertSessionHasNoErrors();

    $x = laRequisicion();

    expect($x->ciudad)->toBe('Bucaramanga')
        ->and($x->tipo_seleccion)->toBe('externa')
        ->and($x->motivoEnPalabras())->toBe('Ampliación de planta');
});

it('el motivo en lista cerrada es lo que permite contar', function () {
    /*
     * La razón de la casilla. Con texto libre, «cuántas contrataciones del
     * año fueron ampliación de planta» no se puede responder nunca.
     */
    pedirPersonal(['motivo_seleccion' => 'ampliacion_planta']);
    pedirPersonal(['motivo_seleccion' => 'ampliacion_planta']);
    pedirPersonal(['motivo_seleccion' => 'reemplazo_titular']);

    expect(Requisicion::where('motivo_seleccion', 'ampliacion_planta')->count())->toBe(2)
        ->and(Requisicion::where('motivo_seleccion', 'reemplazo_titular')->count())->toBe(1);
});

it('«otro» sin decir cuál no clasifica nada', function () {
    pedirPersonal(['motivo_seleccion' => 'otro'])
        ->assertSessionHasErrors('motivo_seleccion_otro');

    pedirPersonal(['motivo_seleccion' => 'otro', 'motivo_seleccion_otro' => 'Proyecto Dropi'])
        ->assertSessionHasNoErrors();

    expect(laRequisicion()->motivoEnPalabras())->toBe('Proyecto Dropi');
});

it('no acepta un motivo ni un tipo de selección inventados', function () {
    pedirPersonal(['motivo_seleccion' => 'porque_si'])
        ->assertSessionHasErrors('motivo_seleccion');

    pedirPersonal(['tipo_seleccion' => 'telepatica'])
        ->assertSessionHasErrors('tipo_seleccion');
});

it('deja pedir personal aunque falten casillas, pero lo dice', function () {
    /*
     * El líder pide el personal cuando lo necesita, no cuando tiene todos los
     * datos. Frenarlo haría que la requisición llegue tarde; callarlo haría
     * que el papel salga con huecos sin que quien firma lo sepa.
     */
    pedirPersonal()->assertSessionHasNoErrors();

    $x = laRequisicion();

    expect($x->faltantesDelFormato())
        ->toContain('la ciudad')
        ->toContain('el motivo de la selección')
        ->toContain('el horario');

    expect(impresoDeRequisicion($x))->toContain('El formato sale con casillas vacías');
});

// ── El impreso ───────────────────────────────────────────────────────────────

it('imprime el formato con las casillas marcadas', function () {
    pedirPersonal([
        'ciudad' => 'Bogotá',
        'tipo_seleccion' => 'externa',
        'motivo_seleccion' => 'ampliacion_planta',
        'horario' => 'Turno de 8 horas',
        'funciones' => 'Alistar pedidos y verificar cantidades.',
    ]);

    $x = laRequisicion();

    $this->actingAs($this->elena)
        ->get("/app/rrhh/requisiciones/{$x->id}/pdf")->assertOk();

    $html = impresoDeRequisicion($x);

    expect($html)
        ->toContain('FORMATO REQUISICIÓN DE PERSONAL')
        ->toContain($x->numero)
        ->toContain('Alistar pedidos y verificar cantidades.')
        // La casilla marcada y las demás vacías.
        ->toContain('caja marcada')
        ->toContain('Cubrimiento de licencias')
        // Y la firma de quien la pide.
        ->toContain('Jorge Líder');
});

it('marca en rojo lo que quedó sin diligenciar en vez de dejarlo en blanco', function () {
    /*
     * Una casilla vacía en el papel se lee como «no aplica». Decir «sin
     * diligenciar» distingue lo que nadie llenó de lo que no corresponde.
     */
    pedirPersonal(['ciudad' => null]);

    expect(impresoDeRequisicion(laRequisicion()))->toContain('sin diligenciar');
});

it('no imprime sexo ni rango de edad', function () {
    /*
     * El papel los pide; la Ley 931 de 2004 prohíbe los límites de edad en
     * una oferta de empleo. Esta prueba está para que no se cuelen el día que
     * alguien copie el formato sin leer esta nota.
     */
    pedirPersonal(['ciudad' => 'Bogotá', 'motivo_seleccion' => 'nuevo_proyecto']);

    $html = impresoDeRequisicion(laRequisicion());

    expect($html)
        ->not->toContain('Rangos preferibles')
        ->not->toContain('Sexo');

    expect(Illuminate\Support\Facades\Schema::getColumnListing('rrhh_requisiciones'))
        ->not->toContain('sexo')
        ->not->toContain('edad');
});

// ── Quién puede imprimirlo ───────────────────────────────────────────────────

it('el líder puede imprimir la suya y no la de otra área', function () {
    pedirPersonal(['ciudad' => 'Bogotá']);
    $mia = laRequisicion();

    $ajena = Requisicion::create([
        'numero' => 'RQ-OTRA-1', 'cargo' => 'Vendedor', 'area' => 'Comercial',
        'numero_vacantes' => 1, 'solicitante_id' => $this->otroLider->id, 'estado' => 'borrador',
    ]);

    $this->actingAs($this->jorge)->get("/app/rrhh/requisiciones/{$mia->id}/pdf")->assertOk();
    $this->actingAs($this->jorge)->get("/app/rrhh/requisiciones/{$ajena->id}/pdf")->assertForbidden();

    // Gestión Humana sí ve todas: es quien lleva el proceso.
    $this->actingAs($this->elena)->get("/app/rrhh/requisiciones/{$ajena->id}/pdf")->assertOk();
});

it('el impreso dice en qué va la solicitud', function () {
    pedirPersonal(['ciudad' => 'Bogotá']);
    $x = laRequisicion();

    $this->actingAs($this->jorge)->post("/app/rrhh/requisiciones/{$x->id}/enviar");
    $this->actingAs($this->elena)->post("/app/rrhh/requisiciones/{$x->id}/aprobar");

    expect(impresoDeRequisicion($x->fresh()))
        ->toContain('Estado de la solicitud')
        ->toContain('Elena');
});
