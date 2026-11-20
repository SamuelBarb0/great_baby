<?php

/**
 * La wallet y el cruce de Dropi viven en «Contabilidad» y están conectados al
 * libro (9-nov, pedido de Samuel).
 *
 *  · Cada movimiento dice cómo llegó al libro: con su asiento, por el recibo
 *    de caja de su factura, o no llegó (recibo pendiente / sin asiento).
 *  · El libro contable abre filtrado a los asientos de un movimiento, aunque
 *    sea de otro mes (su filtro de fecha arranca en «este mes»).
 *  · La Contadora, que ve la wallet, ahora también ve el cruce.
 *  · «Contabilidad Dropi» concilia la wallet con su cuenta (contable.cta_dropi_wallet).
 */

use App\Auth\Permisos;
use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Dropi\Actions\AsignarCorteACarga;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Pages\CruceWalletPedidos;
use App\Modules\Dropi\Filament\Pages\LibroContableDropi;
use App\Modules\Dropi\Filament\Resources\DropiWalletMovimientoResource;
use App\Modules\Dropi\Filament\Resources\DropiWalletMovimientoResource\Pages\ListDropiWalletMovimientos;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use App\Modules\Dropi\Services\ContabilidadDropi;
use App\Support\Reglas;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function walletContRol(string $rol): Role
{
    $r = Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
    $r->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($rol, $roles, true))
        ->keys()->flatMap(fn ($s) => Permisos::permisosDe($s))->all());

    return $r;
}

function walletContPedido(string $orden, ?int $facturaId = null): DropiPedido
{
    return DropiPedido::create([
        'corte_id' => test()->corte->id, 'guia' => 'G'.$orden, 'dropi_orden_id' => $orden,
        'cliente_nombre' => 'Cliente '.$orden, 'monto_esperado_proveedor' => 50000,
        'estado' => EstadoPedidoDropi::Pagado, 'despachado_at' => now()->subDays(5),
        'factura_venta_id' => $facturaId,
    ]);
}

function walletContFactura(string $numero): FacturaVenta
{
    return FacturaVenta::createQuietly([
        'numero' => $numero, 'contacto_id' => test()->cliente->id, 'estado' => 'pendiente',
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => 50000, 'impuestos' => 0, 'total' => 50000, 'saldo' => 50000, 'linea_negocio' => 'dropi',
    ]);
}

function walletContMov(string $id, string $tipo, float $monto, ?DropiPedido $pedido = null, ?string $fecha = null): DropiWalletMovimiento
{
    return DropiWalletMovimiento::create([
        'dropi_movimiento_id' => $id, 'fecha' => $fecha ?? now()->toDateString(),
        'tipo' => $tipo, 'monto' => $monto, 'pedido_id' => $pedido?->id, 'categoria' => 'prueba',
    ]);
}

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Queue::fake();
    Reglas::limpiarRequestCache();
    Reglas::set('contable.cta_cxc_default', '13050501');
    config()->set('dropi.web.wallet_desde', now()->subMonths(3)->toDateString());
    $this->seed(Database\Seeders\PermisosSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->corte = AsignarCorteACarga::run(CarbonImmutable::now());
    $this->cliente = Contacto::create(['numero_documento' => '222222222', 'nombre_completo' => 'Consumidor final', 'es_cliente' => true]);

    // Pedido facturado CON recibo de caja → «por recibo».
    $this->fConRecibo = walletContFactura('DRP-1');
    $this->movConRecibo = walletContMov('web:1', 'pago_guia', 50000, walletContPedido('101', $this->fConRecibo->id));
    PagoVenta::createQuietly([
        'factura_id' => $this->fConRecibo->id, 'contacto_id' => $this->cliente->id, 'fecha' => now()->toDateString(),
        'monto_recibido' => 50000, 'monto_aplicado' => 50000, 'diferencia' => 0,
        'medio_pago' => 'wallet_dropi', 'referencia' => 'web:1', 'banco' => 'Wallet Dropi',
    ]);
    // Pedido facturado SIN recibo → «recibo pendiente».
    $this->fSinRecibo = walletContFactura('DRP-2');
    $this->movSinRecibo = walletContMov('web:2', 'pago_guia', 40000, walletContPedido('102', $this->fSinRecibo->id));
    // Sanción del mes pasado → asiento propio (sanciones / billetera).
    $this->movSancion = walletContMov('web:3', 'indemnizacion', -15000, null, now()->subMonth()->startOfMonth()->addDays(2)->toDateString());
    // Pago de un pedido sin factura → asiento propio (billetera / anticipo).
    $this->movSinFactura = walletContMov('web:4', 'pago_guia', 30000, walletContPedido('104'));
    // Tipo «Otro» → también se asienta desde el 19-nov (otros ingresos).
    $this->movOtro = walletContMov('web:5', 'otro', 7000);

    $this->contadora = User::factory()->create(['name' => 'Contadora']);
    $this->contadora->assignRole(walletContRol('Contador'));
});

it('pone la wallet y el cruce en el grupo Contabilidad', function () {
    expect(DropiWalletMovimientoResource::getNavigationGroup())->toBe('Contabilidad')
        ->and(CruceWalletPedidos::getNavigationGroup())->toBe('Contabilidad');
});

it('deja entrar a la Contadora al cruce, y no a quien no ve la wallet', function () {
    $this->actingAs($this->contadora);
    expect(CruceWalletPedidos::canAccess())->toBeTrue();

    $this->get(CruceWalletPedidos::getUrl())->assertOk()
        ->assertSee('Pagos de Dropi y su contabilidad')
        ->assertSee('DRP-2')
        ->assertSee('Recibo pendiente');

    $this->actingAs(User::factory()->create());
    expect(CruceWalletPedidos::canAccess())->toBeFalse();
});

it('dice cómo llegó cada movimiento al libro', function () {
    $estados = DropiWalletMovimiento::query()->conEstadoContable()->pluck('estado_contable', 'dropi_movimiento_id')->all();

    expect($estados)->toBe([
        'web:1' => DropiWalletMovimiento::CONTABLE_POR_RECIBO,
        'web:2' => DropiWalletMovimiento::CONTABLE_RECIBO_PENDIENTE,
        'web:3' => DropiWalletMovimiento::CONTABLE_ASENTADO,
        'web:4' => DropiWalletMovimiento::CONTABLE_ASENTADO,
        'web:5' => DropiWalletMovimiento::CONTABLE_ASENTADO,
    ]);
    expect($this->movOtro->fresh()->estadoContable())->toBe(DropiWalletMovimiento::CONTABLE_ASENTADO);
});

it('un asiento reversado deja el movimiento sin asiento', function () {
    // Un movimiento corregido a cero reversa el asiento y no crea uno nuevo.
    $this->movSancion->update(['monto' => 0]);

    expect($this->movSancion->fresh()->estadoContable())->toBe(DropiWalletMovimiento::CONTABLE_SIN_ASIENTO);
});

it('filtra la wallet por estado contable y por movimiento', function () {
    $this->actingAs($this->contadora);

    Livewire::test(ListDropiWalletMovimientos::class)
        ->filterTable('estado_contable', [DropiWalletMovimiento::CONTABLE_RECIBO_PENDIENTE])
        ->assertCanSeeTableRecords([$this->movSinRecibo])
        ->assertCanNotSeeTableRecords([$this->movConRecibo, $this->movSancion, $this->movOtro]);

    Livewire::withQueryParams(['filters' => ['movimiento' => ['id' => $this->movOtro->id]]])
        ->test(ListDropiWalletMovimientos::class)
        ->assertCanSeeTableRecords([$this->movOtro])
        ->assertCanNotSeeTableRecords([$this->movSinRecibo]);
});

it('el libro abre en los asientos de un movimiento aunque sea del mes pasado', function () {
    $this->actingAs($this->contadora);
    $propios = MovimientoContable::where('origen_type', DropiWalletMovimiento::class)->where('origen_id', $this->movSancion->id)->get();
    $ajenos = MovimientoContable::where('origen_type', DropiWalletMovimiento::class)->where('origen_id', $this->movSinFactura->id)->get();
    expect($propios)->toHaveCount(2)->and($ajenos)->toHaveCount(2);

    $url = LibroContableDropi::urlDeWallet($this->movSancion->id);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    Livewire::withQueryParams($query)->test(LibroContableDropi::class)
        ->assertCanSeeTableRecords($propios)
        ->assertCanNotSeeTableRecords($ajenos);

    // Y la fila del libro lleva de vuelta al movimiento.
    expect(DropiWalletMovimientoResource::urlDelMovimiento($this->movSancion->id))
        ->toContain('filters%5Bmovimiento%5D%5Bid%5D='.$this->movSancion->id);
});

it('concilia la wallet con la cuenta de la billetera y explica la diferencia', function () {
    $w = app(ContabilidadDropi::class)->delMes(now('America/Bogota')->format('Y-m'))['wallet'];

    // Wallet: 50.000 + 40.000 − 15.000 + 30.000 + 7.000 = 112.000.
    // Libro (billetera): −15.000 (sanción) + 30.000 (pago sin factura) + 7.000
    // («Otro») = 22.000; el recibo de web:1 se creó sin eventos, así que
    // tampoco está en el libro.
    expect($w['saldo_wallet'])->toBe(112000.0)
        ->and($w['cuenta'])->toBe((string) setting('contable.cta_dropi_wallet'))
        ->and($w['saldo_contable'])->toBe(22000.0)
        ->and($w['diferencia'])->toBe(90000.0)
        // Pendiente: sólo el recibo que falta (40.000); el «Otro» ya se asienta.
        ->and($w['pendientes'])->toBe(['n' => 1, 'monto' => 40000.0]);
});
