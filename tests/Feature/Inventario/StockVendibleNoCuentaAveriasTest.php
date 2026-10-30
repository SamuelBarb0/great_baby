<?php

/**
 * El número grande del inventario es lo que se puede vender.
 *
 * La ubicación no dice sólo dónde está la mercancía: dice para qué sirve. Una
 * unidad en «Avería · baja total» está contada, pesada y guardada, pero no se
 * le puede vender a nadie — de hecho se valoriza en $0.
 *
 * El KPI sumaba todas las posiciones con saldo positivo sin mirar la
 * categoría. En producción eso daba **21.645 unidades** cuando lo vendible
 * eran **13.091**: 8.554 estaban dadas de baja. Gerencia miraba un inventario
 * un 65% más grande del que existe, y es el número que se usa para decidir si
 * hay que comprar.
 *
 * No se esconde lo excluido: viaja en su propio dato y la tarjeta lo dice
 * debajo. Un número que calla no es más honesto que uno que miente.
 */

use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $producto = Producto::create([
        'referencia' => 'GB-STOCK', 'nombre' => 'Coche', 'desglose_stock' => true,
    ]);
    $this->variante = ProductoVariante::create([
        'producto_id' => $producto->id, 'color_codigo' => 'AZU',
        'color_nombre' => 'Azul', 'codigo_barras' => '7700000000111',
    ]);
});

/** Nombre propio: en Pest las funciones del archivo son globales. */
function ubicacionDeCategoria(CategoriaUbicacion $categoria, string $codigo): InventarioUbicacion
{
    return InventarioUbicacion::create([
        'codigo' => $codigo, 'nombre' => $categoria->label(),
        'categoria' => $categoria->value, 'activa' => true,
    ]);
}

function meter(object $caso, InventarioUbicacion $donde, int $cantidad): void
{
    InventarioMovimiento::create([
        'variante_id' => $caso->variante->id, 'ubicacion_id' => $donde->id,
        'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => 10000,
    ]);
}

function kpisDeInventario(object $caso): array
{
    return $caso->actingAs($caso->aracely)->get('/app/inventario')
        ->assertOk()->viewData('page')['props']['kpis'];
}

it('la baja total no entra en las unidades vendibles', function () {
    meter($this, ubicacionDeCategoria(CategoriaUbicacion::Venta, 'R-A-01'), 100);
    meter($this, ubicacionDeCategoria(CategoriaUbicacion::AveriaBaja, 'AVR-02'), 60);

    $kpis = kpisDeInventario($this);

    // 160 era el número viejo, y es el que llevaba a comprar de menos.
    expect($kpis['unidades_stock'])->toBe(100)
        ->and($kpis['unidades_no_vendibles'])->toBe(60);
});

it('la avería por reparar tampoco, porque hoy no se vende', function () {
    meter($this, ubicacionDeCategoria(CategoriaUbicacion::Venta, 'R-A-01'), 100);
    meter($this, ubicacionDeCategoria(CategoriaUbicacion::AveriaReparar, 'AVR-01'), 25);

    expect(kpisDeInventario($this)['unidades_stock'])->toBe(100);
});

it('lo apartado para garantías tampoco, porque ya tiene dueño', function () {
    meter($this, ubicacionDeCategoria(CategoriaUbicacion::Venta, 'R-A-01'), 100);
    meter($this, ubicacionDeCategoria(CategoriaUbicacion::Garantia, 'GAR-01'), 40);

    expect(kpisDeInventario($this)['unidades_stock'])->toBe(100);
});

it('la reserva de proveedor sí cuenta: es mercancía que se vende', function () {
    meter($this, ubicacionDeCategoria(CategoriaUbicacion::Venta, 'R-A-01'), 100);
    meter($this, ubicacionDeCategoria(CategoriaUbicacion::ReservaProveedor, 'RES-PROV-01'), 30);

    // El enum ya lo decía con `disponibleParaVenta()`; el KPI ahora lo respeta
    // en vez de tener su propia idea de qué es stock.
    expect(kpisDeInventario($this)['unidades_stock'])->toBe(130)
        ->and(kpisDeInventario($this)['unidades_no_vendibles'])->toBe(0);
});

it('un traslado a medio camino sigue sin inflar el número', function () {
    $origen = ubicacionDeCategoria(CategoriaUbicacion::Venta, 'R-A-01');
    $destino = ubicacionDeCategoria(CategoriaUbicacion::Venta, 'R-B-01');

    meter($this, $origen, 100);
    meter($this, $origen, -40);   // ya salió
    // ...y todavía no llegó al destino.

    // Lo que arregló INV-A4 y no se puede perder al filtrar por categoría:
    // las posiciones con saldo negativo o cero no suman.
    expect(kpisDeInventario($this)['unidades_stock'])->toBe(60);

    meter($this, $destino, 40);

    expect(kpisDeInventario($this)['unidades_stock'])->toBe(100);
});
