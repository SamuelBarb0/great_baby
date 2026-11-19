<?php

/**
 * La migración que repara `dropi_pedido_items.producto_id`.
 *
 * En prod la migración original figura como corrida pero la columna no está.
 * La reparación tiene que crearla y llenarla donde falta, y no tocar nada
 * donde ya existe (local, pruebas, cualquier otra máquina).
 */

use App\Modules\Dropi\Actions\AsignarCorteACarga;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function reparacionProductoId()
{
    return require database_path('migrations/2026_11_09_100000_reparar_producto_id_en_dropi_pedido_items.php');
}

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }

    $producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'REP-1', 'nombre' => 'Producto con variante', 'precio_proveedor' => 1, 'activo' => true, 'desglose_stock' => true,
    ]));
    $this->producto = $producto;
    $this->variante = ProductoVariante::create(['producto_id' => $producto->id, 'color_nombre' => 'Azul', 'talla' => 'U', 'codigo_barras' => 'REP-1-AZUL']);

    $pedido = DropiPedido::create([
        'corte_id' => AsignarCorteACarga::run(CarbonImmutable::now())->id, 'guia' => 'GREP1', 'dropi_orden_id' => '7001',
        'cliente_nombre' => 'Ana', 'monto_esperado_proveedor' => 1000,
    ]);
    $this->itemId = DB::table('dropi_pedido_items')->insertGetId([
        'pedido_id' => $pedido->id, 'sku_dropi' => 'REP-1-AZUL', 'variante_id' => $this->variante->id,
        'cantidad' => 1, 'precio_proveedor_unit' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('crea la columna y la llena desde la variante donde falta, como en prod', function () {
    // Así está prod: la columna no existe.
    Schema::table('dropi_pedido_items', fn (Blueprint $t) => $t->dropConstrainedForeignId('producto_id'));
    expect(Schema::hasColumn('dropi_pedido_items', 'producto_id'))->toBeFalse();

    reparacionProductoId()->up();

    expect(Schema::hasColumn('dropi_pedido_items', 'producto_id'))->toBeTrue()
        ->and(DB::table('dropi_pedido_items')->where('id', $this->itemId)->value('producto_id'))->toBe($this->producto->id);

    // Y la llave foránea quedó: no deja apuntar a un producto inexistente.
    expect(fn () => DB::table('dropi_pedido_items')->where('id', $this->itemId)->update(['producto_id' => 999999]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('donde la columna ya existe no cambia nada, y se puede correr dos veces', function () {
    $otro = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'REP-2', 'nombre' => 'Agregado vinculado a mano', 'precio_proveedor' => 1, 'activo' => true, 'desglose_stock' => false,
    ]));
    // Un ítem que ya tiene su producto no se pisa con el de la variante.
    DB::table('dropi_pedido_items')->where('id', $this->itemId)->update(['producto_id' => $otro->id]);

    reparacionProductoId()->up();
    reparacionProductoId()->up();

    expect(DB::table('dropi_pedido_items')->where('id', $this->itemId)->value('producto_id'))->toBe($otro->id);
});
