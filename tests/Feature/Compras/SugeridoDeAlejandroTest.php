<?php

/**
 * El sugerido con la cuenta de Alejandro (Word, sección 2): lo vendido desde que
 * llegó, cubierto a tres meses. Los productos de línea siempre se mantienen.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Services\SugeridoPedido;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-28 10:00', 'America/Bogota'));

    $this->jorge = User::factory()->create();
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'AdminBodega', 'guard_name' => 'web']));
    $this->gerente = User::factory()->create();
    $this->gerente->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->proveedor = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900111999', 'nombre_completo' => 'Topwin', 'es_proveedor' => true,
    ]);
    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'PPAL', 'nombre' => 'Bodega', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $this->producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'X-500', 'nombre' => 'Producto X', 'activo' => true, 'desglose_stock' => false,
    ]));

    // Llegan 500 unidades el 15 de octubre.
    $this->llegan = function (): void {
        Carbon::setTestNow(Carbon::parse('2026-10-15 22:00', 'America/Bogota'));
        auth()->forgetGuards();
        $orden = CrearOrdenCompra::run([
            'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
            'tipo' => 'importacion', 'moneda' => 'CNY', 'tasa_cambio' => 520,
            'items' => [[
                'producto_id' => $this->producto->id, 'descripcion' => 'X-500', 'iva_pct' => 0, 'precio_unit' => 10,
                'cantidad' => 500, 'cajas' => 10, 'unidades_por_caja' => 50, 'cbm_caja' => 0.2,
            ]],
        ]);
        AprobarOrdenCompra::run($orden);
        $orden = $orden->fresh('items');
        $this->actingAs($this->jorge)->post('/app/compras/recepcion', [
            'orden_id' => $orden->id,
            'items' => [['orden_item_id' => $orden->items->first()->id, 'cantidad_recibida' => 500, 'clasificacion' => 'apto']],
        ])->assertRedirect()->assertSessionHasNoErrors();
    };
    $this->vende = function (int $cantidad, string $cuando): void {
        InventarioMovimiento::create([
            'producto_id' => $this->producto->id, 'ubicacion_id' => $this->bodega->id, 'tipo' => 'salida_venta',
            'cantidad' => -$cantidad, 'costo_unit' => 1, 'created_at' => Carbon::parse($cuando, 'America/Bogota'),
        ]);
    };
    $this->fila = fn (int $objetivo = 90) => app(SugeridoPedido::class)->handle(90, $objetivo, 'X-500')->first();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reproduce el ejemplo del Word: 300 vendidas en 13 días dan 2.077 para tres meses', function () {
    ($this->llegan)();
    ($this->vende)(300, '2026-10-20 12:00');
    Carbon::setTestNow(Carbon::parse('2026-10-28 10:00', 'America/Bogota'));

    $f = ($this->fila)();

    expect($f['dias_medidos'])->toBe(13)
        ->and($f['vendido'])->toBe(300.0)
        ->and($f['necesita'])->toEqual(2076.9)
        // Le quedan 200 en bodega y el sugerido las descuenta; `necesita` muestra su cifra.
        ->and($f['stock'])->toBe(200.0)
        ->and($f['sugerido'])->toEqual(1900.0)
        ->and($f['cajas'])->toBe(38)
        ->and($f['llego_en'])->toBe('2026-10-15');
});

it('no cuenta lo vendido antes de que llegara', function () {
    ($this->vende)(999, '2026-10-01 12:00');
    ($this->llegan)();
    ($this->vende)(130, '2026-10-20 12:00');
    Carbon::setTestNow(Carbon::parse('2026-10-28 10:00', 'America/Bogota'));

    expect(($this->fila)()['vendido'])->toBe(130.0);
});

it('sin importaciones mide con la ventana elegida', function () {
    ($this->vende)(90, '2026-10-20 12:00');
    Carbon::setTestNow(Carbon::parse('2026-10-28 10:00', 'America/Bogota'));

    $f = ($this->fila)();

    expect($f['llego_en'])->toBeNull()
        ->and($f['dias_medidos'])->toBe(90)
        ->and($f['necesita'])->toEqual(90.0);
});

it('un producto normal que ya alcanza no se pide; uno de línea mantiene su mínimo', function () {
    ($this->llegan)();
    ($this->vende)(10, '2026-10-20 12:00');
    Carbon::setTestNow(Carbon::parse('2026-10-28 10:00', 'America/Bogota'));
    Producto::whereKey($this->producto->id)->update(['stock_minimo' => 1000]);

    expect(($this->fila)()['sugerido'])->toEqual(0.0);

    $this->actingAs($this->gerente)->post("/app/compras/sugerido/{$this->producto->id}/de-linea", ['de_linea' => true])
        ->assertRedirect()->assertSessionHasNoErrors();

    $f = ($this->fila)();
    // Mínimo 1.000 con 490 en bodega: faltan 510, que en cajas de 50 son 550.
    expect($f['de_linea'])->toBeTrue()
        ->and($f['sugerido'])->toEqual(550.0);
});

it('marcar de línea no dispara nada hacia SIIGO', function () {
    Queue::fake();

    $this->actingAs($this->gerente)->post("/app/compras/sugerido/{$this->producto->id}/de-linea", ['de_linea' => true])->assertRedirect();

    Queue::assertNothingPushed();
});

it('por defecto cubre tres meses, no cuatro', function () {
    $this->actingAs($this->gerente)->get('/app/compras/sugerido')->assertOk()
        ->assertInertia(fn ($p) => $p->where('filtros.objetivo', 90));
});
