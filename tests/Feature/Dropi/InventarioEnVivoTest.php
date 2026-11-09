<?php

/**
 * «Saldo de inventario por variante × ubicación» (/admin/inventario-en-vivo).
 *
 * Desde el stock dual (21-sep) los saldos van con llave «v-{variante}» o
 * «p-{producto}», pero la vista los seguía buscando por el id pelado: la tabla
 * salía toda en «·» aunque «Unidades totales» sí sumaba, y los productos sin
 * desglose no aparecían.
 */

use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Filament\Pages\InventarioEnVivo;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Filament::setCurrentPanel('admin');

    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->bodega = InventarioUbicacion::create(['codigo' => 'PPAL', 'nombre' => 'Principal', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true]);
    $this->garantia = InventarioUbicacion::create(['codigo' => 'GAR', 'nombre' => 'Garantías', 'categoria' => CategoriaUbicacion::Garantia, 'activa' => true]);

    $this->conDesglose = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'AND-VAR', 'nombre' => 'Andadera por colores', 'precio_proveedor' => 1, 'activo' => true, 'desglose_stock' => true,
    ]));
    $this->variante = ProductoVariante::create(['producto_id' => $this->conDesglose->id, 'color_nombre' => 'Rojo', 'talla' => 'U', 'codigo_barras' => 'AND-VAR-ROJO']);

    $this->agregado = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'MOR-AGR', 'nombre' => 'Mordedera sin desglose', 'precio_proveedor' => 1, 'activo' => true, 'desglose_stock' => false,
    ]));

    $mov = fn (array $a) => InventarioMovimiento::create(['tipo' => 'ingreso', 'costo_unit' => 1, ...$a]);
    $mov(['producto_id' => $this->conDesglose->id, 'variante_id' => $this->variante->id, 'ubicacion_id' => $this->bodega->id, 'cantidad' => 4137]);
    $mov(['producto_id' => $this->conDesglose->id, 'variante_id' => $this->variante->id, 'ubicacion_id' => $this->garantia->id, 'cantidad' => 3]);
    $mov(['producto_id' => $this->agregado->id, 'ubicacion_id' => $this->bodega->id, 'cantidad' => 5258]);
});

it('pinta el saldo de cada variante y de cada producto sin desglose en su ubicación', function () {
    $this->actingAs($this->aracely);

    Livewire::test(InventarioEnVivo::class)
        ->assertSee('AND-VAR-ROJO')
        ->assertSeeHtml('>4137</span>')      // variante en PPAL
        ->assertSeeHtml('>3</span>')         // variante en garantías
        ->assertSee('4140')                  // total de la fila de la variante
        ->assertSee('Mordedera sin desglose')
        ->assertSee('AGREGADO')
        ->assertSeeHtml('>5258</span>')      // producto agregado en PPAL
        ->assertSee('9,398');                // «Unidades totales» cuadra con las celdas
});

it('el buscador y el filtro de categoría siguen filtrando filas y columnas', function () {
    $this->actingAs($this->aracely);

    Livewire::test(InventarioEnVivo::class)
        ->set('busqueda', 'Mordedera')
        ->assertSee('Mordedera sin desglose')
        ->assertDontSee('AND-VAR-ROJO')
        ->set('busqueda', '')
        ->set('filtroCategoria', CategoriaUbicacion::Garantia->value)
        ->assertSee('GAR')
        ->assertDontSeeHtml('>4137</span>');  // la columna PPAL ya no se pinta
});
