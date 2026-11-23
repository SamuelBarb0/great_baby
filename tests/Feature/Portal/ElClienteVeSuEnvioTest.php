<?php

/**
 * Lo que el cliente ve de su envío, y lo que NO debe ver.
 *
 * El portal terminaba en «Facturado»: de ahí en adelante no había ni fecha de
 * salida, ni transportadora, ni guía. El dato existía desde el primer día
 * —logística lo veía completo— pero el cliente tenía que llamar para saber si
 * su mercancía ya iba en camino. Con el despacho parcial se volvió peor: se le
 * podía mandar media caja sin que en ninguna pantalla suya dijera por qué.
 *
 * El error que estos casos existen para atajar es un `??` al revés.
 * `cantidad_despachada` en NULL significa «sale completo» para el descuento de
 * stock, y lo contrario —cero— para cualquier pantalla. Escribir
 * `$i->cantidad_despachada ?? $i->cantidad` en el portal haría que un pedido
 * recién hecho le dijera al cliente «ya despachamos tus 6 unidades».
 */

use App\Models\Contacto;
use App\Modules\Portal\Models\PedidoCaja;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\PedidoClienteItem;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->comprador = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900777888',
        'nombre_completo' => 'Pañalera que pregunta SAS', 'email' => 'envios@cliente.test',
        'es_cliente' => true, 'es_cliente_b2b' => true, 'activo' => true,
        'portal_habilitado' => true, 'password' => Hash::make('clave-de-prueba'),
    ]);
});

/** Un pedido facturado del comprador, con una sola línea de N unidades. */
function pedidoDelPortal(Contacto $cliente, int $cantidad, ?int $yaSalieron = null): PedidoCliente
{
    $pedido = PedidoCliente::create([
        'numero' => 'PB-'.fake()->unique()->numerify('######-####'),
        'contacto_id' => $cliente->id, 'estado' => 'facturado',
        'subtotal' => 100000, 'total' => 100000,
    ]);

    // El ítem necesita a qué apunta: la base tiene un CHECK que exige variante
    // o producto («chk_pedido_cliente_item_sujeto»).
    $producto = \App\Modules\Dropi\Models\Producto::create([
        'referencia' => 'GB-PORTAL-'.fake()->unique()->numerify('####'),
        'nombre' => 'Body manga larga', 'activo' => true, 'desglose_stock' => false,
    ]);

    PedidoClienteItem::create([
        'pedido_id' => $pedido->id, 'producto_id' => $producto->id, 'cantidad' => $cantidad,
        'precio_unitario' => 10000, 'subtotal' => 100000, 'total' => 100000,
        'sku_snapshot' => $producto->referencia, 'descripcion_snapshot' => $producto->nombre,
    ]);

    // `null` deja la columna intacta: así se prueba el pedido que NUNCA salió,
    // que es justo donde el `??` al revés hace daño.
    if ($yaSalieron !== null) {
        $pedido->items()->update(['cantidad_despachada' => $yaSalieron]);
    }

    return $pedido->fresh('items');
}

it('un pedido que nunca salió de bodega no le dice al cliente que ya se despachó', function () {
    $pedido = pedidoDelPortal($this->comprador, 6);

    $this->actingAs($this->comprador, 'cliente')
        ->get("/portal/pedidos/{$pedido->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // CERO, no seis. Es el caso que el `??` invertido rompería.
            ->where('pedido.unidades_despachadas', 0)
            ->where('pedido.unidades_pedidas', 6)
            ->where('pedido.salio_at', null)
            ->where('pedido.resto_pendiente', false)
            ->where('items.0.despachada', 0)
            ->where('items.0.pendiente', 6)
        );
});

it('el que salió a medias muestra la fecha de la primera caja, no un guión', function () {
    $pedido = pedidoDelPortal($this->comprador, 6, yaSalieron: 4);

    /*
     * `despachado_at` se queda en NULL a propósito mientras falte mercancía
     * —es el candado de idempotencia—, así que la fecha tiene que salir de
     * `primer_despacho_at`. Mirando la columna de siempre, el cliente cuya
     * caja lleva nueve días en la calle leería «todavía no ha salido».
     */
    $pedido->forceFill([
        'primer_despacho_at' => now()->subDays(9),
        'despachado_at' => null,
        'despacho_parcial' => true,
        'resto_pendiente' => true,
        'transportadora' => 'SERVIENTREGA',
        'guia_transportadora' => 'GUIA-PRIMERA',
    ])->save();

    PedidoCaja::create([
        'pedido_id' => $pedido->id, 'numero' => 1, 'viaje' => 1, 'guia' => 'GUIA-PRIMERA',
    ]);

    $this->actingAs($this->comprador, 'cliente')
        ->get("/portal/pedidos/{$pedido->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pedido.salio_at', now()->subDays(9)->format('Y-m-d'))
            ->where('pedido.resto_pendiente', true)
            ->where('pedido.unidades_despachadas', 4)
            ->where('pedido.unidades_pedidas', 6)
            ->where('items.0.pendiente', 2)
            ->where('pedido.cajas.0.guia', 'GUIA-PRIMERA')
            ->where('pedido.transportadora', 'SERVIENTREGA')
        );
});

it('al que ya se completó en dos viajes NO se le sigue diciendo que le debemos', function () {
    /*
     * `despacho_parcial` es PEGAJOSO: se queda en true para siempre, porque es
     * la historia del pedido. La bandera viva es `resto_pendiente`. Si la
     * pantalla mirara la pegajosa, un cliente que ya recibió todo seguiría
     * leyendo «tu pedido salió en partes, faltan 0 unidades».
     */
    $pedido = pedidoDelPortal($this->comprador, 6, yaSalieron: 6);

    $pedido->forceFill([
        'primer_despacho_at' => now()->subDays(5),
        'despachado_at' => now(),
        'despacho_parcial' => true,   // salió en dos viajes...
        'resto_pendiente' => false,   // ...pero ya está completo
    ])->save();

    foreach ([[1, 1, 'GUIA-A'], [2, 2, 'GUIA-B']] as [$numero, $viaje, $guia]) {
        PedidoCaja::create([
            'pedido_id' => $pedido->id, 'numero' => $numero, 'viaje' => $viaje, 'guia' => $guia,
        ]);
    }

    $this->actingAs($this->comprador, 'cliente')
        ->get("/portal/pedidos/{$pedido->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pedido.resto_pendiente', false)
            ->where('pedido.unidades_despachadas', 6)
            ->where('pedido.viajes', 2)
            // Las dos guías siguen ahí: el segundo viaje no borra el primero.
            ->where('pedido.cajas.0.guia', 'GUIA-A')
            ->where('pedido.cajas.1.guia', 'GUIA-B')
        );
});

it('la lista del portal marca el que salió a medias sin cambiarle el estado', function () {
    $this->completo = pedidoDelPortal($this->comprador, 4, yaSalieron: 4);
    $this->completo->forceFill(['primer_despacho_at' => now(), 'despachado_at' => now()])->save();

    $medias = pedidoDelPortal($this->comprador, 6, yaSalieron: 4);
    $medias->forceFill(['primer_despacho_at' => now(), 'resto_pendiente' => true])->save();

    $this->actingAs($this->comprador, 'cliente')
        ->get('/portal/pedidos')
        ->assertOk()
        ->assertInertia(function ($page) use ($medias) {
            $filas = collect($page->toArray()['props']['pedidos']['data']);
            $fila = $filas->firstWhere('numero', $medias->numero);

            // El estado NO cambia —sigue siendo «facturado», y es a propósito:
            // el pedido vuelve a la cola—. La diferencia va en una marca aparte.
            expect($fila['estado'])->toBe('facturado')
                ->and($fila['resto_pendiente'])->toBeTrue()
                ->and($fila['unidades_despachadas'])->toBe(4)
                ->and($fila['unidades_pedidas'])->toBe(6);

            // Y el que salió completo queda limpio: la marca es la excepción,
            // no un adorno que lleven todos los despachados.
            $otro = $filas->firstWhere('numero', $this->completo->numero);
            expect($otro['resto_pendiente'])->toBeFalse()
                ->and($otro['salio_at'])->not->toBeNull();
        });
});

it('un cliente no puede abrir el pedido de otro', function () {
    $otro = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900333222',
        'nombre_completo' => 'Pañalera ajena SAS', 'email' => 'ajena@cliente.test',
        'es_cliente' => true, 'es_cliente_b2b' => true, 'activo' => true,
        'portal_habilitado' => true, 'password' => Hash::make('clave-de-prueba'),
    ]);

    $ajeno = pedidoDelPortal($otro, 3, yaSalieron: 3);

    // Ahora que la pantalla trae guía y transportadora, el candado de siempre
    // protege además el número con el que se rastrea el envío de otro.
    $this->actingAs($this->comprador, 'cliente')
        ->get("/portal/pedidos/{$ajeno->id}")
        ->assertNotFound();
});
