<?php

/**
 * El historial del pedido no puede borrar lo que pasó.
 *
 * Dos huecos que salieron recorriendo el flujo el 17-nov-2026:
 *
 *   · El hito «retenido por cartera» se pintaba leyendo `estado === 'retenido'`,
 *     así que DESAPARECÍA en el momento en que Gerencia liberaba el pedido.
 *     Después nadie podía ver que el semáforo lo había frenado, ni quién
 *     decidió vender igual. Autorizar un despacho a un cliente con facturas
 *     vencidas es una decisión comercial con consecuencias, y las decisiones
 *     se firman.
 *
 *   · El historial terminaba en «despachado». Quién recibió la mercancía y
 *     cuándo vivía sólo en la pantalla de guías, así que desde el pedido no se
 *     podía contestar la pregunta más común que hace un cliente por teléfono.
 */

use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed(Database\Seeders\PermisosSeeder::class);

    $this->gerencia = User::factory()->create(['name' => 'Gerencia Demo']);
    $this->gerencia->assignRole(
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Gerencia', 'guard_name' => 'web'])
    );

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'HIST-1', 'nombre' => 'Bodega', 'categoria' => CategoriaUbicacion::Venta,
        'disponible_para_venta' => true, 'activa' => true,
    ]);

    $this->cliente = \App\Models\Contacto::create([
        'numero_documento' => '901777888', 'nombre_completo' => 'Pañalera Mundo Bebé',
        'es_cliente' => true, 'activo' => true, 'ciudad' => 'Medellín',
    ]);

    $this->pedido = PedidoCliente::create([
        'numero' => 'PB-HIST-0001',
        'contacto_id' => $this->cliente->id,
        'ubicacion_origen_id' => $this->bodega->id,
        'estado' => 'retenido',
        'motivo_retencion' => '2 factura(s) vencida(s)',
        'retenido_at' => now()->subHour(),
        'enviado_at' => now()->subHour(),
        'subtotal' => 44000, 'iva' => 0, 'total' => 44000,
    ]);
});

/** Los hitos del historial, tal como los pinta la pantalla. */
function hitos(PedidoCliente $p, User $quien): array
{
    $r = test()->actingAs($quien)->get("/app/pedidos-b2b/{$p->id}");
    $r->assertOk();

    return collect($r->viewData('page')['props']['pedido']['timeline'] ?? [])
        ->pluck('hito')->all();
}

it('mientras está retenido, el historial lo dice', function () {
    expect(hitos($this->pedido, $this->gerencia))->toContain('Pedido RETENIDO por cartera');
});

it('y lo SIGUE diciendo después de liberarlo', function () {
    // El defecto exacto: el hito se borraba al cambiar de estado.
    $this->actingAs($this->gerencia)->post("/app/pedidos-b2b/{$this->pedido->id}/aprobar");

    $hitos = hitos($this->pedido->fresh(), $this->gerencia);

    expect($hitos)
        ->toContain('Pedido RETENIDO por cartera')
        ->toContain('Retención liberada por Gerencia')
        ->toContain('Pedido aprobado');
});

it('la liberación queda firmada con nombre y hora', function () {
    $this->actingAs($this->gerencia)->post("/app/pedidos-b2b/{$this->pedido->id}/aprobar");

    $p = $this->pedido->fresh();

    expect($p->retencion_liberada_at)->not->toBeNull()
        ->and($p->retencion_liberada_por_id)->toBe($this->gerencia->id)
        // Y la fecha de la retención no se perdió.
        ->and($p->retenido_at)->not->toBeNull();
});

it('un pedido que nunca estuvo retenido no inventa el hito', function () {
    $limpio = PedidoCliente::create([
        'numero' => 'PB-HIST-0002',
        'contacto_id' => $this->cliente->id,
        'estado' => 'enviado',
        'enviado_at' => now(),
        'subtotal' => 10000, 'iva' => 0, 'total' => 10000,
    ]);

    $this->actingAs($this->gerencia)->post("/app/pedidos-b2b/{$limpio->id}/aprobar");

    $hitos = hitos($limpio->fresh(), $this->gerencia);

    expect($hitos)->not->toContain('Pedido RETENIDO por cartera')
        ->and($hitos)->not->toContain('Retención liberada por Gerencia')
        ->and($limpio->fresh()->retencion_liberada_at)->toBeNull();
});

it('la entrega cierra el historial, con quién recibió', function () {
    // Antes terminaba en «despachado» y esto vivía sólo en la pantalla de guías.
    $this->pedido->update([
        'estado' => 'despachado',
        'despachado_at' => now()->subDay(),
        'guia_transportadora' => 'SE-123',
        'entregado_at' => now(),
        'entregado_por_id' => $this->gerencia->id,
        'entrega_nota' => 'Recibió Luz Marina Ospina · administradora del local',
    ]);

    $r = $this->actingAs($this->gerencia)->get("/app/pedidos-b2b/{$this->pedido->id}");
    $timeline = collect($r->viewData('page')['props']['pedido']['timeline']);

    $entrega = $timeline->firstWhere('hito', 'Pedido entregado');

    expect($entrega)->not->toBeNull()
        ->and($entrega['detalle'])->toContain('Luz Marina Ospina')
        // Y va de último: es el final del ciclo.
        ->and($timeline->last()['hito'])->toBe('Pedido entregado');
});

it('sin entregar, el hito de entrega no aparece', function () {
    $this->pedido->update([
        'estado' => 'despachado',
        'despachado_at' => now(),
        'guia_transportadora' => 'SE-999',
    ]);

    expect(hitos($this->pedido->fresh(), $this->gerencia))
        ->not->toContain('Pedido entregado');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * QA 17-nov · cambiarle la bodega a un pedido ya FACTURADO volvía a apartar
 * stock que se había soltado a propósito.
 *
 * Al facturar, `liberar()` suelta lo apartado y el comentario dice por qué: de
 * ahí en adelante manda la factura y el despacho descuenta el stock de verdad,
 * así que una reserva viva contaría la misma caja dos veces y el disponible
 * saldría más bajo de lo que es. Esa reserva ya no la suelta nadie —el único
 * que la soltaba fue el facturar que ya pasó—: se queda hasta que expire sola.
 * ─────────────────────────────────────────────────────────────────────────────
 */

it('cambiarle la bodega a un pedido aprobado sí vuelve a apartar', function () {
    $otra = InventarioUbicacion::create([
        'codigo' => 'HIST-2', 'nombre' => 'Bodega dos', 'categoria' => CategoriaUbicacion::Venta,
        'disponible_para_venta' => true, 'activa' => true,
    ]);

    $this->pedido->update(['estado' => 'aprobado', 'retencion_liberada_at' => now()]);

    $this->actingAs($this->gerencia)
        ->post("/app/pedidos-b2b/{$this->pedido->id}/bodega", ['ubicacion_origen_id' => $otra->id])
        ->assertSessionHasNoErrors();

    expect($this->pedido->fresh()->ubicacion_origen_id)->toBe($otra->id);
});

it('cambiarle la bodega a un pedido ya facturado NO vuelve a apartar stock', function () {
    $otra = InventarioUbicacion::create([
        'codigo' => 'HIST-3', 'nombre' => 'Bodega tres', 'categoria' => CategoriaUbicacion::Venta,
        'disponible_para_venta' => true, 'activa' => true,
    ]);

    $this->pedido->update(['estado' => 'facturado', 'retencion_liberada_at' => now()]);

    $reservasAntes = \App\Modules\Inventario\Models\ReservaInventario::count();

    $r = $this->actingAs($this->gerencia)
        ->post("/app/pedidos-b2b/{$this->pedido->id}/bodega", ['ubicacion_origen_id' => $otra->id]);

    $r->assertSessionHasNoErrors();

    // La bodega sí cambia —para eso existe el control— pero sin reservar nada,
    // y la pantalla lo dice en vez de dejarlo en silencio.
    expect($this->pedido->fresh()->ubicacion_origen_id)->toBe($otra->id)
        ->and(\App\Modules\Inventario\Models\ReservaInventario::count())->toBe($reservasAntes)
        ->and(session('success'))->toContain('no se apartó nada');
});
