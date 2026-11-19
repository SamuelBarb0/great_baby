<?php

/**
 * Fase 4 de compras en el panel de Dropi: las importaciones (8-nov).
 *
 * Lo que importa probar: que el contenedor abierto en Dropi es de Dropi, que
 * no se le mete una orden de la otra línea, y que su liquidación —el 1465 que
 * sale y el 1435 que entra— queda entera en el libro de Dropi. Antes el asiento
 * se deducía del módulo (Compras) y caía en Great Baby.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearImportacion;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionDocumento;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\SeguimientoContenedor;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Filament\Pages\ImportacionesDropi;
use App\Modules\Dropi\Filament\Pages\NuevaImportacionDropi;
use App\Modules\Dropi\Filament\Pages\NuevaOrdenCompraDropi;
use App\Modules\Dropi\Filament\Pages\RegistrarRecepcionDropi;
use App\Modules\Dropi\Filament\Pages\VerImportacionDropi;
use App\Modules\Dropi\Filament\Pages\VerOrdenCompraDropi;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    $this->bodega = InventarioUbicacion::create(['codigo' => 'ECO-1', 'nombre' => 'Bodega e-commerce', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true]);
    $this->proveedor = Contacto::create(['numero_documento' => '900333444', 'nombre_completo' => 'Topwin', 'es_proveedor' => true]);
    $this->producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'DR-TET', 'nombre' => 'Tetero', 'activo' => true, 'desglose_stock' => false, 'precio_proveedor' => 3.5,
    ]));

    // Una OC de importación aprobada, sin pasar por pantalla: 100 teteros a 3,50 RMB, tasa 520.
    $this->ocImportacion = function (LineaNegocio $linea): OrdenCompra {
        $orden = CrearOrdenCompra::run([
            'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
            'tipo' => 'importacion', 'moneda' => 'CNY', 'tasa_cambio' => 520, 'linea_negocio' => $linea,
            'items' => [['producto_id' => $this->producto->id, 'descripcion' => 'Tetero', 'iva_pct' => 0,
                'cantidad' => 100, 'cajas' => 10, 'unidades_por_caja' => 10, 'cbm_caja' => 0.5, 'peso_caja_kg' => 8, 'precio_unit' => 3.5]],
        ]);

        return AprobarOrdenCompra::run($orden)->fresh('items');
    };
});

afterEach(fn () => ($this->deshacerRepeater)());

it('«Nueva OC» arma una orden de importación de Dropi en su moneda, con IVA 0 y sus cajas', function () {
    $this->actingAs($this->aracely);

    Livewire::test(NuevaOrdenCompraDropi::class)
        ->fillForm(['tipo' => 'importacion'])
        ->assertFormSet(['moneda' => 'CNY', 'items.0.iva_pct' => 0])
        ->fillForm([
            'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
            'moneda' => 'CNY', 'tasa_cambio' => 520, 'modelo_compra' => 'trading',
            'items' => [['producto_id' => $this->producto->id, 'descripcion' => 'Tetero', 'cantidad' => 100,
                'precio_unit' => 3.5, 'descuento_pct' => 0, 'iva_pct' => 0,
                'cajas' => 10, 'unidades_por_caja' => 10, 'cbm_caja' => 0.5, 'peso_caja_kg' => 8]],
        ])
        ->call('crear')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $oc = OrdenCompra::latest('id')->firstOrFail();
    $item = $oc->items->first();
    expect($oc->linea_negocio)->toBe(LineaNegocio::Dropi)
        ->and($oc->tipo)->toBe('importacion')
        ->and($oc->moneda)->toBe('CNY')
        ->and((float) $oc->tasa_cambio)->toBe(520.0)
        ->and((float) $item->iva_pct)->toBe(0.0)
        ->and((int) $item->cajas)->toBe(10)
        ->and((float) $item->cbm_caja)->toBe(0.5);
});

it('una importación en yuanes no se arma con tasa 1', function () {
    $this->actingAs($this->aracely);

    Livewire::test(NuevaOrdenCompraDropi::class)
        ->fillForm([
            'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
            'tipo' => 'importacion', 'moneda' => 'CNY', 'tasa_cambio' => 1,
            'items' => [['producto_id' => $this->producto->id, 'descripcion' => 'Tetero', 'cantidad' => 1, 'precio_unit' => 3.5, 'iva_pct' => 0]],
        ])
        ->call('crear')
        ->assertHasFormErrors(['tasa_cambio']);

    expect(OrdenCompra::count())->toBe(0);
});

it('el contenedor abierto en Dropi es de Dropi; el de /app sigue en Great Baby', function () {
    $this->actingAs($this->aracely);

    Livewire::test(NuevaImportacionDropi::class)
        ->fillForm(['contenedor' => 'MSKU7725140', 'moneda_origen' => 'CNY', 'tasa_cambio_liquidacion' => 520])
        ->call('crear')
        ->assertHasNoFormErrors()
        ->assertRedirect();
    $dropi = Importacion::latest('id')->firstOrFail();

    $this->post('/app/compras/importacion', ['contenedor' => 'TGHU1112223', 'moneda_origen' => 'USD'])->assertRedirect();
    $gb = Importacion::latest('id')->firstOrFail();

    expect($dropi->linea_negocio)->toBe(LineaNegocio::Dropi)
        ->and($dropi->contenedor)->toBe('MSKU7725140')
        ->and($gb->linea_negocio)->toBe(LineaNegocio::GreatBaby)
        ->and($gb->numero)->not->toBe($dropi->numero);
});

it('no se mete en un contenedor una orden de la otra línea, desde ningún lado', function () {
    $this->actingAs($this->aracely);
    $contenedorDropi = CrearImportacion::run(['moneda_origen' => 'CNY'], LineaNegocio::Dropi);
    $contenedorGb = CrearImportacion::run(['moneda_origen' => 'CNY']);
    $ocGb = ($this->ocImportacion)(LineaNegocio::GreatBaby);
    $ocDropi = ($this->ocImportacion)(LineaNegocio::Dropi);

    // El servicio lo frena, así que lo frena /app también.
    expect(fn () => app(SeguimientoContenedor::class)->ligarOrden($contenedorDropi, $ocGb))
        ->toThrow(InvalidArgumentException::class, 'es de Great Baby');
    $this->post("/app/compras/importacion/{$contenedorGb->id}/orden", ['orden_id' => $ocDropi->id])
        ->assertSessionHas('error');

    expect($contenedorDropi->ordenes()->count())->toBe(0)
        ->and($contenedorGb->ordenes()->count())->toBe(0);

    // Desde la orden: el contenedor nuevo nace en la línea de la orden.
    $this->post("/app/compras/orden/{$ocDropi->id}/contenedor", ['contenedor' => 'NUEVO123'])->assertRedirect();
    $nuevo = Importacion::where('contenedor', 'NUEVO123')->firstOrFail();
    expect($nuevo->linea_negocio)->toBe(LineaNegocio::Dropi)
        ->and($nuevo->ordenes()->pluck('compras_ordenes.id')->all())->toBe([$ocDropi->id]);
});

it('de punta a punta en Dropi: recibir, agregar gastos, liquidar, y el asiento queda en el libro de Dropi', function () {
    $this->actingAs($this->aracely);
    $oc = ($this->ocImportacion)(LineaNegocio::Dropi);

    // Bodega recibe los 100 desde el panel.
    Livewire::test(RegistrarRecepcionDropi::class, ['orden' => $oc->id])
        ->fillForm(['items' => [['orden_item_id' => $oc->items->first()->id, 'cantidad_recibida' => 100, 'clasificacion' => 'apto']]])
        ->call('confirmar')->assertHasNoFormErrors();

    Livewire::test(NuevaImportacionDropi::class)
        ->fillForm(['contenedor' => 'MSKU7725140', 'moneda_origen' => 'CNY'])
        ->call('crear')->assertHasNoFormErrors();
    $imp = Importacion::latest('id')->firstOrFail();

    $pagina = Livewire::test(VerImportacionDropi::class, ['importacion' => $imp->id]);
    $pagina->callAction('agregarOrden', data: ['orden_id' => $oc->id])
        ->assertNotified("Orden {$oc->numero} agregada al contenedor.");
    // El contenedor vacío tomó la tasa de la orden.
    expect((float) $imp->fresh()->tasa_cambio_liquidacion)->toBe(520.0);

    $pagina->callAction('agregarGasto', data: [
        'concepto' => 'flete', 'moneda' => 'COP', 'monto' => 100000, 'capitalizable' => true,
        'metodo_prorrateo' => 'volumen', 'fecha' => now()->toDateString(),
        'comprobante' => UploadedFile::fake()->create('factura flete.pdf', 50, 'application/pdf'),
    ])->assertNotified('Gasto agregado.');
    $pagina->callAction('agregarGasto', data: [
        'concepto' => 'agente_aduana', 'moneda' => 'COP', 'monto' => 30000, 'capitalizable' => false,
        'metodo_prorrateo' => 'valor', 'fecha' => now()->toDateString(),
    ])->assertNotified('Gasto agregado.');

    // El soporte quedó guardado con su nombre, colgado del gasto.
    $flete = $imp->gastos()->where('concepto', 'flete')->firstOrFail();
    $soporte = ImportacionDocumento::where('gasto_id', $flete->id)->firstOrFail();
    expect($soporte->nombre)->toBe('factura flete.pdf')
        ->and($soporte->tipo)->toBe('comprobante_gasto');
    Storage::disk(ImportacionDocumento::DISCO)->assertExists($soporte->ruta);

    $pagina->callAction('liquidar')->assertNotified('Importación liquidada: costos prorrateados.');

    $imp->refresh();
    expect($imp->estado)->toBe(EstadoImportacion::Liquidada)
        // 100 × 3,50 × 520 + el flete; la agencia no es capitalizable.
        ->and((float) $imp->valor_total_costo)->toBe(282000.0);

    $asiento = MovimientoContable::where('origen_type', Importacion::class)->where('origen_id', $imp->id)->get();
    expect($asiento)->not->toBeEmpty()
        ->and($asiento->every(fn ($m) => $m->linea_negocio === LineaNegocio::Dropi))->toBeTrue()
        ->and(round($asiento->sum('debe') - $asiento->sum('haber'), 2))->toBe(0.0);

    // El 1465 que entró con la recepción sale entero, del mismo libro.
    $transito = fn (LineaNegocio $l) => round((float) MovimientoContable::where('cuenta_puc', '1465')->where('linea_negocio', $l->value)->sum('debe')
        - (float) MovimientoContable::where('cuenta_puc', '1465')->where('linea_negocio', $l->value)->sum('haber'), 2);
    expect($transito(LineaNegocio::Dropi))->toBe(0.0)
        ->and(MovimientoContable::where('linea_negocio', LineaNegocio::GreatBaby->value)->count())->toBe(0);

    // Liquidado, ya no ofrece agregar gastos ni volver a liquidar.
    Livewire::test(VerImportacionDropi::class, ['importacion' => $imp->id])
        ->assertActionHidden('agregarGasto')
        ->assertActionHidden('liquidar');
});

it('un gasto sólo se registra en pesos o en la moneda del contenedor', function () {
    $this->actingAs($this->aracely);
    $imp = CrearImportacion::run(['moneda_origen' => 'CNY', 'tasa_cambio_liquidacion' => 520], LineaNegocio::Dropi);

    // La pantalla ni ofrece otra moneda: el contenedor no tiene con qué convertirla.
    Livewire::test(VerImportacionDropi::class, ['importacion' => $imp->id])
        ->callAction('agregarGasto', data: [
            'concepto' => 'flete', 'moneda' => 'USD', 'monto' => 900, 'capitalizable' => true,
            'metodo_prorrateo' => 'valor', 'fecha' => now()->toDateString(),
        ])
        ->assertHasActionErrors(['moneda']);

    // Y la acción compartida la rechaza igual, por si llega por otro lado.
    expect(fn () => App\Modules\Compras\Actions\AgregarGastoImportacion::run($imp, [
        'concepto' => 'flete', 'moneda' => 'USD', 'monto' => 900, 'capitalizable' => true,
        'metodo_prorrateo' => 'valor', 'fecha' => now()->toDateString(),
    ]))->toThrow(Illuminate\Validation\ValidationException::class);

    // En yuanes sí, y se convierte a la tasa del contenedor.
    Livewire::test(VerImportacionDropi::class, ['importacion' => $imp->id])
        ->callAction('agregarGasto', data: [
            'concepto' => 'flete', 'moneda' => 'CNY', 'monto' => 100, 'capitalizable' => true,
            'metodo_prorrateo' => 'valor', 'fecha' => now()->toDateString(),
        ])
        ->assertNotified('Gasto agregado.');

    expect($imp->gastos()->count())->toBe(1)
        ->and((float) $imp->gastos()->first()->monto_base)->toBe(52000.0);
});

it('desde el panel no se ven ni se abren contenedores de Great Baby', function () {
    $this->actingAs($this->aracely);
    $dropi = CrearImportacion::run(['moneda_origen' => 'CNY', 'contenedor' => 'DROPI-1'], LineaNegocio::Dropi);
    $gb = CrearImportacion::run(['moneda_origen' => 'CNY', 'contenedor' => 'GB-1']);

    Livewire::test(ImportacionesDropi::class)
        ->assertCanSeeTableRecords([$dropi])
        ->assertCanNotSeeTableRecords([$gb]);

    $this->get(VerImportacionDropi::getUrl(['importacion' => $gb->id]))->assertNotFound();
    $this->get(VerImportacionDropi::getUrl(['importacion' => $dropi->id]))->assertOk()->assertSee('DROPI-1');
});

it('la OC de importación muestra su moneda y su contenedor, no pesos', function () {
    $this->actingAs($this->aracely);
    $oc = ($this->ocImportacion)(LineaNegocio::Dropi);
    $imp = CrearImportacion::run(['moneda_origen' => 'CNY'], LineaNegocio::Dropi);
    app(SeguimientoContenedor::class)->ligarOrden($imp, $oc);

    $this->get(VerOrdenCompraDropi::getUrl(['orden' => $oc->id]))
        ->assertOk()
        ->assertSee('CNY 350,00')
        ->assertSee($imp->numero);
});

it('sin la casilla de importaciones no se entra a las pantallas de contenedores', function () {
    $vendedor = User::factory()->create();
    $vendedor->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));
    $imp = CrearImportacion::run(['moneda_origen' => 'CNY'], LineaNegocio::Dropi);

    $this->actingAs($vendedor);
    $this->get(ImportacionesDropi::getUrl())->assertForbidden();
    $this->get(VerImportacionDropi::getUrl(['importacion' => $imp->id]))->assertForbidden();
    $this->get(NuevaImportacionDropi::getUrl())->assertForbidden();
});
