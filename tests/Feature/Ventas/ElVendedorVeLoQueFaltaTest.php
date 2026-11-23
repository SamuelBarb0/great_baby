<?php

/**
 * El pedido que se facturó completo y salió a medias.
 *
 * El vendedor es quien llama a cobrar, y este caso era invisible para él: el
 * pedido vuelve a estado «facturado» —a propósito, para que regrese a la cola
 * de alistamiento— así que en su tablero se veía idéntico a uno que salió
 * entero. Peor todavía, se caía entre las tres tablas del seguimiento:
 *
 *   • «sin facturar» filtra por enviado/retenido/aprobado → no entra;
 *   • «por cobrar» exige saldo > 0 → si ya pagaron, no entra;
 *   • «últimos» son los 20 más recientes → un parcial de hace semanas, no entra.
 *
 * Resultado: nadie sabía que se le debía mercancía a un cliente hasta que el
 * cliente llamaba. Y mientras tanto el vendedor insistía con el cobro de una
 * factura que el cliente no pensaba pagar completa.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\PedidoClienteItem;
use App\Services\SeguimientoVendedorService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->vendedora = User::factory()->create();
    $this->vendedora->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->tienda = Contacto::create([
        'numero_documento' => '901999888', 'nombre_completo' => 'Pañalera que no paga SAS',
        'es_cliente' => true, 'es_cliente_b2b' => true, 'ciudad' => 'Cali',
    ]);
});

/**
 * Un pedido facturado del vendedor.
 *
 * `$yaSalieron` null deja `cantidad_despachada` intacta: así se prueba el
 * pedido que nunca salió, que es donde el NULL leído al revés hace daño.
 */
function pedidoDelVendedor(
    Contacto $cliente, User $vendedor, int $cantidad,
    ?int $yaSalieron = null, float $saldo = 0
): PedidoCliente {
    $factura = FacturaVenta::create([
        'numero' => 'FV-'.fake()->unique()->numerify('######'),
        'contacto_id' => $cliente->id, 'estado' => $saldo > 0 ? 'pendiente' : 'pagada',
        'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => 300000, 'impuestos' => 0, 'total' => 300000, 'saldo' => $saldo,
        'vendedor_id' => $vendedor->id,
    ]);

    $pedido = PedidoCliente::create([
        'numero' => 'PB-'.fake()->unique()->numerify('######-####'),
        'contacto_id' => $cliente->id, 'estado' => 'facturado',
        'factura_id' => $factura->id, 'vendedor_id' => $vendedor->id,
        'facturado_at' => now(), 'subtotal' => 300000, 'total' => 300000,
    ]);

    // El ítem necesita a qué apunta: la base tiene un CHECK que exige variante
    // o producto («chk_pedido_cliente_item_sujeto»).
    $producto = \App\Modules\Dropi\Models\Producto::create([
        'referencia' => 'GB-VEND-'.fake()->unique()->numerify('####'),
        'nombre' => 'Pañal etapa 2', 'activo' => true, 'desglose_stock' => false,
    ]);

    PedidoClienteItem::create([
        'pedido_id' => $pedido->id, 'producto_id' => $producto->id, 'cantidad' => $cantidad,
        'precio_unitario' => 10000, 'subtotal' => 300000, 'total' => 300000,
        'sku_snapshot' => $producto->referencia, 'descripcion_snapshot' => $producto->nombre,
    ]);

    if ($yaSalieron !== null) {
        $pedido->items()->update(['cantidad_despachada' => $yaSalieron]);
    }

    return $pedido->fresh('items');
}

it('junta en su propio grupo los pedidos a los que les falta mercancía', function () {
    $medias = pedidoDelVendedor($this->tienda, $this->vendedora, 10, yaSalieron: 6, saldo: 300000);
    $medias->forceFill(['primer_despacho_at' => now()->subDays(3), 'despacho_parcial' => true, 'resto_pendiente' => true])->save();

    // Éste salió en dos viajes y ya quedó completo: `despacho_parcial` sigue en
    // true para siempre —es la historia del pedido— pero no se debe nada.
    $completado = pedidoDelVendedor($this->tienda, $this->vendedora, 4, yaSalieron: 4);
    $completado->forceFill([
        'primer_despacho_at' => now()->subDays(9), 'despachado_at' => now(),
        'estado' => 'despachado', 'despacho_parcial' => true, 'resto_pendiente' => false,
    ])->save();

    // Y éste nunca salió de bodega.
    pedidoDelVendedor($this->tienda, $this->vendedora, 8, saldo: 300000);

    $datos = app(SeguimientoVendedorService::class)->seguimientoPedidos($this->vendedora->id);

    expect(collect($datos['incompletos'])->pluck('numero')->all())->toBe([$medias->numero])
        ->and($datos['totales']['incompletos'])->toBe(1)
        // 10 pedidas − 6 despachadas. Es el número con el que el vendedor pelea
        // internamente por el segundo viaje.
        ->and($datos['totales']['unidades_debidas'])->toBe(4);
});

it('un pedido que nunca salió no dice que ya se despachó entero', function () {
    /*
     * `cantidad_despachada` en NULL significa «sale completo» para el descuento
     * de stock, y lo contrario —cero— para cualquier pantalla. Un `?? cantidad`
     * acá le diría al vendedor que ya se entregaron las 8 unidades de un pedido
     * que todavía está en bodega.
     */
    $pedido = pedidoDelVendedor($this->tienda, $this->vendedora, 8, saldo: 300000);

    $datos = app(SeguimientoVendedorService::class)->seguimientoPedidos($this->vendedora->id);
    $tarjeta = collect($datos['por_cobrar'])->firstWhere('numero', $pedido->numero);

    expect($tarjeta)->not->toBeNull()
        ->and($tarjeta['unidades_despachadas'])->toBe(0)
        ->and($tarjeta['unidades_pedidas'])->toBe(8)
        ->and($tarjeta['unidades_pendientes'])->toBe(8)
        // Pero NO se marca como «salió a medias»: no ha salido nada. Esa marca
        // es de los que ya tienen mercancía afuera.
        ->and($tarjeta['resto_pendiente'])->toBeFalse()
        ->and($tarjeta['salio_at'])->toBeNull();
});

it('el parcial no se cuenta dos veces en el funnel por estado', function () {
    /*
     * `conteos` es el GROUP BY por estado de la dona. Un parcial sigue siendo
     * «facturado», así que YA está contado ahí: si además se metiera una clave
     * propia DENTRO de conteos, el mismo pedido aparecería dos veces y la suma
     * de las filas dejaría de cuadrar con el total de creados. Por eso el grupo
     * de parciales vive al lado, nunca adentro.
     */
    $medias = pedidoDelVendedor($this->tienda, $this->vendedora, 10, yaSalieron: 6, saldo: 300000);
    $medias->forceFill(['primer_despacho_at' => now(), 'resto_pendiente' => true])->save();

    $funnel = app(SeguimientoVendedorService::class)->pedidosPorEstado($this->vendedora->id);

    expect($funnel['conteos']['facturado']['cantidad'])->toBe(1)
        ->and($funnel['creados'])->toBe(1)
        ->and(array_sum(array_column($funnel['conteos'], 'cantidad')))->toBe($funnel['creados']);
});

it('la pantalla de seguimiento manda el grupo nuevo', function () {
    $medias = pedidoDelVendedor($this->tienda, $this->vendedora, 10, yaSalieron: 6, saldo: 300000);
    $medias->forceFill(['primer_despacho_at' => now(), 'resto_pendiente' => true])->save();

    $this->actingAs($this->vendedora)
        ->get('/app/vendedor/seguimiento')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('incompletos.0.numero', $medias->numero)
            ->where('incompletos.0.unidades_pendientes', 4)
            ->where('totales.incompletos', 1)
            // El de arriba sigue siendo un conteo, no un monto: la pantalla lo
            // pinta crudo, mientras `por_cobrar` va por `money()`.
            ->where('totales.unidades_debidas', 4)
        );
});

it('la bandeja del panel trae la marca, y no un null en silencio', function () {
    /*
     * `misPedidos` arma la lista con un `get([...])` de columnas explícitas, y
     * el proyecto no activa `Model::shouldBeStrict()`: si `resto_pendiente` no
     * entra en ese select, se lee como null —es decir, false— SIN un solo
     * error, y la marca no aparece nunca. Este caso es el que lo nota.
     */
    $medias = pedidoDelVendedor($this->tienda, $this->vendedora, 10, yaSalieron: 6, saldo: 300000);
    $medias->forceFill(['primer_despacho_at' => now(), 'resto_pendiente' => true])->save();

    $this->actingAs($this->vendedora)
        ->get('/app/vendedor')
        ->assertOk()
        ->assertInertia(function ($page) use ($medias) {
            $fila = collect($page->toArray()['props']['mis_pedidos'])
                ->firstWhere('numero', $medias->numero);

            expect($fila['estado'])->toBe('facturado')
                ->and($fila['resto_pendiente'])->toBeTrue();
        });
});
