<?php

/**
 * F2b · Facturas que no nacen de un pedido.
 *
 * Hasta hoy la única forma de que existiera una factura era facturar un pedido
 * B2B. Lo que se prueba acá es que la puerta nueva deje la factura TAN completa
 * como la vieja: con consecutivo, con totales que cumplen el invariante de
 * partida doble, y —sobre todo— con asiento.
 *
 * Una factura sin asiento no aparece en ningún balance y nadie se entera hasta
 * el cierre: por eso hay una prueba dedicada sólo a que el asiento exista.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Actions\CrearFacturaManual;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->cliente = Contacto::create([
        'numero_documento' => '900555444',
        'nombre_completo' => 'Cliente Manual SAS',
        'es_cliente' => true,
    ]);

    /*
     * Todo ítem apunta a un producto: lo exige el CHECK
     * `chk_factura_venta_item_sujeto` de la base, que está para que el
     * inventario nunca tenga filas sin saber de qué eran. Un flete tambien es
     * un producto del catálogo, sólo que sin control de stock.
     */
    $this->flete = Producto::create([
        'referencia' => 'SERV-FLETE',
        'nombre' => 'Flete',
        'activo' => true,
        'stock_control' => false,
    ]);
});

function datosManuales(Contacto $cliente, array $extra = []): array
{
    return array_merge([
        'contacto_id' => $cliente->id,
        'fecha_emision' => now()->toDateString(),
        'items' => [[
            'producto_id' => test()->flete->id,
            'descripcion' => 'Servicio de flete', 'cantidad' => 1,
            'precio_unit' => 100000, 'impuesto_pct' => 0,
        ]],
    ], $extra);
}

it('crea la factura con consecutivo, saldo y asiento', function () {
    $f = CrearFacturaManual::run(datosManuales($this->cliente));

    expect($f->numero)->not->toBeEmpty()
        ->and((float) $f->total)->toEqual(100000.0)
        ->and((float) $f->saldo)->toEqual(100000.0)
        // Lo que de verdad importa: que exista el asiento. Sin esto la factura
        // no aparece en ningún balance y nadie se entera hasta el cierre.
        ->and(MovimientoContable::where('origen_type', FacturaVenta::class)
            ->where('origen_id', $f->id)->count())->toBeGreaterThan(0);
});

it('los totales cumplen el invariante de la factura con descuento e IVA', function () {
    // 10 × $45.000 = $450.000 · 10 % de descuento = $45.000 · IVA 19 % sobre
    // $405.000 = $76.950 · total $481.950.
    $f = CrearFacturaManual::run(datosManuales($this->cliente, [
        'items' => [[
            'producto_id' => $this->flete->id,
            'descripcion' => 'Pañal etapa 2', 'cantidad' => 10, 'precio_unit' => 45000,
            'descuento_pct' => 10, 'impuesto_pct' => 19,
        ]],
    ]));

    expect((float) $f->subtotal)->toEqual(450000.0)
        ->and((float) $f->descuento)->toEqual(45000.0)
        ->and((float) $f->impuestos)->toEqual(76950.0)
        ->and((float) $f->total)->toEqual(481950.0)
        // El invariante que valida el modelo al guardar.
        ->and(round((float) $f->subtotal - (float) $f->descuento + (float) $f->impuestos, 2))
        ->toEqual((float) $f->total);
});

it('el ingreso del libro es el subtotal menos el descuento, no el bruto', function () {
    // Si el encabezado guardara el subtotal ya neteado, el descuento se
    // restaría dos veces y el ingreso saldría corto. Acá se fija el contrato.
    CrearFacturaManual::run(datosManuales($this->cliente, [
        'items' => [[
            'producto_id' => $this->flete->id,
            'descripcion' => 'Pañal etapa 2', 'cantidad' => 10, 'precio_unit' => 45000,
            'descuento_pct' => 10, 'impuesto_pct' => 0,
        ]],
    ]));

    expect((float) MovimientoContable::where('cuenta_puc', cta('cta_ingreso_default'))->sum('haber'))->toEqual(405000.0);
});

it('un reparto manual parte el asiento en la proporción declarada', function () {
    $f = CrearFacturaManual::run(datosManuales($this->cliente, [
        'items' => [['producto_id' => $this->flete->id, 'descripcion' => 'Venta acuerdo comercial', 'cantidad' => 1, 'precio_unit' => 100_000_000]],
        'destino_fiscal' => DestinoFiscal::Reparto->value,
        'monto_fiscal' => 20_000_000,
        'motivo_destino' => 'Acuerdo comercial: se declara el 20 %',
    ]));

    $deDestino = fn (DestinoFiscal $d) => (float) MovimientoContable::where('cuenta_puc', cta('cta_cxc_default'))
        ->where('destino_fiscal', $d->value)->sum('debe');

    expect((float) $f->total)->toEqual(100_000_000.0)
        ->and((float) $f->monto_fiscal)->toEqual(20_000_000.0)
        ->and($deDestino(DestinoFiscal::Siigo))->toEqual(20_000_000.0)
        ->and($deDestino(DestinoFiscal::Interno))->toEqual(80_000_000.0);
});

it('una factura manual de Dropi asienta en la línea Dropi', function () {
    /*
     * Esto fallaba: la línea del asiento se deducía del namespace del
     * `origen_type`, y una factura hecha a mano nace en Cartera aunque sea de
     * Dropi. Deducida, TODA factura manual caía en Great Baby y le ensuciaba el
     * balance a los dos negocios a la vez.
     */
    CrearFacturaManual::run(datosManuales($this->cliente, [
        'linea_negocio' => LineaNegocio::Dropi->value,
    ]));

    expect(MovimientoContable::where('linea_negocio', LineaNegocio::GreatBaby->value)->count())->toBe(0)
        ->and(MovimientoContable::where('linea_negocio', LineaNegocio::Dropi->value)->count())->toBeGreaterThan(0);
});

it('una factura interna no nace electrónica', function () {
    // Marcarla electrónica la deja esperando una emisión que nadie va a disparar.
    $f = CrearFacturaManual::run(datosManuales($this->cliente, [
        'destino_fiscal' => DestinoFiscal::Interno->value,
        'motivo_destino' => 'Venta de mostrador sin factura electrónica',
    ]));

    expect((bool) $f->es_electronica)->toBeFalse()
        ->and(MovimientoContable::where('destino_fiscal', DestinoFiscal::Siigo->value)->count())->toBe(0);
});

it('no crea nada sin ítems útiles', function () {
    expect(fn () => CrearFacturaManual::run(datosManuales($this->cliente, [
        'items' => [['producto_id' => $this->flete->id, 'descripcion' => '   ', 'cantidad' => 5, 'precio_unit' => 1000]],
    ])))->toThrow(InvalidArgumentException::class);

    expect(FacturaVenta::count())->toBe(0);
});

it('no deja sacar una factura de lo fiscal sin decir por qué', function () {
    expect(fn () => CrearFacturaManual::run(datosManuales($this->cliente, [
        'destino_fiscal' => DestinoFiscal::Interno->value,
    ])))->toThrow(InvalidArgumentException::class);

    expect(FacturaVenta::count())->toBe(0);
});

it('no deja declarar en un reparto más que el total', function () {
    expect(fn () => CrearFacturaManual::run(datosManuales($this->cliente, [
        'items' => [['producto_id' => $this->flete->id, 'descripcion' => 'Venta', 'cantidad' => 1, 'precio_unit' => 100000]],
        'destino_fiscal' => DestinoFiscal::Reparto->value,
        'monto_fiscal' => 100000,
        'motivo_destino' => 'Acuerdo',
    ])))->toThrow(InvalidArgumentException::class);

    expect(FacturaVenta::count())->toBe(0);
});

it('el vencimiento sale del plazo del cliente cuando no se indica', function () {
    $f = CrearFacturaManual::run(datosManuales($this->cliente, ['fecha_emision' => '2026-10-01']));

    // 30 días es el default cuando el cliente no tiene condición de crédito.
    expect($f->fecha_vencimiento->toDateString())->toBe('2026-10-31');
});

it('no crea la factura si un ítem no sale del catálogo', function () {
    /*
     * El CHECK de la base lo rechazaría igual, pero con un error de MySQL que
     * no le dice a nadie qué hacer. Acá se corta antes y el mensaje explica la
     * salida: crear el producto una vez en el catálogo.
     */
    expect(fn () => CrearFacturaManual::run(datosManuales($this->cliente, [
        'items' => [['descripcion' => 'Flete suelto', 'cantidad' => 1, 'precio_unit' => 50000]],
    ])))->toThrow(InvalidArgumentException::class, 'catálogo');

    expect(FacturaVenta::count())->toBe(0);
});

it('avisa que un producto sin sincronizar no va a poder emitirse', function () {
    // El flete del beforeEach no tiene siigo_id: SiigoEmisionService LANZA al
    // no encontrarle código, y ese error aparecía recién al emitir, horas
    // después y sin la factura delante.
    $items = [['producto_id' => $this->flete->id, 'descripcion' => 'Flete', 'cantidad' => 1, 'precio_unit' => 100000]];

    expect(CrearFacturaManual::avisosDeSiigo($items, DestinoFiscal::Siigo))->not->toBeEmpty()
        // A lo interno no le importa: nunca viaja.
        ->and(CrearFacturaManual::avisosDeSiigo($items, DestinoFiscal::Interno))->toBeEmpty();
});

it('no avisa nada de un producto que sí está sincronizado', function () {
    // `siigo_id` no es fillable a propósito: lo escribe el sync, no un
    // mass-assign. Acá se fuerza para simular un producto ya sincronizado.
    $sincronizado = Producto::create([
        'referencia' => 'REF-OK', 'nombre' => 'Pañal etapa 2', 'activo' => true,
    ]);
    $sincronizado->forceFill(['siigo_id' => 'abc-123'])->save();

    $items = [['producto_id' => $sincronizado->id, 'descripcion' => 'Pañal etapa 2', 'cantidad' => 1, 'precio_unit' => 45000]];

    expect(CrearFacturaManual::avisosDeSiigo($items, DestinoFiscal::Siigo))->toBeEmpty();
});

it('avisa cuando el producto está en SIIGO pero sin referencia', function () {
    // La referencia ES el código con el que viaja el ítem: sin ella la emisión
    // falla igual, aunque el producto esté sincronizado.
    $sinRef = Producto::create(['referencia' => '', 'nombre' => 'Producto sin referencia', 'activo' => true]);
    $sinRef->forceFill(['siigo_id' => 'def-456'])->save();

    $items = [['producto_id' => $sinRef->id, 'descripcion' => 'Algo', 'cantidad' => 1, 'precio_unit' => 1000]];

    expect(CrearFacturaManual::avisosDeSiigo($items, DestinoFiscal::Siigo))->not->toBeEmpty();
});
