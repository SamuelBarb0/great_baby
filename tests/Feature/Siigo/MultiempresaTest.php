<?php

/**
 * Multiempresa · Great Baby, Arsutex y el e-commerce son tres razones
 * sociales: cada una con su NIT, su prefijo y su cuenta de SIIGO.
 *
 * Lo que no puede pasar nunca es que una factura salga con el NIT o por la
 * cuenta de SIIGO de otra empresa: eso es una factura mal emitida ante la
 * DIAN. Por eso varios de estos tests miran justo eso.
 */

use App\Models\Contacto;
use App\Models\EmpresaConfig;
use App\Models\User;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\FacturaVentaItem;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Cartera\Support\NumeroFactura;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Services\SiigoEmisionService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->gb = EmpresaConfig::porCodigo('great_baby');
    $this->arsutex = EmpresaConfig::porCodigo('arsutex');
    $this->arsutex->update(['razon_social' => 'ARSUTEX S.A.S.', 'nit' => '900111222-3',
                            'prefijo_dian' => 'ARS', 'activa' => true]);

    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->cliente = Contacto::create([
        'numero_documento' => '900777888', 'nombre_completo' => 'Almacenes Grandes SAS', 'ciudad' => 'Bogotá',
    ]);
    // Ya existe en la cuenta de Great Baby (no es asignable en masa).
    $this->cliente->forceFill(['siigo_id' => 'id-en-la-cuenta-de-great-baby'])->save();
});

function siigoDeArsutex(): SiigoConfig
{
    $cfg = SiigoConfig::deEmpresa(EmpresaConfig::porCodigo('arsutex')->id);
    $cfg->fill([
        'username' => 'api@arsutex.co', 'ambiente' => 'sandbox', 'activo' => true,
        'tipo_documento_id' => 9001, 'seller_id' => 9002, 'payment_type_id' => 9003,
        'mapa_impuestos' => ['iva' => ['19' => 9019], 'retenciones' => []],
    ]);
    $cfg->access_key = 'llave-arsutex';
    $cfg->save();

    return $cfg;
}

function facturaDeEmpresa(EmpresaConfig $empresa, Contacto $cliente): FacturaVenta
{
    $f = FacturaVenta::create([
        'empresa_id' => $empresa->id,
        'numero' => NumeroFactura::siguiente($empresa),
        'contacto_id' => $cliente->id,
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => 100000, 'descuento' => 0, 'impuestos' => 19000, 'total' => 119000, 'saldo' => 119000,
        'estado' => EstadoFactura::Pendiente, 'es_electronica' => true,
    ]);
    $producto = App\Modules\Dropi\Models\Producto::sinKardexAutomatico(fn () => App\Modules\Dropi\Models\Producto::create([
        'referencia' => 'TET-'.fake()->unique()->numberBetween(100, 99999), 'nombre' => 'Tetero 250 ml',
        'desglose_stock' => false,
    ]));
    FacturaVentaItem::create([
        'factura_id' => $f->id, 'producto_id' => $producto->id, 'descripcion' => 'Tetero 250 ml',
        'cantidad' => 1, 'precio_unit' => 100000, 'impuesto_pct' => 19, 'subtotal' => 100000,
    ]);

    return $f;
}

it('la migración deja a Great Baby como principal y su SIIGO de siempre', function () {
    expect($this->gb->es_principal)->toBeTrue()
        ->and(EmpresaConfig::current()->id)->toBe($this->gb->id)
        ->and(EmpresaConfig::porCodigo('ecommerce')->activa)->toBeFalse()
        ->and(SiigoConfig::current()->empresa_id)->toBe($this->gb->id)
        // La cuenta de Arsutex es otra fila, no la de Great Baby.
        ->and(SiigoConfig::deEmpresa($this->arsutex->id)->id)->not->toBe(SiigoConfig::current()->id);
});

it('cada empresa numera con su prefijo y su propia secuencia', function () {
    $a1 = NumeroFactura::siguiente($this->arsutex);
    $a2 = NumeroFactura::siguiente($this->arsutex);
    $g1 = NumeroFactura::siguiente();

    expect($a1)->toStartWith('ARS-')->toEndWith('-0001')
        ->and($a2)->toEndWith('-0002')
        ->and($g1)->not->toStartWith('ARS-')->toEndWith('-0001');
});

it('una empresa sin prefijo no numera', function () {
    $this->arsutex->update(['prefijo_dian' => '']);

    NumeroFactura::siguiente($this->arsutex->fresh());
})->throws(RuntimeException::class, 'no tiene prefijo');

it('la factura de Arsutex sale por la cuenta de SIIGO de Arsutex', function () {
    siigoDeArsutex();
    $f = facturaDeEmpresa($this->arsutex, $this->cliente);
    // phpunit corre con el driver falso, que ni toca HTTP: acá se quiere ver
    // qué petición sale y con qué credencial.
    config(['siigo.driver' => 'real']);

    Http::fake([
        'api.siigo.com/auth' => Http::response(['access_token' => 'tok-arsutex', 'expires_in' => 86400]),
        'api.siigo.com/v1/customers?*' => Http::response(['results' => []]),
        'api.siigo.com/v1/customers' => Http::response(['id' => 'cli-arsutex'], 201),
        'api.siigo.com/v1/invoices' => Http::response(['id' => 'inv-1', 'name' => 'ARS-1', 'number' => 1,
            'stamp' => ['status' => 'Accepted', 'cufe' => str_repeat('a', 96)]], 201),
        '*' => Http::response([], 200),
    ]);

    try { app(SiigoEmisionService::class)->emitir($f); } catch (Throwable) { /* lo de después de SIIGO no interesa acá */ }

    $auth = collect(Http::recorded())->map(fn ($par) => $par[0])->first(fn (HttpRequest $r) => str_ends_with($r->url(), '/auth'));
    $factura = collect(Http::recorded())->map(fn ($par) => $par[0])
        ->first(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/invoices'));

    expect($auth['username'])->toBe('api@arsutex.co')
        ->and($factura)->not->toBeNull()
        ->and($factura->header('Authorization')[0])->toBe('Bearer tok-arsutex')
        ->and($factura['document']['id'])->toBe(9001)
        ->and($factura['seller'])->toBe(9002)
        ->and($factura['items'][0]['taxes'])->toBe([['id' => 9019]]);

    // El cliente se creó en la cuenta de Arsutex (tener id en la de Great Baby
    // no cuenta), sin pisar su id de Great Baby.
    $altaCliente = collect(Http::recorded())->map(fn ($par) => $par[0])
        ->first(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/customers'));
    expect($altaCliente?->header('Authorization')[0])->toBe('Bearer tok-arsutex')
        ->and($this->cliente->fresh()->siigo_id)->toBe('id-en-la-cuenta-de-great-baby');
});

it('con la cuenta de Arsutex apagada no emite por la de Great Baby', function () {
    $f = facturaDeEmpresa($this->arsutex, $this->cliente);
    Http::fake();

    expect(fn () => app(SiigoEmisionService::class)->emitir($f))
        ->toThrow(RuntimeException::class, 'Arsutex');
    Http::assertNothingSent();
});

it('un IVA sin id en la cuenta de Arsutex frena la emisión', function () {
    siigoDeArsutex()->update(['mapa_impuestos' => ['iva' => [], 'retenciones' => []]]);
    $f = facturaDeEmpresa($this->arsutex, $this->cliente);
    $svc = app(SiigoEmisionService::class);

    expect(fn () => (new ReflectionMethod($svc, 'construirPayload'))
        ->invoke($svc, $f->fresh(), SiigoConfig::deEmpresa($this->arsutex->id)))
        ->toThrow(RuntimeException::class, 'Configuración → Empresas');
});

it('el recibo de una factura de Arsutex no se manda por la cuenta de Great Baby', function () {
    $f = facturaDeEmpresa($this->arsutex, $this->cliente);
    $f->forceFill(['siigo_id' => 'inv-1'])->save();
    $p = new PagoVenta(['factura_id' => $f->id]);
    $p->setRelation('factura', $f);
    Http::fake();

    expect(fn () => app(SiigoEmisionService::class)->emitirVoucher($p))
        ->toThrow(RuntimeException::class, 'fase 2');
    Http::assertNothingSent();
});

it('el pedido elige la empresa y la factura la hereda', function () {
    $p = PedidoCliente::create([
        'numero' => 'PB2B-T-1', 'contacto_id' => $this->cliente->id, 'estado' => 'aprobado',
        'empresa_id' => $this->arsutex->id, 'subtotal' => 100000, 'iva' => 19000, 'total' => 119000,
    ]);
    $producto = App\Modules\Dropi\Models\Producto::sinKardexAutomatico(fn () => App\Modules\Dropi\Models\Producto::create([
        'referencia' => 'TET-B2B', 'nombre' => 'Tetero', 'desglose_stock' => false,
    ]));
    $p->items()->create([
        'producto_id' => $producto->id,
        'sku_snapshot' => 'X', 'descripcion_snapshot' => 'Tetero', 'cantidad' => 1,
        'precio_unitario' => 100000, 'iva_porcentaje' => 19, 'subtotal' => 100000, 'total' => 119000,
    ]);

    $this->actingAs($this->aracely)->post("/app/pedidos-b2b/{$p->id}/facturar");

    $f = FacturaVenta::where('origen_id', $p->id)->first();
    expect($f)->not->toBeNull()
        ->and($f->empresa_id)->toBe($this->arsutex->id)
        ->and($f->numero)->toStartWith('ARS-')
        // Cuenta de Arsutex apagada: nace interna, no electrónica por la de otra.
        ->and((bool) $f->es_electronica)->toBeFalse();
});

it('la empresa se cambia antes de facturar, no después', function () {
    $p = PedidoCliente::create([
        'numero' => 'PB2B-T-2', 'contacto_id' => $this->cliente->id, 'estado' => 'enviado',
        'subtotal' => 0, 'iva' => 0, 'total' => 0,
    ]);

    $this->actingAs($this->aracely)->post("/app/pedidos-b2b/{$p->id}/empresa", ['empresa_id' => $this->arsutex->id])
        ->assertSessionHas('success');
    expect($p->fresh()->empresa_id)->toBe($this->arsutex->id);

    // Una empresa apagada no se puede elegir.
    $this->actingAs($this->aracely)->post("/app/pedidos-b2b/{$p->id}/empresa",
        ['empresa_id' => EmpresaConfig::porCodigo('ecommerce')->id])->assertSessionHasErrors('empresa_id');

    $p->update(['factura_id' => facturaDeEmpresa($this->gb, $this->cliente)->id]);
    $this->actingAs($this->aracely)->post("/app/pedidos-b2b/{$p->id}/empresa", ['empresa_id' => $this->gb->id])
        ->assertStatus(422);
});

it('el PDF y el QR DIAN llevan el NIT de la empresa que facturó', function () {
    $f = facturaDeEmpresa($this->arsutex, $this->cliente);
    $f->forceFill(['cufe' => str_repeat('a', 96)])->save();

    // El QR lleva el NIT del emisor: con el de Great Baby, la DIAN no lo encuentra.
    expect(app(App\Modules\Siigo\Services\QrDianService::class)->contenidoQr($f->fresh()))
        ->toContain('900111222')->not->toContain('901738354');

    $this->actingAs($this->aracely)->get("/cartera/factura/{$f->id}/pdf")->assertOk();
});

it('activar una empresa exige NIT y prefijo', function () {
    $eco = EmpresaConfig::porCodigo('ecommerce');

    $this->actingAs($this->aracely)->post('/app/empresa', [
        'id' => $eco->id, 'activa' => true, 'razon_social' => 'Tienda Online SAS', 'nit' => '', 'prefijo_dian' => '',
    ])->assertSessionHasErrors(['nit', 'prefijo_dian']);

    $this->actingAs($this->aracely)->post('/app/empresa', [
        'id' => $eco->id, 'activa' => true, 'razon_social' => 'Tienda Online SAS', 'nit' => '901999888-1', 'prefijo_dian' => 'ECO',
    ])->assertSessionHas('success');

    expect($eco->fresh()->activa)->toBeTrue()
        ->and($eco->fresh()->razon_social)->toBe('Tienda Online SAS')
        // Guardar otra empresa no toca a Great Baby.
        ->and($this->gb->fresh()->razon_social)->toBe('GREAT BABY S.A.S.');
});

it('guarda la cuenta de SIIGO de la empresa con la llave cifrada', function () {
    $this->actingAs($this->aracely)->post("/app/empresa/{$this->arsutex->id}/siigo", [
        'username' => 'api@arsutex.co', 'access_key' => 'secreta', 'ambiente' => 'sandbox', 'activo' => true,
        'tipo_documento_id' => 1, 'seller_id' => 2, 'payment_type_id' => 3,
        'iva' => ['19' => 555], 'retenciones' => [],
    ])->assertSessionHas('success');

    $cfg = SiigoConfig::deEmpresa($this->arsutex->id);
    expect($cfg->access_key)->toBe('secreta')
        ->and(DB::table('siigo_config')->where('id', $cfg->id)->value('access_key'))->not->toBe('secreta')
        ->and($cfg->ivaSiigoId(19))->toBe(555)
        ->and(SiigoConfig::current()->username)->not->toBe('api@arsutex.co');

    // La de Great Baby no se toca desde acá.
    $this->actingAs($this->aracely)->post("/app/empresa/{$this->gb->id}/siigo", ['ambiente' => 'sandbox'])
        ->assertStatus(422);
});
