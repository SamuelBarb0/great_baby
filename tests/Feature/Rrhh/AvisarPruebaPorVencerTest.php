<?php

/**
 * Que nadie se entere tarde de un período de prueba.
 *
 * La pantalla de Empleados ya mostraba los días que quedaban, y el modelo ya
 * traía el scope para encontrarlos. Pero mostrar no es avisar: había que
 * entrar y mirar, y si nadie entraba el período se vencía solo.
 *
 * Vencerse solo cuesta: pasado el período, terminar el contrato deja de ser
 * libre y pasa a necesitar justa causa. Es de los pocos plazos del ERP donde
 * no enterarse tiene consecuencia legal.
 */

use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Rrhh\Models\Empleado;
use App\Support\Reglas;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
    Reglas::limpiarRequestCache();
    $this->seed(\Database\Seeders\PermisosSeeder::class);

    /*
     * Sin alguien de Gestion Humana no hay a quien avisarle, y ese es el punto:
     * el aviso tiene destinatario. Antes salia con `user_id` nulo, que en la
     * campana significa "lo ve todo el ERP" -alistadores incluidos- y un aviso
     * que le aparece a todos no le pertenece a nadie.
     */
    $rol = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'GestionHumanaTest', 'guard_name' => 'web']);
    $rol->givePermissionTo('ver.rrhh');

    $this->gh = User::factory()->create(['name' => 'Elena de Gestion Humana']);
    $this->gh->assignRole($rol);
});

function empleadoConPrueba(?int $diasRestantes, array $extra = []): Empleado
{
    static $n = 0;
    $n++;

    return Empleado::create(array_merge([
        'nombre' => 'Empleado de prueba '.$n,
        'numero_documento' => 'CCPR'.$n,
        'cargo' => 'Auxiliar',
        'area' => 'Logística',
        'tipo_contrato' => 'indefinido',
        'estado' => 'activo',
        'fecha_ingreso' => now()->subMonths(1)->toDateString(),
        'prueba_hasta' => $diasRestantes === null ? null
            : now()->addDays($diasRestantes)->toDateString(),
    ], $extra));
}

it('avisa de un período que vence pronto', function () {
    empleadoConPrueba(5);

    $this->artisan('rrhh:avisar-prueba')
        ->expectsOutputToContain('vence en 5 días')
        ->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'rrhh_prueba')->count())->toBe(1);
});

it('no avisa de uno que todavía está lejos', function () {
    empleadoConPrueba(60);

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'rrhh_prueba')->count())->toBe(0);
});

it('uno que ya se venció sale en rojo y dice hace cuánto', function () {
    empleadoConPrueba(-4);

    $this->artisan('rrhh:avisar-prueba')
        ->expectsOutputToContain('YA SE VENCIÓ hace 4 días')
        ->assertSuccessful();

    $aviso = NotificacionErp::where('tipo', 'rrhh_prueba')->first();

    expect($aviso->color)->toBe('danger');
});

it('no avisa de uno ya evaluado', function () {
    empleadoConPrueba(5, ['prueba_evaluada_at' => now()]);

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'rrhh_prueba')->count())->toBe(0);
});

it('no avisa de quien ya no trabaja acá', function () {
    empleadoConPrueba(5, ['estado' => 'retirado']);

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'rrhh_prueba')->count())->toBe(0);
});

it('al jefe se le avisa mientras NO haya opinado, que es cuando hace falta', function () {
    $jefe = User::factory()->create(['name' => 'Jorge jefe de area']);

    // El jefe del area sale de la requisicion por la que entro la persona.
    $req = \App\Modules\Rrhh\Models\Requisicion::create([
        'numero' => 'REQ-PRU-1', 'cargo' => 'Auxiliar', 'area' => 'Logística',
        'cantidad' => 1, 'motivo' => 'reemplazo', 'estado' => 'aprobada',
        'solicitante_id' => $jefe->id,
    ]);
    $vac = \App\Modules\Rrhh\Models\Vacante::create([
        'requisicion_id' => $req->id, 'titulo' => 'Auxiliar', 'area' => 'Logística',
        'estado' => 'cerrada',
    ]);

    $e = empleadoConPrueba(3, ['vacante_id' => $vac->id]);

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();

    // Gestión Humana y el jefe: la evaluación necesita las dos firmas.
    expect(NotificacionErp::where('tipo', 'rrhh_prueba')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->gh->id, $jefe->id])->sort()->values()->all());

    // Ya opinó: deja de molestarlo a él, Gestión Humana sigue recibiendo.
    NotificacionErp::query()->delete();
    $e->forceFill(['prueba_jefe_at' => now()])->saveQuietly();

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'rrhh_prueba')->pluck('user_id')->all())
        ->toBe([$this->gh->id]);
});

it('el aviso tiene destinatario: nunca sale como broadcast', function () {
    empleadoConPrueba(3);

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();

    /*
     * Un aviso sin `user_id` lo ve TODO el ERP. Antes salía así cuando el jefe
     * todavía no había opinado, y cuando sí había opinado le llegaba sólo a
     * él: Gestión Humana, que es quien cierra la evaluación, no lo recibía
     * nunca. Los dos lados estaban mal.
     */
    $avisos = NotificacionErp::where('tipo', 'rrhh_prueba')->get();

    expect($avisos)->toHaveCount(1)
        ->and($avisos->first()->user_id)->toBe($this->gh->id)
        ->and($avisos->pluck('user_id')->filter(fn ($id) => $id === null))->toBeEmpty();
});

it('la anticipación sale de la regla', function () {
    empleadoConPrueba(25);

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();
    expect(NotificacionErp::where('tipo', 'rrhh_prueba')->count())->toBe(0);

    Reglas::set('rrhh.dias_aviso_prueba', 30);
    Reglas::limpiarRequestCache();

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();
    expect(NotificacionErp::where('tipo', 'rrhh_prueba')->count())->toBe(1);
});

it('con --dry-run dice qué falta pero no avisa', function () {
    empleadoConPrueba(5);

    $this->artisan('rrhh:avisar-prueba', ['--dry-run' => true])->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'rrhh_prueba')->count())->toBe(0);
});
