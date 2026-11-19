<?php

/**
 * Todo lo que espera una firma, en una sola pantalla.
 *
 * «Cosas del día» ya juntaba siete cosas operativas —pedidos sin aprobar,
 * garantías sin decidir, cartera vencida—, pero las aprobaciones de plata
 * quedaban cada una en su propia pantalla: la orden de compra en Compras, el
 * asiento en Contabilidad, el gasto en Gastos, la solicitud de crédito en
 * Cartera y la liquidación de comisión en CRM. Para saber qué estaba frenado
 * había que abrir cinco pantallas y acordarse de las cinco.
 *
 * Y lo que se frena ahí no es un trámite: una orden de compra sin aprobar es
 * mercancía que no se pidió, y una solicitud de crédito sin responder es una
 * venta detenida con un cliente esperando.
 *
 * Lo que se protege acá:
 *   · que estén las cinco fuentes y no cuatro;
 *   · que traiga MONTO y no sólo conteo, porque «3 pendientes» no dice si son
 *     tres gastos de taxi o tres órdenes de importación;
 *   · que lo ya resuelto desaparezca, incluido lo borrado: un gasto borrado
 *     que sigue pidiendo firma hace que la pantalla deje de creerse.
 */

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
    $this->seed(\Database\Seeders\PermisosSeeder::class);

    // La pantalla es del equipo de bodega y de quien manda.
    $rol = Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']);
    $this->jefa = User::factory()->create();
    $this->jefa->assignRole($rol);

    // El payload se cachea 5 minutos: sin esto, el segundo test leería lo que
    // armó el primero.
    /*
     * Terceros reales: varias de estas tablas tienen llaves NOT NULL
     * (proveedor de la OC, cliente de la solicitud, quien pide el gasto).
     */
    $this->tercero = \App\Models\Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '901777888',
        'nombre_completo' => 'Proveedor de Prueba', 'es_proveedor' => true, 'es_cliente' => true,
    ]);

    // El payload se cachea 5 minutos: sin esto, el segundo test leeria lo que
    // armo el primero.
    Cache::forget('cosas-del-dia');
});

/** Una OC en el estado que se pida. */
function firmaOrden(string $numero, string $estado, float $total): void
{
    DB::table('compras_ordenes')->insert([
        'numero' => $numero, 'proveedor_id' => test()->tercero->id,
        'estado' => $estado, 'total' => $total,
        'fecha_emision' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Un gasto operativo, vivo o borrado. */
function firmaGasto(string $numero, float $monto, bool $borrado = false): void
{
    DB::table('gastos_operativos')->insert([
        'numero' => $numero, 'estado' => 'pendiente', 'monto' => $monto,
        'fecha' => now()->toDateString(), 'categoria' => 'servicios',
        'descripcion' => 'Gasto de prueba', 'solicita_id' => test()->jefa->id,
        'deleted_at' => $borrado ? now() : null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function firmaSolicitud(float $monto): void
{
    DB::table('solicitudes_credito')->insert([
        'contacto_id' => test()->tercero->id, 'estado' => 'pendiente',
        'monto_pedido' => $monto, 'motivo_retencion' => 'Cupo excedido',
        'snapshot_credito' => json_encode(['cupo' => 0]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function firmaAsiento(float $valor, string $estado = 'cuadrado'): void
{
    DB::table('asientos_manuales')->insert([
        'fecha' => now()->toDateString(), 'glosa' => 'Ajuste de prueba',
        'estado' => $estado, 'valor_total' => $valor, 'user_id' => test()->jefa->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function firmaComision(float $total): void
{
    DB::table('comisiones_calculadas')->insert([
        'vendedor_id' => test()->jefa->id,
        'anio' => (int) now()->year, 'mes' => (int) now()->month,
        'estado' => 'borrador', 'total_a_pagar' => $total, 'calculada_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function firmasDe($usuario): array
{
    Cache::forget('cosas-del-dia');

    return test()->actingAs($usuario)
        ->get('/app/cosas-del-dia')
        ->viewData('page')['props']['aprobaciones'];
}

function grupoDe(array $aprobaciones, string $clave): ?array
{
    return collect($aprobaciones['grupos'])->firstWhere('clave', $clave);
}

it('junta las cinco fuentes que antes vivían en cinco pantallas', function () {
    firmaOrden('OC-T1', 'borrador', 5000000);
    firmaGasto('G-T1', 120000);
    firmaSolicitud(3000000);
    firmaAsiento(800000);
    firmaComision(400000);

    $f = firmasDe($this->jefa);

    expect(collect($f['grupos'])->pluck('clave')->sort()->values()->all())
        ->toBe(['asientos', 'comisiones', 'credito', 'gastos', 'ordenes_compra'])
        ->and($f['total'])->toBe(5)
        ->and($f['monto'])->toBe(9320000.0);
});

it('trae monto y no sólo conteo', function () {
    firmaOrden('OC-T2', 'borrador', 1000000);
    firmaOrden('OC-T3', 'borrador', 4000000);

    $g = grupoDe(firmasDe($this->jefa), 'ordenes_compra');

    // «2 pendientes» no dice si son dos gastos de taxi o dos importaciones.
    expect($g['cantidad'])->toBe(2)
        ->and($g['monto'])->toBe(5000000.0);
});

it('lo que ya se aprobó deja de pedir firma', function () {
    firmaOrden('OC-T4', 'borrador', 1000000);
    firmaOrden('OC-T5', 'aprobada', 9000000);

    $g = grupoDe(firmasDe($this->jefa), 'ordenes_compra');

    expect($g['cantidad'])->toBe(1)
        ->and($g['monto'])->toBe(1000000.0);
});

it('un gasto borrado no sigue pidiendo firma', function () {
    firmaGasto('G-T2', 50000);
    firmaGasto('G-T3', 70000, borrado: true);

    // Con `DB::table` el borrado suave no se aplica solo.
    $g = grupoDe(firmasDe($this->jefa), 'gastos');

    expect($g['cantidad'])->toBe(1)
        ->and($g['monto'])->toBe(50000.0);
});

it('sin nada pendiente no se muestran grupos vacíos', function () {
    $f = firmasDe($this->jefa);

    // Cinco tarjetas en cero son cinco cosas que leer para no hacer nada.
    expect($f['grupos'])->toBe([])
        ->and($f['total'])->toBe(0);
});

it('la solicitud de crédito trae el monto del pedido que está deteniendo', function () {
    firmaSolicitud(2500000);

    $g = grupoDe(firmasDe($this->jefa), 'credito');

    // Del otro lado hay una venta parada y un cliente esperando.
    expect($g['monto'])->toBe(2500000.0)
        ->and($g['url'])->toBe('/app/cartera/solicitudes');
});

it('cada grupo dice a dónde ir a resolverlo', function () {
    firmaAsiento(100000);

    $g = grupoDe(firmasDe($this->jefa), 'asientos');

    expect($g['url'])->toBe('/app/contabilidad/asientos-manuales')
        ->and($g['detalle'])->toContain('SIIGO');
});

it('el asiento que espera firma es el CUADRADO, no el borrador', function () {
    /*
     * El borrador es el asiento que todavía se está escribiendo: no espera
     * nada de nadie. El que pide firma es el que ya cuadró —debe igual a
     * haber— y espera el «aprobar» para salir a SIIGO. Con la consulta vieja
     * las aprobaciones contables no aparecían NUNCA acá.
     */
    firmaAsiento(300000, 'borrador');
    firmaAsiento(800000, 'cuadrado');

    $g = grupoDe(firmasDe($this->jefa), 'asientos');

    expect($g['cantidad'])->toBe(1)
        ->and($g['monto'])->toBe(800000.0);
});

it('la orden ya enviada al proveedor sigue esperando firma', function () {
    firmaOrden('OC-T6', 'borrador', 1000000);
    firmaOrden('OC-T7', 'enviada', 2000000);
    firmaOrden('OC-T8', 'aprobada', 9000000);

    // `AprobarOrdenCompra` acepta las dos primeras; sólo se contaba la primera.
    $g = grupoDe(firmasDe($this->jefa), 'ordenes_compra');

    expect($g['cantidad'])->toBe(2)
        ->and($g['monto'])->toBe(3000000.0);
});

it('cada grupo lleva a una pantalla que existe', function () {
    firmaOrden('OC-T9', 'borrador', 1000000);
    firmaGasto('G-T9', 50000);
    firmaSolicitud(900000);
    firmaAsiento(100000, 'cuadrado');
    firmaComision(400000);

    // Una tarjeta que cuenta bien y manda a un 404 no sirve de nada.
    foreach (firmasDe($this->jefa)['grupos'] as $g) {
        $this->actingAs($this->jefa)->get($g['url'])->assertSuccessful();
    }
});

it('cada quien ve sólo los montos de las pantallas que puede abrir', function () {
    firmaOrden('OC-P1', 'borrador', 5000000);
    firmaGasto('G-P1', 120000);
    firmaSolicitud(3000000);
    firmaAsiento(800000, 'cuadrado');
    firmaComision(400000);

    /*
     * La puerta de esta pantalla —«equipo de bodega»— es más ancha que las
     * pantallas a las que mandan estas tarjetas. Un AdminBodega entra acá, y
     * «$836.611 esperando tu firma» ya es el dato aunque el clic después
     * rebote con 403.
     */
    // Entra a la pantalla —tiene acceso a bodega— pero nada mas.
    $rol = Role::firstOrCreate(['name' => 'AdminBodegaTest', 'guard_name' => 'web']);
    $rol->givePermissionTo('ver.bodega');

    $bodega = User::factory()->create();
    $bodega->assignRole($rol);

    $f = firmasDe($bodega);

    expect(collect($f['grupos'])->pluck('clave')->all())
        ->not->toContain('asientos')
        ->not->toContain('comisiones')
        ->not->toContain('gastos')
        ->not->toContain('credito');

    // Quien manda sigue viéndolo todo.
    expect(collect(firmasDe($this->jefa)['grupos'])->pluck('clave')->sort()->values()->all())
        ->toBe(['asientos', 'comisiones', 'credito', 'gastos', 'ordenes_compra']);
});

it('el bloque no se cachea entre usuarios distintos', function () {
    firmaAsiento(800000, 'cuadrado');

    // Entra a la pantalla —tiene acceso a bodega— pero nada mas.
    $rol = Role::firstOrCreate(['name' => 'AdminBodegaTest', 'guard_name' => 'web']);
    $rol->givePermissionTo('ver.bodega');

    $bodega = User::factory()->create();
    $bodega->assignRole($rol);

    /*
     * El caché de «Cosas del día» es uno solo para todo el mundo. Si las
     * aprobaciones entraran ahí, el primero que abriera la pantalla dejaría su
     * versión guardada cinco minutos y el siguiente vería los montos del otro.
     * Por eso acá NO se limpia el caché entre las dos lecturas.
     */
    Cache::forget('cosas-del-dia');

    $deLaJefa = test()->actingAs($this->jefa)
        ->get('/app/cosas-del-dia')->viewData('page')['props']['aprobaciones'];

    $deBodega = test()->actingAs($bodega)
        ->get('/app/cosas-del-dia')->viewData('page')['props']['aprobaciones'];

    expect($deLaJefa['total'])->toBe(1)
        ->and($deBodega['total'])->toBe(0);
});
