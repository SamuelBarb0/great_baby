<?php

/**
 * Quien pide el personal no lo aprueba.
 *
 * La pantalla de solicitudes dice arriba «Lo que piden los líderes de área.
 * Acá se aprueba y se abre la vacante»… y le mostraba el botón de crear a
 * quien aprueba. Con una sola cuenta de Gestión Humana se podía pedir
 * personal, enviárselo a uno mismo y autorizarlo.
 *
 * Probado el 28-oct-2026 con la cuenta de Elena: tres clics, y la bitácora
 * quedó diciendo «Elena Gestión Humana envió la solicitud a Gestión Humana».
 *
 * Pedir personal es comprometer un sueldo todos los meses. Es el mismo control
 * que el del pago a proveedor y por la misma razón: revisar lo propio no es
 * revisar.
 *
 * El control mira la PERSONA, no el rol: siete usuarios pueden aprobar
 * —Gerencia, Gerente y Gestión Humana— así que ninguna solicitud se queda sin
 * quien la mire.
 */

use App\Models\User;
use App\Modules\Rrhh\Enums\EstadoRequisicion;
use App\Modules\Rrhh\Models\Requisicion;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->otraDeGh = User::factory()->create(['name' => 'Otra de Gestión Humana']);
    $this->otraDeGh->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->lider = User::factory()->create(['name' => 'Jorge']);
    $this->lider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));
});

/** Una solicitud enviada, pedida por quien diga el parámetro. */
function solicitudPedidaPor(User $quien): Requisicion
{
    return Requisicion::create([
        'numero' => Requisicion::siguienteNumero(),
        'cargo' => 'Auxiliar de bodega',
        'area' => 'Logística',
        'numero_vacantes' => 1,
        'motivo' => 'Reemplazo de quien renunció.',
        'solicitante_id' => $quien->id,
        'estado' => EstadoRequisicion::Enviada->value,
        'enviada_at' => now(),
    ]);
}

it('quien pidió la solicitud no la puede aprobar', function () {
    $x = solicitudPedidaPor($this->elena);

    $this->actingAs($this->elena)
        ->post("/app/rrhh/requisiciones/{$x->id}/aprobar")
        ->assertStatus(422);

    expect($x->fresh()->estado)->toBe(EstadoRequisicion::Enviada);
});

it('tampoco la puede rechazar', function () {
    $x = solicitudPedidaPor($this->elena);

    // Rechazar lo propio es la otra mitad del mismo problema: deja a una
    // persona decidiendo sola sobre su propio pedido, en los dos sentidos.
    $this->actingAs($this->elena)
        ->post("/app/rrhh/requisiciones/{$x->id}/rechazar", ['motivo' => 'Ya no la necesito.'])
        ->assertStatus(422);

    expect($x->fresh()->estado)->toBe(EstadoRequisicion::Enviada);
});

it('otra persona del mismo equipo sí la aprueba', function () {
    $x = solicitudPedidaPor($this->elena);

    // El control es por persona, no por rol: si fuera por rol, Gestión Humana
    // entera quedaría sin poder aprobar lo que pide cualquiera de ellos.
    $this->actingAs($this->otraDeGh)
        ->post("/app/rrhh/requisiciones/{$x->id}/aprobar")
        ->assertRedirect();

    expect($x->fresh()->estado)->toBe(EstadoRequisicion::Aprobada);
});

it('lo normal sigue funcionando: la pide el líder y la aprueba Gestión Humana', function () {
    $x = solicitudPedidaPor($this->lider);

    $this->actingAs($this->elena)
        ->post("/app/rrhh/requisiciones/{$x->id}/aprobar")
        ->assertRedirect();

    expect($x->fresh()->estado)->toBe(EstadoRequisicion::Aprobada);
});

it('la pantalla no le ofrece a quien pidió un botón que el servidor va a negar', function () {
    $x = solicitudPedidaPor($this->elena);

    $props = $this->actingAs($this->elena)
        ->get("/app/rrhh/requisiciones/{$x->id}")
        ->assertOk()->viewData('page')['props'];

    // Si sólo se cerrara el backend, Elena vería Aprobar y Rechazar y se
    // comería un 422 al apretarlos: ofrecer y después negar es el defecto que
    // este ERP arrastra, y no se arregla a medias.
    expect($props['puede']['decidir'])->toBeFalse();

    $deOtro = solicitudPedidaPor($this->lider);

    $propsOtro = $this->actingAs($this->elena)
        ->get("/app/rrhh/requisiciones/{$deOtro->id}")
        ->assertOk()->viewData('page')['props'];

    expect($propsOtro['puede']['decidir'])->toBeTrue();
});
