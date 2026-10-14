<?php

/**
 * Un pedido aprobado tiene que poder facturarse.
 *
 * Encontrado el 2026-10-13 llevando un pedido de punta a punta por la
 * interfaz: se aprueba y queda en punto muerto. La bandeja de facturación
 * exigía `alistado_fin_at` —que ya estuviera alistado— y la cola de
 * alistamiento sólo muestra pedidos con `estado = 'facturado'`, con sus
 * acciones cortando en «Sólo se alistan pedidos facturados».
 *
 * Para facturar había que alistar; para alistar había que facturar. El pedido
 * no aparecía en ninguna de las dos pantallas y la venta B2B se moría ahí.
 *
 * El orden correcto lo dice el propio despacho en su guarda: «la factura ya
 * está impresa y acompaña físicamente la mercancía». Primero se factura.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Portal\Models\PedidoCliente;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->facturador = User::factory()->create();
    $this->facturador->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->cliente = Contacto::create([
        'numero_documento' => '901777888',
        'nombre_completo' => 'Pañalera Mundo Bebé SAS',
        'es_cliente' => true, 'es_cliente_b2b' => true,
        'ciudad' => 'Medellín',
    ]);
});

function pedidoAprobado(array $extra = []): PedidoCliente
{
    return PedidoCliente::create(array_merge([
        'numero' => 'PB-'.fake()->unique()->numerify('######-####'),
        'contacto_id' => Contacto::first()->id,
        'estado' => 'aprobado',
        'subtotal' => 124500, 'total' => 124500,
    ], $extra));
}

function enLaBandeja($test): \Illuminate\Support\Collection
{
    $props = $test->actingAs($test->facturador)->get('/app/facturacion/bandeja')
        ->assertOk()->viewData('page')['props'];

    return collect($props['pendientes'] ?? $props['pedidos'] ?? []);
}

it('el pedido aprobado aparece en la bandeja sin haber pasado por bodega', function () {
    $p = pedidoAprobado(['numero' => 'PB-SIN-ALISTAR']);

    expect(enLaBandeja($this)->pluck('numero'))->toContain('PB-SIN-ALISTAR')
        ->and($p->alistado_fin_at)->toBeNull();
});

it('la pantalla de facturar abre con un pedido aprobado', function () {
    $p = pedidoAprobado();

    $this->actingAs($this->facturador)
        ->get("/app/facturacion/preview/{$p->id}")
        ->assertOk();
});

it('el que ya tiene factura sale de la bandeja', function () {
    // Con una factura de verdad: `pedidos_cliente.factura_id` tiene clave
    // foránea, y un id inventado lo rechaza la base (bien que lo haga).
    $factura = App\Modules\Cartera\Models\FacturaVenta::create([
        'numero' => 'FV-TEST-0001',
        'contacto_id' => $this->cliente->id,
        'estado' => 'pendiente',
        'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => 124500, 'impuestos' => 0, 'total' => 124500,
    ]);

    pedidoAprobado(['numero' => 'PB-YA-FACTURADO', 'factura_id' => $factura->id]);

    expect(enLaBandeja($this)->pluck('numero'))->not->toContain('PB-YA-FACTURADO');
});

it('un pedido con novedad sin resolver no se factura', function () {
    // Esta sí es una condición legítima: si bodega encontró un problema y
    // nadie lo resolvió, facturar sería facturar mercancía que no está.
    pedidoAprobado([
        'numero' => 'PB-CON-NOVEDAD',
        'alistado_fin_at' => now(),
        'alistado_con_novedad' => true,
        'novedad_resuelta_at' => null,
    ]);

    expect(enLaBandeja($this)->pluck('numero'))->not->toContain('PB-CON-NOVEDAD');
});

it('resuelta la novedad, vuelve a la bandeja', function () {
    pedidoAprobado([
        'numero' => 'PB-RESUELTA',
        'alistado_fin_at' => now(),
        'alistado_con_novedad' => true,
        'novedad_resuelta_at' => now(),
    ]);

    expect(enLaBandeja($this)->pluck('numero'))->toContain('PB-RESUELTA');
});

it('el que todavía no aprueba gerencia no se factura', function () {
    pedidoAprobado(['numero' => 'PB-ENVIADO', 'estado' => 'enviado']);

    expect(enLaBandeja($this)->pluck('numero'))->not->toContain('PB-ENVIADO');
});
