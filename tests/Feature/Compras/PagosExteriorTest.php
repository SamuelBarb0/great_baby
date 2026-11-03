<?php

/**
 * Pagos a China, promedios reales y productos que faltan (reunión del 21-oct-2026).
 */

use App\Models\Contacto;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Actions\LiquidarImportacion;
use App\Modules\Compras\Enums\ConceptoGastoImportacion;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\CompromisoPago;
use App\Modules\Compras\Models\GastoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\CuentasPorPagar;
use App\Modules\Compras\Services\PlanDePagos;
use App\Modules\Compras\Services\PreliquidarOrden;
use App\Modules\Compras\Services\ReporteContenedores;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Support\Reglas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-11-03 10:00:00');

    $this->contador = User::factory()->create();
    $this->contador->assignRole(Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']));
    $this->jorge = User::factory()->create();
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'AdminBodega', 'guard_name' => 'web']));

    $this->proveedor = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900555666',
        'nombre_completo' => 'Topwin', 'es_proveedor' => true,
    ]);
    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'PPAL', 'nombre' => 'Bodega principal', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    // 1.000 RMB a 520 pesos: 520.000.
    $this->orden = function (array $linea = []): OrdenCompra {
        auth()->forgetGuards();
        $orden = CrearOrdenCompra::run([
            'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
            'tipo' => 'importacion', 'moneda' => 'CNY', 'tasa_cambio' => 520,
            'items' => [['descripcion' => 'AB-0085 · oso beige', 'iva_pct' => 0, 'cantidad' => 100, 'cajas' => 10, 'unidades_por_caja' => 10, 'cbm_caja' => 0.5, 'precio_unit' => 10, ...$linea]],
        ]);
        AprobarOrdenCompra::run($orden);

        return $orden->fresh('items');
    };
    $this->contenedor = function (OrdenCompra $orden, ?string $zarpe = null): Importacion {
        $imp = Importacion::create([
            'numero' => Importacion::siguienteNumero(), 'contenedor' => 'MSKU7725140', 'moneda_origen' => 'CNY',
            'tasa_cambio_liquidacion' => 520, 'estado' => EstadoImportacion::EnTransito, 'fecha_zarpe' => $zarpe,
        ]);
        $imp->ordenes()->attach($orden->id, ['volumen_m3' => 5]);

        return $imp;
    };
    $this->plan = fn () => app(PlanDePagos::class);
    $this->fila = function () {
        ($this->plan)()->asegurarPlanes();

        return ($this->plan)()->pendientes()->first();
    };
});

afterEach(function () {
    Carbon::setTestNow();
    Reglas::limpiarRequestCache();
});

it('toda orden aprobada nace con 90 días desde el zarpe, y sin zarpe no inventa la fecha', function () {
    $orden = ($this->orden)();
    $imp = ($this->contenedor)($orden);

    $f = ($this->fila)();
    expect($f['plazo'])->toBe(90)
        ->and($f['vence'])->toBeNull()
        ->and($f['semaforo'])->toBe('sin_fecha')
        ->and($f['monto_cop'])->toBe(520000.0);

    $imp->update(['fecha_zarpe' => '2026-10-01']);

    expect(($this->plan)()->pendientes()->first()['vence'])->toBe('2026-12-30');
});

it('una orden en borrador no entra a la lista', function () {
    auth()->forgetGuards();
    CrearOrdenCompra::run([
        'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id, 'tipo' => 'importacion', 'moneda' => 'CNY', 'tasa_cambio' => 520,
        'items' => [['descripcion' => 'X', 'iva_pct' => 0, 'cantidad' => 1, 'precio_unit' => 1]],
    ]);

    expect(($this->fila)())->toBeNull();
});

it('el semáforo: al día, naranja a 10 días, rojo a 3 y vencida', function (string $zarpe, string $esperado) {
    ($this->contenedor)(($this->orden)(), $zarpe);

    expect(($this->fila)()['semaforo'])->toBe($esperado);
})->with([
    'faltan 11' => ['2026-08-16', 'al_dia'],
    'faltan 10' => ['2026-08-15', 'naranja'],
    'faltan 4' => ['2026-08-09', 'naranja'],
    'faltan 3' => ['2026-08-08', 'rojo'],
    'hoy' => ['2026-08-05', 'rojo'],
    'ayer' => ['2026-08-04', 'vencida'],
]);

it('la fábrica: 30 % al pedir y 70 % cuando se carga', function () {
    $orden = ($this->orden)();
    $imp = ($this->contenedor)($orden);

    $this->actingAs($this->contador)->post("/app/compras/orden/{$orden->id}/plan-pago", ['cuotas' => [
        ['concepto' => 'Anticipo', 'porcentaje' => 30, 'base' => 'emision', 'dias' => 0],
        ['concepto' => 'Saldo', 'porcentaje' => 70, 'base' => 'fabricacion', 'dias' => 0],
    ]])->assertSessionHas('success');

    $filas = ($this->plan)()->pendientes()->keyBy('concepto');
    expect($filas['Anticipo']['monto_cop'])->toBe(156000.0)
        ->and($filas['Anticipo']['vence'])->toBe('2026-11-03')
        ->and($filas['Saldo']['monto_cop'])->toBe(364000.0)
        ->and($filas['Saldo']['vence'])->toBeNull();

    $imp->etapas()->create(['etapa' => 'cargado', 'fecha' => '2026-10-20']);

    expect(($this->plan)()->pendientes()->keyBy('concepto')['Saldo']['vence'])->toBe('2026-10-20');
});

it('un plan que no suma 100 % o con pagos registrados no se guarda', function () {
    $orden = ($this->orden)();
    ($this->plan)()->asegurarPlanes();
    $cuota = fn (float $p) => ['concepto' => 'X', 'porcentaje' => $p, 'base' => 'zarpe', 'dias' => 30];

    $this->actingAs($this->contador)->post("/app/compras/orden/{$orden->id}/plan-pago", ['cuotas' => [$cuota(60)]])->assertSessionHas('error');

    CompromisoPago::where('orden_id', $orden->id)->update(['pagado_en' => '2026-11-01']);
    $this->actingAs($this->contador)->post("/app/compras/orden/{$orden->id}/plan-pago", ['cuotas' => [$cuota(100)]])->assertSessionHas('error');

    expect(CompromisoPago::where('orden_id', $orden->id)->first()->concepto)->toBe('Pago total');
});

it('avisa a los 10 días y a los 3 una sola vez, y a quien corresponde', function () {
    ($this->contenedor)(($this->orden)(), '2026-08-15'); // faltan 10
    $plan = ($this->plan)();
    $plan->asegurarPlanes();

    expect($plan->avisar())->toBe(1)->and($plan->avisar())->toBe(0);
    expect(NotificacionErp::where('tipo', 'pago_exterior_naranja')->pluck('user_id'))
        ->toContain($this->contador->id)
        ->not->toContain($this->jorge->id);

    Carbon::setTestNow('2026-11-10 10:00:00'); // faltan 3
    expect($plan->avisar())->toBe(1)->and($plan->avisar())->toBe(0);
    expect(NotificacionErp::where('tipo', 'pago_exterior_rojo')->count())->toBeGreaterThan(0);
});

it('vencida insiste cada pocos días, salvo que tenga un seguimiento reciente', function () {
    ($this->contenedor)(($this->orden)(), '2026-08-01');
    $plan = ($this->plan)();
    $plan->asegurarPlanes();

    expect($plan->avisar())->toBe(1)->and($plan->avisar())->toBe(0);

    Carbon::setTestNow('2026-11-06 10:00:00');
    expect($plan->avisar())->toBe(1);

    $plan->registrarSeguimiento(CompromisoPago::first(), 'Se pidió prórroga a Topwin', $this->contador->id);
    Carbon::setTestNow('2026-11-09 09:00:00');
    expect($plan->avisar())->toBe(1, 'el seguimiento ya tiene 3 días');
});

it('la cuota vencida abre el aviso para quien tiene la casilla, y se va con el seguimiento', function () {
    ($this->contenedor)(($this->orden)(), '2026-08-01');
    ($this->plan)()->asegurarPlanes();
    $c = CompromisoPago::first();

    $this->actingAs($this->contador)->get('/app/compras/pagos-exterior')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Compras/PagosExterior')->has('pagos_sin_seguimiento', 1)->where('pagos_sin_seguimiento.0.id', $c->id));

    // A Jorge no se le frena la bodega por un pago a China.
    $this->actingAs($this->jorge)->get('/app/compras/importacion')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('pagos_sin_seguimiento', 0));
    $this->actingAs($this->jorge)->get('/app/compras/pagos-exterior')->assertForbidden();

    $this->actingAs($this->contador)->post("/app/compras/pagos-exterior/{$c->id}/seguimiento", ['nota' => 'x'])->assertSessionHasErrors('nota');
    $this->actingAs($this->contador)->post("/app/compras/pagos-exterior/{$c->id}/seguimiento", ['nota' => 'Se transfiere el viernes'])->assertSessionHas('success');

    $this->actingAs($this->contador)->get('/app/compras/pagos-exterior')
        ->assertInertia(fn (Assert $p) => $p->has('pagos_sin_seguimiento', 0)->where('filas.0.seguimiento_nota', 'Se transfiere el viernes'));
});

it('marcar pagada la saca de la lista', function () {
    ($this->contenedor)(($this->orden)(), '2026-08-01');
    ($this->plan)()->asegurarPlanes();
    $c = CompromisoPago::first();

    $this->actingAs($this->contador)->post("/app/compras/pagos-exterior/{$c->id}/pagado", ['fecha' => '2026-11-02'])->assertSessionHas('success');
    $this->actingAs($this->contador)->post("/app/compras/pagos-exterior/{$c->id}/pagado", ['fecha' => '2027-01-01'])->assertSessionHasErrors('fecha');

    expect(($this->plan)()->pendientes())->toHaveCount(0)
        ->and($c->fresh()->pagado_en->toDateString())->toBe('2026-11-02');
});

it('estima el interés con los tramos pactados, y sin ellos no inventa', function () {
    ($this->contenedor)(($this->orden)(), '2026-08-01'); // vencida hace 4 días

    expect(($this->fila)()['interes'])->toBe(0.0);

    Reglas::set('compras.interes_tramos', '[{"desde":1,"hasta":30,"pct":2},{"desde":31,"hasta":60,"pct":5}]');

    expect(($this->plan)()->pendientes()->first()['interes'])->toBe(10400.0);
});

it('la proyección junta China y nacionalización, y lo vencido cae en esta semana', function () {
    $orden = ($this->orden)();
    $imp = ($this->contenedor)($orden, '2026-08-01');
    $imp->etapas()->create(['etapa' => 'levante', 'fecha' => '2026-11-03']);
    GastoImportacion::create([
        'importacion_id' => $imp->id, 'concepto' => ConceptoGastoImportacion::Flete, 'descripcion' => 'Flete',
        'monto' => 1000, 'monto_base' => 1000, 'capitalizable' => true, 'metodo_prorrateo' => 'volumen',
    ]);
    $plan = ($this->plan)();
    $plan->asegurarPlanes();

    // Parámetros por defecto: 5 m³ de flete y gasto, más arancel e IVA sobre 520.000 + flete.
    $u = app(PreliquidarOrden::class)->handle($orden)['lineas'][0]['unidad'];
    $estimado = ($u['flete'] + $u['arancel'] + $u['iva'] + $u['gasto_colombia']) * 100;

    $p = $plan->proyeccion($plan->pendientes());
    $semana = collect($p['semanas'])->firstWhere('semana', '2026-11-02');

    expect($semana['china'])->toBe(520000.0)
        ->and($semana['nacionalizacion'])->toEqualWithDelta($estimado - 1000, 1.0)
        ->and($semana['total'])->toEqualWithDelta(520000 + $estimado - 1000, 1.0);
});

it('cuentas por pagar cuenta el plazo de una importación desde el zarpe', function () {
    $orden = ($this->orden)();
    ($this->contenedor)($orden, '2026-08-01');
    $this->actingAs($this->jorge)->post('/app/compras/recepcion', [
        'orden_id' => $orden->id, 'factura_proveedor' => 'F-1',
        'items' => [['orden_item_id' => $orden->items->first()->id, 'cantidad_recibida' => 100, 'clasificacion' => 'apto']],
    ])->assertRedirect();

    $f = app(CuentasPorPagar::class)->pendientes()->first();
    expect($f['vencimiento'])->toBe('2026-10-30')
        ->and($f['saldo'])->toBe(520000.0);
});

it('con tres contenedores liquidados la orden nueva nace con el promedio real', function () {
    $liquidar = function (float $flete, float $otros) {
        $orden = ($this->orden)(['producto_id' => Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => 'P-'.fake()->unique()->numerify('####'), 'nombre' => 'P', 'precio_proveedor' => 1, 'activo' => true, 'desglose_stock' => false]))->id]);
        $imp = ($this->contenedor)($orden);
        foreach ([[ConceptoGastoImportacion::Flete, $flete], [ConceptoGastoImportacion::AgenteAduana, $otros]] as [$c, $m]) {
            GastoImportacion::create(['importacion_id' => $imp->id, 'concepto' => $c, 'descripcion' => 'G', 'monto' => $m, 'monto_base' => $m, 'capitalizable' => true, 'metodo_prorrateo' => 'volumen']);
        }
        $this->actingAs($this->contador);
        LiquidarImportacion::run($imp->fresh());
    };

    $liquidar(1000000, 500000);
    $liquidar(2000000, 500000);
    expect(PreliquidarOrden::promediosReales())->toBe([]);

    $liquidar(3000000, 500000);
    $p = PreliquidarOrden::parametrosIniciales();

    // 6.000.000 de flete y 1.500.000 de otros en 15 m³.
    expect($p['flete_m3'])->toBe(400000.0)
        ->and($p['gasto_colombia_m3'])->toBe(100000.0);
});

it('crea el producto de la línea con referencia al recibir, y sube al inventario', function () {
    $orden = ($this->orden)();

    $this->actingAs($this->jorge)->post('/app/compras/recepcion', [
        'orden_id' => $orden->id,
        'items' => [['orden_item_id' => $orden->items->first()->id, 'cantidad_recibida' => 100, 'clasificacion' => 'apto']],
    ])->assertRedirect();

    $producto = Producto::where('referencia', 'AB-0085')->first();
    expect($producto->nombre)->toBe('oso beige')
        ->and((float) $producto->precio_proveedor)->toBe(5200.0)
        ->and((float) InventarioMovimiento::where('producto_id', $producto->id)->sum('cantidad'))->toBe(100.0);
});

it('la misma referencia es el mismo producto: no crea otro', function () {
    $existente = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => 'AB-0085', 'nombre' => 'Oso', 'precio_proveedor' => 1, 'activo' => true, 'desglose_stock' => false]));
    $orden = ($this->orden)();

    $this->actingAs($this->jorge)->post("/app/compras/orden/{$orden->id}/productos", [])->assertSessionHas('success');

    expect($orden->items->first()->fresh()->producto_id)->toBe($existente->id)
        ->and(Producto::where('referencia', 'AB-0085')->count())->toBe(1);
});

it('una fila sin referencia avisa y se la puede poner a mano', function () {
    $orden = ($this->orden)(['descripcion' => 'Sin REF (NUEVA ORDEN fila 6) · con pilas']);
    $item = $orden->items->first();

    $this->actingAs($this->jorge)->get("/app/compras/recepcion/nueva?oc={$orden->id}")
        ->assertInertia(fn (Assert $p) => $p->where('oc.items.0.no_sube', true));
    $this->actingAs($this->jorge)->post("/app/compras/orden/{$orden->id}/productos", [])->assertSessionHas('success');
    expect($item->fresh()->producto_id)->toBeNull();

    $this->actingAs($this->jorge)->post("/app/compras/orden/{$orden->id}/productos", ['referencias' => [$item->id => 'NUEVA-77']])->assertSessionHas('success');

    expect($item->fresh()->producto_id)->toBe(Producto::where('referencia', 'NUEVA-77')->value('id'));
});

it('el reporte cuenta dónde están y saca el flete por m³ de cada contenedor', function () {
    $imp = ($this->contenedor)(($this->orden)(), '2026-10-01');
    GastoImportacion::create(['importacion_id' => $imp->id, 'concepto' => ConceptoGastoImportacion::Flete, 'descripcion' => 'F', 'monto' => 1000000, 'monto_base' => 1000000, 'capitalizable' => true, 'metodo_prorrateo' => 'volumen']);
    GastoImportacion::create(['importacion_id' => $imp->id, 'concepto' => ConceptoGastoImportacion::Arancel, 'descripcion' => 'A', 'monto' => 200000, 'monto_base' => 200000, 'capitalizable' => true, 'metodo_prorrateo' => 'valor']);

    $r = app(ReporteContenedores::class)->handle();

    expect($r['conteos']['en_agua'])->toBe(1)
        ->and($r['filas'][0]['flete_m3'])->toBe(200000.0)
        ->and($r['filas'][0]['arancel'])->toBe(200000.0)
        ->and($r['filas'][0]['fob'])->toBe(520000.0);

    $this->actingAs($this->contador)->get('/app/compras/reporte')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('contenedores.conteos.en_agua', 1));
});
