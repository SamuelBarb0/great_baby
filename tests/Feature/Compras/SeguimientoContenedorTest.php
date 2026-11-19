<?php

/**
 * Armar y seguir el contenedor (reuniones con Alejandro y Liz, 21 y 30-oct-2026).
 */

use App\Models\Contacto;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Actions\LiquidarImportacion;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Enums\EtapaContenedor;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionEtapa;
use App\Modules\Compras\Services\SeguimientoContenedor;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->liz = User::factory()->create(['name' => 'Liz']);
    $this->liz->assignRole(Role::firstOrCreate(['name' => 'AdminBodega', 'guard_name' => 'web']));
    $this->vendedor = User::factory()->create();
    $this->vendedor->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    $this->proveedor = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900888222',
        'nombre_completo' => 'Topwin', 'es_proveedor' => true,
    ]);
    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'PPAL', 'nombre' => 'Bodega principal', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $this->orden = function (string $ref = 'TET', string $moneda = 'CNY', bool $aprobar = true) {
        // Quien crea una OC no puede aprobarla: la orden nace fuera de la sesión de Liz.
        auth()->forgetGuards();
        $p = Producto::create(['referencia' => $ref, 'nombre' => $ref, 'precio_proveedor' => 10, 'activo' => true]);
        $v = ProductoVariante::create(['producto_id' => $p->id, 'talla' => 'U', 'color_codigo' => 'NA', 'color_nombre' => 'NA']);
        $orden = CrearOrdenCompra::run([
            'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
            'tipo' => 'importacion', 'moneda' => $moneda, 'tasa_cambio' => 520,
            'items' => [[
                'producto_id' => $p->id, 'variante_id' => $v->id, 'descripcion' => $ref, 'iva_pct' => 0,
                'cantidad' => 100, 'cajas' => 10, 'unidades_por_caja' => 10, 'cbm_caja' => 0.5, 'peso_caja_kg' => 8, 'precio_unit' => 10,
            ]],
        ]);
        if ($aprobar) {
            AprobarOrdenCompra::run($orden);
        }

        return $orden->fresh();
    };

    $this->imp = Importacion::create([
        'numero' => Importacion::siguienteNumero(), 'contenedor' => 'MSKU7725140',
        'moneda_origen' => 'USD', 'estado' => EstadoImportacion::EnTransito,
    ]);
    $this->url = "/app/compras/importacion/{$this->imp->id}";
    $this->marcar = fn (string $etapa, string $fecha) => $this->actingAs($this->liz)
        ->post("{$this->url}/etapa", ['etapa' => $etapa, 'fecha' => $fecha]);
});

afterEach(fn () => Carbon::setTestNow());

it('liga la orden con su volumen y su peso, y el contenedor toma la moneda y la tasa', function () {
    $orden = ($this->orden)();

    $this->actingAs($this->liz)->post("{$this->url}/orden", ['orden_id' => $orden->id])->assertSessionHas('success');

    $imp = $this->imp->fresh();
    $pivote = $imp->ordenes()->first()->pivot;
    expect((float) $pivote->volumen_m3)->toBe(5.0)
        ->and((float) $pivote->peso_kg)->toBe(80.0)
        ->and($imp->moneda_origen)->toBe('CNY')
        ->and((float) $imp->tasa_cambio_liquidacion)->toBe(520.0);
});

it('la tasa del contenedor va con la moneda de la orden, no con la que traía el formulario', function () {
    // El formulario de contenedor propone USD a 4.000.
    $this->imp->update(['moneda_origen' => 'USD', 'tasa_cambio_liquidacion' => 4000]);
    $orden = ($this->orden)();

    $this->actingAs($this->liz)->post("{$this->url}/orden", ['orden_id' => $orden->id])->assertSessionHas('success');

    $imp = $this->imp->fresh();
    expect($imp->moneda_origen)->toBe('CNY')
        ->and((float) $imp->tasa_cambio_liquidacion)->toBe(520.0);
});

it('si la moneda coincide, se respeta la tasa que ya tenía el contenedor', function () {
    $this->imp->update(['moneda_origen' => 'CNY', 'tasa_cambio_liquidacion' => 530]);
    $orden = ($this->orden)();

    $this->actingAs($this->liz)->post("{$this->url}/orden", ['orden_id' => $orden->id])->assertSessionHas('success');

    expect((float) $this->imp->fresh()->tasa_cambio_liquidacion)->toBe(530.0);
});

it('un contenedor ligado por la pantalla ya se puede liquidar', function () {
    $orden = ($this->orden)();
    $this->actingAs($this->liz)->post("{$this->url}/orden", ['orden_id' => $orden->id]);

    LiquidarImportacion::run($this->imp->fresh());

    expect($this->imp->fresh()->estado)->toBe(EstadoImportacion::Liquidada)
        ->and($this->imp->fresh()->lineas)->toHaveCount(1);
});

it('no deja ligar una orden en dos contenedores, sin aprobar o en otra moneda', function () {
    $orden = ($this->orden)('A');
    $this->actingAs($this->liz)->post("{$this->url}/orden", ['orden_id' => $orden->id])->assertSessionHas('success');

    $otro = Importacion::create(['numero' => Importacion::siguienteNumero(), 'moneda_origen' => 'CNY', 'estado' => EstadoImportacion::EnTransito]);
    $this->actingAs($this->liz)->post("/app/compras/importacion/{$otro->id}/orden", ['orden_id' => $orden->id])
        ->assertSessionHas('error', fn ($m) => str_contains($m, $this->imp->numero));
    expect($otro->ordenes()->count())->toBe(0);

    $sinAprobar = ($this->orden)('B', 'CNY', false);
    $this->actingAs($this->liz)->post("{$this->url}/orden", ['orden_id' => $sinAprobar->id])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'aprobada'));

    $enDolares = ($this->orden)('C', 'USD');
    $this->actingAs($this->liz)->post("{$this->url}/orden", ['orden_id' => $enDolares->id])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'USD'));

    expect($this->imp->ordenes()->count())->toBe(1);
});

it('no deja cambiar órdenes ni datos de un contenedor liquidado', function () {
    $orden = ($this->orden)();
    $this->actingAs($this->liz)->post("{$this->url}/orden", ['orden_id' => $orden->id]);
    LiquidarImportacion::run($this->imp->fresh());

    $this->actingAs($this->liz)->delete("{$this->url}/orden/{$orden->id}")->assertSessionHas('error');
    $this->actingAs($this->liz)->put("{$this->url}/datos", ['contenedor' => 'OTRO'])->assertSessionHas('error');

    expect($this->imp->ordenes()->count())->toBe(1)
        ->and($this->imp->fresh()->contenedor)->toBe('MSKU7725140');
});

it('edita los datos del contenedor después de creado', function () {
    $this->actingAs($this->liz)->put("{$this->url}/datos", [
        'contenedor' => 'TCLU1234567', 'bl_awb' => 'BL-9', 'eta' => '2026-12-20', 'tasa_cambio_liquidacion' => 530,
    ])->assertSessionHas('success');

    $imp = $this->imp->fresh();
    expect($imp->contenedor)->toBe('TCLU1234567')
        ->and($imp->eta->toDateString())->toBe('2026-12-20')
        ->and((float) $imp->tasa_cambio_liquidacion)->toBe(530.0);
});

it('las etapas mueven las fechas y el estado que usan kardex, reportes y marketing', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');

    ($this->marcar)('zarpado', '2026-09-01')->assertSessionHas('success');
    expect($this->imp->fresh()->fecha_zarpe->toDateString())->toBe('2026-09-01')
        ->and($this->imp->fresh()->estado)->toBe(EstadoImportacion::EnTransito);

    ($this->marcar)('en_puerto', '2026-10-13')->assertSessionHas('success');
    expect($this->imp->fresh()->fecha_llegada->toDateString())->toBe('2026-10-13')
        ->and($this->imp->fresh()->estado)->toBe(EstadoImportacion::EnPuerto)
        ->and($this->imp->fresh()->diasEnTransito())->toBe(42);

    ($this->marcar)('levante', '2026-11-01')->assertSessionHas('success');
    expect($this->imp->fresh()->fecha_nacionalizacion->toDateString())->toBe('2026-11-01')
        ->and($this->imp->fresh()->estado)->toBe(EstadoImportacion::Nacionalizada);
});

it('rechaza fechas futuras y fuera de orden', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');

    ($this->marcar)('zarpado', '2026-11-04')->assertSessionHas('error', fn ($m) => str_contains($m, 'futura'));
    ($this->marcar)('en_puerto', '2026-10-10')->assertSessionHas('success');
    ($this->marcar)('zarpado', '2026-10-11')->assertSessionHas('error', fn ($m) => str_contains($m, 'posterior'));
    ($this->marcar)('duta', '2026-10-09')->assertSessionHas('error', fn ($m) => str_contains($m, 'anterior'));

    expect(ImportacionEtapa::where('importacion_id', $this->imp->id)->count())->toBe(1);
});

it('quitar una etapa por error devuelve el contenedor a la anterior', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    ($this->marcar)('zarpado', '2026-10-01');
    ($this->marcar)('en_puerto', '2026-11-01');

    $this->actingAs($this->liz)->delete("{$this->url}/etapa/en_puerto")->assertSessionHas('success');

    $imp = $this->imp->fresh();
    expect($imp->estado)->toBe(EstadoImportacion::EnTransito)
        ->and($imp->fecha_llegada)->toBeNull();
});

it('un contenedor viejo con zarpe en la columna cuenta aunque no tenga etapas', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    $this->imp->update(['fecha_zarpe' => '2026-10-24']);

    $r = app(SeguimientoContenedor::class)->resumen($this->imp->fresh());

    expect($r['etapa'])->toBe('zarpado')
        ->and($r['siguiente'])->toBe('en_puerto')
        ->and($r['dias_en_etapa'])->toBe(10)
        ->and($r['dias_atraso'])->toBe(0);
});

it('avisa el atraso a quien ve importaciones, no a los demás, y no lo repite cada día', function () {
    Carbon::setTestNow('2026-09-01 10:00:00');
    ($this->marcar)('zarpado', '2026-09-01');

    // 42 días es lo normal hasta puerto: el día 42 todavía no hay atraso.
    Carbon::setTestNow('2026-10-13 10:00:00');
    expect(app(SeguimientoContenedor::class)->avisarAtrasos())->toBe(0);

    Carbon::setTestNow('2026-10-15 10:00:00');
    expect(app(SeguimientoContenedor::class)->avisarAtrasos())->toBe(1);

    $avisos = NotificacionErp::where('tipo', 'contenedor_atrasado')->get();
    expect($avisos->pluck('user_id'))->toContain($this->liz->id)
        ->and($avisos->pluck('user_id'))->not->toContain($this->vendedor->id)
        ->and($avisos->first()->url)->toBe($this->url)
        ->and($avisos->first()->titulo)->toContain('MSKU7725140');

    Carbon::setTestNow('2026-10-16 10:00:00');
    expect(app(SeguimientoContenedor::class)->avisarAtrasos())->toBe(0);

    Carbon::setTestNow('2026-10-18 10:00:00');
    expect(app(SeguimientoContenedor::class)->avisarAtrasos())->toBe(1);
});

it('con tres contenedores medidos manda el promedio real, no el de la reunión', function () {
    foreach ([30, 30, 33] as $i => $dias) {
        $otro = Importacion::create(['numero' => Importacion::siguienteNumero(), 'moneda_origen' => 'USD', 'estado' => EstadoImportacion::EnTransito]);
        ImportacionEtapa::create(['importacion_id' => $otro->id, 'etapa' => 'zarpado', 'fecha' => '2026-01-01']);
        ImportacionEtapa::create(['importacion_id' => $otro->id, 'etapa' => 'en_puerto', 'fecha' => Carbon::parse('2026-01-01')->addDays($dias)->toDateString()]);

        $s = app(SeguimientoContenedor::class);
        expect($s->diasEsperados(EtapaContenedor::EnPuerto))->toBe($i < 2 ? 42 : 31);
    }
});

it('calcula la llegada a bodega sumando lo que falta', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    ($this->marcar)('levante', '2026-11-01');

    // Levante + 1 día a camino (ya pasó: cuenta desde hoy) + 2 hasta bodega.
    expect(app(SeguimientoContenedor::class)->resumen($this->imp->fresh())['llegada_estimada'])->toBe('2026-11-05');
});

it('la selectividad va por declaración y el levante del contenedor espera a todas', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    $u = fn () => $this->actingAs($this->liz);

    $u()->post("{$this->url}/declaracion", ['numero' => 'D-1', 'selectividad' => 'automatica'])->assertSessionHas('success');
    $u()->post("{$this->url}/declaracion", ['numero' => 'D-2', 'selectividad' => 'fisica', 'fecha_cita' => '2026-11-05'])->assertSessionHas('success');

    $d = $this->imp->declaraciones()->get();
    expect($d->firstWhere('numero', 'D-1')->fecha_levante->toDateString())->toBe('2026-11-03')
        ->and($d->firstWhere('numero', 'D-2')->fecha_levante)->toBeNull();

    ($this->marcar)('levante', '2026-11-03')->assertSessionHas('error', fn ($m) => str_contains($m, '1 declaración'));
    expect(ImportacionEtapa::where('importacion_id', $this->imp->id)->where('etapa', 'levante')->exists())->toBeFalse();

    $fisica = $d->firstWhere('numero', 'D-2');
    $u()->put("{$this->url}/declaracion/{$fisica->id}", ['numero' => 'D-2', 'selectividad' => 'fisica', 'fecha_cita' => '2026-11-05', 'fecha_levante' => '2026-11-03'])
        ->assertSessionHas('success');

    // Liz las va agregando de a una: el sistema no sabe cuál es la última, así
    // que el levante del contenedor lo marca ella cuando ya no faltan.
    expect(ImportacionEtapa::where('importacion_id', $this->imp->id)->where('etapa', 'levante')->exists())->toBeFalse();

    ($this->marcar)('levante', '2026-11-03')->assertSessionHas('success');
    expect($this->imp->fresh()->estado)->toBe(EstadoImportacion::Nacionalizada);
});

it('no deja tocar la declaración de otro contenedor', function () {
    $otro = Importacion::create(['numero' => Importacion::siguienteNumero(), 'moneda_origen' => 'USD', 'estado' => EstadoImportacion::EnTransito]);
    $ajena = $otro->declaraciones()->create(['numero' => 'X', 'selectividad' => 'pendiente']);

    $this->actingAs($this->liz)->delete("{$this->url}/declaracion/{$ajena->id}")->assertNotFound();
    $this->actingAs($this->liz)->put("{$this->url}/declaracion/{$ajena->id}", ['selectividad' => 'automatica'])->assertNotFound();

    expect($ajena->fresh()->selectividad)->toBe('pendiente');
});

it('marcar una inspección avisa a Compras, y se puede resolver', function () {
    $this->actingAs($this->liz)->post("{$this->url}/inspeccion", [
        'tipo' => 'forzosa_puerto', 'fecha' => '2026-11-03', 'notas' => 'Van a abrir el contenedor',
    ])->assertSessionHas('success');

    $aviso = NotificacionErp::where('tipo', 'contenedor_inspeccion')->where('user_id', $this->liz->id)->first();
    expect($aviso->mensaje)->toBe('Van a abrir el contenedor')
        ->and($aviso->titulo)->toContain('forzosa');

    $i = $this->imp->inspecciones()->first();
    $this->actingAs($this->liz)->post("{$this->url}/inspeccion/{$i->id}/resolver")->assertSessionHas('success');
    expect($i->fresh()->resuelta_en)->not->toBeNull();
});

it('quien no tiene la casilla no entra', function () {
    $this->actingAs($this->vendedor)->post("{$this->url}/etapa", ['etapa' => 'zarpado', 'fecha' => '2026-11-01'])->assertForbidden();
});

it('la pantalla y el listado traen el seguimiento', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    ($this->marcar)('zarpado', '2026-09-01');
    $orden = ($this->orden)();
    $this->actingAs($this->liz)->post("{$this->url}/orden", ['orden_id' => $orden->id]);

    $this->actingAs($this->liz)->get($this->url)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Compras/Importacion/Show')
        ->where('puede_editar_seguimiento', true)
        ->where('seguimiento.resumen.etapa', 'zarpado')
        ->where('seguimiento.resumen.dias_atraso', 21)
        ->has('seguimiento.etapas', 11)
        ->has('seguimiento.ordenes', 1)
        ->where('seguimiento.volumen_total', fn ($v) => (float) $v === 5.0));

    $this->actingAs($this->liz)->get('/app/compras/importacion')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('kpis.atrasados', 1)
        ->where('importaciones.data.0.seguimiento.etapa', 'zarpado'));
});
