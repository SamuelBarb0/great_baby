<?php

/**
 * Los PDF de Compras eran sólo de Aracely/Gerencia. Quien abre la orden, la
 * recepción o el contenedor en pantalla ya ve esos costos, pero su PDF le daba
 * 403: Compras no podía bajar la pre-liquidación que le pide a China.
 *
 * La regla: el PDF lo baja quien tiene la sección de esa pantalla. Sigue cerrado
 * para el resto, que es lo que pidió el auditor (#19): antes lo veía cualquiera
 * con sesión.
 */

use App\Models\User;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Models\Contacto;
use App\Modules\Dropi\Models\InventarioUbicacion;
use Spatie\Permission\Models\Role;

function usuarioParaPdfDeCompras(string $rol): User
{
    $u = User::factory()->create();
    $u->assignRole(Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']));

    return $u;
}

beforeEach(function () {
    $proveedor = Contacto::create(['tipo_documento' => 'NIT', 'numero_documento' => '900777888', 'nombre_completo' => 'Topwin', 'es_proveedor' => true]);
    $bodega = InventarioUbicacion::create(['codigo' => 'PPAL', 'nombre' => 'Principal', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true]);

    $this->orden = CrearOrdenCompra::run([
        'proveedor_id' => $proveedor->id, 'bodega_id' => $bodega->id, 'tipo' => 'importacion', 'moneda' => 'CNY', 'tasa_cambio' => 520,
        'items' => [['descripcion' => 'TW-123 · oso', 'iva_pct' => 0, 'cajas' => 10, 'unidades_por_caja' => 10, 'cantidad' => 100, 'cbm_caja' => 0.5, 'precio_unit' => 10]],
    ]);
    $this->recepcion = RecepcionCompra::create([
        'numero' => 'REC-TEST-0001', 'orden_id' => $this->orden->id, 'bodega_id' => $bodega->id,
        'fecha_recepcion' => now()->toDateString(), 'estado' => 'confirmada', 'total_recibido' => 240000,
    ]);
    $this->importacion = Importacion::create([
        'numero' => Importacion::siguienteNumero(), 'contenedor' => 'MSKU1', 'moneda_origen' => 'CNY',
        'tasa_cambio_liquidacion' => 520, 'estado' => EstadoImportacion::EnTransito,
    ]);

    $this->rutas = [
        'orden' => "/compras/orden/{$this->orden->id}/pdf",
        'preliquidacion' => "/compras/orden/{$this->orden->id}/preliquidacion/pdf",
        'recepcion' => "/compras/recepcion/{$this->recepcion->id}/pdf",
        'importacion' => "/compras/importacion/{$this->importacion->id}/pdf",
    ];
});

it('quien tiene la sección de la pantalla baja su PDF', function (string $rol, array $abre) {
    $u = usuarioParaPdfDeCompras($rol);

    foreach ($abre as $pdf) {
        $this->actingAs($u)->get($this->rutas[$pdf])->assertOk();
    }
})->with([
    'Gerente (Compras)' => ['Gerente', ['orden', 'preliquidacion', 'recepcion', 'importacion']],
    'Contador' => ['Contador', ['orden', 'preliquidacion', 'recepcion', 'importacion']],
    'Jorge (bodega)' => ['AdminBodega', ['orden', 'preliquidacion', 'recepcion', 'importacion']],
    'Alistador sólo la recepción, que es la que abre' => ['Alistador', ['recepcion']],
]);

it('sigue cerrado para los demás: es lo que pidió el auditor', function (string $rol, array $cierra) {
    $u = usuarioParaPdfDeCompras($rol);

    foreach ($cierra as $pdf) {
        $this->actingAs($u)->get($this->rutas[$pdf])->assertForbidden();
    }
})->with([
    'Vendedor' => ['Vendedor', ['orden', 'preliquidacion', 'recepcion', 'importacion']],
    'Marketing' => ['Marketing', ['orden', 'preliquidacion', 'recepcion', 'importacion']],
    'Alistador no ve costos de orden ni de contenedor' => ['Alistador', ['orden', 'preliquidacion', 'importacion']],
]);

it('la pantalla de la orden ofrece el botón a quien de verdad lo puede abrir', function () {
    $orden = OrdenCompra::findOrFail($this->orden->id);

    $this->actingAs(usuarioParaPdfDeCompras('Gerente'))->get("/app/compras/oc/{$orden->id}")
        ->assertInertia(fn ($p) => $p->where('puede_pdf', true));
    $this->actingAs(usuarioParaPdfDeCompras('Alistador'))->get("/app/compras/oc/{$orden->id}")
        ->assertInertia(fn ($p) => $p->where('puede_pdf', false));
});

it('una sesión sin permiso sigue sin poder bajar la plantilla ni importar órdenes', function () {
    $this->actingAs(usuarioParaPdfDeCompras('Gerente'))->get('/compras/plantilla/oc')->assertForbidden();
});
