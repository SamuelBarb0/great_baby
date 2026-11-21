<?php

/**
 * Dropi conectado al PUC y a SIIGO (19-nov-2026).
 *
 *  · Cada movimiento de la wallet va a su cuenta HOJA del PUC (11100501 la
 *    billetera, no la 111015 que no existe) y la dirección la manda el signo:
 *    un reverso deshace, no repite.
 *  · Un pago que llega antes de la factura es anticipo; al facturar se cruza.
 *  · Un reverso de pago se deshace con contra-asiento, sin borrar historia.
 *  · La devolución reversa la venta UNA sola vez (la nota crédito, con IVA) y
 *    su propio asiento sólo devuelve el inventario, a la cuenta de la categoría.
 *  · Lo de la wallet viaja a SIIGO como comprobante contable, con Dropi como
 *    tercero; el recibo de la wallet no sale con el tipo de pago del banco.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\NotaCredito;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Catalogo\Models\Categoria;
use App\Modules\Catalogo\Models\Impuesto;
use App\Modules\Dropi\Actions\CobroWalletDeFactura;
use App\Modules\Dropi\Actions\ConciliarWalletDropi;
use App\Modules\Dropi\Actions\FacturarPedidoDropi;
use App\Modules\Dropi\Actions\RegistrarDevolucion;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\DestinoDevolucion;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiDevolucion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Dropi\Services\MapearMovimientoWalletDropi;
use App\Modules\Siigo\Jobs\PushAsientoDropiASiigo;
use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Services\ComprobanteDropiSiigo;
use App\Modules\Siigo\Services\SiigoEmisionService;
use App\Support\Reglas;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Queue::fake();
    Reglas::limpiarRequestCache();
    Reglas::set('dropi.nit_tercero', '901.111.222-3');
    Reglas::set('dropi.facturacion_automatica', true);

    $this->user = App\Models\User::factory()->create();
    $this->bodega = InventarioUbicacion::create(['codigo' => 'BOD-P', 'nombre' => 'P', 'activa' => true, 'categoria' => 'venta']);
    $iva = Impuesto::create(['codigo' => 'IVA19', 'nombre' => 'IVA 19%', 'tipo' => 'iva', 'porcentaje' => 19, 'activo' => true]);

    // Juguetería: el PUC abre ingreso, costo e inventario de esta familia con
    // el mismo nombre; la categoría sólo trae la de ingreso (como en el ERP).
    foreach (['41350104' => '4', '61350504' => '6', '143504' => '1'] as $codigo => $clase) {
        App\Modules\Contabilidad\Models\PlanCuenta::create(['codigo' => $codigo, 'nombre' => 'JUGUETERÍA Y ESTIMULACIÓN',
            'clase' => $clase, 'nivel' => 4, 'naturaleza' => $clase === '4' ? 'credito' : 'debito',
            'permite_movimiento' => true, 'activa' => true]);
    }
    $cat = Categoria::create(['codigo' => 'JUGU-T', 'nombre' => 'Juguetería prueba', 'cuenta_puc_ingreso' => '41350104']);
    $p = Producto::create(['referencia' => 'COCHE-PUC', 'nombre' => 'Coche', 'desglose_stock' => true,
        'impuesto_id' => $iva->id, 'categoria_id' => $cat->id]);
    $this->variante = ProductoVariante::create([
        'producto_id' => $p->id, 'color_codigo' => 'AZU', 'color_nombre' => 'Azul', 'codigo_barras' => '7700000000888',
    ]);
    VincularProductoDropi::run('DV-PUC', $this->variante->id);
    InventarioMovimiento::create([
        'variante_id' => $this->variante->id, 'ubicacion_id' => $this->bodega->id,
        'tipo' => 'entrada_compra', 'cantidad' => 20, 'costo_unit' => 15000,
    ]);
});

/** Pedido de 2 unidades a $59.500 de proveedor (IVA incluido) = $119.000. */
function pucPedido(string $guia): DropiPedido
{
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: '7'.substr(md5($guia), 0, 6), transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 119000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO('DV-PUC', 2, 59500, 'Coche', 'Azul')],
        creadoAt: CarbonImmutable::now(),
    )]);

    return DropiPedido::where('guia', $guia)->firstOrFail();
}

/** Alistar, empacar (sale del kardex y causa costo) y despachar (factura). */
function pucDespachar(DropiPedido $p, int $userId): DropiPedido
{
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $userId);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $userId);
    $p->transicionar(EstadoPedidoDropi::Despachado, 'manual', $userId);

    return $p->fresh();
}

function pucWallet(DropiPedido $p, int $id, float $monto, string $codigo = '1006'): App\Modules\Dropi\DTOs\PagoWalletDTO
{
    return MapearMovimientoWalletDropi::map([
        'id' => $id, 'type' => $monto < 0 ? 'SALIDA' : 'ENTRADA', 'amount' => abs($monto),
        'identification_code' => $codigo, 'order_id' => $p->dropi_orden_id,
        'created_at' => now()->toDateTimeString(), 'description' => "PAGO GUIA: *{$p->guia}*",
    ]);
}

function pucMov(string $id, string $tipo, float $monto): DropiWalletMovimiento
{
    return DropiWalletMovimiento::create([
        'dropi_movimiento_id' => $id, 'fecha' => now()->toDateString(),
        'tipo' => $tipo, 'monto' => $monto, 'categoria' => 'prueba',
    ]);
}

/** Saldo (debe − haber) de una cuenta en el libro. */
function pucSaldo(string $cuenta): float
{
    return round((float) MovimientoContable::where('cuenta_puc', $cuenta)->selectRaw('COALESCE(SUM(debe - haber), 0) s')->value('s'), 2);
}

it('cada movimiento de la wallet va a su cuenta del PUC y el signo decide la dirección', function () {
    $casos = [
        // [tipo, monto, contrapartida, la contrapartida va al DEBE]
        ['retiro_banco', -500000, '11200501', true],   // 1020: sale al banco
        ['retiro_banco', 500000, '11200501', false],   // 1021: retiro negado, vuelve
        ['flete_garantia', -8000, '51355001', true],
        ['flete_garantia', 8000, '51355001', false],   // reverso del flete
        ['tarjeta', -3000, '53051501', true],
        ['indemnizacion', -15000, '53952001', true],   // sanción que cobra Dropi
        ['indemnizacion', 20000, '42950502', false],   // indemnización a favor
        ['otro', -1000, '53959501', true],
        ['otro', 700, '42950502', false],
    ];

    foreach ($casos as $i => [$tipo, $monto, $contra, $alDebe]) {
        $mov = pucMov("web:p{$i}", $tipo, $monto);
        $lineas = MovimientoContable::where('origen_type', DropiWalletMovimiento::class)->where('origen_id', $mov->id)->get();
        $billetera = $lineas->firstWhere('cuenta_puc', '11100501');
        $otra = $lineas->firstWhere('cuenta_puc', $contra);

        expect($lineas)->toHaveCount(2, "{$tipo} {$monto}")
            ->and($billetera)->not->toBeNull()
            ->and($otra)->not->toBeNull()
            ->and((float) ($alDebe ? $otra->debe : $otra->haber))->toBe(abs((float) $monto), "{$tipo} {$monto}")
            ->and((float) ($alDebe ? $billetera->haber : $billetera->debe))->toBe(abs((float) $monto), "{$tipo} {$monto}");
    }

    // La billetera del libro es exactamente la wallet.
    expect(pucSaldo('11100501'))->toBe(round((float) DropiWalletMovimiento::sum('monto'), 2))
        // Ni un peso en la cuenta que no existe ni en los grupos viejos.
        ->and(MovimientoContable::whereIn('cuenta_puc', ['111015', '1110', '4295', '5195', '5395', '530525'])->count())->toBe(0);

    // Todo con Dropi como tercero, y encolado para SIIGO.
    $dropi = Contacto::where('numero_documento', '9011112223')->sole();
    expect(MovimientoContable::where('origen_type', DropiWalletMovimiento::class)->where('tercero_id', '!=', $dropi->id)->count())->toBe(0);
    Queue::assertPushed(PushAsientoDropiASiigo::class, count($casos));
});

it('un pago que llega antes de la factura es anticipo, y al facturar se cruza con ella', function () {
    Reglas::set('dropi.facturacion_automatica', false);
    $p = pucDespachar(pucPedido('PUC-1'), $this->user->id);
    expect($p->factura_venta_id)->toBeNull();

    app(ConciliarWalletDropi::class)->conciliar([pucWallet($p, 81001, 119000)]);

    // Sin factura: la plata es un anticipo, NO un abono a la cartera (que la
    // dejaba en negativo).
    expect(pucSaldo('28050501'))->toBe(-119000.0)
        ->and(pucSaldo('11100501'))->toBe(119000.0)
        ->and(pucSaldo((string) setting('contable.cta_cxc_default')))->toBe(0.0);

    Reglas::set('dropi.facturacion_automatica', true);
    $f = app(FacturarPedidoDropi::class)->handle($p->fresh(), $this->user->id, true);

    $f->refresh();
    expect(PagoVenta::where('medio_pago', 'wallet_dropi')->count())->toBe(1)
        ->and((float) $f->saldo)->toBe(0.0)
        ->and($f->estado->value)->toBe('pagada')
        // El anticipo vuelve a cero, la cartera queda cerrada, la billetera intacta.
        ->and(pucSaldo('28050501'))->toBe(0.0)
        ->and(pucSaldo((string) setting('contable.cta_cxc_default')))->toBe(0.0)
        ->and(pucSaldo('11100501'))->toBe(119000.0);

    // Cruzar otra vez no cambia nada.
    $lineas = MovimientoContable::count();
    CobroWalletDeFactura::cruzarPrevios($p->fresh());
    expect(MovimientoContable::count())->toBe($lineas)
        ->and(PagoVenta::count())->toBe(1);
});

it('la venta de Dropi usa cuentas hoja: ingreso de la categoría e IVA 24080501', function () {
    $p = pucDespachar(pucPedido('PUC-2'), $this->user->id);
    $f = FacturaVenta::findOrFail($p->factura_venta_id);
    $lineas = MovimientoContable::where('origen_type', FacturaVenta::class)->where('origen_id', $f->id)->get();

    expect((float) $lineas->firstWhere('cuenta_puc', '41350104')?->haber)->toBe(100000.0)
        ->and((float) $lineas->firstWhere('cuenta_puc', '24080501')?->haber)->toBe(19000.0)
        // El costo del empaque también va a la familia del producto.
        ->and(pucSaldo('61350504'))->toBe(30000.0)
        ->and(pucSaldo('143504'))->toBe(-30000.0);
});

it('un reverso de pago reabre la factura con contra-asiento, sin borrar el recibo del libro', function () {
    $p = pucDespachar(pucPedido('PUC-3'), $this->user->id);
    $p->transicionar(EstadoPedidoDropi::Entregado, 'api');
    app(ConciliarWalletDropi::class)->conciliar([pucWallet($p, 82001, 119000)]);
    $pago = PagoVenta::sole();
    expect(pucSaldo('11100501'))->toBe(119000.0);

    app(ConciliarWalletDropi::class)->conciliar([pucWallet($p, 82002, -119000, '1009')]);

    $lineas = MovimientoContable::where('origen_type', PagoVenta::class)->where('origen_id', $pago->id)->get();
    expect(PagoVenta::count())->toBe(0)
        ->and((float) FacturaVenta::find($p->factura_venta_id)->saldo)->toBe(119000.0)
        ->and(pucSaldo('11100501'))->toBe(0.0)
        ->and(pucSaldo((string) setting('contable.cta_cxc_default')))->toBe(119000.0)
        // El recibo sigue en el libro, con su reversa al lado.
        ->and($lineas)->toHaveCount(4)
        ->and($lineas->filter(fn ($l) => str_starts_with($l->descripcion, '[REVERSA #')))->toHaveCount(2);
});

it('la devolución reversa la venta UNA vez, con su IVA, y sólo devuelve inventario', function () {
    $p = pucDespachar(pucPedido('PUC-4'), $this->user->id);
    $f = FacturaVenta::findOrFail($p->factura_venta_id);

    RegistrarDevolucion::run('PUC-4', DestinoDevolucion::Reingreso, $this->user->id, null, ['v:'.$this->variante->id => 2]);

    $nc = NotaCredito::sole();
    $dev = DropiDevolucion::sole();
    $deNc = MovimientoContable::where('origen_type', NotaCredito::class)->where('origen_id', $nc->id)->get();
    $deDev = MovimientoContable::where('origen_type', DropiDevolucion::class)->where('origen_id', $dev->id)->get();

    expect((float) $nc->valor)->toBe(119000.0)
        // La NC: base a devoluciones, IVA de vuelta, cartera del cliente de la factura.
        ->and((float) $deNc->firstWhere('cuenta_puc', '41750501')?->debe)->toBe(100000.0)
        ->and((float) $deNc->firstWhere('cuenta_puc', '24080501')?->debe)->toBe(19000.0)
        ->and((float) $deNc->firstWhere('cuenta_puc', (string) setting('contable.cta_cxc_default'))?->haber)->toBe(119000.0)
        // Una sola reversa de la venta en todo el libro.
        ->and(pucSaldo('41750501'))->toBe(100000.0)
        // La devolución: sólo inventario, a la cuenta de la familia.
        ->and($deDev->pluck('cuenta_puc')->unique()->sort()->values()->all())->toBe(['143504', '61350504'])
        ->and(pucSaldo('61350504'))->toBe(0.0)
        // La factura queda sin saldo: la cartera cerró.
        ->and((float) $f->fresh()->saldo)->toBe(0.0)
        ->and(pucSaldo((string) setting('contable.cta_cxc_default')))->toBe(0.0);
});

it('una devolución sin factura no toca la cartera', function () {
    Reglas::set('dropi.facturacion_automatica', false);
    pucDespachar(pucPedido('PUC-5'), $this->user->id);

    RegistrarDevolucion::run('PUC-5', DestinoDevolucion::Reingreso, $this->user->id, null, ['v:'.$this->variante->id => 2]);

    expect(NotaCredito::count())->toBe(0)
        ->and(MovimientoContable::where('cuenta_puc', 'like', '13%')->count())->toBe(0)
        ->and(MovimientoContable::where('cuenta_puc', 'like', '4175%')->count())->toBe(0)
        ->and(pucSaldo('143504'))->toBe(0.0);
});

/** SIIGO falso: guarda lo que le mandan y contesta con un id. */
function pucSiigoFalso(): void
{
    $cfg = SiigoConfig::current();
    $cfg->fill(['username' => 'api@greatbaby.co', 'ambiente' => 'sandbox', 'activo' => true, 'payment_type_id' => 400]);
    $cfg->access_key = 'llave-gb';
    $cfg->save();
    config(['siigo.driver' => 'real']);
    Http::fake([
        'api.siigo.com/auth' => Http::response(['access_token' => 'tok', 'expires_in' => 86400]),
        'api.siigo.com/v1/journals' => Http::sequence()
            ->push(['id' => 'jr-1'], 201)->push(['id' => 'jr-2'], 201)->push(['id' => 'jr-3'], 201),
        '*' => Http::response([], 200),
    ]);
}

function pucJournals(): array
{
    return collect(Http::recorded())->map(fn ($par) => $par[0])
        ->filter(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/journals'))
        ->values()->all();
}

it('lo de la wallet viaja a SIIGO como comprobante, con Dropi como tercero, una sola vez', function () {
    pucSiigoFalso();
    Reglas::set('siigo.doc_type_wallet_dropi', 555);
    $mov = pucMov('web:s1', 'flete_garantia', -8000);

    $ids = app(ComprobanteDropiSiigo::class)->emitir(DropiWalletMovimiento::class, $mov->id);

    $j = pucJournals();
    expect($ids)->toBe(['jr-1'])
        ->and($j)->toHaveCount(1)
        ->and($j[0]['document']['id'])->toBe(555)
        ->and(collect($j[0]['items'])->pluck('account.code')->sort()->values()->all())->toBe(['11100501', '51355001'])
        ->and(collect($j[0]['items'])->pluck('customer.identification')->unique()->all())->toBe(['9011112223'])
        ->and(MovimientoContable::where('origen_id', $mov->id)->where('siigo_journal_id', 'jr-1')->count())->toBe(2);

    // Nada pendiente: un reintento no manda otro.
    expect(app(ComprobanteDropiSiigo::class)->emitir(DropiWalletMovimiento::class, $mov->id))->toBe([]);

    // Si el movimiento cambia, viaja la reversa y el asiento nuevo, cada uno
    // con su fecha, y lo ya enviado no se toca.
    $mov->update(['monto' => -9000]);
    app(ComprobanteDropiSiigo::class)->emitir(DropiWalletMovimiento::class, $mov->id);
    expect(MovimientoContable::where('origen_id', $mov->id)->where('origen_type', DropiWalletMovimiento::class)
        ->whereNull('siigo_journal_id')->count())->toBe(0)
        ->and(MovimientoContable::where('origen_id', $mov->id)->where('siigo_journal_id', 'jr-1')->count())->toBe(2);
});

it('sin el NIT de Dropi el comprobante no sale, y dice por qué', function () {
    pucSiigoFalso();
    Reglas::set('siigo.doc_type_wallet_dropi', 555);
    Reglas::set('dropi.nit_tercero', '');
    $mov = pucMov('web:s2', 'tarjeta', -3000);

    expect(fn () => app(ComprobanteDropiSiigo::class)->emitir(DropiWalletMovimiento::class, $mov->id))
        ->toThrow(RuntimeException::class, 'Falta el NIT de Dropi');
    expect(pucJournals())->toBe([]);
});

it('el recibo de la wallet no sale con el tipo de pago del banco', function () {
    $f = FacturaVenta::createQuietly([
        'numero' => 'DRP-T1', 'contacto_id' => FacturarPedidoDropi::consumidorFinal()->id, 'estado' => 'pendiente',
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'subtotal' => 1000, 'impuestos' => 0, 'total' => 1000, 'saldo' => 1000, 'linea_negocio' => 'dropi',
    ]);
    $pago = PagoVenta::createQuietly([
        'factura_id' => $f->id, 'contacto_id' => $f->contacto_id, 'fecha' => now()->toDateString(),
        'monto_recibido' => 1000, 'monto_aplicado' => 1000, 'diferencia' => 0, 'medio_pago' => 'wallet_dropi',
    ]);
    Reglas::set('siigo.payment_type_recibo', 999);

    $tipo = new ReflectionMethod(SiigoEmisionService::class, 'tipoPagoSiigoDe');
    $tipo->setAccessible(true);

    expect(fn () => $tipo->invoke(app(SiigoEmisionService::class), $pago))
        ->toThrow(RuntimeException::class, 'Wallet Dropi');

    // Con su tipo de pago propio, sale con ése.
    DB::table('metodos_pago')->updateOrInsert(['codigo' => 'wallet_dropi'],
        ['nombre' => 'Wallet Dropi', 'siigo_payment_type_id' => 777, 'created_at' => now(), 'updated_at' => now()]);
    expect($tipo->invoke(app(SiigoEmisionService::class), $pago))->toBe(777);
});

it('las cuentas de Dropi en Reglas no aceptan un grupo', function () {
    App\Modules\Contabilidad\Models\PlanCuenta::create(['codigo' => '1110', 'nombre' => 'Bancos', 'clase' => '1', 'nivel' => 2,
        'naturaleza' => 'debito', 'permite_movimiento' => false, 'activa' => true]);
    App\Modules\Contabilidad\Models\PlanCuenta::create(['codigo' => '11100501', 'nombre' => 'Billetera Virtual Dropi', 'clase' => '1', 'nivel' => 4,
        'naturaleza' => 'debito', 'permite_movimiento' => true, 'activa' => true]);

    expect(fn () => Reglas::set('contable.cta_dropi_wallet', '1110'))->toThrow(InvalidArgumentException::class);
    Reglas::set('contable.cta_dropi_wallet', '11100501');
    expect(setting('contable.cta_dropi_wallet'))->toBe('11100501');
});

it('la reparación rehace con contra-asiento lo que quedó con las cuentas viejas, y una sola vez', function () {
    // Un flete asentado como lo hacía el código viejo: 5195 contra la 111015.
    $mov = DropiWalletMovimiento::withoutEvents(fn () => pucMov('web:r1', 'flete_garantia', -8000));
    foreach ([['5195', 8000, 0], ['111015', 0, 8000]] as [$cuenta, $debe, $haber]) {
        MovimientoContable::create([
            'fecha' => now()->toDateString(), 'cuenta_puc' => $cuenta, 'debe' => $debe, 'haber' => $haber,
            'origen_type' => DropiWalletMovimiento::class, 'origen_id' => $mov->id, 'descripcion' => 'Flete garantía Dropi — viejo',
        ]);
    }

    // En modo revisión no escribe nada.
    $this->artisan('dropi:reparar-contabilidad')->assertSuccessful();
    expect(MovimientoContable::where('origen_id', $mov->id)->count())->toBe(2);

    $this->artisan('dropi:reparar-contabilidad --aplicar')->assertSuccessful();
    expect(pucSaldo('111015'))->toBe(0.0)
        ->and(pucSaldo('5195'))->toBe(0.0)
        ->and(pucSaldo('51355001'))->toBe(8000.0)
        ->and(pucSaldo('11100501'))->toBe(-8000.0)
        // La historia queda: 2 viejas + 2 reversas + 2 nuevas.
        ->and(MovimientoContable::where('origen_id', $mov->id)->where('origen_type', DropiWalletMovimiento::class)->count())->toBe(6);

    // Correrla otra vez no cambia nada.
    $this->artisan('dropi:reparar-contabilidad --aplicar')->assertSuccessful();
    expect(MovimientoContable::where('origen_id', $mov->id)->where('origen_type', DropiWalletMovimiento::class)->count())->toBe(6);
});

it('regla de la casa: lo del ERP no se mueve — Great Baby sigue con sus cuentas de siempre', function () {
    $cliente = Contacto::create(['numero_documento' => '900555111', 'nombre_completo' => 'Cliente B2B', 'es_cliente' => true]);
    $f = FacturaVenta::createQuietly([
        'numero' => 'FV-ERP-1', 'contacto_id' => $cliente->id, 'estado' => 'pendiente', 'linea_negocio' => 'great_baby',
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'subtotal' => 100000, 'descuento' => 0, 'impuestos' => 19000, 'total' => 119000, 'saldo' => 119000,
    ]);
    (new App\Modules\Cartera\Actions\RegistrarAsientoContable())->factura($f);
    $nc = NotaCredito::create(['prefijo' => 'NC', 'numero' => 901, 'factura_id' => $f->id, 'motivo' => 'prueba',
        'valor' => 119000, 'estado' => 'emitida', 'emitida_at' => now()]);

    $cuentas = MovimientoContable::whereIn('origen_type', [FacturaVenta::class, NotaCredito::class])
        ->whereIn('origen_id', [$f->id, $nc->id])->pluck('cuenta_puc')->unique()->all();

    expect($cuentas)->toContain((string) setting('contable.cta_iva_venta_default'))
        ->toContain((string) setting('contable.cta_devolucion_default'))
        ->not->toContain((string) setting('contable.cta_dropi_iva_venta'))
        ->not->toContain((string) setting('contable.cta_dropi_devolucion'))
        // Y las reglas del ERP siguen con sus valores de antes.
        ->and(setting('contable.cta_iva_venta_default'))->toBe('240805')
        ->and(setting('contable.cta_devolucion_default'))->toBe('4175');
});

it('las pantallas lo muestran: la Contadora ve las cuentas de Dropi en Reglas y Contabilidad Dropi concilia contra la 11100501', function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $rol = Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']);
    $rol->syncPermissions(collect(App\Auth\Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array('Contador', $roles, true))
        ->keys()->flatMap(fn ($s) => App\Auth\Permisos::permisosDe($s))->all());
    $contadora = App\Models\User::factory()->create();
    $contadora->assignRole($rol);

    $this->actingAs($contadora)->get('/app/reglas')->assertOk()
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $p) => $p->component('Reglas')
            ->where('grupos.dropi', fn ($reglas) => collect($reglas)->pluck('clave')
                ->intersect(['contable.cta_dropi_wallet', 'contable.cta_dropi_anticipo', 'contable.cta_dropi_iva_venta'])->count() === 3));

    pucMov('web:v1', 'flete_garantia', -8000);
    Filament\Facades\Filament::setCurrentPanel('admin');
    $this->get(App\Modules\Dropi\Filament\Pages\ContabilidadDropi::getUrl())->assertOk()
        ->assertSee('Wallet Dropi vs. cuenta 11100501', false)
        ->assertSee('Saldo de la cuenta 11100501 Billetera Dropi en el libro', false)
        ->assertDontSee('111015', false);
});

it('una sanción de Dropi ligada a un pedido no se pierde: queda en la wallet y en el libro', function () {
    // Antes la sanción (monto negativo) violaba ck_sancion_montos_positivos y
    // la transacción se deshacía entera: el movimiento nunca se guardaba.
    $p = pucPedido('PUC-SAN');
    $r = app(ConciliarWalletDropi::class)->conciliar([pucWallet($p, 83001, -15000, '1052')]);

    $mov = DropiWalletMovimiento::where('dropi_movimiento_id', 'web:83001')->first();
    expect($r['errores'])->toBe(0)
        ->and($mov)->not->toBeNull()
        ->and((float) App\Modules\Dropi\Models\DropiSancion::where('pedido_id', $p->id)->value('monto_recibido'))->toBe(15000.0)
        ->and(pucSaldo('53952001'))->toBe(15000.0)
        ->and(pucSaldo('11100501'))->toBe(-15000.0);
});

/** Compra recibida de 10 coches a $15.000 + IVA 19 %, de la línea indicada. */
function pucCompra(string $linea, int $varianteId): App\Modules\Compras\Models\RecepcionCompra
{
    $prov = Contacto::firstOrCreate(['numero_documento' => '901999888'], ['nombre_completo' => 'Proveedor QA', 'es_proveedor' => true]);
    $oc = App\Modules\Compras\Models\OrdenCompra::create([
        'numero' => 'OC-'.fake()->unique()->numberBetween(10000, 99999), 'proveedor_id' => $prov->id,
        'fecha_emision' => now()->toDateString(), 'estado' => 'aprobada',
        'subtotal' => 150000, 'descuento' => 0, 'iva' => 28500, 'total' => 178500, 'linea_negocio' => $linea,
    ]);
    $item = App\Modules\Compras\Models\OrdenCompraItem::create([
        'orden_id' => $oc->id, 'descripcion' => 'Coche', 'cantidad' => 10, 'precio_unit' => 15000,
        'iva_pct' => 19, 'subtotal' => 150000, 'iva' => 28500, 'total' => 178500, 'variante_id' => $varianteId,
    ]);
    $r = App\Modules\Compras\Models\RecepcionCompra::create([
        'numero' => 'RC-'.fake()->unique()->numberBetween(10000, 99999), 'orden_id' => $oc->id,
        'bodega_id' => test()->bodega->id, 'fecha_recepcion' => now()->toDateString(), 'estado' => 'recibida', 'total_recibido' => 150000,
    ]);
    App\Modules\Compras\Models\RecepcionCompraItem::create([
        'recepcion_id' => $r->id, 'orden_item_id' => $item->id, 'variante_id' => $varianteId,
        'cantidad_recibida' => 10, 'costo_unit' => 15000,
    ]);
    (new App\Modules\Compras\Actions\RegistrarAsientoCompra())->recepcion($r->fresh());

    return $r->fresh();
}

it('una compra de Dropi entra al inventario de su familia, con IVA y proveedor de Dropi, y el pago cierra la deuda', function () {
    $r = pucCompra('dropi', $this->variante->id);
    $lineas = MovimientoContable::where('origen_type', $r::class)->where('origen_id', $r->id)->get();

    expect((float) $lineas->firstWhere('cuenta_puc', '143504')?->debe)->toBe(150000.0)
        ->and((float) $lineas->firstWhere('cuenta_puc', '24081001')?->debe)->toBe(28500.0)
        ->and((float) $lineas->firstWhere('cuenta_puc', '22050501')?->haber)->toBe(178500.0)
        ->and($lineas->pluck('cuenta_puc')->intersect(['1435', '240810', '220505'])->all())->toBe([]);

    // Se vende un pedido de 2: el inventario de la familia queda POSITIVO.
    pucDespachar(pucPedido('PUC-C1'), $this->user->id);
    expect(pucSaldo('143504'))->toBe(120000.0)
        ->and(pucSaldo('61350504'))->toBe(30000.0);

    // El pago al proveedor cancela la deuda en la MISMA cuenta.
    $pago = App\Modules\Cartera\Models\PagoProveedor::create([
        'fecha' => now()->toDateString(), 'contacto_id' => $r->orden->proveedor_id, 'recepcion_id' => $r->id,
        'orden_compra_id' => $r->orden_id, 'user_id' => $this->user->id,
        'monto_bruto' => 178500, 'monto_retenciones' => 0, 'monto_neto' => 178500,
        'metodo' => 'transferencia', 'estado' => 'confirmado',
    ]);
    (new App\Modules\Cartera\Actions\RegistrarAsientoPagoProveedor())->handle($pago->fresh());
    expect(pucSaldo('22050501'))->toBe(0.0);
});

it('regla de la casa: una compra de Great Baby sigue con las cuentas del ERP', function () {
    $r = pucCompra('great_baby', $this->variante->id);
    $cuentas = MovimientoContable::where('origen_type', $r::class)->where('origen_id', $r->id)->pluck('cuenta_puc')->all();

    expect($cuentas)->toContain((string) setting('contable.cta_inventario_default'))
        ->toContain((string) setting('contable.cta_iva_compra_default'))
        ->toContain((string) setting('contable.cta_proveedores_default'))
        ->not->toContain('143504')->not->toContain('24081001')->not->toContain('22050501');
});

/** Carga de 10 coches a $15.000 por el canal indicado, cerrada con su asiento. */
function pucCarga(string $canal, string $motivo, int $varianteId): App\Modules\Inventario\Models\CargaInventario
{
    $prov = Contacto::firstOrCreate(['numero_documento' => '901999777'], ['nombre_completo' => 'Proveedor carga', 'es_proveedor' => true]);
    $c = App\Modules\Inventario\Actions\RegistrarCargaInventario::abrir([
        'motivo' => App\Modules\Inventario\Enums\MotivoCargaInventario::from($motivo),
        'observacion' => 'Carga de prueba', 'tercero_id' => $prov->id,
        'ubicacion_id' => test()->bodega->id, 'canal' => $canal, 'user_id' => test()->user->id,
    ]);
    InventarioMovimiento::create([
        'variante_id' => $varianteId, 'ubicacion_id' => test()->bodega->id, 'tipo' => 'carga_inicial_cliente',
        'cantidad' => 10, 'costo_unit' => 15000,
        'referencia_tipo' => App\Modules\Inventario\Models\CargaInventario::class, 'referencia_id' => $c->id,
    ]);
    $v = ProductoVariante::find($varianteId);
    DB::table('inventario_movimientos')->where('referencia_id', $c->id)
        ->where('referencia_tipo', App\Modules\Inventario\Models\CargaInventario::class)->update(['producto_id' => $v->producto_id]);

    return App\Modules\Inventario\Actions\RegistrarCargaInventario::cerrar($c->fresh());
}

it('la carga de mercancía de Dropi entra al inventario de la familia, contra el proveedor de Dropi, y queda como Dropi', function () {
    $c = pucCarga('dropi', 'compra', $this->variante->id);
    $lineas = MovimientoContable::where('origen_type', $c::class)->where('origen_id', $c->id)->get();

    expect($c->cuenta_contrapartida)->toBe('22050501')
        ->and((float) $lineas->firstWhere('cuenta_puc', '143504')?->debe)->toBe(150000.0)
        ->and((float) $lineas->firstWhere('cuenta_puc', '22050501')?->haber)->toBe(150000.0)
        ->and($lineas->every(fn ($l) => $l->linea_negocio === App\Modules\Contabilidad\Enums\LineaNegocio::Dropi))->toBeTrue();

    // Las otras contrapartidas de Dropi son hojas del PUC.
    foreach (['saldo_inicial' => '37050501', 'aporte_socios' => '31050501', 'donacion' => '42950501', 'sobrante' => '42950501'] as $motivo => $cuenta) {
        expect(App\Modules\Dropi\Support\CuentasDropi::contrapartidaCarga(App\Modules\Inventario\Enums\MotivoCargaInventario::from($motivo)))->toBe($cuenta);
    }
});

it('regla de la casa: la carga de inventario del ERP sigue con sus cuentas y como Great Baby', function () {
    $c = pucCarga('web', 'compra', $this->variante->id);
    $lineas = MovimientoContable::where('origen_type', $c::class)->where('origen_id', $c->id)->get();

    expect($c->cuenta_contrapartida)->toBe((string) setting('contable.cta_carga_compra'))
        ->and($lineas->pluck('cuenta_puc')->all())->not->toContain('143504')->not->toContain('22050501')
        ->and($lineas->every(fn ($l) => $l->linea_negocio === App\Modules\Contabilidad\Enums\LineaNegocio::GreatBaby))->toBeTrue();
});

it('la reparación rehace una carga de Dropi hecha con las cuentas del ERP', function () {
    // Como la dejaba el código de main: 1435 contra 220505 y marcada Great Baby.
    $c = pucCarga('web', 'compra', $this->variante->id);
    $c->forceFill(['canal' => 'dropi'])->save();

    $this->artisan('dropi:reparar-contabilidad --aplicar')->assertSuccessful();

    $vivas = MovimientoContable::query()->vivas()->where('origen_type', $c::class)->where('origen_id', $c->id)->get();
    expect($vivas->pluck('cuenta_puc')->sort()->values()->all())->toBe(['143504', '22050501'])
        ->and($vivas->every(fn ($l) => $l->linea_negocio === App\Modules\Contabilidad\Enums\LineaNegocio::Dropi))->toBeTrue()
        ->and($c->fresh()->cuenta_contrapartida)->toBe('22050501');

    // Una segunda pasada no cambia nada.
    $n = MovimientoContable::where('origen_type', $c::class)->where('origen_id', $c->id)->count();
    $this->artisan('dropi:reparar-contabilidad --aplicar')->assertSuccessful();
    expect(MovimientoContable::where('origen_type', $c::class)->where('origen_id', $c->id)->count())->toBe($n);
});
