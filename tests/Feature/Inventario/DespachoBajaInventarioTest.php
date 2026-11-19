<?php

/**
 * Despachar tiene que bajar el inventario. Siempre.
 *
 * Encontrado el 2026-10-13 llevando una venta B2B entera por la interfaz:
 * pedido → aprobación → factura → alistamiento → despacho con guía, todo en
 * verde, y el stock del producto seguía en 40 unidades después de haber
 * despachado 5. En el libro tampoco había un solo movimiento de clase 6.
 *
 * La causa: el descuento sale de `pedidos_cliente.ubicacion_origen_id`, que
 * llena una regla de `reglas_ruteo_ciudad` al crear el pedido. Esa tabla está
 * vacía y NO existe ninguna pantalla para cargarla, así que todo pedido nace
 * con origen nulo y el descuento se rendía devolviendo 0 en silencio.
 *
 * Es el fallo más caro de los encontrados porque no rompe nada visible: deja
 * al ERP mintiendo sobre lo que hay en bodega, y sólo se descubre el día del
 * conteo o cuando se vende algo que ya no está.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Actions\DescontarStockPorDespacho;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\PedidoClienteItem;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->jorge = User::factory()->create();
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'BOG-1', 'nombre' => 'Bodega Bogotá',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    Contacto::create([
        'numero_documento' => '901777888', 'nombre_completo' => 'Pañalera Mundo Bebé SAS',
        'es_cliente' => true, 'es_cliente_b2b' => true, 'ciudad' => 'Medellín',
    ]);
});

function productoEnBodega(InventarioUbicacion $u, int $cantidad, float $costo = 12000): Producto
{
    $p = Producto::create([
        'referencia' => 'GB-'.fake()->unique()->numerify('####'),
        'nombre' => 'Pijama algodón', 'activo' => true, 'desglose_stock' => false,
    ]);

    // El alta del producto deja su propio movimiento de stock inicial; acá
    // mandamos nosotros dónde entra y con qué costo.
    InventarioMovimiento::where('producto_id', $p->id)->delete();

    InventarioMovimiento::create([
        'producto_id' => $p->id, 'ubicacion_id' => $u->id,
        'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => $costo,
    ]);

    return $p;
}

function pedidoFacturado(array $extra = []): PedidoCliente
{
    $cliente = Contacto::first();

    $factura = FacturaVenta::create([
        'numero' => 'FV-'.fake()->unique()->numerify('######'),
        'contacto_id' => $cliente->id, 'estado' => 'pendiente',
        'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => 124500, 'impuestos' => 0, 'total' => 124500,
    ]);

    return PedidoCliente::create(array_merge([
        'numero' => 'PB-'.fake()->unique()->numerify('######-####'),
        'contacto_id' => $cliente->id, 'estado' => 'facturado',
        'factura_id' => $factura->id,
        'subtotal' => 124500, 'total' => 124500,
    ], $extra));
}

function conUnItem(PedidoCliente $pedido, Producto $producto, int $cantidad = 5): PedidoCliente
{
    PedidoClienteItem::create([
        'pedido_id' => $pedido->id, 'producto_id' => $producto->id,
        'cantidad' => $cantidad, 'precio_unitario' => 24900, 'subtotal' => 124500,
        'sku_snapshot' => $producto->referencia,
        'descripcion_snapshot' => $producto->nombre, 'total' => 124500,
    ]);

    return $pedido->fresh();
}

function saldoTotal(Producto $p): int
{
    return (int) InventarioMovimiento::where('producto_id', $p->id)->sum('cantidad');
}

function salidaDe(Producto $p): ?InventarioMovimiento
{
    return InventarioMovimiento::where('producto_id', $p->id)
        ->where('tipo', 'salida_venta')->first();
}

it('el despacho baja el stock aunque el pedido no traiga bodega de origen', function () {
    // `reglas_ruteo_ciudad` vacía: exactamente como está la base del cliente.
    expect(App\Modules\Portal\Models\ReglaRuteoCiudad::count())->toBe(0);

    $producto = productoEnBodega($this->bodega, 40);
    $pedido = conUnItem(pedidoFacturado(), $producto);

    expect($pedido->ubicacion_origen_id)->toBeNull()
        ->and(saldoTotal($producto))->toBe(40);

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'SE-2026-998877', 'transportadora' => 'Servientrega',
        ])->assertRedirect();

    expect(saldoTotal($producto))->toBe(35);
});

it('el egreso queda en la bodega donde de verdad estaba la mercancía', function () {
    $otra = InventarioUbicacion::create([
        'codigo' => 'MED-1', 'nombre' => 'Bodega Medellín',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $producto = productoEnBodega($otra, 40);
    $pedido = conUnItem(pedidoFacturado(), $producto);

    (new DescontarStockPorDespacho())->ejecutar($pedido, $this->jorge->id);

    expect(salidaDe($producto)->ubicacion_id)->toBe($otra->id);
});

it('causa el costo de ventas: debe 6135, haber 1435', function () {
    $producto = productoEnBodega($this->bodega, 40, costo: 12000);
    $pedido = conUnItem(pedidoFacturado(), $producto);

    (new DescontarStockPorDespacho())->ejecutar($pedido, $this->jorge->id);

    $asiento = MovimientoContable::where('origen_id', $pedido->id)
        ->where('origen_type', PedidoCliente::class)->get();

    // 5 unidades × $12.000 de costo.
    expect((float) $asiento->where('cuenta_puc', '6135')->sum('debe'))->toEqual(60000.0)
        ->and((float) $asiento->where('cuenta_puc', '1435')->sum('haber'))->toEqual(60000.0);
});

it('deja escrita en el pedido la bodega que dedujo', function () {
    // Sin esto la Cola de alistamiento y los reportes siguen sin saber de dónde
    // salió: el pedido queda huérfano para el filtro por bodega.
    $producto = productoEnBodega($this->bodega, 40);
    $pedido = conUnItem(pedidoFacturado(), $producto);

    (new DescontarStockPorDespacho())->ejecutar($pedido, $this->jorge->id);

    expect($pedido->fresh()->ubicacion_origen_id)->toBe($this->bodega->id);
});

it('respeta la bodega del pedido cuando sí viene ruteada', function () {
    $otra = InventarioUbicacion::create([
        'codigo' => 'MED-1', 'nombre' => 'Bodega Medellín',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    // Existencias en las dos, y más en la otra: igual manda lo que dice el pedido.
    $producto = productoEnBodega($this->bodega, 40);
    InventarioMovimiento::create([
        'producto_id' => $producto->id, 'ubicacion_id' => $otra->id,
        'tipo' => 'entrada_compra', 'cantidad' => 100, 'costo_unit' => 9000,
    ]);

    $pedido = conUnItem(pedidoFacturado(['ubicacion_origen_id' => $this->bodega->id]), $producto);

    (new DescontarStockPorDespacho())->ejecutar($pedido, $this->jorge->id);

    expect(salidaDe($producto)->ubicacion_id)->toBe($this->bodega->id);
});

it('la mercancía sale al costo promedio de SU bodega', function () {
    $otra = InventarioUbicacion::create([
        'codigo' => 'MED-1', 'nombre' => 'Bodega Medellín',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    // Sólo hay existencias en Medellín, a $9.000: ese es el costo que debe
    // usar, no cero ni el de otra bodega.
    $producto = productoEnBodega($otra, 40, costo: 9000);
    $pedido = conUnItem(pedidoFacturado(), $producto);

    (new DescontarStockPorDespacho())->ejecutar($pedido, $this->jorge->id);

    expect((float) salidaDe($producto)->costo_unit)->toEqual(9000.0);
});

it('no descuenta dos veces si se reintenta', function () {
    $producto = productoEnBodega($this->bodega, 40);
    $pedido = conUnItem(pedidoFacturado(), $producto);

    (new DescontarStockPorDespacho())->ejecutar($pedido, $this->jorge->id);
    (new DescontarStockPorDespacho())->ejecutar($pedido->fresh(), $this->jorge->id);

    expect(saldoTotal($producto))->toBe(35);
});

it('avisa en pantalla cuando no pudo descontar nada', function () {
    // Un despacho que no baja inventario no puede verse igual que uno que sí:
    // así es como el sistema y el estante se separan sin que nadie se entere.
    $producto = Producto::create([
        'referencia' => 'GB-SINSTOCK', 'nombre' => 'Nunca entró a bodega',
        'activo' => true, 'desglose_stock' => false,
    ]);
    InventarioMovimiento::where('producto_id', $producto->id)->delete();

    $pedido = conUnItem(pedidoFacturado(), $producto);

    $this->actingAs($this->jorge)
        ->post("/app/pedidos-b2b/{$pedido->id}/despachar", [
            'guia_transportadora' => 'SE-000', 'transportadora' => 'Servientrega',
        ])->assertSessionHas('warning');
});
