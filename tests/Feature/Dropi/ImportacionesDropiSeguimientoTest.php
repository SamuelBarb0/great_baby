<?php

/**
 * Fase 4b de compras en el panel de Dropi: el seguimiento del contenedor
 * (8-nov). Etapas, declaraciones, novedades, documentos y datos se mueven
 * desde la página del contenedor de Dropi con las mismas reglas que /app,
 * porque las dos pantallas pasan por SeguimientoContenedor.
 */

use App\Models\Contacto;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearImportacion;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionDeclaracion;
use App\Modules\Compras\Models\ImportacionDocumento;
use App\Modules\Compras\Models\ImportacionInspeccion;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\SeguimientoContenedor;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Filament\Pages\RegistrarRecepcionDropi;
use App\Modules\Dropi\Filament\Pages\VerImportacionDropi;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake(ImportacionDocumento::DISCO);
    $this->seed(Database\Seeders\PermisosSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->deshacerRepeater = Repeater::fake();

    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->compras = User::factory()->create(['name' => 'Liz']);
    $this->compras->assignRole(Role::firstOrCreate(['name' => 'Compras', 'guard_name' => 'web']));

    $this->bodega = InventarioUbicacion::create(['codigo' => 'ECO-1', 'nombre' => 'Bodega e-commerce', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true]);
    $proveedor = Contacto::create(['numero_documento' => '900333444', 'nombre_completo' => 'Topwin', 'es_proveedor' => true]);
    $producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'DR-TET', 'nombre' => 'Tetero', 'activo' => true, 'desglose_stock' => false, 'precio_proveedor' => 3.5,
    ]));

    $this->oc = AprobarOrdenCompra::run(CrearOrdenCompra::run([
        'proveedor_id' => $proveedor->id, 'bodega_id' => $this->bodega->id,
        'tipo' => 'importacion', 'moneda' => 'CNY', 'tasa_cambio' => 520, 'linea_negocio' => LineaNegocio::Dropi,
        'items' => [['producto_id' => $producto->id, 'descripcion' => 'Tetero', 'iva_pct' => 0,
            'cantidad' => 100, 'cajas' => 10, 'unidades_por_caja' => 10, 'cbm_caja' => 0.5, 'peso_caja_kg' => 8, 'precio_unit' => 3.5]],
    ]))->fresh('items');

    $this->imp = CrearImportacion::run(['contenedor' => 'MSKU7725140', 'moneda_origen' => 'CNY'], LineaNegocio::Dropi);
    app(SeguimientoContenedor::class)->ligarOrden($this->imp, $this->oc);
    $this->pagina = fn () => Livewire::test(VerImportacionDropi::class, ['importacion' => $this->imp->id]);
});

afterEach(fn () => ($this->deshacerRepeater)());

it('muestra las 11 etapas y marca, corrige y quita una desde Dropi', function () {
    $this->actingAs($this->aracely);
    $ayer = now('America/Bogota')->subDay()->toDateString();

    ($this->pagina)()
        ->assertSee('Seguimiento')->assertSee('En fabricación')->assertSee('En bodega')
        ->callAction('marcarEtapa', data: ['fecha' => $ayer, 'notas' => 'Salió de Ningbo'], arguments: ['etapa' => 'zarpado'])
        ->assertNotified('Etapa «Zarpó» marcada.');

    $imp = $this->imp->fresh();
    expect($imp->fecha_zarpe->toDateString())->toBe($ayer)
        ->and($imp->etapas()->where('etapa', 'zarpado')->value('notas'))->toBe('Salió de Ningbo')
        ->and($imp->etapas()->where('etapa', 'zarpado')->value('registrado_por'))->toBe($this->aracely->id);

    // Las reglas del servicio: ni futura ni fuera de orden.
    ($this->pagina)()
        ->callAction('marcarEtapa', data: ['fecha' => now('America/Bogota')->addDays(2)->toDateString()], arguments: ['etapa' => 'en_puerto'])
        ->assertNotified('No se pudo');
    ($this->pagina)()
        ->callAction('marcarEtapa', data: ['fecha' => now('America/Bogota')->subDays(5)->toDateString()], arguments: ['etapa' => 'en_puerto'])
        ->assertNotified('No se pudo');
    expect($this->imp->etapas()->where('etapa', 'en_puerto')->exists())->toBeFalse();

    ($this->pagina)()
        ->callAction('quitarEtapa', arguments: ['etapa' => 'zarpado'])
        ->assertNotified('Etapa «Zarpó» quitada.');
    expect($this->imp->etapas()->count())->toBe(0)
        ->and($this->imp->fresh()->fecha_zarpe)->toBeNull();
});

it('no deja marcar el levante con declaraciones sin levante, y la automática lo trae de una vez', function () {
    $this->actingAs($this->aracely);

    ($this->pagina)()
        ->callAction('guardarDeclaracion', data: ['numero' => '482026000123', 'selectividad' => 'documental'])
        ->assertNotified('Declaración guardada.');
    $d = ImportacionDeclaracion::where('importacion_id', $this->imp->id)->firstOrFail();
    expect($d->registrado_por)->toBe($this->aracely->id)->and($d->fecha_levante)->toBeNull();

    ($this->pagina)()
        ->callAction('marcarEtapa', data: ['fecha' => now('America/Bogota')->toDateString()], arguments: ['etapa' => 'levante'])
        ->assertNotified('No se pudo');
    expect($this->imp->etapas()->where('etapa', 'levante')->exists())->toBeFalse();

    // Editarla a «automática» sin fecha le pone el levante de hoy.
    ($this->pagina)()
        ->callAction('guardarDeclaracion', data: ['numero' => '482026000123', 'selectividad' => 'automatica', 'fecha_levante' => null], arguments: ['declaracion' => $d->id])
        ->assertNotified('Declaración guardada.');
    expect($d->fresh()->fecha_levante->toDateString())->toBe(now('America/Bogota')->toDateString())
        ->and(ImportacionDeclaracion::count())->toBe(1);

    ($this->pagina)()
        ->callAction('marcarEtapa', data: ['fecha' => now('America/Bogota')->toDateString()], arguments: ['etapa' => 'levante'])
        ->assertNotified('Etapa «Levante» marcada.');

    ($this->pagina)()
        ->callAction('quitarDeclaracion', arguments: ['declaracion' => $d->id])
        ->assertNotified('Declaración quitada.');
    expect(ImportacionDeclaracion::count())->toBe(0);
});

it('no toca declaraciones ni novedades de otro contenedor', function () {
    $this->actingAs($this->aracely);
    $otro = CrearImportacion::run(['moneda_origen' => 'CNY'], LineaNegocio::Dropi);
    $ajena = app(SeguimientoContenedor::class)->guardarDeclaracion($otro, ['selectividad' => 'pendiente'], null, null);
    $novedad = app(SeguimientoContenedor::class)->registrarInspeccion($otro, ['tipo' => 'otra', 'fecha' => now()->toDateString()], null);

    expect(fn () => ($this->pagina)()->callAction('quitarDeclaracion', arguments: ['declaracion' => $ajena->id]))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => ($this->pagina)()->callAction('resolverInspeccion', arguments: ['inspeccion' => $novedad->id]))
        ->toThrow(ModelNotFoundException::class);
    // Y el servicio tampoco, por si llega por otro lado.
    expect(fn () => app(SeguimientoContenedor::class)->quitarDeclaracion($this->imp, $ajena))
        ->toThrow(InvalidArgumentException::class);

    expect($ajena->fresh())->not->toBeNull()->and($novedad->fresh()->resuelta_en)->toBeNull();
});

it('registrar una novedad le avisa a Compras, y se resuelve con la fecha de hoy', function () {
    $this->actingAs($this->aracely);

    ($this->pagina)()
        ->callAction('registrarInspeccion', data: ['tipo' => 'forzosa_puerto', 'fecha' => now()->toDateString(), 'notas' => 'Cita el lunes'])
        ->assertNotified('Novedad registrada. Compras ya recibió el aviso.');

    $novedad = ImportacionInspeccion::where('importacion_id', $this->imp->id)->firstOrFail();
    expect($novedad->registrado_por)->toBe($this->aracely->id)
        ->and(NotificacionErp::where('user_id', $this->compras->id)->where('tipo', 'contenedor_inspeccion')->value('titulo'))
        ->toBe('Inspección forzosa en puerto: contenedor MSKU7725140');

    ($this->pagina)()
        ->assertSee('Cita el lunes')
        ->callAction('resolverInspeccion', arguments: ['inspeccion' => $novedad->id])
        ->assertNotified('Novedad resuelta.');
    expect($novedad->fresh()->resuelta_en->toDateString())->toBe(now('America/Bogota')->toDateString());
});

it('edita los datos, pero la tasa no cambia con mercancía recibida ni nada cambia ya liquidado', function () {
    $this->actingAs($this->aracely);

    ($this->pagina)()
        ->callAction('editarDatos', data: ['bl_awb' => 'MEDU123', 'puerto_origen' => 'Ningbo', 'tasa_cambio_liquidacion' => 530, 'eta' => '2026-12-01'])
        ->assertNotified('Datos del contenedor actualizados.');
    $imp = $this->imp->fresh();
    expect($imp->bl_awb)->toBe('MEDU123')
        ->and($imp->puerto_origen)->toBe('Ningbo')
        ->and((float) $imp->tasa_cambio_liquidacion)->toBe(530.0)
        ->and($imp->eta->toDateString())->toBe('2026-12-01')
        ->and($imp->contenedor)->toBe('MSKU7725140');

    // Bodega recibe: lo recibido entra al 1465 a esa tasa.
    Livewire::test(RegistrarRecepcionDropi::class, ['orden' => $this->oc->id])
        ->fillForm(['items' => [['orden_item_id' => $this->oc->items->first()->id, 'cantidad_recibida' => 100, 'clasificacion' => 'apto']]])
        ->call('confirmar')->assertHasNoFormErrors();

    $pagina = ($this->pagina)();
    expect($pagina->instance()->tasaFija())->toBeTrue();
    $pagina->callAction('editarDatos', data: ['tasa_cambio_liquidacion' => 600])
        ->assertNotified('No se pudo');
    expect((float) $this->imp->fresh()->tasa_cambio_liquidacion)->toBe(530.0);

    // Lo demás sí se puede, con la misma tasa.
    ($this->pagina)()
        ->callAction('editarDatos', data: ['bl_awb' => 'MEDU999'])
        ->assertNotified('Datos del contenedor actualizados.');
    expect($this->imp->fresh()->bl_awb)->toBe('MEDU999');

    // Liquidado: la pantalla ya no ofrece editar, y el servicio lo frena.
    DB::table('compras_importaciones')->where('id', $this->imp->id)->update(['estado' => EstadoImportacion::Liquidada->value]);
    ($this->pagina)()->assertActionHidden('editarDatos');
    expect(fn () => app(SeguimientoContenedor::class)->actualizarDatos($this->imp->fresh(), ['bl_awb' => 'X']))
        ->toThrow(InvalidArgumentException::class, 'ya está liquidado');
});

it('/app sigue respetando las mismas reglas después de pasarlas al servicio', function () {
    $this->actingAs($this->aracely);
    Livewire::test(RegistrarRecepcionDropi::class, ['orden' => $this->oc->id])
        ->fillForm(['items' => [['orden_item_id' => $this->oc->items->first()->id, 'cantidad_recibida' => 100, 'clasificacion' => 'apto']]])
        ->call('confirmar')->assertHasNoFormErrors();

    $this->put("/app/compras/importacion/{$this->imp->id}/datos", ['tasa_cambio_liquidacion' => 600])
        ->assertSessionHas('error', 'Ya hay mercancía recibida con esta tasa de cambio: no se puede cambiar.');
    $this->put("/app/compras/importacion/{$this->imp->id}/datos", ['bl_awb' => 'MEDU1'])
        ->assertSessionHas('success', 'Datos del contenedor actualizados.');

    $this->post("/app/compras/importacion/{$this->imp->id}/declaracion", ['selectividad' => 'automatica'])
        ->assertSessionHas('success');
    expect(ImportacionDeclaracion::first()->fecha_levante->toDateString())->toBe(now('America/Bogota')->toDateString());

    $this->post("/app/compras/importacion/{$this->imp->id}/inspeccion", ['tipo' => 'duta_fisica', 'fecha' => now()->toDateString()])
        ->assertSessionHas('success', 'Novedad registrada. Compras ya recibió el aviso.');
    expect(NotificacionErp::where('user_id', $this->compras->id)->where('tipo', 'contenedor_inspeccion')->exists())->toBeTrue();

    DB::table('compras_importaciones')->where('id', $this->imp->id)->update(['estado' => EstadoImportacion::Liquidada->value]);
    $this->put("/app/compras/importacion/{$this->imp->id}/datos", ['bl_awb' => 'MEDU2'])
        ->assertSessionHas('error', 'El contenedor ya está liquidado: sus datos no se pueden cambiar.');
    expect($this->imp->fresh()->bl_awb)->toBe('MEDU1');
});

it('sube documentos con versiones, sin dejar subir comprobantes de gasto por aquí', function () {
    $this->actingAs($this->compras);

    ($this->pagina)()
        ->callAction('subirDocumento', data: ['tipo' => 'lista_empaque', 'archivo' => UploadedFile::fake()->create('packing borrador.xlsx', 40)])
        ->assertNotified('Lista de empaque final (packing list) subido.');
    ($this->pagina)()
        ->callAction('subirDocumento', data: ['tipo' => 'lista_empaque', 'archivo' => UploadedFile::fake()->create('packing final.xlsx', 40), 'notas' => 'La buena'])
        ->assertNotified('Lista de empaque final (packing list) (versión 2) subido.');

    $docs = ImportacionDocumento::where('importacion_id', $this->imp->id)->orderBy('version')->get();
    expect($docs->pluck('version')->all())->toBe([1, 2])
        ->and($docs->pluck('nombre')->all())->toBe(['packing borrador.xlsx', 'packing final.xlsx'])
        ->and($docs->last()->notas)->toBe('La buena')
        ->and($docs->last()->subido_por)->toBe($this->compras->id)
        ->and($docs->whereNotNull('gasto_id'))->toBeEmpty();
    Storage::disk(ImportacionDocumento::DISCO)->assertExists($docs->last()->ruta);

    ($this->pagina)()
        ->assertSee('packing final.xlsx')->assertSee('v2')
        ->callAction('subirDocumento', data: ['tipo' => 'comprobante_gasto', 'archivo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
        ->assertHasActionErrors(['tipo']);
    expect(ImportacionDocumento::count())->toBe(2);
});

it('quitar un documento es sólo de Aracely, y queda en la papelera', function () {
    $doc = App\Modules\Compras\Actions\GuardarDocumentoImportacion::run($this->imp, UploadedFile::fake()->create('bl.pdf', 10, 'application/pdf'), 'bl');

    $this->actingAs($this->compras);
    ($this->pagina)()->assertActionHidden('quitarDocumento', ['documento' => $doc->id]);

    $this->actingAs($this->aracely);
    ($this->pagina)()
        ->callAction('quitarDocumento', arguments: ['documento' => $doc->id])
        ->assertNotified('Documento quitado.');
    expect($doc->fresh()->trashed())->toBeTrue();
});
