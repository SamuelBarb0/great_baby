<?php

/**
 * El despacho baja el stock de donde la mercancía está de verdad.
 *
 * Una bodega y sus racks son el mismo sitio físico. Cuando el alistador baja
 * mercancía de «PPAL» a «R12-S1-N1», no salió de la bodega: ahora se sabe en
 * qué estante está.
 *
 * El despacho no lo entendía así. Razonaba sólo contra la fila de la sede, y
 * eso rompía las dos puntas:
 *
 *   · Al comprobar saldo, el de la sede daba cero y cortaba el despacho con
 *     «no hay saldo suficiente» sobre mercancía que estaba a tres metros. Y
 *     como ese aviso sale con un abort dentro de una transacción, bajo Inertia
 *     ni siquiera se veía: el botón se volvía a habilitar y ya.
 *   · Al escribir el egreso, dejaba la sede en negativo y el estante intacto,
 *     así que el sistema seguía diciendo que había 15 unidades en un rack del
 *     que ya se habían despachado. Eso no se descubre hasta la toma física.
 *
 * O sea: el día que alguien usara las 435 posiciones para lo que son, la
 * bodega dejaba de poder despachar.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Actions\DescontarStockPorDespacho;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\PedidoClienteItem;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->quien = User::factory()->create();

    $this->sede = InventarioUbicacion::create([
        'codigo' => 'PPAL-DESP', 'nombre' => 'Bodega principal',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $this->cliente = Contacto::create([
        'numero_documento' => '901444555', 'nombre_completo' => 'Pañalera Despacho SAS',
        'es_cliente' => true, 'ciudad' => 'Bucaramanga',
    ]);
});

/** Una posición colgada de la sede, que es lo que crea el sembrador. */
function estanteDe(InventarioUbicacion $sede, string $codigo): InventarioUbicacion
{
    return InventarioUbicacion::create([
        'codigo' => $codigo, 'nombre' => $codigo,
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'bodega_id' => $sede->id, 'pasillo' => '12',
    ]);
}

/** Un producto agregado con su stock repartido: [ubicacion_id => unidades]. */
function productoConStockEn(array $saldos): Producto
{
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GB-DESP-'.fake()->unique()->numerify('####'),
        'nombre' => 'Caja de pañales', 'activo' => true,
        'desglose_stock' => false, 'precio_proveedor' => 15000,
    ]));

    foreach ($saldos as $ubicacionId => $cantidad) {
        InventarioMovimiento::create([
            'producto_id' => $p->id, 'ubicacion_id' => $ubicacionId,
            'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => 15000,
        ]);
    }

    return $p;
}

/** Un pedido despachado desde la sede, que es lo que estampa el ruteo. */
function pedidoDespachadoDe($test, Producto $producto, int $cantidad): PedidoCliente
{
    $pedido = PedidoCliente::create([
        'numero' => 'PB-'.fake()->unique()->numerify('######-####'),
        'contacto_id' => $test->cliente->id, 'estado' => 'facturado',
        'ubicacion_origen_id' => $test->sede->id,
        'subtotal' => 20000, 'total' => 20000,
    ]);

    PedidoClienteItem::create([
        'pedido_id' => $pedido->id, 'producto_id' => $producto->id,
        'cantidad' => $cantidad, 'precio_unitario' => 20000,
        'subtotal' => 20000, 'total' => 20000,
        'sku_snapshot' => $producto->referencia, 'descripcion_snapshot' => $producto->nombre,
    ]);

    return $pedido->fresh('items');
}

/** El saldo que queda en una ubicación concreta. */
function saldoEn(int $ubicacionId, Producto $producto): int
{
    return (int) InventarioMovimiento::where('ubicacion_id', $ubicacionId)
        ->where('producto_id', $producto->id)
        ->sum('cantidad');
}

it('despacha mercancía que está en un estante, no en la fila de la sede', function () {
    $estante = estanteDe($this->sede, 'R12-S1-N1');

    // Todo bajado al estante: la sede, como fila, está en cero.
    $producto = productoConStockEn([$estante->id => 40]);
    $pedido = pedidoDespachadoDe($this, $producto, 10);

    $lineas = (new DescontarStockPorDespacho())->ejecutar($pedido, $this->quien->id);

    expect($lineas)->toBe(1)
        // Baja del estante, que es donde está.
        ->and(saldoEn($estante->id, $producto))->toBe(30)
        // Y la sede no queda en negativo por mercancía que nunca tuvo encima.
        ->and(saldoEn($this->sede->id, $producto))->toBe(0);
});

it('reparte el egreso entre los estantes cuando uno solo no alcanza', function () {
    $a = estanteDe($this->sede, 'R12-S1-N1');
    $b = estanteDe($this->sede, 'R12-S2-N1');

    $producto = productoConStockEn([$a->id => 12, $b->id => 15]);
    $pedido = pedidoDespachadoDe($this, $producto, 20);

    (new DescontarStockPorDespacho())->ejecutar($pedido, $this->quien->id);

    // Se toma primero del que más tiene —igual que manda la hoja de picking—
    // y el resto del otro: 15 + 5.
    expect(saldoEn($b->id, $producto))->toBe(0)
        ->and(saldoEn($a->id, $producto))->toBe(7)
        ->and(saldoEn($this->sede->id, $producto))->toBe(0);
});

it('la sede absorbe lo que no estaba ubicado en ningún estante', function () {
    $estante = estanteDe($this->sede, 'R12-S3-N1');

    // Lo normal mientras se pasa a posiciones: una parte ubicada y otra no.
    $producto = productoConStockEn([$estante->id => 5, $this->sede->id => 30]);
    $pedido = pedidoDespachadoDe($this, $producto, 12);

    (new DescontarStockPorDespacho())->ejecutar($pedido, $this->quien->id);

    expect(saldoEn($estante->id, $producto))->toBe(0)
        ->and(saldoEn($this->sede->id, $producto))->toBe(23);
});

it('no despacha cuando entre la sede y sus estantes no alcanza', function () {
    $estante = estanteDe($this->sede, 'R12-S4-N1');

    $producto = productoConStockEn([$estante->id => 3, $this->sede->id => 2]);
    $pedido = pedidoDespachadoDe($this, $producto, 10);

    // 5 en total contra 10 pedidas: corta antes de escribir nada.
    expect(fn () => (new DescontarStockPorDespacho())->ejecutar($pedido, $this->quien->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    // Y no dejó nada a medias.
    expect(saldoEn($estante->id, $producto))->toBe(3)
        ->and(saldoEn($this->sede->id, $producto))->toBe(2);
});

it('un estante apagado no cuenta como saldo de la sede', function () {
    $estante = estanteDe($this->sede, 'R12-S5-N1');
    $estante->update(['activa' => false]);

    $producto = productoConStockEn([$estante->id => 50]);
    $pedido = pedidoDespachadoDe($this, $producto, 10);

    // Una posición apagada está fuera de servicio: su mercancía no se promete.
    expect(fn () => (new DescontarStockPorDespacho())->ejecutar($pedido, $this->quien->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
