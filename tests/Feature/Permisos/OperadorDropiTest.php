<?php

/**
 * El rol «Operador Dropi» vive en la pantalla de Roles, no en el código.
 *
 * Estas pruebas sujetan las cuatro cosas que se rompían al abrirle
 * Contabilidad y Compras: que aterrice en su panel, que Conexión Dropi deje de
 * depender de `esAracely()`, que la auditoría que se abre desde Reportes lo
 * acepte, y que aprobar gastos sea una casilla propia —no un efecto lateral de
 * ver contabilidad—.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Contabilidad\Actions\Gastos;
use App\Modules\Dropi\Filament\Pages\CambiosFinancierosDropi;
use App\Modules\Dropi\Filament\Pages\ConexionDropi;
use App\Modules\Dropi\Filament\Pages\EscannerCamara;
use App\Modules\Dropi\Filament\Pages\EstacionEmpaque;
use App\Modules\Dropi\Filament\Pages\ImportarPedidos;
use App\Modules\Dropi\Filament\Pages\InventarioEnVivo;
use App\Modules\Dropi\Filament\Pages\MisProductosDropi;
use App\Modules\Dropi\Filament\Pages\ProductosDeDropi;
use App\Modules\Dropi\Filament\Pages\SiigoDropi;
use App\Modules\Dropi\Filament\Pages\TorreControl;
use App\Modules\Dropi\Filament\Pages\VistaAlistador;
use App\Modules\Dropi\Filament\Resources\DropiDevolucionResource\Pages\EditDropiDevolucion;
use App\Modules\Dropi\Filament\Resources\DropiPedidoResource\Pages\ListDropiPedidos;
use App\Modules\Dropi\Models\DropiDevolucion;
use App\Modules\Dropi\Models\DropiPedido;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Las pantallas del panel que antes preguntaban por un nombre de rol. */
const PANTALLAS_CON_CASILLA = [
    TorreControl::class => 'torre_control',
    EstacionEmpaque::class => 'estacion_empaque',
    VistaAlistador::class => 'vista_alistador',
    EscannerCamara::class => 'escanner_camara',
    InventarioEnVivo::class => 'inventario_vivo',
    MisProductosDropi::class => 'dropi_mis_productos',
    ProductosDeDropi::class => 'dropi_vincular_productos',
];

beforeEach(function () {
    $this->seed(\Database\Seeders\PermisosSeeder::class);

    $this->rol = Role::firstOrCreate(['name' => 'Operador Dropi', 'guard_name' => 'web']);
    $this->rol->syncPermissions([
        'ver.dropi_dashboard', 'ver.dropi_pedidos', 'ver.dropi_cortes', 'ver.dropi_wallet',
        'ver.dropi_devoluciones', 'ver.dropi_discrepancias', 'ver.dropi_reportes',
        'ver.contabilidad', 'ver.libro_diario', 'ver.reportes_contables',
        'ver.cuentas_por_pagar', 'ver.programacion_pagos', 'ver.pagos',
        'ver.compras_oc', 'ver.compras_recepcion', 'ver.compras_importacion', 'ver.compras_reportes',
        'ver.contabilidad_aprobar_gastos', 'ver.dropi_conexion', 'editar.dropi_conexion',
        'ver.dropi_siigo', 'editar.dropi_siigo',
        'ver.dropi_importar_pedidos', 'editar.dropi_importar_pedidos',
    ]);

    $this->operador = User::factory()->create();
    $this->operador->assignRole($this->rol);
});

it('aterriza en el panel de Dropi aunque tenga casillas de compras', function () {
    expect($this->operador->rutaInicial())->toBe('/admin');
});

it('entra por su panel aunque sea colaborador y tenga Operación marcada', function () {
    // Así queda un operador que llega por Gestión Humana (colaborador) y al
    //   que le marcaron «Operación» completa (Vista del alistador incluida).
    $this->rol->givePermissionTo(['ver.portal_colaborador', 'ver.vista_alistador']);

    expect($this->operador->fresh()->rutaInicial())->toBe('/admin');
});

it('el alistador de verdad sigue entrando a su cola de bodega', function () {
    Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']);
    $this->seed(\Database\Seeders\PermisosSeeder::class);

    $alistador = User::factory()->create();
    $alistador->assignRole('Alistador');

    expect($alistador->rutaInicial())->toBe('/app/logistica/cola-jorge');
});

it('al entrar arranca en Modo DROPI si su pantalla es el panel', function () {
    $this->post('/app/login', ['email' => $this->operador->email, 'password' => 'password'])
        ->assertRedirect('/admin');
    expect(session('gb_canal'))->toBe('dropi');

    auth()->logout();

    Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']);
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    $alistador = User::factory()->create();
    $alistador->assignRole('Alistador');

    $this->post('/app/login', ['email' => $alistador->email, 'password' => 'password'])
        ->assertRedirect('/app/logistica/cola-jorge');
    expect(session('gb_canal'))->toBe('empresa');
});

it('desde el login de Inertia el panel se abre como página, no encima del login', function () {
    // El formulario manda `X-Inertia`. Un redirect común a /admin (que no es
    //   Inertia) el navegador lo dibujaba en una ventana gris sobre el login;
    //   la respuesta tiene que ser «visita completa» (409 + X-Inertia-Location).
    $r = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post('/app/login', ['email' => $this->operador->email, 'password' => 'password']);

    $r->assertStatus(409);
    expect($r->headers->get('X-Inertia-Location'))->toEndWith('/admin');
});

it('entra a Conexión Dropi y puede cambiarla', function () {
    $this->actingAs($this->operador);

    expect(ConexionDropi::canAccess())->toBeTrue()
        ->and(Permisos::puedeAccion($this->operador, 'dropi_conexion', 'editar'))->toBeTrue();
});

it('completa Configuración: SIIGO de Dropi e Importar pedidos', function () {
    $this->actingAs($this->operador);

    expect(SiigoDropi::canAccess())->toBeTrue()
        ->and(ImportarPedidos::canAccess())->toBeTrue()
        ->and(Permisos::puedeAccion($this->operador, 'dropi_siigo', 'editar'))->toBeTrue()
        ->and(Permisos::puedeAccion($this->operador, 'dropi_importar_pedidos', 'editar'))->toBeTrue();
});

it('un rol con solo ver no puede guardar la resolución de Dropi', function () {
    $this->actingAs($this->operador);
    expect(Permisos::puedeAccion($this->operador, 'dropi_siigo', 'editar'))->toBeTrue();

    $soloMira = User::factory()->create();
    $soloMira->givePermissionTo('ver.dropi_siigo');

    expect(Permisos::puedeAccion($soloMira, 'dropi_siigo', 'editar'))->toBeFalse();
});

it('ve la auditoría financiera porque llega desde Reportes Dropi', function () {
    $this->actingAs($this->operador);

    expect(CambiosFinancierosDropi::canAccess())->toBeTrue();
});

it('aprueba gastos con su casilla, sin abrirle la firma al contador', function () {
    expect(Gastos::puedeAprobar($this->operador))->toBeTrue();

    $contador = Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']);
    $contador->givePermissionTo('ver.contabilidad');

    $quien = User::factory()->create();
    $quien->assignRole($contador);

    expect(Gastos::puedeAprobar($quien))->toBeFalse();
});

it('sin sus casillas no ve Operación ni Productos del panel', function () {
    $this->actingAs($this->operador);

    foreach (array_keys(PANTALLAS_CON_CASILLA) as $pantalla) {
        expect($pantalla::canAccess())->toBeFalse("{$pantalla} se abrió sin su casilla");
    }
});

it('marcando las casillas abre Operación y Productos del panel', function () {
    $this->rol->givePermissionTo([
        ...array_map(fn ($s) => "ver.{$s}", array_values(PANTALLAS_CON_CASILLA)),
        'editar.dropi_vincular_productos',
    ]);
    $this->actingAs($this->operador->fresh());

    foreach (array_keys(PANTALLAS_CON_CASILLA) as $pantalla) {
        expect($pantalla::canAccess())->toBeTrue("{$pantalla} no se abrió con su casilla");
    }
    expect(Permisos::puedeAccion($this->operador->fresh(), 'dropi_vincular_productos', 'editar'))->toBeTrue();
});

it('con solo ver en Vincular mira las llaves pero no vincula', function () {
    $soloMira = User::factory()->create();
    $soloMira->givePermissionTo('ver.dropi_vincular_productos');
    $this->actingAs($soloMira);

    expect(ProductosDeDropi::canAccess())->toBeTrue()
        ->and(Permisos::puedeAccion($soloMira, 'dropi_vincular_productos', 'editar'))->toBeFalse();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    \Livewire\Livewire::test(ProductosDeDropi::class)
        ->assertActionHidden('vincularSeguros')
        ->assertActionVisible('leer');
});

it('el Alistador sigue viendo exactamente lo que veía', function () {
    Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']);
    $this->seed(\Database\Seeders\PermisosSeeder::class);

    $alistador = User::factory()->create();
    $alistador->assignRole('Alistador');
    $this->actingAs($alistador);

    // Lo que le daban los nombres de rol antes del cambio.
    expect(TorreControl::canAccess())->toBeTrue()
        ->and(EstacionEmpaque::canAccess())->toBeTrue()
        ->and(VistaAlistador::canAccess())->toBeTrue()
        ->and(EscannerCamara::canAccess())->toBeTrue()
        ->and(InventarioEnVivo::canAccess())->toBeTrue()
        ->and(MisProductosDropi::canAccess())->toBeFalse()
        ->and(ProductosDeDropi::canAccess())->toBeFalse();
});

it('ninguna pantalla del panel depende de un nombre de rol', function () {
    // Un rol con TODAS las casillas tiene que abrir lo mismo que root. Si una
    //   pantalla vuelve a preguntar `esAracely()` o `hasAnyRole()`, esto falla.
    $todas = Role::firstOrCreate(['name' => 'Todas las casillas', 'guard_name' => 'web']);
    $todas->syncPermissions(Permission::all());
    $conTodas = User::factory()->create();
    $conTodas->assignRole($todas);

    $root = User::factory()->create();
    $root->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);

    $abre = function (User $u) use ($panel) {
        $this->actingAs($u);

        return collect([...$panel->getPages(), ...$panel->getResources()])
            ->filter(fn ($cls) => $cls::canAccess())
            ->values()
            ->all();
    };

    $deRoot = $abre($root);
    expect($deRoot)->not->toBeEmpty()
        ->and(array_values(array_diff($deRoot, $abre($conTodas))))->toBe([]);
});

it('Productos ofrece la carga del ERP a Dropi en vez de las plantillas de Dropi', function () {
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);

    // Las plantillas «Carga masiva» / «Actualización masiva» de Dropi no
    // aplican los cambios (18-nov-2026): el menú lleva a la carga del ERP.
    $cargaMasiva = fn () => collect($panel->getNavigationItems())
        ->first(fn ($item) => $item->getLabel() === 'Cargar productos y stock a Dropi');

    expect($cargaMasiva())->not->toBeNull()
        ->and($cargaMasiva()->getUrl())->toBe('/app/dropi/carga-productos')
        ->and($cargaMasiva()->getGroup())->toBe('Productos')
        ->and(collect($panel->getNavigationItems())->contains(fn ($i) => str_starts_with((string) $i->getUrl(), 'https://app.dropi.co/dashboard/products')))->toBeFalse()
        ->and(collect($panel->getPages())->contains(fn ($p) => str_ends_with($p, '\ImportarProductos')))->toBeFalse();

    // La ve quien puede cambiar productos en Dropi, el mismo permiso de la pantalla.
    $this->actingAs($this->operador);
    expect($cargaMasiva()->isVisible())->toBeFalse();

    $this->rol->givePermissionTo('ver.dropi_productos');
    $this->actingAs($this->operador->fresh());
    expect($cargaMasiva()->isVisible())->toBeTrue();
});

/** Un pedido que vino de Dropi (con orden), que es cuando aparece su menú. */
function pedidoDeDropiParaElOperador(): DropiPedido
{
    return DropiPedido::create([
        'corte_id' => \App\Modules\Dropi\Actions\AsignarCorteACarga::run(\Carbon\CarbonImmutable::now())->id,
        'guia' => 'GOPER1', 'dropi_orden_id' => '880001',
        'cliente_nombre' => 'Ana', 'monto_esperado_proveedor' => 1000,
    ]);
}

it('el menú Dropi del pedido pide su casilla, no el nombre de Aracely', function () {
    $pedido = pedidoDeDropiParaElOperador();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->operador);

    \Livewire\Livewire::test(ListDropiPedidos::class)
        ->assertTableActionHidden('infoDropi', $pedido)
        ->assertTableActionHidden('actualizarEstatusDropi', $pedido)
        ->assertTableActionHidden('etiquetasDropi', $pedido);

    $this->rol->givePermissionTo('ver.dropi_cambiar_en_dropi');
    $this->actingAs($this->operador->fresh());

    // Con la casilla, la lista abre en «Pendiente» (27-nov-2026); este pedido no tiene estatus de Dropi.
    \Livewire\Livewire::test(ListDropiPedidos::class)
        ->set('activeTab', 'todos')
        ->assertTableActionVisible('infoDropi', $pedido)
        ->assertTableActionVisible('actualizarEstatusDropi', $pedido)
        ->assertTableActionVisible('etiquetasDropi', $pedido);
});

it('el destino de una devolución pide su casilla aparte de «Editar»', function () {
    // Con orden de Dropi o sin ella da igual; la guía distinta evita chocar
    //   con el pedido de la otra prueba si algún día comparten datos.
    $pedido = DropiPedido::create([
        'corte_id' => \App\Modules\Dropi\Actions\AsignarCorteACarga::run(\Carbon\CarbonImmutable::now())->id,
        'guia' => 'GOPER2', 'cliente_nombre' => 'Ana', 'monto_esperado_proveedor' => 1000,
    ]);
    // Sin eventos: crearla dispara la contabilización y la nota crédito, que
    //   no son lo que se prueba acá.
    $devolucion = DropiDevolucion::withoutEvents(fn () => DropiDevolucion::create([
        'pedido_id' => $pedido->id, 'recibido_at' => now(),
        'destino_inventario' => 'reingreso', 'decision_por' => $this->operador->id,
    ]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // «Editar» solo deja corregir las notas: el destino sigue bloqueado (SEG-C3).
    $this->rol->givePermissionTo('editar.dropi_devoluciones');
    $this->actingAs($this->operador->fresh());
    \Livewire\Livewire::test(EditDropiDevolucion::class, ['record' => $devolucion->getRouteKey()])
        ->assertFormFieldDisabled('destino_inventario');

    $this->rol->givePermissionTo('ver.dropi_devoluciones_destino');
    $this->actingAs($this->operador->fresh());
    \Livewire\Livewire::test(EditDropiDevolucion::class, ['record' => $devolucion->getRouteKey()])
        ->assertFormFieldEnabled('destino_inventario');
});
