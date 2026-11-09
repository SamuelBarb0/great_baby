<?php

/**
 * Fase 3 en el panel de Dropi: gastos y asientos manuales.
 *
 *  · Un gasto pedido desde Dropi nace en la línea Dropi (antes /app los creaba
 *    todos en Great Baby), y al aprobarlo y pagarlo su asiento sale en Dropi.
 *  · Un asiento manual de Dropi escribe el libro en Dropi. Antes no tenía
 *    línea y sus líneas caían en Great Baby; los de /app siguen ahí.
 *  · Las reglas son las de /app: quien crea un asiento no lo aprueba, sólo
 *    cuentas auxiliares, tiene que cuadrar.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Actions\AsientosManuales;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Contabilidad\Models\AsientoManual;
use App\Modules\Contabilidad\Models\PlanCuenta;
use App\Modules\Dropi\Filament\Pages\AsientosManualesDropi;
use App\Modules\Dropi\Filament\Pages\GastosDropi;
use App\Modules\Gerencia\Models\GastoOperativo;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function rolDeLaMatriz(string $rol): Role
{
    $r = Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
    $r->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($rol, $roles, true))
        ->keys()->flatMap(fn ($s) => Permisos::permisosDe($s))->all());

    return $r;
}

beforeEach(function () {
    Queue::fake();
    \App\Support\Reglas::limpiarRequestCache();
    $this->seed(Database\Seeders\PermisosSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->deshacerRepeater = Repeater::fake();

    $this->aracely = User::factory()->create(['name' => 'Aracely']);
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->contador = User::factory()->create(['name' => 'Contadora']);
    $this->contador->assignRole(rolDeLaMatriz('Contador'));

    foreach ([[cta('cta_gasto_default'), 'Diversos'], [cta('cta_gastos_por_pagar'), 'Costos y gastos por pagar'],
              [cta('cta_caja_default'), 'Caja'], [cta('cta_bancos_default'), 'Bancos']] as [$codigo, $nombre]) {
        PlanCuenta::firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre, 'nivel' => strlen($codigo) / 2, 'activa' => true, 'permite_movimiento' => true]);
    }
    PlanCuenta::create(['codigo' => '1110', 'nombre' => 'Bancos agrupadora', 'clase' => '1', 'nivel' => 4, 'naturaleza' => 'debito', 'permite_movimiento' => false, 'activa' => true]);
    PlanCuenta::firstOrCreate(['codigo' => '519595'], ['nombre' => 'Otros gastos', 'clase' => '5', 'nivel' => 6, 'naturaleza' => 'debito', 'permite_movimiento' => true, 'activa' => true]);
    PlanCuenta::firstOrCreate(['codigo' => '111005'], ['nombre' => 'Moneda nacional', 'clase' => '1', 'nivel' => 6, 'naturaleza' => 'debito', 'permite_movimiento' => true, 'activa' => true]);
});

afterEach(fn () => ($this->deshacerRepeater)());

function lineasDelLibro(string $tipo, int $id)
{
    return MovimientoContable::where('origen_type', $tipo)->where('origen_id', $id)->get();
}

it('un gasto pedido desde Dropi nace en Dropi y su asiento sale en Dropi al aprobarlo y pagarlo', function () {
    $this->actingAs($this->contador);
    Livewire::test(GastosDropi::class)
        ->callAction('nuevo', [
            'fecha' => now()->toDateString(), 'categoria' => 'otros', 'descripcion' => 'Bolsas de empaque',
            'monto' => 85_000, 'proveedor' => 'Plásticos SAS', 'factura_ref' => 'FE-77', 'metodo_pago' => 'transferencia', 'tipo' => 'gasto',
        ])
        ->assertHasNoActionErrors();

    $g = GastoOperativo::latest('id')->firstOrFail();
    expect($g->linea_negocio)->toBe(LineaNegocio::Dropi)
        ->and($g->estado)->toBe('pendiente')
        ->and($g->solicita_id)->toBe($this->contador->id);

    // La contadora no aprueba (es de Aracely o el Gerente): ni ve el botón.
    Livewire::test(GastosDropi::class)->assertTableActionHidden('aprobar', $g);

    $this->actingAs($this->aracely);
    Livewire::test(GastosDropi::class)->callTableAction('aprobar', $g)->assertHasNoTableActionErrors();
    expect($g->fresh()->estado)->toBe('aprobado');

    Livewire::test(GastosDropi::class)->callTableAction('pagar', $g)->assertHasNoTableActionErrors();
    expect($g->fresh()->estado)->toBe('pagado');

    $libro = lineasDelLibro(GastoOperativo::class, $g->id);
    expect($libro)->not->toBeEmpty()
        ->and($libro->every(fn ($m) => $m->linea_negocio === LineaNegocio::Dropi))->toBeTrue();
});

it('rechazar un gasto pide motivo', function () {
    $this->actingAs($this->aracely);
    $g = \App\Modules\Contabilidad\Actions\Gastos::crear([
        'fecha' => now()->toDateString(), 'categoria' => 'otros', 'descripcion' => 'x', 'monto' => 1000,
        'metodo_pago' => 'transferencia', 'tipo' => 'gasto',
    ], $this->contador, LineaNegocio::Dropi);

    Livewire::test(GastosDropi::class)->callTableAction('rechazar', $g, ['motivo' => 'corto'])->assertHasTableActionErrors(['motivo']);
    Livewire::test(GastosDropi::class)->callTableAction('rechazar', $g, ['motivo' => 'No corresponde a Dropi'])->assertHasNoTableActionErrors();
    expect($g->fresh()->estado)->toBe('rechazado');
});

it('el panel de Dropi no muestra los gastos de Great Baby', function () {
    $datos = ['fecha' => now()->toDateString(), 'categoria' => 'otros', 'descripcion' => 'x', 'monto' => 1000, 'metodo_pago' => 'transferencia', 'tipo' => 'gasto'];
    $gb = \App\Modules\Contabilidad\Actions\Gastos::crear($datos, $this->contador);
    $dr = \App\Modules\Contabilidad\Actions\Gastos::crear($datos, $this->contador, LineaNegocio::Dropi);
    expect($gb->linea_negocio)->toBe(LineaNegocio::GreatBaby);

    $this->actingAs($this->contador);
    Livewire::test(GastosDropi::class)->assertCanSeeTableRecords([$dr])->assertCanNotSeeTableRecords([$gb]);
});

it('un asiento manual de Dropi escribe el libro en Dropi; los de /app siguen en Great Baby', function () {
    $this->actingAs($this->contador);
    Livewire::test(AsientosManualesDropi::class)
        ->callAction('nuevo', [
            'fecha' => now()->toDateString(), 'glosa' => 'Ajuste de fletes de Dropi del mes',
            'lineas' => [
                ['cuenta_puc' => '519595', 'descripcion' => 'Fletes', 'debe' => 40_000, 'haber' => null],
                ['cuenta_puc' => '111005', 'descripcion' => 'Banco', 'debe' => null, 'haber' => 40_000],
            ],
        ])
        ->assertHasNoActionErrors();

    $a = AsientoManual::latest('id')->firstOrFail();
    expect($a->linea_negocio)->toBe(LineaNegocio::Dropi)
        ->and($a->estado)->toBe('cuadrado')
        ->and(lineasDelLibro(AsientoManual::class, $a->id)->pluck('linea_negocio')->unique()->values()->all())->toBe([LineaNegocio::Dropi]);

    // El mismo asiento por /app sigue siendo de Great Baby.
    $this->post('/app/contabilidad/asientos-manuales', [
        'fecha' => now()->toDateString(), 'glosa' => 'Ajuste de Great Baby del mes',
        'lineas' => [
            ['cuenta_puc' => '519595', 'descripcion' => 'Fletes', 'debe' => 10_000],
            ['cuenta_puc' => '111005', 'descripcion' => 'Banco', 'haber' => 10_000],
        ],
    ])->assertRedirect();
    $gb = AsientoManual::latest('id')->firstOrFail();
    expect($gb->linea_negocio)->toBe(LineaNegocio::GreatBaby)
        ->and(lineasDelLibro(AsientoManual::class, $gb->id)->pluck('linea_negocio')->unique()->values()->all())->toBe([LineaNegocio::GreatBaby]);

    // Y la pantalla de Dropi sólo muestra el suyo.
    Livewire::test(AsientosManualesDropi::class)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$gb]);
});

it('un asiento que no cuadra o usa una agrupadora no se guarda', function () {
    $this->actingAs($this->contador);
    Livewire::test(AsientosManualesDropi::class)
        ->callAction('nuevo', [
            'fecha' => now()->toDateString(), 'glosa' => 'Ajuste que no cuadra para nada',
            'lineas' => [
                ['cuenta_puc' => '519595', 'descripcion' => 'a', 'debe' => 40_000, 'haber' => null],
                ['cuenta_puc' => '111005', 'descripcion' => 'b', 'debe' => null, 'haber' => 30_000],
            ],
        ])
        ->assertNotified('No se guardó');

    Livewire::test(AsientosManualesDropi::class)
        ->callAction('nuevo', [
            'fecha' => now()->toDateString(), 'glosa' => 'Ajuste contra la agrupadora de bancos',
            'lineas' => [
                ['cuenta_puc' => '519595', 'descripcion' => 'a', 'debe' => 40_000, 'haber' => null],
                ['cuenta_puc' => '1110', 'descripcion' => 'b', 'debe' => null, 'haber' => 40_000],
            ],
        ])
        ->assertHasActionErrors(['lineas.1.cuenta_puc']);

    expect(AsientoManual::count())->toBe(0);
});

it('quien crea el asiento no lo aprueba; otra persona sí, y anular saca sus líneas del balance', function () {
    $a = AsientosManuales::crear([
        'fecha' => now()->toDateString(), 'glosa' => 'Ajuste de prueba de Dropi',
        'lineas' => [
            ['cuenta_puc' => '519595', 'descripcion' => 'a', 'debe' => 5000],
            ['cuenta_puc' => '111005', 'descripcion' => 'b', 'haber' => 5000],
        ],
    ], $this->contador, LineaNegocio::Dropi);

    $this->actingAs($this->contador);
    Livewire::test(AsientosManualesDropi::class)->assertTableActionHidden('aprobar', $a);

    $this->actingAs($this->aracely);
    Livewire::test(AsientosManualesDropi::class)->callTableAction('aprobar', $a)->assertHasNoTableActionErrors();
    expect($a->fresh()->estado)->toBe('aprobado');

    Livewire::test(AsientosManualesDropi::class)->callTableAction('anular', $a, ['motivo' => 'Cuenta de gasto equivocada'])->assertHasNoTableActionErrors();
    expect($a->fresh()->estado)->toBe('anulado')
        ->and(lineasDelLibro(AsientoManual::class, $a->id))->toBeEmpty();
});
