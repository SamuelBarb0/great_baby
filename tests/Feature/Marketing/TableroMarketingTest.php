<?php

/**
 * Tablero de Marketing (tarjetas MKT-1 a MKT-4, 22-oct-2026).
 *
 *  MKT-1 · Ficha con público objetivo y «listo para publicar»; el botón de
 *          activar/desactivar decide si Ventas ve el producto.
 *  MKT-2 · Creativos por producto/contenedor: archivo liviano o enlace, con
 *          permiso interno / solo ver / descarga.
 *  MKT-3 · La parrilla liga el post al producto y al contenedor y lleva
 *          grabación → edición → entrega; al entregar, el material queda a la
 *          vista de mayorista y dropship.
 *  MKT-4 · En qué etapa va cada producto y qué productos trae un contenedor.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Catalogo\Models\ListaPrecios;
use App\Modules\Catalogo\Models\PrecioVariante;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Actions\PrepararRecepcionDesdeOC;
use App\Modules\Compras\Actions\RecibirMercancia;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Marketing\Models\MarketingCreativo;
use App\Modules\Marketing\Models\MarketingPost;
use App\Modules\Marketing\Services\EtapasProducto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake(MarketingCreativo::DISCO);

    $this->mkt = User::factory()->create();
    $this->mkt->assignRole(Role::firstOrCreate(['name' => 'Marketing', 'guard_name' => 'web']));
    $this->vendedor = User::factory()->create();
    $this->vendedor->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));
    $this->root = User::factory()->create();
    $this->root->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->contador = User::factory()->create();
    $this->contador->assignRole(Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']));

    $this->producto = Producto::create([
        'referencia' => 'MKT-01', 'nombre' => 'Tetero anticólico', 'precio_proveedor' => 5000, 'activo' => true,
    ]);
    $this->variante = ProductoVariante::create([
        'producto_id' => $this->producto->id, 'talla' => 'U', 'color_codigo' => 'AZ', 'color_nombre' => 'Azul',
    ]);
});

// ───────────────────────────── MKT-1 ─────────────────────────────

it('un producto nuevo sigue visible para Ventas hasta que Marketing lo oculte', function () {
    expect($this->producto->fresh()->publicado)->toBeTrue()
        ->and(Producto::visibleParaVentas()->pluck('id')->all())->toContain($this->producto->id);
});

it('ocultar el producto lo saca del pedido del vendedor y volver a activarlo lo trae', function () {
    $lista = ListaPrecios::create(['codigo' => 'MAY', 'nombre' => 'Mayorista', 'canal' => 'b2b', 'activa' => true]);
    PrecioVariante::create(['variante_id' => $this->variante->id, 'lista_id' => $lista->id, 'precio' => 9000, 'vigente_desde' => now()->subDay()]);
    $cliente = Contacto::create([
        'numero_documento' => '900555111', 'nombre_completo' => 'Bebés del Norte', 'lista_precios_id' => $lista->id,
    ]);
    $variantesDelPedido = fn () => $this->actingAs($this->root)->get("/app/vendedor/pedido-nuevo/{$cliente->id}")
        ->assertOk()->viewData('page')['props']['variantes'];

    expect(collect($variantesDelPedido())->pluck('variante_id')->all())->toBe([$this->variante->id]);

    $this->actingAs($this->mkt)->post("/app/marketing/producto/{$this->producto->id}/publicar", ['publicado' => false])
        ->assertSessionHas('success');
    expect($variantesDelPedido())->toBe([]);

    $p = $this->producto->fresh();
    expect($p->publicado)->toBeFalse()
        ->and($p->publicado_cambiado_por)->toBe($this->mkt->id)
        ->and($p->publicado_cambiado_en)->not->toBeNull();

    $this->actingAs($this->mkt)->post("/app/marketing/producto/{$this->producto->id}/publicar", ['publicado' => true]);
    expect(collect($variantesDelPedido())->pluck('variante_id')->all())->toBe([$this->variante->id]);
});

it('no publica un producto inactivo', function () {
    $this->producto->update(['activo' => false, 'publicado' => false]);

    $this->actingAs($this->mkt)->post("/app/marketing/producto/{$this->producto->id}/publicar", ['publicado' => true])
        ->assertSessionHas('error');
    expect($this->producto->fresh()->publicado)->toBeFalse();
});

it('la ficha guarda público objetivo, listo para publicar y piezas, y Marketing la puede abrir', function () {
    $this->actingAs($this->mkt)->get("/app/marketing/producto/{$this->producto->id}/ficha")->assertOk();

    $this->actingAs($this->mkt)->put("/app/marketing/producto/{$this->producto->id}/ficha", [
        'copy_comercial' => 'El tetero que no da cólicos',
        'publico_objetivo' => 'Mamás primerizas, bebés de 0 a 6 meses',
        'listo_publicar' => true,
        'piezas_video' => true, 'piezas_reel' => false, 'piezas_imagenes' => 4,
    ])->assertSessionHas('success');

    $p = $this->producto->fresh();
    expect($p->publico_objetivo)->toBe('Mamás primerizas, bebés de 0 a 6 meses')
        ->and($p->listo_publicar)->toBeTrue()
        ->and($p->piezas_video)->toBeTrue()
        ->and($p->piezas_imagenes)->toBe(4);
});

it('ni el vendedor ni contabilidad tocan la ficha ni la visibilidad', function () {
    foreach ([$this->vendedor, $this->contador] as $u) {
        $this->actingAs($u)->get("/app/marketing/producto/{$this->producto->id}/ficha")->assertForbidden();
        $this->actingAs($u)->post("/app/marketing/producto/{$this->producto->id}/publicar", ['publicado' => false])->assertForbidden();
        $this->actingAs($u)->get('/app/marketing/tablero')->assertForbidden();
    }
    expect($this->producto->fresh()->publicado)->toBeTrue();
});

// ───────────────────────────── MKT-2 ─────────────────────────────

it('Marketing sube creativos y cada área ve según el permiso', function () {
    $this->actingAs($this->mkt)->post('/app/marketing/creativos', [
        'tipo' => 'imagen', 'producto_id' => $this->producto->id, 'permiso' => 'interno',
        'archivos' => [UploadedFile::fake()->image('borrador.jpg')],
    ])->assertSessionHas('success');
    $this->actingAs($this->mkt)->post('/app/marketing/creativos', [
        'tipo' => 'imagen', 'producto_id' => $this->producto->id, 'permiso' => 'solo_ver',
        'archivos' => [UploadedFile::fake()->image('vista.jpg')],
    ]);
    $this->actingAs($this->mkt)->post('/app/marketing/creativos', [
        'tipo' => 'reel', 'producto_id' => $this->producto->id, 'permiso' => 'descarga',
        'archivos' => [UploadedFile::fake()->create('reel.mp4', 2000, 'video/mp4')],
    ]);
    $this->actingAs($this->mkt)->post('/app/marketing/creativos', [
        'tipo' => 'video', 'producto_id' => $this->producto->id, 'permiso' => 'descarga',
        'enlace_externo' => 'https://drive.google.com/file/d/abc/view',
    ]);

    [$interno, $soloVer, $reel, $enlace] = MarketingCreativo::orderBy('id')->get()->all();
    expect($interno->titulo)->toBe('borrador')
        ->and($enlace->titulo)->toBe('drive.google.com')
        ->and($enlace->archivo_path)->toBeNull();
    Storage::disk(MarketingCreativo::DISCO)->assertExists($reel->archivo_path);

    // El vendedor ve lo compartido, no lo interno.
    $this->actingAs($this->vendedor)->get('/app/marketing/creativos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Marketing/Creativos')->has('creativos.data', 3));

    $this->actingAs($this->vendedor)->get("/app/marketing/creativos/{$interno->id}/ver")->assertForbidden();
    $this->actingAs($this->vendedor)->get("/app/marketing/creativos/{$soloVer->id}/ver")->assertOk();
    $this->actingAs($this->vendedor)->get("/app/marketing/creativos/{$soloVer->id}/descargar")->assertForbidden();
    $this->actingAs($this->vendedor)->get("/app/marketing/creativos/{$reel->id}/descargar")
        ->assertOk()->assertDownload('reel.mp4');

    // Marketing ve todo; contabilidad nada.
    $this->actingAs($this->mkt)->get('/app/marketing/creativos')
        ->assertInertia(fn (Assert $page) => $page->has('creativos.data', 4)->where('gestiona', true));
    $this->actingAs($this->contador)->get('/app/marketing/creativos')->assertForbidden();
});

it('el vendedor no sube, ni cambia permisos, ni borra creativos', function () {
    $c = MarketingCreativo::create([
        'tipo' => 'imagen', 'titulo' => 'Foto', 'permiso' => 'descarga',
        'enlace_externo' => 'https://example.com/foto.jpg', 'producto_id' => $this->producto->id,
    ]);

    $this->actingAs($this->vendedor)->post('/app/marketing/creativos', [
        'tipo' => 'imagen', 'permiso' => 'descarga', 'enlace_externo' => 'https://example.com/x.jpg',
    ])->assertForbidden();
    $this->actingAs($this->vendedor)->put("/app/marketing/creativos/{$c->id}", ['permiso' => 'interno'])->assertForbidden();
    $this->actingAs($this->vendedor)->delete("/app/marketing/creativos/{$c->id}")->assertForbidden();

    expect(MarketingCreativo::count())->toBe(1)->and($c->fresh()->permiso)->toBe('descarga');
});

it('rechaza un archivo pesado y pide subirlo como enlace', function () {
    $this->actingAs($this->mkt)->post('/app/marketing/creativos', [
        'tipo' => 'video', 'permiso' => 'interno',
        'archivos' => [UploadedFile::fake()->create('sesion.mp4', MarketingCreativo::MAX_KB + 1, 'video/mp4')],
    ])->assertSessionHasErrors('archivos.0');

    expect(MarketingCreativo::count())->toBe(0);
});

// ───────────────────────────── MKT-3 ─────────────────────────────

it('la parrilla lleva el post de grabación a edición y a entrega, y al entregar lo comparte', function () {
    $imp = Importacion::create([
        'numero' => Importacion::siguienteNumero(), 'contenedor' => 'MSCU7654321',
        'moneda_origen' => 'USD', 'estado' => EstadoImportacion::EnPuerto,
    ]);

    $this->actingAs($this->root)->post('/app/marketing/parrilla', [
        'fecha_publicacion' => now()->addDays(3)->toDateString(),
        'titulo' => 'Reel tetero', 'copy' => 'Sin cólicos', 'canal' => 'instagram', 'tipo' => 'reel',
        'producto_id' => $this->producto->id, 'importacion_id' => $imp->id, 'produccion' => true,
    ])->assertSessionHas('success');

    $post = MarketingPost::firstOrFail();
    expect($post->etapa)->toBe('grabacion')
        ->and($post->grabado_en)->not->toBeNull()
        ->and($post->importacion_id)->toBe($imp->id);

    $this->actingAs($this->root)->post("/app/marketing/parrilla/{$post->id}/etapa")->assertSessionHas('success');
    expect($post->fresh()->etapa)->toBe('edicion');

    // Sin material no se entrega.
    $this->actingAs($this->root)->post("/app/marketing/parrilla/{$post->id}/etapa")->assertSessionHas('error');
    expect($post->fresh()->etapa)->toBe('edicion');

    // El material subido desde el post hereda su producto y contenedor.
    $this->actingAs($this->mkt)->post('/app/marketing/creativos', [
        'tipo' => 'reel', 'post_id' => $post->id, 'permiso' => 'interno',
        'archivos' => [UploadedFile::fake()->create('reel-final.mp4', 500, 'video/mp4')],
    ]);
    $this->actingAs($this->mkt)->post('/app/marketing/creativos', [
        'tipo' => 'imagen', 'post_id' => $post->id, 'permiso' => 'descarga',
        'archivos' => [UploadedFile::fake()->image('portada.jpg')],
    ]);
    $reel = MarketingCreativo::where('tipo', 'reel')->firstOrFail();
    expect($reel->producto_id)->toBe($this->producto->id)->and($reel->importacion_id)->toBe($imp->id);

    $this->actingAs($this->root)->post("/app/marketing/parrilla/{$post->id}/etapa")->assertSessionHas('success');
    expect($post->fresh()->etapa)->toBe('entregado')
        ->and($reel->fresh()->permiso)->toBe('solo_ver')
        ->and(MarketingCreativo::where('tipo', 'imagen')->value('permiso'))->toBe('descarga');

    $this->actingAs($this->root)->post("/app/marketing/parrilla/{$post->id}/etapa")->assertSessionHas('error');
});

// ───────────────────────────── MKT-4 ─────────────────────────────

it('sigue al producto de la compra al contenedor, a bodega, al contenido y a publicado', function () {
    $bodega = InventarioUbicacion::create([
        'codigo' => 'PPAL', 'nombre' => 'Bodega principal', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $proveedor = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900123456',
        'nombre_completo' => 'Fábrica Yiwu', 'es_proveedor' => true,
    ]);
    $this->actingAs($this->root);
    $otro = Producto::create(['referencia' => 'MKT-02', 'nombre' => 'Chupo', 'precio_proveedor' => 1000, 'activo' => true]);

    $etapa = fn () => app(EtapasProducto::class)->para(Producto::whereKey($this->producto->id)->get())[$this->producto->id];
    expect($etapa()['etapa'])->toBeNull();

    $orden = CrearOrdenCompra::run([
        'proveedor_id' => $proveedor->id, 'bodega_id' => $bodega->id,
        'items' => [[
            'producto_id' => $this->producto->id, 'variante_id' => $this->variante->id,
            'descripcion' => 'Tetero', 'cantidad' => 500, 'precio_unit' => 1000, 'iva_pct' => 0,
        ]],
    ]);
    // En borrador no cuenta: la compra aún no está hecha.
    expect($etapa()['etapa'])->toBeNull();
    AprobarOrdenCompra::run($orden);
    expect($etapa()['etapa'])->toBe('compra')->and($etapa()['orden'])->toBe($orden->numero);

    $imp = Importacion::create([
        'numero' => Importacion::siguienteNumero(), 'contenedor' => 'MSCU1111111',
        'moneda_origen' => 'USD', 'estado' => EstadoImportacion::EnTransito,
    ]);
    $imp->ordenes()->attach($orden->id);
    expect($etapa()['etapa'])->toBe('contenedor')->and($etapa()['contenedor']['contenedor'])->toBe('MSCU1111111');

    // MKT-4.2 · el contenedor lista sus productos, no los demás.
    expect(app(EtapasProducto::class)->productosDelContenedor($imp->id))->toBe([$this->producto->id => 500.0]);
    $this->actingAs($this->mkt)->get("/app/marketing/tablero?contenedor={$imp->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Marketing/Tablero')
            ->has('productos', 1)
            ->where('productos.0.id', $this->producto->id)
            ->where('productos.0.cantidad', 500)
            ->where('productos.0.etapa', 'contenedor'));

    $this->actingAs($this->root);
    RecibirMercancia::run(PrepararRecepcionDesdeOC::run($orden->fresh(), $bodega->id));
    expect($etapa()['etapa'])->toBe('bodega')
        ->and($etapa()['llego_en'])->toBe(now()->toDateString())
        ->and($etapa()['dias_desde_llegada'])->toBe(0);

    // MKT-4.3 · piezas pedidas contra las subidas.
    $this->actingAs($this->mkt)->put("/app/marketing/producto/{$this->producto->id}/piezas", [
        'piezas_video' => true, 'piezas_reel' => false, 'piezas_imagenes' => 2,
    ])->assertSessionHas('success');
    MarketingCreativo::create([
        'tipo' => 'imagen', 'titulo' => 'Foto 1', 'permiso' => 'interno',
        'enlace_externo' => 'https://example.com/1.jpg', 'producto_id' => $this->producto->id,
    ]);
    $e = $etapa();
    expect($e['etapa'])->toBe('contenido')
        ->and($e['piezas']['faltan'])->toBe(['video' => 1, 'imagen' => 1])
        ->and($e['piezas']['completas'])->toBeFalse();

    $this->producto->update(['listo_publicar' => true]);
    expect($etapa()['etapa'])->toBe('publicado');

    // Sin filtros el tablero solo trae lo que está en movimiento.
    $this->actingAs($this->mkt)->get('/app/marketing/tablero')
        ->assertInertia(fn (Assert $page) => $page->has('productos', 1)->where('conteo.publicado', 1));
    $this->actingAs($this->mkt)->get('/app/marketing/tablero?etapa=bodega')
        ->assertInertia(fn (Assert $page) => $page->has('productos', 0));
    expect($otro->id)->not->toBe($this->producto->id);
});
