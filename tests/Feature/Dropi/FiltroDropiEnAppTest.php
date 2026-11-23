<?php

/**
 * Las pantallas de compras y contabilidad de /app aceptan `?linea=dropi` (el
 * botón «Abrir en el ERP» del panel de Dropi llega así) y muestran sólo lo de
 * Dropi. Sin el parámetro muestran sólo Great Baby (23-nov-2026: Dropi va
 * aparte) y con `?linea=todas` el consolidado.
 */

use App\Auth\Permisos;
use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Gerencia\Models\GastoOperativo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->seed(Database\Seeders\PermisosSeeder::class);

    $contador = Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']);
    $contador->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array('Contador', $roles, true))
        ->keys()->flatMap(fn ($s) => Permisos::permisosDe($s))->all());

    $this->contador = User::factory()->create();
    $this->contador->assignRole($contador);
});

function propsDe(string $url): array
{
    return test()->actingAs(test()->contador)->get($url)->assertOk()->viewData('page')['props'];
}

it('el libro con ?linea=dropi sólo trae los asientos de Dropi', function () {
    $base = [
        'fecha' => now()->toDateString(), 'cuenta_puc' => '11100501', 'haber' => 0,
        'origen_type' => 'App\\Modules\\Cartera\\Models\\FacturaVenta', 'origen_id' => 1,
    ];
    MovimientoContable::create($base + ['debe' => 100, 'descripcion' => 'de Great Baby', 'linea_negocio' => LineaNegocio::GreatBaby]);
    MovimientoContable::create($base + ['debe' => 40, 'descripcion' => 'de Dropi', 'linea_negocio' => LineaNegocio::Dropi]);

    $todo = propsDe('/app/contabilidad?linea=todas');
    expect(propsDe('/app/contabilidad')['kpis']['movimientos'])->toBe(1);
    $dropi = propsDe('/app/contabilidad?linea=dropi');

    expect($todo['kpis']['movimientos'])->toBe(2)
        ->and($dropi['kpis']['movimientos'])->toBe(1)
        ->and($dropi['kpis']['total_debe'])->toBe(40.0)
        ->and(collect($dropi['movimientosRecientes'])->pluck('descripcion')->all())->toBe(['de Dropi'])
        ->and(collect($dropi['porCuenta'])->sum('debe'))->toBe(40.0)
        ->and($dropi['filtros']['linea'])->toBe('dropi');
});

it('los gastos con ?linea=dropi sólo traen los de Dropi', function () {
    $gasto = fn (LineaNegocio $l, string $desc) => GastoOperativo::create([
        'numero' => 'GO-' . fake()->unique()->numberBetween(1000, 99999),
        'fecha' => now()->toDateString(), 'categoria' => 'Papelería', 'descripcion' => $desc,
        'monto' => 1000, 'metodo_pago' => 'transferencia', 'tipo' => 'gasto', 'estado' => 'pendiente',
        'solicita_id' => User::factory()->create()->id,
        'destino_fiscal' => DestinoFiscal::Siigo, 'linea_negocio' => $l,
    ]);
    $gasto(LineaNegocio::GreatBaby, 'gasto GB');
    $gasto(LineaNegocio::Dropi, 'gasto Dropi');

    $todo = propsDe('/app/gastos?linea=todas');
    expect(propsDe('/app/gastos')['gastos']['data'])->toHaveCount(1);
    $dropi = propsDe('/app/gastos?linea=dropi');

    expect($todo['gastos']['data'])->toHaveCount(2)
        ->and(collect($dropi['gastos']['data'])->pluck('descripcion')->all())->toBe(['gasto Dropi'])
        ->and($dropi['conteos']['pendiente']['total'])->toBe(1)
        // Viaja en los filtros para que los botones de tipo/estado no la pierdan.
        ->and($dropi['filtros']['linea'])->toBe('dropi');
});

it('cuentas por pagar con ?linea=dropi sólo trae las compras de Dropi', function () {
    $proveedor = Contacto::create(['numero_documento' => '901000222', 'nombre_completo' => 'Proveedor X', 'es_proveedor' => true]);
    $bodega = \App\Modules\Dropi\Models\InventarioUbicacion::create(['codigo' => 'R-B-01', 'nombre' => 'Rack B', 'categoria' => 'venta', 'activa' => true]);
    $recepcion = function (LineaNegocio $l, float $valor) use ($proveedor, $bodega) {
        $oc = OrdenCompra::create([
            'numero' => 'OC-' . fake()->unique()->numerify('####'), 'proveedor_id' => $proveedor->id,
            'tipo' => 'nacional', 'moneda' => 'COP', 'fecha_emision' => now()->subDays(10)->toDateString(),
            'subtotal' => $valor, 'total' => $valor, 'estado' => 'aprobada', 'linea_negocio' => $l,
        ]);

        return RecepcionCompra::create([
            'numero' => 'RC-' . fake()->unique()->numerify('####'), 'orden_id' => $oc->id, 'bodega_id' => $bodega->id,
            'fecha_recepcion' => now()->subDays(5)->toDateString(), 'factura_proveedor' => 'FP-' . fake()->unique()->numerify('####'),
            'estado' => 'confirmada', 'total_recibido' => $valor, 'confirmada_at' => now()->subDays(5),
        ]);
    };
    $recepcion(LineaNegocio::GreatBaby, 2_000_000);
    $recepcion(LineaNegocio::Dropi, 300_000);

    $todo = propsDe('/app/compras/cuentas-por-pagar?linea=todas');
    expect(propsDe('/app/compras/cuentas-por-pagar')['filas'])->toHaveCount(1);
    $dropi = propsDe('/app/compras/cuentas-por-pagar?linea=dropi');

    expect($todo['filas'])->toHaveCount(2)
        ->and($dropi['filas'])->toHaveCount(1)
        ->and($dropi['resumen']['total'])->toBe(300_000.0)
        ->and($dropi['filtros']['linea'])->toBe('dropi');
});

it('una línea que no existe se ignora y se ve todo', function () {
    expect(propsDe('/app/gastos?linea=inventada')['filtros']['linea'])->toBeNull();
});
