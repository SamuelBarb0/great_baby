<?php

/**
 * Contra el sandbox PÚBLICO de SIIGO (sandbox@siigoapi.com), nunca contra una cuenta
 * real. No corre con la suite: sólo a mano y con red.
 *
 *   php artisan test tests/E2E/SiigoSandboxTest.php
 *
 * Responde lo que los tests con Queue::fake no pueden: si SIIGO de verdad acepta un
 * producto creado desde una orden de importación, con sus colores.
 */

use App\Modules\Compras\Services\ColoresDeImportacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Siigo\Actions\CrearProductoEnSiigo;
use App\Modules\Siigo\Actions\DesactivarProductoEnSiigo;
use App\Modules\Siigo\Clients\SiigoClient;
use App\Modules\Siigo\Models\SiigoConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $usuario = (string) env('SIIGO_SANDBOX_USER');
    if ($usuario !== 'sandbox@siigoapi.com' || ! env('SIIGO_SANDBOX_KEY')) {
        $this->markTestSkipped('Falta el sandbox público de SIIGO en el .env.');
    }

    config(['siigo.driver' => 'real']);
    SiigoConfig::query()->delete();
    $this->config = SiigoConfig::create([
        'username' => $usuario, 'access_key' => env('SIIGO_SANDBOX_KEY'),
        'partner_id' => 'GreatBabyERP', 'ambiente' => 'sandbox', 'activo' => true, 'push_auto' => false,
    ]);
    $this->app->instance(SiigoClient::class, new SiigoClient($this->config));
    $this->client = app(SiigoClient::class);

    $grupos = $this->client->request('GET', 'v1/account-groups')->json();
    $this->grupo = (int) collect($grupos)->firstWhere('active', true)['id'];
});

it('acepta un producto de una orden con sus colores y Sin identificar', function () {
    $ref = 'GBT'.now()->format('ymdHis');
    $producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => $ref, 'nombre' => 'Prueba colores '.$ref, 'activo' => true,
        'desglose_stock' => false, 'siigo_account_group_override' => $this->grupo,
    ]));

    $colores = app(ColoresDeImportacion::class);
    foreach (['Azul', 'Morado claro', ColoresDeImportacion::SIN_IDENTIFICAR] as $color) {
        $colores->varianteDe($producto->fresh(), $color);
    }

    $resultado = app(CrearProductoEnSiigo::class)->handle($producto->fresh());
    fwrite(STDERR, "\nCREAR ".json_encode($resultado, JSON_UNESCAPED_UNICODE)."\n");

    $variantes = $producto->variantes()->get();
    foreach ($variantes as $v) {
        $r = $this->client->request('GET', 'v1/products?code='.urlencode($v->codigo_barras))->json();
        $p = $r['results'][0] ?? null;
        fwrite(STDERR, "SIIGO {$v->color_nombre} code={$v->codigo_barras} id_local=".($v->siigo_id ? 'si' : 'no').' encontrado='.($p ? 'si' : 'no').' nombre='.($p['name'] ?? '-')."\n");
    }

    expect($variantes->whereNotNull('siigo_id'))->toHaveCount(3);

    // El sandbox es público: no dejar basura activa.
    $des = app(DesactivarProductoEnSiigo::class)->handle($producto->fresh());
    fwrite(STDERR, 'DESACTIVAR '.json_encode($des, JSON_UNESCAPED_UNICODE)."\n");
});
