<?php

/**
 * El arqueo diario de la caja menor.
 *
 * Reemplaza la hoja por día del Excel «CAJA MENOR OCTUBRE 2026». Lo que se
 * protege acá es lo que la hoja de cálculo no puede exigir:
 *
 *  · un gasto sin «entregado a» no entra — sin eso no se le puede pedir el
 *    soporte a nadie;
 *  · el día no cierra con plata sin explicar. El 1 de octubre, en el archivo
 *    real, lo contado difería de lo esperado en $229.400 y los conceptos
 *    anotados sumaban $229.800: cuatrocientos pesos que nadie vio porque una
 *    hoja no obliga a que las dos cifras coincidan;
 *  · lo contado se guarda por denominación, no como total, para que un error
 *    de suma se pueda encontrar;
 *  · lo que ya entró a un arqueo cerrado no se quita.
 *
 * Y el saldo se arrastra: el 2 de octubre abre con exactamente lo que cerró
 * el 1. La caja menor NO se vacía sola cada día.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Contabilidad\Models\Arqueo;
use App\Modules\Contabilidad\Models\Caja;
use App\Modules\Contabilidad\Models\MovimientoCaja;
use App\Modules\Contabilidad\Models\PlanCuenta;
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
        ->keys()->map(fn ($s) => Permisos::permiso($s))->all());

    $this->silvia = User::factory()->create(['name' => 'Silvia Contadora']);
    $this->silvia->assignRole($contador);

    PlanCuenta::create(['codigo' => '11050501', 'nombre' => 'Caja general', 'clase' => '1',
        'nivel' => 5, 'naturaleza' => 'debito', 'permite_movimiento' => true, 'activa' => true]);

    $this->general = Caja::create([
        'codigo' => 'CG-01', 'nombre' => 'Caja general', 'tipo' => 'general',
        'cuenta_puc' => '11050501', 'saldo_inicial' => 0, 'activa' => true,
    ]);

    $this->caja = Caja::create([
        'codigo' => 'CM-01', 'nombre' => 'Caja menor', 'tipo' => 'menor',
        'cuenta_puc' => '11050501', 'saldo_inicial' => 1_000_000, 'activa' => true,
        'caja_destino_cierre_id' => $this->general->id,
    ]);

    $this->dia = '2026-10-01';
});

/** Nombre propio: en Pest las funciones del archivo son globales. */
function anotarEnCaja(object $caso, array $datos)
{
    return $caso->actingAs($caso->silvia)
        ->post("/app/contabilidad/cajas/{$caso->caja->id}/movimientos",
            array_merge(['fecha' => $caso->dia], $datos));
}

function verElDia(object $caso, ?string $fecha = null): array
{
    return $caso->actingAs($caso->silvia)
        ->get("/app/contabilidad/cajas/{$caso->caja->id}/dia?fecha=".($fecha ?? $caso->dia))
        ->assertOk()->viewData('page')['props'];
}

function cerrarElDia(object $caso, array $conteo, array $conceptos = [], ?string $fecha = null)
{
    return $caso->actingAs($caso->silvia)
        ->post("/app/contabilidad/cajas/{$caso->caja->id}/cerrar", [
            'fecha' => $fecha ?? $caso->dia,
            'conteo' => $conteo,
            'conceptos' => $conceptos,
        ]);
}

// ───────────────────── Movimientos del día ─────────────────────

it('un gasto necesita decir a quién se le entregó la plata', function () {
    anotarEnCaja($this, [
        'tipo' => 'gasto', 'descripcion' => 'Cancelación de domicilios', 'valor' => 395000,
    ])->assertSessionHasErrors('entregado_a');

    expect(MovimientoCaja::count())->toBe(0);
});

it('un ingreso no lo necesita', function () {
    anotarEnCaja($this, [
        'tipo' => 'ingreso', 'descripcion' => 'Retiro cuenta de Alejandra', 'valor' => 1_509_000,
    ])->assertRedirect();

    expect(MovimientoCaja::count())->toBe(1);
});

it('el saldo esperado es apertura mas ingresos menos gastos', function () {
    anotarEnCaja($this, ['tipo' => 'ingreso', 'descripcion' => 'Retiro', 'valor' => 500_000]);
    anotarEnCaja($this, ['tipo' => 'gasto', 'descripcion' => 'Aseo', 'entregado_a' => 'Full Company', 'valor' => 200_000]);

    $t = verElDia($this)['totales'];

    expect($t['apertura'])->toBe(1_000_000.0)
        ->and($t['ingresos'])->toBe(500_000.0)
        ->and($t['gastos'])->toBe(200_000.0)
        ->and($t['esperado'])->toBe(1_300_000.0);
});

// ───────────────────── El cierre ─────────────────────

it('no cierra si queda plata sin explicar', function () {
    anotarEnCaja($this, ['tipo' => 'gasto', 'descripcion' => 'Aseo', 'entregado_a' => 'Full', 'valor' => 200_000]);
    // Esperado: 800.000. Contamos 790.000 → faltan 10.000 y nadie dice por qué.
    cerrarElDia($this, ['billete-50000' => 15, 'billete-20000' => 2])
        ->assertSessionHasErrors('conceptos');

    // Es la diferencia de fondo con el Excel: ahí los $400 sueltos del
    // 1 de octubre se guardaron igual.
    expect(Arqueo::count())->toBe(0);
});

it('con el concepto que la explica, sí cierra', function () {
    anotarEnCaja($this, ['tipo' => 'gasto', 'descripcion' => 'Aseo', 'entregado_a' => 'Full', 'valor' => 200_000]);

    cerrarElDia($this,
        ['billete-50000' => 15, 'billete-20000' => 2],
        [['concepto' => 'Vueltos dados en pesos', 'valor' => -10_000]],
    )->assertRedirect();

    $a = Arqueo::firstOrFail();

    expect((float) $a->saldo_esperado)->toBe(800_000.0)
        ->and((float) $a->saldo_contado)->toBe(790_000.0)
        ->and((float) $a->diferencia)->toBe(-10_000.0)
        ->and($a->resultado)->toBe('faltante')
        ->and($a->estado)->toBe('cerrado')
        ->and($a->descuadreSinExplicar())->toBe(0.0);
});

it('el conteo queda guardado billete por billete', function () {
    cerrarElDia($this, ['billete-100000' => 9, 'billete-50000' => 2, 'moneda-500' => 0],
        [['concepto' => 'Ajuste', 'valor' => 0]]);

    $a = Arqueo::with('denominaciones')->firstOrFail();

    // Las que vienen en cero no se guardan: ensucian sin aportar.
    expect($a->denominaciones)->toHaveCount(2)
        ->and($a->totalContado())->toBe(1_000_000.0);
});

it('el arqueo lleva el consecutivo del formato en papel', function () {
    cerrarElDia($this, ['billete-100000' => 10]);

    expect(Arqueo::firstOrFail()->numero)->toBe('OCT-001-2026');
});

it('lo que entró a un arqueo cerrado ya no se quita', function () {
    anotarEnCaja($this, ['tipo' => 'gasto', 'descripcion' => 'Aseo', 'entregado_a' => 'Full', 'valor' => 200_000]);
    $mov = MovimientoCaja::firstOrFail();

    cerrarElDia($this, ['billete-50000' => 16]);

    $this->actingAs($this->silvia)
        ->post("/app/contabilidad/cajas/{$this->caja->id}/movimientos/{$mov->id}/quitar")
        ->assertRedirect();

    expect($mov->fresh())->not->toBeNull()
        ->and($mov->fresh()->arqueo_id)->not->toBeNull();
});

it('con el día cerrado tampoco se agregan movimientos nuevos', function () {
    cerrarElDia($this, ['billete-100000' => 10]);

    anotarEnCaja($this, ['tipo' => 'ingreso', 'descripcion' => 'Tardío', 'valor' => 50_000])
        ->assertRedirect();

    expect(MovimientoCaja::count())->toBe(0);
});

// ───────────────────── El día siguiente ─────────────────────

it('el día siguiente abre con lo que se contó el anterior', function () {
    anotarEnCaja($this, ['tipo' => 'gasto', 'descripcion' => 'Aseo', 'entregado_a' => 'Full', 'valor' => 200_000]);
    cerrarElDia($this, ['billete-50000' => 16]);   // cuadra exacto en 800.000

    // Lo que muestra el Excel del cliente: el 2 de octubre abre con lo que
    // cerró el 1. La caja menor no se vacía sola.
    expect(verElDia($this, '2026-10-02')['totales']['apertura'])->toBe(800_000.0);
});
