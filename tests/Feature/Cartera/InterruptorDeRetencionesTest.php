<?php

/**
 * Prender y apagar una regla de retención es de un clic.
 *
 * La columna «Activa» de /app/cartera/retenciones era un punto pintado: verde
 * si la regla estaba prendida, rojo si no, y nada más. Para cambiarla había que
 * abrir el lápiz, buscar la casilla dentro del formulario, marcarla y guardar
 * — por cada una de las 33 reglas que trae el catálogo de la DIAN.
 *
 * Y es lo que se hace a diario: cambia la ley, cambia el proveedor, se prende
 * o se apaga la regla. Editar la tarifa, casi nunca.
 *
 * Lo que se verifica acá:
 *   · el interruptor prende y apaga de verdad, y lo dice;
 *   · el estado nuevo lo calcula el SERVIDOR, no el navegador;
 *   · una regla sin cuenta del PUC no se prende, y el mensaje dice qué falta;
 *   · una regla incompleta que quedó prendida SÍ se puede apagar;
 *   · quien no lleva la contabilidad no lo puede tocar.
 *
 * El seguro de la cuenta no es formalismo: una regla activa calcula la
 * retención en cada factura de compra y arma su asiento. Sin cuenta el asiento
 * sale mal y nadie lo nota hasta el cierre. De las 33 reglas cargadas en la
 * base de desarrollo, 11 entraron sin cuenta.
 */

use App\Models\User;
use App\Modules\Cartera\Models\RetencionConfig;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    /*
     * Permisos sembrados a propósito: `Permisos::puede()` sólo mira permisos
     * si `permisosSembrados()` dice que sí, y esa respuesta queda en una
     * `static` que vive todo el proceso. Sin sembrar acá, esta prueba pasaría
     * o fallaría según qué hubiera corrido antes.
     */
    $this->seed(Database\Seeders\PermisosSeeder::class);

    $rolContador = Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']);
    $rolContador->syncPermissions(collect(App\Auth\Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array('Contador', $roles, true))
        ->keys()->map(fn ($s) => App\Auth\Permisos::permiso($s))->all());

    $this->silvia = User::factory()->create(['name' => 'Silvia']);
    $this->silvia->assignRole($rolContador);
});

/** Una regla de retención, apagada salvo que el caso diga otra cosa. */
function unaReglaDeRetencion(array $extra = []): RetencionConfig
{
    return RetencionConfig::create(array_merge([
        'tipo' => 'retefuente',
        'ambito' => 'compras',
        'concepto' => 'Compras generales (declarantes renta)',
        'base_minima' => 1_138_950,
        'tarifa_pct' => 2.5,
        'cuenta_puc' => '23654001',
        'activa' => false,
    ], $extra));
}

// ───────────────────────── Lo que se hace a diario ─────────────────────────

it('un clic prende la regla y la pantalla lo dice', function () {
    $regla = unaReglaDeRetencion();

    $this->actingAs($this->silvia)
        ->post("/app/cartera/retenciones/{$regla->id}/activa")
        ->assertRedirect()
        ->assertSessionHas('flash.type', 'success');

    expect($regla->fresh()->activa)->toBeTrue();
});

it('otro clic la apaga', function () {
    $regla = unaReglaDeRetencion(['activa' => true]);

    $this->actingAs($this->silvia)
        ->post("/app/cartera/retenciones/{$regla->id}/activa")
        ->assertRedirect();

    expect($regla->fresh()->activa)->toBeFalse();
});

it('el aviso dice en qué quedó la regla, no sólo «guardado»', function () {
    $regla = unaReglaDeRetencion();

    $this->actingAs($this->silvia)->post("/app/cartera/retenciones/{$regla->id}/activa");

    // Con 33 reglas en pantalla, un «listo» genérico no deja saber cuál se
    // tocó ni en qué quedó.
    expect(session('flash')['message'])
        ->toContain('Compras generales (declarantes renta)')
        ->toContain('activa');
});

it('el estado nuevo lo calcula el servidor, no el navegador', function () {
    $regla = unaReglaDeRetencion(['activa' => true]);

    /*
     * Si la pantalla quedó abierta un rato y alguien más cambió la regla, un
     * `activa` enviado desde el navegador pisaría ese cambio con lo que el
     * navegador recordaba. Por eso el endpoint no lo lee: invierte lo guardado.
     */
    $this->actingAs($this->silvia)
        ->post("/app/cartera/retenciones/{$regla->id}/activa", ['activa' => true]);

    expect($regla->fresh()->activa)->toBeFalse();
});

// ───────────────────────── El seguro de la cuenta ─────────────────────────

it('una regla sin cuenta del PUC no se prende', function () {
    $regla = unaReglaDeRetencion(['cuenta_puc' => null]);

    $this->actingAs($this->silvia)
        ->post("/app/cartera/retenciones/{$regla->id}/activa")
        ->assertRedirect()
        ->assertSessionHas('flash.type', 'error');

    expect($regla->fresh()->activa)->toBeFalse()
        ->and(session('flash')['message'])->toContain('cuenta del PUC');
});

it('una autorretención sin la cuenta de activo tampoco', function () {
    $regla = unaReglaDeRetencion([
        'tipo' => 'autorrenta', 'ambito' => 'ventas',
        'concepto' => 'Autorretención de renta sobre ventas',
        'cuenta_puc' => '23657501', 'cuenta_puc_contrapartida' => null,
    ]);

    // Una autorretención mueve dos cuentas: la 23 de pasivo contra la 13 de
    // activo. Con una sola, cada factura emitida saldría con un asiento
    // descuadrado.
    $this->actingAs($this->silvia)
        ->post("/app/cartera/retenciones/{$regla->id}/activa")
        ->assertSessionHas('flash.type', 'error');

    expect($regla->fresh()->activa)->toBeFalse()
        ->and(session('flash')['message'])->toContain('13');
});

it('una regla incompleta que quedó prendida sí se puede apagar', function () {
    $regla = unaReglaDeRetencion(['cuenta_puc' => null, 'activa' => true]);

    // Apagar nunca se bloquea: con una regla incompleta aplicándose a cada
    // factura, poder apagarla es justo lo que más urge.
    $this->actingAs($this->silvia)
        ->post("/app/cartera/retenciones/{$regla->id}/activa")
        ->assertSessionHas('flash.type', 'success');

    expect($regla->fresh()->activa)->toBeFalse();
});

// ───────────────────────── Quién puede tocarlo ─────────────────────────

it('quien no lleva la contabilidad no mueve el interruptor', function () {
    $regla = unaReglaDeRetencion();

    $vendedor = User::factory()->create(['name' => 'Vendedor Demo']);
    $vendedor->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    $this->actingAs($vendedor)
        ->post("/app/cartera/retenciones/{$regla->id}/activa")
        ->assertStatus(403);

    expect($regla->fresh()->activa)->toBeFalse();
});

it('sin sesión tampoco', function () {
    $regla = unaReglaDeRetencion();

    $this->post("/app/cartera/retenciones/{$regla->id}/activa")
        ->assertRedirect('/login');

    expect($regla->fresh()->activa)->toBeFalse();
});
