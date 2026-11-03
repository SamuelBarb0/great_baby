<?php

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * F1 · Los dos ejes de la contabilidad.
 *
 * Lo que se prueba acá no es el enum: es que el estampado viva en el MODELO.
 * Hay 18 llamadas a `MovimientoContable::create()` repartidas en cinco módulos
 * y ninguna pasa estos campos. Si el `creating` se rompe o alguien lo quita,
 * los asientos entran sin línea de negocio y el balance de Great Baby queda
 * mezclado con el de Dropi sin que nadie se entere — el balance igual «cuadra»,
 * porque le faltan las dos patas a la vez.
 */

/*
 * `origen_type` y `origen_id` son NOT NULL en la tabla: todo asiento nace de un
 * documento, no hay forma de crear uno suelto. Por eso `deOrigen()` siempre
 * tiene de dónde deducir la línea en producción.
 */
function lineaAsiento(array $extra = []): array
{
    return array_merge([
        'fecha' => now()->toDateString(),
        'cuenta_puc' => cta('cta_bancos_default'),
        'debe' => 100,
        'haber' => 0,
        'descripcion' => 'prueba de ejes',
        'origen_type' => 'App\\Modules\\Cartera\\Models\\FacturaVenta',
        'origen_id' => 1,
    ], $extra);
}

it('estampa Great Baby cuando el asiento no dice de quién es', function () {
    $m = MovimientoContable::create(lineaAsiento());

    expect($m->linea_negocio)->toBe(LineaNegocio::GreatBaby)
        ->and($m->destino_fiscal)->toBe(DestinoFiscal::Siigo);
});

it('reconoce a Dropi por el módulo del documento que originó el asiento', function () {
    $m = MovimientoContable::create(lineaAsiento([
        'origen_type' => 'App\\Modules\\Dropi\\Models\\DropiWalletMovimiento',
        'origen_id' => 1,
    ]));

    expect($m->linea_negocio)->toBe(LineaNegocio::Dropi);
});

it('no pisa la línea ni el destino cuando quien asienta ya los trae puestos', function () {
    $m = MovimientoContable::create(lineaAsiento([
        'linea_negocio' => LineaNegocio::Dropi,
        'destino_fiscal' => DestinoFiscal::Reparto,
    ]));

    expect($m->linea_negocio)->toBe(LineaNegocio::Dropi)
        ->and($m->destino_fiscal)->toBe(DestinoFiscal::Reparto);
});

it('estampa también los asientos que entran por el registrador atómico', function () {
    MovimientoContable::registrarAsientoAtomico([
        lineaAsiento(['debe' => 500, 'haber' => 0]),
        lineaAsiento(['cuenta_puc' => cta('cta_ingreso_default'), 'debe' => 0, 'haber' => 500]),
    ]);

    expect(MovimientoContable::count())->toBe(2)
        ->and(MovimientoContable::whereNull('linea_negocio')->count())->toBe(0)
        ->and(MovimientoContable::whereNull('destino_fiscal')->count())->toBe(0);
});

/*
 * El cambio de fondo: «pendiente de SIIGO» deja de ser «todavía no se envió» y
 * pasa a ser «debe irse y todavía no se fue». Antes, lo que alguien decidiera
 * dejar fuera de SIIGO aparecía como pendiente para siempre — el «bórolo» que
 * la contadora temía en la reunión del 21-oct.
 */
it('lo marcado Interno o Reparto deja de contar como pendiente de SIIGO', function () {
    MovimientoContable::create(lineaAsiento());                                        // va a SIIGO, sin enviar
    MovimientoContable::create(lineaAsiento(['destino_fiscal' => DestinoFiscal::Interno]));
    MovimientoContable::create(lineaAsiento(['destino_fiscal' => DestinoFiscal::Reparto]));
    MovimientoContable::create(lineaAsiento(['siigo_journal_id' => 'J-123']));          // ya enviado

    expect(MovimientoContable::count())->toBe(4)
        ->and(MovimientoContable::whereNull('siigo_journal_id')->count())->toBe(3)
        ->and(MovimientoContable::pendienteDeSiigo()->count())->toBe(1);
});

it('separa el balance de cada línea de negocio', function () {
    MovimientoContable::create(lineaAsiento(['debe' => 300]));
    MovimientoContable::create(lineaAsiento(['debe' => 200, 'linea_negocio' => LineaNegocio::Dropi]));

    expect(MovimientoContable::deLinea(LineaNegocio::GreatBaby)->sum('debe'))->toEqual(300)
        ->and(MovimientoContable::deLinea(LineaNegocio::Dropi)->sum('debe'))->toEqual(200)
        ->and(MovimientoContable::sum('debe'))->toEqual(500);
});
