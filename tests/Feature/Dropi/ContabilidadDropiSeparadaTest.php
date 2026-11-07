<?php

/**
 * Los libros de Great Baby y del e-commerce (Dropi) van separados.
 *
 * Lo pidió contabilidad (Jennifer): la facturación del e-commerce es aparte, y
 * todo lo que sale de ella —la venta, su costo, el cobro desde la wallet—
 * tiene que verse en el libro Dropi y en ningún momento en el de Great Baby.
 *
 * Antes del 6-nov no era así: FacturarPedidoDropi no marcaba la línea (la
 * factura tomaba el default `great_baby`) y el recibo de caja que crea el
 * cobro de Dropi la deducía del módulo Cartera (también Great Baby).
 */

use App\Modules\Cartera\Actions\RegistrarAsientoContable;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Catalogo\Models\Impuesto;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Actions\ConciliarWalletDropi;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\MapearMovimientoWalletDropi;
use App\Support\Reglas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Queue::fake();
    Reglas::set('dropi.facturacion_automatica', true);
    App\Models\EmpresaConfig::porCodigo('ecommerce')->update(['nit' => '901999888-1', 'prefijo_dian' => 'ECO', 'activa' => true]);

    $bodega = InventarioUbicacion::create(['codigo' => 'SEP-VENTA', 'nombre' => 'Venta', 'activa' => true, 'categoria' => 'venta']);
    $iva = Impuesto::create(['codigo' => 'IVA19', 'nombre' => 'IVA 19%', 'tipo' => 'iva', 'porcentaje' => 19, 'activo' => true]);
    // Agregado, como casi todo el catálogo real.
    $this->producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => '899', 'nombre' => 'Volante con sonido', 'desglose_stock' => false, 'impuesto_id' => $iva->id,
    ]));
    VincularProductoDropi::make()->aProducto('DV-1843712', $this->producto->id);
    InventarioMovimiento::create(['producto_id' => $this->producto->id, 'ubicacion_id' => $bodega->id,
        'tipo' => 'entrada_compra', 'cantidad' => 20, 'costo_unit' => 15000]);
});

function sepPedidoDespachado(string $guia): DropiPedido
{
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: '8'.substr(md5($guia), 0, 6), transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 119000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO('DV-1843712', 2, 59500, 'Volante de Juguete con Música', 'Negro-amarillo')],
        creadoAt: CarbonImmutable::now(),
    )]);
    $p = DropiPedido::where('guia', $guia)->firstOrFail();
    $p->transicionar(EstadoPedidoDropi::Despachado, 'api');

    return $p->fresh();
}

function sepPagoWallet(DropiPedido $p, int $id, float $monto)
{
    return MapearMovimientoWalletDropi::map([
        'id' => $id, 'type' => 'ENTRADA', 'amount' => $monto, 'identification_code' => '1006',
        'order_id' => $p->dropi_orden_id, 'created_at' => now()->toDateTimeString(), 'description' => "PAGO GUIA: *{$p->guia}*",
    ]);
}

/** Una venta de Great Baby con su asiento, para tener los dos libros con datos. */
function sepVentaGreatBaby(float $total): FacturaVenta
{
    $cliente = App\Models\Contacto::create(['numero_documento' => '900'.random_int(100000, 999999), 'nombre_completo' => 'Almacén B2B']);
    $f = FacturaVenta::create([
        'numero' => 'GB-SEP-'.random_int(1000, 9999), 'contacto_id' => $cliente->id,
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => round($total / 1.19, 2), 'descuento' => 0, 'impuestos' => round($total - $total / 1.19, 2),
        'total' => $total, 'saldo' => $total, 'estado' => EstadoFactura::Pendiente, 'es_electronica' => false,
        'linea_negocio' => LineaNegocio::GreatBaby,
    ]);
    (new RegistrarAsientoContable())->factura($f->refresh());

    return $f;
}

it('la venta del e-commerce, su costo y su cobro desde la wallet van al libro Dropi', function () {
    $p = sepPedidoDespachado('SEP-1');
    $f = FacturaVenta::findOrFail($p->factura_venta_id);

    expect($f->linea_negocio)->toBe(LineaNegocio::Dropi);
    $asientoFactura = MovimientoContable::where('origen_type', FacturaVenta::class)->where('origen_id', $f->id)->get();
    expect($asientoFactura)->not->toBeEmpty()
        ->and($asientoFactura->pluck('linea_negocio')->unique()->all())->toBe([LineaNegocio::Dropi]);

    $p->transicionar(EstadoPedidoDropi::Entregado, 'api');
    app(ConciliarWalletDropi::class)->conciliar([sepPagoWallet($p, 9101, 119000)]);

    $pago = PagoVenta::sole();
    $asientoPago = MovimientoContable::where('origen_type', PagoVenta::class)->where('origen_id', $pago->id)->get();
    expect($asientoPago)->not->toBeEmpty()
        ->and($asientoPago->pluck('linea_negocio')->unique()->all())->toBe([LineaNegocio::Dropi]);

    // Nada del e-commerce cayó en Great Baby.
    expect(MovimientoContable::where('linea_negocio', LineaNegocio::GreatBaby->value)->count())->toBe(0);
});

it('cada libro cuadra solo y entre los dos suman el total', function () {
    $p = sepPedidoDespachado('SEP-2');
    $p->transicionar(EstadoPedidoDropi::Entregado, 'api');
    app(ConciliarWalletDropi::class)->conciliar([sepPagoWallet($p, 9102, 119000)]);
    sepVentaGreatBaby(595000);

    $suma = fn (?LineaNegocio $l) => MovimientoContable::query()
        ->when($l, fn ($q) => $q->where('linea_negocio', $l->value))
        ->selectRaw('COALESCE(SUM(debe),0) d, COALESCE(SUM(haber),0) h, COUNT(*) n')->first();

    $gb = $suma(LineaNegocio::GreatBaby);
    $dropi = $suma(LineaNegocio::Dropi);
    $todo = $suma(null);

    expect((float) $gb->d)->toBe((float) $gb->h)              // Great Baby cuadra solo
        ->and((float) $dropi->d)->toBe((float) $dropi->h)     // Dropi cuadra solo
        ->and((int) $gb->n + (int) $dropi->n)->toBe((int) $todo->n)
        ->and(round((float) $gb->d + (float) $dropi->d, 2))->toBe(round((float) $todo->d, 2))
        ->and((float) $gb->d)->toBeGreaterThan(0)
        ->and((float) $dropi->d)->toBeGreaterThan(0);
});

it('el estado de resultados filtrado a Dropi trae la venta del e-commerce y no la de Great Baby', function () {
    sepPedidoDespachado('SEP-3');          // 119.000 con IVA → 100.000 de ingreso
    sepVentaGreatBaby(595000);             // 500.000 de ingreso, en Great Baby
    $aracely = App\Models\User::factory()->create();
    $aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $ingresos = fn (string $linea) => $this->actingAs($aracely)
        ->get('/app/contabilidad/estado-resultados?linea='.$linea)
        ->assertOk()
        ->viewData('page')['props']['ingresos']['total'];

    expect((float) $ingresos('dropi'))->toBe(100000.0)
        ->and((float) $ingresos('great_baby'))->toBe(500000.0)
        ->and((float) $ingresos('todas'))->toBe(600000.0);
});

it('panel contable, cartera, reportes de cartera y notas crédito separan Dropi de Great Baby', function () {
    sepPedidoDespachado('SEP-5');                         // factura Dropi 119.000, sin pagar
    $gb = sepVentaGreatBaby(595000);                      // factura Great Baby 595.000, sin pagar
    App\Modules\Cartera\Models\NotaCredito::create([
        'factura_id' => $gb->id, 'prefijo' => 'NC', 'numero' => 77, 'motivo' => 'Devolución', 'valor' => 50000, 'estado' => 'emitida',
    ]);
    $aracely = App\Models\User::factory()->create();
    $aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $props = fn (string $url) => $this->actingAs($aracely)->get($url)->assertOk()->viewData('page')['props'];

    // Panel contable: lo asentado de cada libro.
    $debeDropi = (float) MovimientoContable::where('linea_negocio', 'dropi')->whereDate('fecha', '>=', now()->startOfMonth())->sum('debe');
    $debeGb = (float) MovimientoContable::where('linea_negocio', 'great_baby')->whereDate('fecha', '>=', now()->startOfMonth())->sum('debe');
    expect((float) $props('/app/contabilidad/panel?linea=dropi')['kpis']['total_debe'])->toBe($debeDropi)
        ->and((float) $props('/app/contabilidad/panel?linea=great_baby')['kpis']['total_debe'])->toBe($debeGb)
        ->and($debeDropi)->toBeGreaterThan(0);

    // Dashboard de cartera.
    expect((float) $props('/app/cartera?linea=dropi')['kpis']['facturado_mes'])->toBe(119000.0)
        ->and((float) $props('/app/cartera?linea=great_baby')['kpis']['facturado_mes'])->toBe(595000.0)
        ->and((float) $props('/app/cartera')['kpis']['facturado_mes'])->toBe(714000.0);

    // Reportes de cartera: lo que se debe, por libro.
    expect((float) $props('/app/cartera/reportes?linea=dropi')['kpis']['total_cartera'])->toBe(119000.0)
        ->and((float) $props('/app/cartera/reportes?linea=great_baby')['kpis']['total_cartera'])->toBeGreaterThan(119000.0);

    // Notas crédito: la de Great Baby no aparece en Dropi.
    expect($props('/app/cartera/notas-credito?linea=dropi')['kpis']['total'])->toBe(0)
        ->and($props('/app/cartera/notas-credito?linea=great_baby')['kpis']['total'])->toBe(1);
});

it('dropi:reestampar-linea mueve al libro Dropi lo del e-commerce que nació en Great Baby, sin tocar montos', function () {
    $p = sepPedidoDespachado('SEP-4');
    $f = FacturaVenta::findOrFail($p->factura_venta_id);
    // Así quedaban antes del arreglo.
    FacturaVenta::whereKey($f->id)->update(['linea_negocio' => 'great_baby']);
    MovimientoContable::where('origen_type', FacturaVenta::class)->where('origen_id', $f->id)->update(['linea_negocio' => 'great_baby']);
    $antes = MovimientoContable::where('origen_type', FacturaVenta::class)->where('origen_id', $f->id)->sum('debe');
    sepVentaGreatBaby(595000);

    $this->artisan('dropi:reestampar-linea')->assertSuccessful();
    expect(FacturaVenta::find($f->id)->linea_negocio)->toBe(LineaNegocio::GreatBaby);   // sin --aplicar no escribe

    $this->artisan('dropi:reestampar-linea --aplicar')->assertSuccessful();

    $movs = MovimientoContable::where('origen_type', FacturaVenta::class)->where('origen_id', $f->id)->get();
    expect(FacturaVenta::find($f->id)->linea_negocio)->toBe(LineaNegocio::Dropi)
        ->and($movs->pluck('linea_negocio')->unique()->all())->toBe([LineaNegocio::Dropi])
        ->and((float) $movs->sum('debe'))->toBe((float) $antes)
        // La de Great Baby no se tocó.
        ->and(FacturaVenta::where('linea_negocio', 'great_baby')->count())->toBe(1);
});
