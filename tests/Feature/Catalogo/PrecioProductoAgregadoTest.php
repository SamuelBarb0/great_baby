<?php

/**
 * Un producto agregado tiene que poder venderse.
 *
 * Un agregado es el que no se desglosa por variante: colores surtidos, una
 * sola existencia por referencia. Los que entraron por el Excel del cliente
 * son así. Hasta el 2026-10-08 **no se podían vender**: el precio de un
 * granular sale de `precios_variante`, pero el del agregado no tenía dónde
 * vivir, así que el pedido le buscaba precio, no lo encontraba y descartaba la
 * línea **en silencio**. Medido ese día: 15 de 31 productos activos eran
 * inalcanzables y nadie sabía por qué.
 *
 * Lo que se protege acá:
 *   · con precio en la lista del cliente, el agregado entra al pedido;
 *   · entra con `variante_id` nulo y su `producto_id`, que es como el kardex
 *     y la contabilidad lo distinguen de un granular;
 *   · sin precio en ESA lista, la línea se descarta (no se cae al costo, que
 *     sería venderle al cliente a precio de compra);
 *   · un precio vencido no se usa;
 *   · el pedido guarda quién lo levantó.
 */

use App\Models\Contacto;
use App\Modules\Catalogo\Models\ListaPrecios;
use App\Modules\Catalogo\Models\PrecioProducto;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Portal\Actions\CrearPedidoCliente;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->lista = ListaPrecios::create(['codigo' => 'MAY', 'nombre' => 'Mayorista', 'canal' => 'b2b', 'activa' => true]);

    $this->cliente = Contacto::create([
        'numero_documento' => '901444333',
        'nombre_completo' => 'Pañalera de Prueba SAS',
        'es_cliente_b2b' => true,
        'lista_precios_id' => $this->lista->id,
        'ciudad' => 'Medellín',
    ]);

    $this->producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'AGR-BABERO',
        'nombre' => 'Babero impermeable',
        'desglose_stock' => false,   // agregado
        'stock_directo' => 50,
        'precio_proveedor' => 9000,  // COSTO, no precio de venta
    ]));
});

function ponerPrecio($producto, $lista, float $precio, ?string $hasta = null): PrecioProducto
{
    return PrecioProducto::create([
        'producto_id' => $producto->id,
        'lista_id' => $lista->id,
        'precio' => $precio,
        'vigente_desde' => now()->subDay()->toDateString(),
        'vigente_hasta' => $hasta,
    ]);
}

function pedirAgregado($cliente, $producto, int $cantidad = 2, ?int $vendedorId = null)
{
    return app(CrearPedidoCliente::class)->ejecutar(
        cliente: $cliente,
        items: [['producto_id' => $producto->id, 'cantidad' => $cantidad]],
        notas: null,
        vendedorId: $vendedorId,
    );
}

it('vende un producto agregado que tiene precio en la lista del cliente', function () {
    ponerPrecio($this->producto, $this->lista, 24900);

    $pedido = pedirAgregado($this->cliente, $this->producto, 2);
    $linea = $pedido->items()->firstOrFail();

    expect((float) $pedido->subtotal)->toBe(49800.0)
        // La línea va por producto, sin variante: así la distinguen el kardex
        // y el asiento contable.
        ->and($linea->variante_id)->toBeNull()
        ->and($linea->producto_id)->toBe($this->producto->id)
        ->and((float) $linea->precio_unitario)->toBe(24900.0)
        ->and($linea->sku_snapshot)->toBe('AGR-BABERO');
});

it('no le vende al costo cuando no hay precio en su lista', function () {
    // Sin `PrecioProducto`, antes se caía al `precio_proveedor` (9.000) y el
    // cliente B2B compraba a precio de compra. Ahora la línea se descarta y el
    // pedido avisa en vez de facturar a pérdida.
    expect(fn () => pedirAgregado($this->cliente, $this->producto))
        ->toThrow(ValidationException::class);

    expect(App\Modules\Portal\Models\PedidoCliente::count())->toBe(0);
});

it('ignora un precio que ya venció', function () {
    ponerPrecio($this->producto, $this->lista, 24900, now()->subDay()->toDateString());

    expect(fn () => pedirAgregado($this->cliente, $this->producto))
        ->toThrow(ValidationException::class);
});

it('no usa el precio de la lista de otro cliente', function () {
    $otraLista = ListaPrecios::create(['codigo' => 'DET', 'nombre' => 'Detal', 'canal' => 'b2b', 'activa' => true]);
    ponerPrecio($this->producto, $otraLista, 31000);

    expect(fn () => pedirAgregado($this->cliente, $this->producto))
        ->toThrow(ValidationException::class);
});

it('guarda quién levantó el pedido', function () {
    ponerPrecio($this->producto, $this->lista, 24900);
    $vendedor = App\Models\User::factory()->create();

    $pedido = pedirAgregado($this->cliente, $this->producto, 1, $vendedor->id);

    expect($pedido->vendedor_id)->toBe($vendedor->id);
});

it('el pedido que arma el cliente solo no lleva vendedor', function () {
    ponerPrecio($this->producto, $this->lista, 24900);

    $pedido = pedirAgregado($this->cliente, $this->producto, 1, null);

    expect($pedido->vendedor_id)->toBeNull();
});

it('el agregado cuenta para el cupo de crédito', function () {
    // Antes el cupo sólo sumaba precios de variante: un agregado pesaba $0 y
    // el pedido nunca se retenía por cupo, por grande que fuera.
    ponerPrecio($this->producto, $this->lista, 24900);
    App\Modules\Cartera\Models\CondicionCredito::create([
        'contacto_id' => $this->cliente->id, 'cupo' => 40000, 'plazo_dias' => 30,
        'activa' => true, 'vigente_desde' => now()->subMonth()->toDateString(),
    ]);

    $pedido = pedirAgregado($this->cliente, $this->producto, 2);

    expect($pedido->estado)->toBe('retenido')
        ->and($pedido->motivo_retencion)->toContain('cupo excedido en $9,800');
});

it('el agregado dentro del cupo no se retiene', function () {
    ponerPrecio($this->producto, $this->lista, 24900);
    App\Modules\Cartera\Models\CondicionCredito::create([
        'contacto_id' => $this->cliente->id, 'cupo' => 100000, 'plazo_dias' => 30,
        'activa' => true, 'vigente_desde' => now()->subMonth()->toDateString(),
    ]);

    expect(pedirAgregado($this->cliente, $this->producto, 2)->estado)->toBe('enviado');
});

it('un producto solo puede tener un precio por lista', function () {
    ponerPrecio($this->producto, $this->lista, 24900);

    expect(fn () => ponerPrecio($this->producto, $this->lista, 30000))
        ->toThrow(Illuminate\Database\QueryException::class);
});
