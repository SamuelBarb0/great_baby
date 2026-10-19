<?php

namespace App\Auth;

use App\Models\User;

/**
 * Matriz ÚNICA de autorización · GREAT BABY ERP.
 *
 *   Fix demo A2 · antes cada Filament Resource, cada canAccessPanel(), cada
 *   helper del User (esAracely, esContable, esAlistador, esSac) tenía su
 *   propia lista de roles hard-coded. Consecuencia visible en auditoría:
 *
 *     - Contador no podía entrar al panel (canAccessPanel omitía Contador
 *       aunque esContable() sí lo reconocía)
 *     - SAC veía solo 3 opciones cuando la lógica esperaba 6+
 *     - Vendedor sin acceso ni a comisiones ni a CRM aunque tiene data
 *     - Cambiar el scope de un rol requería tocar 30+ archivos
 *
 *   Ahora una sola matriz declarativa. Cada Resource/Page llama
 *   `Permisos::puede($u, 'facturas')`. Cambio de scope = editar esta clase.
 */
final class Permisos
{
    /**
     * Sección → roles que pueden verla en el sidebar.
     *
     * Aracely y Gerencia son ROOT — no se enumeran aquí, pasan siempre.
     * El resto se declara explícito.
     */
    public const MATRIZ = [
        // OPERACIÓN DIARIA
        'torre_control'    => ['Gerente', 'Alistador', 'Contador', 'ServicioCliente', 'Vendedor'],
        'estacion_empaque' => ['Alistador'],
        'vista_alistador'  => ['Alistador'],
        'escanner_camara'  => ['Alistador'],
        'inventario_vivo'  => ['Gerente', 'Alistador', 'Contador'],

        // DROPI
        'dropi_dashboard'  => ['Gerente'],
        'dropi_pedidos'    => ['Gerente', 'Alistador'],
        'dropi_cortes'     => ['Gerente', 'Contador'],
        'dropi_wallet'     => ['Gerente', 'Contador'],
        'dropi_devoluciones' => ['Gerente', 'Alistador', 'ServicioCliente'],
        'dropi_discrepancias' => ['Gerente', 'Contador'],
        'dropi_reportes'   => ['Gerente', 'Contador'],

        // CARTERA
        'facturas'         => ['Gerente', 'Contador', 'Vendedor'],
        'pagos'            => ['Gerente', 'Contador'],
        'contactos'        => ['Gerente', 'ServicioCliente', 'Vendedor'],
        'cartera_dashboard' => ['Gerente', 'Contador'],
        'condiciones_credito' => ['Gerente', 'Contador'],
        'cobranzas'        => ['Gerente', 'Contador'],
        'excepciones_credito' => ['Gerente', 'Contador'],
        // Maestro de métodos de pago · lo mantenía el área contable en Filament.
        'metodos_pago'     => ['Gerente', 'Contador'],
        'crm'              => ['Gerente', 'ServicioCliente', 'Vendedor'],
        // `comisiones` = correr y aprobar el cierre mensual de comisiones de
        //   TODOS los vendedores. Estaba declarada pero no gateaba nada, y
        //   figuraba Vendedor: al darle significado eso habría dejado a un
        //   vendedor liquidando las comisiones de la empresa. Queda con los
        //   mismos roles que tenía la pantalla en Filament.
        'comisiones'       => ['Gerente', 'Contador'],
        //   La configuración (porcentajes, metas, bonos) es más sensible que
        //   el cierre: define cuánto cobra cada quien. Sólo gerencia, igual
        //   que antes.
        'comisiones_config' => [],

        // CONTABILIDAD
        'contabilidad'     => ['Gerente', 'Contador'],
        'libro_diario'     => ['Gerente', 'Contador'],
        'reportes_contables' => ['Gerente', 'Contador'],

        // COMPRAS
        'compras_oc'       => ['Gerente'],
        'compras_recepcion' => ['Gerente', 'Alistador'],
        'compras_importacion' => ['Gerente'],
        'compras_reportes' => ['Gerente', 'Contador'],

        // INVENTARIO
        'traslados'        => ['Gerente', 'Alistador'],
        'tomas_fisicas'    => ['Gerente', 'Contador', 'Alistador'],
        'alertas_stock'    => ['Gerente', 'Alistador'],
        'kardex'           => ['Gerente', 'Contador', 'Alistador'],
        'stock_bodegas'    => ['Gerente', 'Alistador', 'Contador'],

        // CATÁLOGO · A1 FIX · Contador tiene link en sidebar · darle lectura.
        //   Las acciones destructivas (eliminar, forzar push, duplicar) ya
        //   piden esRoot aparte en ProductosController, así que el Contador
        //   solo puede leer/editar · lo mínimo para su rol fiscal.
        'productos'        => ['Gerente', 'Contador'],
        'ubicaciones'      => ['Gerente'],
        'importar_productos' => ['Gerente'],
        // B3-A5 · llave dedicada para el botón «Sincronizar con SIIGO»
        // (push MANUAL desde ProductoResource). Consume rate limit real y
        // afecta contabilidad en SIIGO, así que la lista es más corta que
        // la del CRUD de productos. Root siempre bypasea.
        'productos.push_siigo' => ['Gerente'],

        // BANDEJAS + IMPORTACIONES
        'bandeja_importaciones' => ['Gerente', 'Contador', 'Alistador', 'ServicioCliente'],

        // SERVICIO Y GARANTÍAS
        'garantias'        => ['Gerente', 'ServicioCliente'],
        'servicio_cliente' => ['Gerente', 'ServicioCliente'],

        // PERFILES OPERATIVOS
        //   Estas secciones no nacieron en la matriz: el acceso de bodega,
        //   facturación y marketing se decidía con `hasRole()` dentro de los
        //   helpers de User. Al declararlas acá, esos perfiles también quedan
        //   bajo el control de la pantalla de Roles en vez de estar fijos en
        //   código. Los roles que figuran son los que ya tenían el acceso.
        'bodega'           => ['AdminBodega'],
        'logistica'        => ['AdminBodega', 'Alistador', 'Despachador'],
        'facturacion'      => ['Facturador'],
        'marketing'        => ['Marketing'],

        // Secciones que no existían y por eso no se podían delegar: el acceso
        //   se decidía con `hasRole('Vendedor')` / `hasRole('Gerente')` escrito
        //   en el controller. Se declaran con EXACTAMENTE los roles que ya
        //   tenían el acceso, así nada cambia y a la vez quedan bajo el control
        //   de la pantalla de Roles.
        'vendedor'         => ['Vendedor'],
        'rrhh'             => ['Gerente', 'GestionHumana'],
        // Gestión Humana, por secciones · un líder de área NO entra a «rrhh»:
        //   ahí se ven salarios y hojas de vida de toda la empresa. Lo único
        //   que necesita es pedir personal y seguir SUS solicitudes, y eso es
        //   un permiso aparte. Lo pidió así Elena en la reunión del 19-oct:
        //   «solamente los jefes o líderes de área pueden hacer la solicitud».
        'rrhh_requisiciones' => ['Gerente', 'GestionHumana', 'LiderArea'],
        'rrhh_seleccion'     => ['Gerente', 'GestionHumana'],
        // La orden de contratación la autoriza la JUNTA DIRECTIVA, no Gestión
        //   Humana ni el líder que pidió el personal: «solo ingresa la empresa
        //   realmente a un contrato si viene autorizado por la junta directiva»
        //   (Elena, 19-oct). Por eso es un permiso aparte y NO lo tiene
        //   GestionHumana: si lo tuviera, ella se autorizaría a sí misma.
        'rrhh_orden_contratacion' => ['Gerente'],
        'mapa_colombia'    => ['AdminBodega', 'Gerente'],
        // La parrilla de contenido la gateaba `esAracely()`, así que el rol
        //   Marketing la veía en el menú y recibía 403. Se deja como permiso
        //   propio con la lista vacía (hoy sólo gerencia, igual que antes),
        //   para que dárselo a Marketing sea marcar una casilla y no un cambio
        //   de código.
        'marketing_parrilla' => [],

        // CONFIGURACIÓN — solo root
        'empresa'          => [],
        'siigo'            => [],
        'usuarios'         => [],
        'plantillas'       => [],
    ];

    /**
     * Roles que la pantalla de administración no deja borrar ni renombrar.
     *
     *   Aracely  · la dueña. Si se puede borrar, un error de configuración deja
     *              el sistema sin nadie que entre a arreglarlo.
     *   Gerencia · el otro acceso root, por si Aracely no está disponible.
     *   AdminBodega · la operación logística (Don Jorge). Sin ese rol la bodega
     *              se detiene, y no es algo que convenga poder borrar por error.
     *
     * Todo lo demás —Vendedor, Contador, Facturador, etc.— el admin lo crea,
     * edita y elimina desde la pantalla de Roles.
     */
    public const ROLES_PROTEGIDOS = ['Aracely', 'Gerencia', 'AdminBodega'];

    /**
     * Cómo se muestra cada sección en la pantalla de Roles: grupo y etiqueta
     * en palabras. Sin esto el admin vería nombres técnicos como
     * `dropi_discrepancias` y tendría que adivinar qué está marcando.
     *
     * Una sección sin entrada acá se muestra con su nombre formateado, así que
     * agregar una sección nueva nunca rompe la pantalla.
     */
    public const GRUPOS = [
        'Operación' => ['torre_control', 'estacion_empaque', 'vista_alistador', 'escanner_camara', 'inventario_vivo'],
        'Dropi' => ['dropi_dashboard', 'dropi_pedidos', 'dropi_cortes', 'dropi_wallet', 'dropi_devoluciones', 'dropi_discrepancias', 'dropi_reportes'],
        'Cartera y CRM' => ['facturas', 'pagos', 'contactos', 'cartera_dashboard', 'condiciones_credito', 'cobranzas', 'excepciones_credito', 'crm', 'comisiones', 'comisiones_config', 'metodos_pago'],
        'Contabilidad' => ['contabilidad', 'libro_diario', 'reportes_contables'],
        'Compras' => ['compras_oc', 'compras_recepcion', 'compras_importacion', 'compras_reportes'],
        'Inventario' => ['traslados', 'tomas_fisicas', 'alertas_stock', 'kardex', 'stock_bodegas'],
        'Catálogo' => ['productos', 'ubicaciones', 'importar_productos', 'productos.push_siigo'],
        'Servicio al cliente' => ['garantias', 'servicio_cliente', 'bandeja_importaciones'],
        'Perfiles operativos' => ['bodega', 'logistica', 'facturacion', 'marketing', 'marketing_parrilla', 'vendedor', 'rrhh', 'rrhh_requisiciones', 'rrhh_seleccion', 'rrhh_orden_contratacion', 'mapa_colombia'],
        'Configuración' => ['empresa', 'siigo', 'usuarios', 'plantillas'],
    ];

    public const ETIQUETAS = [
        'torre_control' => 'Torre de control',
        'estacion_empaque' => 'Estación de empaque',
        'vista_alistador' => 'Vista del alistador',
        'escanner_camara' => 'Escáner con cámara',
        'inventario_vivo' => 'Inventario en vivo',
        'dropi_dashboard' => 'Panel Dropi',
        'dropi_pedidos' => 'Pedidos Dropi',
        'dropi_cortes' => 'Cortes Dropi',
        'dropi_wallet' => 'Billetera Dropi',
        'dropi_devoluciones' => 'Devoluciones Dropi',
        'dropi_discrepancias' => 'Discrepancias Dropi',
        'dropi_reportes' => 'Reportes Dropi',
        'facturas' => 'Facturas de venta',
        'pagos' => 'Pagos recibidos',
        'contactos' => 'Clientes y proveedores',
        'cartera_dashboard' => 'Panel de cartera',
        'condiciones_credito' => 'Condiciones de crédito',
        'cobranzas' => 'Cobranzas',
        'excepciones_credito' => 'Excepciones de crédito',
        'crm' => 'CRM y segmentación',
        'comisiones' => 'Cierre mensual de comisiones',
        'comisiones_config' => 'Configurar comisiones (porcentajes y metas)',
        'metodos_pago' => 'Métodos de pago',
        'contabilidad' => 'Panel contable',
        'libro_diario' => 'Libro diario',
        'reportes_contables' => 'Reportes contables',
        'compras_oc' => 'Órdenes de compra',
        'compras_recepcion' => 'Recepción de mercancía',
        'compras_importacion' => 'Importaciones',
        'compras_reportes' => 'Reportes de compras',
        'traslados' => 'Traslados entre bodegas',
        'tomas_fisicas' => 'Tomas físicas',
        'alertas_stock' => 'Alertas de stock',
        'kardex' => 'Kardex',
        'stock_bodegas' => 'Stock por bodega',
        'productos' => 'Productos',
        'ubicaciones' => 'Ubicaciones y bodegas',
        'importar_productos' => 'Importar productos',
        'productos.push_siigo' => 'Enviar productos a SIIGO',
        'bandeja_importaciones' => 'Bandeja de importaciones',
        'garantias' => 'Garantías',
        'servicio_cliente' => 'Servicio al cliente',
        'bodega' => 'Operar bodega (recepción, traslados, conteos)',
        'logistica' => 'Cola de alistamiento y despacho',
        'facturacion' => 'Bandeja de facturación',
        'marketing' => 'Marketing y préstamos de producto',
        'vendedor' => 'Panel del vendedor y armar pedidos en terreno',
        'rrhh' => 'Gestión humana (vacantes y empleados)',
        'rrhh_requisiciones' => 'Solicitar personal y seguir mis solicitudes',
        'rrhh_seleccion' => 'Proceso de selección (candidatos, entrevistas, terna)',
        'rrhh_orden_contratacion' => 'Autorizar contrataciones (Junta Directiva)',
        'mapa_colombia' => 'Mapa de Colombia',
        'marketing_parrilla' => 'Parrilla de contenido',
        'empresa' => 'Datos de la empresa',
        'siigo' => 'Integración SIIGO',
        'usuarios' => 'Usuarios',
        'plantillas' => 'Plantillas de documentos',
    ];

    /**
     * Nombre con que cada sección vive en la tabla `permissions` de Spatie.
     * Prefijamos para no chocar con permisos de otros paquetes.
     */
    public static function permiso(string $seccion): string
    {
        return 'ver.'.$seccion;
    }

    /** Etiqueta legible de una sección, con respaldo para secciones nuevas. */
    public static function etiqueta(string $seccion): string
    {
        return self::ETIQUETAS[$seccion] ?? ucfirst(str_replace(['_', '.'], ' ', $seccion));
    }

    /**
     * Catálogo para la pantalla de Roles: grupos con sus permisos y etiquetas.
     * Las secciones que no estén en GRUPOS caen en "Otros" para que nunca
     * queden invisibles.
     *
     * @return array<string, list<array{seccion:string, permiso:string, etiqueta:string}>>
     */
    public static function catalogo(): array
    {
        $agrupadas = [];
        foreach (self::GRUPOS as $grupo => $secciones) {
            foreach ($secciones as $s) {
                $agrupadas[$grupo][] = [
                    'seccion' => $s,
                    'permiso' => self::permiso($s),
                    'etiqueta' => self::etiqueta($s),
                ];
            }
        }

        $yaListadas = array_merge(...array_values(self::GRUPOS));
        foreach (array_keys(self::MATRIZ) as $s) {
            if (! in_array($s, $yaListadas, true)) {
                $agrupadas['Otros'][] = [
                    'seccion' => $s,
                    'permiso' => self::permiso($s),
                    'etiqueta' => self::etiqueta($s),
                ];
            }
        }

        return $agrupadas;
    }

    /**
     * ¿El user tiene acceso a esta sección?
     *
     * Desde que existe la pantalla de Roles, la autoridad es la tabla
     * `permissions`: lo que el admin marca ahí es lo que manda. La MATRIZ de
     * abajo quedó como semilla (de dónde salieron los permisos la primera vez)
     * y como respaldo para una instalación que todavía no los sembró — sin eso,
     * un deploy sin seeder dejaría a todo el mundo sin acceso.
     *
     * Aracely y Gerencia son root y pasan siempre: si el admin se equivoca
     * marcando casillas, alguien tiene que poder entrar a arreglarlo.
     */
    public static function puede(?User $u, string $seccion): bool
    {
        if (! $u) return false;
        if (self::esRoot($u)) return true;

        if (self::permisosSembrados()) {
            return $u->can(self::permiso($seccion));
        }

        return $u->hasAnyRole(self::MATRIZ[$seccion] ?? []);
    }

    /**
     * ¿Ya se sembraron los permisos? Se consulta una vez por request.
     * Si la tabla no existe todavía (instalación a medio migrar) devolvemos
     * false y la autorización cae a la matriz en código.
     */
    private static function permisosSembrados(): bool
    {
        static $sembrados = null;
        if ($sembrados !== null) {
            return $sembrados;
        }

        try {
            $sembrados = \Spatie\Permission\Models\Permission::query()
                ->where('name', 'like', 'ver.%')->exists();
        } catch (\Throwable) {
            $sembrados = false;
        }

        return $sembrados;
    }

    /**
     * ¿Es root? (Aracely dueña o Gerencia general)
     */
    public static function esRoot(?User $u): bool
    {
        return $u && $u->hasAnyRole(['Aracely', 'Gerencia']);
    }

    /**
     * Secciones que efectivamente abren algo en `/admin`.
     *
     * `/admin` quedó siendo **sólo Dropi**: el ERP se maneja completo en Vue
     * (`/app`). Antes Filament tenía 40 pantallas que duplicaban lo que ya
     * existe en Vue, cada una con su propia autorización, y por eso un mismo
     * módulo se comportaba distinto según por dónde entraras.
     *
     * Así que la puerta del panel la abre tener algo de Dropi, más las dos
     * secciones con que se autorizan sus pantallas de operación (el alistador
     * y las devoluciones que atiende servicio al cliente).
     */
    public const SECCIONES_PANEL = [
        'dropi_dashboard', 'dropi_pedidos', 'dropi_cortes', 'dropi_wallet',
        'dropi_devoluciones', 'dropi_discrepancias', 'dropi_reportes',
        'vista_alistador', 'servicio_cliente',
    ];

    /**
     * ¿Puede entrar al panel Filament?
     *
     * Antes era una lista de nombres de rol escrita acá, así que un rol creado
     * en `/app/roles` NUNCA podía entrar a `/admin` por más casillas que se le
     * marcaran: la pantalla de roles prometía algo que no cumplía.
     *
     * Ahora la puerta la abre tener al menos una sección que el panel muestre,
     * y adentro cada Resource sigue pidiendo su propio permiso.
     *
     * Desde que `/admin` es sólo Dropi, el Vendedor ya no entra: no tiene nada
     * que hacer ahí, su trabajo entero vive en `/app/vendedor`.
     */
    public static function puedeAccederPanel(?User $u): bool
    {
        if (! $u) return false;
        if (self::esRoot($u)) return true;

        foreach (self::SECCIONES_PANEL as $seccion) {
            if (self::puede($u, $seccion)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lista de secciones que este user puede ver. Útil para armar menús.
     */
    public static function seccionesDe(?User $u): array
    {
        if (! $u) return [];
        return array_keys(array_filter(
            self::MATRIZ,
            fn ($_, $seccion) => self::puede($u, $seccion),
            ARRAY_FILTER_USE_BOTH
        ));
    }
}
