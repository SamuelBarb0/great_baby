<?php

/**
 * Un asiento manual equivocado tiene que poder sacarse del libro.
 *
 * La pantalla ofrecía crear y aprobar, nada más. Un asiento mal digitado se
 * quedaba para siempre y la única salida era un segundo asiento contrario,
 * que es correcto en la teoría y en la práctica nadie hace. El 2026-10-29, en
 * producción, un asiento de prueba contra la 1110 —que es agrupadora— dejó
 * saldo en el balance general y hubo que sacarlo por consola.
 *
 * Dos reglas que no son obvias:
 *
 *  · El asiento NO se borra. Queda en la lista marcado `anulado` con su
 *    motivo, porque lo que hay que poder auditar después no es que
 *    desapareciera sino quién lo sacó y por qué. Lo que sale del balance son
 *    sus líneas, borradas suave — que es exactamente lo que el panel contable
 *    ya llama «anulado» en su casilla «Incluir asientos anulados».
 *
 *  · Un asiento que ya viajó a SIIGO no se anula de este lado. SIIGO es la
 *    contabilidad oficial; borrarlo sólo acá deja los dos libros diciendo
 *    cosas distintas y nadie se entera hasta el cierre.
 *
 * Y de paso el seguro que faltaba al crearlo: la cuenta tiene que ADMITIR
 * MOVIMIENTO, no sólo existir. Media tabla del PUC son agrupadoras que
 * cuadran igual y vuelven rechazadas desde SIIGO.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Models\AsientoManual;
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
        ->keys()->flatMap(fn ($s) => Permisos::permisosDe($s))->all());

    $this->silvia = User::factory()->create(['name' => 'Silvia Contadora']);
    $this->silvia->assignRole($contador);

    // La agrupadora y sus dos auxiliares, como en el PUC de verdad.
    PlanCuenta::create(['codigo' => '1110', 'nombre' => 'Bancos', 'clase' => '1',
        'nivel' => 4, 'naturaleza' => 'debito', 'permite_movimiento' => false, 'activa' => true]);
    PlanCuenta::create(['codigo' => '111005', 'nombre' => 'Moneda nacional', 'clase' => '1',
        'nivel' => 6, 'naturaleza' => 'debito', 'permite_movimiento' => true, 'activa' => true]);
    PlanCuenta::create(['codigo' => '11050501', 'nombre' => 'Caja general', 'clase' => '1',
        'nivel' => 5, 'naturaleza' => 'debito', 'permite_movimiento' => true, 'activa' => true]);
});

/** Nombre propio: en Pest las funciones del archivo son globales. */
function crearAsientoDePrueba(object $caso, string $cuentaDebe = '11050501', string $cuentaHaber = '111005')
{
    return $caso->actingAs($caso->silvia)->post('/app/contabilidad/asientos-manuales', [
        'fecha' => now()->toDateString(),
        'glosa' => 'Traslado de caja general al banco por cierre',
        'lineas' => [
            ['cuenta_puc' => $cuentaDebe, 'debe' => 200000, 'haber' => 0, 'descripcion' => 'Sale de caja'],
            ['cuenta_puc' => $cuentaHaber, 'debe' => 0, 'haber' => 200000, 'descripcion' => 'Entra al banco'],
        ],
    ]);
}

function lineasVivasDelAsiento(AsientoManual $a): int
{
    return MovimientoContable::where('origen_type', AsientoManual::class)
        ->where('origen_id', $a->id)->count();
}

// ───────────────────── El seguro al crear ─────────────────────

it('no se puede asentar contra una cuenta que no admite movimiento', function () {
    crearAsientoDePrueba($this, '1110', '11050501')
        ->assertSessionHasErrors('lineas.0.cuenta_puc');

    // Lo que importa: no quedó nada en el libro. Antes esto entraba con 200 OK.
    expect(AsientoManual::count())->toBe(0)
        ->and(MovimientoContable::count())->toBe(0);
});

it('el mensaje explica que hay que usar la auxiliar', function () {
    $errores = crearAsientoDePrueba($this, '1110', '11050501')
        ->assertSessionHasErrors()->getSession()->get('errors');

    expect($errores->first('lineas.0.cuenta_puc'))
        ->toContain('agrupadora')
        ->toContain('auxiliar');
});

it('contra una auxiliar sí entra', function () {
    crearAsientoDePrueba($this);

    expect(AsientoManual::count())->toBe(1)
        ->and(MovimientoContable::count())->toBe(2);
});

// ───────────────────────── Anular ─────────────────────────

it('anular saca las líneas del balance pero deja el asiento a la vista', function () {
    crearAsientoDePrueba($this);
    $asiento = AsientoManual::firstOrFail();

    expect(lineasVivasDelAsiento($asiento))->toBe(2);

    $this->actingAs($this->silvia)
        ->post("/app/contabilidad/asientos-manuales/{$asiento->id}/anular", [
            'motivo' => 'Se tecleo la cuenta del banco que no era.',
        ])->assertRedirect();

    $a = $asiento->fresh();

    expect($a->estado)->toBe('anulado')
        ->and($a->anulado_por)->toBe($this->silvia->id)
        ->and($a->anulado_at)->not->toBeNull()
        ->and($a->motivo_anulacion)->toContain('cuenta del banco')
        // Fuera del balance...
        ->and(lineasVivasDelAsiento($a))->toBe(0)
        // ...pero no borradas: la casilla «Incluir asientos anulados» las trae.
        ->and(MovimientoContable::withTrashed()
            ->where('origen_id', $a->id)->count())->toBe(2);
});

it('anular sin decir por qué no se acepta', function () {
    crearAsientoDePrueba($this);
    $asiento = AsientoManual::firstOrFail();

    $this->actingAs($this->silvia)
        ->post("/app/contabilidad/asientos-manuales/{$asiento->id}/anular", ['motivo' => ''])
        ->assertSessionHasErrors('motivo');

    // Una acción que saca plata del libro sin firma no se puede auditar.
    expect(lineasVivasDelAsiento($asiento))->toBe(2)
        ->and($asiento->fresh()->estado)->toBe('cuadrado');
});

it('un motivo de dos palabras tampoco sirve', function () {
    crearAsientoDePrueba($this);
    $asiento = AsientoManual::firstOrFail();

    $this->actingAs($this->silvia)
        ->post("/app/contabilidad/asientos-manuales/{$asiento->id}/anular", ['motivo' => 'error'])
        ->assertSessionHasErrors('motivo');

    expect(lineasVivasDelAsiento($asiento))->toBe(2);
});

it('un asiento que ya está en SIIGO no se anula de este lado', function () {
    crearAsientoDePrueba($this);
    $asiento = AsientoManual::firstOrFail();
    // Por el camino real: cuadrado → aprobado → sincronizado. La máquina de
    // estados del modelo no admite saltarse el paso.
    $asiento->update(['estado' => 'aprobado']);
    $asiento->update(['estado' => 'sincronizado', 'siigo_journal_id' => 'SIIGO-9981']);

    $this->actingAs($this->silvia)
        ->post("/app/contabilidad/asientos-manuales/{$asiento->id}/anular", [
            'motivo' => 'Nos dimos cuenta tarde del error de cuenta.',
        ])->assertRedirect();

    // Sigue en el libro: la salida es una nota contraria, no un borrado que
    // dejaría a SIIGO y al ERP diciendo cosas distintas.
    expect(lineasVivasDelAsiento($asiento))->toBe(2)
        ->and($asiento->fresh()->anulado_at)->toBeNull();
});

it('anular dos veces no pisa el motivo del primero', function () {
    crearAsientoDePrueba($this);
    $asiento = AsientoManual::firstOrFail();

    $this->actingAs($this->silvia)->post("/app/contabilidad/asientos-manuales/{$asiento->id}/anular", [
        'motivo' => 'El primero, que es el que vale.',
    ]);
    $this->actingAs($this->silvia)->post("/app/contabilidad/asientos-manuales/{$asiento->id}/anular", [
        'motivo' => 'El segundo, que no deberia entrar.',
    ]);

    expect($asiento->fresh()->motivo_anulacion)->toContain('el que vale');
});

it('la pantalla ya no ofrece cuentas agrupadoras', function () {
    $cuentas = $this->actingAs($this->silvia)->get('/app/contabilidad/asientos-manuales')
        ->assertOk()->viewData('page')['props']['cuentas'];

    $codigos = collect($cuentas)->pluck('codigo');

    // Si el selector las sigue ofreciendo, el contador las va a elegir aunque
    // el servidor las rechace, y el error aparece recién al guardar.
    expect($codigos)->not->toContain('1110')
        ->and($codigos)->toContain('111005')
        ->and($codigos)->toContain('11050501');
});
