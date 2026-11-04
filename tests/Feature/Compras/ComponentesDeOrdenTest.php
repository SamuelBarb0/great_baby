<?php

/**
 * Subproductos (Word de Alejandro, sección 7): las filas de empaque o partes con la
 * misma referencia se cuentan al recibir, pero no son unidades para vender.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Actions\CrearProductosDeOrden;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\PasoDeCompra;
use App\Modules\Compras\Services\PreliquidarOrden;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Queue::fake();

    $this->jorge = User::factory()->create(['name' => 'Jorge']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'AdminBodega', 'guard_name' => 'web']));
    $this->proveedor = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900555666',
        'nombre_completo' => 'Yiwu', 'es_proveedor' => true,
    ]);
    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'PPAL', 'nombre' => 'Bodega principal', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    // El caso real de `lacuenta-AND+DROP260803`: la media y sus tres empaques.
    $this->media = function (): OrdenCompra {
        auth()->forgetGuards();
        $linea = fn (array $l) => [
            'iva_pct' => 0, 'descuento_pct' => 0, 'tipo_linea' => 'componente', 'precio_unit' => 0,
            'descripcion' => 'AND2505-889 · MEDIA', 'peso_caja_kg' => 5, ...$l,
            'cantidad' => $l['cajas'] * $l['unidades_por_caja'],
        ];

        $orden = CrearOrdenCompra::run([
            'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
            'tipo' => 'importacion', 'moneda' => 'CNY', 'tasa_cambio' => 520,
            'items' => [
                $linea(['tipo_linea' => 'producto', 'precio_unit' => 7.8, 'cajas' => 12, 'unidades_por_caja' => 1000, 'cbm_caja' => 0.137256]),
                $linea(['cajas' => 2, 'unidades_por_caja' => 6000, 'cbm_caja' => 0.047952]),
                $linea(['cajas' => 8, 'unidades_por_caja' => 3000, 'cbm_caja' => 0.029725]),
                $linea(['cajas' => 1, 'unidades_por_caja' => 12000, 'cbm_caja' => 0.04284]),
            ],
        ]);
        AprobarOrdenCompra::run($orden);

        return $orden->fresh('items');
    };

    $this->recibir = fn (OrdenCompra $orden) => $this->actingAs($this->jorge)->post('/app/compras/recepcion', [
        'orden_id' => $orden->id,
        'items' => $orden->items->map(fn ($i) => [
            'orden_item_id' => $i->id, 'cantidad_recibida' => (float) $i->cantidad, 'clasificacion' => 'apto',
        ])->all(),
    ]);
});

it('liga cada componente al producto de su misma referencia y no le deja producto propio', function () {
    $orden = ($this->media)();
    $principal = $orden->items->first();

    $componentes = $orden->items->filter->esComponente();
    expect($componentes)->toHaveCount(3);
    $componentes->each(function ($c) use ($principal) {
        expect($c->componente_de_item_id)->toBe($principal->id)
            ->and($c->producto_id)->toBeNull();
    });
});

it('no crea productos para los componentes', function () {
    $orden = ($this->media)();

    expect(CrearProductosDeOrden::run($orden))->toBe(1)
        ->and(Producto::where('referencia', 'AND2505-889')->count())->toBe(1)
        ->and($orden->fresh('items')->items->filter->esComponente()->whereNotNull('producto_id'))->toHaveCount(0);
});

it('sube al inventario 12.000 unidades y no 60.000, aunque se cuenten las cuatro filas', function () {
    $orden = ($this->media)();

    ($this->recibir)($orden)->assertRedirect()->assertSessionHasNoErrors();

    $producto = Producto::where('referencia', 'AND2505-889')->firstOrFail();
    expect((float) InventarioMovimiento::where('producto_id', $producto->id)->sum('cantidad'))->toBe(12000.0)
        ->and((float) $orden->fresh('items')->items->sum('cantidad_recibida'))->toBe(60000.0);
});

it('suma el costo de los componentes al producto sin cambiar el total del contenedor', function () {
    $orden = ($this->media)();
    $pre = app(PreliquidarOrden::class)->handle($orden);

    $principal = collect($pre['lineas'])->firstWhere('es_componente', false);
    $componentes = collect($pre['lineas'])->where('es_componente', true);
    $delEmpaque = $componentes->sum('costo_total') / 12000;

    expect($delEmpaque)->toBeGreaterThan(0)
        ->and($principal['costo_con_componentes'])->toEqualWithDelta($principal['unidad']['costo'] + $delEmpaque, 0.01)
        ->and($pre['totales']['costo_total'])->toEqualWithDelta($principal['costo_total'] + $componentes->sum('costo_total'), 0.05);
});

it('entra al kardex con el costo estimado que ya incluye el empaque', function () {
    $orden = ($this->media)();
    $esperado = collect(app(PreliquidarOrden::class)->handle($orden)['lineas'])->firstWhere('es_componente', false)['costo_con_componentes'];

    ($this->recibir)($orden)->assertRedirect();

    $producto = Producto::where('referencia', 'AND2505-889')->firstOrFail();
    expect((float) InventarioMovimiento::where('producto_id', $producto->id)->first()->costo_unit)->toEqualWithDelta($esperado, 0.01);
});

it('avisa del componente que no tiene un producto con su referencia', function () {
    auth()->forgetGuards();
    $orden = CrearOrdenCompra::run([
        'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
        'tipo' => 'importacion', 'moneda' => 'CNY', 'tasa_cambio' => 520,
        'items' => [[
            'descripcion' => 'ZZ-100 · CAJA', 'tipo_linea' => 'componente', 'iva_pct' => 0,
            'cantidad' => 10, 'cajas' => 1, 'unidades_por_caja' => 10, 'cbm_caja' => 0.1, 'precio_unit' => 0,
        ]],
    ]);

    expect(implode(' ', app(PreliquidarOrden::class)->handle($orden)['avisos']))->toContain('1 componente(s) sin un producto');
});

it('no pide crear productos para los componentes en el paso a paso', function () {
    auth()->forgetGuards();
    $orden = CrearOrdenCompra::run([
        'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
        'tipo' => 'importacion', 'moneda' => 'CNY', 'tasa_cambio' => 520,
        'items' => [
            ['descripcion' => 'AB-1 · SILLA', 'iva_pct' => 0, 'cantidad' => 10, 'cajas' => 1, 'unidades_por_caja' => 10, 'cbm_caja' => 0.1, 'precio_unit' => 5],
            ['descripcion' => 'AB-1 · ESPUMA', 'tipo_linea' => 'componente', 'iva_pct' => 0, 'cantidad' => 10, 'cajas' => 1, 'unidades_por_caja' => 10, 'cbm_caja' => 0.1, 'precio_unit' => 0],
        ],
    ]);

    expect(app(PasoDeCompra::class)->deOrden($orden->fresh('items'), $this->jorge)['titulo'])->toContain('Cree los 1 productos');
});

it('no cambia el total recibido: los componentes en $0 no suman plata', function () {
    $orden = ($this->media)();

    ($this->recibir)($orden)->assertRedirect();

    // 12.000 × 7,80 × 520: lo mismo que se le debe a China, con o sin empaque.
    expect((float) $orden->fresh()->recepciones()->first()->total_recibido)->toEqualWithDelta(48672000.0, 0.01);
});
