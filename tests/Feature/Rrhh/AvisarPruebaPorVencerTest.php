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

    expect(NotificacionErp::where('tipo', 'prueba_por_vencer')->count())->toBe(1);
});

it('no avisa de uno que todavía está lejos', function () {
    empleadoConPrueba(60);

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'prueba_por_vencer')->count())->toBe(0);
});

it('uno que ya se venció sale en rojo y dice hace cuánto', function () {
    empleadoConPrueba(-4);

    $this->artisan('rrhh:avisar-prueba')
        ->expectsOutputToContain('YA SE VENCIÓ hace 4 días')
        ->assertSuccessful();

    $aviso = NotificacionErp::where('tipo', 'prueba_por_vencer')->first();

    expect($aviso->color)->toBe('danger');
});

it('no avisa de uno ya evaluado', function () {
    empleadoConPrueba(5, ['prueba_evaluada_at' => now()]);

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'prueba_por_vencer')->count())->toBe(0);
});

it('no avisa de quien ya no trabaja acá', function () {
    empleadoConPrueba(5, ['estado' => 'retirado']);

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'prueba_por_vencer')->count())->toBe(0);
});

it('le llega al jefe que ya opinó', function () {
    $jefe = User::factory()->create();
    empleadoConPrueba(3, ['prueba_jefe_por_id' => $jefe->id]);

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'prueba_por_vencer')->value('user_id'))
        ->toBe($jefe->id);
});

it('si nadie ha opinado, el aviso es para todos', function () {
    empleadoConPrueba(3);

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();

    // `user_id` nulo = lo ve Gestión Humana y quien tenga el permiso.
    expect(NotificacionErp::where('tipo', 'prueba_por_vencer')->value('user_id'))->toBeNull();
});

it('la anticipación sale de la regla', function () {
    empleadoConPrueba(25);

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();
    expect(NotificacionErp::where('tipo', 'prueba_por_vencer')->count())->toBe(0);

    Reglas::set('rrhh.dias_aviso_prueba', 30);
    Reglas::limpiarRequestCache();

    $this->artisan('rrhh:avisar-prueba')->assertSuccessful();
    expect(NotificacionErp::where('tipo', 'prueba_por_vencer')->count())->toBe(1);
});

it('con --dry-run dice qué falta pero no avisa', function () {
    empleadoConPrueba(5);

    $this->artisan('rrhh:avisar-prueba', ['--dry-run' => true])->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'prueba_por_vencer')->count())->toBe(0);
});
