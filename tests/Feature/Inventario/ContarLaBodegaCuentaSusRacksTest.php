<?php

/**
 * Contar «la bodega» tiene que incluir sus racks.
 *
 * El snapshot de la toma física buscaba el saldo de la ubicación EXACTA. Para
 * una posición suelta está bien; para una SEDE —que en Great Baby tiene 436
 * posiciones colgando— significaba que todo lo ya bajado a estantería contaba
 * como cero.
 *
 * Medido en la base de desarrollo el 22-nov-2026: una toma sobre la bodega
 * principal congeló 14 unidades de una referencia que tenía 19; las otras 5
 * estaban en dos racks de esa misma bodega.
 *
 * Lo que pasa después es lo caro. El operario cuenta físicamente la bodega y
 * encuentra 19. La toma calcula +5 de sobrante, el cierre escribe una entrada
 * de 5 en la fila de la sede, y el rack se queda con las suyas: el sistema
 * termina con 24 unidades donde hay 19, más un asiento de aprovechamiento
 * (4295) por mercancía que nunca se perdió. Un conteo que inventa sobrantes es
 * peor que no contar, y éste los inventaba en silencio.
 */

use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Actions\PrepararTomaFisica;
use App\Modules\Inventario\Enums\EstadoTomaFisica;
use App\Modules\Inventario\Models\TomaFisica;
use App\Modules\Inventario\Models\TomaFisicaItem;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->sede = InventarioUbicacion::create([
        'codigo' => 'SEDE-CNT', 'nombre' => 'Bodega que se cuenta',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'disponible_para_venta' => true,
    ]);

    // Dos racks colgando de esa sede, que es como está armada la bodega real.
    $this->rackA = InventarioUbicacion::create([
        'codigo' => 'CNT-R1-N1', 'nombre' => 'Rack 1 nivel 1',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'disponible_para_venta' => true, 'bodega_id' => $this->sede->id, 'pasillo' => '1',
    ]);

    $this->rackB = InventarioUbicacion::create([
        'codigo' => 'CNT-R7-N1', 'nombre' => 'Rack 7 nivel 1',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'disponible_para_venta' => true, 'bodega_id' => $this->sede->id, 'pasillo' => '7',
    ]);
});

/** Un producto agregado con stock repartido: [ubicacion_id => unidades]. */
function productoParaContar(array $saldos, float $costo = 9000): Producto
{
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GB-CNT-'.fake()->unique()->numerify('####'),
        'nombre' => 'Body manga larga', 'activo' => true,
        'desglose_stock' => false, 'precio_proveedor' => 1,
    ]));

    foreach ($saldos as $ubicacionId => $cantidad) {
        InventarioMovimiento::create([
            'producto_id' => $p->id, 'ubicacion_id' => $ubicacionId,
            'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => $costo,
        ]);
    }

    return $p;
}

function contar(InventarioUbicacion $donde, string $tipo = 'total'): TomaFisica
{
    $t = TomaFisica::create([
        'numero' => 'QA-CNT-'.fake()->unique()->numerify('#####'),
        'ubicacion_id' => $donde->id,
        'fecha_conteo' => now()->toDateString(),
        'tipo' => $tipo,
        'estado' => EstadoTomaFisica::Borrador->value,
    ]);

    PrepararTomaFisica::run($t);

    return $t->fresh();
}

function saldoCongelado(TomaFisica $toma, Producto $p): ?float
{
    return TomaFisicaItem::where('toma_id', $toma->id)
        ->where('producto_id', $p->id)
        ->value('saldo_sistema');
}

it('la toma de una bodega congela también lo que está en sus racks', function () {
    // 14 en la fila de la sede y 5 repartidas en dos racks: 19 en total.
    $producto = productoParaContar([
        $this->sede->id => 14,
        $this->rackA->id => 3,
        $this->rackB->id => 2,
    ]);

    $toma = contar($this->sede);

    // 19, no 14. Con 14 el operario que cuenta la bodega y encuentra 19 genera
    // un sobrante de 5 que nunca existió.
    expect((float) saldoCongelado($toma, $producto))->toBe(19.0);
});

it('la toma de un rack suelto sigue contando sólo ese rack', function () {
    $producto = productoParaContar([
        $this->sede->id => 14,
        $this->rackA->id => 3,
        $this->rackB->id => 2,
    ]);

    // Es el otro lado de lo mismo: si la toma de una posición empezara a sumar
    // la bodega entera, contar un estante sería imposible.
    expect((float) saldoCongelado(contar($this->rackA, 'ciclico'), $producto))->toBe(3.0);
});

it('el costo del ajuste se promedia sobre los mismos lugares que se cuentan', function () {
    /*
     * El costo también miraba la ubicación exacta. Una referencia que vive sólo
     * en racks salía sin costo en una toma de la sede, y el ajuste se valoraba
     * con el `precio_proveedor` del maestro — justo lo que esta clase dejó de
     * hacer en la auditoría M3, porque ese precio lo puede mover cualquiera
     * con acceso al catálogo antes de abrir la toma.
     */
    $soloEnRacks = productoParaContar([$this->rackA->id => 10], costo: 7500);

    $item = TomaFisicaItem::where('toma_id', contar($this->sede)->id)
        ->where('producto_id', $soloEnRacks->id)->first();

    expect((float) $item->saldo_sistema)->toBe(10.0)
        // 7.500 del kardex, no el 1 del maestro.
        ->and((float) $item->costo_unit)->toBe(7500.0);
});

it('cuenta lo que hay, aunque no se pueda vender', function () {
    /*
     * Una toma física cuenta mercancía, no mercancía vendible. Si las
     * posiciones de avería o cuarentena quedaran fuera, aparecerían como
     * sobrante justo las unidades apartadas, que son las que más vigila el
     * área.
     */
    $averias = InventarioUbicacion::create([
        'codigo' => 'CNT-AVE', 'nombre' => 'Averías de la sede',
        'categoria' => CategoriaUbicacion::AveriaReparar, 'activa' => true,
        'disponible_para_venta' => false, 'bodega_id' => $this->sede->id,
    ]);

    $producto = productoParaContar([$this->sede->id => 4, $averias->id => 6]);

    expect((float) saldoCongelado(contar($this->sede), $producto))->toBe(10.0);
});

it('no deja abrir una toma de la bodega mientras se cuenta uno de sus racks', function () {
    /*
     * El agujero que abrió ensanchar el alcance.
     *
     * Contar la bodega cuenta también sus racks, así que una toma de la sede y
     * otra de uno de sus estantes congelan LAS MISMAS unidades físicas aunque
     * sus `ubicacion_id` sean distintos. Las dos cuentan 8 donde el sistema
     * decía 10, las dos cierran con −2, y el kardex termina en 6 con dos
     * asientos de faltante por una sola diferencia.
     */
    productoParaContar([$this->sede->id => 14, $this->rackA->id => 5]);

    $crear = fn (InventarioUbicacion $donde) => $this->actingAs($this->aracely)
        ->post('/app/inventario/conteos', ['ubicacion_id' => $donde->id, 'tipo' => 'ciclico']);

    $crear($this->rackA)->assertSessionHas('success');

    // La bodega abarca ese rack: no puede abrirse.
    $crear($this->sede)->assertSessionHasErrors('ubicacion_id');

    expect(TomaFisica::count())->toBe(1);
});

it('tampoco al revés: con la bodega abierta no se puede contar un rack suyo', function () {
    productoParaContar([$this->sede->id => 14, $this->rackA->id => 5]);

    $crear = fn (InventarioUbicacion $donde) => $this->actingAs($this->aracely)
        ->post('/app/inventario/conteos', ['ubicacion_id' => $donde->id, 'tipo' => 'total']);

    $crear($this->sede)->assertSessionHas('success');
    $crear($this->rackA)->assertSessionHasErrors('ubicacion_id');

    expect(TomaFisica::count())->toBe(1);
});

it('dos racks hermanos sí se pueden contar a la vez', function () {
    // No se pisan: son sitios distintos y es lo normal en un conteo cíclico.
    productoParaContar([$this->rackA->id => 5, $this->rackB->id => 7]);

    $crear = fn (InventarioUbicacion $donde) => $this->actingAs($this->aracely)
        ->post('/app/inventario/conteos', ['ubicacion_id' => $donde->id, 'tipo' => 'ciclico']);

    $crear($this->rackA)->assertSessionHas('success');
    $crear($this->rackB)->assertSessionHas('success');

    expect(TomaFisica::count())->toBe(2);
});

it('una posición desactivada con saldo sigue contando', function () {
    /*
     * Desactivar una posición la saca del plano pero NO la vacía. Si queda
     * fuera del conteo, el operario cuenta esas unidades en el piso, sale un
     * sobrante y el cierre lo escribe en la sede: el mismo sobrante inventado
     * que este arreglo vino a matar, entrando por otra puerta.
     */
    $retirado = InventarioUbicacion::create([
        'codigo' => 'CNT-R9-OFF', 'nombre' => 'Rack retirado del plano',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => false,
        'disponible_para_venta' => false, 'bodega_id' => $this->sede->id, 'pasillo' => '9',
    ]);

    $producto = productoParaContar([$this->sede->id => 14, $retirado->id => 6]);

    expect((float) saldoCongelado(contar($this->sede), $producto))->toBe(20.0);
});
