<?php

/**
 * El reparto por color (Word de Great Baby, sección 1): el tope es el total de la
 * referencia, no cada color, y lo que llega revuelto se clasifica después.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Actions\CrearProductosDeOrden;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Compras\Services\ColoresDeImportacion;
use App\Modules\Compras\Services\EtiquetasDeOrden;
use App\Modules\Compras\Services\LectorOrdenProveedor;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Siigo\Jobs\PushProductoASiigo;
use Illuminate\Support\Facades\Queue;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Queue::fake();

    $this->jorge = User::factory()->create(['name' => 'Jorge']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'AdminBodega', 'guard_name' => 'web']));
    $this->proveedor = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900222777', 'nombre_completo' => 'Topwin', 'es_proveedor' => true,
    ]);
    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'PPAL', 'nombre' => 'Bodega', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $this->linea = fn (string $ref, int $cantidad, ?string $color = null) => [
        'descripcion' => "{$ref} · CASCO", 'iva_pct' => 0, 'precio_unit' => 10, 'color_nombre' => $color,
        'cantidad' => $cantidad, 'cajas' => $cantidad, 'unidades_por_caja' => 1, 'cbm_caja' => 0.01,
    ];
    $this->orden = function (array $lineas, string $tipo = 'importacion'): OrdenCompra {
        auth()->forgetGuards();
        $orden = CrearOrdenCompra::run([
            'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
            'tipo' => $tipo, 'moneda' => $tipo === 'importacion' ? 'CNY' : 'COP', 'tasa_cambio' => $tipo === 'importacion' ? 520 : 1,
            'items' => $lineas,
        ]);
        AprobarOrdenCompra::run($orden);

        return $orden->fresh('items');
    };
    $this->recibir = fn (OrdenCompra $orden, array $filas, array $extra = []) => $this->actingAs($this->jorge)
        ->post('/app/compras/recepcion', ['orden_id' => $orden->id, 'items' => $filas, ...$extra]);
    $this->stockDe = fn (Producto $p, string $color) => (float) InventarioMovimiento::query()
        ->where('variante_id', $p->variantes()->where('color_nombre', $color)->value('id'))->sum('cantidad');
});

it('el caso del casco: cada color sube o baja y no se pierde ninguna unidad', function () {
    $orden = ($this->orden)([
        ($this->linea)('802', 100, 'Rosado'), ($this->linea)('802', 50, 'Verde'), ($this->linea)('802', 50, 'Rojo'),
    ]);
    [$rosado, $verde, $rojo] = $orden->items->all();

    ($this->recibir)($orden, [
        ['orden_item_id' => $rosado->id, 'cantidad_recibida' => 90, 'color_nombre' => 'Rosado'],
        ['orden_item_id' => $verde->id, 'cantidad_recibida' => 60, 'color_nombre' => 'Verde'],
        ['orden_item_id' => $rojo->id, 'cantidad_recibida' => 45, 'color_nombre' => 'Rojo'],
        ['orden_item_id' => $rosado->id, 'cantidad_recibida' => 5, 'color_nombre' => 'Sin identificar'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $p = Producto::where('referencia', '802')->firstOrFail();

    expect((bool) $p->desglose_stock)->toBeTrue()
        ->and(($this->stockDe)($p, 'Rosado'))->toBe(90.0)
        ->and(($this->stockDe)($p, 'Verde'))->toBe(60.0)
        ->and(($this->stockDe)($p, 'Rojo'))->toBe(45.0)
        ->and(($this->stockDe)($p, 'Sin identificar'))->toBe(5.0)
        ->and((float) InventarioMovimiento::where('producto_id', $p->id)->sum('cantidad'))->toBe(200.0);
});

it('una línea agrupada se reparte al contar, aunque la orden no traiga colores', function () {
    $orden = ($this->orden)([($this->linea)('900', 300)]);
    $id = $orden->items->first()->id;

    ($this->recibir)($orden, [
        ['orden_item_id' => $id, 'cantidad_recibida' => 170, 'color_nombre' => 'Azul'],
        ['orden_item_id' => $id, 'cantidad_recibida' => 130, 'color_nombre' => 'Verde'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $p = Producto::where('referencia', '900')->firstOrFail();
    expect(($this->stockDe)($p, 'Azul'))->toBe(170.0)
        ->and(($this->stockDe)($p, 'Verde'))->toBe(130.0);
});

it('lo que quede sin color en un producto con colores entra a Sin identificar', function () {
    $orden = ($this->orden)([($this->linea)('901', 10)]);
    $id = $orden->items->first()->id;

    ($this->recibir)($orden, [
        ['orden_item_id' => $id, 'cantidad_recibida' => 8, 'color_nombre' => 'Azul'],
        ['orden_item_id' => $id, 'cantidad_recibida' => 2],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $p = Producto::where('referencia', '901')->firstOrFail();
    expect(($this->stockDe)($p, 'Sin identificar'))->toBe(2.0)
        ->and((float) InventarioMovimiento::where('producto_id', $p->id)->whereNull('variante_id')->sum('cantidad'))->toBe(0.0);
});

it('contar más que el total pide el motivo y, con él, se guarda', function () {
    $orden = ($this->orden)([($this->linea)('902', 100)]);
    $filas = [['orden_item_id' => $orden->items->first()->id, 'cantidad_recibida' => 105]];

    ($this->recibir)($orden, $filas)->assertSessionHasErrors('recepcion');
    expect(RecepcionCompra::count())->toBe(0);

    ($this->recibir)($orden, $filas, ['motivo_exceso' => 'Vinieron 5 de cortesía'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $p = Producto::where('referencia', '902')->firstOrFail();
    expect((float) InventarioMovimiento::where('producto_id', $p->id)->sum('cantidad'))->toBe(105.0)
        /*
         * El motivo vive en SU columna desde el 20-nov-2026, no pegado al final
         * de `observaciones`. Antes no se podían listar «las recepciones que
         * llegaron con exceso» sin buscar una frase dentro de un texto libre, y
         * editar las observaciones se llevaba la constancia por delante.
         */
        ->and(RecepcionCompra::first()->motivo_exceso)->toContain('Vinieron 5 de cortesía');
});

it('sin colores nada cambia: el producto nuevo sigue siendo uno solo', function () {
    $orden = ($this->orden)([($this->linea)('903', 40)]);

    ($this->recibir)($orden, [['orden_item_id' => $orden->items->first()->id, 'cantidad_recibida' => 40]])
        ->assertRedirect()->assertSessionHasNoErrors();

    $p = Producto::where('referencia', '903')->firstOrFail();
    expect((bool) $p->desglose_stock)->toBeFalse()
        ->and($p->variantes()->count())->toBe(0)
        ->and((float) InventarioMovimiento::where('producto_id', $p->id)->whereNull('variante_id')->sum('cantidad'))->toBe(40.0);
});

it('un producto que ya tiene stock guarda el color en la recepción y su stock sigue siendo uno', function () {
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => '904', 'nombre' => 'Viejo', 'activo' => true, 'desglose_stock' => false,
    ]));
    InventarioMovimiento::create([
        'producto_id' => $p->id, 'ubicacion_id' => $this->bodega->id, 'tipo' => 'ingreso',
        'cantidad' => 7, 'costo_unit' => 1, 'created_at' => now(),
    ]);

    $orden = ($this->orden)([['producto_id' => $p->id, ...($this->linea)('904', 20)]]);
    ($this->recibir)($orden, [['orden_item_id' => $orden->items->first()->id, 'cantidad_recibida' => 20, 'color_nombre' => 'Azul']])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect((bool) $p->fresh()->desglose_stock)->toBeFalse()
        ->and($p->variantes()->count())->toBe(0)
        ->and(RecepcionCompra::first()->items->first()->color_nombre)->toBe('Azul')
        ->and((float) InventarioMovimiento::where('producto_id', $p->id)->sum('cantidad'))->toBe(27.0);
});

it('lo sin identificar pasa a su color sin cambiar el total ni el costo', function () {
    $orden = ($this->orden)([($this->linea)('905', 10)]);
    $id = $orden->items->first()->id;
    ($this->recibir)($orden, [
        ['orden_item_id' => $id, 'cantidad_recibida' => 4, 'color_nombre' => 'Azul'],
        ['orden_item_id' => $id, 'cantidad_recibida' => 6, 'color_nombre' => 'Sin identificar'],
    ])->assertRedirect();

    $rec = RecepcionCompra::first();
    $fila = $rec->items->firstWhere('color_nombre', 'Sin identificar');
    $p = Producto::where('referencia', '905')->firstOrFail();
    $costo = (float) InventarioMovimiento::where('variante_id', $fila->variante_id)->value('costo_unit');

    $this->actingAs($this->jorge)->post("/app/compras/recepcion/{$rec->id}/reclasificar", [
        'item_id' => $fila->id, 'color' => 'Azul', 'cantidad' => 4,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(($this->stockDe)($p, 'Azul'))->toBe(8.0)
        ->and(($this->stockDe)($p, 'Sin identificar'))->toBe(2.0)
        ->and((float) InventarioMovimiento::where('producto_id', $p->id)->sum('cantidad'))->toBe(10.0)
        ->and((float) InventarioMovimiento::where('tipo', 'reclasificacion_entrada')->value('costo_unit'))->toEqual($costo);

    // Sólo quedan 2.
    $this->actingAs($this->jorge)->post("/app/compras/recepcion/{$rec->id}/reclasificar", [
        'item_id' => $fila->id, 'color' => 'Rojo', 'cantidad' => 3,
    ])->assertSessionHasErrors('reclasificar');
});

it('dos filas sin identificar del mismo producto muestran un solo saldo', function () {
    $orden = ($this->orden)([($this->linea)('909', 10), ($this->linea)('909', 10)]);
    [$a, $b] = $orden->items->all();
    ($this->recibir)($orden, [
        ['orden_item_id' => $a->id, 'cantidad_recibida' => 6, 'color_nombre' => 'Azul'],
        ['orden_item_id' => $a->id, 'cantidad_recibida' => 4, 'color_nombre' => 'Sin identificar'],
        ['orden_item_id' => $b->id, 'cantidad_recibida' => 10],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $rec = RecepcionCompra::first();
    $this->actingAs($this->jorge)->get("/app/compras/recepcion/{$rec->id}")->assertOk()
        ->assertInertia(function ($p) {
            $saldos = collect($p->toArray()['props']['recepcion']['items'])->pluck('sin_identificar')->filter()->values()->all();
            expect($saldos)->toEqual([14.0]);
        });
});

it('cada color nace con su código de barras antes de ir a China', function () {
    $orden = ($this->orden)([($this->linea)('906', 10, 'Azul'), ($this->linea)('906', 10, 'Morado claro')]);

    CrearProductosDeOrden::run($orden);
    $orden = $orden->fresh('items.variante', 'items.producto');
    $codigos = $orden->items->pluck('variante.codigo_barras');

    expect(ProductoVariante::count())->toBe(2)
        ->and($codigos->unique())->toHaveCount(2)
        ->and($codigos->first())->toStartWith('906-')
        ->and(collect(app(EtiquetasDeOrden::class)->paraPdf($orden))->pluck('referencia')->all())->toEqual($codigos->all());
});

it('crear colores no manda nada a SIIGO con el push apagado', function () {
    $orden = ($this->orden)([($this->linea)('907', 10, 'Azul')]);

    CrearProductosDeOrden::run($orden);

    Queue::assertNotPushed(PushProductoASiigo::class);
});

it('una compra nacional sigue topada por línea', function () {
    $orden = ($this->orden)([['descripcion' => 'Papel', 'iva_pct' => 0, 'precio_unit' => 10, 'cantidad' => 10]], 'nacional');

    ($this->recibir)($orden, [['orden_item_id' => $orden->items->first()->id, 'cantidad_recibida' => 11]])
        ->assertSessionHasErrors('recepcion');
    expect(RecepcionCompra::count())->toBe(0);
});

it('lee la columna de color del Excel, si la trae', function () {
    $ruta = tempnam(sys_get_temp_dir(), 'oc').'.xlsx';
    $w = new Writer;
    $w->openToFile($ruta);
    $w->addRow(Row::fromValues(['REF', 'DESCRIPCION', 'COLOR', 'CAJAS', 'CTN/CAJA', 'PRECIO', 'CBM']));
    $w->addRow(Row::fromValues(['802', 'CASCO', 'Rosado', 10, 10, 5, 0.1]));
    $w->close();

    $lineas = app(LectorOrdenProveedor::class)->leer($ruta)['lineas'];
    @unlink($ruta);

    expect($lineas[0]['color_nombre'])->toBe('Rosado');
});

it('la pantalla de recibir entrega los datos del reparto', function () {
    $orden = ($this->orden)([($this->linea)('908', 10, 'Azul')]);

    $this->actingAs($this->jorge)->get("/app/compras/recepcion/nueva?oc={$orden->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->where('oc.items.0.referencia', '908')
            ->where('oc.items.0.color_nombre', 'Azul')
            ->has('oc.colores'));
});
