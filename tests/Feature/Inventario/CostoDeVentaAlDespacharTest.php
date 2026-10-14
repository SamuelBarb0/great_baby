<?php

/**
 * Despachar un pedido tiene que causar el costo de la mercancía que salió.
 *
 * Antes la venta sólo registraba el ingreso. El efecto, medido el 2026-10-08
 * sobre la base real: **ni un solo movimiento de clase 6 en todo el libro**.
 * Eso dejaba el margen bruto del Estado de resultados en 100% —inútil para
 * decidir— y la cuenta de inventario nunca bajaba, así que el Balance general
 * sobrestimaba el activo y la utilidad de forma permanente.
 *
 * Se causa al DESPACHAR y no al facturar porque es cuando la mercancía sale
 * físicamente, que es el mismo momento en que baja el kardex: así el libro y
 * el estante cuentan lo mismo.
 *
 * Lo que se verifica acá:
 *   · el movimiento de salida queda valorizado al promedio ponderado;
 *   · se escribe el asiento costo (débito) contra inventario (crédito);
 *   · el asiento cuadra y vale cantidad × costo;
 *   · despachar dos veces no duplica ni el kardex ni el libro;
 *   · sin costo conocido no se inventa un asiento en cero.
 */

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Actions\DescontarStockPorDespacho;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\PedidoClienteItem;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'BOD-TEST', 'nombre' => 'Bodega de prueba',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $this->cliente = App\Models\Contacto::create([
        'numero_documento' => '901555444',
        'nombre_completo' => 'Tienda del Barrio SAS',
    ]);
});

/** Producto agregado con existencias entradas a un costo conocido. */
function productoConStock(InventarioUbicacion $bodega, int $cantidad, float $costo): Producto
{
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'REF-'.fake()->unique()->numberBetween(1000, 99999),
        'nombre' => 'Pañal de prueba',
        'desglose_stock' => false,
        'stock_directo' => $cantidad,
        'precio_proveedor' => $costo,
    ]));

    InventarioMovimiento::create([
        'producto_id' => $p->id,
        'ubicacion_id' => $bodega->id,
        'tipo' => 'entrada_compra',
        'cantidad' => $cantidad,
        'costo_unit' => $costo,
    ]);

    return $p;
}

function pedidoDespachable(App\Models\Contacto $cliente, InventarioUbicacion $bodega, Producto $p, int $cantidad): PedidoCliente
{
    $pedido = PedidoCliente::create([
        'numero' => 'PC-'.fake()->unique()->numberBetween(1000, 99999),
        'contacto_id' => $cliente->id,
        'ubicacion_origen_id' => $bodega->id,
        'estado' => 'aprobado',
    ]);

    PedidoClienteItem::create([
        'pedido_id' => $pedido->id,
        'producto_id' => $p->id,
        'sku_snapshot' => $p->referencia,
        'descripcion_snapshot' => $p->nombre,
        'cantidad' => $cantidad,
        'precio_unitario' => 50000,
        'subtotal' => 50000 * $cantidad,
        'total' => 50000 * $cantidad,
    ]);

    return $pedido->fresh();
}

it('valoriza la salida al costo promedio y causa el costo de venta', function () {
    $p = productoConStock($this->bodega, 100, 12000);
    $pedido = pedidoDespachable($this->cliente, $this->bodega, $p, 7);

    $lineas = (new DescontarStockPorDespacho())->ejecutar($pedido);

    expect($lineas)->toBe(1);

    $salida = InventarioMovimiento::where('tipo', 'salida_venta')->firstOrFail();
    expect((float) $salida->cantidad)->toBe(-7.0)
        ->and((float) $salida->costo_unit)->toBe(12000.0);

    $asiento = MovimientoContable::where('origen_type', PedidoCliente::class)
        ->where('origen_id', $pedido->id)->get();

    $esperado = 7 * 12000.0;
    expect($asiento)->toHaveCount(2)
        ->and((float) $asiento->firstWhere('cuenta_puc', '6135')->debe)->toBe($esperado)
        ->and((float) $asiento->firstWhere('cuenta_puc', '1435')->haber)->toBe($esperado)
        // Partida doble: lo que se carga al costo se descarga del inventario.
        ->and((float) $asiento->sum('debe'))->toBe((float) $asiento->sum('haber'));
});

it('usa el promedio ponderado cuando hubo entradas a distinto costo', function () {
    // 100 a 10.000 y 100 a 20.000 → el costo de salida es 15.000, no el último
    // ni el primero.
    $p = productoConStock($this->bodega, 100, 10000);
    InventarioMovimiento::create([
        'producto_id' => $p->id, 'ubicacion_id' => $this->bodega->id,
        'tipo' => 'entrada_compra', 'cantidad' => 100, 'costo_unit' => 20000,
    ]);

    $pedido = pedidoDespachable($this->cliente, $this->bodega, $p, 10);
    (new DescontarStockPorDespacho())->ejecutar($pedido);

    $salida = InventarioMovimiento::where('tipo', 'salida_venta')->firstOrFail();
    expect((float) $salida->costo_unit)->toBe(15000.0);

    $asiento = MovimientoContable::where('origen_id', $pedido->id)
        ->where('origen_type', PedidoCliente::class)->get();
    expect((float) $asiento->firstWhere('cuenta_puc', '6135')->debe)->toBe(150000.0);
});

it('despachar dos veces no duplica el kardex ni el libro', function () {
    $p = productoConStock($this->bodega, 100, 12000);
    $pedido = pedidoDespachable($this->cliente, $this->bodega, $p, 7);

    $accion = new DescontarStockPorDespacho();
    $accion->ejecutar($pedido);
    $segunda = $accion->ejecutar($pedido->fresh());

    expect($segunda)->toBe(0)
        ->and(InventarioMovimiento::where('tipo', 'salida_venta')->count())->toBe(1)
        ->and(MovimientoContable::where('origen_id', $pedido->id)
            ->where('origen_type', PedidoCliente::class)->count())->toBe(2);
});

it('sin costo conocido descuenta el stock pero no inventa un asiento en cero', function () {
    // Producto que entró sin costo: es el caso de las cargas viejas por Excel.
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'SIN-COSTO-1', 'nombre' => 'Producto sin costo',
        'desglose_stock' => false, 'stock_directo' => 50, 'precio_proveedor' => 0,
    ]));
    InventarioMovimiento::create([
        'producto_id' => $p->id, 'ubicacion_id' => $this->bodega->id,
        'tipo' => 'entrada_compra', 'cantidad' => 50, 'costo_unit' => 0,
    ]);

    $pedido = pedidoDespachable($this->cliente, $this->bodega, $p, 5);
    $lineas = (new DescontarStockPorDespacho())->ejecutar($pedido);

    expect($lineas)->toBe(1)
        ->and(InventarioMovimiento::where('tipo', 'salida_venta')->count())->toBe(1)
        ->and(MovimientoContable::where('origen_id', $pedido->id)
            ->where('origen_type', PedidoCliente::class)->count())->toBe(0);
});

it('un pedido sin bodega de origen igual descuenta: la deduce del kardex', function () {
    /*
     * Esta prueba decía antes «no descuenta ni asienta» y daba verde: dejó
     * fijada como correcta la razón por la que ninguna venta B2B bajó nunca el
     * inventario. La bodega del pedido la pone una regla de ruteo por ciudad, y
     * esa tabla está vacía —no existe pantalla para cargarla—, así que TODOS los
     * pedidos caían en este caso. Verificado el 2026-10-13 llevando una venta
     * entera por la interfaz: 40 unidades antes del despacho, 40 después.
     *
     * Cuando el pedido no lo dice, se deduce de dónde está la mercancía.
     */
    $p = productoConStock($this->bodega, 100, 12000);
    $pedido = pedidoDespachable($this->cliente, $this->bodega, $p, 7);
    $pedido->update(['ubicacion_origen_id' => null]);

    $lineas = (new DescontarStockPorDespacho())->ejecutar($pedido->fresh());

    expect($lineas)->toBe(1)
        ->and((int) InventarioMovimiento::where('producto_id', $p->id)->sum('cantidad'))->toBe(93)
        ->and(MovimientoContable::where('origen_id', $pedido->id)->count())->toBe(2);
});

it('no inventa un egreso de algo que no está en ninguna bodega', function () {
    // El otro lado del mismo cambio: deducir no puede volverse adivinar. Sin
    // existencias en ningún lado no hay de dónde sacarlo.
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'NUNCA-ENTRO', 'nombre' => 'Jamás entró a bodega',
        'desglose_stock' => false, 'stock_directo' => 0, 'precio_proveedor' => 12000,
    ]));
    $pedido = pedidoDespachable($this->cliente, $this->bodega, $p, 7);
    $pedido->update(['ubicacion_origen_id' => null]);

    $accion = new DescontarStockPorDespacho();

    expect($accion->ejecutar($pedido->fresh()))->toBe(0)
        ->and($accion->motivo)->not->toBeNull()
        ->and(MovimientoContable::count())->toBe(0);
});
