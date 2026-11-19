<?php

/**
 * La hoja de picking dice dónde está la mercancía, también la agregada.
 *
 * Encontrado el 2026-10-14 armando el manual de logística: la hoja imprimía
 * «⚠ SIN STOCK — reportar a Jorge» sobre un producto con 52 unidades repartidas
 * en dos bodegas.
 *
 * La consulta de ubicaciones filtraba `whereIn('m.variante_id', …)`. Los
 * productos AGREGADOS —los que no se desglosan por color ni talla, la mayor
 * parte del catálogo de Great Baby— tienen la variante en null, así que nunca
 * entraban y su lista salía vacía.
 *
 * El daño no es la hoja fea: es que manda al alistador a reportar un faltante
 * que no existe, y un faltante abre novedad y retiene el pedido.
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

    $this->rack = InventarioUbicacion::create([
        'codigo' => 'BOG-R1', 'nombre' => 'Rack A · Nivel 01',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $this->otra = InventarioUbicacion::create([
        'codigo' => 'BOG-2', 'nombre' => 'Bodega secundaria',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $this->cliente = Contacto::create([
        'numero_documento' => '901777888', 'nombre_completo' => 'Pañalera Mundo Bebé SAS',
        'es_cliente' => true, 'ciudad' => 'Medellín',
    ]);
});

function productoAgregadoEn(array $saldos): Producto
{
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GB-AGREGADO', 'nombre' => 'Pijama algodón',
        'activo' => true, 'desglose_stock' => false, 'precio_proveedor' => 12000,
    ]));

    foreach ($saldos as $ubicacionId => $cantidad) {
        InventarioMovimiento::create([
            'producto_id' => $p->id, 'ubicacion_id' => $ubicacionId,
            'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => 12000,
        ]);
    }

    return $p;
}

function hojaDe($test, Producto $producto): array
{
    $factura = FacturaVenta::create([
        'numero' => 'FV-'.fake()->unique()->numerify('######'),
        'contacto_id' => $test->cliente->id, 'estado' => 'pendiente',
        'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => 74700, 'impuestos' => 0, 'total' => 74700,
    ]);

    $pedido = PedidoCliente::create([
        'numero' => 'PB-'.fake()->unique()->numerify('######-####'),
        'contacto_id' => $test->cliente->id, 'estado' => 'facturado',
        'factura_id' => $factura->id, 'subtotal' => 74700, 'total' => 74700,
    ]);

    PedidoClienteItem::create([
        'pedido_id' => $pedido->id, 'producto_id' => $producto->id,
        'cantidad' => 3, 'precio_unitario' => 24900, 'subtotal' => 74700, 'total' => 74700,
        'sku_snapshot' => $producto->referencia, 'descripcion_snapshot' => $producto->nombre,
    ]);

    $props = $test->actingAs($test->jorge)
        ->get("/app/logistica/cola-jorge/{$pedido->id}/picking")
        ->assertOk()->viewData('page')['props'];

    return collect($props['items'])->first();
}

it('encuentra la ubicación de un producto agregado', function () {
    $p = productoAgregadoEn([$this->rack->id => 32]);

    expect(hojaDe($this, $p)['ubicaciones'])->not->toBeEmpty();
});

it('pone primero la bodega con más existencias', function () {
    // El alistador va a la que tiene más; las otras son el plan B.
    $p = productoAgregadoEn([$this->rack->id => 32, $this->otra->id => 20]);

    $ubic = hojaDe($this, $p)['ubicaciones'];

    expect($ubic[0]['codigo'])->toBe('BOG-R1')
        ->and($ubic[0]['stock'])->toBe(32)
        ->and($ubic[1]['codigo'])->toBe('BOG-2');
});

it('un producto que de verdad no tiene stock sigue saliendo vacío', function () {
    // El aviso de «sin stock» tiene que seguir existiendo cuando es cierto.
    $p = productoAgregadoEn([]);

    expect(hojaDe($this, $p)['ubicaciones'])->toBeEmpty();
});

it('no cuenta el stock de las variantes como si fuera del producto', function () {
    /*
     * Un movimiento con variante pertenece a la variante, no al agregado. Hoy
     * el modelo impide mezclar los dos modos, pero los datos viejos sí pueden
     * traerlos juntos —por eso la consulta lleva `whereNull('m.variante_id')`—,
     * así que se arma el caso como quedó en la base: producto granular con su
     * variante, y después pasado a agregado.
     */
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GB-MIXTO', 'nombre' => 'Producto de datos viejos',
        'activo' => true, 'desglose_stock' => true, 'precio_proveedor' => 12000,
    ]));
    $variante = App\Modules\Dropi\Models\ProductoVariante::create([
        'producto_id' => $p->id, 'codigo_barras' => 'GB-MIXTO-ROJO', 'color_nombre' => 'Rojo',
    ]);
    $p->forceFill(['desglose_stock' => false])->save();

    InventarioMovimiento::create([
        'producto_id' => $p->id, 'ubicacion_id' => $this->rack->id,
        'tipo' => 'entrada_compra', 'cantidad' => 32, 'costo_unit' => 12000,
    ]);
    InventarioMovimiento::create([
        'producto_id' => $p->id, 'variante_id' => $variante->id,
        'ubicacion_id' => $this->otra->id, 'tipo' => 'entrada_compra',
        'cantidad' => 500, 'costo_unit' => 12000,
    ]);

    $ubic = collect(hojaDe($this, $p->fresh())['ubicaciones']);

    expect($ubic->firstWhere('codigo', 'BOG-R1')['stock'])->toBe(32)
        ->and($ubic->pluck('codigo'))->not->toContain('BOG-2');
});

it('ignora las ubicaciones inactivas', function () {
    $muerta = InventarioUbicacion::create([
        'codigo' => 'VIEJA', 'nombre' => 'Bodega cerrada',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => false,
    ]);
    $p = productoAgregadoEn([$this->rack->id => 32, $muerta->id => 99]);

    expect(collect(hojaDe($this, $p)['ubicaciones'])->pluck('codigo'))->not->toContain('VIEJA');
});
