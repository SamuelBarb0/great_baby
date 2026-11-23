<?php

/**
 * Un pedido puede salir en varios viajes. Lo que NO puede es mentir.
 *
 * El área lo pidió por escrito el 20-nov-2026 —«se autoriza el despacho de la
 * mercancía disponible, sin esperar a completar la totalidad»— y se implementó
 * al día siguiente. Al probarlo por el navegador el 21-nov aparecieron cuatro
 * defectos, y ninguno lo habría atajado un test porque la función entró sin un
 * solo caso cubierto. Éste es ese caso, escrito después del daño:
 *
 *   1. se podía despachar CERO unidades, y quedaba una guía en el aire que ya
 *      salía en Seguimiento de guías sobre un pedido «pendiente de despacho»;
 *   2. el segundo viaje BORRABA la guía del primero, así que al cliente que
 *      reclamaba la primera caja no se le podía dar el número ni mirando la
 *      base;
 *   3. tras un parcial la pantalla del pedido no mostraba nada: ni la guía, ni
 *      cuánto se había llevado el transportador;
 *   4. cuando no alcanzaba la mercancía, el motivo real —qué SKU, cuánto pide,
 *      cuánto hay— se perdía y en pantalla salía «El servidor rechazó la
 *      operación».
 *
 * Lo que de verdad hay que cuidar es el total: si el segundo viaje descuenta de
 * más se vende mercancía dos veces, y si descuenta de menos el ERP dice tener
 * lo que ya salió por la puerta.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\PedidoClienteItem;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->jorge = User::factory()->create();
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'PARC-1', 'nombre' => 'Bodega del parcial',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $this->cliente = Contacto::create([
        'numero_documento' => '901444555', 'nombre_completo' => 'Pañalera del Parcial SAS',
        'es_cliente' => true, 'es_cliente_b2b' => true, 'ciudad' => 'Medellín',
    ]);
});

/*
 * Ayudantes con nombre propio: en Pest las funciones de un archivo de pruebas
 * son globales, y las de `DespachoBajaInventarioTest` ya ocupan los nombres
 * cortos. Redeclararlas revienta la suite entera.
 */

function productoParaElParcial(InventarioUbicacion $u, int $cantidad): Producto
{
    $p = Producto::create([
        'referencia' => 'GB-PARC-'.fake()->unique()->numerify('####'),
        'nombre' => 'Body manga larga', 'activo' => true, 'desglose_stock' => false,
    ]);

    // El alta deja su propio movimiento de stock inicial; acá mandamos nosotros.
    InventarioMovimiento::where('producto_id', $p->id)->delete();

    InventarioMovimiento::create([
        'producto_id' => $p->id, 'ubicacion_id' => $u->id,
        'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => 10000,
    ]);

    return $p;
}

function pedidoDeVariosViajes(Contacto $cliente, Producto $producto, int $cantidad): PedidoCliente
{
    $factura = FacturaVenta::create([
        'numero' => 'FV-'.fake()->unique()->numerify('######'),
        'contacto_id' => $cliente->id, 'estado' => 'pendiente',
        'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => 200000, 'impuestos' => 0, 'total' => 200000,
    ]);

    $pedido = PedidoCliente::create([
        'numero' => 'PB-'.fake()->unique()->numerify('######-####'),
        'contacto_id' => $cliente->id, 'estado' => 'facturado',
        'factura_id' => $factura->id,
        'subtotal' => 200000, 'total' => 200000,
    ]);

    PedidoClienteItem::create([
        'pedido_id' => $pedido->id, 'producto_id' => $producto->id,
        'cantidad' => $cantidad, 'precio_unitario' => 10000, 'subtotal' => 200000,
        'sku_snapshot' => $producto->referencia,
        'descripcion_snapshot' => $producto->nombre, 'total' => 200000,
    ]);

    return $pedido->fresh('items');
}

function saldoDelParcial(Producto $p): int
{
    return (int) InventarioMovimiento::where('producto_id', $p->id)->sum('cantidad');
}

// ─────────────────────────────────────────────────────────────────────────
// 1 · QUE SALGA ALGO
// ─────────────────────────────────────────────────────────────────────────

it('no deja despachar cero unidades, y no escribe ni la guía ni la caja', function () {
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'FANTASMA-001',
            'cantidades' => [$linea->id => 0],
            'resto_pendiente' => true,
        ])
        ->assertSessionHasErrors('guia_transportadora');

    $pedido->refresh();

    // Lo importante no es sólo que no despache: es que no deje RASTRO. Una guía
    // escrita sobre un pedido que no salió aparece en Seguimiento de guías, y
    // quien se la pase al cliente lo manda a rastrear un envío que no existe.
    expect($pedido->guia_transportadora)->toBeNull()
        ->and($pedido->cajas()->count())->toBe(0)
        ->and($pedido->despacho_parcial)->toBeFalsy()
        ->and(saldoDelParcial($producto))->toBe(20);

    /*
     * Y la línea tiene que seguir en NULL, no en 0.
     *
     * Para `DescontarStockPorDespacho` el NULL significa «sale completo» y el 0
     * significa «no sale nada»: si el corte dejara un 0 escrito, el pedido
     * dejaría de descontar para siempre.
     */
    expect($linea->fresh()->cantidad_despachada)->toBeNull();
});

it('tampoco deja despachar un pedido que ya salió completo', function () {
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    $linea->forceFill(['cantidad_despachada' => 6])->save();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", ['guia_transportadora' => 'DE-MAS-001'])
        ->assertSessionHasErrors('guia_transportadora');

    expect($pedido->fresh()->cajas()->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────
// 2 · LOS DOS VIAJES
// ─────────────────────────────────────────────────────────────────────────

it('sale en dos viajes y el kardex baja exactamente lo pedido, ni de más ni de menos', function () {
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    // ── Viaje 1 · salen 4 y el resto sigue debiéndose ──
    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'VIAJE-A',
            'cantidades' => [$linea->id => 4],
            'resto_pendiente' => true,
        ])->assertRedirect();

    $pedido->refresh();

    expect(saldoDelParcial($producto))->toBe(16)
        ->and($linea->fresh()->cantidad_despachada)->toEqual(4)
        // Vuelve a la cola: estado «facturado» y SIN fecha de despacho, que es
        // lo que mira el candado de idempotencia. Ponerla cerraría la puerta
        // del segundo viaje.
        ->and($pedido->estado)->toBe('facturado')
        ->and($pedido->despachado_at)->toBeNull()
        ->and($pedido->despacho_parcial)->toBeTrue()
        ->and($pedido->resto_pendiente)->toBeTrue();

    // ── Viaje 2 · salen las 2 que faltaban ──
    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'VIAJE-B',
            'cantidades' => [$linea->id => 2],
        ])->assertRedirect();

    $pedido->refresh();

    expect($pedido->estado)->toBe('despachado')
        ->and($pedido->despachado_at)->not->toBeNull()
        ->and($pedido->resto_pendiente)->toBeFalse()
        ->and($linea->fresh()->cantidad_despachada)->toEqual(6)
        // 20 − 6. El segundo viaje descontó LO SUYO, no lo del primero otra vez.
        ->and(saldoDelParcial($producto))->toBe(14);
});

it('el segundo viaje no borra la guía del primero', function () {
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'VIAJE-A',
            'cantidades' => [$linea->id => 4],
            'resto_pendiente' => true,
        ])->assertRedirect();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'VIAJE-B',
            'cantidades' => [$linea->id => 2],
        ])->assertRedirect();

    $cajas = $pedido->fresh()->cajas()->orderBy('numero')->get();

    /*
     * Las DOS. Antes acá quedaba una sola —el segundo despacho borraba las
     * cajas del primero antes de recrearlas— y la guía del primer viaje sólo
     * sobrevivía enterrada en la nota de un movimiento de kardex.
     */
    expect($cajas)->toHaveCount(2)
        ->and($cajas[0]->numero)->toBe(1)
        ->and($cajas[0]->guia)->toBe('VIAJE-A')
        ->and($cajas[1]->numero)->toBe(2)
        ->and($cajas[1]->guia)->toBe('VIAJE-B');
});

it('cada caja sabe de qué viaje salió, y el pedido guarda la fecha del primero', function () {
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    // Viaje 1 · dos cajas.
    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'V1-A',
            'guias_extra' => ['V1-B'],
            'cantidades' => [$linea->id => 3],
            'resto_pendiente' => true,
        ])->assertRedirect();

    $pedido->refresh();
    $primeraFecha = $pedido->primer_despacho_at;

    /*
     * La fecha del primer envío tiene que existir YA, aunque el pedido siga
     * abierto. Es la única desde la que se pueden contar días de tránsito:
     * `despachado_at` se deja NULL a propósito mientras falte algo, así que sin
     * ésta un pedido cuya primera caja salió hace ocho días figura como si
     * nunca hubiera salido.
     */
    expect($primeraFecha)->not->toBeNull()
        ->and($pedido->despachado_at)->toBeNull();

    // Viaje 2 · una caja.
    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'V2-UNICA',
            'cantidades' => [$linea->id => 3],
        ])->assertRedirect();

    $pedido->refresh();
    $cajas = $pedido->cajas()->orderBy('numero')->get();

    expect($cajas)->toHaveCount(3)
        // Numeradas corrido, pero agrupadas por viaje: saber cuáles salieron
        // juntas es lo primero que se pregunta cuando el cliente dice que le
        // llegaron dos de tres.
        ->and($cajas[0]->viaje)->toBe(1)
        ->and($cajas[1]->viaje)->toBe(1)
        ->and($cajas[2]->viaje)->toBe(2)
        // Y la fecha del primero NO se pisa con la del segundo.
        ->and($pedido->primer_despacho_at->timestamp)->toBe($primeraFecha->timestamp)
        ->and($pedido->despachado_at)->not->toBeNull();
});

it('no deja repetir en el segundo viaje una guía que ya se usó en el primero', function () {
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'LA-MISMA',
            'cantidades' => [$linea->id => 4],
            'resto_pendiente' => true,
        ])->assertRedirect();

    /*
     * La comprobación vieja sólo comparaba las guías de UNA petición, así que
     * entre viajes se colaba. Dos cajas con el mismo número es justo el daño
     * que esa comprobación existía para evitar: «el cliente reclama una caja
     * que el sistema dice que sí llegó».
     */
    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'LA-MISMA',
            'cantidades' => [$linea->id => 2],
        ])
        ->assertSessionHasErrors('guia_transportadora');

    expect($pedido->fresh()->cajas()->count())->toBe(1);
});

it('cerrar el resto deja el pedido despachado con lo que sí salió', function () {
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    // «Ya no sale»: el cliente no espera el resto.
    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'CERRADO-A',
            'cantidades' => [$linea->id => 4],
            'resto_pendiente' => false,
        ])->assertRedirect();

    $pedido->refresh();

    expect($pedido->estado)->toBe('despachado')
        ->and($pedido->despachado_at)->not->toBeNull()
        ->and($pedido->despacho_parcial)->toBeTrue()
        ->and($pedido->resto_pendiente)->toBeFalse()
        // Sólo bajó lo que salió. Las 2 que no salieron siguen en bodega.
        ->and(saldoDelParcial($producto))->toBe(16);
});

it('no despacha más de lo que el cliente pidió aunque se teclee de más', function () {
    $producto = productoParaElParcial($this->bodega, 100);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'DE-MAS',
            'cantidades' => [$linea->id => 99],
        ])->assertRedirect();

    // Topado en 6: despachar de más no es un parcial, es descontar stock que
    // nadie compró.
    expect($linea->fresh()->cantidad_despachada)->toEqual(6)
        ->and(saldoDelParcial($producto))->toBe(94);
});

// ─────────────────────────────────────────────────────────────────────────
// 3 · QUE VUELVA A LA COLA DE VERDAD
// ─────────────────────────────────────────────────────────────────────────

it('el pedido que queda a medias vuelve a la cola listo para alistar otra vez', function () {
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    // Como queda un pedido que ya pasó por alistamiento y espera despacho.
    $pedido->forceFill([
        'alistador_id' => $this->jorge->id,
        'alistado_asignado_at' => now()->subHours(3),
        'alistado_inicio_at' => now()->subHours(2),
        'alistado_fin_at' => now()->subHour(),
        'casilla_consolidacion' => 'A1',
    ])->save();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'VUELVE-A-LA-COLA',
            'cantidades' => [$linea->id => 4],
            'resto_pendiente' => true,
        ])->assertRedirect();

    $pedido->refresh();

    /*
     * Sin esto el pedido reaparecía en «Listos para empaque» con el cronómetro
     * del día anterior, y ahí se quedaba: `asignar`, `iniciar` y `finalizar`
     * cortan con 422 mientras estas marcas existan. La única forma de moverlo
     * era despacharlo otra vez sin que nadie recogiera lo que faltaba.
     */
    expect($pedido->alistado_fin_at)->toBeNull()
        ->and($pedido->alistado_inicio_at)->toBeNull()
        ->and($pedido->alistador_id)->toBeNull()
        // Y suelta la casilla: no se está armando en el piso mientras espera.
        ->and($pedido->casilla_consolidacion)->toBeNull();

    // Y ahora sí se puede volver a asignar, que es la prueba de que no quedó
    // atascado.
    $this->actingAs($this->jorge)
        ->post("/app/logistica/cola-jorge/{$pedido->id}/asignar", ['alistador_id' => $this->jorge->id])
        ->assertRedirect();

    expect($pedido->fresh()->alistador_id)->toBe($this->jorge->id);
});

it('la hoja de picking del segundo viaje pide lo que falta, no el pedido entero', function () {
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'PICKING-A',
            'cantidades' => [$linea->id => 4],
            'resto_pendiente' => true,
        ])->assertRedirect();

    /*
     * El renglón decía 6 —lo que pidió el cliente— y mandaba al alistador a
     * recoger el pedido COMPLETO otra vez. En bodega eso termina de una de dos
     * formas, las dos malas: o salen 10 unidades de un pedido de 6, o alguien
     * se para frente al estante contando y descubre que la hoja no cuadra con
     * la caja que ya se fue.
     */
    $this->actingAs($this->jorge)
        ->get("/app/logistica/cola-jorge/{$pedido->id}/picking")
        ->assertInertia(fn ($page) => $page
            ->where('items.0.cantidad', 2)
            ->where('items.0.cantidad_pedida', 6)
            ->where('items.0.ya_salio', 4)
            // Y la hoja lo dice en grande, porque se imprime y se camina con ella.
            ->where('pedido.viaje_anterior', true)
            ->where('pedido.ya_despachado', 4)
        );
});

it('en Seguimiento de guías el parcial cuenta días desde que salió, y se puede marcar entregado', function () {
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'EN-CAMINO',
            'cantidades' => [$linea->id => 4],
            'resto_pendiente' => true,
        ])->assertRedirect();

    // La primera caja salió hace 20 días: la transportadora ya debería haberla
    // entregado. Antes esto no se podía ni plantear, porque sin despachado_at
    // el pedido figuraba como si no hubiera salido nunca.
    $pedido->refresh()->forceFill(['primer_despacho_at' => now()->subDays(20)])->save();
    $pedido->refresh();

    expect($pedido->diasEnTransito())->toBe(20)
        // Y SIGUE siendo «no entregada»: el parcial no apaga la alarma. Si
        // fuera un estado propio, esta caja se caería de «Piden atención» y
        // nadie la reclamaría.
        ->and($pedido->estadoGuia())->toBe(\App\Modules\Portal\Enums\EstadoGuia::NoEntregada)
        ->and($pedido->estadoGuia()->pideAtencion())->toBeTrue();

    // Y la caja que SÍ llegó se puede marcar entregada, aunque falte el resto.
    $this->actingAs($this->jorge)
        ->post("/app/logistica/guias/{$pedido->id}/entregar", ['nota' => 'Recibió la señora Marta'])
        ->assertRedirect();

    expect($pedido->fresh()->entregado_at)->not->toBeNull();
});

it('los contadores de Seguimiento no se desincronizan de las filas con un parcial', function () {
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'CONTADOR',
            'cantidades' => [$linea->id => 4],
            'resto_pendiente' => true,
        ])->assertRedirect();

    /*
     * El conteo de esa pantalla usa un `select()` recortado y las filas no.
     * Si a ese select le falta `primer_despacho_at`, el atributo vale null
     * —el proyecto no activa shouldBeStrict()— y el mismo pedido se cuenta
     * como «pendiente» en la pestaña mientras la fila dice «a tiempo». Nada
     * avisa: la pantalla muestra nueve guías bajo una pestaña que dice siete.
     */
    $props = $this->actingAs($this->jorge)
        ->get('/app/logistica/guias?estado=todos')
        ->assertOk()->viewData('page')['props'];

    $fila = collect($props['guias'])->firstWhere('id', $pedido->id);

    expect($fila)->not->toBeNull()
        ->and($fila['despachado'])->not->toBeNull()     // la fecha de la 1.ª caja
        ->and($fila['resto_pendiente'])->toBeTrue()
        // Y el contador de SU estado tiene que incluirlo.
        ->and($props['conteo'][$fila['estado']])->toBeGreaterThan(0);
});

// ─────────────────────────────────────────────────────────────────────────
// 4 · QUE EL MOTIVO LLEGUE A LA PANTALLA
// ─────────────────────────────────────────────────────────────────────────

it('cuando no alcanza el saldo lo dice en pantalla, con el sku y las cantidades', function () {
    $producto = productoParaElParcial($this->bodega, 2);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);

    // Con bodega de origen puesta se va por el camino de `exigirSaldo`, que es
    // el que abortaba con 422 y dejaba la pantalla muda.
    $pedido->forceFill(['ubicacion_origen_id' => $this->bodega->id])->save();

    $r = $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", ['guia_transportadora' => 'SIN-SALDO']);

    $r->assertSessionHasErrors('guia_transportadora');

    /*
     * El mensaje tiene que SERVIR. «El servidor rechazó la operación» —que es
     * lo que salía— no le dice a quien está en bodega ni qué falta ni cuánto
     * hay ni qué hacer.
     */
    $error = session('errors')->first('guia_transportadora');

    expect($error)->toContain($producto->referencia)
        ->and($error)->toContain('pide 6, hay 2');

    // Y no dejó nada a medias.
    $pedido->refresh();
    expect($pedido->guia_transportadora)->toBeNull()
        ->and($pedido->despachado_at)->toBeNull()
        ->and($pedido->cajas()->count())->toBe(0)
        ->and(saldoDelParcial($producto))->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────
// 5 · LO QUE LA PANTALLA TIENE QUE PODER MOSTRAR
// ─────────────────────────────────────────────────────────────────────────

it('la pantalla del pedido recibe el parcial, las cajas y lo que falta', function () {
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'VISIBLE-A',
            'cantidades' => [$linea->id => 4],
            'resto_pendiente' => true,
        ])->assertRedirect();

    /*
     * Sin estos campos la pantalla se veía IDÉNTICA a la de un pedido que no ha
     * salido: mismo estado, mismo botón, ni la guía ni cuánto se llevó el
     * transportador. Todo eso vivía sólo dentro del modal de despachar, así que
     * quien abría el pedido al día siguiente no tenía cómo saberlo.
     */
    $this->actingAs($this->jorge)
        ->get("/app/pedidos-b2b/{$pedido->id}")
        ->assertInertia(fn ($page) => $page
            ->where('pedido.despacho_parcial', true)
            ->where('pedido.resto_pendiente', true)
            ->where('pedido.cajas.0.numero', 1)
            ->where('pedido.cajas.0.guia', 'VISIBLE-A')
            ->where('items.0.despachada', 4)
            ->where('items.0.pendiente', 2)
        );
});

it('la historia del pedido registra CADA viaje, no sólo el último', function () {
    /*
     * El hito de despacho colgaba de `despachado_at`, que en un parcial se
     * queda en NULL hasta que salga todo: mientras faltara mercancía, la línea
     * de tiempo no registraba ninguna salida. La primera caja —la que el
     * cliente ya recibió y por la que llama a reclamar— no existía en ninguna
     * parte del historial. Y al completarse, aparecía un solo «Pedido
     * despachado» con la guía del último viaje.
     */
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'HIST-A',
            'cantidades' => [$linea->id => 4],
            'resto_pendiente' => true,
        ])->assertRedirect();

    // Con el pedido todavía a medias, la salida YA tiene que constar.
    $this->actingAs($this->jorge)
        ->get("/app/pedidos-b2b/{$pedido->id}")
        ->assertInertia(function ($page) {
            $salidas = collect($page->toArray()['props']['pedido']['timeline'])
                ->filter(fn ($h) => str_contains($h['detalle'] ?? '', 'HIST-A'));

            expect($salidas)->toHaveCount(1)
                // Y dice lo que todavía se debe, que es la pregunta del cliente.
                ->and($salidas->first()['detalle'])->toContain('quedan 2 unidades');
        });

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'HIST-B',
            'cantidades' => [$linea->id => 2],
            'resto_pendiente' => false,
        ])->assertRedirect();

    $this->actingAs($this->jorge)
        ->get("/app/pedidos-b2b/{$pedido->id}")
        ->assertInertia(function ($page) {
            $hitos = collect($page->toArray()['props']['pedido']['timeline']);
            $viajes = $hitos->filter(fn ($h) => str_starts_with($h['hito'] ?? '', 'Salió el viaje'));

            // Dos viajes, dos hitos, cada uno con SU guía y en orden.
            expect($viajes->pluck('hito')->values()->all())
                ->toBe(['Salió el viaje 1', 'Salió el viaje 2'])
                ->and($viajes->first()['detalle'])->toContain('HIST-A')
                ->and($viajes->last()['detalle'])->toContain('HIST-B')
                // Ya no se debe nada: el aviso de faltante desaparece.
                ->and($viajes->last()['detalle'])->not->toContain('quedan');
        });
});

it('un pedido que salió de una sola vez no habla de viajes', function () {
    // El rótulo «Salió el viaje 1» sobre un pedido normal sería ruido: el 99%
    // de los pedidos salen completos y nunca tuvieron que saber qué es un viaje.
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'UNICA-1',
        ])->assertRedirect();

    $this->actingAs($this->jorge)
        ->get("/app/pedidos-b2b/{$pedido->id}")
        ->assertInertia(function ($page) {
            $hitos = collect($page->toArray()['props']['pedido']['timeline']);

            expect($hitos->pluck('hito'))->toContain('Pedido despachado')
                ->and($hitos->pluck('hito')->filter(fn ($h) => str_contains($h, 'viaje')))->toBeEmpty();
        });
});

it('el segundo viaje sin transportadora no borra la del primero', function () {
    /*
     * El campo es opcional, así que un segundo despacho con el selector en
     * «— sin transportadora —» dejaba el pedido sin empresa. `urlDeRastreo()`
     * necesita las dos —nombre y guía—, así que el cliente se quedaba sin el
     * enlace de rastreo que YA tenía, y la pantalla de guías perdía con quién
     * reclamar la caja que iba en camino.
     */
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'TRANS-A',
            'transportadora' => 'SERVIENTREGA',
            'cantidades' => [$linea->id => 4],
            'resto_pendiente' => true,
        ])->assertRedirect();

    // Segundo viaje, sin tocar el selector de transportadora.
    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'TRANS-B',
            'cantidades' => [$linea->id => 2],
            'resto_pendiente' => false,
        ])->assertRedirect();

    expect($pedido->fresh()->transportadora)->toBe('SERVIENTREGA');
});

it('no deja despachar media unidad', function () {
    /*
     * Con `numeric` entraba un 2,5: la línea guardaba 2.5000 y el kardex bajaba
     * `(int) 2.5 = 2`. Media unidad salía de la bodega sin bajar del sistema, y
     * como el candado de saldo hace el mismo truncado, no había cómo notarlo.
     */
    $producto = productoParaElParcial($this->bodega, 20);
    $pedido = pedidoDeVariosViajes($this->cliente, $producto, 6);
    $linea = $pedido->items->first();

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'MEDIA-1',
            'cantidades' => [$linea->id => 2.5],
            'resto_pendiente' => true,
        ])->assertSessionHasErrors('cantidades.'.$linea->id);

    // Y no dejó rastro: ni guía, ni caja, ni kardex.
    expect($pedido->fresh()->guia_transportadora)->toBeNull()
        ->and($pedido->cajas()->count())->toBe(0)
        ->and(saldoDelParcial($producto))->toBe(20);
});
