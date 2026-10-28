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
/*
 * OJO · este mapa es una copia A MANO del menú de `AppLayout.vue`.
 *
 * Cuando una entrada del menú cambia de clave hay que cambiarla también acá, o
 * la prueba compara contra un menú que ya no existe. Pasó al cablear las
 * casillas de /app/roles: cuatro items pasaron a declarar su propia clave y
 * esta copia siguió diciendo la vieja.
 */
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
    /*
     * Gestión Humana entero. Estaban sólo vacantes y empleados, así que las
     * otras ocho entradas del grupo no las cubría nadie —y es el grupo con más
     * permisos distintos: el líder de área ve dos cosas, la Junta ve otra, el
     * colaborador ve una sola, y Gestión Humana ve el resto.
     */
    '/app/rrhh/requisiciones' => 'rrhh_requisiciones',
    '/app/rrhh/mi-equipo' => 'rrhh_requisiciones',
    '/app/rrhh/vacantes' => 'rrhh',
    '/app/rrhh/ordenes' => 'rrhh_ordenes',
    '/app/rrhh/contrataciones' => 'rrhh',
    '/app/rrhh/empleados' => 'rrhh',
    '/app/rrhh/asignaciones' => 'rrhh_asignaciones',
    '/app/rrhh/novedades' => 'rrhh_novedades',
    '/app/rrhh/indicadores' => 'rrhh_indicadores',
    // Las tres que nacieron después y nadie agregó acá: justo las que
    // manejan plata y separación de funciones.
    '/app/rrhh/nomina' => 'rrhh_nomina',
    '/app/rrhh/tesoreria' => 'rrhh_tesoreria',
    '/app/rrhh/formatos' => 'rrhh',
    '/app/mi-espacio' => 'portal_colaborador',
    '/app/compras' => 'compras_lista',
    '/app/compras/oc/nueva' => 'compras_gestion',
    '/app/compras/importacion' => 'compras_importacion',
    '/app/compras/devoluciones' => 'devoluciones_proveedor',
    '/app/compras/reporte' => 'compras_reportes',
    '/app/compras/recepcion/nueva' => 'compras_recepcion',
    '/app/inventario' => 'bodega',
    '/app/inventario/kardex' => 'bodega_operativa',
    '/app/inventario/conteos' => 'bodega_operativa',
    '/app/inventario/traslados' => 'bodega_operativa',
    '/app/inventario/alertas' => 'bodega_operativa',
    '/app/inventario/ubicaciones' => 'ubicaciones',
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

/*
 * Todos los roles del ERP.
 *
 * `GestionHumana`, `LiderArea` y `Colaborador` faltaban: son los tres que
 * inventó el módulo de Gestión Humana, y sin ellos esta prueba ejercitaba las
 * doce entradas del grupo con roles que no las tienen. O sea: pasaba sin
 * probar nada de lo que vino a proteger.
 */
const ROLES_ERP = [
    'Aracely', 'Gerencia', 'Gerente', 'Contador', 'Vendedor', 'AdminBodega',
    'Alistador', 'Despachador', 'ServicioCliente', 'Marketing', 'Facturador',
    'GestionHumana', 'LiderArea', 'Colaborador',
];

beforeEach(function () {
    $this->seed(\Database\Seeders\UsuariosDemoSeeder::class);
    $this->seed(\Database\Seeders\PermisosSeeder::class);
});

it('no ofrece en el menú nada que responda 403', function () {
    $rotos = [];

    foreach (ROLES_ERP as $rol) {
        $u = User::whereHas('roles', fn ($q) => $q->where('name', $rol))->first();

        /*
         * Un rol sin usuario demo se saltaba EN SILENCIO, así que la prueba
         * pasaba sin haberlo probado y nadie se enteraba. Si falta el usuario,
         * la prueba se rompe: es la única forma de que la cobertura que esta
         * prueba promete sea cierta.
         */
        expect($u)->not->toBeNull("El rol {$rol} no tiene usuario demo: sin él esta prueba no lo cubre.");

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

        /*
         * Un rol sin usuario demo se saltaba EN SILENCIO, así que la prueba
         * pasaba sin haberlo probado y nadie se enteraba. Si falta el usuario,
         * la prueba se rompe: es la única forma de que la cobertura que esta
         * prueba promete sea cierta.
         */
        expect($u)->not->toBeNull("El rol {$rol} no tiene usuario demo: sin él esta prueba no lo cubre.");

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
