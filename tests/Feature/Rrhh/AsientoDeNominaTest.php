<?php

/**
 * La nómina entra al libro contable.
 *
 * Hasta acá no entraba. El módulo tenía el flujo completo —liquidar, aprobar,
 * pagar, cada mano con su permiso— y no escribía ni una línea en
 * `movimientos_contables`. Se pagaron $5.518.390 de la nómina de octubre y la
 * contabilidad nunca se enteró: ni el gasto de personal, ni los aportes, ni
 * las provisiones, ni la cuenta por pagar laboral. Quien mirara la utilidad
 * del mes la veía más alta de lo real por toda la nómina.
 *
 * ── Lo que estas pruebas defienden ──────────────────────────────────────────
 *
 *  1. **Dos asientos, no uno.** Al aprobar nace la obligación con la gente; al
 *     pagar se cancela contra el banco. Entre los dos momentos el pasivo
 *     existe, y es lo que alguien necesita ver para saber cuánto se debe antes
 *     del día 30.
 *  2. **Contra cuentas que admiten movimiento.** El resto del libro está
 *     asentado contra agrupadoras —104 de 104 líneas— y eso es lo que va a
 *     doler el día que SIIGO exija la cuenta auxiliar. Acá se arranca bien, y
 *     esta prueba es lo que impide que se vuelva a torcer.
 *  3. **No duplica.** Reaprobar o reintentar no puede cobrar dos veces el
 *     gasto de personal del mes.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Nomina;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses()->group('rrhh', 'contabilidad');

/*
 * Montaje propio.
 *
 * Los helpers de `NominaTest` existen sólo cuando ese archivo se carga, y en
 * Pest las funciones son GLOBALES: redefinir `unEmpleadoConAsiento()` acá rompería la
 * suite entera con un «cannot redeclare». Por eso van con nombre propio.
 */
beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->junta = User::factory()->create(['name' => 'Junta Directiva']);
    $this->junta->assignRole(rolParaAsiento('Gerente'));

    $this->silvia = User::factory()->create(['name' => 'Silvia']);
    $this->silvia->assignRole(rolParaAsiento('Contador'));
});

function rolParaAsiento(string $nombre): Role
{
    $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

    $rol->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($nombre, $roles, true))
        ->keys()->flatMap(fn ($s) => Permisos::permisosDe($s))->all());

    return $rol;
}

function unEmpleadoConAsiento(array $extra = []): Empleado
{
    static $n = 0;
    $n++;

    return Empleado::create(array_merge([
        'nombre' => "Trabajador asiento {$n}", 'tipo_documento' => 'CC',
        'numero_documento' => "asi{$n}", 'cargo' => 'Auxiliar', 'area' => 'Logística',
        'tipo_contrato' => 'indefinido', 'salario' => 1_500_000,
        'fecha_ingreso' => '2026-01-15', 'estado' => 'activo',
        'estado_induccion' => 'completada',
    ], $extra));
}

function liquidarParaAsiento(string $periodo = '2026-10')
{
    return test()->actingAs(test()->elena)
        ->post('/app/rrhh/nomina/liquidar', ['periodo' => $periodo]);
}

function laNominaDelAsiento(string $periodo = '2026-10'): Nomina
{
    return Nomina::with('lineas')->where('periodo', $periodo)->firstOrFail();
}

/** El total movido en una cuenta por los asientos de esta nómina. */
function enCuenta(Nomina $n, string $regla, string $lado): float
{
    return (float) MovimientoContable::query()
        ->where('origen_type', Nomina::class)
        ->where('origen_id', $n->id)
        ->where('cuenta_puc', (string) setting('contable.'.$regla))
        ->sum($lado);
}

function asientosDe(Nomina $n)
{
    return MovimientoContable::where('origen_type', Nomina::class)
        ->where('origen_id', $n->id)->get();
}

// ── Que exista, para empezar ────────────────────────────────────────────────

it('aprobar la nómina la mete al libro', function () {
    unEmpleadoConAsiento();
    liquidarParaAsiento();
    $n = laNominaDelAsiento();

    expect(asientosDe($n))->toHaveCount(0);

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");

    expect(asientosDe($n->fresh()))->not->toHaveCount(0);
});

it('el asiento cuadra al centavo', function () {
    unEmpleadoConAsiento();
    unEmpleadoConAsiento(['salario' => 3_200_000]);
    liquidarParaAsiento();
    $n = laNominaDelAsiento();

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");

    $movs = asientosDe($n);
    expect(round($movs->sum('debe') - $movs->sum('haber'), 2))->toBe(0.0);
});

// ── Contra qué cuentas ──────────────────────────────────────────────────────

it('no asienta contra cuentas que el plan prohíbe mover', function () {
    /*
     * Es el defecto que ya tiene el resto del libro. El plan marca las cuentas
     * agrupadoras con `permite_movimiento = 0` y la migración que lo creó lo
     * dice en la columna: «Solo cuentas de detalle (hoja) reciben asientos».
     * Nadie lo verificaba.
     */
    unEmpleadoConAsiento();
    liquidarParaAsiento();
    $n = laNominaDelAsiento();

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");
    $this->actingAs($this->silvia)->post("/app/rrhh/nomina/{$n->id}/pagar", [
        'fecha_pago' => '2026-10-30',
    ]);

    $agrupadoras = asientosDe($n->fresh())
        ->map(fn ($m) => $m->cuenta_puc)
        ->unique()
        ->filter(fn ($codigo) => DB::table('plan_cuentas')
            ->where('codigo', $codigo)
            ->where('permite_movimiento', 0)
            ->exists())
        ->values()
        ->all();

    expect($agrupadoras)->toBe([]);
});

// ── Qué dice cada línea ─────────────────────────────────────────────────────

it('lo que se le consigna a la gente queda como salarios por pagar', function () {
    unEmpleadoConAsiento();
    unEmpleadoConAsiento(['salario' => 2_400_000]);
    liquidarParaAsiento();
    $n = laNominaDelAsiento();

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");

    $neto = round((float) $n->fresh()->lineas()->sum('neto'), 2);

    expect(enCuenta($n, 'cta_nomina_salarios_por_pagar', 'haber'))->toBe($neto);
});

it('la salud junta lo del empleado con lo de la empresa', function () {
    /*
     * A la EPS se le gira todo en la misma planilla. Separarlo en el libro
     * obligaría a sumarlo otra vez para poder pagar.
     */
    unEmpleadoConAsiento();
    liquidarParaAsiento();
    $n = laNominaDelAsiento();

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");

    $linea = $n->fresh()->lineas()->first();
    $esperado = round((float) $linea->deduccion_salud + (float) $linea->patronal_salud, 2);

    expect(enCuenta($n, 'cta_nomina_por_pagar_salud', 'haber'))->toBe($esperado)
        ->and($esperado)->toBeGreaterThan(0.0);
});

it('lo que pone la empresa es gasto, y no se le descuenta a nadie', function () {
    unEmpleadoConAsiento();
    liquidarParaAsiento();
    $n = laNominaDelAsiento();

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");

    $linea = $n->fresh()->lineas()->first();

    // La ARL la paga entera la empresa: es gasto, y el empleado no aporta.
    expect(enCuenta($n, 'cta_nomina_gasto_arl', 'debe'))
        ->toBe(round((float) $linea->patronal_arl, 2))
        ->and(enCuenta($n, 'cta_nomina_por_pagar_arl', 'haber'))
        ->toBe(round((float) $linea->patronal_arl, 2));
});

it('las provisiones quedan como pasivo, no como pago', function () {
    unEmpleadoConAsiento();
    liquidarParaAsiento();
    $n = laNominaDelAsiento();

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");

    $linea = $n->fresh()->lineas()->first();

    foreach ([
        'cesantias' => 'provision_cesantias',
        'prima' => 'provision_prima',
        'vacaciones' => 'provision_vacaciones',
    ] as $concepto => $columna) {
        $monto = round((float) $linea->{$columna}, 2);

        expect($monto)->toBeGreaterThan(0.0, "la provisión de {$concepto} salió en cero")
            ->and(enCuenta($n, "cta_nomina_gasto_{$concepto}", 'debe'))->toBe($monto)
            ->and(enCuenta($n, "cta_nomina_provision_{$concepto}", 'haber'))->toBe($monto);
    }
});

// ── El pago ─────────────────────────────────────────────────────────────────

it('pagar cancela el pasivo contra el banco', function () {
    unEmpleadoConAsiento();
    liquidarParaAsiento();
    $n = laNominaDelAsiento();

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");
    $this->actingAs($this->silvia)->post("/app/rrhh/nomina/{$n->id}/pagar", [
        'fecha_pago' => '2026-10-30',
    ]);

    $neto = round((float) $n->fresh()->lineas()->sum('neto'), 2);

    // El pasivo nace al aprobar y se cancela al pagar: queda en cero.
    expect(enCuenta($n, 'cta_nomina_salarios_por_pagar', 'debe'))->toBe($neto)
        ->and(enCuenta($n, 'cta_nomina_salarios_por_pagar', 'haber'))->toBe($neto)
        ->and(enCuenta($n, 'cta_bancos_default', 'haber'))->toBe($neto);
});

it('entre aprobar y pagar, el pasivo con la gente se ve', function () {
    /*
     * Es la razón de que sean dos asientos y no uno. Si todo se registrara al
     * consignar, entre el día que gerencia firma y el día que sale la plata la
     * empresa debería una nómina entera y el balance no lo diría.
     */
    unEmpleadoConAsiento();
    liquidarParaAsiento();
    $n = laNominaDelAsiento();

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");

    $neto = round((float) $n->fresh()->lineas()->sum('neto'), 2);
    $saldo = enCuenta($n, 'cta_nomina_salarios_por_pagar', 'haber')
        - enCuenta($n, 'cta_nomina_salarios_por_pagar', 'debe');

    expect($saldo)->toBe($neto);
});

// ── Que no se duplique ──────────────────────────────────────────────────────

it('volver a aprobar no cobra dos veces el mes', function () {
    unEmpleadoConAsiento();
    liquidarParaAsiento();
    $n = laNominaDelAsiento();

    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");
    $cuantos = asientosDe($n->fresh())->count();

    // La segunda aprobación la rechaza el estado, pero aunque la acción se
    // llamara de nuevo —un reintento, un job— no puede duplicar.
    app(\App\Modules\Contabilidad\Actions\RegistrarAsientoNomina::class)
        ->causar($n->fresh());

    expect(asientosDe($n->fresh())->count())->toBe($cuantos);
});

it('el ausentismo baja el gasto en vez de inventar un pasivo', function () {
    /*
     * Lo que no se trabajó no se le debe a nadie: ni al empleado ni a una
     * entidad. Lo que corresponde es que el gasto de sueldos quede por lo
     * realmente causado. Va como línea aparte y no restando del débito para
     * que en el libro se vea cuánto se dejó de pagar por ausencias.
     */
    $e = unEmpleadoConAsiento();
    \App\Modules\Rrhh\Models\Novedad::create([
        'empleado_id' => $e->id,
        'periodo' => '2026-10',
        'tipo' => \App\Modules\Rrhh\Enums\TipoNovedad::Incapacidad,
        'clase' => 'EG',
        'fecha_inicio' => '2026-10-05',
        'fecha_fin' => '2026-10-07',
        'dias' => 3,
        'descuento' => 150_000,
    ]);

    liquidarParaAsiento();
    $n = laNominaDelAsiento();
    $this->actingAs($this->junta)->post("/app/rrhh/nomina/{$n->id}/aprobar");

    expect(enCuenta($n, 'cta_nomina_sueldos', 'haber'))->toBe(150_000.0);
});
