<?php

/**
 * El flujo de caja: proyección por área contra el real del ERP.
 *
 * Reemplaza `flujo_de_caja_semanal_V8.xlsx`. La estructura se conserva —
 * conceptos agrupados, tipo F/V, responsable, columnas por período con
 * proyección y real— y lo que cambia es de dónde sale el REAL.
 *
 * Hoy esa columna se teclea cada semana copiando de otros archivos, y de ahí
 * que el resumen de octubre muestre «mayor descuadre de caja: $106.054.462».
 * Una cifra transcrita a mano cada siete días no puede ser confiable.
 *
 * Tres reglas que la hoja de cálculo no puede imponer:
 *
 *  · Un renglón lo informa SU responsable. Si cualquiera pudiera escribir en
 *    cualquier fila, la columna «responsable» sería decoración.
 *  · Un concepto con origen calcula su real: aceptarlo también a mano
 *    dejaría dos verdades para la misma celda.
 *  · Un pago anulado no es una salida de caja.
 */

use App\Auth\Permisos;
use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Tesoreria\Models\FlujoConcepto;
use App\Modules\Tesoreria\Models\FlujoProyeccion;
use App\Modules\Tesoreria\Services\FlujoDeCaja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\FlujoDeCajaConceptosSeeder::class);

    $contador = Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']);
    $contador->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array('Contador', $roles, true))
        ->keys()->map(fn ($s) => Permisos::permiso($s))->all());

    $this->silvia = User::factory()->create(['name' => 'Silvia Contadora']);
    $this->silvia->assignRole($contador);

    // Leidy de Administración informa los arriendos. No es contadora.
    $this->leidy = User::factory()->create(['name' => 'Leidy Administración']);
    FlujoConcepto::where('codigo', 'arriendos')->update(['responsable_id' => $this->leidy->id]);

    $this->lunes = Carbon::parse('2026-10-26');   // lunes
});

/** Nombre propio: en Pest las funciones del archivo son globales. */
function conceptoDeFlujo(string $codigo): FlujoConcepto
{
    return FlujoConcepto::where('codigo', $codigo)->firstOrFail();
}

function proyectar(object $caso, string $codigo, float $monto, array $extra = [])
{
    return $caso->actingAs($extra['como'] ?? $caso->silvia)
        ->post('/app/tesoreria/flujo-de-caja/proyeccion', array_merge([
            'concepto_id' => conceptoDeFlujo($codigo)->id,
            'periodo_tipo' => 'semana',
            'periodo' => $caso->lunes->toDateString(),
            'monto' => $monto,
        ], collect($extra)->except('como')->all()));
}

// ───────────────────── Los renglones ─────────────────────

it('el seeder trae la estructura del Excel que ya usan', function () {
    expect(FlujoConcepto::count())->toBeGreaterThan(10)
        ->and(conceptoDeFlujo('recaudo_cartera')->grupo)->toBe('ingreso_operacional')
        ->and(conceptoDeFlujo('nomina_internos')->tipo)->toBe('F')
        // Cuatro renglones calculan su real solos: ahí está el ahorro.
        ->and(FlujoConcepto::whereNotNull('origen')->count())->toBeGreaterThanOrEqual(4);
});

it('volver a sembrar no duplica ni pisa el responsable asignado', function () {
    $antes = FlujoConcepto::count();

    $this->seed(Database\Seeders\FlujoDeCajaConceptosSeeder::class);

    expect(FlujoConcepto::count())->toBe($antes)
        ->and(conceptoDeFlujo('arriendos')->responsable_id)->toBe($this->leidy->id);
});

// ───────────────────── Quién informa qué ─────────────────────

it('cada quien informa sus renglones', function () {
    proyectar($this, 'arriendos', 8_000_000, ['como' => $this->leidy])->assertRedirect();

    expect(FlujoProyeccion::count())->toBe(1)
        ->and((float) FlujoProyeccion::first()->monto)->toBe(8_000_000.0)
        ->and(FlujoProyeccion::first()->capturado_por)->toBe($this->leidy->id);
});

it('nadie escribe en el renglón de otra área', function () {
    $otra = User::factory()->create(['name' => 'Fernando Compras']);

    // Si cualquiera pudiera, la columna «responsable» del Excel sería adorno
    // y nadie podría responder por su número.
    proyectar($this, 'arriendos', 1_000_000, ['como' => $otra])->assertForbidden();

    expect(FlujoProyeccion::count())->toBe(0);
});

it('contabilidad sí puede sobre cualquier renglón', function () {
    proyectar($this, 'arriendos', 8_000_000)->assertRedirect();

    expect(FlujoProyeccion::count())->toBe(1);
});

it('la pantalla le dice a cada quien qué le falta informar', function () {
    $props = $this->actingAs($this->leidy)->get('/app/tesoreria/flujo-de-caja')
        ->assertOk()->viewData('page')['props'];

    // Es la lista que hoy se pide por WhatsApp cada lunes.
    expect($props['misPendientes'])->not->toBeEmpty()
        ->and(collect($props['misPendientes'])->pluck('concepto')->unique()->all())
        ->toBe(['Arriendos']);
});

// ───────────────────── El real, calculado ─────────────────────

it('el recaudo real sale de los pagos recibidos, no de que alguien lo teclee', function () {
    $cliente = Contacto::create([
        'numero_documento' => '900111222', 'nombre_completo' => 'Cliente SAS', 'es_cliente' => true,
    ]);

    // Dos pagos dentro de la semana y uno fuera.
    //
    // `withoutEvents` porque un pago sin factura detrás no puede armar su
    // asiento —no hay CxC que cancelar— y acá lo que se prueba es la suma del
    // flujo, no la contabilización, que tiene sus propias pruebas.
    PagoVenta::withoutEvents(function () use ($cliente) {
        foreach ([['2026-10-27', 3_000_000], ['2026-10-29', 2_000_000], ['2026-11-05', 9_000_000]] as [$f, $v]) {
            PagoVenta::create([
                'contacto_id' => $cliente->id, 'fecha' => $f, 'medio_pago' => 'transferencia',
                'monto_recibido' => $v, 'monto_aplicado' => 0, 'diferencia' => $v,
            ]);
        }
    });

    $reales = app(FlujoDeCaja::class)->realesDelPeriodo(
        $this->lunes, $this->lunes->copy()->endOfWeek()
    );

    expect($reales['recaudo_cartera'])->toBe(5_000_000.0);
});

it('un concepto calculado no acepta un real tecleado', function () {
    proyectar($this, 'recaudo_cartera', 100_000_000, ['real_manual' => 55_000_000])
        ->assertSessionHasErrors('real_manual');

    // Dos verdades para la misma celda es peor que ninguna.
    expect(FlujoProyeccion::count())->toBe(0);
});

it('un concepto sin origen sí acepta su real a mano', function () {
    // No todo lo que mueve la caja pasa por el ERP: un préstamo de un socio,
    // un sobregiro. Negarse a registrarlo lo dejaría invisible.
    proyectar($this, 'prestamos_corrientes', 20_000_000, ['real_manual' => 18_000_000])
        ->assertRedirect();

    expect((float) FlujoProyeccion::first()->real_manual)->toBe(18_000_000.0);
});

// ───────────────────── La grilla ─────────────────────

it('la grilla trae los períodos pedidos y arranca en lunes', function () {
    $g = app(FlujoDeCaja::class)->grilla('semana', $this->lunes, 4);

    expect($g['periodos'])->toHaveCount(4)
        ->and($g['periodos'][0]['inicio'])->toBe('2026-10-26')
        ->and($g['periodos'][1]['inicio'])->toBe('2026-11-02');
});

it('en vista mensual los períodos arrancan el día 1', function () {
    $g = app(FlujoDeCaja::class)->grilla('mes', Carbon::parse('2026-10-15'), 3);

    expect($g['periodos'][0]['inicio'])->toBe('2026-10-01')
        ->and($g['periodos'][2]['inicio'])->toBe('2026-12-01');
});

it('el resumen resta los egresos sin que nadie escriba un negativo', function () {
    proyectar($this, 'recaudo_cartera', 100_000_000);
    proyectar($this, 'arriendos', 8_000_000);

    $servicio = app(FlujoDeCaja::class);
    $resumen = $servicio->resumen($servicio->grilla('semana', $this->lunes, 1));

    // 100 de ingreso menos 8 de egreso. El signo lo decide el grupo.
    expect($resumen['proyectado'])->toBe(92_000_000.0);
});

it('el saldo inicial no se suma al flujo: es una foto, no un movimiento', function () {
    proyectar($this, 'saldo_bancos', 500_000_000);

    $servicio = app(FlujoDeCaja::class);
    $resumen = $servicio->resumen($servicio->grilla('semana', $this->lunes, 1));

    expect($resumen['proyectado'])->toBe(0.0);
});

it('un vendedor sin renglones a cargo no entra', function () {
    $v = User::factory()->create();
    $v->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    $this->actingAs($v)->get('/app/tesoreria/flujo-de-caja')->assertForbidden();
});

it('pero quien informa un renglón sí entra, aunque no sea de contabilidad', function () {
    $this->actingAs($this->leidy)->get('/app/tesoreria/flujo-de-caja')->assertOk();
});
