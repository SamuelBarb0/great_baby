<?php

/**
 * Fase 2 de compras en el panel de Dropi: pagarle al proveedor y programar pagos.
 *
 *  · El pago se registra desde la factura (Cuentas por pagar), queda ligado a
 *    su recepción y baja el saldo.
 *  · Lo confirma OTRA persona; al confirmar, el asiento sale en la línea Dropi.
 *    Antes salía en Great Baby: el pago no tiene línea propia y se deducía del
 *    módulo (Cartera).
 *  · La programación sólo toma facturas de Dropi, no deja programar más que el
 *    saldo y la autoriza gerencia, nunca quien la armó.
 */

use App\Auth\Permisos;
use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Actions\ConfirmarPagoProveedor;
use App\Modules\Cartera\Actions\RegistrarPagoProveedor;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\ProgramacionPago;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Filament\Pages\CuentasPorPagarDropi;
use App\Modules\Dropi\Filament\Pages\PagosProveedorDropi;
use App\Modules\Dropi\Filament\Pages\ProgramacionPagosDropi;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function rolConPermisosDe(string $rol): Role
{
    $r = Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
    $r->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($rol, $roles, true))
        ->keys()->flatMap(fn ($s) => Permisos::permisosDe($s))->all());

    return $r;
}

beforeEach(function () {
    Queue::fake();
    $this->seed(Database\Seeders\PermisosSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->deshacerRepeater = Repeater::fake();

    $this->contador = User::factory()->create(['name' => 'Contadora']);
    $this->contador->assignRole(rolConPermisosDe('Contador'));
    $this->gerente = User::factory()->create(['name' => 'Gerente']);
    $this->gerente->assignRole(rolConPermisosDe('Gerente'));

    if (! DB::table('retenciones_config')->where('tipo', 'retefuente')->where('concepto', 'compras_generales')->exists()) {
        DB::table('retenciones_config')->insert([
            'tipo' => 'retefuente', 'concepto' => 'compras_generales', 'base_minima' => 1_000_000,
            'tarifa_pct' => 2.5, 'cuenta_puc' => '236540', 'activa' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->proveedor = Contacto::create(['numero_documento' => '901444555', 'nombre_completo' => 'Juguetes Andinos', 'es_proveedor' => true, 'ciudad' => 'Bogotá']);
    $this->bodega = \App\Modules\Dropi\Models\InventarioUbicacion::create(['codigo' => 'R-P-01', 'nombre' => 'Rack P', 'categoria' => 'venta', 'activa' => true]);
});

afterEach(fn () => ($this->deshacerRepeater)());

/** Una factura de proveedor confirmada, de la línea dada. */
function facturaProveedor(LineaNegocio $l, float $valor): RecepcionCompra
{
    $oc = OrdenCompra::create([
        'numero' => 'OC-' . fake()->unique()->numerify('####'), 'proveedor_id' => test()->proveedor->id,
        'tipo' => 'nacional', 'moneda' => 'COP', 'fecha_emision' => now()->subDays(10)->toDateString(),
        'subtotal' => $valor, 'total' => $valor, 'estado' => 'aprobada', 'linea_negocio' => $l,
    ]);

    return RecepcionCompra::create([
        'numero' => 'RC-' . fake()->unique()->numerify('####'), 'orden_id' => $oc->id, 'bodega_id' => test()->bodega->id,
        'fecha_recepcion' => now()->subDays(5)->toDateString(), 'factura_proveedor' => 'FP-' . fake()->unique()->numerify('####'),
        'estado' => 'confirmada', 'total_recibido' => $valor, 'confirmada_at' => now()->subDays(5),
    ]);
}

it('«Registrar pago» desde la factura liga el pago a la recepción y baja el saldo', function () {
    $rc = facturaProveedor(LineaNegocio::Dropi, 500_000);
    $this->actingAs($this->contador);

    Livewire::test(CuentasPorPagarDropi::class)
        ->callTableAction('pagar', $rc->id, [
            'fecha' => now()->toDateString(), 'monto_bruto' => 200_000, 'iva' => 0,
            'concepto_retencion' => 'compras_generales', 'metodo' => 'transferencia',
        ])
        ->assertHasNoTableActionErrors();

    $pago = PagoProveedor::latest('id')->firstOrFail();
    expect($pago->recepcion_id)->toBe($rc->id)
        ->and($pago->contacto_id)->toBe($this->proveedor->id)
        ->and($pago->estado)->toBe('pendiente')
        ->and((float) $pago->monto_neto)->toBe(200_000.0); // bajo la base mínima: sin retención

    $fila = app(\App\Modules\Compras\Services\CuentasPorPagar::class)->pendientes()->firstWhere('recepcion_id', $rc->id);
    expect($fila['saldo'])->toBe(300_000.0);
});

it('no deja pagar más de lo que se debe de la factura', function () {
    $rc = facturaProveedor(LineaNegocio::Dropi, 100_000);
    $this->actingAs($this->contador);

    Livewire::test(CuentasPorPagarDropi::class)
        ->callTableAction('pagar', $rc->id, [
            'fecha' => now()->toDateString(), 'monto_bruto' => 150_000, 'iva' => 0,
            'concepto_retencion' => 'compras_generales', 'metodo' => 'transferencia',
        ])
        ->assertNotified('Es más de lo que se debe');

    expect(PagoProveedor::count())->toBe(0);
});

it('confirmar es de otra persona, y el asiento del pago sale en Dropi', function () {
    $rc = facturaProveedor(LineaNegocio::Dropi, 300_000);
    $pago = RegistrarPagoProveedor::run([
        'fecha' => now()->toDateString(), 'contacto_id' => $this->proveedor->id, 'recepcion_id' => $rc->id,
        'orden_compra_id' => $rc->orden_id, 'monto_bruto' => 300_000, 'iva' => 0,
        'concepto_retencion' => 'compras_generales', 'metodo' => 'transferencia', 'user_id' => $this->contador->id,
    ]);

    // Quien lo registró no lo confirma.
    $this->actingAs($this->contador);
    Livewire::test(PagosProveedorDropi::class)
        ->callTableAction('confirmar', $pago)
        ->assertNotified("Pago #{$pago->id}: vos lo registraste, así que no lo podés confirmar. Lo tiene que revisar otra persona del equipo contable.");
    expect($pago->fresh()->estado)->toBe('pendiente');

    $this->actingAs($this->gerente);
    Livewire::test(PagosProveedorDropi::class)
        ->callTableAction('confirmar', $pago)
        ->assertNotified("Pago #{$pago->id} confirmado y encolado a SIIGO");

    $asiento = MovimientoContable::where('origen_type', PagoProveedor::class)->where('origen_id', $pago->id)->get();
    expect($pago->fresh()->estado)->toBe('confirmado')
        ->and($asiento)->not->toBeEmpty()
        ->and($asiento->every(fn ($m) => $m->linea_negocio === LineaNegocio::Dropi))->toBeTrue();
});

it('el pago de una compra de Great Baby sigue en Great Baby, y uno sin recepción también', function () {
    $rc = facturaProveedor(LineaNegocio::GreatBaby, 300_000);
    $conRecepcion = RegistrarPagoProveedor::run([
        'fecha' => now()->toDateString(), 'contacto_id' => $this->proveedor->id, 'recepcion_id' => $rc->id,
        'monto_bruto' => 300_000, 'concepto_retencion' => 'compras_generales', 'metodo' => 'transferencia', 'user_id' => $this->contador->id,
    ]);
    $suelto = RegistrarPagoProveedor::run([
        'fecha' => now()->toDateString(), 'contacto_id' => $this->proveedor->id,
        'monto_bruto' => 50_000, 'concepto_retencion' => 'compras_generales', 'metodo' => 'transferencia', 'user_id' => $this->contador->id,
    ]);
    ConfirmarPagoProveedor::run($conRecepcion, $this->gerente);
    ConfirmarPagoProveedor::run($suelto, $this->gerente);

    $lineas = MovimientoContable::where('origen_type', PagoProveedor::class)->pluck('linea_negocio')->unique()->values();
    expect($lineas->all())->toBe([LineaNegocio::GreatBaby]);
});

it('anular un pago confirmado revierte su asiento', function () {
    $rc = facturaProveedor(LineaNegocio::Dropi, 300_000);
    $pago = RegistrarPagoProveedor::run([
        'fecha' => now()->toDateString(), 'contacto_id' => $this->proveedor->id, 'recepcion_id' => $rc->id,
        'monto_bruto' => 300_000, 'concepto_retencion' => 'compras_generales', 'metodo' => 'transferencia', 'user_id' => $this->contador->id,
    ]);
    ConfirmarPagoProveedor::run($pago, $this->gerente);
    $this->actingAs($this->gerente);

    Livewire::test(PagosProveedorDropi::class)
        ->callTableAction('anular', $pago, ['motivo' => 'Se pagó a la cuenta equivocada'])
        ->assertHasNoTableActionErrors();

    expect($pago->fresh()->estado)->toBe('anulado')
        ->and(MovimientoContable::where('origen_type', PagoProveedor::class)->where('origen_id', $pago->id)->count())->toBe(0);
});

it('programar: sólo facturas de Dropi, nunca más que el saldo, y autoriza gerencia sin ser quien la armó', function () {
    $dropi = facturaProveedor(LineaNegocio::Dropi, 400_000);
    $gb = facturaProveedor(LineaNegocio::GreatBaby, 900_000);
    $this->actingAs($this->contador);

    // Una factura de Great Baby no se programa desde acá: ni es opción del formulario.
    Livewire::test(ProgramacionPagosDropi::class)
        ->callAction('nueva', ['fecha_programada' => now()->toDateString(), 'items' => [['recepcion_id' => $gb->id, 'monto' => 100]]])
        ->assertHasActionErrors(['items.0.recepcion_id']);

    // Más que el saldo, tampoco.
    Livewire::test(ProgramacionPagosDropi::class)
        ->callAction('nueva', ['fecha_programada' => now()->toDateString(), 'items' => [['recepcion_id' => $dropi->id, 'monto' => 500_000]]])
        ->assertNotified('No se pudo programar');
    expect(ProgramacionPago::count())->toBe(0);

    Livewire::test(ProgramacionPagosDropi::class)
        ->callAction('nueva', ['fecha_programada' => now()->toDateString(), 'items' => [['recepcion_id' => $dropi->id, 'monto' => 400_000]]])
        ->assertHasNoActionErrors();
    $p = ProgramacionPago::firstOrFail();
    expect($p->estado)->toBe('borrador')->and($p->total())->toBe(400_000.0);

    // La gerente la autoriza; la contadora (que la armó) ni ve el botón.
    Livewire::test(ProgramacionPagosDropi::class)->assertTableActionHidden('autorizar', $p);
    $this->actingAs($this->gerente);
    Livewire::test(ProgramacionPagosDropi::class)
        ->callTableAction('autorizar', $p)
        ->assertNotified("Programación {$p->numero} autorizada");
    expect($p->fresh()->estado)->toBe('autorizada')
        ->and($p->fresh()->autorizada_por)->toBe($this->gerente->id);
});

it('la gerente que arma una programación no la autoriza ella misma', function () {
    $dropi = facturaProveedor(LineaNegocio::Dropi, 400_000);
    $this->actingAs($this->gerente);

    Livewire::test(ProgramacionPagosDropi::class)
        ->callAction('nueva', ['fecha_programada' => now()->toDateString(), 'items' => [['recepcion_id' => $dropi->id, 'monto' => 400_000]]]);
    $p = ProgramacionPago::firstOrFail();

    Livewire::test(ProgramacionPagosDropi::class)
        ->callTableAction('autorizar', $p)
        ->assertNotified('Quien arma la programación no la autoriza. Pedile el visto bueno a gerencia.');
    expect($p->fresh()->estado)->toBe('borrador');
});
