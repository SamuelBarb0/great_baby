<?php

/**
 * Tarjeta Dropi · 9. Facturación Dropi → SIIGO (decidido el 2026-10-20):
 * una factura por pedido, al despachar, a consumidor final, por el valor de
 * proveedor con IVA incluido; el pago de Dropi la cierra; lo que cobra Dropi
 * (sanciones, fletes) es gasto con Dropi como tercero.
 */

use App\Jobs\ReintentarEmisionDian;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Catalogo\Models\Impuesto;
use App\Modules\Dropi\Actions\ConciliarWalletDropi;
use App\Modules\Dropi\Actions\FacturarPedidoDropi;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PagoWalletDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiRemision;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Dropi\Services\MapearMovimientoWalletDropi;
use App\Support\Reglas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Queue::fake();
    Reglas::set('dropi.facturacion_automatica', true);
    // 7-nov · lo de Dropi lo factura Great Baby con su propia resolución.
    $this->greatBaby = App\Models\EmpresaConfig::current();

    $this->user = App\Models\User::factory()->create();
    $bodega = InventarioUbicacion::create(['codigo' => 'BOD-F', 'nombre' => 'F', 'activa' => true, 'categoria' => 'venta']);
    $iva = Impuesto::create(['codigo' => 'IVA19', 'nombre' => 'IVA 19%', 'tipo' => 'iva', 'porcentaje' => 19, 'activo' => true]);
    $p = Producto::create(['referencia' => 'COCHE-01', 'nombre' => 'Coche', 'desglose_stock' => true, 'impuesto_id' => $iva->id]);
    $this->variante = ProductoVariante::create([
        'producto_id' => $p->id, 'color_codigo' => 'AZU', 'color_nombre' => 'Azul', 'codigo_barras' => '7700000000999',
    ]);
    VincularProductoDropi::run('DV-555', $this->variante->id);
    InventarioMovimiento::create([
        'variante_id' => $this->variante->id, 'ubicacion_id' => $bodega->id,
        'tipo' => 'entrada_compra', 'cantidad' => 20, 'costo_unit' => 15000,
    ]);
});

/** Pedido de 2 unidades a $59.500 c/u de proveedor (con IVA) = $119.000. */
function facPedido(string $guia, string $sku = 'DV-555'): DropiPedido
{
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: '9'.substr(md5($guia), 0, 6), transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 119000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO($sku, 2, 59500, 'Coche', 'Azul')],
        creadoAt: CarbonImmutable::now(),
    )]);

    return DropiPedido::where('guia', $guia)->firstOrFail();
}

function facDespachar(DropiPedido $p, ?int $userId): DropiPedido
{
    $p->transicionar(EstadoPedidoDropi::Despachado, 'api', $userId);

    return $p->fresh();
}

function pagoDropi(DropiPedido $p, int $id, float $monto, string $codigo = '1006'): PagoWalletDTO
{
    return MapearMovimientoWalletDropi::map([
        'id' => $id, 'type' => $monto < 0 ? 'SALIDA' : 'ENTRADA', 'amount' => abs($monto),
        'identification_code' => $codigo, 'order_id' => $p->dropi_orden_id,
        'created_at' => now()->toDateTimeString(), 'description' => "PAGO GUIA: *{$p->guia}*",
    ]);
}

it('al despachar: remisión 1:1 y factura a consumidor final por el valor de proveedor, IVA incluido', function () {
    $p = facDespachar(facPedido('F-1'), $this->user->id);

    $remision = DropiRemision::where('pedido_id', $p->id)->sole();
    $f = FacturaVenta::with(['items', 'contacto'])->findOrFail($p->factura_venta_id);

    expect((float) $remision->valor_proveedor)->toBe(119000.0)
        ->and($f->contacto->numero_documento)->toBe(FacturarPedidoDropi::DOC_CONSUMIDOR_FINAL)
        ->and((float) $f->total)->toBe(119000.0)
        ->and((float) $f->subtotal)->toBe(100000.0)
        ->and((float) $f->impuestos)->toBe(19000.0)
        ->and($f->items)->toHaveCount(1)
        ->and((float) $f->items[0]->precio_unit)->toBe(50000.0)
        ->and((float) $f->items[0]->impuesto_pct)->toBe(19.0)
        ->and($f->items[0]->variante_id)->toBe($this->variante->id)
        ->and($f->origen_type)->toBe(DropiPedido::class)
        // El asiento de la venta: la CxC por el total. La cuenta se lee de la
        // regla y no se escribe acá: cuando pasó de 1305 a 13050501 esta
        // prueba se cayó aunque el comportamiento era correcto.
        ->and((float) MovimientoContable::where('origen_type', FacturaVenta::class)->where('origen_id', $f->id)
            ->where('cuenta_puc', (string) setting('contable.cta_cxc_default'))->sum('debe'))->toBe(119000.0);
});

it('con SIIGO activo y la resolución de Dropi puesta, factura Great Baby, nace electrónica y se encola', function () {
    App\Modules\Siigo\Models\SiigoConfig::current()->forceFill(['activo' => true, 'tipo_documento_id' => 100, 'tipo_documento_dropi_id' => 200])->save();

    $p = facDespachar(facPedido('F-2'), $this->user->id);

    $f = FacturaVenta::find($p->factura_venta_id);
    expect($f->es_electronica)->toBeTrue()
        ->and($f->empresa_id)->toBe($this->greatBaby->id)
        ->and($f->linea_negocio)->toBe(App\Modules\Contabilidad\Enums\LineaNegocio::Dropi)
        // Consecutivo propio: no gasta el rango de Great Baby.
        ->and($f->numero)->toStartWith('DRP-');
    Queue::assertPushed(ReintentarEmisionDian::class);
});

it('sin la resolución de Dropi la factura se queda en el ERP: no sale con la de Great Baby', function () {
    App\Modules\Siigo\Models\SiigoConfig::current()->forceFill(['activo' => true, 'tipo_documento_id' => 100, 'tipo_documento_dropi_id' => null])->save();

    $p = facDespachar(facPedido('F-8'), $this->user->id);

    $f = FacturaVenta::find($p->factura_venta_id);
    expect($f)->not->toBeNull()
        ->and($f->es_electronica)->toBeFalse();
    Queue::assertNotPushed(ReintentarEmisionDian::class);
});

it('si la regla apunta a una razón social apagada no factura con la de Great Baby', function () {
    Reglas::set('dropi.empresa', 'ecommerce');
    App\Models\EmpresaConfig::porCodigo('ecommerce')->update(['activa' => false]);
    // Aunque el SIIGO de Great Baby esté prendido.
    App\Modules\Siigo\Models\SiigoConfig::current()->forceFill(['activo' => true])->save();

    $p = facDespachar(facPedido('F-9'), $this->user->id);

    expect($p->factura_venta_id)->toBeNull()
        ->and(FacturaVenta::count())->toBe(0);
});

it('con la regla apagada o antes de la fecha de corte no factura', function () {
    Reglas::set('dropi.facturacion_automatica', false);
    $p = facDespachar(facPedido('F-3'), $this->user->id);
    expect($p->factura_venta_id)->toBeNull();

    Reglas::set('dropi.facturacion_automatica', true);
    Reglas::set('dropi.facturar_desde', now()->addDay()->toDateString());
    $p2 = facDespachar(facPedido('F-4'), $this->user->id);
    expect($p2->factura_venta_id)->toBeNull()
        ->and(FacturaVenta::count())->toBe(0);
});

it('despachar dos veces no factura dos veces', function () {
    $p = facDespachar(facPedido('F-5'), $this->user->id);
    app(FacturarPedidoDropi::class)->handle($p);

    expect(FacturaVenta::count())->toBe(1)
        ->and(DropiRemision::where('pedido_id', $p->id)->count())->toBe(1);
});

it('un pedido sin productos vinculados se despacha igual: queda la remisión, sin factura', function () {
    $p = facDespachar(facPedido('F-6', 'DV-NOVINCULADO'), $this->user->id);

    // La DIAN pide el producto: sin vínculo no hay factura, pero la remisión
    // sí documenta lo que salió.
    expect($p->estado)->toBe(EstadoPedidoDropi::Despachado)
        ->and($p->factura_venta_id)->toBeNull()
        ->and(DropiRemision::where('pedido_id', $p->id)->exists())->toBeTrue();
});

it('el pago de Dropi cierra la factura con un solo crédito al 1305, y el reverso la reabre', function () {
    $p = facDespachar(facPedido('F-7'), $this->user->id);
    $p->transicionar(EstadoPedidoDropi::Entregado, 'api');

    app(ConciliarWalletDropi::class)->conciliar([pagoDropi($p, 7001, 119000)]);

    $f = FacturaVenta::find($p->factura_venta_id);
    $pago = PagoVenta::sole();
    expect($pago->medio_pago)->toBe('wallet_dropi')
        ->and($f->saldo)->toEqual(0)
        ->and($f->estado->value)->toBe('pagada');

    // La CxC se acredita una sola vez (el recibo), no también desde la wallet.
    $creditoCxC = (float) MovimientoContable::where('cuenta_puc', (string) setting('contable.cta_cxc_default'))->sum('haber');
    expect($creditoCxC)->toBe(119000.0)
        ->and((float) MovimientoContable::where('cuenta_puc', '111015')->sum('debe'))->toBe(119000.0);

    // Dropi corrige el estado de la guía y reversa el pago.
    app(ConciliarWalletDropi::class)->conciliar([pagoDropi($p, 7002, -119000, '1009')]);
    expect(PagoVenta::count())->toBe(0)
        ->and((float) FacturaVenta::find($f->id)->saldo)->toBe(119000.0);
});

it('9.2 · una sanción de Dropi (salida) es gasto 5395 con Dropi como tercero, no ingreso', function () {
    Reglas::set('dropi.nit_tercero', '901.111.222-3');

    DropiWalletMovimiento::create([
        'dropi_movimiento_id' => 'web:9001', 'fecha' => now()->toDateString(),
        'tipo' => 'indemnizacion', 'monto' => -15000, 'categoria' => '1052 SALIDA POR INDEMNIZACION ORDEN',
    ]);

    $lineas = MovimientoContable::where('origen_type', DropiWalletMovimiento::class)->get();
    $gasto = $lineas->firstWhere('cuenta_puc', '5395');
    expect($gasto)->not->toBeNull()
        ->and((float) $gasto->debe)->toBe(15000.0)
        ->and($lineas->firstWhere('cuenta_puc', '4295'))->toBeNull()
        ->and(App\Models\Contacto::find($gasto->tercero_id)?->numero_documento)->toBe('9011112223');
});

it('el cierre del corte ya no factura por vendedor (no duplica la venta)', function () {
    $corte = App\Modules\Dropi\Models\DropiCorte::create(['fecha' => now()->toDateString(), 'numero' => 2]);
    $p = facPedido('F-8');
    DB::table('dropi_pedidos')->where('id', $p->id)->update([
        'corte_id' => $corte->id, 'requiere_factura_b2b' => true, 'vendedor_identificacion' => '900123456',
    ]);
    $p = facDespachar($p->fresh(), $this->user->id);

    App\Modules\Dropi\Actions\CerrarCorte::run($corte->id, $this->user->id);

    expect(FacturaVenta::count())->toBe(1)
        ->and(FacturaVenta::first()->id)->toBe($p->factura_venta_id);
});

// ---------- 4.6 · escanear guía → facturar ----------

function facAracely(): App\Models\User
{
    $u = App\Models\User::factory()->create();
    $u->assignRole(Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    return $u;
}

it('4.6 · despachar escaneando la guía devuelve la factura que salió', function () {
    $p = facPedido('E-1');
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->user->id);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->user->id);

    $r = $this->actingAs(facAracely())->postJson('/dropi/escaner/despachar', ['pedido_id' => $p->id, 'guia' => 'E-1'])
        ->assertOk()->json();

    // Decía 'interna' porque la factura nacía no electrónica cuando SIIGO
    // estaba apagado. Desde el 2026-11-05 el destino manda sobre el estado de
    // la integración: lo de Dropi se declara, así que queda encolada.
    expect($r['factura']['numero'])->toBe(FacturaVenta::find($p->fresh()->factura_venta_id)->numero)
        ->and($r['factura']['estado'])->toBe('enviando_siigo');
});

it('4.6 · con la facturación apagada el escáner dice por qué y Aracely factura a mano', function () {
    Reglas::set('dropi.facturacion_automatica', false);
    $p = facDespachar(facPedido('E-2'), $this->user->id);
    $aracely = facAracely();

    $a = $this->actingAs($aracely)->postJson('/dropi/escaner/analizar', ['guia' => 'E-2'])->assertOk()->json('factura');
    expect($a['numero'])->toBeNull()
        ->and($a['motivo'])->toContain('apagada')
        ->and($a['puede_facturar'])->toBeTrue();

    $r = $this->actingAs($aracely)->postJson('/dropi/escaner/facturar', ['pedido_id' => $p->id, 'guia' => 'E-2'])->assertOk()->json();
    expect($r['factura']['numero'])->not->toBeNull()
        ->and(FacturaVenta::count())->toBe(1);

    // Escanear otra vez no factura dos veces.
    $this->actingAs($aracely)->postJson('/dropi/escaner/facturar', ['pedido_id' => $p->id, 'guia' => 'E-2'])->assertOk();
    expect(FacturaVenta::count())->toBe(1);
});

it('4.6 · un alistador ve el motivo pero no puede facturar', function () {
    Reglas::set('dropi.facturacion_automatica', false);
    $p = facDespachar(facPedido('E-3'), $this->user->id);
    $alistador = App\Models\User::factory()->create();
    $alistador->assignRole(Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']));

    $a = $this->actingAs($alistador)->postJson('/dropi/escaner/analizar', ['guia' => 'E-3'])->assertOk()->json('factura');
    expect($a['puede_facturar'])->toBeFalse();
    $this->actingAs($alistador)->postJson('/dropi/escaner/facturar', ['pedido_id' => $p->id, 'guia' => 'E-3'])->assertForbidden();
});
