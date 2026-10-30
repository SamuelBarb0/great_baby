<?php

/**
 * Un movimiento de kardex anulado no es stock.
 *
 * `InventarioMovimiento` fuerza borrado suave a propósito: el kardex es el
 * libro de la bodega y no se borra, se anula. Pero las consultas de stock son
 * `DB::table('inventario_movimientos')` —query builder crudo, que no sabe de
 * `deleted_at`— y seguían sumando lo anulado.
 *
 * El efecto no es teórico. El 2026-10-08, midiendo por qué ningún asiento
 * llegaba a SIIGO, se encontraron 144 movimientos apuntando a productos y
 * variantes borrados: 8.958 unidades de stock fantasma venidas de una carga
 * de Excel. `inventario:reparar-kardex` existe justo para anularlos. Los
 * anuló — y el tablero, el reporte valorizado y la ficha del producto
 * siguieron contándolos exactamente igual, porque ninguna de las dieciocho
 * consultas crudas del repositorio filtraba `deleted_at`.
 *
 * Lo grave es dónde termina ese número: `ExportSaldosInicialesService` arma
 * el Excel de «saldos iniciales de inventario» que se sube a SIIGO. Stock
 * fantasma ahí es un saldo de apertura falso en la contabilidad real.
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

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'R-A-01', 'nombre' => 'Rack A · Nivel 01',
        'categoria' => CategoriaUbicacion::Venta->value, 'activa' => true,
    ]);

    $producto = Producto::create([
        'referencia' => 'GB-ANUL', 'nombre' => 'Coche', 'desglose_stock' => true,
    ]);
    $this->variante = ProductoVariante::create([
        'producto_id' => $producto->id, 'color_codigo' => 'ROJ',
        'color_nombre' => 'Rojo', 'codigo_barras' => '7700000000222',
    ]);
});

/** Nombre propio: en Pest las funciones del archivo son globales. */
function movimientoDeKardex(object $caso, int $cantidad): InventarioMovimiento
{
    return InventarioMovimiento::create([
        'variante_id' => $caso->variante->id, 'ubicacion_id' => $caso->bodega->id,
        'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => 10000,
    ]);
}

function pantallaDeInventario(object $caso): array
{
    return $caso->actingAs($caso->aracely)->get('/app/inventario')
        ->assertOk()->viewData('page')['props'];
}

it('el movimiento anulado deja de contar en el tablero', function () {
    movimientoDeKardex($this, 100);
    $fantasma = movimientoDeKardex($this, 137);

    expect(pantallaDeInventario($this)['kpis']['unidades_stock'])->toBe(237);

    $fantasma->delete();

    expect(pantallaDeInventario($this)['kpis']['unidades_stock'])->toBe(100);
});

it('anular no borra la fila: el kardex conserva el rastro', function () {
    $mov = movimientoDeKardex($this, 137);
    $mov->delete();

    // La otra mitad de la regla. Si anular borrara físicamente, el libro de la
    // bodega perdería la historia y no habría cómo auditar el ajuste.
    expect(InventarioMovimiento::find($mov->id))->toBeNull()
        ->and(InventarioMovimiento::withTrashed()->find($mov->id))->not->toBeNull();
});

it('el movimiento anulado tampoco aparece en la tabla de stock por bodega', function () {
    movimientoDeKardex($this, 100);
    $fantasma = movimientoDeKardex($this, 137);

    $fantasma->delete();

    $fila = collect(pantallaDeInventario($this)['stock'])
        ->firstWhere('referencia', 'GB-ANUL');

    // El tablero y la tabla se calculan por caminos distintos: arreglar uno
    // solo deja dos números que no coinciden en la misma pantalla.
    expect((int) $fila['stock'])->toBe(100);
});

it('un movimiento anulado no se puede resucitar: el kardex es append-only', function () {
    $mov = movimientoDeKardex($this, 137);
    $mov->delete();

    // Restaurar es un UPDATE sobre la fila, y el kardex no admite ediciones:
    // lo que se registró, se registró. Devolver esas unidades al stock se hace
    // con un movimiento nuevo de signo contrario, que deja su propia huella.
    expect(fn () => InventarioMovimiento::withTrashed()->find($mov->id)->restore())
        ->toThrow(DomainException::class, 'append-only');

    expect(pantallaDeInventario($this)['kpis']['unidades_stock'])->toBe(0);

    movimientoDeKardex($this, 137);

    expect(pantallaDeInventario($this)['kpis']['unidades_stock'])->toBe(137);
});
