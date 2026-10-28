<?php

/**
 * Las tres manos, y qué pasa cuando una sola las junta.
 *
 * La nómina, la liquidación definitiva y la entrega de cargo tienen el mismo
 * control: tres decisiones de tres personas distintas. Los permisos no
 * alcanzan a garantizarlo, y eso se ve al recorrer los roles:
 *
 *   · **Gerente** tiene `rrhh`, `rrhh_nomina_aprobar` y `contabilidad`: puede
 *     liquidar, aprobar y pagar la nómina él solo.
 *   · **Aracely y Gerencia** son root y pasan siempre.
 *   · **GestionHumana** también lleva requisiciones, así que puede firmar el
 *     bloque del jefe en la entrega de cargo además del suyo.
 *
 * No se bloquea: en una empresa de este tamaño la dueña firma por todos, y
 * bloquearlo dejaría la nómina sin poder salir. Lo que sí se hace es decirlo,
 * porque un control que no se cumplió y además no se ve es peor que no
 * tenerlo — el papel afirma tres revisiones donde hubo una.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Liquidacion;
use App\Modules\Rrhh\Models\Nomina;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->junta = User::factory()->create(['name' => 'Junta Directiva']);
    $this->junta->assignRole(rolDeManos('Gerente'));

    $this->silvia = User::factory()->create(['name' => 'Silvia']);
    $this->silvia->assignRole(rolDeManos('Contador'));
});

function rolDeManos(string $nombre): Role
{
    $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

    $rol->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($nombre, $roles, true))
        ->keys()->map(fn ($s) => Permisos::permiso($s))->all());

    return $rol;
}

function alguienEnNomina(): Empleado
{
    static $n = 0;
    $n++;

    return Empleado::create([
        'nombre' => "Trabajador {$n}", 'tipo_documento' => 'CC', 'numero_documento' => "man{$n}",
        'cargo' => 'Auxiliar', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 2_000_000, 'fecha_ingreso' => '2026-01-15', 'estado' => 'activo',
        'arl_nivel_riesgo' => 'I',
    ]);
}

// ── Lo que los permisos NO garantizan ────────────────────────────────────────

it('el rol Gerente puede hacer las tres manos de la nómina', function () {
    /*
     * No es un defecto que haya que tapar: es un hecho de cómo está repartida
     * la matriz, y por eso existe el aviso. Esta prueba lo deja escrito para
     * que nadie lo descubra de nuevo creyendo que el control era hermético.
     */
    expect(Permisos::puede($this->junta, 'rrhh'))->toBeTrue()
        ->and(Permisos::puede($this->junta, 'rrhh_nomina_aprobar'))->toBeTrue()
        ->and(Permisos::puede($this->junta, 'contabilidad'))->toBeTrue();

    // Gestión Humana y tesorería, en cambio, tienen una sola cada una.
    expect(Permisos::puede($this->elena, 'rrhh_nomina_aprobar'))->toBeFalse()
        ->and(Permisos::puede($this->silvia, 'rrhh'))->toBeFalse();
});

// ── El aviso en la nómina ────────────────────────────────────────────────────

it('avisa cuando una misma persona liquidó, aprobó y pagó', function () {
    alguienEnNomina();

    $this->actingAs($this->junta)->post('/app/rrhh/nomina/liquidar', ['periodo' => '2026-10']);
    $n = Nomina::where('periodo', '2026-10')->firstOrFail();

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");
    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/pagar", ['fecha_pago' => '2026-10-30']);

    expect($n->refresh()->hizoVariasManos())->toBe(['Junta Directiva']);
});

it('no avisa nada cuando cada quien hizo la suya', function () {
    alguienEnNomina();

    $this->actingAs($this->elena)->post('/app/rrhh/nomina/liquidar', ['periodo' => '2026-10']);
    $n = Nomina::where('periodo', '2026-10')->firstOrFail();

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");
    $this->actingAs($this->silvia)->post("/app/rrhh/nomina/{$n->id}/pagar", ['fecha_pago' => '2026-10-30']);

    expect($n->refresh()->hizoVariasManos())->toBe([]);
});

it('una nómina a medias todavía no tiene nada que avisar', function () {
    alguienEnNomina();

    $this->actingAs($this->junta)->post('/app/rrhh/nomina/liquidar', ['periodo' => '2026-10']);

    expect(Nomina::where('periodo', '2026-10')->first()->hizoVariasManos())->toBe([]);
});

// ── El aviso en la liquidación definitiva ────────────────────────────────────

it('avisa lo mismo en la liquidación de quien se va', function () {
    $e = alguienEnNomina();
    $e->update(['fecha_retiro' => '2026-06-30', 'estado' => 'retirado', 'tipo_retiro' => 'renuncia']);

    $this->actingAs($this->junta)->post("/app/rrhh/empleados/{$e->id}/liquidacion");
    $this->actingAs($this->junta)->post("/app/rrhh/empleados/{$e->id}/liquidacion/aprobar");
    $this->actingAs($this->junta)
        ->post("/app/rrhh/empleados/{$e->id}/liquidacion/pagar", ['fecha_pago' => '2026-07-05']);

    expect(Liquidacion::where('empleado_id', $e->id)->first()->hizoVariasManos())
        ->toBe(['Junta Directiva']);
});

it('el aviso llega a la pantalla, no se queda en el modelo', function () {
    /*
     * De nada sirve que el modelo lo sepa si la pantalla no lo dice: el punto
     * entero del aviso es que lo vea quien mira la nómina.
     */
    alguienEnNomina();

    $this->actingAs($this->junta)->post('/app/rrhh/nomina/liquidar', ['periodo' => '2026-10']);
    $n = Nomina::where('periodo', '2026-10')->firstOrFail();
    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");

    $this->actingAs($this->junta)->get('/app/rrhh/nomina?periodo=2026-10')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('nomina.manos_repetidas', ['Junta Directiva']));
});
