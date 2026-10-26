<?php

/**
 * El menú de `/app` no puede ofrecer lo que después responde 403.
 *
 * El sidebar decidía qué mostrar con listas de nombres de rol escritas en
 * `AppLayout.vue`, aparte de los gates de los controllers. Las dos listas se
 * fueron separando y quedaron enlaces muertos: la Contadora veía «CRM» y el
 * rol Marketing veía «Parrilla de contenido», y los dos recibían 403 al entrar.
 *
 * Desde que el menú se arma con `App\Auth\MenuApp`, la clave de cada ítem se
 * resuelve con la misma expresión que su controller. Esta prueba lo mantiene
 * así: recorre todos los ítems con todos los roles y falla si aparece
 *
 *   · un ítem ofrecido que no abre, o
 *   · una pantalla que la persona puede abrir y el menú le esconde.
 *
 * El mapa de abajo es el espejo del `groups` de AppLayout.vue. Si se agrega una
 * entrada al menú hay que agregarla acá; si no, no queda cubierta.
 */

use App\Auth\MenuApp;
use App\Models\User;

/** href del ítem => clave de permiso que declara en AppLayout.vue */
const MENU_APP = [
    '/app/cosas-del-dia' => 'cosas_dia',
    '/app/mapa-colombia' => 'mapa',
    '/app' => 'torre_control',
    '/app/estacion-empaque' => 'estacion_empaque',
    '/app/vendedor' => 'vendedor',
    '/app/vendedor/ventas-por-cliente' => 'vendedor',
    '/app/vendedor/contado-credito' => 'vendedor',
    '/app/vendedor/seguimiento' => 'vendedor',
    '/app/logistica/cola-jorge' => 'cola_alistamiento',
    '/app/pedidos-b2b' => 'pedidos_b2b',
    '/app/facturacion/bandeja' => 'facturacion',
    '/app/cartera' => 'cartera',
    '/app/facturas' => 'cartera',
    '/app/contactos' => 'cartera',
    '/app/pagos' => 'cartera',
    '/app/credito' => 'cartera',
    '/app/crm' => 'crm',
    '/app/cartera/reportes' => 'cartera',
    '/app/cartera/cobranzas' => 'cartera',
    '/app/cartera/solicitudes' => 'cartera',
    '/app/cartera/movimientos' => 'cartera',
    '/app/cartera/retenciones' => 'cartera',
    '/app/cartera/notas-credito' => 'cartera',
    '/app/cartera/notas-debito' => 'cartera',
    '/app/cartera/pagos-proveedor' => 'cartera',
    '/app/cartera/comisiones' => 'comisiones',
    '/app/cartera/metodos-pago' => 'metodos_pago',
    '/app/garantias' => 'garantias',
    '/app/garantias/nueva' => 'garantias',
    '/app/marketing' => 'marketing',
    '/app/marketing/parrilla' => 'marketing_parrilla',
    '/app/marketing/tablero' => 'marketing_tablero',
    '/app/marketing/creativos' => 'marketing_material',
    '/app/rrhh/vacantes' => 'rrhh',
    '/app/rrhh/empleados' => 'rrhh',
    '/app/compras' => 'compras_lista',
    '/app/compras/oc/nueva' => 'compras_gestion',
    '/app/compras/importacion' => 'compras_gestion',
    '/app/compras/devoluciones' => 'devoluciones_proveedor',
    '/app/compras/reporte' => 'compras_gestion',
    '/app/compras/recepcion/nueva' => 'compras_gestion',
    '/app/inventario' => 'bodega',
    '/app/inventario/kardex' => 'bodega_operativa',
    '/app/inventario/conteos' => 'bodega_operativa',
    '/app/inventario/traslados' => 'bodega_operativa',
    '/app/inventario/alertas' => 'bodega_operativa',
    '/app/inventario/ubicaciones' => 'bodega',
    '/app/inventario/reporte-stock' => 'bodega_operativa',
    '/app/inventario/importar-cliente' => 'bodega',
    '/app/catalogo/productos' => 'catalogo',
    '/app/catalogo/jerarquia-siigo' => 'catalogo',
    '/app/contabilidad' => 'contabilidad',
    '/app/contabilidad/panel' => 'contabilidad',
    '/app/contabilidad/plan-cuentas' => 'contabilidad',
    '/app/contabilidad/reportes' => 'contabilidad',
    '/app/contabilidad/reporte-detalle' => 'contabilidad',
    '/app/contabilidad/asientos-manuales' => 'contabilidad',
    '/app/contabilidad/pendientes-siigo' => 'contabilidad',
    '/app/contabilidad/discrepancias-siigo' => 'contabilidad',
    '/app/contabilidad/validacion-puc-siigo' => 'contabilidad',
    '/app/gastos' => 'gastos',
    '/app/bandeja-importaciones' => 'bandeja_importaciones',
    '/app/roles' => 'configuracion',
    '/app/reglas' => 'configuracion',
    '/app/empresa' => 'configuracion',
    '/app/plantillas' => 'configuracion',
    '/app/siigo' => 'siigo',
];

const ROLES_ERP = [
    'Aracely', 'Gerencia', 'Gerente', 'Contador', 'Vendedor', 'AdminBodega',
    'Alistador', 'Despachador', 'ServicioCliente', 'Marketing', 'Facturador',
];

beforeEach(function () {
    $this->seed(\Database\Seeders\UsuariosDemoSeeder::class);
    $this->seed(\Database\Seeders\PermisosSeeder::class);
});

it('no ofrece en el menú nada que responda 403', function () {
    $rotos = [];

    foreach (ROLES_ERP as $rol) {
        $u = User::whereHas('roles', fn ($q) => $q->where('name', $rol))->first();
        if (! $u) {
            continue;
        }

        $claves = MenuApp::clavesDe($u);

        foreach (MENU_APP as $href => $clave) {
            if (! in_array($clave, $claves, true)) {
                continue;
            }

            $status = $this->actingAs($u)->get($href)->getStatusCode();

            // `/app` manda a cada quien a su pantalla de inicio: un 302 ahí es
            // lo esperado, no un enlace roto.
            $ok = $status === 200 || ($href === '/app' && $status === 302);

            if (! $ok) {
                $rotos[] = "{$rol} ve {$href} y recibe {$status}";
            }
        }
    }

    expect($rotos)->toBe([]);
});

it('no esconde en el menú pantallas que la persona sí puede abrir', function () {
    $ocultos = [];

    foreach (ROLES_ERP as $rol) {
        $u = User::whereHas('roles', fn ($q) => $q->where('name', $rol))->first();
        if (! $u) {
            continue;
        }

        $claves = MenuApp::clavesDe($u);

        foreach (MENU_APP as $href => $clave) {
            if (in_array($clave, $claves, true)) {
                continue;
            }

            if ($this->actingAs($u)->get($href)->getStatusCode() === 200) {
                $ocultos[] = "{$rol} puede abrir {$href} y el menú no se lo muestra";
            }
        }
    }

    expect($ocultos)->toBe([]);
});

it('cada item del menú declara una clave que MenuApp conoce', function () {
    $conocidas = array_keys(MenuApp::permisos(User::first()));
    $desconocidas = array_values(array_diff(array_unique(array_values(MENU_APP)), $conocidas));

    expect($desconocidas)->toBe([]);
});
