<?php

/**
 * Referencias, etiquetas, código de barras, Excel para China, sugerido y lista de
 * empaque (reuniones con Alejandro y Liz, 21 y 30-oct-2026).
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionDocumento;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\SugeridoPedido;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Support\Code128;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->gerente = User::factory()->create();
    $this->gerente->assignRole(Role::firstOrCreate(['name' => 'Gerente', 'guard_name' => 'web']));
    $this->jorge = User::factory()->create();
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'AdminBodega', 'guard_name' => 'web']));
    $this->vendedor = User::factory()->create();
    $this->vendedor->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    $this->proveedor = Contacto::create(['tipo_documento' => 'NIT', 'numero_documento' => '900777888', 'nombre_completo' => 'Topwin', 'es_proveedor' => true]);
    $this->bodega = InventarioUbicacion::create(['codigo' => 'PPAL', 'nombre' => 'Principal', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true]);
    $this->producto = fn (string $ref, array $extra = []) => Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => $ref, 'nombre' => "Producto {$ref}", 'precio_proveedor' => 1, 'activo' => true, 'desglose_stock' => false, ...$extra]));

    $this->orden = function (array $lineas): OrdenCompra {
        auth()->forgetGuards();
        $orden = CrearOrdenCompra::run([
            'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id, 'tipo' => 'importacion', 'moneda' => 'CNY', 'tasa_cambio' => 520,
            'items' => array_map(fn ($l) => ['iva_pct' => 0, 'cajas' => 10, 'unidades_por_caja' => 10, 'cantidad' => 100, 'cbm_caja' => 0.5, 'precio_unit' => 10, ...$l], $lineas),
        ]);
        AprobarOrdenCompra::run($orden);

        return $orden->fresh('items');
    };
    $this->excelLista = function (array $filas): string {
        $ruta = tempnam(sys_get_temp_dir(), 'pl').'.xlsx';
        $w = new Writer;
        $w->openToFile($ruta);
        $w->addRow(Row::fromValues(['REF', 'DESCRIPTION', 'CTNS', 'QTY/CTN', 'T.QTY', 'CBM/CTN', 'TOTAL CBM']));
        foreach ($filas as $f) {
            $w->addRow(Row::fromValues($f));
        }
        $w->close();

        return $ruta;
    };
    $this->comparar = function (OrdenCompra $orden, array $filas) {
        $imp = Importacion::create(['numero' => Importacion::siguienteNumero(), 'contenedor' => 'MSKU1', 'moneda_origen' => 'CNY', 'tasa_cambio_liquidacion' => 520, 'estado' => EstadoImportacion::EnTransito]);
        $imp->ordenes()->attach($orden->id, ['volumen_m3' => 5]);
        $archivo = new UploadedFile(($this->excelLista)($filas), 'PL final.xlsx', null, null, true);
        $this->actingAs($this->jorge)->post("/app/compras/importacion/{$imp->id}/documento", ['tipo' => 'lista_empaque', 'archivo' => $archivo])->assertSessionHas('success');

        return [$imp, ImportacionDocumento::where('importacion_id', $imp->id)->first()];
    };
});

it('Code 128 calcula el control como dice la norma y arranca y termina bien', function () {
    // 104 + 48·1 + 42·2 + 42·3 + 17·4 + 18·5 + 19·6 + 35·7 = 879, y 879 mod 103 = 55.
    $s = Code128::simbolos('PJJ123C');
    expect($s[0])->toBe(104)
        ->and(end($s))->toBe(106)
        ->and($s[count($s) - 2])->toBe(55)
        ->and(count($s))->toBe(10);

    // Cada símbolo son 11 módulos; el de fin, 13.
    expect(count(Code128::modulos('A')))->toBe(11 * 3 + 13);
    expect(substr(Code128::png('AB-0085'), 1, 3))->toBe('PNG');
});

it('Code 128 rechaza lo que no sabe codificar', function () {
    Code128::simbolos('niño');
})->throws(InvalidArgumentException::class);

it('las barras de un código con tilde salen en ASCII y el escáner igual encuentra la variante', function () {
    // Los diseños como «LEÓ» generan códigos con tilde; Code 128 B no la tiene.
    expect(Code128::codificable('AND2512-79/154-02LEÓ-6M'))->toBe('AND2512-79/154-02LEO-6M')
        ->and(substr(Code128::png('AND2512-79/154-02LEÓ-6M'), 1, 3))->toBe('PNG')
        ->and(Code128::svg('AND2512-79/154-02LEÓ-6M'))->toContain('<rect x="')->toContain('LEÓ');

    // Lo que lee la pistola («LEO») encuentra la variante guardada con «LEÓ»
    // (codigo_barras es utf8mb4_unicode_ci), igual que hace ProcesarEscaneoEmpaque.
    $p = ($this->producto)('AND2512-79/154', ['desglose_stock' => true]);
    $v = ProductoVariante::create(['producto_id' => $p->id, 'codigo_barras' => 'AND2512-79/154-02LEÓ-6M']);
    expect(ProductoVariante::where('codigo_barras', Code128::codificable($v->codigo_barras))->value('id'))->toBe($v->id);
});

it('una referencia alterna es el mismo producto, y no se puede repetir en otro', function () {
    $oso = ($this->producto)('AB-0085');
    $otro = ($this->producto)('AB-0099');

    $this->actingAs($this->gerente)->post("/app/catalogo/producto/{$oso->id}/referencia", ['referencia' => 'TW-123', 'motivo' => 'Cambio de proveedor'])->assertSessionHas('success');

    expect(Producto::buscarPorReferencia('TW-123')->id)->toBe($oso->id);

    $this->actingAs($this->gerente)->post("/app/catalogo/producto/{$otro->id}/referencia", ['referencia' => 'TW-123'])->assertSessionHas('error');
    $this->actingAs($this->gerente)->post("/app/catalogo/producto/{$otro->id}/referencia", ['referencia' => 'AB-0085'])->assertSessionHas('error');
    $this->actingAs($this->vendedor)->post("/app/catalogo/producto/{$oso->id}/referencia", ['referencia' => 'X-1'])->assertForbidden();

    // La línea de una orden que trae la referencia nueva usa el mismo producto.
    $orden = ($this->orden)([['descripcion' => 'TW-123 · oso', 'cantidad' => 100]]);
    $this->actingAs($this->gerente)->post("/app/compras/orden/{$orden->id}/productos", []);
    expect($orden->items->first()->fresh()->producto_id)->toBe($oso->id)
        ->and(Producto::where('referencia', 'TW-123')->count())->toBe(0);
});

it('la etiqueta se ancla al producto y sólo admite imagen', function () {
    $oso = ($this->producto)('AB-0085');

    $this->actingAs($this->gerente)->post("/app/catalogo/producto/{$oso->id}/etiqueta", ['etiqueta' => UploadedFile::fake()->create('e.pdf', 10, 'application/pdf')])->assertSessionHasErrors('etiqueta');
    $this->actingAs($this->gerente)->post("/app/catalogo/producto/{$oso->id}/etiqueta", ['etiqueta' => UploadedFile::fake()->image('etiqueta.png', 200, 120)])->assertSessionHas('success');
    // Subir otra la reemplaza: es siempre la misma para el mismo producto.
    $this->actingAs($this->gerente)->post("/app/catalogo/producto/{$oso->id}/etiqueta", ['etiqueta' => UploadedFile::fake()->image('nueva.png', 200, 120)])->assertSessionHas('success');

    expect($oso->fresh()->getMedia('etiqueta_importacion'))->toHaveCount(1)
        ->and($oso->fresh()->getFirstMedia('etiqueta_importacion')->file_name)->toBe('nueva.png');

    $orden = ($this->orden)([['descripcion' => 'AB-0085 · oso', 'producto_id' => $oso->id], ['descripcion' => 'AB-0099 · otro']]);
    $this->actingAs($this->gerente)->get("/app/compras/oc/{$orden->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('etiquetas.con', 1)->where('etiquetas.sin', 1)->where('puede_excel', true));
});

it('el Excel para China separa por destino y lleva las imágenes puestas', function () {
    $oso = ($this->producto)('AB-0085');
    $oso->addMedia(UploadedFile::fake()->image('e.png', 200, 120))->toMediaCollection('etiqueta_importacion');
    $ecommerce = App\Models\EmpresaConfig::current();
    $dropi = App\Models\EmpresaConfig::create(['codigo' => 'ECOM', 'nit' => '900111222', 'razon_social' => 'E-commerce SAS', 'nombre_comercial' => 'E-commerce', 'es_principal' => false]);
    $orden = ($this->orden)([
        ['descripcion' => 'AB-0085 · oso', 'producto_id' => $oso->id],
        ['descripcion' => 'AB-0099 · otro', 'empresa_destino_id' => $dropi->id, 'cajas' => 5],
    ]);

    $r = $this->actingAs($this->gerente)->get("/app/compras/orden/{$orden->id}/excel");
    $r->assertOk();
    $libro = IOFactory::load($r->baseResponse->getFile()->getPathname());

    expect($libro->getSheetNames())->toBe([$ecommerce->nombreCorto(), 'E-commerce']);
    $h = $libro->getSheet(0);
    expect((string) $h->getCell('A5')->getValue())->toBe('AB-0085')
        ->and((string) $h->getCell('B5')->getValue())->toBe('oso')
        ->and((int) $h->getCell('C5')->getValue())->toBe(10)
        // Etiqueta y código de barras.
        ->and($h->getDrawingCollection())->toHaveCount(2);
    expect((string) $libro->getSheet(1)->getCell('A5')->getValue())->toBe('AB-0099')
        ->and((int) $libro->getSheet(1)->getCell('C5')->getValue())->toBe(5);

    // Lleva precios: bodega no lo baja, pero sí ve las etiquetas.
    $this->actingAs($this->jorge)->get("/app/compras/orden/{$orden->id}/excel")->assertForbidden();
    $this->actingAs($this->jorge)->get("/app/compras/orden/{$orden->id}/etiquetas")->assertOk();
    $this->actingAs($this->vendedor)->get("/app/compras/orden/{$orden->id}/etiquetas")->assertForbidden();
});

it('compara lo pedido con la lista, y lo que falta o sobra queda marcado', function () {
    $orden = ($this->orden)([
        ['descripcion' => 'AB-1 · igual'],
        ['descripcion' => 'AB-2 · menos'],
        ['descripcion' => 'AB-3 · quitada'],
    ]);
    [$imp, $doc] = ($this->comparar)($orden, [
        ['AB-1', 'igual', 10, 10, 100, 0.5, 5],
        ['AB-2', 'menos', 8, 10, 80, 0.5, 4],
        ['ZZ-9', 'sorpresa', 2, 10, 20, 0.5, 1],
    ]);

    $this->actingAs($this->jorge)->get("/app/compras/importacion/{$imp->id}/documento/{$doc->id}/comparar")->assertOk()
        ->assertInertia(function (Assert $p) {
            $p->component('Compras/Importacion/Comparacion')
                ->where('resumen.cajas_pedidas', 30)
                ->where('resumen.cajas_lista', 20)
                ->where('resumen.diferencias', 3)
                ->where('resumen.sin_motivo', 3)
                ->where('filas.0.referencia', 'AB-3')->where('filas.0.estado', 'quitada')
                ->where('filas.1.referencia', 'ZZ-9')->where('filas.1.estado', 'nueva')
                ->where('filas.2.referencia', 'AB-2')->where('filas.2.estado', 'cambia')
                ->where('filas.3.referencia', 'AB-1')->where('filas.3.estado', 'igual');
        });

    // No toca la orden.
    expect((float) $orden->items->first()->fresh()->cantidad)->toBe(100.0);
});

it('el motivo queda guardado, y unir una referencia la lee como la misma', function () {
    $orden = ($this->orden)([['descripcion' => 'AB-3 · cambió de código']]);
    [$imp, $doc] = ($this->comparar)($orden, [['TW-77', 'el mismo', 10, 10, 100, 0.5, 5]]);
    $url = "/app/compras/importacion/{$imp->id}/empaque";

    $this->actingAs($this->jorge)->post("{$url}/motivo", ['referencia' => 'AB-3', 'motivo' => 'El proveedor le cambió el código'])->assertSessionHas('success');
    $this->actingAs($this->jorge)->post("{$url}/motivo", ['referencia' => 'AB-3', 'motivo' => 'x'])->assertSessionHasErrors('motivo');
    $this->actingAs($this->jorge)->post("{$url}/vincular", ['referencia' => 'TW-77', 'orden_item_id' => $orden->items->first()->id])->assertSessionHas('success');

    $producto = $orden->items->first()->fresh()->producto;
    expect($producto->referencia)->toBe('AB-3')
        ->and(Producto::buscarPorReferencia('TW-77')->id)->toBe($producto->id);

    $this->actingAs($this->jorge)->get("/app/compras/importacion/{$imp->id}/documento/{$doc->id}/comparar")
        ->assertInertia(fn (Assert $p) => $p
            ->where('resumen.diferencias', 0)
            ->where('filas.0.estado', 'igual')
            ->where('filas.0.como_llego', 'TW-77'));
});

it('guarda todos los motivos de una vez, y si uno no vale no guarda ninguno', function () {
    $orden = ($this->orden)([['descripcion' => 'AB-1 · x'], ['descripcion' => 'AB-2 · y']]);
    [$imp, $doc] = ($this->comparar)($orden, [['ZZ-9', 'otra', 2, 10, 20, 0.5, 1]]);
    $url = "/app/compras/importacion/{$imp->id}/empaque/motivos";

    $this->actingAs($this->jorge)->post($url, ['motivos' => [
        ['referencia' => 'AB-1', 'motivo' => 'No la cargaron'],
        ['referencia' => 'AB-2', 'motivo' => 'x'],
    ]])->assertSessionHasErrors('motivos.1.motivo');
    expect(Illuminate\Support\Facades\DB::table('compras_diferencias_empaque')->count())->toBe(0);

    $this->actingAs($this->jorge)->post($url, ['motivos' => [
        ['referencia' => 'AB-1', 'motivo' => 'No la cargaron'],
        ['referencia' => 'AB-2', 'motivo' => 'Se agotó en la fábrica'],
    ]])->assertSessionHas('success');

    $this->actingAs($this->jorge)->get("/app/compras/importacion/{$imp->id}/documento/{$doc->id}/comparar")
        ->assertInertia(fn (Assert $p) => $p->where('resumen.diferencias', 3)->where('resumen.sin_motivo', 1));

    $this->actingAs($this->vendedor)->post($url, ['motivos' => [['referencia' => 'AB-1', 'motivo' => 'Otro motivo']]])->assertForbidden();
});

it('no une con una línea de otro contenedor', function () {
    $orden = ($this->orden)([['descripcion' => 'AB-3 · x']]);
    $ajena = ($this->orden)([['descripcion' => 'AB-4 · y']]);
    [$imp] = ($this->comparar)($orden, [['TW-77', 'x', 10, 10, 100, 0.5, 5]]);

    $this->actingAs($this->jorge)->post("/app/compras/importacion/{$imp->id}/empaque/vincular", ['referencia' => 'TW-77', 'orden_item_id' => $ajena->items->first()->id])->assertSessionHas('error');

    expect(Producto::buscarPorReferencia('TW-77'))->toBeNull();
});

it('un PDF no se compara: lo dice y queda guardado', function () {
    $orden = ($this->orden)([['descripcion' => 'AB-3 · x']]);
    $imp = Importacion::create(['numero' => Importacion::siguienteNumero(), 'moneda_origen' => 'CNY', 'estado' => EstadoImportacion::EnTransito]);
    $imp->ordenes()->attach($orden->id);
    $this->actingAs($this->jorge)->post("/app/compras/importacion/{$imp->id}/documento", ['tipo' => 'lista_empaque', 'archivo' => UploadedFile::fake()->create('PL.pdf', 50, 'application/pdf')]);
    $doc = ImportacionDocumento::first();

    $this->actingAs($this->jorge)->from("/app/compras/importacion/{$imp->id}")
        ->get("/app/compras/importacion/{$imp->id}/documento/{$doc->id}/comparar")
        ->assertRedirect()->assertSessionHas('error');
});

it('el sugerido: venta, stock y lo que viene dan lo que falta, en cajas', function () {
    $mueve = ($this->producto)('MUEVE-1');
    $quieto = ($this->producto)('QUIETO-1');
    $bajo = ($this->producto)('BAJO-1', ['stock_minimo' => 50]);
    $mov = fn (Producto $p, string $tipo, float $c, string $hace = '10 days') => InventarioMovimiento::create([
        'producto_id' => $p->id, 'ubicacion_id' => $this->bodega->id, 'tipo' => $tipo, 'cantidad' => $c, 'costo_unit' => 1, 'created_at' => now()->sub($hace),
    ]);

    // 180 vendidas en 90 días = 2 al día. Para 120 días hacen falta 240.
    $mov($mueve, 'ingreso', 200, '100 days');
    $mov($mueve, 'salida_venta', -180);
    // Quedan 20. Vienen 100 en camino. Faltan 120: 12 cajas de 10.
    ($this->orden)([['descripcion' => 'MUEVE-1 · x', 'producto_id' => $mueve->id, 'cantidad' => 100]]);
    $mov($quieto, 'ingreso', 40, '100 days');
    $mov($bajo, 'ingreso', 10, '100 days');
    // Una venta vieja o un traslado no son demanda de este periodo.
    $mov($quieto, 'salida_venta', -5, '200 days');
    $mov($quieto, 'traslado_salida', -5);

    $s = app(SugeridoPedido::class);
    $f = $s->handle(90, 120)->keyBy('referencia');

    expect($f['MUEVE-1']['vendido'])->toBe(180.0)
        ->and($f['MUEVE-1']['stock'])->toBe(20.0)
        ->and($f['MUEVE-1']['por_llegar'])->toBe(100.0)
        ->and($f['MUEVE-1']['sugerido'])->toBe(120.0)
        ->and($f['MUEVE-1']['cajas'])->toBe(12)
        ->and($f['MUEVE-1']['rotacion'])->toBe('alta')
        ->and($f['MUEVE-1']['cobertura_dias'])->toBe(60)
        ->and($f['QUIETO-1']['rotacion'])->toBe('sin_movimiento')
        ->and($f['QUIETO-1']['sugerido'])->toBe(0.0)
        ->and($f['BAJO-1']['bajo_minimo'])->toBeTrue();

    expect($s->resumen($s->handle(90, 120)))->toBe(['por_reforzar' => 1, 'bajo_minimo' => 1, 'sin_movimiento' => 2]);

    $this->actingAs($this->gerente)->get('/app/compras/sugerido?dias=90&objetivo=120&q=MUEVE')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Compras/Sugerido')->has('filas', 1)->where('filas.0.referencia', 'MUEVE-1'));
    $this->actingAs($this->vendedor)->get('/app/compras/sugerido')->assertForbidden();
});
