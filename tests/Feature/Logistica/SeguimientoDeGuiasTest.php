<?php

/**
 * Dónde está cada guía despachada.
 *
 * Un pedido B2B moría en «despachado»: quedaban la fecha, la transportadora y
 * el número de guía, y nadie volvía a mirarlo. Si la mercancía no llegaba, el
 * primero en enterarse era el cliente llamando a reclamar. El canal Dropi sí
 * tenía su alerta de mercancía en tránsito desde hacía meses; la venta B2B,
 * que es la que factura a crédito, no tenía ninguna.
 *
 * Lo que se protege acá son los plazos, porque son lo único que distingue
 * «va en camino» de «hay que reclamar»:
 *
 *   · dentro del plazo normal ................ A tiempo
 *   · pasado el plazo normal ................. Tardía
 *   · pasado el plazo de reclamo ............. No entregada
 *   · con fecha de entrega ................... Entregada
 *
 * Y que los días se cuenten hasta la ENTREGA, no hasta hoy: si no, una guía
 * entregada hace un mes seguiría envejeciendo en la pantalla.
 */

use App\Modules\Portal\Enums\EstadoGuia;
use App\Modules\Portal\Models\PedidoCliente;
use App\Support\Reglas;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
    Reglas::limpiarRequestCache();

    $this->cliente = \App\Models\Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900333444',
        'nombre_completo' => 'Cliente de guías', 'es_cliente' => true, 'ciudad' => 'Medellín',
    ]);
});

function pedidoDespachado(?int $haceDias, array $extra = []): PedidoCliente
{
    static $n = 0;
    $n++;

    return PedidoCliente::create(array_merge([
        'numero' => 'PB2B-GUIA-'.$n,
        'contacto_id' => test()->cliente->id,
        'estado' => 'despachado',
        'subtotal' => 100000, 'iva' => 0, 'total' => 100000,
        'despachado_at' => $haceDias === null ? null : now()->subDays($haceDias),
        'transportadora' => 'Servientrega',
        'guia_transportadora' => 'SE-'.$n,
    ], $extra));
}

it('una guía recién despachada va a tiempo', function () {
    expect(pedidoDespachado(2)->estadoGuia())->toBe(EstadoGuia::ATiempo);
});

it('pasado el plazo normal se marca tardía', function () {
    // El plazo por defecto son 5 días.
    expect(pedidoDespachado(7)->estadoGuia())->toBe(EstadoGuia::Tardia);
});

it('pasado el plazo de reclamo se marca no entregada', function () {
    expect(pedidoDespachado(30)->estadoGuia())->toBe(EstadoGuia::NoEntregada);
});

it('sin despachar es pendiente, no «a tiempo»', function () {
    $p = pedidoDespachado(null, ['estado' => 'facturado']);

    expect($p->estadoGuia())->toBe(EstadoGuia::Pendiente)
        ->and($p->diasEnTransito())->toBeNull();
});

it('con fecha de entrega queda entregada por más vieja que sea', function () {
    $p = pedidoDespachado(40, ['entregado_at' => now()->subDays(35)]);

    expect($p->estadoGuia())->toBe(EstadoGuia::Entregada);
});

it('los días se cuentan hasta la entrega, no hasta hoy', function () {
    $p = pedidoDespachado(30, ['entregado_at' => now()->subDays(25)]);

    // Despachada hace 30 días y entregada hace 25: estuvo 5 en camino. Si se
    // contara hasta hoy, una entrega vieja seguiría envejeciendo sola.
    expect($p->diasEnTransito())->toBe(5);
});

it('los plazos salen de las reglas y se pueden mover', function () {
    $p = pedidoDespachado(7);
    expect($p->estadoGuia())->toBe(EstadoGuia::Tardia);

    Reglas::set('logistica.dias_transito', 10);
    Reglas::limpiarRequestCache();

    expect($p->fresh()->estadoGuia())->toBe(EstadoGuia::ATiempo);
});

it('arma el enlace de rastreo con el número de guía', function () {
    $url = pedidoDespachado(1, ['transportadora' => 'Servientrega', 'guia_transportadora' => 'ABC123'])
        ->urlDeRastreo();

    expect($url)->toContain('servientrega.com')
        ->and($url)->toContain('ABC123');
});

it('reconoce la transportadora aunque venga mal escrita', function () {
    // En los pedidos viene de todas las formas: con tildes, en minúscula,
    // con espacios. El nombre se normaliza antes de comparar.
    foreach (['INTERRAPIDISIMO', 'Inter Rapidisimo', 'interrapidísimo'] as $escrito) {
        expect(pedidoDespachado(1, ['transportadora' => $escrito])->urlDeRastreo())
            ->not->toBeNull("no reconoció «{$escrito}»");
    }
});

it('sin guía no hay enlace que armar', function () {
    expect(pedidoDespachado(1, ['guia_transportadora' => null])->urlDeRastreo())->toBeNull();
});

it('una transportadora que nadie configuró no inventa un enlace', function () {
    expect(pedidoDespachado(1, ['transportadora' => 'Mensajería del Barrio'])->urlDeRastreo())
        ->toBeNull();
});
