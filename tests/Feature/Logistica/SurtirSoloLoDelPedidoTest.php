<?php

/**
 * De Exhibición sólo se trae lo que el pedido lleva, y sólo lo que falta.
 *
 * La hoja de picking manda al alistador a completar desde Exhibición cuando los
 * racks no alcanzan, y le da el botón para reportar el traslado en el acto. Esa
 * puerta estaba abierta de más: el endpoint comprobaba de DÓNDE sale la
 * mercancía y QUIÉN la pide, pero nunca QUÉ.
 *
 * Con eso, cualquiera con rol de alistador podía tomar el id de un pedido suyo,
 * pedir la referencia más cara del catálogo —que no está en ese pedido— en la
 * cantidad que se le ocurriera, y el traslado se ejecutaba: mercancía saliendo
 * de Exhibición a nombre de un pedido que no la lleva.
 *
 * El tope es lo PENDIENTE de la línea y no lo pedido: en un segundo viaje, lo
 * que falta por traer es lo que todavía no ha salido.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Models\Oficina;
use App\Modules\Inventario\Models\Traslado;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\PedidoClienteItem;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->alistador = User::factory()->create();
    $this->alistador->assignRole(Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']));

    // El código de oficina es varchar(4) y las reales son «10», «11»… «14»:
    // la de exhibición del área es la 14, y es la que marca el respaldo.
    $this->oficinaRespaldo = Oficina::firstOrCreate(
        ['codigo' => '14'],
        ['nombre' => 'Exhibición', 'activa' => true, 'orden' => 90]
    );
    $this->oficinaRespaldo->forceFill(['es_respaldo_surtido' => true, 'activa' => true])->save();

    /*
     * El alistador responde por la bodega del pedido. No es adorno: para
     * traer de Exhibición, `EjecutarTraslado` exige que el destino sea una
     * bodega que esa persona YA ve —si no, cualquiera con un pedido en la mano
     * podría sacar mercancía de la vitrina hacia una bodega ajena—.
     */
    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'SUR-PPAL', 'nombre' => 'Bodega del pedido',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'disponible_para_venta' => true,
        'responsable_user_id' => $this->alistador->id,
    ]);

    $this->vitrina = InventarioUbicacion::create([
        'codigo' => 'SUR-EXH', 'nombre' => 'Sala de exhibición',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'disponible_para_venta' => true, 'oficina_id' => $this->oficinaRespaldo->id,
    ]);

    $this->cliente = Contacto::create([
        'numero_documento' => '901333444', 'nombre_completo' => 'Pañalera del surtido SAS',
        'es_cliente' => true, 'ciudad' => 'Bucaramanga',
    ]);
});

function productoConStockEnVitrina(InventarioUbicacion $vitrina, int $cantidad): Producto
{
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GB-SUR-'.fake()->unique()->numerify('####'),
        'nombre' => 'Body manga larga', 'activo' => true,
        'desglose_stock' => false, 'precio_proveedor' => 9000,
    ]));

    InventarioMovimiento::create([
        'producto_id' => $p->id, 'ubicacion_id' => $vitrina->id,
        'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => 9000,
    ]);

    return $p;
}

function pedidoQueNecesitaSurtido($test, Producto $producto, int $cantidad): PedidoCliente
{
    $pedido = PedidoCliente::create([
        'numero' => 'PB-'.fake()->unique()->numerify('######-####'),
        'contacto_id' => $test->cliente->id, 'estado' => 'facturado',
        'ubicacion_origen_id' => $test->bodega->id,
        'alistador_id' => $test->alistador->id,
        'subtotal' => 100000, 'total' => 100000,
    ]);

    PedidoClienteItem::create([
        'pedido_id' => $pedido->id, 'producto_id' => $producto->id,
        'cantidad' => $cantidad, 'precio_unitario' => 10000,
        'subtotal' => 100000, 'total' => 100000,
        'sku_snapshot' => $producto->referencia, 'descripcion_snapshot' => $producto->nombre,
    ]);

    return $pedido->fresh('items');
}

function surtir($test, PedidoCliente $pedido, Producto $producto, int $cantidad)
{
    return $test->actingAs($test->alistador)
        ->post("/app/logistica/cola-jorge/{$pedido->id}/surtir", [
            'origen_id' => $test->vitrina->id,
            'producto_id' => $producto->id,
            'cantidad' => $cantidad,
        ]);
}

it('trae de Exhibición lo que el pedido necesita', function () {
    $producto = productoConStockEnVitrina($this->vitrina, 20);
    $pedido = pedidoQueNecesitaSurtido($this, $producto, 6);

    surtir($this, $pedido, $producto, 6)->assertRedirect();

    // Salió de la vitrina y entró a la bodega del pedido, sin perder una unidad.
    expect((float) InventarioMovimiento::where('producto_id', $producto->id)
        ->where('ubicacion_id', $this->vitrina->id)->sum('cantidad'))->toBe(14.0)
        ->and((float) InventarioMovimiento::where('producto_id', $producto->id)
            ->where('ubicacion_id', $this->bodega->id)->sum('cantidad'))->toBe(6.0);
});

it('NO trae un producto que no está en el pedido', function () {
    /*
     * El agujero. El alistador pedía la referencia más cara del catálogo a
     * nombre de un pedido suyo y la mercancía salía igual.
     */
    $delPedido = productoConStockEnVitrina($this->vitrina, 20);
    $ajeno = productoConStockEnVitrina($this->vitrina, 500);

    $pedido = pedidoQueNecesitaSurtido($this, $delPedido, 6);

    surtir($this, $pedido, $ajeno, 50)->assertStatus(422);

    // Ni traslado ni una sola unidad movida.
    expect(Traslado::count())->toBe(0)
        ->and((float) InventarioMovimiento::where('producto_id', $ajeno->id)
            ->where('ubicacion_id', $this->vitrina->id)->sum('cantidad'))->toBe(500.0);
});

it('no trae más de lo que el pedido necesita', function () {
    $producto = productoConStockEnVitrina($this->vitrina, 500);
    $pedido = pedidoQueNecesitaSurtido($this, $producto, 6);

    surtir($this, $pedido, $producto, 50)->assertStatus(422);

    expect(Traslado::count())->toBe(0)
        ->and((float) InventarioMovimiento::where('ubicacion_id', $this->bodega->id)->sum('cantidad'))->toBe(0.0);
});

it('en un segundo viaje el tope es lo que FALTA, no lo que se pidió', function () {
    $producto = productoConStockEnVitrina($this->vitrina, 500);
    $pedido = pedidoQueNecesitaSurtido($this, $producto, 6);

    // Ya salieron 4: sólo faltan 2 por traer.
    $pedido->items()->update(['cantidad_despachada' => 4]);

    surtir($this, $pedido, $producto, 3)->assertStatus(422);
    expect(Traslado::count())->toBe(0);

    surtir($this, $pedido->fresh('items'), $producto, 2)->assertRedirect();
    expect((float) InventarioMovimiento::where('ubicacion_id', $this->bodega->id)->sum('cantidad'))->toBe(2.0);
});

it('no trae medias unidades', function () {
    // Un decimal descuadra la vitrina contra la bodega por la parte que el
    // kardex trunca.
    $producto = productoConStockEnVitrina($this->vitrina, 20);
    $pedido = pedidoQueNecesitaSurtido($this, $producto, 6);

    $this->actingAs($this->alistador)
        ->post("/app/logistica/cola-jorge/{$pedido->id}/surtir", [
            'origen_id' => $this->vitrina->id,
            'producto_id' => $producto->id,
            'cantidad' => 2.5,
        ])->assertSessionHasErrors('cantidad');

    expect(Traslado::count())->toBe(0);
});

it('si en Exhibición no alcanza, no deja un traslado huérfano', function () {
    /*
     * `enviar()` y `recibir()` corrían FUERA de la transacción, así que el
     * traslado ya commiteado quedaba en Borrador —uno por cada reintento— y el
     * alistador veía un 500 en vez del motivo.
     */
    $producto = productoConStockEnVitrina($this->vitrina, 1);
    $pedido = pedidoQueNecesitaSurtido($this, $producto, 6);

    try {
        surtir($this, $pedido, $producto, 6);
    } catch (\Throwable) {
        // Lo que importa no es cómo se reporta, sino que no quede rastro.
    }

    expect(Traslado::count())->toBe(0)
        ->and((float) InventarioMovimiento::where('ubicacion_id', $this->bodega->id)->sum('cantidad'))->toBe(0.0)
        ->and((float) InventarioMovimiento::where('producto_id', $producto->id)
            ->where('ubicacion_id', $this->vitrina->id)->sum('cantidad'))->toBe(1.0);
});
