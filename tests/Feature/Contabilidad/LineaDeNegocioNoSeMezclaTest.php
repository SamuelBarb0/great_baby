<?php

/**
 * Great Baby y Dropi no se pueden mezclar en la contabilidad.
 *
 * Aracely lo pidió así el 2026-11-06: «que nunca se me vayan a unir, siempre
 * las necesito aparte, contabilidad, todo». Y al ir a comprobarlo resultó que
 * ya estaban unidas: 34 facturas y 247 movimientos contables, TODOS en
 * `great_baby`, cero en `dropi`.
 *
 * El defecto era silencioso. Nadie escribía `linea_negocio` al crear una
 * factura —ni el pedido B2B, ni Dropi, ni la manual sin elegir— y la columna
 * tiene `great_baby` por defecto. El libro contable sí sabe deducirla cuando
 * el documento no la trae, pero la factura SÍ la traía: con el valor
 * equivocado. El default le ganaba a la deducción correcta.
 *
 * Lo que se protege acá:
 *   · la factura nace con la línea que le toca, sin que nadie se la escriba;
 *   · manda la razón social: lo que factura la empresa de Dropi es de Dropi;
 *   · una factura no puede decir una línea distinta a la de su empresa;
 *   · los movimientos del libro heredan esa línea.
 */

use App\Models\EmpresaConfig;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Models\DropiPedido;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
    $this->cliente = \App\Models\Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900111222',
        'nombre_completo' => 'Cliente de prueba', 'es_cliente' => true,
    ]);
    $this->granBaby = EmpresaConfig::firstOrCreate(
        ['codigo' => 'great_baby'],
        ['razon_social' => 'GREAT BABY SAS', 'es_principal' => true, 'activa' => true]
    );
    $this->ecommerce = EmpresaConfig::firstOrCreate(
        ['codigo' => 'ecommerce'],
        ['razon_social' => 'E-COMMERCE SAS', 'es_principal' => false, 'activa' => true,
         'nit' => '901222333', 'prefijo_dian' => 'ECO']
    );
    /*
     * La premisa de este archivo, dicha en voz alta.
     *
     * Antes se apoyaba en el valor por defecto de `dropi.empresa`, que valía
     * `ecommerce`. El 7-nov el negocio lo cambió a `great_baby` —misma empresa,
     * otra resolución DIAN— y estos casos se cayeron sin que nadie los hubiera
     * tocado. Lo que acá se comprueba es qué pasa cuando Dropi SÍ tiene razón
     * social propia; eso hay que declararlo, no heredarlo.
     *
     * El caso contrario —Dropi facturando con la principal— está al final.
     */
    \App\Support\Reglas::set('dropi.empresa', 'ecommerce');
    \App\Support\Reglas::limpiarRequestCache();
});

/** Una factura mínima que pasa el invariante de partida doble. */
function facturaLinea(array $extra = []): FacturaVenta
{
    static $n = 0;
    $n++;

    return FacturaVenta::create(array_merge([
        'numero' => 'FV-LN-'.$n,
        'contacto_id' => test()->cliente->id,
        'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'estado' => 'pendiente',
        'tipo' => 'credito',
        'subtotal' => 100000, 'descuento' => 0, 'impuestos' => 0,
        'total' => 100000, 'saldo' => 100000,
    ], $extra));
}

it('una factura que nace de un pedido Dropi queda en la línea de Dropi', function () {
    $f = facturaLinea(['origen_type' => DropiPedido::class, 'origen_id' => 1]);

    expect($f->fresh()->linea_negocio)->toBe(LineaNegocio::Dropi);
});

it('una factura de un pedido B2B queda en Great Baby', function () {
    $f = facturaLinea([
        'origen_type' => \App\Modules\Portal\Models\PedidoCliente::class,
        'origen_id' => 1,
    ]);

    expect($f->fresh()->linea_negocio)->toBe(LineaNegocio::GreatBaby);
});

it('manda la razón social: lo que factura la empresa de Dropi es de Dropi', function () {
    /*
     * Sin origen que la delate. Antes esta factura caía en Great Baby por el
     * default de la columna, y en el balance sumaba del lado equivocado.
     */
    $f = facturaLinea(['empresa_id' => $this->ecommerce->id]);

    expect($f->fresh()->linea_negocio)->toBe(LineaNegocio::Dropi);
});

it('cuál es la empresa de Dropi sale de la regla, no de un código fijo', function () {
    \App\Support\Reglas::set('dropi.empresa', 'arsutex');
    $arsutex = EmpresaConfig::firstOrCreate(
        ['codigo' => 'arsutex'],
        ['razon_social' => 'ARSUTEX SAS', 'es_principal' => false, 'activa' => true]
    );

    expect(facturaLinea(['empresa_id' => $arsutex->id])->fresh()->linea_negocio)
        ->toBe(LineaNegocio::Dropi)
        ->and(facturaLinea(['empresa_id' => $this->ecommerce->id])->fresh()->linea_negocio)
        ->toBe(LineaNegocio::GreatBaby);
});

it('no deja guardar una factura de Dropi marcada como Great Baby', function () {
    expect(fn () => facturaLinea([
        'empresa_id' => $this->ecommerce->id,
        'linea_negocio' => LineaNegocio::GreatBaby,
    ]))->toThrow(RuntimeException::class, 'no se mezclan');
});

it('respeta la línea que se elige a mano cuando la empresa no impone ninguna', function () {
    $f = facturaLinea([
        'empresa_id' => $this->granBaby->id,
        'linea_negocio' => LineaNegocio::Dropi,
    ]);

    expect($f->fresh()->linea_negocio)->toBe(LineaNegocio::Dropi);
});

it('los movimientos del libro heredan la línea de la factura', function () {
    $f = facturaLinea(['origen_type' => DropiPedido::class, 'origen_id' => 7]);

    $m = MovimientoContable::create([
        'fecha' => now()->toDateString(),
        'cuenta_puc' => '13050501', 'debe' => 100000, 'haber' => 0,
        'origen_type' => FacturaVenta::class, 'origen_id' => $f->id,
        'descripcion' => 'Prueba de línea',
    ]);

    expect($m->fresh()->linea_negocio)->toBe(LineaNegocio::Dropi);
});

it('una factura vieja desalineada se puede volver a guardar: se corrige sola', function () {
    /*
     * `saving` corre en CADA guardado —aplicar un pago, cambiar el estado,
     * tocar el saldo—, así que una factura de cuando la columna tenía default
     * `great_baby` y nadie miraba la razón social quedaba imposible de guardar
     * PARA SIEMPRE. Ni su pago se podía registrar, y el error hablaba de
     * líneas de negocio, que no tiene nada que ver con lo que la persona
     * estaba haciendo.
     */
    $f = facturaLinea(['empresa_id' => $this->ecommerce->id]);

    // Como quedaron las viejas: la razón social dice Dropi, la fila dice otra.
    \Illuminate\Support\Facades\DB::table('facturas_venta')
        ->where('id', $f->id)->update(['linea_negocio' => 'great_baby']);

    $vieja = \App\Modules\Cartera\Models\FacturaVenta::find($f->id);

    expect($vieja->linea_negocio->value)->toBe('great_baby');

    // Registrar su pago no tiene por qué saber de líneas de negocio.
    $vieja->saldo = 0;
    $vieja->estado = 'pagada';
    $vieja->save();

    // Manda la razón social, que es lo que dice el hook de `creating`.
    expect($vieja->fresh()->linea_negocio->value)->toBe('dropi')
        ->and((string) ($vieja->fresh()->estado->value ?? $vieja->fresh()->estado))->toBe('pagada');
});

it('cambiarla a mano a la que no corresponde sigue reventando', function () {
    // El candado que pidió Aracely sigue puesto donde importa: cuando alguien
    // las está uniendo a propósito.
    $f = facturaLinea(['empresa_id' => $this->ecommerce->id]);

    expect(function () use ($f) {
        $f->linea_negocio = LineaNegocio::GreatBaby;
        $f->save();
    })->toThrow(RuntimeException::class);
});

it('cuando Dropi factura con la razón social principal, manda el origen', function () {
    /*
     * Lo que decidió el negocio el 7-nov: Dropi dejó de tener razón social
     * propia y factura con la de Great Baby, con otra resolución DIAN.
     *
     * Una razón social que factura las DOS líneas no distingue ninguna, así
     * que la línea tiene que salir del documento que originó la factura —el
     * mismo criterio que ya usa el libro contable y el comando de reestampado.
     *
     * Sin esto, deducir de la razón social le decía a TODA factura de Great
     * Baby que tenía que ser Dropi, y reventaba con un mensaje que se
     * contradecía solo: «la razón social Great Baby factura Dropi, pero se
     * está guardando como Great Baby».
     */
    \App\Support\Reglas::set('dropi.empresa', 'great_baby');

    // Sin origen y sin que nadie elija: Great Baby, no Dropi.
    expect(facturaLinea(['empresa_id' => $this->granBaby->id])->fresh()->linea_negocio)
        ->toBe(LineaNegocio::GreatBaby);

    // Y marcarla Great Baby a mano se guarda: ya no hay nada que mezclar.
    $aMano = facturaLinea([
        'empresa_id' => $this->granBaby->id,
        'linea_negocio' => LineaNegocio::GreatBaby,
    ]);

    expect($aMano->fresh()->linea_negocio)->toBe(LineaNegocio::GreatBaby);

    // Pero lo que nace de un pedido Dropi sigue siendo Dropi: manda el origen.
    expect(facturaLinea([
        'empresa_id' => $this->granBaby->id,
        'origen_type' => DropiPedido::class,
        'origen_id' => 1,
    ])->fresh()->linea_negocio)->toBe(LineaNegocio::Dropi);
});
