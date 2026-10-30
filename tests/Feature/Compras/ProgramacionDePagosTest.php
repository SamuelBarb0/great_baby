<?php

/**
 * Qué facturas se pagan, cuándo, y con la firma de quién.
 *
 * Hoy esa decisión vive en una hoja impresa: las filas elegidas marcadas en
 * naranja y, al margen y a lápiz, «autorizaron 22 millones Octubre 27». No
 * hay rastro de quién autorizó esos 22 millones ni a qué facturas
 * corresponden. Si en enero alguien pregunta por qué se le pagó a un
 * proveedor antes que a otro, la respuesta está en un papel perdido.
 *
 * Tres reglas que el papel no puede imponer:
 *
 *  · No se programa más de lo que se debe de esa factura. Pasa cuando se
 *    teclea el valor total en vez del saldo, y nadie lo nota hasta que el
 *    proveedor avisa que le pagaron de más.
 *  · Quien arma la lista no la autoriza. Si fuera la misma persona, la firma
 *    al margen no significaría nada.
 *  · El monto autorizado se guarda aparte de la suma de los renglones: en el
 *    papel autorizan una cifra redonda y después la reparten, y que las dos
 *    no coincidan es información, no un error.
 */

use App\Auth\Permisos;
use App\Models\Contacto;
use App\Models\User;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\ProgramacionPago;
use App\Modules\Compras\Models\RecepcionCompra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->seed(Database\Seeders\PermisosSeeder::class);

    $contador = Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']);
    $contador->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array('Contador', $roles, true))
        ->keys()->map(fn ($s) => Permisos::permiso($s))->all());

    $this->silvia = User::factory()->create(['name' => 'Silvia Contadora']);
    $this->silvia->assignRole($contador);

    // Gerencia: la que suelta la plata. Con sus permisos de la matriz, no
    // sólo el rol: sin ellos `esContable()` lo frena en el middleware y el
    // test probaría un 403, no la regla de los cuatro ojos.
    $rolGerente = Role::firstOrCreate(['name' => 'Gerente', 'guard_name' => 'web']);
    $rolGerente->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array('Gerente', $roles, true))
        ->keys()->map(fn ($s) => Permisos::permiso($s))->all());

    $this->gerente = User::factory()->create(['name' => 'Gerente Demo']);
    $this->gerente->assignRole($rolGerente);

    $this->proveedor = Contacto::create([
        'numero_documento' => '901000111', 'nombre_completo' => 'Arsutex',
        'es_proveedor' => true, 'ciudad' => 'Bogotá',
    ]);

    $this->bodega = \App\Modules\Dropi\Models\InventarioUbicacion::create([
        'codigo' => 'R-A-01', 'nombre' => 'Rack A', 'categoria' => 'venta', 'activa' => true,
    ]);
});

/** Nombre propio: en Pest las funciones del archivo son globales. */
function facturaPorPagar(object $caso, float $valor): RecepcionCompra
{
    $oc = OrdenCompra::create([
        'numero' => 'OC-'.fake()->unique()->numerify('####'),
        'proveedor_id' => $caso->proveedor->id,
        'tipo' => 'nacional', 'moneda' => 'COP',
        'fecha_emision' => now()->subDays(40)->toDateString(),
        'subtotal' => $valor, 'total' => $valor, 'estado' => 'aprobada',
    ]);

    return RecepcionCompra::create([
        'numero' => 'RC-'.fake()->unique()->numerify('####'),
        'orden_id' => $oc->id, 'bodega_id' => $caso->bodega->id,
        'fecha_recepcion' => now()->subDays(30)->toDateString(),
        'factura_proveedor' => 'FP-'.fake()->unique()->numerify('####'),
        'estado' => 'confirmada', 'total_recibido' => $valor,
        'confirmada_at' => now()->subDays(30),
    ]);
}

function programar(object $caso, array $items, array $extra = [])
{
    return $caso->actingAs($extra['como'] ?? $caso->silvia)
        ->post('/app/compras/programacion-pagos', array_merge([
            'fecha_programada' => now()->addDays(2)->toDateString(),
            'items' => $items,
        ], collect($extra)->except('como')->all()));
}

it('se arma una programación con varias facturas', function () {
    $a = facturaPorPagar($this, 10_000_000);
    $b = facturaPorPagar($this, 5_000_000);

    programar($this, [
        ['recepcion_id' => $a->id, 'monto' => 10_000_000],
        ['recepcion_id' => $b->id, 'monto' => 2_000_000],
    ])->assertRedirect();

    $p = ProgramacionPago::with('items')->firstOrFail();

    expect($p->numero)->toStartWith('PP-')
        ->and($p->estado)->toBe('borrador')
        ->and($p->items)->toHaveCount(2)
        // El abono parcial es normal: la plata no alcanza para todos.
        ->and($p->total())->toBe(12_000_000.0);
});

it('no se programa más de lo que se debe de esa factura', function () {
    $f = facturaPorPagar($this, 3_000_000);

    programar($this, [['recepcion_id' => $f->id, 'monto' => 8_000_000]])
        ->assertSessionHasErrors('items.0.monto');

    // Pasa al teclear el valor total en vez del saldo, y no se nota hasta
    // que el proveedor avisa que le pagaron de más.
    expect(ProgramacionPago::count())->toBe(0);
});

it('la misma factura no puede ir dos veces', function () {
    $f = facturaPorPagar($this, 5_000_000);

    programar($this, [
        ['recepcion_id' => $f->id, 'monto' => 1_000_000],
        ['recepcion_id' => $f->id, 'monto' => 1_000_000],
    ])->assertSessionHasErrors('items.1.recepcion_id');

    expect(ProgramacionPago::count())->toBe(0);
});

it('guarda el monto autorizado aparte de lo repartido', function () {
    $f = facturaPorPagar($this, 20_000_000);

    programar($this, [['recepcion_id' => $f->id, 'monto' => 18_000_000]],
        ['monto_autorizado' => 22_000_000]);

    $p = ProgramacionPago::with('items')->firstOrFail();

    // 22 millones autorizados, 18 repartidos: quedan 4 sin asignar. Verlo es
    // lo que evita que alguien pague de más creyendo que sobra.
    expect((float) $p->monto_autorizado)->toBe(22_000_000.0)
        ->and($p->total())->toBe(18_000_000.0)
        ->and($p->sinRepartir())->toBe(4_000_000.0);
});

// ───────────────────── La firma ─────────────────────

it('quien arma la programación no la autoriza', function () {
    $f = facturaPorPagar($this, 5_000_000);
    programar($this, [['recepcion_id' => $f->id, 'monto' => 5_000_000]], ['como' => $this->gerente]);

    $p = ProgramacionPago::firstOrFail();

    $this->actingAs($this->gerente)
        ->post("/app/compras/programacion-pagos/{$p->id}/autorizar")->assertRedirect();

    // Si el mismo que arma firma, la firma no dice nada.
    expect($p->fresh()->estado)->toBe('borrador')
        ->and($p->fresh()->autorizada_por)->toBeNull();
});

it('gerencia sí la autoriza, y queda su nombre y la hora', function () {
    $f = facturaPorPagar($this, 5_000_000);
    programar($this, [['recepcion_id' => $f->id, 'monto' => 5_000_000]]);

    $p = ProgramacionPago::firstOrFail();

    $this->actingAs($this->gerente)
        ->post("/app/compras/programacion-pagos/{$p->id}/autorizar")->assertRedirect();

    // Es el dato que hoy va a lápiz al margen y nadie puede recuperar después.
    expect($p->fresh()->estado)->toBe('autorizada')
        ->and($p->fresh()->autorizada_por)->toBe($this->gerente->id)
        ->and($p->fresh()->autorizada_at)->not->toBeNull();
});

it('contabilidad no autoriza el desembolso', function () {
    $f = facturaPorPagar($this, 5_000_000);
    programar($this, [['recepcion_id' => $f->id, 'monto' => 5_000_000]]);

    $p = ProgramacionPago::firstOrFail();

    $this->actingAs($this->silvia)
        ->post("/app/compras/programacion-pagos/{$p->id}/autorizar")->assertForbidden();

    expect($p->fresh()->estado)->toBe('borrador');
});

it('no se autoriza dos veces', function () {
    $f = facturaPorPagar($this, 5_000_000);
    programar($this, [['recepcion_id' => $f->id, 'monto' => 5_000_000]]);
    $p = ProgramacionPago::firstOrFail();

    $this->actingAs($this->gerente)->post("/app/compras/programacion-pagos/{$p->id}/autorizar");
    $primera = $p->fresh()->autorizada_at;

    $this->actingAs($this->gerente)->post("/app/compras/programacion-pagos/{$p->id}/autorizar");

    expect($p->fresh()->autorizada_at->toDateTimeString())->toBe($primera->toDateTimeString());
});

it('anular exige decir por qué', function () {
    $f = facturaPorPagar($this, 5_000_000);
    programar($this, [['recepcion_id' => $f->id, 'monto' => 5_000_000]]);
    $p = ProgramacionPago::firstOrFail();

    $this->actingAs($this->silvia)
        ->post("/app/compras/programacion-pagos/{$p->id}/anular", ['motivo' => ''])
        ->assertSessionHasErrors('motivo');

    $this->actingAs($this->silvia)
        ->post("/app/compras/programacion-pagos/{$p->id}/anular",
            ['motivo' => 'El proveedor renegocio el plazo a 60 dias.'])
        ->assertRedirect();

    expect($p->fresh()->estado)->toBe('anulada')
        ->and($p->fresh()->notas)->toContain('renegocio el plazo');
});

it('un vendedor no entra', function () {
    $v = User::factory()->create();
    $v->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    $this->actingAs($v)->get('/app/compras/programacion-pagos')->assertForbidden();
});
