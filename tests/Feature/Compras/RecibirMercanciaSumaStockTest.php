<?php

/**
 * Recibir mercancía tiene que meterla al inventario.
 *
 * Encontrado el 2026-10-14 armando el manual de logística: se crea una orden de
 * compra por 20 unidades, se aprueba, se recibe… y el stock queda igual que
 * antes. Es el espejo exacto del defecto del despacho (la venta no sacaba
 * stock; la compra no lo metía).
 *
 * Eran tres cosas encadenadas:
 *
 *   1. `exists:compras_orden_items` — la tabla es `compra_orden_items`, en
 *      singular. Con el nombre mal, CONFIRMAR UNA RECEPCIÓN REVENTABA SIEMPRE
 *      con «table doesn't exist»: el botón se quedaba en «Guardando…» y no se
 *      podía meter nada a bodega por la interfaz.
 *   2. El formulario de la OC sólo mandaba `descripcion` (texto libre). El
 *      backend ya aceptaba `producto_id` y la columna existía, pero nadie la
 *      llenaba, así que la línea llegaba a bodega sin saber de qué producto
 *      era y `RecibirMercancia` se saltaba el kardex en silencio.
 *   3. El ítem de recepción copiaba `variante_id` de la OC pero NO
 *      `producto_id`, así que los productos agregados —la mayor parte del
 *      catálogo de Great Baby— lo perdían igual.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'BOD-1', 'nombre' => 'Bodega principal',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $this->proveedor = Contacto::create([
        'numero_documento' => '901000111', 'nombre_completo' => 'Textiles Andinos SAS',
        'es_proveedor' => true,
    ]);

    $this->producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GB-COMPRA', 'nombre' => 'Pijama algodón',
        'activo' => true, 'desglose_stock' => false, 'precio_proveedor' => 12000,
    ]));
});

function crearOC($test, array $item): int
{
    $test->actingAs($test->aracely)->post('/app/compras/oc', [
        'proveedor_id' => $test->proveedor->id,
        'bodega_id' => $test->bodega->id,
        'tipo' => 'nacional', 'moneda' => 'COP', 'tasa_cambio' => 1,
        'items' => [$item],
    ])->assertRedirect();

    return (int) DB::table('compras_ordenes')->latest('id')->value('id');
}

function aprobarYRecibir($test, int $ocId, float $cantidad)
{
    $test->actingAs($test->aracely)->post("/app/compras/oc/{$ocId}/aprobar")->assertRedirect();

    $itemId = (int) DB::table('compra_orden_items')->where('orden_id', $ocId)->value('id');

    return $test->actingAs($test->aracely)->post('/app/compras/recepcion', [
        'orden_id' => $ocId,
        'items' => [['orden_item_id' => $itemId, 'cantidad_recibida' => $cantidad]],
    ]);
}

function saldoDe(Producto $p): int
{
    return (int) InventarioMovimiento::where('producto_id', $p->id)->sum('cantidad');
}

it('la recepción se guarda · la regla exists apuntaba a una tabla inexistente', function () {
    // Antes esto devolvía 500 «Base table or view not found».
    $oc = crearOC($this, [
        'producto_id' => $this->producto->id,
        'descripcion' => 'GB-COMPRA · Pijama algodón',
        'cantidad' => 20, 'precio_unit' => 12000, 'iva_pct' => 0,
    ]);

    aprobarYRecibir($this, $oc, 20)->assertRedirect();

    expect(DB::table('compras_recepciones')->count())->toBe(1);
});

it('la mercancía recibida entra al inventario', function () {
    expect(saldoDe($this->producto))->toBe(0);

    $oc = crearOC($this, [
        'producto_id' => $this->producto->id,
        'descripcion' => 'GB-COMPRA · Pijama algodón',
        'cantidad' => 20, 'precio_unit' => 12000, 'iva_pct' => 0,
    ]);
    aprobarYRecibir($this, $oc, 20);

    expect(saldoDe($this->producto))->toBe(20);
});

it('el movimiento entra con su costo y en la bodega destino de la OC', function () {
    // Sin costo el kardex no se puede valorizar ni el despacho causar su costo.
    $oc = crearOC($this, [
        'producto_id' => $this->producto->id,
        'descripcion' => 'GB-COMPRA', 'cantidad' => 20, 'precio_unit' => 12000, 'iva_pct' => 0,
    ]);
    aprobarYRecibir($this, $oc, 20);

    $mov = InventarioMovimiento::where('producto_id', $this->producto->id)
        ->where('tipo', 'entrada_compra')->first();

    expect((float) $mov->costo_unit)->toEqual(12000.0)
        ->and($mov->ubicacion_id)->toBe($this->bodega->id);
});

it('el ítem de recepción hereda el producto de la línea de la OC', function () {
    // Copiaba sólo `variante_id`; un producto agregado llegaba sin nada.
    $oc = crearOC($this, [
        'producto_id' => $this->producto->id,
        'descripcion' => 'GB-COMPRA', 'cantidad' => 20, 'precio_unit' => 12000, 'iva_pct' => 0,
    ]);
    aprobarYRecibir($this, $oc, 20);

    expect((int) DB::table('compras_recepcion_items')->value('producto_id'))
        ->toBe($this->producto->id);
});

it('una línea sin producto se recibe pero no inventa existencias', function () {
    // Caso legítimo: fletes, seguros, servicios. Se registra la recepción y no
    // se toca el kardex, porque no hay a qué sumarle.
    $oc = crearOC($this, [
        'descripcion' => 'Flete marítimo', 'cantidad' => 1, 'precio_unit' => 500000, 'iva_pct' => 0,
    ]);
    aprobarYRecibir($this, $oc, 1)->assertRedirect();

    expect(DB::table('compras_recepciones')->count())->toBe(1)
        ->and(InventarioMovimiento::where('tipo', 'entrada_compra')->count())->toBe(0);
});

it('rechaza una línea que apunta a un producto inexistente', function () {
    // Se validaba como entero suelto: un id inventado pasaba derecho.
    $this->actingAs($this->aracely)->post('/app/compras/oc', [
        'proveedor_id' => $this->proveedor->id,
        'bodega_id' => $this->bodega->id,
        'tipo' => 'nacional', 'moneda' => 'COP', 'tasa_cambio' => 1,
        'items' => [[
            'producto_id' => 999999, 'descripcion' => 'Inventado',
            'cantidad' => 1, 'precio_unit' => 100, 'iva_pct' => 0,
        ]],
    ])->assertSessionHasErrors('items.0.producto_id');
});

it('recibir de a poco suma dos veces y respeta el tope de la OC', function () {
    $oc = crearOC($this, [
        'producto_id' => $this->producto->id,
        'descripcion' => 'GB-COMPRA', 'cantidad' => 20, 'precio_unit' => 12000, 'iva_pct' => 0,
    ]);

    aprobarYRecibir($this, $oc, 12);
    expect(saldoDe($this->producto))->toBe(12);

    $itemId = (int) DB::table('compra_orden_items')->where('orden_id', $oc)->value('id');
    $this->actingAs($this->aracely)->post('/app/compras/recepcion', [
        'orden_id' => $oc,
        'items' => [['orden_item_id' => $itemId, 'cantidad_recibida' => 8]],
    ]);

    expect(saldoDe($this->producto))->toBe(20);
});
