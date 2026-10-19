<?php

namespace App\Auth;

use App\Models\User;

/**
 * Qué puede abrir cada persona en el menú de `/app`.
 *
 * El sidebar de `AppLayout.vue` decidía con listas de nombres de rol escritas
 * en el propio Vue (`roles: [...ROL_SUPER, 'Contador']`). Eso traía dos
 * problemas, los dos verificados en pantalla:
 *
 *   · **Enlaces muertos.** El menú mostraba cosas que el controller no deja
 *     abrir. La Contadora veía «CRM · Segmentación» y recibía 403; el rol
 *     Marketing veía «Parrilla de contenido» y también 403.
 *   · **La pantalla de Roles no mandaba.** Marcarle una casilla a un rol nuevo
 *     no le mostraba nada, porque el menú preguntaba por el NOMBRE del rol.
 *
 * Acá cada clave del menú se resuelve con **la misma expresión que usa el
 * controller de esa ruta**. Si alguna vez se cambia un gate, se cambia también
 * acá y no hay forma de que el menú y la pantalla digan cosas distintas: es un
 * solo lugar, y la prueba `MenuCoincideConAccesoTest` compara ítem por ítem
 * contra el HTTP real de los 11 roles.
 */
final class MenuApp
{
    /**
     * Clave de menú → cómo se autoriza.
     *
     * El comentario de cada línea dice de qué controller salió la expresión,
     * para poder volver a verificarla sin adivinar.
     *
     * @return array<string, bool>
     */
    public static function permisos(?User $u): array
    {
        if (! $u) {
            return [];
        }

        $root = Permisos::esRoot($u);
        $puede = fn (string $s) => Permisos::puede($u, $s);

        return [
            // CosasDelDiaController · esEquipoBodega()
            'cosas_dia' => $puede('bodega'),
            // MapaColombiaController · puede('mapa_colombia')
            'mapa' => $puede('mapa_colombia'),
            // DashboardController · redirige a su inicio si no es root ni Gerente
            'torre_control' => $root || $u->hasRole('Gerente'),
            // EstacionEmpaqueController · esAracely() || Alistador|AdminBodega
            'estacion_empaque' => $root || $puede('vista_alistador') || $puede('bodega'),

            // VendedorPedidoController · puede('vendedor')
            'vendedor' => $puede('vendedor'),

            // ColaJorgeController · esAracely()||esAdminBodega()||esAlistador()||Despachador
            'cola_alistamiento' => $root || $puede('bodega') || $puede('vista_alistador') || $puede('logistica'),
            // PedidosB2BController · esEquipoBodega() || Despachador
            'pedidos_b2b' => $puede('bodega') || $u->hasRole('Despachador'),

            // FacturacionController · esAracely() || esFacturador()
            'facturacion' => $puede('facturacion'),

            // Cartera entera (dashboard, facturas, pagos, crédito, notas, etc.) · esContable()
            'cartera' => $puede('contabilidad'),
            // CrmController · esAracely() — no se delega: sólo gerencia.
            'crm' => $root,
            // ComisionesController · puede('comisiones') · el cierre mensual
            'comisiones' => $puede('comisiones'),
            // MetodosPagoController · puede('metodos_pago')
            'metodos_pago' => $puede('metodos_pago'),

            // GarantiasController · esAracely() || ServicioCliente|Gerente
            'garantias' => $puede('garantias'),

            // MarketingPrestamosController · rol Marketing
            'marketing' => $puede('marketing'),
            // MarketingController (parrilla) · puede('marketing_parrilla')
            'marketing_parrilla' => $puede('marketing_parrilla'),

            // RrhhController · puede('rrhh')
            'rrhh' => $puede('rrhh'),
            // RequisicionesController · el líder de área entra ACÁ y a nada
            //   más de Gestión Humana: pide personal y sigue lo suyo.
            'rrhh_requisiciones' => $puede('rrhh_requisiciones'),
            // SeleccionController / ContratacionController · el proceso de
            //   selección y la contratación son de Gestión Humana.
            'rrhh_seleccion' => $puede('rrhh_seleccion'),
            // OrdenContratacionController · lo autoriza la Junta Directiva.
            'rrhh_orden_contratacion' => $puede('rrhh_orden_contratacion'),
            // La PANTALLA de órdenes la abren los dos lados, igual que su
            //   middleware: Gestión Humana pide, la Junta decide.
            'rrhh_ordenes' => $puede('rrhh') || $puede('rrhh_orden_contratacion'),
            // IndicadoresRrhhController · los números de toda la empresa, para
            //   Gestión Humana y para la Junta. El líder de área no entra:
            //   esto no es de su equipo.
            'rrhh_indicadores' => $puede('rrhh') || $puede('rrhh_orden_contratacion'),

            // ComprasController (listado de OC) · esContable()
            'compras_lista' => $puede('contabilidad'),
            // ComprasGestionController (nueva OC, importación, reporte, recepción) · esEquipoBodega()
            'compras_gestion' => $puede('bodega'),
            // DevolucionProveedorController · esEquipoBodega() || Contador
            'devoluciones_proveedor' => $puede('bodega') || $u->hasRole('Contador'),

            // InventarioController / InventarioUbicacionesController · esEquipoBodega()
            'bodega' => $puede('bodega'),
            // InventarioGestionController (kardex, conteos, traslados, alertas,
            //   reporte de stock) · equipo bodega + alistador
            'bodega_operativa' => $puede('bodega') || $puede('vista_alistador'),

            // ProductosController / JerarquiaSiigoController · puede('productos')
            'catalogo' => $puede('productos'),

            // Contabilidad completa · esContable()
            'contabilidad' => $puede('contabilidad'),

            // GastosController · sólo pide sesión: cualquiera registra un gasto
            //   propio y `esContable()` decide adentro quién aprueba.
            'gastos' => true,

            // BandejaImportacionesController · esAracely()
            'bandeja_importaciones' => $root,

            // Roles, Reglas, Empresa, Plantillas · esAracely()
            'configuracion' => $root,
            // SiigoController · esAracely() || Gerente
            'siigo' => $root || $u->hasRole('Gerente'),

            // El módulo Dropi se opera desde /admin, que ahora es sólo Dropi.
            'dropi' => Permisos::puedeAccederPanel($u),
        ];
    }

    /** Sólo las claves que la persona puede ver, para compartir menos datos. */
    public static function clavesDe(?User $u): array
    {
        return array_keys(array_filter(self::permisos($u)));
    }
}
