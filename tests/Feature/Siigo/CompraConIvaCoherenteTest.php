<?php

/**
 * Lo que el ERP dice que costó la compra tiene que ser lo que SIIGO calcula.
 *
 * Encontrado el 2026-10-14: una orden de compra de $240.000 **sin IVA** se
 * rechazaba con
 *
 *   invalid_total_payments · "The total payments must be equal to the total
 *   purchase. The total purchase calculated is 285600"
 *
 * $285.600 − $240.000 = $45.600, exactamente el 19%. El payload le adjuntaba
 * el impuesto a TODA línea sin mirar la orden, así que SIIGO liquidaba un
 * total distinto del que el ERP mandaba en `payments` y rechazaba la compra
 * entera. Toda compra exenta o al 0% era irrecuperable.
 */

use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Models\Contacto;
use App\Models\ReglaNegocio;
use App\Models\User;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    // `phpunit.xml` fuerza `SIIGO_DRIVER=fake`, y en ese modo el cliente
    // responde solo, sin tocar la red: no habría petición que inspeccionar.
    // Acá se quiere el camino real, interceptado con `Http::fake`.
    config(['siigo.driver' => 'real']);

    // El id del IVA 19% en SIIGO tiene que estar configurado para que el
    // adjunto de impuestos sea posible; si no, el test no probaría nada.
    ReglaNegocio::updateOrCreate(
        ['clave' => 'siigo.tax_id_iva_19'],
        ['valor' => '1270', 'tipo' => 'string', 'grupo' => 'siigo', 'etiqueta' => 'IVA 19% en SIIGO']
    );
    // Sin tipo de pago configurado el payload ni siquiera lleva `payments`,
    // que es justo lo que se quiere comprobar.
    ReglaNegocio::updateOrCreate(
        ['clave' => 'siigo.payment_type_compra_default'],
        ['valor' => '11154', 'tipo' => 'string', 'grupo' => 'siigo', 'etiqueta' => 'Tipo de pago en compras']
    );
    ReglaNegocio::updateOrCreate(
        ['clave' => 'siigo.doc_type_compra'],
        ['valor' => '2377', 'tipo' => 'string', 'grupo' => 'siigo', 'etiqueta' => 'Tipo documento compra']
    );
    App\Support\Reglas::limpiarRequestCache();

    // Sin credenciales el cliente corta antes de llamar y no habría payload
    // que mirar. Son de mentira: la llamada se intercepta con Http::fake.
    App\Modules\Siigo\Models\SiigoConfig::current()->forceFill([
        'username' => 'test@siigo.test', 'access_key' => 'llave-de-prueba',
        'partner_id' => 'GreatBabyERP', 'activo' => true, 'push_auto' => true,
        'tipo_documento_id' => 28006, 'seller_id' => 916, 'payment_type_id' => 11143,
    ])->save();
    App\Modules\Siigo\Models\SiigoConfig::invalidarPushAutoCache();

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'BOD-1', 'nombre' => 'Bodega principal',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $this->proveedor = Contacto::create([
        'numero_documento' => '901000111', 'nombre_completo' => 'Textiles Andinos SAS',
        'es_proveedor' => true,
    ]);
    $this->producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GB-IVA', 'nombre' => 'Pijama algodón',
        'activo' => true, 'desglose_stock' => false, 'precio_proveedor' => 12000,
    ]));
    $this->producto->forceFill(['siigo_id' => 'abc-123', 'siigo_code' => 'GB-IVA'])->save();
});

/** Arma OC + recepción con el IVA que se le indique, y devuelve el payload. */
function payloadDeCompra($test, float $ivaPct): array
{
    $iva = round(240000 * $ivaPct / 100, 2);

    $oc = OrdenCompra::create([
        'numero' => 'OC-'.fake()->unique()->numerify('######'),
        'proveedor_id' => $test->proveedor->id, 'bodega_id' => $test->bodega->id,
        'tipo' => 'nacional', 'moneda' => 'COP', 'tasa_cambio' => 1,
        'estado' => 'aprobada', 'fecha_emision' => now()->toDateString(),
        'subtotal' => 240000, 'iva' => $iva, 'total' => 240000 + $iva,
        'creado_por' => User::factory()->create()->id,
    ]);

    $item = $oc->items()->create([
        'producto_id' => $test->producto->id,
        'descripcion' => 'Pijama algodón', 'cantidad' => 20,
        'precio_unit' => 12000, 'iva_pct' => $ivaPct, 'descuento_pct' => 0,
        'subtotal' => 240000, 'iva' => $iva, 'total' => 240000 + $iva,
    ]);

    $rec = RecepcionCompra::create([
        'numero' => 'REC-'.fake()->unique()->numerify('######'),
        'orden_id' => $oc->id, 'bodega_id' => $test->bodega->id,
        'fecha_recepcion' => now()->toDateString(),
        'estado' => 'confirmada', 'total_recibido' => 240000 + $iva,
    ]);
    $rec->items()->create([
        'orden_item_id' => $item->id, 'producto_id' => $test->producto->id,
        'cantidad_recibida' => 20, 'costo_unit' => 12000, 'subtotal' => 240000,
        'clasificacion' => 'apto',
    ]);

    /*
     * El payload se arma dentro de `emitirCompra()`, que además lo manda. En
     * vez de refactorizar código que funciona, se intercepta la llamada y se
     * mira lo que de verdad salió por el cable.
     */
    Illuminate\Support\Facades\Http::fake([
        '*/auth' => Illuminate\Support\Facades\Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/v1/purchases' => Illuminate\Support\Facades\Http::response(['id' => 'siigo-compra-1', 'number' => 1], 201),
        '*' => Illuminate\Support\Facades\Http::response([], 200),
    ]);

    try {
        app(App\Modules\Siigo\Services\SiigoEmisionService::class)->emitirCompra($rec->fresh());
    } catch (\Throwable) {
        // Lo que importa es el payload que salió, no si el resto del flujo cerró.
    }

    $enviado = [];
    Illuminate\Support\Facades\Http::assertSent(function ($req) use (&$enviado) {
        if (str_contains($req->url(), '/v1/purchases')) {
            $enviado = $req->data();
        }

        return true;
    });

    return $enviado;
}

it('una línea sin IVA NO lleva impuestos a SIIGO', function () {
    // Es el caso que rompía: SIIGO liquidaba 19% de más y rechazaba todo.
    $p = payloadDeCompra($this, 0);

    expect($p['items'][0])->not->toHaveKey('taxes');
});

it('una línea con IVA 19% sí los lleva', function () {
    $p = payloadDeCompra($this, 19);

    expect($p['items'][0])->toHaveKey('taxes')
        ->and($p['items'][0]['taxes'][0]['id'])->toBe(1270);
});

it('el pago declarado coincide con el total de la orden sin IVA', function () {
    $p = payloadDeCompra($this, 0);

    expect((float) $p['payments'][0]['value'])->toEqual(240000.0);
});

it('el pago declarado coincide con el total de la orden con IVA', function () {
    // 240.000 + 19% = 285.600, que es justo lo que SIIGO calcula por su lado.
    $p = payloadDeCompra($this, 19);

    expect((float) $p['payments'][0]['value'])->toEqual(285600.0);
});
