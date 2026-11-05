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
        // La estacion de empaque la abre `Alistador` o `AdminBodega`: el admin
        //   de bodega empaca cuando falta gente, y la puerta ya lo dejaba.
        'vista_alistador'  => ['Alistador', 'AdminBodega'],
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
        // TESORERÍA · lo que se construyó para reemplazar los Excel de caja.
        //   Secciones propias y no colgadas de `contabilidad` para que Aracely
        //   pueda, por ejemplo, dejar que una auxiliar cargue el arqueo diario
        //   sin abrirle el libro contable entero.
        'cajas'            => ['Gerente', 'Contador'],
        'arqueo_caja'      => ['Gerente', 'Contador'],
        'flujo_caja'       => ['Gerente', 'Contador'],
        'cuentas_por_pagar' => ['Gerente', 'Contador'],
        'programacion_pagos' => ['Gerente', 'Contador'],
        'reportes_contables' => ['Gerente', 'Contador'],
        /*
         * Las reglas CONTABLES, y solo esas.
         *
         * Reglas de negocio es de administracion y asi se queda: ahi viven
         * 140 reglas en 11 grupos —SIIGO, RRHH, Dropi, CRM— y abrirle la
         * pantalla entera al contador le daria mucho mas que cuentas.
         *
         * Pero las 45 de 'contable.*' son suyas: dicen a que cuenta postea
         * cada transaccion, y hoy tres de ellas apuntan a cuentas que el
         * plan prohibe mover (6135, 1435, 1305). Silvia sabe cual es la
         * correcta y no podia escribirla: tenia que dictarsela a Aracely.
         *
         * Esta clave abre solo ese grupo. El filtro va en el servidor, no
         * en la pantalla.
         */
        'reglas_contables' => ['Contador'],

        // COMPRAS
        // `Compras` es el rol de Alejandro y Liz. No recibe `compras_recepcion`: contar
        //   y subir el stock es el aval de Jorge, y eso abriría la pantalla de contar.
        'compras_oc'       => ['Gerente', 'Compras'],
        // Recibir mercancia lo hace bodega, y el Contador la consulta. Los dos
        //   entraban por `esEquipoBodega() || esContable()`; si no quedan acá,
        //   el dia que la casilla mande pierden la pantalla.
        'compras_recepcion' => ['Gerente', 'Alistador', 'AdminBodega', 'Contador'],
        'compras_importacion' => ['Gerente', 'AdminBodega', 'Contador', 'Compras'],
        'compras_reportes' => ['Gerente', 'Contador', 'AdminBodega', 'Compras'],
        // No va con importaciones: esa casilla la tiene bodega, y a Jorge no se le
        //   puede frenar la pantalla con el aviso de un pago vencido a China.
        'compras_pagos_exterior' => ['Gerente', 'Contador', 'Compras'],

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
        'productos'        => ['Gerente', 'Contador', 'Compras'],
        // Jorge administra sus bodegas: la pantalla ya se le abria.
        'ubicaciones'      => ['Gerente', 'AdminBodega'],
        // Entraba por `puede('productos')`, que incluye al Contador.
        'importar_productos' => ['Gerente', 'Contador'],
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
        // El cuadro de novedades que se le manda al contador cada mes. Va
        //   aparte porque es lo único de Gestión Humana que alguien de
        //   contabilidad podría necesitar abrir sin ver hojas de vida —hoy no
        //   lo tiene, pero el día que haga falta se reparte desde la pantalla
        //   de roles en vez de tocar código.
        'rrhh_novedades'     => ['Gerente', 'GestionHumana'],
        // La orden de contratación la autoriza la JUNTA DIRECTIVA, no Gestión
        //   Humana ni el líder que pidió el personal: «solo ingresa la empresa
        //   realmente a un contrato si viene autorizado por la junta directiva»
        //   (Elena, 19-oct). Por eso es un permiso aparte y NO lo tiene
        //   GestionHumana: si lo tuviera, ella se autorizaría a sí misma.
        'rrhh_orden_contratacion' => ['Gerente'],
        /*
         * La nómina es permiso aparte porque es la pantalla donde está el
         * sueldo de TODO el mundo. Quien lleva requisiciones o selección no
         * tiene por qué ver cuánto gana el de al lado.
         *
         * Contabilidad entra porque le toca pagarla; por eso ve la pantalla
         * aunque no sea de Gestión Humana.
         */
        'rrhh_nomina'        => ['Gerente', 'GestionHumana', 'Contador'],
        /*
         * Aprobar es de gerencia y de nadie más, por la misma razón que la
         * orden de contratación: si Gestión Humana liquidara y aprobara, se
         * estaría aprobando a sí misma. Son tres manos a propósito —liquida
         * Gestión Humana, aprueba gerencia, paga tesorería— y un solo botón
         * de «listo» haría que quien lo apriete firme por las tres.
         */
        'rrhh_nomina_aprobar' => ['Gerente'],
        // El portal del propio empleado: sus datos, sus documentos, sus
        //   herramientas y sus avisos. Es lo ÚNICO que abre el rol
        //   `Colaborador`, y nadie más lo necesita —cada quien ve lo suyo
        //   porque el controlador lo ata a su `user_id`, no porque tenga
        //   este permiso—.
        'portal_colaborador' => ['Colaborador'],
        'mapa_colombia'    => ['AdminBodega', 'Gerente'],
        // La parrilla de contenido la gateaba `esAracely()`, así que el rol
        //   Marketing la veía en el menú y recibía 403. Es permiso propio, y de
        //   Marketing por defecto: la parrilla es su trabajo diario, y dependía
        //   de que alguien marcara la casilla en cada instalación.
        'marketing_parrilla' => ['Marketing'],
        // Ver y bajar los creativos que Marketing comparte: los vendedores
        //   (mayorista) los usan con sus clientes. Dropship es Aracely, root.
        'marketing_material' => ['Vendedor', 'Marketing'],
        // Tablero de lanzamiento por contenedor: lo trabajan Marketing (Anny),
        //   Compras (gerente) y Bodega; Dropshipping es Aracely, root.
        'marketing_lanzamientos' => ['Marketing', 'Gerente', 'AdminBodega'],

        // CONFIGURACIÓN — solo root
        'empresa'          => [],
        // El Gerente monitorea SIIGO desde antes; sin nombrarlo aca lo perderia
        //   el dia que la casilla mande (Aracely/Gerencia pasan por ser root).
        'siigo'            => ['Gerente'],
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
        'Contabilidad' => ['contabilidad', 'libro_diario', 'reportes_contables', 'reglas_contables'],
        'Tesorería y caja' => ['cajas', 'arqueo_caja', 'flujo_caja', 'cuentas_por_pagar', 'programacion_pagos'],
        'Compras' => ['compras_oc', 'compras_recepcion', 'compras_importacion', 'compras_pagos_exterior', 'compras_reportes'],
        'Inventario' => ['traslados', 'tomas_fisicas', 'alertas_stock', 'kardex', 'stock_bodegas'],
        'Catálogo' => ['productos', 'ubicaciones', 'importar_productos', 'productos.push_siigo'],
        'Servicio al cliente' => ['garantias', 'servicio_cliente', 'bandeja_importaciones'],
        'Perfiles operativos' => ['bodega', 'logistica', 'facturacion', 'marketing', 'marketing_parrilla', 'marketing_material', 'marketing_lanzamientos', 'vendedor', 'rrhh', 'rrhh_requisiciones', 'rrhh_seleccion', 'rrhh_novedades', 'rrhh_orden_contratacion', 'rrhh_nomina', 'rrhh_nomina_aprobar', 'portal_colaborador', 'mapa_colombia'],
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
        'cajas' => 'Cajas y bancos',
        'arqueo_caja' => 'Arqueo diario de caja',
        'flujo_caja' => 'Flujo de caja',
        'cuentas_por_pagar' => 'Cuentas por pagar',
        'programacion_pagos' => 'Programación de pagos',
        'reportes_contables' => 'Reportes contables',
        'compras_oc' => 'Órdenes de compra',
        'compras_recepcion' => 'Recepción de mercancía',
        'compras_importacion' => 'Importaciones',
        'compras_pagos_exterior' => 'Pagos al exterior',
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
        'rrhh_novedades' => 'Cuadro de novedades (incapacidades, permisos, horas extra)',
        'rrhh_orden_contratacion' => 'Autorizar contrataciones (Junta Directiva)',
        'rrhh_nomina' => 'Nómina (liquidar y consultar lo que gana cada quien)',
        'rrhh_nomina_aprobar' => 'Aprobar la nómina del mes (gerencia)',
        'portal_colaborador' => 'Mi espacio (portal del empleado)',
        'mapa_colombia' => 'Mapa de Colombia',
        'marketing_parrilla' => 'Parrilla de contenido',
        'marketing_material' => 'Material de marketing (ver y descargar creativos)',
        'marketing_lanzamientos' => 'Tablero de lanzamiento (por contenedor)',
        'empresa' => 'Datos de la empresa',
        'siigo' => 'Integración SIIGO',
        'usuarios' => 'Usuarios',
        'plantillas' => 'Plantillas de documentos',
    ];

    /**
     * Nombre con que cada sección vive en la tabla `permissions` de Spatie.
     * Prefijamos para no chocar con permisos de otros paquetes.
     */
    /**
     * Las cuatro acciones que se pueden dar por separado sobre una sección.
     *
     * Hasta acá una sección era UN permiso: quien podía verla podía hacerle
     * todo. Eso obligaba a elegir entre dar acceso completo o no dar nada, y
     * en la práctica se daba completo — el auxiliar que sólo necesitaba
     * consultar la cartera terminaba pudiendo borrar una factura.
     *
     * `ver` es la puerta: sin ella las demás no sirven de nada, y así se
     * valida. Marcar «editar» sin «ver» sería una casilla que no hace nada.
     */
    public const ACCIONES = [
        'ver' => 'Ver',
        'crear' => 'Crear',
        'editar' => 'Editar',
        'eliminar' => 'Eliminar',
    ];

    /**
     * El nombre del permiso de una sección.
     *
     * Sin segundo argumento devuelve el de VER, que es como lo llaman las
     * decenas de sitios escritos antes de que existieran las acciones. Así
     * nada de lo viejo cambia de significado.
     */
    public static function permiso(string $seccion, string $accion = 'ver'): string
    {
        return $accion.'.'.$seccion;
    }

    /** Los cuatro permisos de una sección, para sembrarlos juntos. */
    public static function permisosDe(string $seccion): array
    {
        return array_map(
            fn (string $a) => self::permiso($seccion, $a),
            array_keys(self::ACCIONES),
        );
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
                $agrupadas[$grupo][] = self::filaDelCatalogo($s);
            }
        }

        $yaListadas = array_merge(...array_values(self::GRUPOS));
        foreach (array_keys(self::MATRIZ) as $s) {
            if (! in_array($s, $yaListadas, true)) {
                $agrupadas['Otros'][] = self::filaDelCatalogo($s);
            }
        }

        return $agrupadas;
    }

    /**
     * Una sección con sus cuatro casillas.
     *
     * `permiso` se conserva —es el de ver— porque la pantalla vieja y varios
     * sitios lo leen por ese nombre. `acciones` es lo nuevo: el nombre del
     * permiso de cada acción, para pintar la grilla.
     */
    private static function filaDelCatalogo(string $seccion): array
    {
        return [
            'seccion' => $seccion,
            'permiso' => self::permiso($seccion),
            'etiqueta' => self::etiqueta($seccion),
            'acciones' => collect(self::ACCIONES)
                ->map(fn ($texto, $accion) => [
                    'accion' => $accion,
                    'texto' => $texto,
                    'permiso' => self::permiso($seccion, $accion),
                ])->values()->all(),
        ];
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
     * ¿Puede HACER algo en esta sección, no sólo verla?
     *
     * `ver` es la puerta: quien no puede ver tampoco puede crear, editar ni
     * borrar, aunque alguien le haya marcado esas casillas. Se valida acá y
     * no en la pantalla porque una casilla mal puesta no debería abrir nada.
     *
     * Mientras los permisos no estén sembrados cae al respaldo por rol, igual
     * que `puede()`: en una instalación a medio migrar es preferible el
     * comportamiento viejo a cerrarle la puerta a todo el mundo.
     */
    public static function puedeAccion(?User $u, string $seccion, string $accion): bool
    {
        if (! $u) return false;
        if (self::esRoot($u)) return true;
        if (! array_key_exists($accion, self::ACCIONES)) return false;

        if (! self::permisosSembrados()) {
            return $u->hasAnyRole(self::MATRIZ[$seccion] ?? []);
        }

        if (! $u->can(self::permiso($seccion))) {
            return false;
        }

        return $accion === 'ver' || $u->can(self::permiso($seccion, $accion));
    }

    /** Atajo para lo que más se pregunta: ¿puede cambiar algo acá? */
    public static function puedeEditar(?User $u, string $seccion): bool
    {
        return self::puedeAccion($u, $seccion, 'editar');
    }

    /**
     * ¿Ya se sembraron los permisos? Se consulta una vez por request.
     *
     * Si la tabla no existe todavía (instalación a medio migrar) devolvemos
     * false y la autorización cae a la matriz en código.
     *
     * La respuesta se guarda en el CONTENEDOR, no en un `static`. Un `static`
     * dura todo el proceso, y eso no es «una vez por request» donde el proceso
     * atiende más de una: en Octane, y sobre todo en la suite, donde los mil y
     * pico tests comparten proceso. Ahí el primer test que sembraba permisos
     * dejaba la bandera en `true` para todos los siguientes, y los archivos
     * que montan su usuario con sólo un rol —sin permisos— se caían en bloque
     * según el ORDEN alfabético de las carpetas. El contenedor se rearma por
     * request y por test, que es la vida que le corresponde a esta respuesta.
     *
     * En un worker de colas el contenedor también vive todo el proceso, y ahí
     * está bien: la respuesta sólo cambia cuando corre el seeder, y eso pasa
     * en un despliegue, que reinicia los workers de todos modos.
     */
    private static function permisosSembrados(): bool
    {
        $llave = 'permisos.sembrados';

        if (app()->bound($llave)) {
            return app($llave);
        }

        try {
            $sembrados = \Spatie\Permission\Models\Permission::query()
                ->where('name', 'like', 'ver.%')->exists();
        } catch (\Throwable) {
            $sembrados = false;
        }

        app()->instance($llave, $sembrados);

        return $sembrados;
    }

    /**
     * ¿Es root? (Aracely dueña o Gerencia general)
     */
    /**
     * ¿Tiene alguna de estas secciones?
     *
     * Para los controladores que atienden varias pantallas con casillas
     * distintas: la puerta de clase deja entrar si tiene cualquiera, y cada
     * método exige después la suya. Sin esto habría que elegir una sola clave
     * por controlador, y las otras volverían a quedar huérfanas — que es
     * justo lo que estamos arreglando.
     */
    public static function tieneAlguno(?User $u, array $secciones): bool
    {
        foreach ($secciones as $seccion) {
            if (self::puede($u, $seccion)) {
                return true;
            }
        }

        return false;
    }

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
