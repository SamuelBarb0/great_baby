<?php

/**
 * El stock inicial entra al kardex con su costo.
 *
 * Encontrado el 2026-10-13 abriendo el «Reporte de stock por bodega» después de
 * cargar un producto: la bodega tenía 40 unidades de algo que costó $12.000 y
 * el reporte la valorizaba en $0.
 *
 * El movimiento de stock inicial se creaba sin `costo_unit`. Como el kardex se
 * valoriza con el promedio ponderado de las ENTRADAS, todo lo que entró así
 * vale cero, y arrastra:
 *   · toda bodega valorizada en $0 en el reporte y en el balance;
 *   · el despacho sale a costo cero, así que no causa costo de ventas
 *     (`causarCosto` se salta el asiento) y el margen bruto da 100%.
 *
 * El costo está en el mismo formulario donde se escribe la cantidad: es el
 * «Costo» del producto (`precio_proveedor`). Nunca llegaba al kardex.
 */

use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Support\CostoPromedio;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'BOG-1', 'nombre' => 'Bodega Bogotá',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
});

function movimientoInicial(Producto $p): ?InventarioMovimiento
{
    return InventarioMovimiento::where('producto_id', $p->id)
        ->where('tipo', 'stock_inicial_form')->first();
}

it('guarda el costo del producto en el movimiento de stock inicial', function () {
    $p = Producto::create([
        'referencia' => 'GB-CONCOSTO', 'nombre' => 'Pijama algodón',
        'activo' => true, 'desglose_stock' => false,
        'stock_directo' => 40, 'precio_proveedor' => 12000,
    ]);

    expect((float) movimientoInicial($p)->costo_unit)->toEqual(12000.0);
});

it('el costo promedio de la bodega sale del stock inicial', function () {
    // Es lo que usa el despacho para valorizar la salida: si da cero, el
    // despacho no causa costo de ventas y el margen miente.
    $p = Producto::create([
        'referencia' => 'GB-PROM', 'nombre' => 'Pijama algodón',
        'activo' => true, 'desglose_stock' => false,
        'stock_directo' => 40, 'precio_proveedor' => 12000,
    ]);

    $costos = CostoPromedio::porUbicacion(
        [['variante_id' => null, 'producto_id' => $p->id]],
        movimientoInicial($p)->ubicacion_id
    );

    expect($costos['p:'.$p->id])->toEqual(12000.0);
});

it('un producto sin costo cargado no inventa uno', function () {
    // Mejor un nulo honesto que un cero que se confunde con «gratis».
    $p = Producto::create([
        'referencia' => 'GB-SINCOSTO', 'nombre' => 'Sin costo todavía',
        'activo' => true, 'desglose_stock' => false, 'stock_directo' => 10,
    ]);

    expect(movimientoInicial($p)->costo_unit)->toBeNull();
});

it('el producto creado desde la pantalla llega con su costo al kardex', function () {
    // La ruta completa, como la usa la persona: formulario → producto → kardex.
    $this->actingAs($this->admin)->post('/app/catalogo/productos', [
        'referencia' => 'GB-WEB01', 'nombre' => 'Pijama algodón',
        'desglose_stock' => false, 'stock_directo' => 40,
        'precio_proveedor' => 12000,
        'stock_ubicacion_id' => $this->bodega->id,
    ])->assertRedirect();

    $mov = movimientoInicial(Producto::where('referencia', 'GB-WEB01')->firstOrFail());

    expect($mov->ubicacion_id)->toBe($this->bodega->id)
        ->and((float) $mov->costo_unit)->toEqual(12000.0);
});
