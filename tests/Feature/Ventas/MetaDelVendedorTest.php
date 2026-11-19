<?php

/**
 * El vendedor tiene que saber contra qué se mide, y a tiempo.
 *
 * El panel mostraba lo vendido y nada contra qué compararlo: «$4.120.000 este
 * mes» no dice si va bien o mal. El vendedor se enteraba en la reunión de fin
 * de mes, que es cuando ya no se puede hacer nada —si faltaban dos millones,
 * el día 12 había tiempo de salir a buscarlos y el día 30 no.
 *
 * Lo que se protege acá:
 *   · que el número que se muestra sea el RITMO y no sólo el porcentaje: ir al
 *     60% el día 12 y el día 28 son dos situaciones distintas;
 *   · que los días que faltan sean HÁBILES, porque un vendedor B2B no factura
 *     en domingo y decirle que le quedan cuatro días cuando dos son fin de
 *     semana le hace creer que tiene el doble de margen;
 *   · que la meta vaya por mes y no se sobreescriba, porque eso borraría la
 *     historia del cumplimiento;
 *   · que una meta en cero NO sea una meta, porque cumpliría siempre al 100%
 *     y el tablero mostraría a todo el mundo en verde;
 *   · que la ponga Gerencia: una meta que se pone uno mismo no es una meta.
 */

use App\Models\Contacto;
use App\Models\MetaVendedor;
use App\Models\User;
use App\Modules\Crm\Actions\CumplimientoDeMeta;
use App\Modules\Portal\Models\PedidoCliente;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
    $this->seed(\Database\Seeders\PermisosSeeder::class);

    $rol = Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']);
    $this->jefa = User::factory()->create();
    $this->jefa->assignRole($rol);

    /*
     * Con acceso real de vendedor: sin el permiso, `/app/vendedor` responde
     * 403 y el test «falla» por la puerta, no por la meta.
     */
    $rolVendedor = Role::firstOrCreate(['name' => 'VendedorMeta', 'guard_name' => 'web']);
    $rolVendedor->givePermissionTo(['ver.vendedor', 'crear.vendedor', 'editar.vendedor']);

    $this->carlos = User::factory()->create(['name' => 'Carlos Vendedor']);
    $this->carlos->assignRole($rolVendedor);

    $this->cliente = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900989898',
        'nombre_completo' => 'Pañalera con Meta', 'es_cliente' => true,
    ]);
});

/** Una venta efectiva: facturada dentro del mes. */
function ventaDe(float $monto, ?int $vendedorId = null, ?string $cuando = null): PedidoCliente
{
    static $n = 0;
    $n++;

    $fecha = $cuando ?? now()->toDateTimeString();

    return PedidoCliente::create([
        'numero' => 'PB-META-'.$n,
        'contacto_id' => test()->cliente->id,
        'vendedor_id' => $vendedorId ?? test()->carlos->id,
        'estado' => 'facturado',
        'facturado_at' => $fecha,
        'subtotal' => $monto, 'iva' => 0, 'total' => $monto,
    ]);
}

function metaDe(float $monto, ?int $vendedorId = null): MetaVendedor
{
    return MetaVendedor::create([
        'user_id' => $vendedorId ?? test()->carlos->id,
        'anio' => (int) now()->year, 'mes' => (int) now()->month,
        'monto' => $monto,
    ]);
}

it('sin meta no se inventa una: se dice que falta', function () {
    ventaDe(1000000);

    $c = CumplimientoDeMeta::run($this->carlos->id);

    expect($c['tiene_meta'])->toBeFalse()
        ->and($c['cumplimiento'])->toBeNull()
        ->and($c['vendido'])->toBe(1000000.0);
});

it('mide lo vendido contra la meta del mes', function () {
    metaDe(10000000);
    ventaDe(6000000);

    $c = CumplimientoDeMeta::run($this->carlos->id);

    expect($c['cumplimiento'])->toBe(60.0)
        ->and($c['falta'])->toBe(4000000.0);
});

it('no cuenta la venta de otro vendedor', function () {
    $otra = User::factory()->create();
    metaDe(10000000);
    ventaDe(6000000, $this->carlos->id);
    ventaDe(9000000, $otra->id);

    expect(CumplimientoDeMeta::run($this->carlos->id)['vendido'])->toBe(6000000.0);
});

it('no cuenta la venta de otro mes', function () {
    metaDe(10000000);
    ventaDe(6000000);
    ventaDe(5000000, null, now()->subMonth()->toDateTimeString());

    expect(CumplimientoDeMeta::run($this->carlos->id)['vendido'])->toBe(6000000.0);
});

it('dice cuánto hay que vender por día hábil para llegar', function () {
    // A mitad de un mes conocido, para que los días hábiles sean estables.
    $this->travelTo(Illuminate\Support\Carbon::create(2026, 6, 15, 9));

    metaDe(10000000);   // ya viajamos: queda en junio 2026
    ventaDe(4000000, null, Illuminate\Support\Carbon::create(2026, 6, 10)->toDateTimeString());

    $c = CumplimientoDeMeta::run($this->carlos->id);

    // Del 15 al 30 de junio de 2026 hay 12 días hábiles contando hoy.
    expect($c['dias_restantes'])->toBe(12)
        ->and($c['falta'])->toBe(6000000.0)
        ->and($c['ritmo_necesario'])->toBe(500000.0);
});

it('los días que faltan son hábiles, no corridos', function () {
    // Viernes 26 de junio de 2026: quedan viernes, lunes, martes = 3 hábiles,
    // aunque falten 5 días corridos.
    $this->travelTo(Illuminate\Support\Carbon::create(2026, 6, 26, 9));

    metaDe(1000000);

    // Decirle que le quedan 5 días cuando dos son fin de semana le hace creer
    // que tiene casi el doble de margen.
    expect(CumplimientoDeMeta::run($this->carlos->id)['dias_restantes'])->toBe(3);
});

it('ir al 60% no significa lo mismo el día 12 que el día 28', function () {
    // La meta va con el anio y el mes del periodo que se va a mirar, no con
    // la fecha real del dia en que corre el test.
    MetaVendedor::create([
        'user_id' => $this->carlos->id, 'anio' => 2026, 'mes' => 6, 'monto' => 10000000,
    ]);
    ventaDe(6000000, null, Illuminate\Support\Carbon::create(2026, 6, 5)->toDateTimeString());

    $this->travelTo(Illuminate\Support\Carbon::create(2026, 6, 10, 9));
    $temprano = CumplimientoDeMeta::run($this->carlos->id, 2026, 6);

    $this->travelTo(Illuminate\Support\Carbon::create(2026, 6, 29, 9));
    $tarde = CumplimientoDeMeta::run($this->carlos->id, 2026, 6);

    expect($temprano['cumplimiento'])->toBe(60.0)
        ->and($tarde['cumplimiento'])->toBe(60.0)
        // El mismo porcentaje, dos situaciones distintas.
        ->and($temprano['va_en_ritmo'])->toBeTrue()
        ->and($tarde['va_en_ritmo'])->toBeFalse();
});

it('con el mes terminado no hay ritmo que calcular', function () {
    metaDe(10000000);
    ventaDe(4000000, null, now()->startOfMonth()->addDay()->toDateTimeString());

    $this->travelTo(now()->endOfMonth()->addDays(2));

    $c = CumplimientoDeMeta::run($this->carlos->id, (int) now()->subMonth()->year, (int) now()->subMonth()->month);

    // Dividir entre cero daría infinito, y mostrarlo es peor que no mostrar nada.
    expect($c['dias_restantes'])->toBe(0)
        ->and($c['ritmo_necesario'])->toBeNull();
});

it('cumplida no deja saldo pendiente', function () {
    metaDe(5000000);
    ventaDe(6000000);

    $c = CumplimientoDeMeta::run($this->carlos->id);

    expect($c['cumplimiento'])->toBe(120.0)
        ->and($c['falta'])->toBe(0.0)
        ->and($c['ritmo_necesario'])->toBeNull();
});

it('la meta va por mes: la de junio no es la de julio', function () {
    MetaVendedor::create(['user_id' => $this->carlos->id, 'anio' => 2026, 'mes' => 6, 'monto' => 5000000]);
    MetaVendedor::create(['user_id' => $this->carlos->id, 'anio' => 2026, 'mes' => 7, 'monto' => 9000000]);

    expect(CumplimientoDeMeta::run($this->carlos->id, 2026, 6)['meta'])->toBe(5000000.0)
        ->and(CumplimientoDeMeta::run($this->carlos->id, 2026, 7)['meta'])->toBe(9000000.0);
});

it('guardar una meta en blanco la quita, no la deja en cero', function () {
    metaDe(5000000);

    $this->actingAs($this->jefa)->post('/app/vendedor/metas', [
        'anio' => (int) now()->year, 'mes' => (int) now()->month,
        'metas' => [['user_id' => $this->carlos->id, 'monto' => 0]],
    ])->assertRedirect();

    // Una meta de $0 cumpliría siempre al 100% y el tablero pintaría a todo
    // el mundo en verde.
    expect(MetaVendedor::count())->toBe(0)
        ->and(CumplimientoDeMeta::run($this->carlos->id)['tiene_meta'])->toBeFalse();
});

it('guardar dos veces el mismo mes actualiza, no duplica', function () {
    $this->actingAs($this->jefa)->post('/app/vendedor/metas', [
        'anio' => (int) now()->year, 'mes' => (int) now()->month,
        'metas' => [['user_id' => $this->carlos->id, 'monto' => 5000000]],
    ]);
    $this->actingAs($this->jefa)->post('/app/vendedor/metas', [
        'anio' => (int) now()->year, 'mes' => (int) now()->month,
        'metas' => [['user_id' => $this->carlos->id, 'monto' => 7000000]],
    ]);

    expect(MetaVendedor::count())->toBe(1)
        ->and((float) MetaVendedor::first()->monto)->toBe(7000000.0);
});

it('copiar del mes anterior no pisa lo que ya se definió', function () {
    $anterior = now()->subMonth();
    $otro = User::factory()->create();

    MetaVendedor::create(['user_id' => $this->carlos->id, 'anio' => (int) $anterior->year, 'mes' => (int) $anterior->month, 'monto' => 5000000]);
    MetaVendedor::create(['user_id' => $otro->id, 'anio' => (int) $anterior->year, 'mes' => (int) $anterior->month, 'monto' => 3000000]);

    // A Carlos ya se le puso una distinta para este mes: tenía una razón.
    metaDe(8000000);

    $this->actingAs($this->jefa)->post('/app/vendedor/metas/copiar', [
        'anio' => (int) now()->year, 'mes' => (int) now()->month,
    ])->assertRedirect();

    expect((float) MetaVendedor::para($this->carlos->id, (int) now()->year, (int) now()->month)->monto)->toBe(8000000.0)
        ->and((float) MetaVendedor::para($otro->id, (int) now()->year, (int) now()->month)->monto)->toBe(3000000.0);
});

it('un vendedor no se pone su propia meta', function () {
    $this->actingAs($this->carlos)
        ->get('/app/vendedor/metas')
        ->assertForbidden();

    $this->actingAs($this->carlos)
        ->post('/app/vendedor/metas', [
            'anio' => (int) now()->year, 'mes' => (int) now()->month,
            'metas' => [['user_id' => $this->carlos->id, 'monto' => 1]],
        ])
        ->assertForbidden();

    expect(MetaVendedor::count())->toBe(0);
});

it('el panel del vendedor trae su meta', function () {
    metaDe(10000000);
    ventaDe(6000000);

    $meta = $this->actingAs($this->carlos)
        ->get('/app/vendedor')
        ->viewData('page')['props']['meta'];

    expect($meta['tiene_meta'])->toBeTrue()
        ->and($meta['cumplimiento'])->toBe(60.0);
});

it('el avance del mes se calcula sin pasar por un vendedor', function () {
    $this->travelTo(Illuminate\Support\Carbon::create(2026, 6, 15, 9));

    // Pedirlo con un id en cero habría sumado la venta de TODO el equipo:
    // el `when($vendedorId)` del servicio trata el 0 como «sin filtro».
    ventaDe(99999999, $this->carlos->id, Illuminate\Support\Carbon::create(2026, 6, 2)->toDateTimeString());

    $avance = CumplimientoDeMeta::avanceDelMesDe(2026, 6);

    // Junio 2026 tiene 22 días hábiles; del 15 al 30 quedan 12.
    expect($avance)->toBe(round(((22 - 12) / 22) * 100, 1));
});
