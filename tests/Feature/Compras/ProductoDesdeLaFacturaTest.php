<?php

/**
 * La factura del proveedor trae mercancía que no está en el catálogo.
 *
 * El hueco que esto cierra: una línea de orden puede nacer con sólo
 * `descripcion` y sin producto. Al recibirla, `RecibirMercancia` sólo escribe
 * kardex si la línea tiene producto o variante, así que la recepción se
 * confirmaba, la orden quedaba recibida, el asiento contable se hacía… y NO
 * entraba una sola unidad al inventario. En silencio.
 *
 * No se bloquea la recepción, y eso es a propósito: un flete o un seguro son
 * líneas legítimas sin mercancía —hay una prueba con ese nombre en
 * `RecibirMercanciaSumaStockTest`— y bloquearlas dejaría a quien descarga sin
 * poder registrar nada. Lo que se cierra es el silencio: la línea se marca y
 * se puede resolver desde la misma pantalla.
 *
 * Lo que se verifica acá:
 *   · crear el producto desde la línea la deja enlazada y con stock al recibir;
 *   · enlazar uno que ya existe hace lo mismo sin duplicar el catálogo;
 *   · una referencia repetida se rechaza, para no terminar con dos productos
 *     para la misma caja;
 *   · una línea que YA tiene producto no se puede reapuntar;
 *   · con color, la variante queda creada Y el color queda escrito en la línea
 *     —si no, la recepción no hereda la variante y la mercancía vuelve a
 *     quedarse sin a qué sumarle.
 */

use App\Modules\Compras\Actions\EnlazarProductoALinea;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'BOD-F', 'nombre' => 'Bodega', 'categoria' => 'venta',
        'disponible_para_venta' => true, 'activa' => true,
    ]);

    $proveedor = \App\Models\Contacto::create([
        'numero_documento' => '901333444', 'nombre_completo' => 'Textiles del Norte',
        'es_proveedor' => true, 'activo' => true,
    ]);

    $this->orden = OrdenCompra::create([
        'numero' => 'OC-FACTURA-1',
        'proveedor_id' => $proveedor->id,
        'bodega_id' => $this->bodega->id,
        'tipo' => 'nacional', 'moneda' => 'COP', 'tasa_cambio' => 1,
        'estado' => 'aprobada',
        'fecha_emision' => now()->toDateString(),
        'subtotal' => 480000, 'iva' => 0, 'total' => 480000,
        'creado_por' => \App\Models\User::factory()->create()->id,
    ]);

    // La línea tal como llega de la factura: descripción y nada más.
    $this->linea = $this->orden->items()->create([
        'descripcion' => 'Cobija polar estampada 90x110',
        'cantidad' => 40, 'cantidad_recibida' => 0, 'precio_unit' => 12000,
    ]);
});

/** Recibe la línea completa y devuelve cuánto entró al kardex. */
function recibirTodo(OrdenCompra $orden, $linea): float
{
    $rc = \App\Modules\Compras\Actions\RegistrarRecepcion::run([
        'orden_id' => $orden->id,
        'items' => [['orden_item_id' => $linea->id, 'cantidad_recibida' => 40]],
    ]);

    return (float) \App\Modules\Dropi\Models\InventarioMovimiento::where('referencia_id', $rc->id)
        ->where('referencia_tipo', \App\Modules\Compras\Models\RecepcionCompra::class)
        ->sum('cantidad');
}

it('sin producto la recepción se confirma pero no mueve una sola unidad', function () {
    // Este es el comportamiento viejo, y queda escrito: es legítimo para
    // fletes y seguros, y es justo por eso que no se puede bloquear.
    expect(recibirTodo($this->orden, $this->linea))->toBe(0.0);
});

it('crear el producto desde la línea la deja sumando inventario', function () {
    EnlazarProductoALinea::run($this->linea, [
        'nombre' => 'Cobija polar estampada 90x110',
        'referencia' => 'GB-COBIJA-90110',
    ]);

    $linea = $this->linea->fresh();
    $producto = Producto::where('referencia', 'GB-COBIJA-90110')->first();

    expect($producto)->not->toBeNull()
        ->and($producto->activo)->toBeTrue()
        ->and($linea->producto_id)->toBe($producto->id)
        ->and(recibirTodo($this->orden, $linea))->toBe(40.0);
});

it('enlazar uno que ya existe no duplica el catálogo', function () {
    $ya = Producto::create([
        'nombre' => 'Cobija polar', 'referencia' => 'GB-COB-1',
        'activo' => true, 'desglose_stock' => false,
    ]);

    EnlazarProductoALinea::run($this->linea, ['producto_id' => $ya->id]);

    expect($this->linea->fresh()->producto_id)->toBe($ya->id)
        ->and(Producto::count())->toBe(1)
        ->and(recibirTodo($this->orden, $this->linea->fresh()))->toBe(40.0);
});

it('una referencia repetida se rechaza en vez de crear un gemelo', function () {
    Producto::create([
        'nombre' => 'Cobija vieja', 'referencia' => 'GB-COBIJA-90110',
        'activo' => true, 'desglose_stock' => false,
    ]);

    expect(fn () => EnlazarProductoALinea::run($this->linea, [
        'nombre' => 'Cobija polar estampada 90x110',
        'referencia' => 'GB-COBIJA-90110',
    ]))->toThrow(InvalidArgumentException::class, 'Búsquelo y enlácelo');

    expect(Producto::count())->toBe(1)
        ->and($this->linea->fresh()->producto_id)->toBeNull();
});

it('una línea que ya tiene producto no se puede reapuntar', function () {
    // Cambiar a qué producto apunta una línea ya recibida movería stock de un
    // producto a otro sin dejar rastro.
    $uno = Producto::create(['nombre' => 'Uno', 'referencia' => 'R-1', 'activo' => true, 'desglose_stock' => false]);
    $otro = Producto::create(['nombre' => 'Otro', 'referencia' => 'R-2', 'activo' => true, 'desglose_stock' => false]);

    EnlazarProductoALinea::run($this->linea, ['producto_id' => $uno->id]);

    expect(fn () => EnlazarProductoALinea::run($this->linea->fresh(), ['producto_id' => $otro->id]))
        ->toThrow(InvalidArgumentException::class, 'ya tiene producto');

    expect($this->linea->fresh()->producto_id)->toBe($uno->id);
});

it('con color crea la variante y deja el color escrito en la línea', function () {
    /*
     * Lo segundo importa tanto como lo primero: `RegistrarRecepcion` compara
     * el color contado contra el de la línea y sólo hereda la variante si
     * coinciden. Con la línea en blanco, la recepción se iba sin variante y
     * la mercancía quedaba otra vez sin a qué sumarle.
     */
    EnlazarProductoALinea::run($this->linea, [
        'nombre' => 'Cobija polar estampada 90x110',
        'referencia' => 'GB-COBIJA-90110',
        'desglose_stock' => true,
        'color_nombre' => 'Rosa',
    ]);

    $linea = $this->linea->fresh();

    expect($linea->variante_id)->not->toBeNull()
        ->and(mb_strtolower((string) $linea->color_nombre))->toBe('rosa')
        ->and(recibirTodo($this->orden, $linea))->toBe(40.0);
});
