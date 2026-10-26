<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import {
    LayoutDashboard, Package, ShoppingCart, Warehouse, Calculator,
    FileText, Users, Truck, Settings, Bell, Search, LogOut,
    Menu as MenuIcon, X, ChevronDown, Home, BarChart3, MessageSquare,
    Boxes, Camera, Scissors, Wallet, MapPin, AlertTriangle, Radio, Upload,
    Undo2, ScanLine, Tag, Layers, CreditCard, LayoutGrid, Images,
} from 'lucide-vue-next';
import CommandPalette from '@/Components/CommandPalette.vue';
import CopilotoChat from '@/Components/CopilotoChat.vue';
import NotificacionesBell from '@/Components/NotificacionesBell.vue';
import SemaforoSiigo from '@/Components/SemaforoSiigo.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
// C-QA-D-8: badge de pedidos B2B pendientes por revisar
const pedidosB2BPend = computed(() => Number(page.props.badges?.pedidos_b2b_pendientes || 0));

// Sidebar: persistente por usuario. Colapsable manualmente en desktop y móvil.
// Se recuerda el estado en localStorage.
const readSidebar = () => {
    if (typeof window === 'undefined') return true;
    const stored = localStorage.getItem('gb.sidebar.open');
    if (stored !== null) return stored === '1';
    return window.innerWidth >= 768; // default: abierto en desktop, cerrado en mobile
};
const sidebarOpen = ref(readSidebar());

// Persistir preferencia
const setSidebar = (v) => {
    sidebarOpen.value = v;
    try { localStorage.setItem('gb.sidebar.open', v ? '1' : '0'); } catch {}
};
const toggleSidebar = () => setSidebar(! sidebarOpen.value);

// Al pasar de mobile a desktop, restaurar preferencia
useEventListener(typeof window !== 'undefined' ? window : null, 'resize', () => {
    // Solo forzar cierre si venimos de desktop→mobile
    if (window.innerWidth < 768 && sidebarOpen.value) {
        const stored = localStorage.getItem('gb.sidebar.open');
        if (stored === null) sidebarOpen.value = false; // primera vez móvil = cerrado
    }
});

// Cerrar con ESC en mobile
useEventListener(typeof window !== 'undefined' ? window : null, 'keydown', (e) => {
    if (e.key === 'Escape' && sidebarOpen.value && window.innerWidth < 768) setSidebar(false);
});

// Toast global de errores con COLA (evita pisar mensajes en cascada).
import { useSonido } from '@/composables/useSonido';
const { beep: beepErr } = useSonido();
const toasts = ref([]);
let toastSeq = 0;
let ultimoBeepAt = 0; // throttle 1500ms — evita cascada auditiva en bodega
useEventListener(typeof window !== 'undefined' ? window : null, 'gb:error', (e) => {
    const id = ++toastSeq;
    toasts.value.push({ id, mensaje: e.detail?.mensaje || 'Error' });
    const ahora = Date.now();
    if (ahora - ultimoBeepAt > 1500) {
        beepErr('error');
        ultimoBeepAt = ahora;
    }
    setTimeout(() => {
        toasts.value = toasts.value.filter(t => t.id !== id);
    }, 6000);
});
const cerrarToast = (id) => { toasts.value = toasts.value.filter(t => t.id !== id); };

/**
 * Avisos que manda el servidor (`->with('success'|'warning'|'info')`).
 *
 * Ya viajaban en `flash` desde HandleInertiaRequests y nadie los pintaba acá:
 * 21 controllers confirmaban "guardado" y el usuario no veía nada, salvo en
 * las pantallas que se habían hecho su propio banner. Al escucharlos en el
 * layout, cualquier pantalla queda cubierta sin tocarla una por una.
 */
const TONOS = {
    success: { clase: 'bg-emerald-600', icono: '✓' },
    warning: { clase: 'bg-amber-600', icono: '!' },
    info: { clase: 'bg-brand-600', icono: 'i' },
    error: { clase: 'bg-red-600', icono: '⚠' },
};

const mostrarToast = (mensaje, tono = 'success') => {
    const id = ++toastSeq;
    toasts.value.push({ id, mensaje, tono });
    // Los avisos buenos se van solos; los de error y advertencia duran más
    // porque suelen pedir que la persona haga algo.
    setTimeout(() => {
        toasts.value = toasts.value.filter(t => t.id !== id);
    }, tono === 'success' ? 4000 : 8000);
};

/*
 * Avisos que manda cualquier pantalla con `avisar()` (composables/useAviso.js).
 *
 * Es el reemplazo del `alert()` nativo, que dentro del iframe de la app de
 * escritorio no aparece: la persona apretaba «subir imagen», fallaba, y no
 * veía nada. Acá reusa la misma cola de toasts que los avisos del servidor.
 */
useEventListener(typeof window !== 'undefined' ? window : null, 'gb:aviso', (e) => {
    mostrarToast(e.detail?.mensaje || '', e.detail?.tono || 'info');
});

watch(
    () => page.props.flash,
    (flash) => {
        if (! flash) return;
        ['success', 'warning', 'info', 'error'].forEach((tono) => {
            if (flash[tono]) mostrarToast(flash[tono], tono);
        });
    },
    { immediate: true, deep: true },
);
/**
 * Qué grupos del menú arrancan abiertos.
 *
 * Antes era una lista fija de cuatro (`operacion, ventas, logistica, bodega`),
 * así que la Contadora entraba y veía dos títulos grises, y el Facturador no
 * veía su único ítem. Ahora abren todos los que el rol puede ver y la elección
 * se recuerda, porque cada quien usa dos o tres y los demás estorban.
 */
const CLAVE_GRUPOS = 'gb.menu.grupos';

const openGroups = ref((() => {
    try {
        const guardado = JSON.parse(localStorage.getItem(CLAVE_GRUPOS) || 'null');
        if (guardado && typeof guardado === 'object') return guardado;
    } catch { /* sin localStorage (modo privado): abrimos todo */ }
    return {};
})());

/*
 * El menú se arma con los PERMISOS que manda el servidor, no con nombres de rol.
 *
 * Antes cada grupo e ítem llevaba su propia lista (`roles: [...]`) escrita acá.
 * Eso hacía dos cosas malas, las dos comprobadas en pantalla: mostraba enlaces
 * que el controller no deja abrir (la Contadora veía «CRM» y recibía 403; el
 * rol Marketing veía «Parrilla de contenido» y también), y volvía decorativa la
 * pantalla de Roles, porque marcar una casilla no cambiaba el menú.
 *
 * Ahora cada entrada declara `permiso: '<clave>'` y la clave la resuelve
 * `App\Auth\MenuApp` en PHP con la MISMA expresión que usa el controller de esa
 * ruta. Si el menú lo ofrece, se puede abrir.
 */
const groups = [
    {
        key: 'operacion',
        label: 'Operación',
        items: [
            { name: '🌅 Cosas del día', href: '/app/cosas-del-dia', icon: BarChart3, permiso: 'cosas_dia' },
            { name: '🗺 Mapa Colombia', href: '/app/mapa-colombia', icon: BarChart3, permiso: 'mapa' },
            { name: 'Torre de Control', href: '/app', icon: BarChart3, permiso: 'torre_control' },
            { name: 'Estación de Empaque', href: '/app/estacion-empaque', icon: Package, permiso: 'estacion_empaque' },
        ],
    },
    {
        // LOG-J1 + Miracle port · Mi gestión comercial del vendedor.
        //   Replica la estructura del panel vendedor de Miracle:
        //     Mi Panel · Ventas por Cliente · Contado/Crédito · Seguimiento
        //   más el acceso al armador de pedido en terreno.
        key: 'ventas',
        label: 'Mi gestión comercial',
        items: [
            { name: '📊 Mi Panel', href: '/app/vendedor', icon: BarChart3, permiso: 'vendedor' },
            { name: '👥 Ventas por Cliente', href: '/app/vendedor/ventas-por-cliente', icon: Users, permiso: 'vendedor' },
            { name: '💳 Contado / Crédito', href: '/app/vendedor/contado-credito', icon: Calculator, permiso: 'vendedor' },
            { name: '📋 Seguimiento', href: '/app/vendedor/seguimiento', icon: FileText, permiso: 'vendedor' },
        ],
    },
    {
        // Logística unificada para TODAS las bodegas (hay 7+ sedes, no una sola).
        key: 'logistica',
        label: 'Logística',
        items: [
            { name: '📦 Cola de alistamiento', href: '/app/logistica/cola-jorge', icon: Package, permiso: 'cola_alistamiento' },
            { name: 'Pedidos B2B', href: '/app/pedidos-b2b', icon: Package, badge: 'pedidosB2BPend', permiso: 'pedidos_b2b' },
        ],
    },
    {
        // Rol Facturador · segregación de funciones con Contador.
        //   Entre el pedido alistado y la factura SIIGO hay una revisión manual
        //   del Facturador: valida email/NIT/items y decide send_dian/send_mail.
        key: 'facturacion',
        label: 'Facturación',
        items: [
            { name: '🧾 Bandeja facturación', href: '/app/facturacion/bandeja', icon: FileText, permiso: 'facturacion' },
        ],
    },
    {
        // Cartera y CRM: dashboard de deuda, facturas, pagos, cobranzas, CRM.
        //   NO es para el Vendedor en terreno · él solo arma pedidos y mira
        //   su propio panel comercial, no gestiona la cartera de la empresa.
        key: 'cartera',
        label: 'Cartera y CRM',
        items: [
            { name: 'Dashboard cartera', href: '/app/cartera', icon: BarChart3, permiso: 'cartera' },
            { name: 'Facturas de venta', href: '/app/facturas', icon: FileText, permiso: 'cartera' },
            { name: 'Contactos', href: '/app/contactos', icon: Users, permiso: 'cartera' },
            { name: 'Pagos', href: '/app/pagos', icon: Calculator, permiso: 'cartera' },
            { name: 'Crédito y cobranza', href: '/app/credito', icon: Calculator, permiso: 'cartera' },
            // CRM lo gatea `esAracely()` en su controller: sólo gerencia.
            { name: 'CRM · Segmentación', href: '/app/crm', icon: Bell, permiso: 'crm' },
            { name: 'Cartera · Reportes', href: '/app/cartera/reportes', icon: BarChart3, permiso: 'cartera' },
            { name: 'Cobranzas registros', href: '/app/cartera/cobranzas', icon: MessageSquare, permiso: 'cartera' },
            { name: 'Solicitudes crédito', href: '/app/cartera/solicitudes', icon: FileText, permiso: 'cartera' },
            { name: 'Movimientos contables', href: '/app/cartera/movimientos', icon: Calculator, permiso: 'cartera' },
            // Sprint 4 · B.3 · reglas retención tributaria
            { name: 'Retenciones (Retefuente/Reteica/Reteiva)', href: '/app/cartera/retenciones', icon: Calculator, permiso: 'cartera' },
            // Sprint 4 · B.1 · NC manuales
            { name: 'Notas crédito', href: '/app/cartera/notas-credito', icon: FileText, permiso: 'cartera' },
            // Sprint 4 · B.2 · ND manuales
            { name: 'Notas débito', href: '/app/cartera/notas-debito', icon: FileText, permiso: 'cartera' },
            // A3 FIX #7 · 'Asientos manuales' movido al grupo Contabilidad (abajo).
            // Sprint 4 · B.3+ · Pagos a proveedor con retenciones
            { name: 'Pagos a proveedor', href: '/app/cartera/pagos-proveedor', icon: FileText, permiso: 'cartera' },
            // Estas dos sólo existían en el panel Filament, que quedó para Dropi.
            { name: 'Comisiones de vendedores', href: '/app/cartera/comisiones', icon: Calculator, permiso: 'comisiones' },
            { name: 'Métodos de pago', href: '/app/cartera/metodos-pago', icon: CreditCard, permiso: 'metodos_pago' },
        ],
    },
    {
        // Servicio al cliente a secas: quien atiende el teléfono no tiene por
        // qué ver la parrilla de marketing en su mismo grupo.
        key: 'servicio',
        label: 'Servicio al cliente',
        items: [
            { name: 'Garantías · tickets', href: '/app/garantias', icon: Bell, permiso: 'garantias' },
            { name: 'Nueva garantía', href: '/app/garantias/nueva', icon: Bell, permiso: 'garantias' },
        ],
    },
    {
        // LOG-J9 · Panel propio del rol Marketing: préstamos de productos
        //   para fotos/videos y su propio almacén. Permisos reducidos.
        // Un solo Marketing. Antes había dos grupos ("Servicio y Marketing" y
        // "Marketing · Préstamos") y había que adivinar en cuál estaba cada cosa.
        key: 'marketing',
        label: 'Marketing',
        items: [
            { name: '🎬 Mi panel marketing', href: '/app/marketing', icon: Package, permiso: 'marketing' },
            // La parrilla la gatea `esAracely()`: el rol Marketing la veía en el
            // menú y recibía 403 al entrar. Ahora sólo la ve quien puede abrirla.
            { name: 'Parrilla de contenido', href: '/app/marketing/parrilla', icon: BarChart3, permiso: 'marketing_parrilla' },
            // MKT-4 · dónde va cada producto, de la compra a la publicación.
            { name: 'Tablero de productos', href: '/app/marketing/tablero', icon: LayoutGrid, permiso: 'marketing_tablero' },
            // MKT-2 · lo ven también los vendedores: es el material para vender.
            { name: 'Creativos', href: '/app/marketing/creativos', icon: Images, permiso: 'marketing_material' },
            // A1 FIX #7 · el CTA "Nuevo préstamo" caía en 403 porque el gate
            //   de InventarioGestionController no acepta rol Marketing. Lo
            //   quitamos y Marketing crea su préstamo desde su propio panel.
        ],
    },
    {
        key: 'rrhh',
        label: 'Gestión Humana',
        items: [
            // Primero la solicitud: es por donde arranca el proceso, y para el
            // líder de área es lo ÚNICO que ve de este grupo.
            { name: 'Solicitudes de personal', href: '/app/rrhh/requisiciones', icon: Users, permiso: 'rrhh_requisiciones' },
            // El jefe de área ve SU gente acá: período de prueba y poco más.
            //   No entra a «Empleados», que lleva los salarios de la empresa.
            { name: 'Mi equipo', href: '/app/rrhh/mi-equipo', icon: Users, permiso: 'rrhh_requisiciones' },
            { name: 'Vacantes', href: '/app/rrhh/vacantes', icon: Users, permiso: 'rrhh' },
            // La autorización de la Junta Directiva. La ven los dos lados:
            //   quien la pide (Gestión Humana) y quien decide (la Junta).
            { name: '✓ Órdenes de contratación', href: '/app/rrhh/ordenes', icon: Users, permiso: 'rrhh_ordenes' },
            { name: 'Contrataciones', href: '/app/rrhh/contrataciones', icon: Users, permiso: 'rrhh' },
            { name: 'Empleados', href: '/app/rrhh/empleados', icon: Users, permiso: 'rrhh' },
            { name: 'Dotación y herramientas', href: '/app/rrhh/asignaciones', icon: Package, permiso: 'rrhh_asignaciones' },
            // Lo que Elena lleva a la Junta Directiva.
            { name: '📊 Indicadores', href: '/app/rrhh/indicadores', icon: BarChart3, permiso: 'rrhh_indicadores' },
            // El espacio del propio empleado. Es lo ÚNICO que ve un
            //   Colaborador, así que el grupo entero le queda con una entrada.
            { name: '👤 Mi espacio', href: '/app/mi-espacio', icon: Users, permiso: 'portal_colaborador' },
        ],
    },
    {
        // Compras: el listado de OC es del área contable; levantar la OC,
        //   recibir e importar es de bodega. Antes el grupo entero se mostraba
        //   a Contador y las cuatro últimas le respondían 403.
        key: 'compras',
        label: 'Compras',
        items: [
            { name: 'Órdenes de compra', href: '/app/compras', icon: ShoppingCart, permiso: 'compras_lista' },
            { name: '＋ Nueva OC', href: '/app/compras/oc/nueva', icon: ShoppingCart, permiso: 'compras_gestion' },
            { name: 'Importaciones · contenedores', href: '/app/compras/importacion', icon: ShoppingCart, permiso: 'compras_gestion' },
            { name: 'Devoluciones a proveedor', href: '/app/compras/devoluciones', icon: ShoppingCart, permiso: 'devoluciones_proveedor' },
            { name: 'Reporte de compras', href: '/app/compras/reporte', icon: BarChart3, permiso: 'compras_gestion' },
        ],
    },
    {
        // BODEGA · lo que necesita Jorge para operar la bodega todos los días.
        //   Recepciones, kardex, traslados, conteos, racks. Un solo bloque claro.
        //   Hay 7+ bodegas, así que no hay nombres personales acá.
        // A1 FIX · Contador no opera bodega · se removió del roles[]. El rol
        //   contable NO entra a kardex, conteos, traslados ni racks.
        key: 'bodega',
        label: 'Bodega',
        items: [
            { name: '📥 Recibir mercancía (desde contenedor)', href: '/app/compras/recepcion/nueva', icon: Warehouse, permiso: 'compras_gestion' },
            // Mismo destino que el ítem de Compras y con el mismo nombre: antes
            // se llamaba «Devolver a proveedor» acá y «Devoluciones a proveedor»
            // allá, y parecían dos pantallas distintas.
            { name: 'Devoluciones a proveedor', href: '/app/compras/devoluciones', icon: Warehouse, permiso: 'devoluciones_proveedor' },
            { name: 'Stock por producto', href: '/app/inventario', icon: Warehouse, permiso: 'bodega' },
            { name: 'Kardex (movimientos)', href: '/app/inventario/kardex', icon: Warehouse, permiso: 'bodega_operativa' },
            { name: 'Conteos físicos', href: '/app/inventario/conteos', icon: Warehouse, permiso: 'bodega_operativa' },
            { name: 'Traslados entre bodegas', href: '/app/inventario/traslados', icon: Warehouse, permiso: 'bodega_operativa' },
            { name: 'Alertas de stock', href: '/app/inventario/alertas', icon: Warehouse, permiso: 'bodega_operativa' },
            { name: '🏢 Bodegas · ubicaciones (Rack·Sección·Nivel)', href: '/app/inventario/ubicaciones', icon: Warehouse, permiso: 'bodega' },
            { name: 'Reporte de stock por bodega', href: '/app/inventario/reporte-stock', icon: BarChart3, permiso: 'bodega_operativa' },
            { name: '📥 Importar inventario (Excel)', href: '/app/inventario/importar-cliente', icon: Warehouse, permiso: 'bodega' },
        ],
    },
    {
        // Catálogo: ficha SIIGO del producto y la jerarquía Línea/Grupo/Subgrupo/Clase.
        key: 'catalogo',
        label: 'Catálogo',
        items: [
            // Sprint 4 · G.3 · CRUD Productos con 4 pestañas SIIGO.
            { name: 'Productos (ficha SIIGO)', href: '/app/catalogo/productos', icon: Package, permiso: 'catalogo' },
            // Sprint 4 · G.2 · CRUD Línea/Grupo/Subgrupo/Clase.
            { name: 'Jerarquía SIIGO', href: '/app/catalogo/jerarquia-siigo', icon: Tag, permiso: 'catalogo' },
        ],
    },
    {
        // Contabilidad: todo lo del contador queda limpio en su propia caja.
        key: 'contabilidad',
        label: 'Contabilidad',
        items: [
            { name: 'Dashboard contable', href: '/app/contabilidad', icon: Calculator, permiso: 'contabilidad' },
            { name: 'Panel operativo', href: '/app/contabilidad/panel', icon: Calculator, permiso: 'contabilidad' },
            { name: 'Plan de cuentas (PUC)', href: '/app/contabilidad/plan-cuentas', icon: FileText, permiso: 'contabilidad' },
            { name: 'Reportes (9)', href: '/app/contabilidad/reportes', icon: BarChart3, permiso: 'contabilidad' },
            { name: 'Reporte detalle', href: '/app/contabilidad/reporte-detalle', icon: FileText, permiso: 'contabilidad' },
            // A3 FIX #7 · movido desde grupo Cartera (donde no pertenece semánticamente).
            { name: 'Asientos manuales', href: '/app/contabilidad/asientos-manuales', icon: FileText, permiso: 'contabilidad' },
            { name: '⚠ Pendientes SIIGO', href: '/app/contabilidad/pendientes-siigo', icon: Bell, permiso: 'contabilidad' },
            { name: '🔎 Discrepancias SIIGO', href: '/app/contabilidad/discrepancias-siigo', icon: AlertTriangle, permiso: 'contabilidad' },
            // A3 FIX #7 · ruta CONT-C5 existía pero no estaba en sidebar.
            { name: '✓ Validación PUC SIIGO', href: '/app/contabilidad/validacion-puc-siigo', icon: FileText, permiso: 'contabilidad' },
        ],
    },
    {
        key: 'dropi',
        label: 'Dropi',
        items: [
            // El módulo Dropi se opera en /admin, que quedó siendo sólo Dropi.
            { name: '🚚 Abrir módulo Dropi', href: '/admin/dropi-pedidos', icon: LayoutDashboard, external: true, permiso: 'dropi' },
        ],
    },
    {
        // Grupo aparte y con nombre neutro: cualquiera puede pedir un reembolso
        // y la pantalla ya se adapta (quien no es del área contable ve sólo los
        // suyos). Estaba dentro de «Gerencia», y al armar el menú por permisos
        // un despachador habría visto un grupo llamado Gerencia con un ítem.
        key: 'gastos',
        label: 'Gastos',
        items: [
            { name: 'Gastos y reembolsos', href: '/app/gastos', icon: Calculator, permiso: 'gastos' },
        ],
    },
    {
        key: 'gerencia',
        label: 'Gerencia',
        items: [
            { name: 'Bandeja importaciones', href: '/app/bandeja-importaciones', icon: FileText, permiso: 'bandeja_importaciones' },
        ],
    },
    {
        key: 'config',
        label: 'Configuración',
        items: [
            { name: '🛡 Roles y permisos', href: '/app/roles', icon: Users, permiso: 'configuracion' },
            { name: 'Reglas de negocio', href: '/app/reglas', icon: Settings, permiso: 'configuracion' },
            { name: 'Empresa', href: '/app/empresa', icon: Home, permiso: 'configuracion' },
            { name: 'Plantillas PDF', href: '/app/plantillas', icon: FileText, permiso: 'configuracion' },
            { name: 'SIIGO', href: '/app/siigo', icon: Settings, permiso: 'siigo' },
        ],
    },
];

// UX #15: usar page.url (reactivo a Inertia) en vez de window.location (estático).
const currentPath = computed(() => page.url || '/');
const isActive = (href) => href === '/app' ? currentPath.value === '/app' : currentPath.value.startsWith(href);

// Sin entrada guardada el grupo está abierto: lo que el rol ve, lo ve completo.
const grupoAbierto = (key) => openGroups.value[key] !== false;

const toggleGroup = (key) => {
    openGroups.value[key] = ! grupoAbierto(key);
    try {
        localStorage.setItem(CLAVE_GRUPOS, JSON.stringify(openGroups.value));
    } catch { /* si no hay localStorage, se pierde al recargar y no pasa nada */ }
};

/*
 * Filtra el menú con los permisos que calculó el servidor.
 *
 *   • `auth.menu` es la lista de claves que esta persona puede abrir de verdad
 *     (App\Auth\MenuApp, misma expresión que el controller de cada ruta).
 *   • Un ítem sin `permiso` no se muestra: si alguien agrega una entrada nueva
 *     y olvida declararla, queda oculta en vez de ofrecer un enlace que falla.
 *   • Un grupo sin ítems visibles desaparece, así nadie ve títulos vacíos.
 *
 * Acá ya no se mira el nombre del rol: esa era la razón por la que marcar una
 * casilla en /app/roles no cambiaba nada en el menú.
 */
const clavesMenu = computed(() => page.props.auth?.menu || []);
const puedeVer = (clave) => !! clave && clavesMenu.value.includes(clave);

const visibleGroups = computed(() => groups
    .map((g) => ({
        ...g,
        items: g.items.filter((it) => puedeVer(it.permiso)),
    }))
    .filter((g) => g.items.length > 0)
);
</script>

<template>
    <div class="min-h-screen bg-surface-50 dark:bg-surface-950 flex">
        <!-- Backdrop móvil: click cierra el sidebar -->
        <div
            v-if="sidebarOpen"
            @click="setSidebar(false)"
            aria-hidden="true"
            class="md:hidden fixed inset-0 z-30 bg-black/40 backdrop-blur-sm"
        ></div>
        <!-- Sidebar colapsable con ancho animado en desktop -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-40 transform transition-all duration-200 md:relative',
                'bg-white dark:bg-surface-900 border-r border-surface-200 dark:border-surface-800',
                sidebarOpen ? 'w-64 translate-x-0' : 'w-0 -translate-x-full md:w-0 md:translate-x-0',
                'overflow-hidden',
            ]"
        >
            <!-- Brand -->
            <div class="h-16 flex items-center justify-between px-4 border-b border-surface-200 dark:border-surface-800 w-64">
                <Link href="/app" class="flex items-center gap-2 font-bold text-lg">
                    <div class="h-8 w-8 rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center text-white text-xs">
                        GB
                    </div>
                    <span class="text-surface-900 dark:text-surface-100">GREAT BABY</span>
                </Link>
                <button @click="setSidebar(false)" class="btn-ghost p-1.5" title="Cerrar menú">
                    <X class="h-5 w-5"/>
                </button>
            </div>

            <!-- Nav -->
            <nav class="flex-1 overflow-y-auto px-2 py-3 space-y-1 w-64">
                <div v-for="group in visibleGroups" :key="group.key">
                    <button
                        @click="toggleGroup(group.key)"
                        class="w-full flex items-center justify-between px-3 py-2 text-xs font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-200"
                    >
                        <span>{{ group.label }}</span>
                        <ChevronDown :class="['h-4 w-4 transition-transform', grupoAbierto(group.key) ? 'rotate-0' : '-rotate-90']"/>
                    </button>
                    <div v-show="grupoAbierto(group.key)" class="mt-1 space-y-0.5">
                        <template v-for="item in group.items" :key="item.href">
                            <!-- Links externos (ej. panel Filament /admin/*) se renderizan como <a> normal
                                 y abren en nueva pestaña. Los internos usan <Link> de Inertia. -->
                            <a v-if="item.external"
                                :href="item.href"
                                target="_blank"
                                rel="noopener"
                                class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800"
                            >
                                <component :is="item.icon" class="h-4 w-4 flex-shrink-0"/>
                                <span class="flex-1">{{ item.name }}</span>
                                <span class="text-[10px] uppercase text-surface-400">↗</span>
                            </a>
                            <Link v-else
                                :href="item.href"
                                :class="[
                                    'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
                                    isActive(item.href)
                                        ? 'bg-brand-50 text-brand-800 dark:bg-brand-900/30 dark:text-brand-300'
                                        : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800',
                                ]"
                            >
                                <component :is="item.icon" class="h-4 w-4 flex-shrink-0"/>
                                <span class="flex-1">{{ item.name }}</span>
                                <span v-if="item.badge === 'pedidosB2BPend' && pedidosB2BPend > 0"
                                    class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center animate-pulse">
                                    {{ pedidosB2BPend }}
                                </span>
                            </Link>
                        </template>
                    </div>
                </div>
            </nav>
        </aside>

        <!-- Main -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Topbar -->
            <header class="h-16 border-b border-surface-200 dark:border-surface-800 bg-white dark:bg-surface-900 flex items-center justify-between px-4 sticky top-0 z-30">
                <div class="flex items-center gap-3">
                    <button @click="toggleSidebar" class="btn-ghost p-2" aria-label="Alternar menú lateral"
                        :title="sidebarOpen ? 'Ocultar menú' : 'Mostrar menú'">
                        <MenuIcon class="h-5 w-5"/>
                    </button>
                    <!-- MEJORAS-A · ⌘K real: buscador global funcional -->
                    <CommandPalette/>
                </div>

                <div class="flex items-center gap-2">
                    <!--
                        FASE E · Semáforo SIIGO (polling 30s).

                        Sólo para quien puede abrir /app/siigo. Se mostraba a
                        todo el mundo, y como esa pantalla es de gerencia, cada
                        usuario sin acceso se quedaba con el chip en «cargando…»
                        para siempre —el `catch {}` del componente se traga el
                        403— mientras pedía el semáforo cada 30 segundos y
                        recibía un Forbidden cada vez. Y el chip es un enlace,
                        así que al hacerle clic se llegaba a un 403.

                        Es el mismo defecto que ya se había arreglado en el menú
                        («mostraba enlaces que el controller no deja abrir»);
                        este sobrevivió porque vive en la barra, no en `groups`.
                    -->
                    <SemaforoSiigo v-if="puedeVer('siigo')"/>
                    <!-- MEJORAS-B · Bell real-time con polling 20s + sonido -->
                    <NotificacionesBell/>
                    <div class="flex items-center gap-2 pl-2 border-l border-surface-200 dark:border-surface-800">
                        <div class="h-8 w-8 rounded-full bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center text-white text-sm font-bold" :title="user?.name">
                            {{ user?.name?.charAt(0) ?? '?' }}
                        </div>
                        <div class="hidden md:block text-sm">
                            <div class="font-medium">{{ user?.name }}</div>
                            <div class="text-xs text-surface-500">{{ user?.es_aracely ? 'Gerencia' : (user?.roles?.[0] || 'Operario') }}</div>
                        </div>
                        <Link href="/logout" method="post" as="button" class="btn-ghost p-2" title="Cerrar sesión" aria-label="Cerrar sesión">
                            <LogOut class="h-4 w-4"/>
                        </Link>
                    </div>
                </div>
            </header>

            <!-- Content -->
            <main class="flex-1 p-6 overflow-x-auto">
                <slot/>
            </main>
        </div>

        <!-- MEJORAS-B · Copiloto IA flotante (solo Aracely lo ve) -->
        <CopilotoChat v-if="user?.es_aracely"/>

        <!-- Toasts globales de error con COLA (varios errores no se pisan). -->
        <div class="fixed bottom-6 right-6 z-50 space-y-2 max-w-sm w-full pointer-events-none">
            <transition-group
                tag="div"
                class="space-y-2"
                enter-active-class="transition duration-200"
                enter-from-class="translate-y-4 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0">
                <div v-for="t in toasts" :key="t.id"
                     role="alert"
                     :class="['pointer-events-auto px-4 py-3 rounded-lg shadow-2xl text-white text-sm font-medium flex items-center gap-3',
                              (TONOS[t.tono || 'error'] || TONOS.error).clase]">
                    <span class="text-lg" aria-hidden="true">{{ (TONOS[t.tono || 'error'] || TONOS.error).icono }}</span>
                    <span class="flex-1">{{ t.mensaje }}</span>
                    <button @click="cerrarToast(t.id)" class="text-white/70 hover:text-white text-lg" aria-label="Cerrar">×</button>
                </div>
            </transition-group>
        </div>
    </div>
</template>
