<?php

/**
 * Fase D2 · La carta del examen médico ocupacional (SST-F-001).
 *
 * El ERP guardaba la FECHA y el RESULTADO del examen, pero no tenía con qué
 * PEDIRLO: la carta que la persona lleva a la IPS se escribía por fuera. Era
 * el sistema sabiendo que el examen se hizo sin haber participado en pedirlo.
 *
 * Lo que estas pruebas cuidan:
 *
 *   · Que el TIPO sea el correcto. Una carta que dice «de ingreso» para
 *     alguien que se está yendo manda a la IPS a aplicar la batería
 *     equivocada, y el concepto que vuelve no sirve para nada.
 *   · Que los datos de la IPS salgan de las reglas. Es un proveedor: el día
 *     que se cambie, la carta tiene que salir con la nueva sin tocar código.
 *   · Que quede rastro de haberla pedido.
 */

use App\Models\User;
use App\Modules\Rrhh\Enums\TipoExamenMedico;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\Contratacion;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Vacante;
use App\Support\Reglas;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);
    Reglas::limpiarRequestCache();

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->lider = User::factory()->create();
    $this->lider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));

    $this->empleada = Empleado::create([
        'nombre' => 'Sandra Gómez', 'tipo_documento' => 'CC', 'numero_documento' => '2001',
        'cargo' => 'Auxiliar contable', 'area' => 'Contabilidad', 'tipo_contrato' => 'indefinido',
        'salario' => 2_000_000, 'fecha_ingreso' => '2026-01-15',
        'estado' => 'activo', 'estado_induccion' => 'pendiente',
    ]);
});

function contratacionConCandidato(): Contratacion
{
    $v = Vacante::create(['titulo' => 'Auxiliar de bodega', 'area' => 'Logística', 'estado' => 'abierta']);
    $c = Candidato::create([
        'vacante_id' => $v->id, 'nombre' => 'Diana Moreno', 'documento' => '1055667788',
        'etapa' => 'seleccionado',
    ]);

    return Contratacion::create([
        'candidato_id' => $c->id, 'vacante_id' => $v->id, 'estado' => 'en_proceso',
        'salario' => 1_500_000, 'tipo_contrato' => 'indefinido',
    ]);
}

// ── La de ingreso ────────────────────────────────────────────────────────────

it('la carta de ingreso sale desde la contratación', function () {
    $x = contratacionConCandidato();

    $this->actingAs($this->elena)
        ->get("/app/rrhh/contrataciones/{$x->id}/emo")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('queda escrito que se pidió el examen', function () {
    // Si no queda rastro, dentro de un mes nadie sabe si la carta se entregó.
    $x = contratacionConCandidato();

    $this->actingAs($this->elena)->get("/app/rrhh/contrataciones/{$x->id}/emo");

    expect($x->fresh()->bitacora()->where('evento', 'emo_solicitado')->exists())->toBeTrue();
});

// ── Las de quien ya trabaja acá ──────────────────────────────────────────────

it('la carta de egreso sale desde la ficha del empleado', function () {
    /*
     * El de egreso lo exige la Resolución 2346. Sin él la empresa queda sin
     * con qué demostrar en qué condiciones se fue la persona, y una demanda
     * posterior por enfermedad laboral se pierde sola.
     */
    $this->actingAs($this->elena)
        ->get("/app/rrhh/empleados/{$this->empleada->id}/emo?tipo=egreso")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('los cuatro tipos de quien ya está contratado funcionan', function () {
    foreach (TipoExamenMedico::deEmpleado() as $tipo) {
        $this->actingAs($this->elena)
            ->get("/app/rrhh/empleados/{$this->empleada->id}/emo?tipo={$tipo->value}")
            ->assertOk();
    }
});

it('no se puede pedir el de INGRESO para alguien que ya está contratado', function () {
    /*
     * El candado del tipo. La carta le dice a la IPS qué batería aplicar: una
     * que diga «de ingreso» para alguien que ya trabaja acá devuelve un
     * concepto que no sirve, y hay que repetir el examen.
     */
    $this->actingAs($this->elena)
        ->get("/app/rrhh/empleados/{$this->empleada->id}/emo?tipo=ingreso")
        ->assertStatus(422);
});

it('un tipo inventado se rechaza', function () {
    $this->actingAs($this->elena)
        ->get("/app/rrhh/empleados/{$this->empleada->id}/emo?tipo=lo_que_sea")
        ->assertStatus(422);
});

it('sin tipo tampoco sale', function () {
    $this->actingAs($this->elena)
        ->get("/app/rrhh/empleados/{$this->empleada->id}/emo")
        ->assertStatus(422);
});

// ── Quién puede pedirla ──────────────────────────────────────────────────────

it('un líder de área no genera cartas de examen médico', function () {
    $this->actingAs($this->lider)
        ->get("/app/rrhh/empleados/{$this->empleada->id}/emo?tipo=periodico")
        ->assertForbidden();
});

// ── La IPS ───────────────────────────────────────────────────────────────────

it('la IPS sale de las reglas y no del código', function () {
    /*
     * Es un proveedor. El día que se cambie de IPS la carta tiene que salir
     * con la nueva sin esperar a un programador — y sin que alguien tenga que
     * acordarse de avisar.
     */
    Reglas::set('rrhh.ips_emo_nombre', 'IPS NUEVA SALUD OCUPACIONAL');
    Reglas::set('rrhh.ips_emo_direccion', 'Calle 100 # 1-2');
    Reglas::limpiarRequestCache();

    $html = view('rrhh.pdf.orden-examen-medico', [
        'tipo' => TipoExamenMedico::Periodico,
        'persona' => ['nombre' => 'Sandra Gómez', 'documento' => '2001', 'cargo' => 'Auxiliar contable'],
        'empresa' => App\Models\EmpresaConfig::current(),
        'ips' => [
            'nombre' => setting('rrhh.ips_emo_nombre'),
            'direccion' => setting('rrhh.ips_emo_direccion'),
            'horario' => setting('rrhh.ips_emo_horario'),
            'instrucciones' => setting('rrhh.ips_emo_instrucciones'),
        ],
        'generadoPor' => 'Elena',
    ])->render();

    expect($html)->toContain('IPS NUEVA SALUD OCUPACIONAL')
        ->toContain('Calle 100 # 1-2');
});

it('la carta lleva su código de control documental y la norma que la respalda', function () {
    $html = view('rrhh.pdf.orden-examen-medico', [
        'tipo' => TipoExamenMedico::Egreso,
        'persona' => ['nombre' => 'Sandra Gómez', 'documento' => '2001', 'cargo' => 'Auxiliar contable'],
        'empresa' => App\Models\EmpresaConfig::current(),
        'ips' => ['nombre' => 'IPS', 'direccion' => 'x', 'horario' => 'y', 'instrucciones' => 'z'],
        'generadoPor' => 'Elena',
    ])->render();

    expect($html)->toContain('SST-F-001')
        ->toContain('Resolución 2346 de 2007')
        ->toContain('COPIA NO CONTROLADA')
        // Y dice de qué examen se trata, que es lo que la IPS necesita saber.
        ->toContain('examen médico ocupacional de egreso');
});
