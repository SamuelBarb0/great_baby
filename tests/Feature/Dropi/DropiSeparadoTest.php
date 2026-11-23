<?php

/**
 * Dropi aparte del ERP (23-nov-2026): «legalmente es la misma empresa pero
 * internamente es otra — juntos pero no revueltos».
 *
 *  · Dropi crea y ve sus propios clientes y proveedores; un NIT que ya tenía
 *    el ERP se comparte, no se duplica, y el ERP no ve los que son sólo de Dropi.
 *  · Las compras de Dropi se cargan en masa desde Excel, como Dropi.
 *  · La mercancía de Great Baby pasa a Dropi como traslado (no compra): sale de
 *    la bodega del ERP, entra a la de Dropi al mismo costo y cada libro cuadra
 *    por su lado contra la cuenta interna.
 *  · El ERP muestra sólo Great Baby si nadie elige; el consolidado, a un clic.
 *  · La factura de Dropi se ve dentro de Dropi.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Contabilidad\Support\FiltroEjes;
use App\Modules\Dropi\Actions\RegistrarIngresoErpDesdeCarga;
use App\Modules\Dropi\Actions\RegistrarTerceroDropi;
use App\Modules\Dropi\Models\DropiCargaProducto as Carga;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\ImportadorOrdenesCompraDropi;
use App\Modules\Inventario\Enums\MotivoCargaInventario;
use App\Support\Reglas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Reglas::limpiarRequestCache();
    $this->user = App\Models\User::factory()->create();
    $this->bodegaGb = InventarioUbicacion::create(['codigo' => 'SEP-GB', 'nombre' => 'Bodega Great Baby', 'activa' => true, 'categoria' => 'venta']);
    $this->bodegaDropi = InventarioUbicacion::create(['codigo' => 'SEP-DR', 'nombre' => 'Bodega E-commerce', 'activa' => true, 'categoria' => 'venta']);
    Reglas::set('compras.ubicacion_ecommerce', $this->bodegaDropi->id);
});

// ───────────────────────── terceros ─────────────────────────

it('Dropi crea su proveedor sólo en Dropi, y un NIT del ERP se comparte sin duplicarse', function () {
    $nuevo = RegistrarTerceroDropi::run(['nombre' => 'Textiles Uno', 'tipo_documento' => 'NIT', 'numero_documento' => '900111001', 'es_proveedor' => true]);
    expect($nuevo['ya_existia'])->toBeFalse()
        ->and($nuevo['contacto']->en_dropi)->toBeTrue()
        ->and($nuevo['contacto']->en_erp)->toBeFalse();

    $erp = Contacto::create(['tipo_documento' => 'NIT', 'numero_documento' => '900111002', 'nombre_completo' => 'Del ERP', 'es_cliente' => true, 'activo' => true]);
    $compartido = RegistrarTerceroDropi::run(['nombre' => 'Otro nombre', 'tipo_documento' => 'NIT', 'numero_documento' => '900111002', 'es_proveedor' => true]);

    expect($compartido['ya_existia'])->toBeTrue()
        ->and($compartido['contacto']->id)->toBe($erp->id)
        ->and(Contacto::where('numero_documento', '900111002')->count())->toBe(1)
        ->and($erp->fresh()->only(['en_erp', 'en_dropi', 'es_cliente', 'es_proveedor', 'nombre_completo']))
        ->toBe(['en_erp' => true, 'en_dropi' => true, 'es_cliente' => true, 'es_proveedor' => true, 'nombre_completo' => 'Del ERP']);
});

it('el ERP no ve los terceros que son sólo de Dropi; la carga de Dropi sí', function () {
    RegistrarTerceroDropi::run(['nombre' => 'Solo Dropi SAS', 'tipo_documento' => 'NIT', 'numero_documento' => '900222001', 'es_proveedor' => true]);
    Contacto::create(['tipo_documento' => 'NIT', 'numero_documento' => '900222002', 'nombre_completo' => 'Solo ERP SAS', 'es_proveedor' => true, 'activo' => true]);
    $this->actingAs(App\Models\User::factory()->create()->assignRole(Spatie\Permission\Models\Role::findOrCreate('Aracely')));

    $erp = collect($this->getJson('/app/api/contactos/buscar?q=SAS')->json())->pluck('nombre');
    $dropi = collect($this->getJson('/app/api/contactos/buscar?q=SAS&lado=dropi')->json())->pluck('nombre');

    expect($erp->implode('|'))->toContain('Solo ERP')->not->toContain('Solo Dropi')
        ->and($dropi->implode('|'))->toContain('Solo Dropi')->not->toContain('Solo ERP');
});

// ───────────────────────── compras desde Excel ─────────────────────────

function sepExcel(array $filas): string
{
    $ruta = tempnam(sys_get_temp_dir(), 'ocd').'.xlsx';
    $w = new Writer();
    $w->openToFile($ruta);
    $w->addRow(Row::fromValues(ImportadorOrdenesCompraDropi::COLUMNAS));
    foreach ($filas as $f) {
        $w->addRow(Row::fromValues(array_map(fn ($c) => $f[$c] ?? '', ImportadorOrdenesCompraDropi::COLUMNAS)));
    }
    $w->close();

    return $ruta;
}

it('importa compras de Dropi desde Excel: una orden por referencia, como Dropi y en borrador; la mala no entra y dice la fila', function () {
    $this->actingAs($this->user);
    Producto::create(['referencia' => 'SEP-A', 'nombre' => 'Body', 'desglose_stock' => false]);
    Producto::create(['referencia' => 'SEP-B', 'nombre' => 'Gorro', 'desglose_stock' => false]);

    $r = app(ImportadorOrdenesCompraDropi::class)->importar(sepExcel([
        ['referencia_oc' => 'C-1', 'proveedor_nit' => '900333001', 'proveedor_nombre' => 'Confecciones Uno', 'producto_referencia' => 'SEP-A', 'cantidad' => 10, 'costo_unitario' => 5000],
        ['referencia_oc' => 'C-1', 'proveedor_nit' => '900333001', 'producto_referencia' => 'SEP-B', 'cantidad' => 4, 'costo_unitario' => '2.500', 'iva_pct' => 0],
        ['referencia_oc' => 'C-2', 'proveedor_nit' => '900333001', 'producto_referencia' => 'NO-EXISTE', 'cantidad' => 1, 'costo_unitario' => 100],
        ['referencia_oc' => 'C-3', 'proveedor_nit' => '900333999', 'producto_referencia' => 'SEP-A', 'cantidad' => 1, 'costo_unitario' => 100],
    ]));

    expect($r['ok'])->toBe(1)->and($r['errores'])->toHaveCount(2)
        ->and($r['errores'][0])->toContain('C-2')->toContain('fila 4')->toContain('NO-EXISTE')
        ->and($r['errores'][1])->toContain('C-3')->toContain('no existe');

    $oc = OrdenCompra::where('numero', $r['ordenes'][0])->with('items', 'proveedor')->sole();
    expect($oc->linea_negocio)->toBe(LineaNegocio::Dropi)
        ->and($oc->estado->value)->toBe('borrador')
        ->and($oc->bodega_id)->toBe($this->bodegaDropi->id)
        ->and($oc->items)->toHaveCount(2)
        ->and((float) $oc->items->firstWhere('cantidad', 4)->precio_unit)->toBe(2500.0)
        ->and($oc->proveedor->en_dropi)->toBeTrue()
        ->and($oc->proveedor->en_erp)->toBeFalse();
});

// ───────────────────────── traslado desde Great Baby ─────────────────────────

function sepCargaTraslado(array $filas, int $origen, int $destino, int $userId): Carga
{
    return Carga::create(['numero' => Carga::siguienteNumero(), 'origen' => 'excel', 'modo' => 'sumar', 'concepto' => 'Traslado prueba', 'bodega_dropi_id' => 1,
        'estado' => Carga::BORRADOR, 'user_id' => $userId, 'filas_excel' => $filas,
        'ingreso_erp' => ['activo' => true, 'motivo' => 'traslado_great_baby', 'observacion' => 'Mercancía de Great Baby para Dropi',
            'ubicacion_id' => $destino, 'origen_ubicacion_id' => $origen]]);
}

it('el traslado desde Great Baby saca de su bodega, entra a Dropi al mismo costo y cada libro cuadra contra la cuenta interna', function () {
    $p = Producto::create(['referencia' => 'SEP-T', 'nombre' => 'Mordedor', 'desglose_stock' => false, 'precio_proveedor' => 1]);
    InventarioMovimiento::create(['producto_id' => $p->id, 'ubicacion_id' => $this->bodegaGb->id, 'tipo' => 'entrada_compra', 'cantidad' => 30, 'costo_unit' => 8000]);

    $carga = sepCargaTraslado([['fila' => 2, 'ref' => 'SEP-T', 'cantidad' => 12, 'costo' => 99999, 'nombre' => 'Mordedor']], $this->bodegaGb->id, $this->bodegaDropi->id, $this->user->id);
    $res = app(RegistrarIngresoErpDesdeCarga::class)->handle($carga);
    $ci = $res['carga_inventario'];

    $stock = app(App\Modules\Inventario\Services\StockService::class);
    expect($stock->saldoFisicoProducto($p->id, $this->bodegaGb->id))->toBe(18)
        ->and($stock->saldoFisicoProducto($p->id, $this->bodegaDropi->id))->toBe(12)
        // al costo de Great Baby, no al del Excel
        ->and((float) $ci->total_valor)->toBe(96000.0)
        ->and($ci->motivo)->toBe(MotivoCargaInventario::TrasladoGreatBaby)
        ->and(Producto::where('referencia', 'SEP-T')->count())->toBe(1);

    $l = MovimientoContable::where('origen_type', $ci::class)->where('origen_id', $ci->id)->get();
    $dropi = $l->filter(fn ($x) => $x->linea_negocio === LineaNegocio::Dropi);
    $gb = $l->filter(fn ($x) => $x->linea_negocio === LineaNegocio::GreatBaby);
    $interna = (string) setting('contable.cta_dropi_cuenta_interna');

    expect(round((float) $dropi->sum('debe'), 2))->toBe(round((float) $dropi->sum('haber'), 2))
        ->and(round((float) $gb->sum('debe'), 2))->toBe(round((float) $gb->sum('haber'), 2))
        ->and((float) $dropi->firstWhere('cuenta_puc', $interna)->haber)->toBe(96000.0)
        ->and((float) $gb->firstWhere('cuenta_puc', $interna)->debe)->toBe(96000.0)
        ->and((float) $gb->where('cuenta_puc', '!=', $interna)->sum('haber'))->toBe(96000.0)
        ->and($l->where('cuenta_puc', $interna)->every(fn ($x) => $x->destino_fiscal === DestinoFiscal::Interno))->toBeTrue()
        // en el consolidado la cuenta interna queda en cero
        ->and((float) $l->where('cuenta_puc', $interna)->sum('debe') - (float) $l->where('cuenta_puc', $interna)->sum('haber'))->toBe(0.0);

    // Correrlo otra vez no mueve nada de nuevo.
    app(RegistrarIngresoErpDesdeCarga::class)->handle($carga->fresh());
    expect($stock->saldoFisicoProducto($p->id, $this->bodegaGb->id))->toBe(18);
});

it('sin stock suficiente en Great Baby el traslado no mueve nada', function () {
    $p = Producto::create(['referencia' => 'SEP-T2', 'nombre' => 'Sonajero', 'desglose_stock' => false]);
    $q = Producto::create(['referencia' => 'SEP-T3', 'nombre' => 'Babero', 'desglose_stock' => false]);
    InventarioMovimiento::create(['producto_id' => $p->id, 'ubicacion_id' => $this->bodegaGb->id, 'tipo' => 'entrada_compra', 'cantidad' => 50, 'costo_unit' => 1000]);
    InventarioMovimiento::create(['producto_id' => $q->id, 'ubicacion_id' => $this->bodegaGb->id, 'tipo' => 'entrada_compra', 'cantidad' => 2, 'costo_unit' => 1000]);

    $carga = sepCargaTraslado([
        ['fila' => 2, 'ref' => 'SEP-T2', 'cantidad' => 5, 'costo' => null, 'nombre' => ''],
        ['fila' => 3, 'ref' => 'SEP-T3', 'cantidad' => 9, 'costo' => null, 'nombre' => ''],
    ], $this->bodegaGb->id, $this->bodegaDropi->id, $this->user->id);

    expect(fn () => app(RegistrarIngresoErpDesdeCarga::class)->handle($carga))->toThrow(RuntimeException::class, 'hay 2');
    expect(InventarioMovimiento::where('notas', 'like', '%Traslado desde Great Baby%')->count())->toBe(0)
        ->and(MovimientoContable::where('origen_type', App\Modules\Inventario\Models\CargaInventario::class)->count())->toBe(0);
});

it('el traslado es sólo de Dropi: el ERP no lo ofrece ni lo acepta', function () {
    expect(collect(MotivoCargaInventario::opciones())->pluck('value'))->not->toContain('traslado_great_baby')
        ->and(collect(MotivoCargaInventario::opciones(dropi: true))->pluck('value'))->toContain('traslado_great_baby');
});

// ───────────────────────── el ERP va aparte ─────────────────────────

it('el ERP muestra sólo Great Baby si nadie elige; «todas» es el consolidado', function () {
    expect(FiltroEjes::deRequest(Request::create('/x'))->linea)->toBe('great_baby')
        ->and(FiltroEjes::deRequest(Request::create('/x', 'GET', ['linea' => 'todas']))->linea)->toBe('todas')
        ->and(FiltroEjes::deRequest(Request::create('/x', 'GET', ['linea' => 'dropi']))->linea)->toBe('dropi');
});

it('la factura de Dropi se abre dentro de Dropi; una de Great Baby no', function () {
    $cliente = Contacto::create(['tipo_documento' => 'CC', 'numero_documento' => '1000999', 'nombre_completo' => 'Ana', 'es_cliente' => true, 'activo' => true]);
    $hacer = fn (string $linea) => DB::table('facturas_venta')->insertGetId([
        'numero' => 'SEP-'.$linea, 'contacto_id' => $cliente->id, 'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'estado' => 'pendiente', 'tipo' => 'venta', 'subtotal' => 100, 'descuento' => 0, 'impuestos' => 19, 'total' => 119, 'retenciones' => 0, 'saldo' => 119,
        'linea_negocio' => $linea, 'destino_fiscal' => 'siigo', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $fd = $hacer('dropi');
    $fg = $hacer('great_baby');
    $this->actingAs(App\Models\User::factory()->create()->assignRole(Spatie\Permission\Models\Role::findOrCreate('Aracely')));

    $this->get('/admin/factura-dropi?factura='.$fd)->assertOk()->assertSee('SEP-dropi')->assertDontSee('/app/facturas/');
    $this->get('/admin/factura-dropi?factura='.$fg)->assertNotFound();
});

it('el panel de Dropi no tiene botones ni menús que lleven al ERP', function () {
    $this->actingAs(App\Models\User::factory()->create()->assignRole(Spatie\Permission\Models\Role::findOrCreate('Aracely')));

    $html = $this->get('/admin/compras-ordenes-dropi')->assertOk()->getContent();
    expect($html)->not->toContain('Abrir en el ERP')
        ->not->toContain('/app/catalogo/productos')
        ->toContain('Importar desde Excel');

    $this->get('/admin/catalogo-productos')->assertOk();
    $this->get('/admin/terceros-dropi')->assertOk()->assertSee('Clientes y proveedores');
});

// ───────────────────────── productos conectados ─────────────────────────

it('la compra no inventa productos: si la referencia no existe, pide crearla primero', function () {
    $carga = Carga::create(['numero' => Carga::siguienteNumero(), 'origen' => 'excel', 'modo' => 'sumar', 'concepto' => 'Compra prueba', 'bodega_dropi_id' => 1,
        'estado' => Carga::BORRADOR, 'user_id' => $this->user->id,
        'filas_excel' => [['fila' => 2, 'ref' => 'NO-CREADO-1', 'cantidad' => 5, 'costo' => 1000, 'nombre' => 'Nuevo']],
        'ingreso_erp' => ['activo' => true, 'motivo' => 'compra', 'observacion' => 'Compra de prueba',
            'tercero_id' => Contacto::create(['numero_documento' => '900444001', 'nombre_completo' => 'Prov', 'es_proveedor' => true, 'activo' => true])->id,
            'ubicacion_id' => $this->bodegaDropi->id]]);

    expect(fn () => app(RegistrarIngresoErpDesdeCarga::class)->handle($carga))->toThrow(RuntimeException::class, 'créela primero');
    expect(Producto::where('referencia', 'NO-CREADO-1')->exists())->toBeFalse();
});

it('«Crear en Dropi» abre la carga con el producto del ERP, y uno ya conectado no se repite', function () {
    $this->actingAs($this->user);
    $p = Producto::create(['referencia' => 'ERP-SOLO-1', 'nombre' => 'Cuna portátil', 'desglose_stock' => false]);

    $resp = app(App\Http\Controllers\App\DropiCargaProductosController::class)->desdeErp($p);
    $carga = Carga::latest('id')->first();
    $op = $carga->operaciones()->sole();

    expect($resp->getTargetUrl())->toContain("/app/dropi/carga-productos/{$carga->id}")
        ->and($op->tipo)->toBe(App\Modules\Dropi\Models\DropiCargaOperacion::CREAR)
        ->and($op->ref_excel)->toBe('ERP-SOLO-1')
        ->and($op->detalle['sku'])->toBe('ERP-SOLO-1');

    $p->forceFill(['dropi_sku' => '555'])->save();
    expect(fn () => app(App\Http\Controllers\App\DropiCargaProductosController::class)->desdeErp($p->fresh()))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});
