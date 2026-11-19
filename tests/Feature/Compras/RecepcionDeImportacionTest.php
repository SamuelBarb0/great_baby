<?php

/**
 * El stock de una importación sube cuando Jorge la acepta, con lo que él contó, y
 * en pesos. La liquidación sólo corrige el costo (reuniones del 21 y 30-oct-2026).
 */

use App\Models\Contacto;
use App\Models\EmpresaConfig;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Actions\LiquidarImportacion;
use App\Modules\Compras\Enums\ConceptoGastoImportacion;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\GastoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionEtapa;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\SeguimientoContenedor;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Models\Traslado;
use App\Modules\Inventario\Support\CostoPromedio;
use App\Support\Reglas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Queue::fake();

    $this->jorge = User::factory()->create(['name' => 'Jorge']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'AdminBodega', 'guard_name' => 'web']));
    $this->aracely = User::factory()->create(['name' => 'Aracely']);
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->proveedor = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900333444',
        'nombre_completo' => 'Topwin', 'es_proveedor' => true,
    ]);
    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'PPAL', 'nombre' => 'Bodega principal', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $this->producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'TET', 'nombre' => 'Tetero', 'precio_proveedor' => 10, 'activo' => true, 'desglose_stock' => false,
    ]));
    $this->principal = EmpresaConfig::current();

    // 100 teteros a 3,50 RMB, con la tasa de 520 pesos.
    $this->orden = function (array $extra = [], string $moneda = 'CNY', float $tasa = 520): OrdenCompra {
        auth()->forgetGuards();
        $orden = CrearOrdenCompra::run([
            'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
            'tipo' => 'importacion', 'moneda' => $moneda, 'tasa_cambio' => $tasa,
            'items' => [[
                'producto_id' => $this->producto->id, 'descripcion' => 'Tetero', 'iva_pct' => 0,
                'cantidad' => 100, 'cajas' => 10, 'unidades_por_caja' => 10, 'cbm_caja' => 0.5, 'peso_caja_kg' => 8,
                'precio_unit' => 3.5, ...$extra,
            ]],
        ]);
        AprobarOrdenCompra::run($orden);

        return $orden->fresh('items');
    };
    $this->contenedor = function (OrdenCompra $orden): Importacion {
        $imp = Importacion::create([
            'numero' => Importacion::siguienteNumero(), 'contenedor' => 'MSKU7725140',
            'moneda_origen' => $orden->moneda, 'estado' => EstadoImportacion::EnPuerto, 'tasa_cambio_liquidacion' => $orden->tasa_cambio,
        ]);
        $imp->ordenes()->attach($orden->id, ['volumen_m3' => 5, 'peso_kg' => 80]);

        return $imp;
    };
    $this->recibir = fn (OrdenCompra $orden, array $lineas) => $this->actingAs($this->jorge)->post('/app/compras/recepcion', [
        'orden_id' => $orden->id,
        'items' => array_map(fn ($l) => ['orden_item_id' => $orden->items->first()->id, ...$l], $lineas),
    ]);
    $this->saldo = fn (int $ubicacion) => (float) InventarioMovimiento::where('producto_id', $this->producto->id)
        ->whereNull('variante_id')->where('ubicacion_id', $ubicacion)->sum('cantidad');
});

afterEach(function () {
    Carbon::setTestNow();
    Reglas::limpiarRequestCache();
});

it('sube al inventario lo que Jorge contó, no lo pedido, y lo dañado no queda a la venta', function () {
    $orden = ($this->orden)();

    ($this->recibir)($orden, [
        ['cantidad_recibida' => 90, 'clasificacion' => 'apto'],
        ['cantidad_recibida' => 5, 'clasificacion' => 'averia', 'motivo_clasificacion' => 'Cajas mojadas'],
        ['cantidad_recibida' => 5, 'clasificacion' => 'faltante', 'motivo_clasificacion' => 'No venían'],
    ])->assertRedirect();

    $averia = InventarioUbicacion::where('categoria', CategoriaUbicacion::AveriaReparar->value)->first();
    $item = $orden->items->first()->fresh();

    expect(($this->saldo)($this->bodega->id))->toBe(90.0)
        ->and(($this->saldo)($averia->id))->toBe(5.0)
        ->and($averia->categoria->disponibleParaVenta())->toBeFalse()
        // Lo que no llegó sigue pendiente en la orden.
        ->and((float) $item->cantidad_recibida)->toBe(95.0)
        ->and($orden->fresh()->estado->value)->toBe('parcial');
});

it('la deuda y el 1465 quedan en pesos: 3,50 RMB no son 3,50 pesos', function () {
    $orden = ($this->orden)();

    ($this->recibir)($orden, [['cantidad_recibida' => 100, 'clasificacion' => 'apto']]);

    // 100 × 3,50 × 520
    $mov = MovimientoContable::where('cuenta_puc', '1465')->first();
    expect((float) $mov->debe)->toBe(182000.0);
});

it('entra al costo estimado de la pre-liquidación, y sin parámetros al valor en pesos', function () {
    $orden = ($this->orden)();
    $estimado = app(App\Modules\Compras\Services\PreliquidarOrden::class)->handle($orden)['lineas'][0]['unidad']['costo'];

    ($this->recibir)($orden, [['cantidad_recibida' => 100, 'clasificacion' => 'apto']]);

    $mov = InventarioMovimiento::where('producto_id', $this->producto->id)->first();
    expect($mov->tipo)->toBe('entrada_importacion')
        ->and(round((float) $mov->costo_unit, 2))->toBe(round((float) $estimado, 2))
        ->and((float) $mov->costo_unit)->toBeGreaterThan(1820.0);
});

it('liquidar corrige el costo sin tocar la cantidad, y el promedio queda en el costo real', function () {
    $orden = ($this->orden)();
    $imp = ($this->contenedor)($orden);
    ($this->recibir)($orden, [['cantidad_recibida' => 100, 'clasificacion' => 'apto']]);
    GastoImportacion::create([
        'importacion_id' => $imp->id, 'concepto' => ConceptoGastoImportacion::Flete,
        'descripcion' => 'Flete', 'monto' => 100000, 'monto_base' => 100000,
        'capitalizable' => true, 'metodo_prorrateo' => 'volumen',
    ]);

    $this->actingAs($this->aracely);
    LiquidarImportacion::run($imp->fresh());

    // (182.000 + 100.000) / 100
    $linea = $imp->fresh('lineas')->lineas->first();
    expect((float) $linea->costo_final_unit)->toBe(2820.0)
        ->and(($this->saldo)($this->bodega->id))->toBe(100.0)
        ->and(CostoPromedio::porUbicacion([['variante_id' => null, 'producto_id' => $this->producto->id]], $this->bodega->id))
        ->toBe(['p:'.$this->producto->id => 2820.0]);

    // Lo que entró al 1465 en la recepción es lo que sale en la liquidación.
    $tres = MovimientoContable::where('cuenta_puc', '1465')->get();
    expect((float) $tres->sum('debe'))->toBe((float) $tres->sum('haber'));
});

it('con un faltante, liquida sólo lo recibido y el 1465 cuadra', function () {
    $orden = ($this->orden)();
    $imp = ($this->contenedor)($orden);
    ($this->recibir)($orden, [
        ['cantidad_recibida' => 80, 'clasificacion' => 'apto'],
        ['cantidad_recibida' => 20, 'clasificacion' => 'faltante', 'motivo_clasificacion' => 'No venían'],
    ]);

    $this->actingAs($this->aracely);
    LiquidarImportacion::run($imp->fresh());

    $linea = $imp->fresh('lineas')->lineas->first();
    expect((float) $linea->cantidad)->toBe(80.0)
        ->and((float) $linea->costo_fob_total)->toBe(145600.0);
    $tres = MovimientoContable::where('cuenta_puc', '1465')->get();
    expect((float) $tres->sum('debe'))->toBe(145600.0)
        ->and((float) $tres->sum('haber'))->toBe(145600.0);
});

it('una línea que no llegó entera no se liquida, no carga flete y el 1465 cuadra', function () {
    $otro = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => 'OTRO', 'nombre' => 'Otro', 'precio_proveedor' => 1, 'activo' => true, 'desglose_stock' => false]));
    auth()->forgetGuards();
    $orden = CrearOrdenCompra::run([
        'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id, 'tipo' => 'importacion', 'moneda' => 'CNY', 'tasa_cambio' => 520,
        'items' => [
            ['producto_id' => $this->producto->id, 'descripcion' => 'Tetero', 'iva_pct' => 0, 'cantidad' => 100, 'cajas' => 10, 'unidades_por_caja' => 10, 'cbm_caja' => 0.5, 'precio_unit' => 3.5],
            ['producto_id' => $otro->id, 'descripcion' => 'Otro', 'iva_pct' => 0, 'cantidad' => 100, 'cajas' => 10, 'unidades_por_caja' => 10, 'cbm_caja' => 0.5, 'precio_unit' => 3.5],
        ],
    ]);
    AprobarOrdenCompra::run($orden);
    $orden = $orden->fresh('items');
    $imp = ($this->contenedor)($orden);
    GastoImportacion::create(['importacion_id' => $imp->id, 'concepto' => ConceptoGastoImportacion::Flete, 'descripcion' => 'Flete', 'monto' => 100000, 'monto_base' => 100000, 'capitalizable' => true, 'metodo_prorrateo' => 'volumen']);

    $this->actingAs($this->jorge)->post('/app/compras/recepcion', ['orden_id' => $orden->id, 'items' => [
        ['orden_item_id' => $orden->items[0]->id, 'cantidad_recibida' => 100, 'clasificacion' => 'apto'],
        ['orden_item_id' => $orden->items[1]->id, 'cantidad_recibida' => 100, 'clasificacion' => 'faltante', 'motivo_clasificacion' => 'No vino'],
    ]])->assertRedirect();

    $this->actingAs($this->aracely);
    LiquidarImportacion::run($imp->fresh());

    $imp = $imp->fresh('lineas');
    expect($imp->lineas)->toHaveCount(1)
        ->and((float) $imp->valor_fob)->toBe(182000.0)
        // Todo el flete cae en lo que sí llegó.
        ->and((float) $imp->lineas->first()->costo_final_unit)->toBe(2820.0);
    $tres = MovimientoContable::where('cuenta_puc', '1465')->get();
    expect((float) $tres->sum('debe'))->toBe(182000.0)
        ->and((float) $tres->sum('haber'))->toBe(182000.0);
});

it('la pantalla anticipa lo que la liquidación va a dar', function () {
    $orden = ($this->orden)();
    $imp = ($this->contenedor)($orden);
    GastoImportacion::create(['importacion_id' => $imp->id, 'concepto' => ConceptoGastoImportacion::Flete, 'descripcion' => 'Flete', 'monto' => 100000, 'monto_base' => 100000, 'capitalizable' => true, 'metodo_prorrateo' => 'volumen']);
    GastoImportacion::create(['importacion_id' => $imp->id, 'concepto' => ConceptoGastoImportacion::Flete, 'descripcion' => 'Otro', 'monto' => 20000, 'monto_base' => 20000, 'capitalizable' => false, 'metodo_prorrateo' => 'volumen']);
    $url = "/app/compras/importacion/{$imp->id}";

    // Nada recibido: va por lo pedido, y lo dice.
    $this->actingAs($this->aracely)->get($url)->assertInertia(fn (Assert $p) => $p
        ->where('importacion.total_fob', 182000)
        ->where('importacion.fob_es_previsto', true)
        ->where('importacion.fob_sin_recibir', 1)
        ->where('importacion.total_gastos', 120000)
        ->where('importacion.gastos_al_inventario', 100000));

    // Recibidos 90: cuenta lo recibido.
    ($this->recibir)($orden, [['cantidad_recibida' => 90, 'clasificacion' => 'apto']])->assertRedirect();
    $this->actingAs($this->aracely)->get($url)->assertInertia(fn (Assert $p) => $p
        ->where('importacion.total_fob', 163800)
        ->where('importacion.fob_sin_recibir', 0));

    // Y la liquidación da lo mismo que se anticipó.
    LiquidarImportacion::run($imp->fresh());
    expect((float) $imp->fresh()->valor_fob)->toBe(163800.0)
        ->and((float) $imp->fresh()->valor_gastos)->toBe(100000.0);
    $this->actingAs($this->aracely)->get($url)->assertInertia(fn (Assert $p) => $p
        ->where('importacion.total_fob', 163800)
        ->where('importacion.fob_es_previsto', false));
});

it('sin tasa de cambio la pantalla abre y dice qué falta', function () {
    $imp = ($this->contenedor)(($this->orden)([], 'CNY', 1));

    $this->actingAs($this->aracely)->get("/app/compras/importacion/{$imp->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('importacion.total_fob', 0)
            ->where('importacion.fob_aviso', fn ($aviso) => str_contains($aviso, 'Falta la tasa de cambio')));
});

it('liquidar antes de recibir no sube nada, y lo que se reciba después entra al costo real', function () {
    $orden = ($this->orden)();
    $imp = ($this->contenedor)($orden);

    $this->actingAs($this->aracely);
    LiquidarImportacion::run($imp->fresh());
    expect(($this->saldo)($this->bodega->id))->toBe(0.0);

    ($this->recibir)($orden->fresh('items'), [['cantidad_recibida' => 100, 'clasificacion' => 'apto']]);

    expect(($this->saldo)($this->bodega->id))->toBe(100.0)
        ->and((float) InventarioMovimiento::where('producto_id', $this->producto->id)->first()->costo_unit)->toBe(1820.0);
});

it('sin tasa de cambio no deja recibir, lo dice y no guarda nada', function () {
    $orden = ($this->orden)([], 'CNY', 1);

    ($this->recibir)($orden, [['cantidad_recibida' => 100, 'clasificacion' => 'apto']])
        ->assertSessionHasErrors('recepcion');

    expect(($this->saldo)($this->bodega->id))->toBe(0.0)
        ->and(App\Modules\Compras\Models\RecepcionCompra::count())->toBe(0);

    $this->actingAs($this->jorge)->get("/app/compras/recepcion/nueva?oc={$orden->id}")
        ->assertInertia(fn (Assert $p) => $p->where('oc.falta_tasa', fn ($m) => str_contains($m, 'tasa de cambio')));
});

it('Jorge ve el valor en pesos, las cajas y qué es de e-commerce', function () {
    $ecommerce = EmpresaConfig::create(['codigo' => 'ECOM', 'nit' => '900111222', 'razon_social' => 'E-commerce SAS', 'nombre_comercial' => 'E-commerce', 'es_principal' => false]);
    $orden = ($this->orden)(['empresa_destino_id' => $ecommerce->id]);
    ($this->contenedor)($orden);

    $this->actingAs($this->jorge)->get("/app/compras/recepcion/nueva?oc={$orden->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('oc.es_importacion', true)
            ->where('oc.contenedor', 'MSKU7725140')
            ->where('oc.items.0.precio_unit', fn ($v) => (float) $v === 1820.0)
            ->where('oc.items.0.cajas', 10)
            ->where('oc.items.0.destino', 'E-commerce')
            ->where('oc.items.0.para_otra_empresa', true));
});

it('lo de e-commerce avisa a Araceli y le deja el traslado para aceptar', function () {
    $destino = InventarioUbicacion::create([
        'codigo' => 'ECOM-1', 'nombre' => 'E-commerce', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    Reglas::set('compras.ubicacion_ecommerce', $destino->id);
    $ecommerce = EmpresaConfig::create(['codigo' => 'ECOM', 'nit' => '900111222', 'razon_social' => 'E-commerce SAS', 'es_principal' => false]);
    $orden = ($this->orden)(['empresa_destino_id' => $ecommerce->id]);

    ($this->recibir)($orden, [['cantidad_recibida' => 100, 'clasificacion' => 'apto']])->assertRedirect("/app/compras/recepcion/".App\Modules\Compras\Models\RecepcionCompra::max('id'));

    $traslado = Traslado::with('items')->first();
    expect($traslado->estado->value)->toBe('borrador')
        ->and($traslado->origen_id)->toBe($this->bodega->id)
        ->and($traslado->destino_id)->toBe($destino->id)
        ->and((float) $traslado->items->first()->cantidad_solicitada)->toBe(100.0);

    $aviso = NotificacionErp::where('tipo', 'recepcion_ecommerce')->where('user_id', $this->aracely->id)->first();
    expect($aviso->url)->toBe("/app/inventario/traslados/{$traslado->id}");
    // Primero entra todo a Great Baby; pasa a e-commerce cuando Araceli acepte.
    expect(($this->saldo)($this->bodega->id))->toBe(100.0);
});

it('sin ubicación de e-commerce configurada avisa igual, sin traslado', function () {
    $ecommerce = EmpresaConfig::create(['codigo' => 'ECOM', 'nit' => '900111222', 'razon_social' => 'E-commerce SAS', 'es_principal' => false]);
    $orden = ($this->orden)(['empresa_destino_id' => $ecommerce->id]);

    ($this->recibir)($orden, [['cantidad_recibida' => 100, 'clasificacion' => 'apto']])->assertSessionHasNoErrors();

    expect(Traslado::count())->toBe(0)
        ->and(NotificacionErp::where('tipo', 'recepcion_ecommerce')->first()->mensaje)->toContain('falta configurar');
});

it('una línea de e-commerce sin producto creado avisa igual a Araceli', function () {
    $ecommerce = EmpresaConfig::create(['codigo' => 'ECOM', 'nit' => '900111222', 'razon_social' => 'E-commerce SAS', 'es_principal' => false]);
    $orden = ($this->orden)(['empresa_destino_id' => $ecommerce->id, 'producto_id' => null]);

    ($this->recibir)($orden, [['cantidad_recibida' => 100, 'clasificacion' => 'apto']])->assertRedirect();

    expect(Traslado::count())->toBe(0)
        ->and(NotificacionErp::where('tipo', 'recepcion_ecommerce')->where('user_id', $this->aracely->id)->count())->toBe(1);
});

it('lo de Great Baby y lo dañado no generan traslado', function () {
    $orden = ($this->orden)(['empresa_destino_id' => $this->principal->id]);

    ($this->recibir)($orden, [['cantidad_recibida' => 100, 'clasificacion' => 'apto']]);

    expect(Traslado::count())->toBe(0)
        ->and(NotificacionErp::where('tipo', 'recepcion_ecommerce')->count())->toBe(0);
});

it('recibir marca el contenedor en bodega, salvo que falten declaraciones', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    $orden = ($this->orden)();
    $imp = ($this->contenedor)($orden);

    ($this->recibir)($orden, [['cantidad_recibida' => 100, 'clasificacion' => 'apto']]);
    expect(ImportacionEtapa::where('importacion_id', $imp->id)->where('etapa', 'en_bodega')->exists())->toBeTrue();

    $orden2 = ($this->orden)();
    $imp2 = ($this->contenedor)($orden2);
    $imp2->declaraciones()->create(['numero' => 'D-1', 'selectividad' => 'fisica']);

    ($this->recibir)($orden2, [['cantidad_recibida' => 100, 'clasificacion' => 'apto']])->assertSessionHasNoErrors();
    expect(ImportacionEtapa::where('importacion_id', $imp2->id)->where('etapa', 'en_bodega')->exists())->toBeFalse()
        ->and(($this->saldo)($this->bodega->id))->toBe(200.0);
});

it('no deja cambiar la tasa cuando ya hay mercancía recibida con ella', function () {
    $orden = ($this->orden)();
    $imp = ($this->contenedor)($orden);
    ($this->recibir)($orden, [['cantidad_recibida' => 100, 'clasificacion' => 'apto']]);

    $this->actingAs($this->jorge)->put("/app/compras/importacion/{$imp->id}/datos", ['tasa_cambio_liquidacion' => 600])
        ->assertSessionHas('error');

    expect((float) $imp->fresh()->tasa_cambio_liquidacion)->toBe(520.0);
});

it('le avisa a quien recibe el día, las cajas y el peso, una sola vez', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    $orden = ($this->orden)();
    $imp = ($this->contenedor)($orden);
    $imp->etapas()->create(['etapa' => 'levante', 'fecha' => '2026-11-03']);

    $s = app(SeguimientoContenedor::class);
    expect($s->avisarLlegadas())->toBe(1)
        ->and($s->avisarLlegadas())->toBe(0);

    $aviso = NotificacionErp::where('tipo', 'contenedor_por_llegar')->where('user_id', $this->jorge->id)->first();
    expect($aviso->titulo)->toContain('MSKU7725140')->toContain('06/11')
        ->and($aviso->mensaje)->toContain('10 cajas')->toContain('80 kg')->toContain('5,0 m³');
});

it('no avisa de un contenedor que todavía está lejos, y la nota de Liz llega con el aviso', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    $orden = ($this->orden)();
    $imp = ($this->contenedor)($orden);
    $imp->etapas()->create(['etapa' => 'zarpado', 'fecha' => '2026-11-01']);

    expect(app(SeguimientoContenedor::class)->avisarLlegadas())->toBe(0);

    $imp->etapas()->create(['etapa' => 'camino_bodega', 'fecha' => '2026-11-03', 'notas' => 'Llega de noche, entre 10 y 1']);

    expect(app(SeguimientoContenedor::class)->avisarLlegadas())->toBe(1)
        ->and(NotificacionErp::where('tipo', 'contenedor_por_llegar')->first()->mensaje)->toContain('Llega de noche');
});

it('un gasto en pesos no se multiplica por la tasa, y en la moneda del contenedor sí', function () {
    $orden = ($this->orden)();
    $imp = ($this->contenedor)($orden);
    $gasto = fn (array $d) => $this->actingAs($this->jorge)->post("/app/compras/importacion/{$imp->id}/gasto", [
        'concepto' => 'flete', 'monto' => 5000000, 'moneda' => 'COP', 'metodo_prorrateo' => 'volumen',
        'capitalizable' => true, 'fecha' => '2026-11-03', ...$d,
    ]);

    $gasto([])->assertSessionHas('success');
    $gasto(['monto' => 100, 'moneda' => 'CNY', 'concepto' => 'agente_aduana'])->assertSessionHas('success');

    $gastos = $imp->gastos()->orderBy('id')->get();
    expect((float) $gastos[0]->monto_base)->toBe(5000000.0)
        ->and((float) $gastos[1]->monto_base)->toBe(52000.0)
        // Sin descripción: la columna no admite nulos, y antes daba un 500.
        ->and($gastos[0]->descripcion)->toBe('Flete internacional');
});

it('un gasto en otra moneda o con un concepto que no existe se rechaza con su mensaje', function () {
    $orden = ($this->orden)();
    $imp = ($this->contenedor)($orden);
    $gasto = fn (array $d) => $this->actingAs($this->jorge)->post("/app/compras/importacion/{$imp->id}/gasto", [
        'concepto' => 'flete', 'monto' => 100, 'moneda' => 'COP', 'metodo_prorrateo' => 'volumen', 'fecha' => '2026-11-03', ...$d,
    ]);

    $gasto(['moneda' => 'USD'])->assertSessionHasErrors('moneda');
    $gasto(['concepto' => 'bodegaje'])->assertSessionHasErrors('concepto');

    expect($imp->gastos()->count())->toBe(0);
});
