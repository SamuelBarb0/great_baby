<?php

/**
 * El stock agregado tiene que existir para TODAS las pantallas.
 *
 * Este ERP guarda el saldo de dos maneras: por variante (color/talla) y por
 * producto a secas. Varias pantallas se escribieron mirando sólo la primera, y
 * el resultado es siempre el mismo: unidades que están en la bodega y que el
 * sistema no ve. Ya pasó con la hoja de picking y se corrigió; acá se cierran
 * las tres que seguían.
 *
 * Lo que se cubre —medido en la base de desarrollo el 29-oct-2026—:
 *
 *   1 · El conteo físico sembraba por la BANDERA del producto en vez de por el
 *       kardex. Un producto marcado granular al que le cargaron el saldo sin
 *       variante no entraba por ningún lado: 20 de 39 unidades invisibles. Un
 *       conteo que omite saldo es peor que no contar, porque devuelve una
 *       diferencia inventada y alguien ajusta el inventario con ella.
 *
 *   2 · El buscador de traslados y el de devoluciones a proveedor sólo
 *       consultaban variantes, así que no se podía armar ni un traslado ni una
 *       devolución de esos productos: el borrador quedaba sin ítems para
 *       siempre.
 *
 * `DesgloseDualTest` ya avisaba de los dos primeros en su cabecera —«NO cubre
 * (queda para R2): EjecutarTraslado con items agregados, PrepararTomaFisica
 * con productos agregados»—. Esto es ese R2.
 */

use App\Models\User;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Inventario\Actions\PrepararTomaFisica;
use App\Modules\Inventario\Models\TomaFisica;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'QA-AGG', 'nombre' => 'Bodega de la prueba',
        'categoria' => \App\Modules\Dropi\Enums\CategoriaUbicacion::Venta, 'activa' => true,
    ]);
});

/** Un producto declarado agregado, con saldo sin variante. */
function elAgregadoDeclarado(object $caso, int $cantidad = 19): Producto
{
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'QA-AGG-DECLARADO', 'nombre' => 'Pijama de la prueba',
        'activo' => true, 'desglose_stock' => false, 'precio_proveedor' => 12000,
    ]));

    InventarioMovimiento::create([
        'producto_id' => $p->id, 'ubicacion_id' => $caso->bodega->id,
        'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => 12000,
    ]);

    return $p;
}

/**
 * El caso que rompía todo: la bandera dice «granular» y el saldo entró
 * agregado. No es un dato inventado para la prueba — así está cargado el
 * catálogo real.
 */
function elGranularConSaldoAgregado(object $caso, int $cantidad = 20): Producto
{
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'QA-AGG-GRANULAR', 'nombre' => 'Body de la prueba',
        'activo' => true, 'desglose_stock' => true, 'precio_proveedor' => 9000,
    ]));

    ProductoVariante::create([
        'producto_id' => $p->id, 'codigo_barras' => 'QA-AGG-GRANULAR-RS',
        'color_nombre' => 'Rosa', 'talla' => 'U',
    ]);

    // El saldo entra SIN variante, que es justo el desacuerdo.
    InventarioMovimiento::create([
        'producto_id' => $p->id, 'ubicacion_id' => $caso->bodega->id,
        'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => 9000,
    ]);

    return $p;
}

function elConteoDe(object $caso): TomaFisica
{
    $t = TomaFisica::create([
        'numero' => 'QA-TF-'.fake()->unique()->numerify('#####'),
        'ubicacion_id' => $caso->bodega->id,
        'fecha_conteo' => now()->toDateString(),
        'tipo' => 'ciclico',
        // Explícito: el default vive en la base y el modelo recién creado no
        // lo lee, así que `PrepararTomaFisica` veía «sin estado» y abortaba.
        'estado' => \App\Modules\Inventario\Enums\EstadoTomaFisica::Borrador->value,
    ]);

    PrepararTomaFisica::run($t);

    return $t->fresh();
}

// ───────────────────────── 1 · El conteo físico ─────────────────────────

it('el conteo siembra lo que dice el kardex, no lo que dice la bandera', function () {
    elAgregadoDeclarado($this, 19);
    elGranularConSaldoAgregado($this, 20);

    $items = elConteoDe($this)->items;

    // Antes entraba sólo el declarado agregado: el otro se perdía entre los
    // dos bloques y sus 20 unidades no se contaban.
    expect($items)->toHaveCount(2)
        ->and((int) $items->sum('saldo_sistema'))->toBe(39);
});

it('el renglón del conteo dice qué se está contando', function () {
    elAgregadoDeclarado($this, 19);

    $item = elConteoDe($this)->items->first();

    // Salía con SKU y producto en blanco: una fila que decía «19» y nada más.
    // No se puede contar lo que no se sabe qué es.
    expect($item->producto?->referencia)->toBe('QA-AGG-DECLARADO')
        ->and($item->producto?->nombre)->toBe('Pijama de la prueba');
});

it('un producto sin saldo no entra al conteo cíclico', function () {
    Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'QA-AGG-VACIO', 'nombre' => 'Sin existencias',
        'activo' => true, 'desglose_stock' => false, 'precio_proveedor' => 1000,
    ]));
    elAgregadoDeclarado($this, 19);

    // El arreglo ensancha lo que entra; no puede ensancharlo de más y llenar
    // la planilla de renglones en cero.
    expect(elConteoDe($this)->items)->toHaveCount(1);
});

// ───────────────────── 2 · Los dos buscadores ─────────────────────

it('el buscador de traslados encuentra el stock agregado', function () {
    elAgregadoDeclarado($this, 19);

    $r = $this->actingAs($this->aracely)
        ->getJson('/app/inventario/buscar-variantes?q=Pijama de la prueba')
        ->assertOk()->json();

    // Devolvía 0 resultados, así que el traslado se quedaba en borrador sin
    // manera de agregarle un ítem.
    expect($r)->not->toBeEmpty()
        ->and(collect($r)->pluck('sku'))->toContain('QA-AGG-DECLARADO');
});

it('el buscador de devoluciones encuentra el stock agregado de esa bodega', function () {
    elAgregadoDeclarado($this, 19);

    $r = $this->actingAs($this->aracely)
        ->getJson('/app/compras/devoluciones/items/buscar?q=Pijama de la prueba'
            .'&ubicacion_id='.$this->bodega->id)
        ->assertOk()->json();

    expect($r)->not->toBeEmpty()
        ->and(collect($r)->firstWhere('sku', 'QA-AGG-DECLARADO')['stock_actual'])->toBe(19);
});

it('la devolución no ofrece lo que no está en esa bodega', function () {
    elAgregadoDeclarado($this, 19);

    $otra = InventarioUbicacion::create([
        'codigo' => 'QA-OTRA', 'nombre' => 'Otra bodega',
        'categoria' => \App\Modules\Dropi\Enums\CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    // Se devuelve desde una bodega concreta: ensanchar la búsqueda no puede
    // llegar a ofrecer mercancía que allí no hay.
    $r = $this->actingAs($this->aracely)
        ->getJson('/app/compras/devoluciones/items/buscar?q=Pijama de la prueba'
            .'&ubicacion_id='.$otra->id)
        ->assertOk()->json();

    expect($r)->toBeEmpty();
});
