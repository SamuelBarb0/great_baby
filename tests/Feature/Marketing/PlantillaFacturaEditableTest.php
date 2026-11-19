<?php

/**
 * MKT-7 · Plantilla de factura editable.
 *
 * Lo que pidió el cliente: «No la quiero así, quiero cambiarle el color… la
 * URL del logo, cuánto quieres de grande el logo, colores, los textos».
 *
 * El editor ya existía, pero el logo NUNCA salía en el PDF: dompdf corre con
 * `enable_remote = false` e ignora en silencio una URL https, y la otra vía
 * (pegar un data URI) la rechazaba la validación con `max:2048`. Faltaba
 * además la firma.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Plantillas\Models\PlantillaDocumento;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

// PNG de 1×1 px.
const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

beforeEach(function () {
    $this->aracely = User::factory()->create(['name' => 'Aracely']);
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
});

function datosPlantilla(array $config = [], array $extra = []): array
{
    return $extra + [
        'id' => null, // el editor siempre lo manda (null = plantilla nueva)
        'tipo' => 'factura',
        'nombre' => 'Estilo GB',
        'predeterminada' => true,
        'activa' => true,
        'config' => array_replace_recursive(PlantillaDocumento::defaults(), $config),
    ];
}

function facturaDeMuestra(): FacturaVenta
{
    $cliente = Contacto::create([
        'numero_documento' => '900111222',
        'nombre_completo' => 'Bebés del Norte SAS',
        'ciudad' => 'Bogotá',
    ]);

    return FacturaVenta::create([
        'numero' => 'FV-MKT7-1',
        'contacto_id' => $cliente->id,
        'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => 100000, 'descuento' => 0, 'impuestos' => 19000,
        'total' => 119000, 'saldo' => 119000,
        'estado' => EstadoFactura::Pendiente,
    ])->load(['contacto', 'items.variante.producto', 'pagos']);
}

it('acepta un logo subido como archivo aunque pese más de 2 KB', function () {
    $logo = 'data:image/png;base64,'.str_repeat(PNG_1PX, 200);

    $this->actingAs($this->aracely)
        ->post('/app/plantillas', datosPlantilla(['logo_url' => $logo, 'logo_alto_px' => 120]))
        ->assertRedirect()->assertSessionHasNoErrors();

    $p = PlantillaDocumento::firstOrFail();
    expect($p->config['logo_url'])->toBe($logo)
        ->and($p->config['logo_alto_px'])->toBe(120);
});

it('rechaza logos por http o hacia la red interna', function (string $url) {
    $this->actingAs($this->aracely)
        ->post('/app/plantillas', datosPlantilla(['logo_url' => $url]))
        ->assertSessionHasErrors('config.logo_url');
})->with([
    'http plano' => 'http://example.com/logo.png',
    'localhost' => 'https://localhost/logo.png',
    'IP privada' => 'https://192.168.1.10/logo.png',
    'metadata' => 'https://169.254.169.254/latest',
]);

it('descarga el logo https y lo entrega embebido para que dompdf lo pinte', function () {
    Http::fake(['8.8.8.8/*' => Http::response(base64_decode(PNG_1PX), 200, ['Content-Type' => 'image/png'])]);

    $img = PlantillaDocumento::imagenParaPdf('https://8.8.8.8/logo.png');

    expect($img)->toBe('data:image/png;base64,'.PNG_1PX);
});

it('si la URL no devuelve una imagen, el PDF sale sin logo en vez de romperse', function () {
    Http::fake(['8.8.4.4/*' => Http::response('<html>no</html>', 200, ['Content-Type' => 'text/html'])]);

    expect(PlantillaDocumento::imagenParaPdf('https://8.8.4.4/logo.png'))->toBeNull();
});

it('guarda la firma con nombre, cargo e imagen', function () {
    $firma = 'data:image/png;base64,'.PNG_1PX;

    $this->actingAs($this->aracely)
        ->post('/app/plantillas', datosPlantilla([
            'mostrar_firma' => true,
            'firma_url' => $firma,
            'firma_nombre' => 'Aracely',
            'firma_cargo' => 'Gerente administrativa',
            'firma_alto_px' => 60,
        ]))
        ->assertRedirect()->assertSessionHasNoErrors();

    $cfg = PlantillaDocumento::firstOrFail()->configEfectiva();
    expect($cfg['mostrar_firma'])->toBeTrue()
        ->and($cfg['firma_url'])->toBe($firma)
        ->and($cfg['firma_nombre'])->toBe('Aracely')
        ->and($cfg['firma_cargo'])->toBe('Gerente administrativa');
});

it('pinta la firma en la factura cuando está activa', function () {
    $cfg = PlantillaDocumento::configParaPdf(array_replace_recursive(PlantillaDocumento::defaults(), [
        'mostrar_firma' => true,
        'firma_url' => 'data:image/png;base64,'.PNG_1PX,
        'firma_nombre' => 'Aracely',
        'firma_cargo' => 'Gerente administrativa',
    ]));

    $html = (string) view('cartera.pdf.factura-plantilla', [
        'factura' => facturaDeMuestra(),
        'empresa' => \App\Models\EmpresaConfig::current(),
        'qrSvg' => '',
        'publica' => false,
        'cfg' => $cfg,
    ]);

    expect($html)->toContain('class="firma"')
        ->toContain('Gerente administrativa')
        ->toContain('data:image/png;base64,'.PNG_1PX);
});

it('una versión nueva marcada como predeterminada le quita la marca a la anterior', function () {
    $this->actingAs($this->aracely)->post('/app/plantillas', datosPlantilla())->assertRedirect()->assertSessionHasNoErrors();
    $primera = PlantillaDocumento::firstOrFail();

    // «Duplicar» manda la misma config sin id y sin predeterminada…
    $this->actingAs($this->aracely)
        ->post('/app/plantillas', datosPlantilla([], ['nombre' => 'Estilo GB (copia)', 'predeterminada' => false]))
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(PlantillaDocumento::paraTipo('factura')->id)->toBe($primera->id);

    // …y al marcarla, pasa a ser la que usan las facturas.
    $copia = PlantillaDocumento::where('nombre', 'Estilo GB (copia)')->firstOrFail();
    $this->actingAs($this->aracely)
        ->post('/app/plantillas', datosPlantilla([], ['id' => $copia->id, 'nombre' => $copia->nombre, 'predeterminada' => true]))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(PlantillaDocumento::paraTipo('factura')->id)->toBe($copia->id)
        ->and($primera->fresh()->predeterminada)->toBeFalse();
});
