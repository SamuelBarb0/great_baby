<?php

/**
 * Borrar un pago de cliente tiene que llevarse su asiento.
 *
 * El 8 de septiembre de 2026, en producción, alguien registró y borró seis
 * pagos de $1.200.000 contra la misma factura en cinco minutos —probando—.
 * Los seis quedaron con `deleted_at`, pero sus doce líneas siguieron vivas en
 * el libro: $7.200.000 debitados al banco y $7.200.000 acreditados a clientes
 * por una plata que nunca entró. La 1305 quedó en negativo y el balance
 * general decía que la empresa le debía dinero a sus clientes.
 *
 * `PagoVentaObserver::deleted()` existe desde el commit inicial y hace
 * exactamente lo que hay que hacer. Lo que no existía era una prueba que lo
 * cubriera: la única de anulación mira `PagoProveedor`, que es otro modelo y
 * otro flujo. Por eso el agujero vivió nueve semanas sin que nadie lo viera.
 *
 * El último caso es el que de verdad importa. Un borrado en masa por el query
 * builder —`PagoVenta::where(...)->delete()`— NO dispara eventos de modelo,
 * así que el observer no corre y el asiento queda huérfano. Es la única
 * explicación que queda en pie para lo de septiembre, y el libro contable no
 * puede depender de por cuál puerta se borró la fila.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Actions\CrearFacturaManual;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    // El observer encola el voucher a SIIGO al crear el pago.
    Queue::fake();

    $this->cliente = Contacto::create([
        'numero_documento' => '900777111',
        'nombre_completo' => 'Cliente que paga y se arrepiente SAS',
        'es_cliente' => true,
    ]);

    $this->articulo = Producto::create([
        'referencia' => 'REF-BORRAR', 'nombre' => 'Producto', 'activo' => true,
    ]);
});

/**
 * Nombres propios a propósito: en Pest las funciones del archivo son globales,
 * y `facturaParaPagar()` / `pagar()` ya viven en PagoHeredaDestinoTest.
 */
function facturaParaBorrarlePagos(float $total): FacturaVenta
{
    return CrearFacturaManual::run([
        'contacto_id' => test()->cliente->id,
        'fecha_emision' => now()->toDateString(),
        'destino_fiscal' => DestinoFiscal::Siigo->value,
        'linea_negocio' => LineaNegocio::GreatBaby->value,
        'items' => [[
            'producto_id' => test()->articulo->id,
            'descripcion' => 'Venta', 'cantidad' => 1, 'precio_unit' => $total,
        ]],
    ]);
}

/** Un pago creado por el camino normal: el observer es quien asienta. */
function pagoParaBorrar(FacturaVenta $f, float $monto): PagoVenta
{
    return PagoVenta::create([
        'factura_id' => $f->id,
        'contacto_id' => $f->contacto_id,
        'fecha' => now()->toDateString(),
        'medio_pago' => 'transferencia',
        'monto_recibido' => $monto,
        'monto_aplicado' => $monto,
        'diferencia' => 0,
    ])->fresh();
}

function lineasVivasDelPago(PagoVenta $p): int
{
    return MovimientoContable::where('origen_type', PagoVenta::class)
        ->where('origen_id', $p->id)->count();
}

it('crear el pago deja su asiento en el libro', function () {
    $pago = pagoParaBorrar(facturaParaBorrarlePagos(1_200_000), 1_200_000);

    // Sin esto, los demás casos pasarían por no haber asentado nunca nada.
    expect(lineasVivasDelPago($pago))->toBeGreaterThan(0);
});

it('borrar el pago se lleva su asiento', function () {
    $pago = pagoParaBorrar(facturaParaBorrarlePagos(1_200_000), 1_200_000);

    $pago->delete();

    expect($pago->fresh()?->deleted_at ?? PagoVenta::withTrashed()->find($pago->id)->deleted_at)
        ->not->toBeNull()
        ->and(lineasVivasDelPago($pago))->toBe(0);
});

it('restaurar el pago devuelve su asiento', function () {
    $pago = pagoParaBorrar(facturaParaBorrarlePagos(1_200_000), 1_200_000);
    $pago->delete();

    PagoVenta::withTrashed()->find($pago->id)->restore();

    // Si el restore no reasentara, deshacer un borrado dejaría la factura
    // abonada en cartera y sin respaldo en el libro.
    expect(lineasVivasDelPago($pago))->toBeGreaterThan(0);
});

it('borrar el pago no deja la factura cobrada', function () {
    $factura = facturaParaBorrarlePagos(1_200_000);
    $pago = pagoParaBorrar($factura, 1_200_000);

    expect((float) $factura->fresh()->saldo)->toBe(0.0);

    $pago->delete();

    // El dinero volvió a deberse: si el saldo no se recalcula, el cliente
    // queda como al día y nadie le vuelve a cobrar.
    expect((float) $factura->fresh()->saldo)->toBe(1_200_000.0);
});

it('un borrado en masa tampoco puede dejar el asiento huérfano', function () {
    $pago = pagoParaBorrar(facturaParaBorrarlePagos(1_200_000), 1_200_000);

    // La misma fila, borrada por la otra puerta. El query builder no dispara
    // eventos de modelo, así que el observer no se entera — y el libro queda
    // con un ingreso de plata que ya nadie puede explicar.
    PagoVenta::where('id', $pago->id)->delete();

    expect(lineasVivasDelPago($pago))->toBe(0);
});

it('el borrado físico en masa no se muerde la cola', function () {
    /*
     * `PagoVentaBuilder` reescribe `forceDelete()` para borrar de a uno y
     * disparar el observer. Pero `$pago->forceDelete()` termina llamando a
     * `newModelQuery()->forceDelete()` —o sea, al mismo método— y eso se mordía
     * la cola: cargaba la fila, llamaba al modelo, el modelo volvía a entrar…
     * hasta agotar la memoria de PHP.
     *
     * Y tenía un llamador real: borrar físicamente una factura hace
     * `forceDelete()` sobre cada uno de sus pagos.
     */
    $f = facturaParaBorrarlePagos(500000);
    $a = pagoParaBorrar($f, 200000);
    $b = pagoParaBorrar($f, 100000);

    $borrados = PagoVenta::whereIn('id', [$a->id, $b->id])->forceDelete();

    expect($borrados)->toBe(2)
        ->and(PagoVenta::withTrashed()->whereIn('id', [$a->id, $b->id])->count())->toBe(0);
});

it('el borrado físico de a uno tampoco, y el asiento se va con él', function () {
    $f = facturaParaBorrarlePagos(500000);
    $p = pagoParaBorrar($f, 200000);

    expect(lineasVivasDelPago($p))->toBeGreaterThan(0);

    $p->forceDelete();

    // Lo que importa del builder sigue cumpliéndose: el observer corrió y no
    // quedó una línea contable apuntando a un pago que ya no existe.
    expect(PagoVenta::withTrashed()->whereKey($p->id)->exists())->toBeFalse()
        ->and(lineasVivasDelPago($p))->toBe(0);
});
