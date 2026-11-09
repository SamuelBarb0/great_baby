<?php

/**
 * «Compras» y «Contabilidad» dentro del panel de Dropi: cada pantalla muestra
 * sólo lo de la línea Dropi, nunca lo de Great Baby, y la ve sólo quien puede
 * ver la misma pantalla en /app.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Actions\CrearFacturaManual;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Filament\Pages\CuentasPorPagarDropi;
use App\Modules\Dropi\Filament\Pages\GastosDropi;
use App\Modules\Dropi\Filament\Pages\LibroContableDropi;
use App\Modules\Dropi\Filament\Pages\OrdenesCompraDropi;
use App\Modules\Dropi\Filament\Pages\PagosRecibidosDropi;
use App\Modules\Dropi\Filament\Pages\RecepcionesDropi;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Gerencia\Models\GastoOperativo;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->seed(Database\Seeders\PermisosSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->proveedor = Contacto::create(['numero_documento' => '901000333', 'nombre_completo' => 'Proveedor Y', 'es_proveedor' => true]);
    $this->bodega = \App\Modules\Dropi\Models\InventarioUbicacion::create(['codigo' => 'R-C-01', 'nombre' => 'Rack C', 'categoria' => 'venta', 'activa' => true]);
});

/** Una compra confirmada con factura, de la línea dada. */
function compraDeLinea(LineaNegocio $l, float $valor): array
{
    $oc = OrdenCompra::create([
        'numero' => 'OC-' . fake()->unique()->numerify('####'), 'proveedor_id' => test()->proveedor->id,
        'tipo' => 'nacional', 'moneda' => 'COP', 'fecha_emision' => now()->subDays(10)->toDateString(),
        'subtotal' => $valor, 'total' => $valor, 'estado' => 'aprobada', 'linea_negocio' => $l,
    ]);
    $rc = RecepcionCompra::create([
        'numero' => 'RC-' . fake()->unique()->numerify('####'), 'orden_id' => $oc->id, 'bodega_id' => test()->bodega->id,
        'fecha_recepcion' => now()->subDays(5)->toDateString(), 'factura_proveedor' => 'FP-' . fake()->unique()->numerify('####'),
        'estado' => 'confirmada', 'total_recibido' => $valor, 'confirmada_at' => now()->subDays(5),
    ]);

    return [$oc, $rc];
}

it('órdenes y recepciones: sólo las de Dropi', function () {
    [$ocGb, $rcGb] = compraDeLinea(LineaNegocio::GreatBaby, 2_000_000);
    [$ocDr, $rcDr] = compraDeLinea(LineaNegocio::Dropi, 300_000);
    $this->actingAs($this->aracely);

    Livewire::test(OrdenesCompraDropi::class)
        ->assertCanSeeTableRecords([$ocDr])->assertCanNotSeeTableRecords([$ocGb]);
    Livewire::test(RecepcionesDropi::class)
        ->assertCanSeeTableRecords([$rcDr])->assertCanNotSeeTableRecords([$rcGb]);
});

it('cuentas por pagar: la deuda de Dropi y nada más', function () {
    compraDeLinea(LineaNegocio::GreatBaby, 2_000_000);
    [, $rcDr] = compraDeLinea(LineaNegocio::Dropi, 300_000);
    $this->actingAs($this->aracely);

    $pagina = Livewire::test(CuentasPorPagarDropi::class)->assertOk()
        ->assertSee($rcDr->factura_proveedor)
        ->assertSee('$ 300.000');

    expect($pagina->instance()->resumen()[0]['valor'])->toBe('$ 300.000');
});

it('libro y gastos: sólo lo de Dropi', function () {
    $base = ['fecha' => now()->toDateString(), 'cuenta_puc' => '11100501', 'haber' => 0,
        'origen_type' => 'App\\Modules\\Cartera\\Models\\FacturaVenta', 'origen_id' => 1];
    $movGb = MovimientoContable::create($base + ['debe' => 100, 'descripcion' => 'GB', 'linea_negocio' => LineaNegocio::GreatBaby]);
    $movDr = MovimientoContable::create($base + ['debe' => 40, 'descripcion' => 'Dropi', 'linea_negocio' => LineaNegocio::Dropi]);

    $gasto = fn (LineaNegocio $l) => GastoOperativo::create([
        'numero' => 'GO-' . fake()->unique()->numberBetween(1000, 99999), 'fecha' => now()->toDateString(),
        'categoria' => 'Papelería', 'descripcion' => 'x', 'monto' => 1000, 'metodo_pago' => 'transferencia',
        'tipo' => 'gasto', 'estado' => 'pendiente', 'solicita_id' => User::factory()->create()->id,
        'destino_fiscal' => DestinoFiscal::Siigo, 'linea_negocio' => $l,
    ]);
    $gGb = $gasto(LineaNegocio::GreatBaby);
    $gDr = $gasto(LineaNegocio::Dropi);
    $this->actingAs($this->aracely);

    Livewire::test(LibroContableDropi::class)
        ->assertCanSeeTableRecords([$movDr])->assertCanNotSeeTableRecords([$movGb]);
    Livewire::test(GastosDropi::class)
        ->assertCanSeeTableRecords([$gDr])->assertCanNotSeeTableRecords([$gGb]);
});

it('pagos recibidos: sólo los de facturas de Dropi', function () {
    $cliente = Contacto::create(['numero_documento' => '900111222', 'nombre_completo' => 'Cliente Z', 'es_cliente' => true]);
    $producto = Producto::create(['referencia' => 'REF-PZ', 'nombre' => 'Producto Z', 'activo' => true]);
    $pago = function (LineaNegocio $l) use ($cliente, $producto) {
        $f = CrearFacturaManual::run([
            'contacto_id' => $cliente->id, 'fecha_emision' => now()->toDateString(),
            'destino_fiscal' => DestinoFiscal::Siigo->value, 'linea_negocio' => $l->value,
            'items' => [['producto_id' => $producto->id, 'descripcion' => 'Venta', 'cantidad' => 1, 'precio_unit' => 50_000]],
        ]);

        return PagoVenta::create(['factura_id' => $f->id, 'contacto_id' => $cliente->id, 'fecha' => now()->toDateString(),
            'medio_pago' => 'transferencia', 'monto_recibido' => 50_000, 'monto_aplicado' => 50_000, 'diferencia' => 0]);
    };
    $pGb = $pago(LineaNegocio::GreatBaby);
    $pDr = $pago(LineaNegocio::Dropi);
    $this->actingAs($this->aracely);

    Livewire::test(PagosRecibidosDropi::class)
        ->assertCanSeeTableRecords([$pDr])->assertCanNotSeeTableRecords([$pGb]);
});

it('el menú tiene Compras y Contabilidad, «Abrir en el ERP» va relativo y el Alistador no entra', function () {
    $html = $this->actingAs($this->aracely)->get('/admin/compras-ordenes-dropi')->assertOk()
        ->assertSee('Cuentas por pagar')->assertSee('Libro contable')->assertSee('Pagos recibidos')
        ->assertSee('Contabilidad Dropi')
        // Relativo: con url() tomaba el APP_URL de otro puerto.
        ->assertSee('href="/app/compras?linea=dropi"', false)
        ->getContent();
    expect($html)->not->toContain('8076/app');

    $alistador = User::factory()->create();
    $alistador->assignRole(Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']));
    foreach (['compras-ordenes-dropi', 'compras-recepciones-dropi', 'compras-cuentas-por-pagar-dropi',
        'contabilidad-libro-dropi', 'contabilidad-gastos-dropi', 'contabilidad-pagos-dropi'] as $slug) {
        $this->actingAs($alistador)->get('/admin/' . $slug)->assertForbidden();
    }
});
