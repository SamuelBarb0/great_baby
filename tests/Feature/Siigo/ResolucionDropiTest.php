<?php

/**
 * Dropi factura con Great Baby y su propia resolución DIAN (7-nov).
 *
 * Great Baby, los puntos de venta y el e-commerce son la misma empresa: un NIT
 * y una cuenta de SIIGO. SIIGO separa la contabilidad por la resolución, así
 * que lo que no puede pasar es que una factura de Dropi salga con la de Great
 * Baby. Si la de Dropi no está configurada, la factura no sale.
 */

use App\Console\Commands\DropiReestamparLineaCommand;
use App\Models\Contacto;
use App\Models\EmpresaConfig;
use App\Models\User;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\FacturaVentaItem;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Filament\Pages\SiigoDropi;
use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Models\SiigoDocumentType;
use App\Modules\Siigo\Services\SiigoEmisionService;
use App\Support\Reglas;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $cfg = SiigoConfig::current();
    $cfg->fill([
        'username' => 'api@greatbaby.co', 'ambiente' => 'sandbox', 'activo' => true,
        'tipo_documento_id' => 100, 'tipo_documento_dropi_id' => 200, 'seller_id' => 300, 'payment_type_id' => 400,
    ]);
    $cfg->access_key = 'llave-gb';
    $cfg->save();

    DB::table('impuestos')->insert(['codigo' => 'IVA19', 'nombre' => 'IVA 19%', 'tipo' => 'iva', 'porcentaje' => 19,
        'activo' => true, 'siigo_id' => 1919, 'created_at' => now(), 'updated_at' => now()]);

    $this->cliente = Contacto::create(['numero_documento' => '222222222222', 'nombre_completo' => 'Consumidor final', 'ciudad' => 'Bogotá']);
    $this->cliente->forceFill(['siigo_id' => 'cli-gb'])->save();
});

function resFactura(LineaNegocio $linea, Contacto $cliente): FacturaVenta
{
    $f = FacturaVenta::create([
        'empresa_id' => EmpresaConfig::current()->id,
        'linea_negocio' => $linea,
        'numero' => ($linea === LineaNegocio::Dropi ? 'DRP-' : 'FV-').fake()->unique()->numberBetween(1000, 99999),
        'contacto_id' => $cliente->id,
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'subtotal' => 100000, 'descuento' => 0, 'impuestos' => 19000, 'total' => 119000, 'saldo' => 119000,
        'estado' => EstadoFactura::Pendiente, 'es_electronica' => true,
    ]);
    $producto = App\Modules\Dropi\Models\Producto::sinKardexAutomatico(fn () => App\Modules\Dropi\Models\Producto::create([
        'referencia' => 'COC-'.fake()->unique()->numberBetween(100, 99999), 'nombre' => 'Coche', 'desglose_stock' => false,
    ]));
    // En la cuenta de Great Baby el producto tiene que existir ya en SIIGO.
    $producto->forceFill(['siigo_id' => 'prod-'.$producto->id])->save();
    FacturaVentaItem::create([
        'factura_id' => $f->id, 'producto_id' => $producto->id, 'descripcion' => 'Coche',
        'cantidad' => 1, 'precio_unit' => 100000, 'impuesto_pct' => 19, 'subtotal' => 100000,
    ]);

    return $f;
}

/** Emite contra un SIIGO falso y devuelve la factura que se le mandó (o null). */
function resEmitir(FacturaVenta $f): ?HttpRequest
{
    config(['siigo.driver' => 'real']);
    Http::fake([
        'api.siigo.com/auth' => Http::response(['access_token' => 'tok-gb', 'expires_in' => 86400]),
        'api.siigo.com/v1/invoices' => Http::response(['id' => 'inv-1', 'name' => 'X-1', 'number' => 1,
            'stamp' => ['status' => 'Accepted', 'cufe' => str_repeat('a', 96)]], 201),
        '*' => Http::response([], 200),
    ]);

    try { app(SiigoEmisionService::class)->emitir($f); } catch (Throwable) { /* lo de después de SIIGO no interesa acá */ }

    return collect(Http::recorded())->map(fn ($par) => $par[0])
        ->first(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/invoices'));
}

it('la factura de Dropi sale por la cuenta de Great Baby con la resolución de Dropi', function () {
    $enviada = resEmitir(resFactura(LineaNegocio::Dropi, $this->cliente));

    expect($enviada)->not->toBeNull()
        ->and($enviada->header('Authorization')[0])->toBe('Bearer tok-gb')
        ->and($enviada['document']['id'])->toBe(200);
});

it('la factura de Great Baby sigue saliendo con la resolución de Great Baby', function () {
    $enviada = resEmitir(resFactura(LineaNegocio::GreatBaby, $this->cliente));

    expect($enviada['document']['id'])->toBe(100);
});

it('sin la resolución de Dropi no se manda nada: nunca cae a la de Great Baby', function () {
    SiigoConfig::current()->forceFill(['tipo_documento_dropi_id' => null])->save();
    $f = resFactura(LineaNegocio::Dropi, $this->cliente);
    config(['siigo.driver' => 'real']);
    Http::fake();

    expect(fn () => app(SiigoEmisionService::class)->emitir($f))
        ->toThrow(RuntimeException::class, 'Resolución SIIGO para Dropi');
    expect(collect(Http::recorded())->map(fn ($par) => $par[0])
        ->first(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/invoices')))->toBeNull();
});

it('«SIIGO de Dropi» está en el menú del panel Dropi, sólo para quien administra', function () {
    $aracely = User::factory()->create();
    $aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $alistador = User::factory()->create();
    $alistador->assignRole(Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']));

    // Se llega desde el menú del panel, no sólo montando la página.
    $this->actingAs($aracely)->get('/admin/mis-productos-dropi')->assertOk()
        ->assertSee('SIIGO de Dropi')
        ->assertSee('/admin/siigo-dropi', false);
    $this->actingAs($aracely)->get('/admin/siigo-dropi')->assertOk()
        ->assertSee('Resolución SIIGO para Dropi')
        ->assertSee('api@greatbaby.co');
    $this->actingAs($alistador)->get('/admin/siigo-dropi')->assertForbidden();
});

it('«SIIGO de Dropi» guarda la resolución, deja quitarla y no acepta la de Great Baby', function () {
    Filament::setCurrentPanel('admin');
    $aracely = User::factory()->create();
    $aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->actingAs($aracely);
    SiigoConfig::current()->forceFill(['tipo_documento_dropi_id' => null])->save();

    Livewire::test(SiigoDropi::class)
        ->set('resolucion', '100')                    // la de Great Baby
        ->call('guardar')
        ->assertHasErrors('resolucion');
    expect(SiigoConfig::current()->fresh()->tipo_documento_dropi_id)->toBeNull();

    Livewire::test(SiigoDropi::class)
        ->set('resolucion', '200')
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertNotified('Resolución de Dropi guardada');
    expect(SiigoConfig::current()->fresh()->tipo_documento_dropi_id)->toBe(200);

    Livewire::test(SiigoDropi::class)
        ->assertSet('resolucion', '200')
        ->set('resolucion', '')
        ->call('guardar')
        ->assertNotified('Resolución de Dropi quitada');
    expect(SiigoConfig::current()->fresh()->tipo_documento_dropi_id)->toBeNull();
});

it('si SIIGO ya bajó las resoluciones, se elige de la lista y la de Great Baby no se puede', function () {
    Filament::setCurrentPanel('admin');
    $aracely = User::factory()->create();
    $aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->actingAs($aracely);
    SiigoDocumentType::create(['type' => 'FV', 'siigo_id' => 100, 'code' => '1', 'name' => 'Factura Great Baby', 'active' => true]);
    SiigoDocumentType::create(['type' => 'FV', 'siigo_id' => 200, 'code' => '2', 'name' => 'Factura Dropi', 'active' => true]);

    $html = Livewire::test(SiigoDropi::class)
        ->assertSee('Factura Dropi')
        ->assertSee('(Great Baby)')
        ->html();

    expect($html)->toMatch('/<option value="200"(?![^>]*disabled)[^>]*>/')   // la de Dropi se puede elegir
        ->and($html)->toMatch('/<option value="100"[^>]*disabled[^>]*>/');   // la de Great Baby no
});

it('la regla deja a Dropi en Great Baby y re-estampar no se lleva las facturas de Great Baby', function () {
    expect(setting('dropi.empresa'))->toBe('great_baby');

    $deGreatBaby = resFactura(LineaNegocio::GreatBaby, $this->cliente);

    $this->artisan(DropiReestamparLineaCommand::class, ['--aplicar' => true])->assertSuccessful();

    expect($deGreatBaby->fresh()->linea_negocio)->toBe(LineaNegocio::GreatBaby);
});

it('la migración pasa la regla guardada de «ecommerce» a Great Baby', function () {
    Reglas::set('dropi.empresa', 'ecommerce');
    $migracion = require database_path('migrations/2026_11_07_120000_siigo_resolucion_dropi.php');
    $migracion->up();
    Reglas::limpiarRequestCache();

    expect(DB::table('reglas_negocio')->where('clave', 'dropi.empresa')->value('valor'))->toBe('great_baby');
});
