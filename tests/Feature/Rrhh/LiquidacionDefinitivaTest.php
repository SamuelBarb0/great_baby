<?php

/**
 * F10 · La liquidación definitiva del contrato.
 *
 * Cuando alguien se iba, el ERP sabía la fecha, el tipo de retiro, la
 * entrevista de salida y la entrega de cargo con sus tres firmas. Lo único que
 * no sabía era **cuánta plata se le debe**, que es justamente lo que la
 * persona está esperando. Se calculaba en un Excel aparte, y un error ahí no
 * se nota hasta que alguien reclama —o hasta que no reclama, que es peor—.
 *
 * ── Lo que estas pruebas defienden ──────────────────────────────────────────
 *
 *  1. **Las dos bases.** Cesantías, intereses y prima se calculan sobre
 *     salario MÁS auxilio de transporte; vacaciones sobre el salario SOLO.
 *     Usar la misma base para las cuatro —que es lo fácil y lo que hace un
 *     Excel a mano— le paga de más o de menos a cada persona que sale.
 *  2. **Lo que no se inventa.** Vacaciones ya tomadas, cesantías ya
 *     consignadas e indemnización no se deducen de nada: se preguntan.
 *  3. **Tres manos**, igual que la nómina.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Liquidacion;
use App\Modules\Rrhh\Support\LiquidacionDefinitiva;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->junta = User::factory()->create(['name' => 'Junta Directiva']);
    $this->junta->assignRole(rolDeLiquidacion('Gerente'));

    $this->silvia = User::factory()->create(['name' => 'Silvia']);
    $this->silvia->assignRole(rolDeLiquidacion('Contador'));

    $this->jorge = User::factory()->create(['name' => 'Jorge Líder']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));
});

function rolDeLiquidacion(string $nombre): Role
{
    $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

    $rol->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($nombre, $roles, true))
        ->keys()->flatMap(fn ($s) => Permisos::permisosDe($s))->all());

    return $rol;
}

function seRetira(array $extra = []): Empleado
{
    static $n = 0;
    $n++;

    return Empleado::create(array_merge([
        'nombre' => "Quien se liquida {$n}", 'tipo_documento' => 'CC', 'numero_documento' => "liq{$n}",
        'cargo' => 'Vendedor', 'area' => 'Comercial', 'tipo_contrato' => 'indefinido',
        'salario' => 1_500_000,
        'fecha_ingreso' => '2026-01-05', 'fecha_retiro' => '2026-06-30',
        'estado' => 'retirado', 'estado_induccion' => 'completada', 'tipo_retiro' => 'renuncia',
    ], $extra));
}

function liquidarContrato(Empleado $e, array $datos = [])
{
    return test()->actingAs(test()->elena)
        ->post("/app/rrhh/empleados/{$e->id}/liquidacion", $datos);
}

// ── Las dos bases ────────────────────────────────────────────────────────────

it('calcula cesantías, intereses y prima sobre salario más auxilio', function () {
    /*
     * Del 05/01 al 30/06 con año comercial de 360 son 176 días.
     * Base: 1.500.000 + 249.095 = 1.749.095.
     */
    $e = seRetira();

    $c = LiquidacionDefinitiva::de($e)->calcular();

    expect($c['dias_cesantias'])->toBe(176)
        ->and($c['cesantias'])->toBe(round(1_749_095 * 176 / 360, 2))
        ->and($c['intereses_cesantias'])->toBe(round($c['cesantias'] * 0.12 * 176 / 360, 2))
        ->and($c['prima'])->toBe(round(1_749_095 * 176 / 360, 2));
});

it('calcula las vacaciones sobre el salario solo, sin auxilio', function () {
    /*
     * El auxilio compensa el transporte AL TRABAJO, y en vacaciones no hay
     * transporte al trabajo. Es el error clásico del Excel hecho a mano.
     */
    $e = seRetira();

    $c = LiquidacionDefinitiva::de($e)->calcular();

    expect($c['vacaciones'])->toBe(round(1_500_000 * 176 / 720, 2))
        // Y si se hubiera usado la base prestacional, daría más.
        ->and($c['vacaciones'])->toBeLessThan(round(1_749_095 * 176 / 720, 2))
        ->and($c['detalle']['vacaciones'])->toContain('sin auxilio');
});

it('a quien no devenga auxilio le liquida todo sobre el salario', function () {
    $e = seRetira(['salario' => 9_000_000]);

    $c = LiquidacionDefinitiva::de($e)->calcular();

    expect($c['auxilio_base'])->toBe(0.0)
        ->and($c['cesantias'])->toBe(round(9_000_000 * 176 / 360, 2));
});

it('la prima sale del semestre y no de todo el año', function () {
    /*
     * La prima se paga por semestre: quien se va en noviembre no cobra prima
     * desde enero, cobra desde julio.
     */
    $e = seRetira(['fecha_ingreso' => '2026-01-05', 'fecha_retiro' => '2026-11-30']);

    $c = LiquidacionDefinitiva::de($e)->calcular();

    /*
     * Prima: del 01/07 al 30/11 son 150 días.
     * Cesantías: del 05/01 —su ingreso, que es posterior al 1 de enero— al
     * 30/11 son 326. No 330: el período arranca en la fecha de ingreso
     * cuando entró ese mismo año.
     */
    expect($c['dias_prima'])->toBe(150)
        ->and($c['dias_cesantias'])->toBe(326)
        ->and($c['detalle']['prima'])->toContain('01/07/2026')
        ->and($c['detalle']['cesantias'])->toContain('05/01/2026');
});

it('las cesantías arrancan en enero, no en el ingreso de hace años', function () {
    /*
     * Lo de años anteriores se consignó al fondo en febrero: no se vuelve a
     * deber. Las vacaciones sí se acumulan desde el ingreso.
     */
    $e = seRetira(['fecha_ingreso' => '2023-03-10', 'fecha_retiro' => '2026-06-30']);

    $c = LiquidacionDefinitiva::de($e)->calcular();

    expect($c['dias_cesantias'])->toBe(180)        // 01/01 a 30/06
        ->and($c['dias_vacaciones'])->toBeGreaterThan(1100); // desde 2023
});

// ── Lo que no se inventa ─────────────────────────────────────────────────────

it('descuenta los días de vacaciones que ya disfrutó', function () {
    $e = seRetira();

    $sin = LiquidacionDefinitiva::de($e)->calcular();
    $con = LiquidacionDefinitiva::de($e)->calcular(['vacaciones_tomadas' => 7]);

    // Cada día hábil disfrutado consume dos días de los 360.
    expect($con['vacaciones'])->toBe(round(1_500_000 * (176 - 14) / 720, 2))
        ->and($con['vacaciones'])->toBeLessThan($sin['vacaciones'])
        ->and($con['detalle']['vacaciones'])->toContain('7 días ya disfrutados');
});

it('resta lo que ya se le consignó al fondo', function () {
    $e = seRetira();

    $c = LiquidacionDefinitiva::de($e)->calcular(['cesantias_ya_pagadas' => 300_000]);

    expect($c['total_descuentos'])->toBe(300_000.0)
        ->and($c['neto'])->toBe(round($c['total_a_favor'] - 300_000, 2));
});

it('no acepta indemnización sin decir por qué', function () {
    /*
     * Una indemnización sin concepto es plata sin explicación, y es el
     * renglón que más se mira cuando alguien reclama.
     */
    $e = seRetira();

    liquidarContrato($e, ['indemnizacion' => 1_500_000])
        ->assertSessionHasErrors('indemnizacion_concepto');

    liquidarContrato($e, [
        'indemnizacion' => 1_500_000,
        'indemnizacion_concepto' => 'Art. 64 CST · 30 días de salario',
    ])->assertSessionHasNoErrors();

    expect((float) Liquidacion::where('empleado_id', $e->id)->first()->indemnizacion)
        ->toBe(1_500_000.0);
});

it('tampoco acepta un descuento sin concepto', function () {
    $e = seRetira();

    liquidarContrato($e, ['otros_descuentos' => 200_000])
        ->assertSessionHasErrors('otros_descuentos_concepto');
});

it('no liquida a quien todavía trabaja acá', function () {
    $e = seRetira(['fecha_retiro' => null, 'estado' => 'activo']);

    liquidarContrato($e)->assertSessionHasErrors('liquidacion');

    expect(Liquidacion::where('empleado_id', $e->id)->exists())->toBeFalse();
});

// ── El retrato ───────────────────────────────────────────────────────────────

it('no cambia cuando después le corrigen el salario a la ficha', function () {
    $e = seRetira(['salario' => 1_500_000]);

    liquidarContrato($e);
    $antes = (float) Liquidacion::where('empleado_id', $e->id)->first()->neto;

    $e->update(['salario' => 9_000_000, 'cargo' => 'Otro cargo']);

    $liq = Liquidacion::where('empleado_id', $e->id)->firstOrFail();

    expect((float) $liq->neto)->toBe($antes)
        ->and((float) $liq->salario_base)->toBe(1_500_000.0)
        ->and($liq->cargo)->toBe('Vendedor');
});

// ── Las tres manos ───────────────────────────────────────────────────────────

it('la calcula Gestión Humana, la aprueba gerencia y la paga tesorería', function () {
    $e = seRetira();

    // Calcular
    $this->actingAs($this->jorge)
        ->post("/app/rrhh/empleados/{$e->id}/liquidacion")->assertForbidden();
    liquidarContrato($e)->assertRedirect();

    // Aprobar: no quien la calculó.
    $this->actingAs($this->elena)
        ->post("/app/rrhh/empleados/{$e->id}/liquidacion/aprobar")->assertForbidden();
    $this->actingAs($this->junta)
        ->post("/app/rrhh/empleados/{$e->id}/liquidacion/aprobar")->assertRedirect();

    // Pagar: tesorería.
    $this->actingAs($this->elena)
        ->post("/app/rrhh/empleados/{$e->id}/liquidacion/pagar", ['fecha_pago' => '2026-07-05'])
        ->assertForbidden();
    $this->actingAs($this->silvia)
        ->post("/app/rrhh/empleados/{$e->id}/liquidacion/pagar", ['fecha_pago' => '2026-07-05'])
        ->assertRedirect();

    $liq = Liquidacion::where('empleado_id', $e->id)->firstOrFail();

    expect($liq->estado)->toBe(Liquidacion::PAGADA)
        ->and($liq->aprobada_por_id)->toBe($this->junta->id)
        ->and($liq->pagada_por_id)->toBe($this->silvia->id);
});

it('no se paga antes de que gerencia apruebe', function () {
    $e = seRetira();
    liquidarContrato($e);

    $this->actingAs($this->silvia)
        ->post("/app/rrhh/empleados/{$e->id}/liquidacion/pagar", ['fecha_pago' => '2026-07-05'])
        ->assertStatus(422);
});

it('una liquidación aprobada ya no se recalcula', function () {
    $e = seRetira();
    liquidarContrato($e);
    $this->actingAs($this->junta)->post("/app/rrhh/empleados/{$e->id}/liquidacion/aprobar");

    liquidarContrato($e)->assertStatus(422);
});

it('no se aprueba un neto en cero sin que alguien lo explique', function () {
    /*
     * Casi siempre significa que falta un dato. Firmarlo así es dejar a
     * alguien sin su liquidación con la firma de gerencia encima.
     */
    $e = seRetira(['salario' => 0]);
    liquidarContrato($e);

    $this->actingAs($this->junta)
        ->post("/app/rrhh/empleados/{$e->id}/liquidacion/aprobar")
        ->assertSessionHasErrors('aprobar');

    // Con una explicación escrita, sí.
    liquidarContrato($e, ['observaciones' => 'Contrato de aprendizaje sin salario asignado.']);

    $this->actingAs($this->junta)
        ->post("/app/rrhh/empleados/{$e->id}/liquidacion/aprobar")
        ->assertSessionHasNoErrors();
});

// ── El impreso ───────────────────────────────────────────────────────────────

it('el impreso lleva la cuenta y el paz y salvo en el mismo papel', function () {
    /*
     * Juntos a propósito: nadie debería firmar el recibido sin tener el
     * desglose enfrente.
     */
    $e = seRetira();
    liquidarContrato($e, ['vacaciones_tomadas' => 7]);

    $liq = Liquidacion::where('empleado_id', $e->id)->firstOrFail();

    $this->actingAs($this->elena)
        ->get("/app/rrhh/empleados/{$e->id}/liquidacion/pdf")->assertOk();

    $html = view('rrhh.pdf.liquidacion-definitiva', [
        'liq' => $liq, 'empresa' => App\Models\EmpresaConfig::current(), 'generadoPor' => 'Elena',
    ])->render();

    expect($html)
        ->toContain('LIQUIDACIÓN DEFINITIVA DE CONTRATO')
        ->toContain('× 176 ÷ 360')
        ->toContain('7 días ya disfrutados')
        ->toContain('queda a paz y salvo por los conceptos aquí liquidados')
        // Mientras no esté pagada, el papel lo dice.
        ->toContain('Borrador');
});
