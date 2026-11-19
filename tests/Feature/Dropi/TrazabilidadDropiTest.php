<?php

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Actions\RegistrarDevolucion;
use App\Modules\Dropi\Enums\DestinoDevolucion;
use App\Modules\Dropi\Listeners\DescontarInventarioAlEmpacar;
use App\Modules\Dropi\Models\DropiCargaOperacion;
use App\Modules\Dropi\Models\DropiCargaProducto;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiPedidoItem;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Inventario\Models\CargaInventario;

/*
 * Trazabilidad de lo que hace Dropi en el inventario: cada movimiento del
 * kardex dice de qué pedido/devolución viene, la devolución reingresa también
 * los productos agregados, y el stock inicial de un producto nuevo dice por
 * qué entra (carga CI- con asiento).
 */

beforeEach(function () {
    $this->bodega = InventarioUbicacion::firstOrCreate(['codigo' => 'TRZ-VENTA'], ['nombre' => 'Venta test', 'activa' => true, 'categoria' => 'venta']);
    $this->gerente = \App\Models\User::factory()->create();
    $this->gerente->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Aracely', 'web'));
    $corte = \App\Modules\Dropi\Actions\AsignarCorteACarga::run(\Carbon\CarbonImmutable::now());
    $this->pedido = DropiPedido::create([
        'corte_id' => $corte->id, 'guia' => 'GUIA-TRZ-1', 'dropi_orden_id' => '7001', 'cliente_nombre' => 'Ana',
        'monto_esperado_proveedor' => 1000, 'estado' => 'devolucion_en_camino',
    ]);
});

function trzAgregado(string $ref = 'AGR-1'): Producto
{
    return Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => $ref, 'nombre' => 'Agregado test', 'desglose_stock' => false]));
}

// ───────────────────────── B · devolución ─────────────────────────

// Desde main, el reingreso se ancla al pedido (`'devolucion'` + pedido_id) para que
// un reintento no duplique el ingreso; el motivo vive en la devolución, no en el movimiento.
it('la devolución reingresa los productos agregados y apunta al pedido devuelto', function () {
    $agregado = trzAgregado();
    DropiPedidoItem::create(['pedido_id' => $this->pedido->id, 'producto_id' => $agregado->id, 'variante_id' => null, 'cantidad' => 2, 'precio_proveedor_unit' => 1000]);

    $r = RegistrarDevolucion::run('GUIA-TRZ-1', DestinoDevolucion::Reingreso, $this->gerente->id, 'Cliente no estaba');

    $mov = InventarioMovimiento::where('producto_id', $agregado->id)->whereNull('variante_id')->sole();
    expect($r['movimientos'])->toBe(1)
        ->and((int) $mov->cantidad)->toBe(2)
        ->and($mov->referencia_tipo)->toBe('devolucion')
        ->and($mov->referencia_id)->toBe($this->pedido->id)
        ->and($mov->ubicacion_id)->toBe($this->bodega->id)
        ->and($mov->notas)->toContain('GUIA-TRZ-1')
        ->and($r['devolucion']->notas)->toContain('Cliente no estaba');
});

it('la devolución reingresa también los granulares, una vez por variante', function () {
    $p = Producto::create(['referencia' => 'GRA-1', 'nombre' => 'Granular test', 'desglose_stock' => true]);
    $v = ProductoVariante::create(['producto_id' => $p->id, 'color_codigo' => 'ROJ', 'color_nombre' => 'Rojo', 'codigo_barras' => '7700000099991']);
    DropiPedidoItem::create(['pedido_id' => $this->pedido->id, 'producto_id' => $p->id, 'variante_id' => $v->id, 'cantidad' => 3, 'precio_proveedor_unit' => 1000]);

    $r = RegistrarDevolucion::run('GUIA-TRZ-1', DestinoDevolucion::Reingreso, $this->gerente->id);

    expect($r['movimientos'])->toBe(1)
        ->and((int) InventarioMovimiento::where('variante_id', $v->id)->where('referencia_tipo', 'devolucion')->sum('cantidad'))->toBe(3);
});

it('una baja total no reingresa stock', function () {
    $agregado = trzAgregado();
    DropiPedidoItem::create(['pedido_id' => $this->pedido->id, 'producto_id' => $agregado->id, 'cantidad' => 2, 'precio_proveedor_unit' => 1000]);

    $r = RegistrarDevolucion::run('GUIA-TRZ-1', DestinoDevolucion::BajaTotal, $this->gerente->id);

    expect($r['movimientos'])->toBe(0)->and(InventarioMovimiento::where('producto_id', $agregado->id)->count())->toBe(0);
});

// ───────────────────────── A · kardex ─────────────────────────

it('el kardex dice de qué pedido o devolución viene cada movimiento y enlaza al pedido', function () {
    $agregado = trzAgregado('AGR-KDX');
    InventarioMovimiento::create(['producto_id' => $agregado->id, 'ubicacion_id' => $this->bodega->id, 'tipo' => 'egreso', 'cantidad' => -2,
        'referencia_tipo' => DescontarInventarioAlEmpacar::REFERENCIA_TIPO, 'referencia_id' => $this->pedido->id, 'notas' => 'Empaque']);
    DropiPedidoItem::create(['pedido_id' => $this->pedido->id, 'producto_id' => $agregado->id, 'cantidad' => 2, 'precio_proveedor_unit' => 1000]);
    RegistrarDevolucion::run('GUIA-TRZ-1', DestinoDevolucion::Reingreso, $this->gerente->id);

    $this->actingAs($this->gerente)->get('/app/inventario/kardex?codigo=AGR-KDX')->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('movimientos.0.referencia_label', 'Empaque · guía GUIA-TRZ-1')
            ->where('movimientos.0.referencia_link', "/app/dropi/pedidos/{$this->pedido->id}/editar")
            ->where('movimientos.1.referencia_label', 'Devolución · guía GUIA-TRZ-1')
            ->where('movimientos.1.referencia_link', "/app/dropi/pedidos/{$this->pedido->id}/editar"));
});

// ───────────────────────── C · stock inicial con motivo ─────────────────────────

it('crear un producto con stock pide por qué entra', function () {
    $this->actingAs($this->gerente)->post('/app/catalogo/productos', [
        'referencia' => 'NUE-1', 'nombre' => 'Producto nuevo', 'desglose_stock' => false, 'stock_directo' => 10, 'precio_proveedor' => 5000,
    ])->assertSessionHasErrors(['stock_motivo', 'stock_observacion']);

    expect(Producto::where('referencia', 'NUE-1')->exists())->toBeFalse();
});

it('el stock inicial de la ficha queda como carga CI- con kardex enlazado y asiento', function () {
    $this->actingAs($this->gerente)->post('/app/catalogo/productos', [
        'referencia' => 'NUE-2', 'nombre' => 'Producto nuevo', 'desglose_stock' => false, 'stock_directo' => 10, 'precio_proveedor' => 5000,
        'stock_motivo' => 'saldo_inicial', 'stock_observacion' => 'Conteo de bodega del 18 de noviembre',
    ])->assertSessionHasNoErrors();

    $p = Producto::where('referencia', 'NUE-2')->sole();
    $mov = InventarioMovimiento::where('producto_id', $p->id)->sole();
    $carga = CargaInventario::findOrFail($mov->referencia_id);

    expect($mov->referencia_tipo)->toBe(CargaInventario::class)
        ->and((float) $mov->costo_unit)->toBe(5000.0)
        ->and($carga->motivo->value)->toBe('saldo_inicial')
        ->and($carga->observacion)->toBe('Conteo de bodega del 18 de noviembre')
        ->and($carga->canal)->toBe('ficha')
        ->and((float) $carga->total_valor)->toBe(50000.0);

    $asiento = MovimientoContable::where('origen_type', CargaInventario::class)->where('origen_id', $carga->id)->get();
    expect((float) $asiento->sum('debe'))->toBe(50000.0)->and((float) $asiento->sum('haber'))->toBe(50000.0);
});

it('una compra en la ficha exige el proveedor', function () {
    $this->actingAs($this->gerente)->post('/app/catalogo/productos', [
        'referencia' => 'NUE-3', 'nombre' => 'Producto nuevo', 'desglose_stock' => false, 'stock_directo' => 4,
        'stock_motivo' => 'compra', 'stock_observacion' => 'Factura del proveedor',
    ])->assertSessionHasErrors('stock_tercero_id');
});

it('el stock creado por código queda documentado como inventario inicial', function () {
    $this->bodega->forceFill(['activa' => true])->save();
    $p = Producto::create(['referencia' => 'COD-1', 'nombre' => 'Por código', 'desglose_stock' => false, 'stock_directo' => 3]);

    $mov = InventarioMovimiento::where('producto_id', $p->id)->sole();
    $carga = CargaInventario::findOrFail($mov->referencia_id);
    expect($carga->motivo->value)->toBe('saldo_inicial')
        ->and($carga->canal)->toBe('sistema')
        ->and($carga->observacion)->toContain('COD-1');
});

// ───────────────────────── E · cargas a Dropi en la ficha ─────────────────────────

it('la ficha del producto muestra sus cargas a Dropi', function () {
    $p = trzAgregado('DRP-FICHA');
    $carga = DropiCargaProducto::create(['numero' => 'DC-2026-000099', 'origen' => 'excel', 'modo' => 'reemplazar', 'concepto' => 'Inventario noviembre',
        'bodega_dropi_id' => 10548, 'estado' => DropiCargaProducto::TERMINADO]);
    $carga->operaciones()->create(['fila' => 2, 'ref_excel' => 'DRP-FICHA', 'tipo' => 'actualizar', 'estado' => DropiCargaOperacion::OK, 'dropi_producto_id' => 555, 'procesado_at' => now(),
        'detalle' => ['lineas' => [['variation_id' => null, 'etiqueta' => 'Producto', 'cantidad' => 8, 'antes' => 10, 'movimiento' => 'SALIDA', 'unidades' => 2, 'despues' => 8]]]]);

    $this->actingAs($this->gerente)->get("/app/catalogo/productos/{$p->id}")->assertOk()
        ->assertInertia(fn ($pg) => $pg
            ->has('cargas_dropi', 1)
            ->where('cargas_dropi.0.numero', 'DC-2026-000099')
            ->where('cargas_dropi.0.lineas.0.movimiento', 'SALIDA')
            ->where('cargas_dropi.0.lineas.0.despues', 8));
});
