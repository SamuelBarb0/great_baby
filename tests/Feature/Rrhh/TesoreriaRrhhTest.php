<?php

/**
 * La bandeja de tesorería.
 *
 * Tres flujos del módulo terminan con su firma —pagar la nómina, pagar la
 * liquidación y dar conformidad al paz y salvo— y el backend lo exigía bien:
 * cada acción pide `contabilidad`.
 *
 * Lo que estaba roto era la otra mitad: **los botones vivían en pantallas que
 * piden `rrhh`**, que el Contador no tiene. El control existía en el código y
 * en la práctica lo saltaba alguien de Gestión Humana apretando el botón «por»
 * tesorería. Un control que obliga a saltárselo para trabajar no es un
 * control.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\EntregaCargo;
use App\Modules\Rrhh\Models\Liquidacion;
use App\Modules\Rrhh\Models\Nomina;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->silvia = User::factory()->create(['name' => 'Silvia']);
    $this->silvia->assignRole(rolDeTesoreria('Contador'));

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->junta = User::factory()->create(['name' => 'Junta']);
    $this->junta->assignRole(rolDeTesoreria('Gerente'));
});

function rolDeTesoreria(string $nombre): Role
{
    $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

    $rol->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($nombre, $roles, true))
        ->keys()->flatMap(fn ($s) => Permisos::permisosDe($s))->all());

    return $rol;
}

function quienSale(array $extra = []): Empleado
{
    static $n = 0;
    $n++;

    return Empleado::create(array_merge([
        'nombre' => "Quien sale {$n}", 'tipo_documento' => 'CC', 'numero_documento' => "tes{$n}",
        'cargo' => 'Auxiliar', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 2_000_000, 'fecha_ingreso' => '2026-01-15', 'estado' => 'activo',
        'arl_nivel_riesgo' => 'I',
    ], $extra));
}

// ── El problema que la bandeja resuelve ──────────────────────────────────────

it('tesorería no puede abrir la ficha donde estaban los botones', function () {
    /*
     * Esto NO es un defecto que haya que arreglar abriendo la ficha: la ficha
     * lleva el expediente entero —hoja de vida, documentos, conceptos
     * psicotécnicos— y tesorería no necesita nada de eso para pagar.
     *
     * Lo que estaba mal era no darle ninguna otra puerta.
     */
    $e = quienSale();

    expect(Permisos::puede($this->silvia, 'rrhh'))->toBeFalse();

    $this->actingAs($this->silvia)->get("/app/rrhh/empleados/{$e->id}")->assertForbidden();

    // Pero su bandeja sí abre.
    $this->actingAs($this->silvia)->get('/app/rrhh/tesoreria')->assertOk();
});

it('la bandeja es de quien paga, no de quien liquida', function () {
    $this->actingAs($this->elena)->get('/app/rrhh/tesoreria')->assertForbidden();
    $this->actingAs($this->silvia)->get('/app/rrhh/tesoreria')->assertOk();
});

// ── Qué muestra ──────────────────────────────────────────────────────────────

it('muestra sólo lo aprobado que todavía no se ha pagado', function () {
    quienSale();

    $this->actingAs($this->elena)->post('/app/rrhh/nomina/liquidar', ['periodo' => '2026-10']);
    $n = Nomina::where('periodo', '2026-10')->firstOrFail();

    // Todavía en borrador: no es asunto de tesorería.
    $this->actingAs($this->silvia)->get('/app/rrhh/tesoreria')
        ->assertInertia(fn ($p) => $p->has('nominas', 0));

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");

    $this->actingAs($this->silvia)->get('/app/rrhh/tesoreria')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('nominas', 1)
            // El JSON serializa el decimal como entero cuando no tiene centavos.
            ->where('nominas.0.neto', fn ($v) => (float) $v === (float) $n->refresh()->total_neto));

    $this->actingAs($this->silvia)
        ->post("/app/rrhh/nomina/{$n->id}/pagar", ['fecha_pago' => '2026-10-30']);

    // Pagada: sale de la bandeja.
    $this->actingAs($this->silvia)->get('/app/rrhh/tesoreria')
        ->assertInertia(fn ($p) => $p->has('nominas', 0));
});

it('trae las liquidaciones aprobadas de quienes se fueron', function () {
    $e = quienSale(['fecha_retiro' => '2026-06-30', 'estado' => 'retirado', 'tipo_retiro' => 'renuncia']);

    $this->actingAs($this->elena)->post("/app/rrhh/empleados/{$e->id}/liquidacion");
    $this->actingAs($this->junta)->post("/app/rrhh/empleados/{$e->id}/liquidacion/aprobar");

    $this->actingAs($this->silvia)->get('/app/rrhh/tesoreria')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('liquidaciones', 1)
            ->where('liquidaciones.0.nombre', $e->nombre));
});

it('trae los paz y salvo que esperan su firma, con los conceptos a la vista', function () {
    /*
     * Firmar sin ver sobre qué se firma es lo mismo que no firmar. La bandeja
     * muestra los conceptos que tesorería misma escribió.
     */
    $e = quienSale(['fecha_retiro' => '2026-06-30', 'estado' => 'retirado']);

    $this->actingAs($this->silvia)->post("/app/rrhh/empleados/{$e->id}/entrega-cargo", [
        'fecha' => '2026-06-30',
        'items' => [['bloque' => 'paz_salvo', 'nombre' => 'Anticipo de nómina', 'detalle' => 'Sin saldo']],
    ]);

    $this->actingAs($this->silvia)->get('/app/rrhh/tesoreria')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('entregas', 1)
            ->where('entregas.0.conceptos.0.nombre', 'Anticipo de nómina'));
});

it('no muestra los datos del expediente, sólo lo que hace falta para pagar', function () {
    /*
     * La razón de que sea una pantalla aparte y no la ficha: tesorería
     * necesita a quién, cuánto y por qué concepto. Nada más.
     */
    $e = quienSale(['fecha_retiro' => '2026-06-30', 'estado' => 'retirado', 'tipo_retiro' => 'renuncia']);

    $this->actingAs($this->elena)->post("/app/rrhh/empleados/{$e->id}/liquidacion");
    $this->actingAs($this->junta)->post("/app/rrhh/empleados/{$e->id}/liquidacion/aprobar");

    $this->actingAs($this->silvia)->get('/app/rrhh/tesoreria')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->has('liquidaciones.0', fn ($l) => $l
                ->hasAll(['empleado_id', 'nombre', 'documento', 'retiro', 'neto', 'aprobada', 'aprobada_por'])
                // Ni el salario base, ni el desglose, ni las observaciones.
                ->missing('salario_base')
                ->missing('cesantias')));
});

// ── Y desde la bandeja sí puede firmar ───────────────────────────────────────

it('desde la bandeja tesorería sí puede dar su conformidad', function () {
    $e = quienSale(['fecha_retiro' => '2026-06-30', 'estado' => 'retirado']);

    $this->actingAs($this->silvia)->post("/app/rrhh/empleados/{$e->id}/entrega-cargo", [
        'fecha' => '2026-06-30',
        'items' => [['bloque' => 'paz_salvo', 'nombre' => 'Nada pendiente', 'detalle' => null]],
    ]);

    $this->actingAs($this->silvia)
        ->post("/app/rrhh/empleados/{$e->id}/entrega-cargo/tesoreria/confirmar")
        ->assertRedirect();

    expect(EntregaCargo::where('empleado_id', $e->id)->first()->tesoreria_confirmo_por_id)
        ->toBe($this->silvia->id);
});
