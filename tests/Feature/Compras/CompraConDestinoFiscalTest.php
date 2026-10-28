<?php

/**
 * La otra mitad de la bolsita: compras que no se declaran.
 *
 * Hasta hoy los dos ejes sólo existían del lado de las ventas y toda compra
 * caía en «fiscal» por el valor por defecto. El balance interno quedaba con
 * ingresos que no se declaran y ningún costo que no se declara — o sea, con la
 * utilidad inflada, que es justo el número que alguien va a mirar para decidir
 * algo.
 *
 * Lo que se prueba acá:
 *   · que el costo de una compra interna caiga en el libro interno
 *   · que la recepción herede la decisión de su orden
 *   · que una compra interna NO viaje a SIIGO, ni sola ni colada dentro de la
 *     compra consolidada de una hermana
 *
 * En compras no hay «reparto»: declarar media compra significaría descontar IVA
 * de una factura que no existe por el resto.
 */

use App\Models\Contacto;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Siigo\Services\SiigoEmisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->bodega = \App\Modules\Dropi\Models\InventarioUbicacion::create([
        'codigo' => 'BOD-TEST', 'nombre' => 'Bodega de prueba', 'categoria' => 'venta',
    ]);

    $this->proveedor = Contacto::create([
        'numero_documento' => '901555333',
        'nombre_completo' => 'Proveedor de Contado SAS',
        'es_proveedor' => true,
    ]);
});

function ordenCon(DestinoFiscal $destino, ?LineaNegocio $linea = null): OrdenCompra
{
    return OrdenCompra::create([
        'numero' => 'OC-'.fake()->unique()->numberBetween(10000, 99999),
        'proveedor_id' => test()->proveedor->id,
        'fecha_emision' => now()->toDateString(),
        'estado' => 'aprobada',
        'subtotal' => 1_000_000, 'descuento' => 0, 'iva' => 0, 'total' => 1_000_000,
        'destino_fiscal' => $destino,
        'linea_negocio' => $linea ?? LineaNegocio::GreatBaby,
        'motivo_destino' => $destino === DestinoFiscal::Siigo ? null : 'Proveedor sin RUT',
    ]);
}

it('la recepción hereda el destino de su orden', function () {
    $orden = ordenCon(DestinoFiscal::Interno);

    $r = RecepcionCompra::create([
        'numero' => 'RC-'.fake()->unique()->numberBetween(10000, 99999),
        'orden_id' => $orden->id,
        'bodega_id' => test()->bodega->id,
        'fecha_recepcion' => now()->toDateString(),
        'estado' => 'recibida',
        'total_recibido' => 1_000_000,
    ]);

    expect($r->fresh()->destino_fiscal)->toBe(DestinoFiscal::Interno)
        ->and($r->fresh()->motivo_destino)->toBe('Proveedor sin RUT');
});

it('la recepción hereda también la línea de negocio', function () {
    $orden = ordenCon(DestinoFiscal::Siigo, LineaNegocio::Dropi);

    $r = RecepcionCompra::create([
        'numero' => 'RC-'.fake()->unique()->numberBetween(10000, 99999),
        'orden_id' => $orden->id,
        'bodega_id' => test()->bodega->id,
        'fecha_recepcion' => now()->toDateString(),
        'estado' => 'recibida',
        'total_recibido' => 1_000_000,
    ]);

    expect($r->fresh()->linea_negocio)->toBe(LineaNegocio::Dropi);
});

it('si alguien ya puso el destino a mano, no se lo pisa', function () {
    // La factura del proveedor aparece después: hay que poder corregirlo en la
    // recepción sin anular y rehacer la orden entera.
    $orden = ordenCon(DestinoFiscal::Interno);

    $r = RecepcionCompra::create([
        'numero' => 'RC-'.fake()->unique()->numberBetween(10000, 99999),
        'orden_id' => $orden->id,
        'bodega_id' => test()->bodega->id,
        'fecha_recepcion' => now()->toDateString(),
        'estado' => 'recibida',
        'total_recibido' => 1_000_000,
        'destino_fiscal' => DestinoFiscal::Siigo,
    ]);

    expect($r->fresh()->destino_fiscal)->toBe(DestinoFiscal::Siigo);
});

it('una compra sin destino explícito sigue siendo fiscal, como hasta hoy', function () {
    // La migración no cambia el comportamiento de nada: sólo lo hace decidible.
    $orden = OrdenCompra::create([
        'numero' => 'OC-SINDESTINO',
        'proveedor_id' => $this->proveedor->id,
        'fecha_emision' => now()->toDateString(),
        'estado' => 'aprobada',
        'subtotal' => 500_000, 'descuento' => 0, 'iva' => 0, 'total' => 500_000,
    ]);

    expect($orden->fresh()->destino_fiscal)->toBe(DestinoFiscal::Siigo)
        ->and($orden->fresh()->linea_negocio)->toBe(LineaNegocio::GreatBaby);
});

it('el costo de una compra interna cae en el libro INTERNO', function () {
    /*
     * Este es el punto de todo el bloque. Mientras las compras no tuvieron
     * destino, el inventario y el IVA de TODA compra caian en el libro fiscal
     * por el valor por defecto del estampado. Entonces el balance interno
     * mostraba los ingresos que no se declaran y ningun costo que no se
     * declara: la utilidad interna salia inflada, que es justo el numero que
     * alguien iba a mirar para decidir algo.
     */
    $orden = ordenCon(DestinoFiscal::Interno);
    $recepcion = recepcionConItems($orden, 1_000_000);

    app(\App\Modules\Compras\Actions\RegistrarAsientoCompra::class)->recepcion($recepcion);

    $deDestino = fn (DestinoFiscal $d) => \App\Modules\Cartera\Models\MovimientoContable::query()
        ->where('origen_type', RecepcionCompra::class)
        ->where('origen_id', $recepcion->id)
        ->where('destino_fiscal', $d->value);

    expect($deDestino(DestinoFiscal::Siigo)->count())->toBe(0)
        ->and((float) $deDestino(DestinoFiscal::Interno)->where('cuenta_puc', '1435')->sum('debe'))
        ->toEqual(1_000_000.0);
});

it('el costo de una compra fiscal sigue cayendo en el libro fiscal', function () {
    // El comportamiento de siempre tiene que quedar igual.
    $orden = ordenCon(DestinoFiscal::Siigo);
    $recepcion = recepcionConItems($orden, 1_000_000);

    app(\App\Modules\Compras\Actions\RegistrarAsientoCompra::class)->recepcion($recepcion);

    $movs = \App\Modules\Cartera\Models\MovimientoContable::query()
        ->where('origen_type', RecepcionCompra::class)->where('origen_id', $recepcion->id);

    expect((clone $movs)->where('destino_fiscal', DestinoFiscal::Interno->value)->count())->toBe(0)
        ->and((clone $movs)->count())->toBeGreaterThan(0);
});

it('una compra de Dropi asienta en la línea Dropi', function () {
    $orden = ordenCon(DestinoFiscal::Siigo, LineaNegocio::Dropi);
    $recepcion = recepcionConItems($orden, 500_000);

    app(\App\Modules\Compras\Actions\RegistrarAsientoCompra::class)->recepcion($recepcion);

    $deLinea = fn (LineaNegocio $l) => \App\Modules\Cartera\Models\MovimientoContable::query()
        ->where('origen_type', RecepcionCompra::class)->where('origen_id', $recepcion->id)
        ->where('linea_negocio', $l->value)->count();

    expect($deLinea(LineaNegocio::GreatBaby))->toBe(0)
        ->and($deLinea(LineaNegocio::Dropi))->toBeGreaterThan(0);
});

it('una compra interna NO se puede emitir a SIIGO', function () {
    /*
     * Mandarla significaría descontar un IVA que no tiene factura con qué
     * soportarse, y eso no se deshace con un botón.
     */
    $orden = ordenCon(DestinoFiscal::Interno);

    $r = RecepcionCompra::create([
        'numero' => 'RC-'.fake()->unique()->numberBetween(10000, 99999),
        'orden_id' => $orden->id,
        'bodega_id' => test()->bodega->id,
        'fecha_recepcion' => now()->toDateString(),
        'estado' => 'confirmada',
        'total_recibido' => 1_000_000,
    ]);

    expect(fn () => app(SiigoEmisionService::class)->emitirCompra($r->fresh()))
        ->toThrow(RuntimeException::class, 'no va a SIIGO');
});

it('el destino de la orden arrastra a sus recepciones no emitidas', function () {
    $orden = ordenCon(DestinoFiscal::Siigo);

    $r = RecepcionCompra::create([
        'numero' => 'RC-'.fake()->unique()->numberBetween(10000, 99999),
        'orden_id' => $orden->id,
        'bodega_id' => test()->bodega->id,
        'fecha_recepcion' => now()->toDateString(),
        'estado' => 'recibida',
        'total_recibido' => 1_000_000,
    ]);

    $this->actingAs(contadorDeCompras())
        ->post('/app/compras/destino', [
            'orden_id' => $orden->id,
            'destino' => DestinoFiscal::Interno->value,
            'motivo' => 'Compra de contado sin factura',
        ])->assertRedirect();

    expect($orden->fresh()->destino_fiscal)->toBe(DestinoFiscal::Interno)
        ->and($r->fresh()->destino_fiscal)->toBe(DestinoFiscal::Interno);
});

it('una recepción ya emitida en SIIGO no se pasa a interno', function () {
    // El documento de allá no cambia porque acá se marque otra cosa: eso se
    // revierte con nota crédito.
    $orden = ordenCon(DestinoFiscal::Siigo);

    RecepcionCompra::create([
        'numero' => 'RC-'.fake()->unique()->numberBetween(10000, 99999),
        'orden_id' => $orden->id,
        'bodega_id' => test()->bodega->id,
        'fecha_recepcion' => now()->toDateString(),
        'estado' => 'confirmada',
        'total_recibido' => 1_000_000,
    ])->forceFill(['siigo_id' => 'sg-999'])->saveQuietly();

    $this->actingAs(contadorDeCompras())
        ->post('/app/compras/destino', [
            'orden_id' => $orden->id,
            'destino' => DestinoFiscal::Interno->value,
            'motivo' => 'Me arrepentí',
        ])->assertSessionHasErrors('destino');

    expect($orden->fresh()->destino_fiscal)->toBe(DestinoFiscal::Siigo);
});

it('sacar una compra de lo fiscal sin decir por qué no pasa', function () {
    $orden = ordenCon(DestinoFiscal::Siigo);

    $this->actingAs(contadorDeCompras())
        ->post('/app/compras/destino', [
            'orden_id' => $orden->id,
            'destino' => DestinoFiscal::Interno->value,
        ])->assertSessionHasErrors('motivo');

    expect($orden->fresh()->destino_fiscal)->toBe(DestinoFiscal::Siigo);
});

it('la pantalla filtra las compras por destino', function () {
    ordenCon(DestinoFiscal::Siigo);
    ordenCon(DestinoFiscal::Interno);

    $r = $this->actingAs(contadorDeCompras())->get('/app/compras?tab=ordenes&destino=interna');

    $r->assertOk();
    $ordenes = $r->viewData('page')['props']['ordenes']['data'];

    expect($ordenes)->toHaveCount(1)
        ->and($ordenes[0]['destino'])->toBe(DestinoFiscal::Interno->value);
});

/** Una recepción confirmada con un ítem, que es lo que el asiento necesita. */
function recepcionConItems(OrdenCompra $orden, float $monto): RecepcionCompra
{
    $item = \Illuminate\Support\Facades\DB::table('compra_orden_items')->insertGetId([
        'orden_id' => $orden->id, 'descripcion' => 'Mercancía',
        'cantidad' => 1, 'precio_unit' => $monto, 'subtotal' => $monto, 'total' => $monto,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $recepcion = RecepcionCompra::create([
        'numero' => 'RC-'.fake()->unique()->numberBetween(10000, 99999),
        'orden_id' => $orden->id,
        'bodega_id' => test()->bodega->id,
        'fecha_recepcion' => now()->toDateString(),
        'estado' => 'confirmada',
        'total_recibido' => $monto,
    ]);

    \Illuminate\Support\Facades\DB::table('compras_recepcion_items')->insert([
        'recepcion_id' => $recepcion->id, 'orden_item_id' => $item,
        'cantidad_recibida' => 1, 'costo_unit' => $monto, 'subtotal' => $monto,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $recepcion->fresh();
}

/** El Contador, que es quien puede entrar a Compras. */
function contadorDeCompras(): \App\Models\User
{
    $u = \App\Models\User::factory()->create();
    $u->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']));

    return $u;
}
