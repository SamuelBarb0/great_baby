<?php

/**
 * «Kardex» en Contabilidad de Dropi (28-nov-2026, la señora Lahida: «¿cómo se me
 * descuenta el inventario, dónde miro el kardex?»): se busca como en «Buscar
 * producto» y cada movimiento dice en palabras qué pasó, con su documento.
 */

use App\Models\User;
use App\Modules\Dropi\Filament\Pages\KardexDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\SalidaInventarioDropi;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->bodega = InventarioUbicacion::firstOrCreate(['codigo' => 'KX-BOD'], ['nombre' => 'Bodega kardex', 'activa' => true, 'categoria' => 'venta']);
    $this->abeja = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => 'KX-01', 'nombre' => 'ABEJA BAILARINA', 'desglose_stock' => false, 'activo' => true]));
    $this->pedido = DropiPedido::create([
        'guia' => '240099990001', 'dropi_orden_id' => '99990001', 'cliente_nombre' => 'Ana',
        'monto_esperado_proveedor' => 1000, 'dropi_creado_at' => now(),
    ]);

    // El kardex es append-only: la fecha se fija con el reloj, no editando el movimiento.
    $mov = function (int $cantidad, string $tipo, ?float $costo, ?string $refTipo = null, ?int $refId = null, string $cuando = '2026-11-20 08:00') {
        $this->travelTo(\Carbon\Carbon::parse($cuando, 'America/Bogota'));
        InventarioMovimiento::create([
            'producto_id' => $this->abeja->id, 'variante_id' => null, 'ubicacion_id' => $this->bodega->id,
            'tipo' => $tipo, 'cantidad' => $cantidad, 'costo_unit' => $costo,
            'referencia_tipo' => $refTipo, 'referencia_id' => $refId,
        ]);
        $this->travelBack();
    };

    $mov(10, 'stock_inicial_form', 1000, cuando: '2026-11-20 08:00');
    $mov(10, 'entrada_compra', 2000, cuando: '2026-11-21 08:00');
    $mov(-3, 'egreso', 1500, SalidaInventarioDropi::EGRESO, $this->pedido->id, '2026-11-22 08:00');
    $mov(1, 'ingreso', 1500, SalidaInventarioDropi::REVERSO, $this->pedido->id, '2026-11-22 09:00');
});

it('está en Contabilidad de Dropi y busca por el nombre que el producto tiene en Dropi', function () {
    DB::table('dropi_vinculos_producto')->insert([
        'sku_dropi' => 'DV-KX-1', 'producto_id' => $this->abeja->id, 'variante_id' => null,
        'producto_nombre' => 'Abejita chiquitica', 'variacion' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->aracely);

    expect(KardexDropi::getNavigationGroup())->toBe('Contabilidad');

    // Una sola coincidencia: se abre de una.
    Livewire::test(KardexDropi::class)
        ->set('buscar', 'abejita')
        ->assertSet('producto', $this->abeja->id)
        ->assertSeeText('ABEJA BAILARINA');
});

it('cada movimiento dice qué pasó, con su guía, y el saldo y el costo promedio cuadran', function () {
    $this->actingAs($this->aracely);

    $pagina = Livewire::test(KardexDropi::class)
        ->call('elegir', $this->abeja->id)
        // 10 + 10 − 3 + 1 = 18; promedio (10×1000 + 10×2000) / 20 = 1500.
        ->assertSeeInOrder(['Unidades hoy', '18', 'Costo promedio por unidad', '$1.500', 'Valor del inventario', '$27.000'])
        ->assertSeeText('Volvió: se desempacó el pedido')
        ->assertSeeText('Salió por pedido Dropi')
        ->assertSeeText('Guía 240099990001')
        ->assertSee(\App\Modules\Dropi\Filament\Resources\DropiPedidoResource::getUrl('view', ['record' => $this->pedido->id]), false)
        ->assertDontSeeText('egreso')
        ->assertSeeText('Mostrando 4 de 4 movimientos');

    // El más reciente arriba, en palabras, con el saldo que dejó cada uno.
    expect(collect($pagina->instance()->kardex['movimientos'])->map(fn ($m) => [$m['que'], $m['saldo']])->all())->toBe([
        ['Volvió: se desempacó el pedido', 18],
        ['Salió por pedido Dropi', 17],
        ['Entró por compra', 20],
        ['Inventario inicial (al crear el producto)', 10],
    ]);
});

it('quien no tiene kardex ni contabilidad no la ve', function () {
    $sinPermiso = User::factory()->create();
    $this->actingAs($sinPermiso);

    expect(KardexDropi::canAccess())->toBeFalse();
    $this->get(KardexDropi::getUrl())->assertForbidden();
});

it('sin buscar muestra el resumen del periodo, lo último que se movió y lo que más se movió (28-nov-2026)', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-11-28 10:00', 'America/Bogota'));
    $this->actingAs($this->aracely);

    $pagina = Livewire::test(KardexDropi::class)->set('periodo', 'mes');
    $k = $pagina->instance();

    // Salieron 3 por pedido (a $1.500), entraron 20, volvió 1; valor = 10.000 + 20.000 − 4.500 + 1.500.
    expect($k->resumen)->toMatchArray(['salieron' => 3, 'salieron_costo' => 4500.0, 'entraron' => 20, 'volvieron' => 1, 'otras_salidas' => 0, 'valor' => 27000.0])
        ->and($k->recientes[0])->toMatchArray(['producto_id' => $this->abeja->id, 'producto' => 'ABEJA BAILARINA', 'que' => 'Volvió: se desempacó el pedido', 'cantidad' => 1])
        ->and($k->top[0])->toMatchArray(['id' => $this->abeja->id, 'salieron' => 3, 'entraron' => 21, 'saldo' => 18]);

    $pagina->assertSeeText('Salieron por pedidos Dropi')
        ->assertSeeText('Los que más se movieron')
        ->assertSeeText('Últimos movimientos de todos los productos')
        ->assertSee("elegir({$this->abeja->id})", false);

    // Hoy no se movió nada: el resumen en cero, los últimos movimientos siguen ahí.
    $pagina->set('periodo', 'hoy');
    expect($pagina->instance()->resumen['salieron'])->toBe(0)
        ->and($pagina->instance()->recientes)->toHaveCount(4);

    // Del resumen al kardex del producto y de vuelta.
    $pagina->call('elegir', $this->abeja->id)->assertSeeText('Volver al resumen')
        ->call('soltar')->assertSet('producto', null)->assertSeeText('Últimos movimientos de todos los productos');
});
