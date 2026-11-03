<?php

/**
 * Lo que viaja a SIIGO cuando la factura es un reparto.
 *
 * El contrato es uno solo y es el que hace que todo lo demás cuadre: **la
 * factura que se crea en SIIGO suma exactamente `monto_fiscal`**, que es lo
 * mismo que postea el asiento fiscal. Si las dos cifras se separan, el balance
 * fiscal del ERP deja de cuadrar contra SIIGO y eso sólo se descubre en el
 * cierre, cuando ya nadie recuerda qué factura fue.
 *
 * Se eligió declarar MENOS UNIDADES AL PRECIO REAL en vez de las mismas
 * unidades a precio menor: un precio unitario inventado es más difícil de
 * sostener ante la DIAN que un conteo que no cuadra, y el inventario de verdad
 * vive en el ERP. Es una decisión contable, así que se puede cambiar con el
 * ajuste `siigo.reparto_modo` — hay un test de eso también.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Actions\CrearFacturaManual;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Siigo\Services\SiigoEmisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    // `Reglas` cachea en una propiedad estatica que vive lo que el proceso: en
    // produccion es por request y no molesta, pero entre tests arrastraba el
    // modo de reparto de uno al siguiente.
    \App\Support\Reglas::limpiarRequestCache();

    $this->cliente = Contacto::create([
        'numero_documento' => '900999111',
        'nombre_completo' => 'Cliente del Reparto SIIGO SAS',
        'es_cliente' => true,
    ]);

    // Dos productos, para que el reparto tenga que repartir entre varias líneas
    // y se vea si el redondeo descuadra.
    // SIIGO rechaza una factura cuyo IVA no este mapeado, asi que el impuesto
    // del 19 % tiene que existir con su id de alla.
    \Illuminate\Support\Facades\DB::table('impuestos')->updateOrInsert(
        ['codigo' => 'IVA19'],
        ['nombre' => 'IVA 19%', 'tipo' => 'iva', 'porcentaje' => 19, 'activo' => 1,
         'siigo_id' => 1234, 'created_at' => now(), 'updated_at' => now()],
    );

    $this->uno = Producto::create(['referencia' => 'RP-1', 'nombre' => 'Producto uno', 'activo' => true]);
    $this->dos = Producto::create(['referencia' => 'RP-2', 'nombre' => 'Producto dos', 'activo' => true]);
    foreach ([$this->uno, $this->dos] as $p) {
        $p->forceFill(['siigo_id' => 'sg-'.$p->referencia])->save();
    }
});

function facturaRepartida(array $lineas, float $declarado): FacturaVenta
{
    return CrearFacturaManual::run([
        'contacto_id' => test()->cliente->id,
        'fecha_emision' => now()->toDateString(),
        'destino_fiscal' => DestinoFiscal::Reparto->value,
        'monto_fiscal' => $declarado,
        'motivo_destino' => 'Acuerdo comercial',
        'items' => $lineas,
    ]);
}

/** El payload tal como se le mandaría a SIIGO, sin llamar a nadie. */
function payloadDe(FacturaVenta $f): array
{
    $servicio = app(SiigoEmisionService::class);

    $construir = new ReflectionMethod($servicio, 'construirPayload');
    $construir->setAccessible(true);

    $config = \App\Modules\Siigo\Models\SiigoConfig::current();
    $config->forceFill([
        'activo' => true, 'tipo_documento_id' => 1, 'seller_id' => 1, 'payment_type_id' => 1,
    ])->save();

    return $construir->invoke($servicio, $f->fresh()->load('items.producto'), $config->fresh());
}

/** Lo que SIIGO va a calcular como total, a partir de las líneas del payload. */
function totalQueCalculaSiigo(array $payload, float $ivaPct = 0): float
{
    $base = 0.0;
    foreach ($payload['items'] as $l) {
        $base += $l['quantity'] * $l['price'] * (1 - ($l['discount'] ?? 0) / 100);
    }

    return round($base * (1 + $ivaPct / 100), 2);
}

it('la factura de SIIGO suma exactamente lo declarado', function () {
    // $100.000 de venta real · $20.000 declarados · sin IVA para aislar el caso.
    $f = facturaRepartida([
        ['producto_id' => $this->uno->id, 'descripcion' => 'Producto uno', 'cantidad' => 10, 'precio_unit' => 10_000],
    ], 20_000);

    $payload = payloadDe($f);

    expect(totalQueCalculaSiigo($payload))->toEqual(20_000.0)
        ->and($payload['payments'][0]['value'])->toEqual(20_000.0);
});

it('reparte entre varias líneas sin perder un peso', function () {
    // Una proporción fea a propósito: 1/3 de un total con centavos.
    $f = facturaRepartida([
        ['producto_id' => $this->uno->id, 'descripcion' => 'Producto uno', 'cantidad' => 3, 'precio_unit' => 33_333.33],
        ['producto_id' => $this->dos->id, 'descripcion' => 'Producto dos', 'cantidad' => 7, 'precio_unit' => 14_285.71],
    ], 33_333.33);

    $payload = payloadDe($f);

    expect(totalQueCalculaSiigo($payload))->toEqual(33_333.33);
});

it('mantiene el precio unitario real y baja la cantidad', function () {
    /*
     * Éste es el modo elegido. Lo que se defiende ante la DIAN es el precio:
     * tiene que ser el mismo que en cualquier otra factura del producto.
     */
    $f = facturaRepartida([
        ['producto_id' => $this->uno->id, 'descripcion' => 'Producto uno', 'cantidad' => 10, 'precio_unit' => 10_000],
    ], 20_000);

    $linea = payloadDe($f)['items'][0];

    expect((float) $linea['price'])->toEqual(10_000.0)   // precio intacto
        ->and((float) $linea['quantity'])->toEqual(2.0); // 2 de 10 unidades
});

it('con el modo «precio» hace lo contrario: mismas unidades, precio menor', function () {
    // La decisión es contable, no técnica: se cambia por ajuste.
    \App\Support\Reglas::set('siigo.reparto_modo', 'precio');
    \App\Support\Reglas::limpiarRequestCache();

    $f = facturaRepartida([
        ['producto_id' => $this->uno->id, 'descripcion' => 'Producto uno', 'cantidad' => 10, 'precio_unit' => 10_000],
    ], 20_000);

    $linea = payloadDe($f)['items'][0];

    expect((float) $linea['quantity'])->toEqual(10.0)   // cantidad intacta
        ->and((float) $linea['price'])->toEqual(2_000.0)
        ->and(totalQueCalculaSiigo(payloadDe($f)))->toEqual(20_000.0);
});

it('el IVA viaja escalado porque se escala la base', function () {
    // $119.000 totales (100.000 + 19 %) · se declara la quinta parte.
    $f = facturaRepartida([
        ['producto_id' => $this->uno->id, 'descripcion' => 'Producto uno',
         'cantidad' => 10, 'precio_unit' => 10_000, 'impuesto_pct' => 19],
    ], 23_800);

    $payload = payloadDe($f);

    // La base declarada es 20.000 y SIIGO le suma el 19 % → 23.800.
    expect(totalQueCalculaSiigo($payload, 19))->toEqual(23_800.0)
        ->and($payload['items'][0])->toHaveKey('taxes');
});

it('respeta el descuento de la línea al repartir', function () {
    // 10 × $10.000 con 10 % de descuento = $90.000. Se declara un tercio.
    $f = facturaRepartida([
        ['producto_id' => $this->uno->id, 'descripcion' => 'Producto uno',
         'cantidad' => 10, 'precio_unit' => 10_000, 'descuento_pct' => 10],
    ], 30_000);

    $payload = payloadDe($f);

    expect((float) $payload['items'][0]['discount'])->toEqual(10.0)
        ->and(totalQueCalculaSiigo($payload))->toEqual(30_000.0);
});

it('avisa cuando el modo «precio» no puede dar exacto', function () {
    /*
     * El precio solo tiene dos decimales: con 10 unidades y 10 % de descuento
     * los totales alcanzables van de nueve en nueve centavos, asi que $30.000
     * cae en el medio y no hay precio que lo de.
     *
     * Mandar esos tres centavos de diferencia seria peor que no mandar nada:
     * aparecen en la conciliacion meses despues, sin explicacion.
     */
    \App\Support\Reglas::set('siigo.reparto_modo', 'precio');
    \App\Support\Reglas::limpiarRequestCache();

    $f = facturaRepartida([
        ['producto_id' => $this->uno->id, 'descripcion' => 'Producto uno',
         'cantidad' => 10, 'precio_unit' => 10_000, 'descuento_pct' => 10],
    ], 30_000);

    expect(fn () => payloadDe($f))->toThrow(RuntimeException::class, 'no da exacto');
});

it('una factura que no se declara NO se puede emitir', function () {
    /*
     * Sin esta guarda bastaba con que algo disparara la emisión para mandar a
     * la DIAN justo lo que se había decidido dejar afuera. Y eso no se deshace:
     * se corrige con nota crédito.
     */
    $f = CrearFacturaManual::run([
        'contacto_id' => $this->cliente->id,
        'fecha_emision' => now()->toDateString(),
        'destino_fiscal' => DestinoFiscal::Interno->value,
        'motivo_destino' => 'Venta sin factura electrónica',
        'items' => [['producto_id' => $this->uno->id, 'descripcion' => 'Producto uno', 'cantidad' => 1, 'precio_unit' => 50_000]],
    ]);

    $servicio = app(SiigoEmisionService::class);
    $validar = new ReflectionMethod($servicio, 'validarElegible');
    $validar->setAccessible(true);

    expect(fn () => $validar->invoke($servicio, $f->load('items', 'contacto')))
        ->toThrow(RuntimeException::class, 'no va a SIIGO');
});

it('un reparto con monto inválido se corta con un mensaje que dice qué hacer', function () {
    $f = facturaRepartida([
        ['producto_id' => $this->uno->id, 'descripcion' => 'Producto uno', 'cantidad' => 1, 'precio_unit' => 50_000],
    ], 20_000);

    // Alguien deja el monto en cero por fuera de la pantalla.
    $f->forceFill(['monto_fiscal' => 0])->saveQuietly();

    expect(fn () => payloadDe($f))->toThrow(RuntimeException::class, 'porción válida');
});
