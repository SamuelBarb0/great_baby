<?php

/**
 * Quien crea no aprueba.
 *
 * El control existía en el código —con su guarda, su mensaje de 403 y un
 * comentario diciendo que el default era `false`— pero el código ponía `true`.
 * Entonces `! $permitirAuto` era **siempre falso** y el 403 no salía nunca.
 * Peor: la clave no estaba registrada en `Reglas::DEFAULTS`, así que
 * `Reglas::set()` la rechazaba con «Regla desconocida» y no había forma de
 * arreglarlo desde la interfaz. Era un control decorativo permanente.
 *
 * Con eso, una sola persona podía inventar un proveedor, emitirle una orden de
 * compra, «recibirla» y meter el costo al inventario y al libro fiscal sin que
 * nadie más interviniera. El fraude de compras clásico, sin un segundo par de
 * ojos en ningún punto.
 *
 * Gerencia queda exenta a propósito: el control es para que un Contador o un
 * AdminBodega no se apruebe lo suyo, no para trabar a la dueña del negocio.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use App\Support\Reglas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Reglas::limpiarRequestCache();

    $this->proveedor = Contacto::create([
        'numero_documento' => '901777666', 'nombre_completo' => 'Proveedor Segregación SAS', 'es_proveedor' => true,
    ]);
});

function usuarioCon(string $rol): User
{
    $u = User::factory()->create();
    $u->assignRole(Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']));

    return $u;
}

function ordenCreadaPor(User $u): OrdenCompra
{
    $oc = OrdenCompra::create([
        'numero' => 'OC-SEG-'.fake()->unique()->numberBetween(1000, 9999),
        'proveedor_id' => test()->proveedor->id,
        'fecha_emision' => now()->toDateString(),
        'estado' => 'borrador',
        'subtotal' => 1_000_000, 'descuento' => 0, 'iva' => 0, 'total' => 1_000_000,
        'creado_por' => $u->id,
    ]);

    // La accion valida los items antes que la segregacion.
    \Illuminate\Support\Facades\DB::table('compra_orden_items')->insert([
        'orden_id' => $oc->id, 'descripcion' => 'Mercancia',
        'cantidad' => 1, 'precio_unit' => 1_000_000,
        'subtotal' => 1_000_000, 'total' => 1_000_000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $oc->fresh();
}

it('el control está ACTIVO por defecto', function () {
    // El default decía `true` contra su propio comentario.
    expect(setting('compras.permitir_auto_aprobar'))->toBeFalse()
        ->and(setting('contabilidad.permitir_auto_aprobar_asiento'))->toBeFalse();
});

it('un Contador no puede aprobar la orden que él mismo creó', function () {
    $contador = usuarioCon('Contador');
    $oc = ordenCreadaPor($contador);

    $this->actingAs($contador);

    expect(fn () => AprobarOrdenCompra::run($oc->fresh()))
        ->toThrow(HttpException::class, 'Segregación de funciones');

    expect($oc->fresh()->estado->value)->toBe('borrador');
});

it('el mensaje dice cómo desactivarlo, para que nadie quede trabado', function () {
    $contador = usuarioCon('Contador');
    $oc = ordenCreadaPor($contador);

    $this->actingAs($contador);

    expect(fn () => AprobarOrdenCompra::run($oc->fresh()))
        ->toThrow(HttpException::class, 'Reglas de negocio');
});

it('otra persona sí puede aprobarla', function () {
    // El control es sobre quien la creó, no sobre el rol.
    $contador = usuarioCon('Contador');
    $oc = ordenCreadaPor($contador);

    $this->actingAs(usuarioCon('Gerente'));
    AprobarOrdenCompra::run($oc->fresh());

    expect($oc->fresh()->estado->value)->toBe('aprobada');
});

it('Gerencia puede aprobar lo suyo: el control no es para la dueña', function () {
    $aracely = usuarioCon('Aracely');
    $oc = ordenCreadaPor($aracely);

    $this->actingAs($aracely);
    AprobarOrdenCompra::run($oc->fresh());

    expect($oc->fresh()->estado->value)->toBe('aprobada');
});

it('se puede apagar desde las reglas, sin tocar código', function () {
    /*
     * Ésta es la mitad que faltaba: las claves no estaban registradas, así que
     * `Reglas::set()` las rechazaba con «Regla desconocida». El control era
     * imposible de ajustar desde la interfaz.
     */
    Reglas::set('compras.permitir_auto_aprobar', true);
    Reglas::limpiarRequestCache();

    $contador = usuarioCon('Contador');
    $oc = ordenCreadaPor($contador);

    $this->actingAs($contador);
    AprobarOrdenCompra::run($oc->fresh());

    expect($oc->fresh()->estado->value)->toBe('aprobada');
});
