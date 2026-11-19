<?php

/**
 * Marketing arranca sabiendo qué le toca y cada pantalla dice qué sigue.
 */

use App\Models\User;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Marketing\Models\Lanzamiento;
use App\Modules\Marketing\Models\MarketingCreativo;
use App\Modules\Marketing\Services\EtapasProducto;
use App\Modules\Marketing\Services\PasoDeMarketing;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->aracely = User::factory()->create(['name' => 'Aracely']);
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->anny = User::factory()->create(['name' => 'Anny']);
    $this->anny->assignRole(Role::firstOrCreate(['name' => 'Marketing', 'guard_name' => 'web']));

    $this->imp = Importacion::create(['numero' => Importacion::siguienteNumero(), 'contenedor' => 'MSCU1', 'moneda_origen' => 'USD', 'estado' => EstadoImportacion::EnTransito]);
    $this->lanzamiento = Lanzamiento::where('importacion_id', $this->imp->id)->firstOrFail();
    $this->tarjeta = fn (string $clave) => $this->lanzamiento->tarjetas()->where('clave', $clave)->first();
    $this->finalizar = function (string ...$claves) {
        foreach ($claves as $clave) {
            ($this->tarjeta)($clave)->update(['lista' => 'finalizado']);
        }
    };
    $this->url = "/app/marketing/lanzamientos/{$this->lanzamiento->id}";

    $this->producto = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => 'TET', 'nombre' => 'Tetero', 'precio_proveedor' => 10, 'activo' => true, 'desglose_stock' => false]));
    $this->paso = function () {
        $p = $this->producto->fresh();

        return app(PasoDeMarketing::class)->deProducto($p, app(EtapasProducto::class)->para(collect([$p]))[$p->id]);
    };
});

it('en el tablero dice a quién se espera y deja adelantar lo propio', function () {
    ($this->finalizar)('recepcion');

    $this->actingAs($this->anny)->get($this->url)->assertInertia(fn (Assert $p) => $p
        ->where('paso.titulo', 'Se está esperando: 2. Selección de Productos.')
        ->where('paso.acciones.0.etiqueta', 'Adelantar la tarjeta 4')
        ->where('paso.acciones.0.tarjeta', ($this->tarjeta)('propuesta')->id)
        ->where('paso.acciones.1.principal', false));
});

it('cuando le toca a Marketing lo dice y abre su tarjeta', function () {
    ($this->finalizar)('recepcion', 'seleccion', 'brief');

    $this->actingAs($this->anny)->get($this->url)->assertInertia(fn (Assert $p) => $p
        ->where('paso.titulo', 'Le toca: 4. Propuesta Creativa.')
        ->where('paso.numero', 2)
        ->where('paso.acciones.0.accion', 'abrir-tarjeta')
        ->where('paso.acciones.0.tarjeta', ($this->tarjeta)('propuesta')->id));
});

it('una tarjeta por aprobar sólo le pide algo a Aracely', function () {
    ($this->finalizar)('recepcion', 'seleccion', 'brief', 'propuesta');
    ($this->tarjeta)('validacion')->update(['lista' => 'pendiente_aprobacion']);

    $this->actingAs($this->aracely)->get($this->url)->assertInertia(fn (Assert $p) => $p
        ->where('paso.titulo', 'Revise y apruebe: 5. Validación Comercial.')
        ->where('paso.acciones.0.principal', true));
    $this->actingAs($this->anny)->get($this->url)->assertInertia(fn (Assert $p) => $p
        ->where('paso.titulo', 'Esperando que Aracely apruebe: 5. Validación Comercial.')
        ->where('paso.acciones.0.principal', false));
});

it('una tarjeta bloqueada dice el motivo y quién la resuelve', function () {
    ($this->finalizar)('recepcion', 'seleccion', 'brief', 'propuesta', 'validacion');
    ($this->tarjeta)('muestras')->update(['lista' => 'bloqueado', 'bloqueo_motivo' => 'No hay muestra', 'bloqueo_accion' => 'Traerla', 'bloqueo_responsable_id' => $this->aracely->id]);

    $this->actingAs($this->anny)->get($this->url)->assertInertia(fn (Assert $p) => $p
        ->where('paso.titulo', 'Está bloqueada: 6. Entrega de Muestras.')
        ->where('paso.explica', 'Motivo: No hay muestra. Hay que: Traerla.')
        ->where('paso.quien', 'Aracely'));
});

it('al terminar las diez, el tablero lo dice', function () {
    $this->lanzamiento->tarjetas()->update(['lista' => 'finalizado']);

    $this->actingAs($this->anny)->get($this->url)->assertInertia(fn (Assert $p) => $p
        ->where('paso.numero', 6)
        ->where('paso.acciones', []));
});

it('lleva el producto de definir piezas hasta publicado', function () {
    expect(($this->paso)()['acciones'][0]['accion'])->toBe('piezas');

    $this->producto->update(['piezas_video' => true, 'piezas_imagenes' => 2]);
    $paso = ($this->paso)();
    expect($paso['titulo'])->toBe('Suba lo que falta: 1 video, 2 imágenes.')
        ->and($paso['acciones'][0]['etiqueta'])->toBe('Subir video');

    foreach (['video', 'imagen', 'imagen'] as $tipo) {
        MarketingCreativo::create(['producto_id' => $this->producto->id, 'tipo' => $tipo, 'titulo' => 'x', 'enlace_externo' => 'https://drive.google.com/x', 'permiso' => 'interno', 'subido_por' => $this->anny->id]);
    }
    expect(($this->paso)()['acciones'][0]['accion'])->toBe('textos');

    $this->producto->update(['copy_comercial' => 'Ideal', 'publico_objetivo' => 'Mamás']);
    expect(($this->paso)()['acciones'][0]['accion'])->toBe('publicar');

    $this->producto->update(['activo' => false]);
    expect(($this->paso)()['acciones'])->toBe([]);
    $this->producto->update(['activo' => true]);

    $this->actingAs($this->anny)->post("/app/marketing/producto/{$this->producto->id}/publicar", ['publicado' => true, 'listo' => true])->assertRedirect();
    expect($this->producto->fresh()->listo_publicar)->toBeTrue()
        ->and(($this->paso)()['numero'])->toBe(6)
        ->and(($this->paso)()['quien'])->toBeNull();
});

it('ocultar para Ventas no borra que estaba listo', function () {
    $this->producto->update(['listo_publicar' => true]);

    $this->actingAs($this->anny)->post("/app/marketing/producto/{$this->producto->id}/publicar", ['publicado' => false])->assertRedirect();

    expect($this->producto->fresh()->publicado)->toBeFalse()
        ->and($this->producto->fresh()->listo_publicar)->toBeTrue();
});

it('la lista de productos y la ficha traen lo que sigue', function () {
    $this->actingAs($this->anny)->get('/app/marketing/tablero?q=TET')->assertInertia(fn (Assert $p) => $p
        ->where('productos.0.paso.acciones.0.accion', 'piezas'));
    $this->actingAs($this->anny)->get("/app/marketing/producto/{$this->producto->id}/ficha")->assertInertia(fn (Assert $p) => $p
        ->where('paso.acciones.0.accion', 'piezas'));
});

it('«lo que me toca» muestra su tarjeta y lleva a ella', function () {
    ($this->finalizar)('recepcion', 'seleccion', 'brief');

    $propuesta = ($this->tarjeta)('propuesta');
    $this->actingAs($this->anny)->get('/app/marketing')->assertInertia(fn (Assert $p) => $p
        ->component('Marketing/Panel')
        ->where('pendientes.0.titulo', 'MSCU1: le toca «4. Propuesta Creativa»')
        ->where('pendientes.0.url', "/app/marketing/lanzamientos/{$this->lanzamiento->id}?tarjeta={$propuesta->id}"));
});

it('a Aracely le muestra primero lo que espera su aprobación', function () {
    ($this->tarjeta)('validacion')->update(['lista' => 'pendiente_aprobacion']);

    expect(app(PasoDeMarketing::class)->pendientes($this->aracely)[0]['titulo'])
        ->toBe('MSCU1: por aprobar «5. Validación Comercial»');
});

it('un lanzamiento terminado no aparece como pendiente', function () {
    $this->lanzamiento->update(['finalizado_en' => now()]);

    expect(app(PasoDeMarketing::class)->pendientes($this->anny))->toBe([]);
});
