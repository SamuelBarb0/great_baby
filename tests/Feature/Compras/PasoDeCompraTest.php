<?php

/**
 * Cada pantalla de la importación dice qué sigue, quién lo hace y con qué botón.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Actions\LiquidarImportacion;
use App\Modules\Compras\Enums\ConceptoGastoImportacion;
use App\Modules\Compras\Enums\EtapaContenedor;
use App\Modules\Compras\Models\GastoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\SeguimientoContenedor;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Queue::fake();

    $this->jefe = User::factory()->create();
    $this->jefe->assignRole(Role::firstOrCreate(['name' => 'Gerente', 'guard_name' => 'web']));
    $this->bodeguero = User::factory()->create();
    $this->bodeguero->assignRole(Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']));
    $this->vendedor = User::factory()->create();
    $this->vendedor->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    $proveedor = Contacto::create(['tipo_documento' => 'NIT', 'numero_documento' => '900333445', 'nombre_completo' => 'Topwin', 'es_proveedor' => true]);
    $bodega = InventarioUbicacion::create(['codigo' => 'PPAL', 'nombre' => 'Bodega principal', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true]);
    $producto = Producto::sinKardexAutomatico(fn () => Producto::create(['referencia' => 'TET', 'nombre' => 'Tetero', 'precio_proveedor' => 10, 'activo' => true, 'desglose_stock' => false]));

    $this->borrador = function (string $moneda = 'CNY', ?int $productoId = null) use ($proveedor, $bodega, $producto): OrdenCompra {
        auth()->forgetGuards();

        return CrearOrdenCompra::run([
            'proveedor_id' => $proveedor->id, 'bodega_id' => $bodega->id,
            'tipo' => 'importacion', 'moneda' => $moneda, 'tasa_cambio' => 520,
            'items' => [[
                'producto_id' => $productoId ?? $producto->id, 'descripcion' => 'Tetero', 'iva_pct' => 0,
                'cantidad' => 100, 'cajas' => 10, 'unidades_por_caja' => 10, 'cbm_caja' => 0.5, 'peso_caja_kg' => 8, 'precio_unit' => 3.5,
            ]],
        ]);
    };
    $this->aprobada = function (string $moneda = 'CNY'): OrdenCompra {
        $orden = ($this->borrador)($moneda);
        AprobarOrdenCompra::run($orden);

        return $orden->fresh('items');
    };
    $this->poner = fn (OrdenCompra $orden, array $datos = []) => $this->actingAs($this->jefe)->post("/app/compras/orden/{$orden->id}/contenedor", $datos);
    $this->contenedorDe = fn (OrdenCompra $orden): Importacion => Importacion::whereHas('ordenes', fn ($q) => $q->whereKey($orden->id))->firstOrFail();
    $this->etapa = fn (Importacion $imp, EtapaContenedor $e) => app(SeguimientoContenedor::class)->marcarEtapa($imp, $e, Carbon::parse('2026-10-01'), null, null);
    $this->pasoDe = fn (string $url, User $u) => $this->actingAs($u)->get($url)->assertOk();
});

it('lleva la orden del borrador al contenedor', function () {
    $orden = ($this->borrador)();
    ($this->pasoDe)("/app/compras/oc/{$orden->id}", $this->jefe)->assertInertia(fn (Assert $p) => $p
        ->where('paso.numero', 1)
        ->where('paso.quien', 'Compras')
        ->where('paso.acciones.0.accion', 'aprobar'));

    AprobarOrdenCompra::run($orden);
    ($this->pasoDe)("/app/compras/oc/{$orden->id}", $this->jefe)->assertInertia(fn (Assert $p) => $p
        ->where('paso.numero', 2)
        ->where('paso.acciones.0.accion', 'contenedor')
        ->where('paso.acciones.1.href', "/app/compras/orden/{$orden->id}/excel")
        ->where('contenedor', null));
});

it('pide crear los productos antes de aprobar, sin obligar', function () {
    $orden = ($this->borrador)();
    $orden->items()->update(['producto_id' => null, 'descripcion' => 'NUEVO-1 · Cosa']);

    ($this->pasoDe)("/app/compras/oc/{$orden->id}", $this->jefe)->assertInertia(fn (Assert $p) => $p
        ->where('paso.numero', 1)
        ->where('paso.acciones.0.accion', 'ir-productos')
        ->where('paso.acciones.1.accion', 'aprobar'));
});

it('pone la orden en un contenedor nuevo con su moneda y su tasa', function () {
    $orden = ($this->aprobada)();

    ($this->poner)($orden, ['contenedor' => 'MSKU1'])->assertRedirectContains('tab=seguimiento');

    $imp = ($this->contenedorDe)($orden);
    expect($imp->contenedor)->toBe('MSKU1')
        ->and($imp->moneda_origen)->toBe('CNY')
        ->and((float) $imp->tasa_cambio_liquidacion)->toBe(520.0)
        ->and($imp->ordenes()->first()->pivot->volumen_m3)->toEqual(5);
});

it('la pone en uno abierto; si no se puede, no deja un contenedor huérfano', function () {
    $primera = ($this->aprobada)();
    ($this->poner)($primera);
    $imp = ($this->contenedorDe)($primera);

    // Sólo le ofrece los de su moneda.
    $otra = ($this->aprobada)('USD');
    ($this->pasoDe)("/app/compras/oc/{$otra->id}", $this->jefe)->assertInertia(fn (Assert $p) => $p->where('contenedores_abiertos', []));

    $this->from("/app/compras/oc/{$otra->id}");
    ($this->poner)($otra, ['importacion_id' => $imp->id])->assertSessionHas('error');
    expect(Importacion::count())->toBe(1);

    $segunda = ($this->aprobada)();
    ($this->pasoDe)("/app/compras/oc/{$segunda->id}", $this->jefe)->assertInertia(fn (Assert $p) => $p->where('contenedores_abiertos.0.id', $imp->id));
    ($this->poner)($segunda, ['importacion_id' => $imp->id])->assertRedirect();
    expect($imp->ordenes()->count())->toBe(2);

    // Ya en un contenedor no se puede poner en otro, ni crear uno de paso.
    ($this->poner)($segunda)->assertSessionHas('error');
    expect(Importacion::count())->toBe(1);
});

it('sólo deja poner la orden a quien maneja contenedores', function () {
    $orden = ($this->aprobada)();

    $this->actingAs($this->vendedor)->post("/app/compras/orden/{$orden->id}/contenedor")->assertForbidden();
    expect(Importacion::count())->toBe(0);
});

it('acompaña el contenedor del viaje a los costos', function () {
    $orden = ($this->aprobada)();
    ($this->poner)($orden);
    $imp = ($this->contenedorDe)($orden);
    $url = "/app/compras/importacion/{$imp->id}";

    ($this->pasoDe)($url, $this->jefe)->assertInertia(fn (Assert $p) => $p
        ->where('paso.numero', 3)
        ->where('paso.acciones.0.accion', 'marcar')
        ->where('paso.acciones.0.etiqueta', 'Marcar «En fabricación»'));

    ($this->etapa)($imp, EtapaContenedor::EnPuerto);
    ($this->pasoDe)($url, $this->jefe)->assertInertia(fn (Assert $p) => $p
        ->where('paso.numero', 3)
        ->where('paso.acciones.0.etiqueta', 'Marcar «DUTA aprobada»')
        ->where('paso.acciones.1.href', "/app/compras/recepcion/nueva?oc={$orden->id}"));

    ($this->etapa)($imp->fresh(), EtapaContenedor::CaminoBodega);
    ($this->pasoDe)($url, $this->jefe)->assertInertia(fn (Assert $p) => $p
        ->where('paso.numero', 4)
        ->where('paso.quien', 'Bodega')
        ->where('paso.acciones.0.href', "/app/compras/recepcion/nueva?oc={$orden->id}"));

    $this->actingAs($this->jefe)->post('/app/compras/recepcion', ['orden_id' => $orden->id, 'items' => [
        ['orden_item_id' => $orden->items->first()->id, 'cantidad_recibida' => 90, 'clasificacion' => 'apto'],
    ]])->assertRedirect();

    // Faltan 10, pero bodega ya contó: no se queda esperando lo que nunca llegará.
    ($this->pasoDe)($url, $this->jefe)->assertInertia(fn (Assert $p) => $p
        ->where('paso.numero', 5)
        ->where('paso.acciones.0.accion', 'agregar-gasto')
        ->where('paso.acciones.1.accion', 'liquidar')
        ->where('seguimiento.ordenes.0.recepciones.0.total', 163800));

    GastoImportacion::create(['importacion_id' => $imp->id, 'concepto' => ConceptoGastoImportacion::Flete, 'descripcion' => 'Flete', 'monto' => 1000, 'monto_base' => 1000, 'capitalizable' => true, 'metodo_prorrateo' => 'volumen']);
    ($this->pasoDe)($url, $this->jefe)->assertInertia(fn (Assert $p) => $p->where('paso.acciones.0.accion', 'liquidar'));

    $this->actingAs($this->jefe);
    LiquidarImportacion::run($imp->fresh());
    ($this->pasoDe)($url, $this->jefe)->assertInertia(fn (Assert $p) => $p
        ->where('paso.numero', 6)
        ->where('paso.acciones.0.href', '/app/compras/pagos-exterior'));
});

it('con una declaración sin levante dice eso, no «marque Levante»', function () {
    $orden = ($this->aprobada)();
    ($this->poner)($orden);
    $imp = ($this->contenedorDe)($orden);
    ($this->etapa)($imp, EtapaContenedor::Declaraciones);
    $imp->declaraciones()->create(['numero' => 'D-1', 'selectividad' => 'fisica']);

    ($this->pasoDe)("/app/compras/importacion/{$imp->id}", $this->jefe)->assertInertia(fn (Assert $p) => $p
        ->where('paso.titulo', 'Falta el levante de 1 declaración(es).')
        ->where('paso.acciones.0.accion', 'aduana'));
});

it('un contenedor vacío pide su orden', function () {
    $imp = Importacion::create(['numero' => Importacion::siguienteNumero(), 'moneda_origen' => 'CNY']);

    ($this->pasoDe)("/app/compras/importacion/{$imp->id}", $this->jefe)->assertInertia(fn (Assert $p) => $p
        ->where('paso.numero', 2)
        ->where('paso.acciones.0.accion', 'agregar-orden'));
});

it('desde la orden y la recepción lleva al contenedor', function () {
    $orden = ($this->aprobada)();
    ($this->poner)($orden);
    $imp = ($this->contenedorDe)($orden);

    ($this->pasoDe)("/app/compras/oc/{$orden->id}", $this->jefe)->assertInertia(fn (Assert $p) => $p
        ->where('contenedor.id', $imp->id)
        ->where('paso.numero', 3)
        ->where('paso.acciones.0.etiqueta', 'Ir al contenedor')
        ->where('paso.acciones.0.href', "/app/compras/importacion/{$imp->id}?tab=seguimiento"));

    $this->actingAs($this->jefe)->post('/app/compras/recepcion', ['orden_id' => $orden->id, 'items' => [
        ['orden_item_id' => $orden->items->first()->id, 'cantidad_recibida' => 100, 'clasificacion' => 'apto'],
    ]])->assertRedirect();
    $recepcion = App\Modules\Compras\Models\RecepcionCompra::where('orden_id', $orden->id)->firstOrFail();

    ($this->pasoDe)("/app/compras/recepcion/{$recepcion->id}", $this->jefe)->assertInertia(fn (Assert $p) => $p
        ->where('contenedor.id', $imp->id)
        ->where('paso.numero', 5)
        ->where('paso.acciones.0.href', "/app/compras/importacion/{$imp->id}?tab=costos"));
});

it('no ofrece botones de lo que la persona no puede hacer', function () {
    $orden = ($this->aprobada)();
    ($this->poner)($orden);
    $imp = ($this->contenedorDe)($orden);

    ($this->pasoDe)("/app/compras/importacion/{$imp->id}", $this->bodeguero)->assertInertia(fn (Assert $p) => $p
        ->where('paso.numero', 3)
        ->where('paso.acciones.0.href', "/app/compras/recepcion/nueva?oc={$orden->id}")
        ->count('paso.acciones', 1));
});

it('una orden que no es de importación sigue sin guía', function () {
    $orden = ($this->aprobada)();
    Illuminate\Support\Facades\DB::table('compras_ordenes')->where('id', $orden->id)->update(['tipo' => 'nacional']);

    ($this->pasoDe)("/app/compras/oc/{$orden->id}", $this->jefe)->assertInertia(fn (Assert $p) => $p->where('paso', null));
});

it('lista las etiquetas de la orden, cada producto una vez', function () {
    $orden = ($this->aprobada)();

    ($this->pasoDe)("/app/compras/oc/{$orden->id}", $this->jefe)->assertInertia(fn (Assert $p) => $p
        ->count('lineas_etiqueta', 1)
        ->where('lineas_etiqueta.0.tiene_etiqueta', false)
        ->where('puede_etiqueta', true));
});
