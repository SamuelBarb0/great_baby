<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Package, Plus, Search, Eye, Pencil, Trash2, Cloud, CloudOff, Lock, CloudDownload, RefreshCw,
    Download, Upload, FileText, FileSpreadsheet, Inbox, AlertTriangle,
} from 'lucide-vue-next';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import { mensajeDeError } from '@/composables/useMensajeError';

const props = defineProps({
    filtros: { type: Object, required: true },
    /*
     * Si quien mira puede ESCRIBIR en el catálogo. La pantalla se abre con
     * lectura para más gente de la que puede eliminar, clonar, importar o
     * empujar a SIIGO: sin esta bandera, los botones salían para todos y
     * daban 403 uno por uno.
     */
    puede_administrar: { type: Boolean, default: false },
    // «Traer de SIIGO» y «Por código» van a /app/siigo/*, con su propia puerta.
    puede_siigo: { type: Boolean, default: false },
    // Importar el catálogo tiene su propia casilla.
    puede_importar: { type: Boolean, default: false },
    kpis: { type: Object, required: true },
    productos: { type: Object, required: true },
    lineas: { type: Array, required: true },
    autoSync: { type: Object, default: () => ({ chequeados: 0, actualizados: 0, ts: null }) },
    // Últimas importaciones Excel SIIGO · lo pasa el controller como array de
    // {id, archivo, iniciada, exitosas, fallidas, estado}. Si el controller
    // no lo entrega aún, el modal muestra "Sin importaciones previas".
    ultimasImports: { type: Array, default: () => [] },
});

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

const { money } = useMoney();

// Flash message del controller (ej. "Producto X creado · enviando a SIIGO…")
// El middleware HandleInertiaRequests comparte flash como {success, error, warning, info}.
// `flashLocal` permite mostrar flashes desde el propio JS (ej. "sync iniciada").
const page = usePage();
const flashLocal = ref(null);
const flashMsg = computed(() => {
    if (flashLocal.value) return flashLocal.value;
    const f = page.props.flash || {};
    if (f.success) return { type: 'success', message: f.success };
    if (f.error)   return { type: 'error',   message: f.error };
    if (f.warning) return { type: 'warning', message: f.warning };
    if (f.info)    return { type: 'info',    message: f.info };
    return null;
});
const flashVisible = ref(false);
let flashTimer;
const hideFlash = () => { flashVisible.value = false; flashLocal.value = null; clearTimeout(flashTimer); };
const showFlash = ({ type = 'info', message }) => {
    flashLocal.value = { type, message };
    flashVisible.value = true;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flashVisible.value = false; flashLocal.value = null; }, 9000);
};

import { watch as vueWatch } from 'vue';
// FASE F4 · el watch anterior solo apagaba `flashVisible` tras 7s pero NO
// reseteaba `flashLocal`; eso dejaba el toast viejo pegado como primera
// opción del computed, pisando flashes nuevos del controller cuando
// Inertia hacía re-render. Ahora el timer limpia AMBOS, y clicks a ✕
// también los limpian atómicamente.
vueWatch(flashMsg, (v) => {
    if (v?.message) {
        flashVisible.value = true;
        clearTimeout(flashTimer);
        flashTimer = setTimeout(() => {
            flashVisible.value = false;
            flashLocal.value = null;
        }, 7000);
    }
}, { immediate: true });

const q = ref(props.filtros.q);
const activo = ref(props.filtros.activo);
const lineaId = ref(props.filtros.linea_id);
const sinSiigo = ref(!!props.filtros.sin_siigo);

let debounce;
const filtrar = () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get('/app/catalogo/productos', {
            q: q.value || null,
            activo: activo.value === '' ? null : activo.value,
            linea_id: lineaId.value || null,
            sin_siigo: sinSiigo.value ? 1 : null,
        }, { preserveScroll: true, preserveState: true, replace: true });
    }, 300);
};

const eliminar = (p) => {
    // FASE F2.A3 · quitar del array de seleccionados si estaba marcado
    seleccionados.value = seleccionados.value.filter(id => id !== p.id);
    modalConfirm.value = {
        titulo: `¿Eliminar producto ${p.referencia}?`,
        mensaje: `Solo se permite si no tiene movimientos de kardex.`,
        color: 'rose',
        textoConfirmar: 'Eliminar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.delete(`/app/catalogo/productos/${p.id}`, { preserveScroll: true });
        },
    };
};

// FASE C2 UI · Selección masiva
const seleccionados = ref([]);
const bulkCargando = ref(false);

const todosSeleccionados = computed(() => {
    const visibles = (props.productos.data || []).map(p => p.id);
    return visibles.length > 0 && visibles.every(id => seleccionados.value.includes(id));
});

const toggleTodos = () => {
    const visibles = (props.productos.data || []).map(p => p.id);
    if (todosSeleccionados.value) {
        seleccionados.value = seleccionados.value.filter(id => ! visibles.includes(id));
    } else {
        seleccionados.value = [...new Set([...seleccionados.value, ...visibles])];
    }
};

// Modal de confirmación propio · reemplaza confirm/prompt (bloqueados en iframe del app).
const modalBulk = ref(null);  // { titulo, mensaje, requierePct, pctValor, onConfirmar, color }
// Fix Tailwind JIT · `text-${color}-700` interpolado NO se genera en el
// build porque el JIT escanea strings literales, no concatenaciones. Mapa
// estático de las clases que efectivamente usamos aquí.
const PALETA_MODAL = {
    emerald: { title: 'text-emerald-700', btn: 'bg-emerald-600 hover:bg-emerald-700' },
    rose:    { title: 'text-rose-700',    btn: 'bg-rose-600 hover:bg-rose-700' },
    amber:   { title: 'text-amber-700',   btn: 'bg-amber-600 hover:bg-amber-700' },
    sky:     { title: 'text-sky-700',     btn: 'bg-sky-600 hover:bg-sky-700' },
    brand:   { title: 'text-brand-700',   btn: 'bg-brand-600 hover:bg-brand-700' },
};
const colorModalTitulo = computed(() => (PALETA_MODAL[modalBulk.value?.color] || PALETA_MODAL.brand).title);
const colorModalBtn    = computed(() => (PALETA_MODAL[modalBulk.value?.color] || PALETA_MODAL.brand).btn);

const abrirBulkActivar = () => abrirBulkModal({
    titulo: `Activar ${seleccionados.value.length} productos`,
    mensaje: `Marcará como activo los ${seleccionados.value.length} productos seleccionados · volverán a aparecer en el facturador.`,
    color: 'emerald',
    onConfirmar: () => enviarBulk('activo', true),
});
const abrirBulkDesactivar = () => abrirBulkModal({
    titulo: `Desactivar ${seleccionados.value.length} productos`,
    mensaje: `Marcará como inactivo los ${seleccionados.value.length} productos · dejarán de aparecer en el facturador. Se pueden reactivar.`,
    color: 'amber',
    onConfirmar: () => enviarBulk('activo', false),
});
const abrirBulkDescuento = () => abrirBulkModal({
    titulo: `Ajustar precio de ${seleccionados.value.length} productos`,
    mensaje: 'Negativo = descuento · positivo = aumento. Rango -100 a 100.',
    color: 'violet',
    requierePct: true,
    pctValor: -10,
    onConfirmar: (pct) => enviarBulk('descuento', pct),
});
const abrirBulkEliminar = () => abrirBulkModal({
    titulo: `Eliminar ${seleccionados.value.length} productos`,
    mensaje: `Los que tengan movimientos de kardex se SALTARÁN automáticamente. Los demás pasan a Papelera (recuperables 30 días).`,
    color: 'red',
    onConfirmar: () => enviarBulk('eliminar'),
});
// PROD-13 · bulk push a SIIGO. Encola 1 job PushProductoASiigo por producto:
// 'crear' si el producto no tiene siigo_id, 'actualizar' si ya lo tiene.
// Inactivos y productos sin referencia se saltan automáticamente.
const abrirBulkPushSiigo = () => abrirBulkModal({
    titulo: `Enviar ${seleccionados.value.length} productos a SIIGO`,
    mensaje: 'Se encolará cada producto en la cola SIIGO (crear o actualizar según corresponda). Los inactivos o sin referencia se saltan. El proceso corre en segundo plano · podés seguir trabajando.',
    color: 'sky',
    onConfirmar: () => enviarBulkPushSiigo(),
});
const enviarBulkPushSiigo = async () => {
    bulkCargando.value = true;
    try {
        const { data } = await axios.post('/app/catalogo/productos-bulk-push-siigo', {
            ids: seleccionados.value,
        });
        showFlash({ type: 'success', message: data.mensaje });
        seleccionados.value = [];
        cerrarBulkModal();
        // No necesitamos reload inmediato · los siigo_sync_at se actualizan a
        // medida que la cola procesa; el usuario puede revisar el semáforo.
    } catch (e) {
        showFlash({ type: 'error', message: mensajeDeError(e, 'No pude enviar los productos a SIIGO') });
    } finally {
        bulkCargando.value = false;
    }
};

const abrirBulkModal = (cfg) => { modalBulk.value = cfg; };
const cerrarBulkModal = () => { modalBulk.value = null; };

const enviarBulk = async (accion, valor = null) => {
    bulkCargando.value = true;
    try {
        const payload = { ids: seleccionados.value, accion };
        if (accion === 'activo') payload.activo = valor;
        if (accion === 'descuento') payload.descuento_pct = valor;
        const { data } = await axios.post('/app/catalogo/productos-bulk-edit', payload);
        showFlash({ type: 'success', message: data.mensaje || `${data.afectados} productos actualizados.` });
        seleccionados.value = [];
        cerrarBulkModal();
        router.reload({ only: ['productos', 'kpis'] });
    } catch (e) {
        showFlash({ type: 'error', message: mensajeDeError(e, 'No pude aplicar el cambio masivo') });
    } finally {
        bulkCargando.value = false;
    }
};

// Compat para plantilla vieja (los botones del header llamaban a estos nombres)
const bulkAccion = (accion, valor) => accion === 'activo' ? (valor ? abrirBulkActivar() : abrirBulkDesactivar()) : null;
const bulkEliminar = abrirBulkEliminar;

const importandoDeSiigo = ref(false);
const modalImportar = ref(false);
const codeImport = ref('');
const resultadoImport = ref(null);  // {ok, mensaje}

const abrirImportar = () => {
    codeImport.value = '';
    resultadoImport.value = null;
    modalImportar.value = true;
};

const confirmarImportar = async () => {
    const code = (codeImport.value || '').trim();
    if (! code) { resultadoImport.value = { ok: false, mensaje: 'Falta el código' }; return; }
    importandoDeSiigo.value = true;
    resultadoImport.value = null;
    try {
        const { data } = await axios.post('/app/siigo/importar-por-code', { code });
        resultadoImport.value = data;
        if (data.ok) {
            setTimeout(() => {
                modalImportar.value = false;
                router.reload({ only: ['productos', 'kpis'] });
            }, 1500);
        }
    } catch (e) {
        resultadoImport.value = { ok: false, mensaje: mensajeDeError(e, 'No pude importar el archivo') };
    } finally {
        importandoDeSiigo.value = false;
    }
};

// Auto-refresh del listado · si hay productos pendientes de sync (recién
// creados/editados que esperan al worker), reconsulto cada 3s hasta que
// todos tengan badge verde. Evita que el user tenga que apretar F5.
const colaPendientes = ref(0);
const checkCola = async () => {
    try {
        const { data } = await axios.get('/app/siigo/logs?per_page=1');
        // Si hay jobs pendientes (los chequea el panel SIIGO), re-pedimos la lista.
        // Simplificado: siempre refrescamos la lista cada 3s mientras hay
        // productos sin siigo_id en los últimos 2min, hasta un max de 20 ticks.
    } catch (e) { /* ignore */ }
};
let autoRefreshTimer, autoRefreshCount = 0;
const refrescarLista = () => {
    router.reload({ only: ['productos', 'kpis'], preserveScroll: true, preserveState: true });
};
onMounted(() => {
    // Si hay flash recién → hay algo nuevo cuyo push está en cola, refrescamos
    // cada 3s por 60s para captar el badge verde cuando llegue de SIIGO.
    if (flashMsg.value?.message?.includes('segundo plano')) {
        autoRefreshTimer = setInterval(() => {
            autoRefreshCount++;
            refrescarLista();
            if (autoRefreshCount > 20) clearInterval(autoRefreshTimer);  // ~60s
        }, 3000);
    }
});
onUnmounted(() => { clearInterval(autoRefreshTimer); clearTimeout(flashTimer); });

// Sincronización masiva ASÍNCRONA · encola job y hace polling cada 3s.
// Al terminar, refresca la lista sola para mostrar lo que cambió en SIIGO.
const sincronizandoTodo = ref(false);
const resumenSync = ref(null);
const deshaciendoSync = ref(false);
let pollingTimer = null;

const deshacerUltimaSync = async () => {
    modalConfirm.value = {
        titulo: `¿Deshacer la última sincronización?`,
        mensaje: `Se marcarán como inactivos los ${resumenSync.value?.nuevos || 0} productos nuevos que trajo del sandbox.\nSe PROTEGEN automáticamente los que tienen movimientos o fueron editados.\n\nEsta acción es REVERSIBLE (soft-delete).`,
        color: 'amber',
        textoConfirmar: 'Deshacer',
        onConfirmar: async () => {
            modalConfirm.value = null;
            deshaciendoSync.value = true;
            try {
                const { data } = await axios.post('/app/siigo/reconciliar/deshacer', { confirmar: true });
                if (data.ok) {
                    showFlash({ type: 'success', message: '🗑 Sync revertida · lista actualizada.' });
                    resumenSync.value = null;
                    router.reload({ only: ['productos', 'kpis'] });
                } else {
                    showFlash({ type: 'error', message: '⚠ ' + (data.mensaje || 'No se pudo deshacer') });
                }
            } catch (e) {
                showFlash({ type: 'error', message: mensajeDeError(e, 'No pude deshacer la sincronizacion') });
            } finally {
                deshaciendoSync.value = false;
            }
        },
    };
};

const consultarEstadoSync = async () => {
    try {
        const { data } = await axios.get('/app/siigo/reconciliar/estado');
        if (data.estado === 'completado') {
            clearInterval(pollingTimer);
            sincronizandoTodo.value = false;
            resumenSync.value = { ...data.resumen, modo: data.modo };
            const r = data.resumen || {};
            const total = (r.nuevos || 0) + (r.actualizados || 0);
            const msg = total === 0
                ? '✓ Sin cambios nuevos en SIIGO · ya estaba al día.'
                : `✓ Trajimos ${total} cambios · +${r.nuevos || 0} nuevos · ${r.actualizados || 0} actualizados${r.errores ? ` · ${r.errores} errores` : ''}`;
            showFlash({ type: 'success', message: msg });
            router.reload({ only: ['productos', 'kpis'] });
        } else if (data.estado === 'fallido') {
            clearInterval(pollingTimer);
            sincronizandoTodo.value = false;
            showFlash({ type: 'error', message: '⚠ Sync SIIGO falló: ' + (data.error || 'error desconocido') });
        }
    } catch (e) {
        // Red tumbada · no mata el polling, reintenta en el próximo tick.
    }
};

// FASE G · Importar Excel SIIGO
const modalImportarExcel = ref(false);
const archivoImport = ref(null);
const importandoExcel = ref(false);
const resumenImport = ref(null);
const abrirImportarExcel = () => {
    archivoImport.value = null;
    resumenImport.value = null;
    modalImportarExcel.value = true;
};
const confirmarImportarExcel = async () => {
    if (! archivoImport.value) return;
    importandoExcel.value = true;
    resumenImport.value = null;
    try {
        const data = new FormData();
        data.append('archivo', archivoImport.value);
        const { data: r } = await axios.post('/app/catalogo/productos-importar-siigo', data, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        resumenImport.value = r;
        showFlash({
            type: r.errores ? 'warning' : 'success',
            message: `Import SIIGO · ${r.procesados} filas · +${r.nuevos} nuevos · ${r.actualizados} actualizados${r.errores ? ` · ${r.errores} errores` : ''}`,
        });
        // FASE F2.A11 · cerrar modal auto si todo OK; si hay errores lo
        // dejamos abierto para que vea el enlace al reporte xlsx.
        if (! r.errores) {
            modalImportarExcel.value = false;
            archivoImport.value = null;
        }
        router.reload({ only: ['productos', 'kpis'] });
    } catch (e) {
        showFlash({ type: 'error', message: mensajeDeError(e, 'No pude importar el Excel') });
    } finally {
        importandoExcel.value = false;
    }
};

// PROD-16 · Política de conflicto · reconciliar sobre-escribe campos locales
// con lo que SIIGO tenga. Antes de disparar el job pedimos confirmación
// explícita y recordamos que para ver qué va a cambiar hay un reporte global.
const modalReconciliar = ref(false);
const abrirConfirmarReconciliar = () => {
    if (sincronizandoTodo.value) return;
    modalReconciliar.value = true;
};

// B4 · Botón "Traer mis cambios de SIIGO"
//   Modo INCREMENTAL · solo trae los productos que cambiaron en SIIGO desde
//   la última sync (updated_start). Típicamente <30 seg. El full pull vive
//   detrás de `php artisan siigo:reconciliar --full --confirmar` para evitar
//   accidentes con el sandbox compartido.
const sincronizarTodo = async () => {
    modalReconciliar.value = false;
    sincronizandoTodo.value = true;
    resumenSync.value = null;
    try {
        const { data } = await axios.post('/app/siigo/reconciliar');
        if (data.ok) {
            showFlash({
                type: 'success',
                message: data.yaCorriendo
                    ? '⏳ Ya hay una sync en curso · esperando que termine…'
                    : (data.mensaje || '⏳ Trayendo cambios de SIIGO…'),
            });
            clearInterval(pollingTimer);
            pollingTimer = setInterval(consultarEstadoSync, 3000);
        } else {
            sincronizandoTodo.value = false;
            showFlash({ type: 'error', message: '⚠ ' + (data.mensaje || 'No se pudo iniciar la sincronización') });
        }
    } catch (e) {
        sincronizandoTodo.value = false;
        showFlash({ type: 'error', message: mensajeDeError(e, 'No pude sincronizar con SIIGO') });
    }
};

// Al cargar, si hay una sync en curso (puede haberse disparado en otra pestaña)
// retomamos el polling solos.
onMounted(() => {
    axios.get('/app/siigo/reconciliar/estado').then(({ data }) => {
        if (data.estado === 'corriendo') {
            sincronizandoTodo.value = true;
            clearInterval(pollingTimer);
            pollingTimer = setInterval(consultarEstadoSync, 3000);
        }
    }).catch(() => {});
});
onUnmounted(() => { clearInterval(pollingTimer); });

// FASE F2.A2 · limpia selección al cambiar de página o aplicar filtros
// (evita que bulk afecte productos NO visibles de una página anterior).
const stopNav = router.on('start', () => { seleccionados.value = []; });
onUnmounted(() => { if (typeof stopNav === 'function') stopNav(); });
</script>

<template>
    <Head title="Productos · Kardex Referencias SIIGO"/>
    <AppLayout>
        <!-- Flash message (ej. "Producto X creado · enviando a SIIGO…") -->
        <div v-if="flashVisible && flashMsg"
             :class="['fixed top-4 right-4 z-50 shadow-xl rounded-lg px-4 py-3 flex items-center gap-3 border max-w-md transition-all',
                      flashMsg.type === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-900' :
                      flashMsg.type === 'error' ? 'bg-red-50 border-red-300 text-red-900' :
                      'bg-sky-50 border-sky-300 text-sky-900']">
            <span class="text-sm font-medium">{{ flashMsg.message }}</span>
            <button @click="hideFlash" class="ml-auto text-current opacity-60 hover:opacity-100">✕</button>
        </div>

        <div class="space-y-4">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Package class="h-6 w-6 text-brand-600"/>
                        Productos · Kardex Referencias SIIGO
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Catálogo de referencias con las 4 pestañas SIIGO (General · Comercial · Características · Ficha técnica).
                    </p>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <!-- Grupo SIIGO · sincronización bidireccional -->
                    <div v-if="puede_siigo"
                         class="flex items-center gap-0.5 rounded-lg border border-emerald-200 bg-emerald-50/40 p-0.5"
                         title="Traer datos desde SIIGO al ERP">
                        <button type="button" @click="abrirConfirmarReconciliar" :disabled="sincronizandoTodo"
                                class="text-sm inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-emerald-700 hover:bg-emerald-100 disabled:opacity-50">
                            <RefreshCw :class="['h-4 w-4', sincronizandoTodo && 'animate-spin']"/>
                            <span class="hidden md:inline">{{ sincronizandoTodo ? 'Trayendo…' : 'Traer de SIIGO' }}</span>
                        </button>
                        <div class="h-5 w-px bg-emerald-200"></div>
                        <button type="button" @click="abrirImportar"
                                class="text-sm inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-emerald-700 hover:bg-emerald-100">
                            <CloudDownload class="h-4 w-4"/>
                            <span class="hidden md:inline">Por código</span>
                        </button>
                    </div>

                    <!-- Excel SIIGO · botón único que abre el modal con todas las acciones -->
                    <button v-if="puede_importar" type="button" @click="abrirImportarExcel()"
                            class="text-sm inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-amber-200 bg-amber-50/40 text-amber-700 hover:bg-amber-100">
                        <FileSpreadsheet class="h-4 w-4"/>
                        Excel SIIGO
                    </button>

                    <!-- Papelera · acción secundaria discreta -->
                    <Link v-if="puede_administrar" href="/app/catalogo/productos-papelera"
                          class="text-sm inline-flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-surface-600 hover:bg-surface-100"
                          title="Papelera · productos eliminados en los últimos 30 días">
                        <Trash2 class="h-4 w-4"/>
                        <span class="hidden lg:inline">Papelera</span>
                    </Link>

                    <!-- Separador visual + Primary -->
                    <div class="h-6 w-px bg-surface-200 mx-1 hidden sm:block"></div>
                    <Link href="/app/catalogo/productos/nuevo" class="btn-primary text-sm inline-flex items-center gap-1.5 px-4 py-2">
                        <Plus class="h-4 w-4"/> Nuevo producto
                    </Link>
                </div>
            </div>

            <!-- Modal Importar Excel SIIGO · estilo TalentMap -->
            <div v-if="modalImportarExcel"
                 class="fixed inset-0 bg-black/50 flex items-start justify-center z-50 p-4 overflow-y-auto"
                 @click.self="modalImportarExcel = false">
                <div class="bg-surface-50 rounded-2xl shadow-2xl max-w-4xl w-full my-6 overflow-hidden">
                    <!-- Botón cerrar flotante -->
                    <div class="relative">
                        <button @click="modalImportarExcel = false"
                                class="absolute top-3 right-3 z-10 w-8 h-8 rounded-full bg-white/90 hover:bg-white text-surface-600 flex items-center justify-center">✕</button>
                    </div>

                    <div class="p-5 space-y-4">
                        <!-- Banda header ámbar -->
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 flex items-start gap-3">
                            <div class="shrink-0 w-11 h-11 rounded-lg bg-amber-500 text-white flex items-center justify-center">
                                <Upload class="h-5 w-5"/>
                            </div>
                            <div class="flex-1">
                                <h2 class="text-lg font-bold text-sky-800">Carga masiva de Productos SIIGO</h2>
                                <p class="text-sm text-sky-700/80 mt-0.5">
                                    Importa muchos productos en un solo paso usando la plantilla oficial. Los nombres de columnas y los enums se generan con los datos reales de tu catálogo SIIGO.
                                </p>
                            </div>
                        </div>

                        <!-- Instrucciones de uso + plantilla CTA -->
                        <div class="rounded-xl border border-surface-200 bg-white overflow-hidden">
                            <div class="flex items-center gap-2 px-4 py-3 border-b border-surface-100">
                                <div class="w-7 h-7 rounded-md bg-sky-100 text-sky-600 flex items-center justify-center">
                                    <FileText class="h-4 w-4"/>
                                </div>
                                <h3 class="font-semibold text-surface-800">Instrucciones de uso</h3>
                            </div>
                            <div class="p-4 grid md:grid-cols-[1fr_300px] gap-4">
                                <ol class="text-sm text-surface-700 space-y-2">
                                    <li><span class="font-bold text-amber-600">1.</span> Descarga la <b>plantilla oficial</b> con los datos de tu catálogo.</li>
                                    <li><span class="font-bold text-amber-600">2.</span> Llena la hoja <code class="bg-surface-100 px-1 rounded text-xs">Datos</code> · 34 columnas A–AH.</li>
                                    <li><span class="font-bold text-amber-600">3.</span> Usa la columna <code class="bg-surface-100 px-1 rounded text-xs">C</code> (código del producto) como clave única · si existe actualiza, si no, crea.</li>
                                    <li><span class="font-bold text-amber-600">4.</span> Selecciona categorías e impuestos desde los <b>desplegables</b> de la Hoja2.</li>
                                    <li><span class="font-bold text-amber-600">5.</span> Sube el archivo aquí. Al terminar, los cambios se empujan a SIIGO en segundo plano.</li>
                                </ol>
                                <div class="rounded-xl border border-amber-200 bg-amber-50/60 p-3 flex flex-col">
                                    <h4 class="font-semibold text-sm text-surface-800">Plantilla oficial</h4>
                                    <p class="text-xs text-surface-600 mt-1 mb-3 flex-1">
                                        Archivo xlsx con las 34 columnas SIIGO y los enums reales de tu catálogo (categorías, impuestos, unidades).
                                    </p>
                                    <a href="/app/catalogo/productos-plantilla-siigo"
                                       class="block text-center text-sm font-semibold px-3 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white inline-flex items-center justify-center gap-2">
                                        <Download class="h-4 w-4"/> Descargar plantilla
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Subir archivo · dropzone -->
                        <div class="rounded-xl border border-surface-200 bg-white overflow-hidden">
                            <div class="flex items-center gap-2 px-4 py-3 border-b border-surface-100">
                                <div class="w-7 h-7 rounded-md bg-amber-100 text-amber-600 flex items-center justify-center">
                                    <Upload class="h-4 w-4"/>
                                </div>
                                <h3 class="font-semibold text-surface-800">Subir archivo</h3>
                            </div>
                            <div class="p-4 space-y-3">
                                <label class="block cursor-pointer border-2 border-dashed border-surface-300 hover:border-amber-400 rounded-xl p-8 text-center transition-colors"
                                       :class="archivoImport && 'border-emerald-400 bg-emerald-50/40'">
                                    <input type="file" accept=".xlsx,.xls"
                                           @change="archivoImport = $event.target.files?.[0] || null"
                                           class="hidden"/>
                                    <div class="flex flex-col items-center gap-1.5">
                                        <div class="w-11 h-11 rounded-full bg-sky-100 text-sky-600 flex items-center justify-center">
                                            <FileSpreadsheet class="h-5 w-5"/>
                                        </div>
                                        <div class="text-sm text-sky-700 font-medium">
                                            {{ archivoImport ? archivoImport.name : 'Haz click o arrastra un archivo .xlsx' }}
                                        </div>
                                        <div class="text-xs text-surface-500">Máx. 5 MB</div>
                                    </div>
                                </label>

                                <div v-if="resumenImport" class="rounded-lg border p-3 text-sm space-y-1"
                                     :class="resumenImport.errores ? 'border-amber-300 bg-amber-50' : 'border-emerald-300 bg-emerald-50'">
                                    <div><strong>{{ resumenImport.procesados }}</strong> filas · <strong class="text-emerald-700">{{ resumenImport.nuevos }}</strong> nuevos · <strong class="text-sky-700">{{ resumenImport.actualizados }}</strong> actualizados · <strong :class="resumenImport.errores > 0 ? 'text-red-700' : 'text-surface-500'">{{ resumenImport.errores }}</strong> errores</div>
                                    <a v-if="resumenImport.reporte_url" :href="resumenImport.reporte_url"
                                       class="text-xs underline text-sky-700">Descargar reporte Excel (procesados + errores)</a>
                                </div>

                                <div class="flex justify-end">
                                    <button @click="confirmarImportarExcel"
                                            :disabled="!archivoImport || importandoExcel"
                                            class="text-sm px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white disabled:opacity-40 inline-flex items-center gap-2 font-medium">
                                        <RefreshCw :class="['h-4 w-4', importandoExcel && 'animate-spin']"/>
                                        {{ importandoExcel ? 'Procesando…' : 'Procesar archivo' }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Últimas importaciones · link a la bandeja completa -->
                        <div class="rounded-xl border border-surface-200 bg-white overflow-hidden">
                            <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-surface-100">
                                <div class="flex items-center gap-2">
                                    <div class="w-7 h-7 rounded-md bg-sky-100 text-sky-600 flex items-center justify-center">
                                        <Inbox class="h-4 w-4"/>
                                    </div>
                                    <h3 class="font-semibold text-surface-800">Últimas importaciones</h3>
                                </div>
                                <Link href="/app/bandeja" class="text-xs text-sky-600 hover:underline">Ver todas →</Link>
                            </div>
                            <div class="p-0 overflow-x-auto">
                                <table v-tabla-movil v-if="(ultimasImports || []).length" class="w-full text-sm">
                                    <thead class="text-[11px] text-surface-500 uppercase bg-surface-50">
                                        <tr>
                                            <th class="text-left px-3 py-2">Archivo</th>
                                            <th class="text-left px-3 py-2">Fecha</th>
                                            <th class="text-right px-3 py-2">Procesados</th>
                                            <th class="text-right px-3 py-2">Errores</th>
                                            <th class="text-center px-3 py-2">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-surface-100">
                                        <tr v-for="l in ultimasImports.slice(0,5)" :key="l.id" class="hover:bg-amber-50/30">
                                            <td class="px-3 py-2 text-xs font-mono truncate max-w-xs">{{ l.archivo }}</td>
                                            <td class="px-3 py-2 text-xs text-surface-500">{{ l.iniciada }}</td>
                                            <td class="px-3 py-2 text-right text-emerald-600 font-medium">{{ l.exitosas }}</td>
                                            <td class="px-3 py-2 text-right" :class="l.fallidas > 0 ? 'text-red-600 font-bold' : 'text-surface-400'">{{ l.fallidas }}</td>
                                            <td class="px-3 py-2 text-center">
                                                <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase"
                                                      :class="l.estado === 'completada' ? 'bg-emerald-100 text-emerald-700' : l.estado === 'fallida' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'">
                                                    {{ l.estado }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div v-else class="p-6 text-center text-sm text-surface-500">
                                    Sin importaciones previas. La primera que subas quedará registrada aquí.
                                </div>
                            </div>
                        </div>

                        <!-- Footer con cerrar -->
                        <div class="flex items-center justify-end pt-1">
                            <button @click="modalImportarExcel = false"
                                    class="text-sm px-4 py-2 rounded-lg border border-surface-300 hover:bg-surface-100">
                                Cerrar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Indicador auto-sync bidireccional con SIIGO -->
            <div v-if="autoSync && autoSync.ts" class="rounded-lg border border-sky-200 bg-sky-50 p-2 text-xs text-sky-800 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-sky-500 animate-pulse"></span>
                <span>
                    <strong>Auto-sync SIIGO al cargar:</strong>
                    revisé <strong>{{ autoSync.chequeados }}</strong> producto{{ autoSync.chequeados === 1 ? '' : 's' }} linkeado{{ autoSync.chequeados === 1 ? '' : 's' }},
                    <span v-if="autoSync.actualizados > 0" class="text-emerald-700">
                        <strong>{{ autoSync.actualizados }}</strong> con cambios nuevos ✓
                    </span>
                    <span v-else class="text-sky-700">ninguno cambió desde la última vista</span>
                </span>
                <span class="ml-auto text-sky-500">Refresca (F5) para re-sincronizar</span>
            </div>

            <!-- Resumen última sincronización masiva + Papelera de sync -->
            <div v-if="resumenSync" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 flex items-center gap-3 flex-wrap">
                <span class="font-bold">✓ Sincronización completa:</span>
                <span><strong>{{ resumenSync.nuevos }}</strong> nuevos</span>
                <span>·</span>
                <span><strong>{{ resumenSync.actualizados }}</strong> actualizados</span>
                <span>·</span>
                <span><strong>{{ resumenSync.linkeados }}</strong> linkeados</span>
                <span>·</span>
                <span><strong>{{ resumenSync.zombies }}</strong> marcados inactivos</span>
                <span v-if="resumenSync.errores > 0" class="text-amber-700">· <strong>{{ resumenSync.errores }}</strong> errores</span>
                <button v-if="resumenSync.nuevos > 100" type="button"
                        @click="deshacerUltimaSync"
                        :disabled="deshaciendoSync"
                        class="ml-2 text-xs inline-flex items-center gap-1 px-2 py-1 rounded border border-amber-400 text-amber-800 bg-amber-50 hover:bg-amber-100 disabled:opacity-50">
                    🗑 {{ deshaciendoSync ? 'Deshaciendo…' : 'Deshacer esta sync' }}
                </button>
                <button @click="resumenSync = null" class="ml-auto text-emerald-600 hover:text-emerald-900">✕</button>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500">Total</div>
                    <div class="text-2xl font-bold mt-1">{{ kpis.total.toLocaleString('es-CO') }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500">Activos</div>
                    <div class="text-2xl font-bold mt-1 text-emerald-600">{{ kpis.activos.toLocaleString('es-CO') }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500">Sin sincronizar SIIGO</div>
                    <div class="text-2xl font-bold mt-1 text-amber-600">{{ kpis.sin_siigo.toLocaleString('es-CO') }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500">Precio protegido</div>
                    <div class="text-2xl font-bold mt-1">{{ kpis.protegidos.toLocaleString('es-CO') }}</div>
                    <div class="text-[10px] text-surface-400">no editable al facturar</div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="card p-3">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
                    <div class="relative md:col-span-2">
                        <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-surface-400"/>
                        <input v-model="q" @input="filtrar" placeholder="Buscar por referencia, nombre o code SIIGO…"
                               class="input pl-9 w-full text-sm"/>
                    </div>
                    <select v-model="lineaId" @change="filtrar" class="input text-sm">
                        <option value="">Todas las líneas</option>
                        <option v-for="l in lineas" :key="l.id" :value="l.id">{{ l.codigo }} · {{ l.nombre }}</option>
                    </select>
                    <div class="flex gap-2">
                        <select v-model="activo" @change="filtrar" class="input text-sm flex-1">
                            <option value="">Todos</option>
                            <option value="1">Solo activos</option>
                            <option value="0">Solo inactivos</option>
                        </select>
                        <label class="flex items-center gap-1 text-xs whitespace-nowrap">
                            <input type="checkbox" v-model="sinSiigo" @change="filtrar" class="rounded"/>
                            Sin SIIGO
                        </label>
                    </div>
                </div>
            </div>

            <!-- Tabla -->
            <!-- FASE C2 UI · Barra de acciones masivas -->
            <div v-if="seleccionados.length > 0"
                 class="card p-3 border-2 border-sky-500 bg-sky-50 flex items-center gap-3 flex-wrap sticky top-16 z-20">
                <span class="font-bold text-sky-900">
                    {{ seleccionados.length }} producto{{ seleccionados.length === 1 ? '' : 's' }} seleccionado{{ seleccionados.length === 1 ? '' : 's' }}
                </span>
                <button @click="seleccionados = []"
                        class="text-xs underline text-sky-700 hover:text-sky-900">Deseleccionar</button>
                <div class="ml-auto flex items-center gap-2 flex-wrap">
                    <button @click="abrirBulkActivar" :disabled="bulkCargando"
                            class="text-xs px-2 py-1 rounded border border-emerald-500 text-emerald-700 hover:bg-emerald-50 disabled:opacity-50">
                        Activar
                    </button>
                    <button @click="abrirBulkDesactivar" :disabled="bulkCargando"
                            class="text-xs px-2 py-1 rounded border border-amber-500 text-amber-700 hover:bg-amber-50 disabled:opacity-50">
                        Desactivar
                    </button>
                    <button @click="abrirBulkDescuento" :disabled="bulkCargando"
                            class="text-xs px-2 py-1 rounded border border-violet-500 text-violet-700 hover:bg-violet-50 disabled:opacity-50">
                        Ajustar precio %
                    </button>
                    <!-- PROD-13 · bulk push a SIIGO (solo si hay al menos uno para pushear) -->
                    <button v-if="puede_administrar" @click="abrirBulkPushSiigo" :disabled="bulkCargando"
                            class="text-xs px-2 py-1 rounded border border-sky-500 text-sky-700 hover:bg-sky-50 disabled:opacity-50 inline-flex items-center gap-1">
                        <Cloud class="h-3.5 w-3.5"/> Enviar a SIIGO ({{ seleccionados.length }})
                    </button>
                    <button v-if="puede_administrar" @click="abrirBulkEliminar" :disabled="bulkCargando"
                            class="text-xs px-2 py-1 rounded border border-red-500 text-red-700 hover:bg-red-50 disabled:opacity-50">
                        🗑 Eliminar ({{ seleccionados.length }})
                    </button>
                </div>
            </div>

            <!-- Modal bulk-edit (reemplaza confirm/prompt nativos bloqueados en iframe) -->
            <div v-if="modalBulk"
                 class="fixed inset-0 bg-black/40 flex items-center justify-center z-50"
                 @click.self="cerrarBulkModal">
                <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl max-w-md w-full m-4 p-5 space-y-4">
                    <h2 class="font-bold text-lg" :class="colorModalTitulo">
                        {{ modalBulk.titulo }}
                    </h2>
                    <p class="text-sm text-surface-600">{{ modalBulk.mensaje }}</p>
                    <div v-if="modalBulk.requierePct">
                        <label class="text-xs font-semibold text-surface-600">Porcentaje</label>
                        <input v-model.number="modalBulk.pctValor" type="number" min="-100" max="100" step="0.1"
                               class="input w-full font-mono text-lg text-center"
                               @keyup.enter="modalBulk.onConfirmar(modalBulk.pctValor)"/>
                        <p class="text-[10px] text-surface-500 mt-1">
                            Ejemplos: <code>-10</code> baja 10% · <code>15</code> sube 15%
                        </p>
                    </div>
                    <div class="flex items-center gap-2 justify-end pt-2 border-t">
                        <button @click="cerrarBulkModal"
                                class="text-sm px-3 py-1.5 rounded border border-surface-300 hover:bg-surface-50">
                            Cancelar
                        </button>
                        <button @click="modalBulk.onConfirmar(modalBulk.pctValor)"
                                :disabled="bulkCargando || (modalBulk.requierePct && (modalBulk.pctValor === null || modalBulk.pctValor < -100 || modalBulk.pctValor > 100))"
                                :class="['text-sm px-3 py-1.5 rounded text-white disabled:opacity-50', colorModalBtn]">
                            {{ bulkCargando ? 'Aplicando…' : 'Confirmar' }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="card overflow-hidden">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="p-2 w-8">
                                <input type="checkbox" :checked="todosSeleccionados" @change="toggleTodos" class="rounded"/>
                            </th>
                            <th class="text-left p-2 w-32">Referencia</th>
                            <th class="text-left p-2">Nombre</th>
                            <th class="text-left p-2">Marca</th>
                            <th class="text-left p-2">Línea</th>
                            <th class="text-right p-2">Precio prov.</th>
                            <th class="text-center p-2" title="Nº de variantes activas">Var.</th>
                            <th class="text-center p-2">SIIGO</th>
                            <th class="text-left p-2 w-28" title="Última foto enviada a SIIGO">Última sync</th>
                            <th class="text-center p-2">Activo</th>
                            <th class="text-right p-2 w-24">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="p in productos.data" :key="p.id"
                            :class="['hover:bg-surface-50', seleccionados.includes(p.id) && 'bg-sky-50']">
                            <td class="p-2 text-center">
                                <input type="checkbox" :value="p.id" v-model="seleccionados" class="rounded"/>
                            </td>
                            <td class="p-2 font-mono font-bold text-brand-600">
                                <Link :href="`/app/catalogo/productos/${p.id}`" class="hover:underline">{{ p.referencia }}</Link>
                            </td>
                            <td class="p-2">
                                {{ p.nombre }}
                                <Lock v-if="p.proteger_precio" class="h-3 w-3 inline text-amber-600 ml-1" title="Precio protegido"/>
                            </td>
                            <td class="p-2 text-xs text-surface-500">{{ p.marca || '—' }}</td>
                            <td class="p-2 text-xs">{{ p.linea || '—' }}</td>
                            <td class="p-2 text-right font-mono">
                                <!-- PROD-8 · en granular el precio real vive por variante; mostrar guión evita confundir con $0. -->
                                <span v-if="p.desglose_stock" class="text-surface-400" title="Precio por variante">—</span>
                                <span v-else>{{ money(p.precio_proveedor) }}</span>
                            </td>
                            <td class="p-2 text-center text-xs">
                                <!-- PROD-12 · contador rápido de variantes activas (badge gris si 0). -->
                                <span v-if="p.variantes_count"
                                      class="inline-flex items-center justify-center min-w-[1.5rem] px-1.5 py-0.5 rounded bg-sky-50 text-sky-700 font-medium">
                                    {{ p.variantes_count }}
                                </span>
                                <span v-else class="text-surface-300">—</span>
                            </td>
                            <td class="p-2 text-center">
                                <Cloud v-if="p.siigo_id" class="h-4 w-4 inline text-emerald-600" :title="`SIIGO: ${p.siigo_code}`"/>
                                <CloudOff v-else class="h-4 w-4 inline text-amber-600" title="Pendiente sync"/>
                            </td>
                            <!-- PROD-17 · Última sync a SIIGO · texto relativo con fecha completa en tooltip. -->
                            <td class="p-2 text-xs text-surface-500" :title="p.siigo_sync_iso || 'Nunca sincronizado'">
                                {{ p.siigo_sync_at || '—' }}
                            </td>
                            <td class="p-2 text-center">
                                <span :class="p.activo ? 'text-emerald-600' : 'text-red-500'" class="text-lg">●</span>
                            </td>
                            <td class="p-2 text-right whitespace-nowrap">
                                <Link :href="`/app/catalogo/productos/${p.id}`" class="text-brand-600 hover:text-brand-700 p-1 inline-block" title="Ver/Editar">
                                    <Pencil class="h-4 w-4"/>
                                </Link>
                                <button v-if="puede_administrar" @click="eliminar(p)" class="text-red-500 hover:text-red-700 p-1" title="Eliminar">
                                    <Trash2 class="h-4 w-4"/>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!productos.data.length">
                            <td colspan="10" class="p-8 text-center text-surface-500 text-sm">
                                Sin productos con esos filtros.
                            </td>
                        </tr>
                    </tbody>
                </table>
                <!-- Paginación -->
                <div v-if="productos.last_page > 1" class="flex items-center justify-between p-3 border-t text-xs">
                    <div class="text-surface-500">{{ productos.from }}–{{ productos.to }} de {{ productos.total }}</div>
                    <div class="flex gap-1">
                        <template v-for="l in productos.links" :key="l.label">
                            <button v-if="l.url" @click="router.get(l.url, {}, { preserveScroll: true })"
                                    :class="['px-2 py-1 rounded', l.active ? 'bg-brand-600 text-white' : 'hover:bg-surface-100']"
                                    v-html="l.label"/>
                            <span v-else :class="['px-2 py-1 text-surface-400']" v-html="l.label"/>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal · Importar producto desde SIIGO por código -->
        <div v-if="modalImportar" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-6 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-lg font-bold flex items-center gap-2">
                        <CloudDownload class="h-5 w-5 text-sky-600"/>
                        Traer producto de SIIGO
                    </h3>
                    <button @click="modalImportar = false" class="text-surface-400 hover:text-surface-700">✕</button>
                </div>
                <p class="text-sm text-surface-600">
                    Pega el <strong>código</strong> (SKU) del producto que creaste en SIIGO Nube.
                    El ERP lo buscará y lo guardará aquí.
                </p>
                <input v-model="codeImport" type="text" autofocus
                       @keydown.enter="confirmarImportar"
                       placeholder="Ej: dfgi3665fgfvndf365856"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"/>
                <div v-if="resultadoImport" :class="['text-sm p-3 rounded', resultadoImport.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700']">
                    {{ resultadoImport.ok ? '✓ ' : '⚠ ' }}{{ resultadoImport.mensaje }}
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="modalImportar = false"
                            class="px-4 py-2 text-sm rounded-lg border hover:bg-surface-50">
                        Cancelar
                    </button>
                    <button type="button" @click="confirmarImportar" :disabled="importandoDeSiigo || !codeImport.trim()"
                            class="px-4 py-2 text-sm rounded-lg bg-sky-600 text-white hover:bg-sky-700 disabled:opacity-50">
                        {{ importandoDeSiigo ? 'Importando…' : 'Importar ahora' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- PROD-16 · Modal de confirmación "Traer de SIIGO" · explicita que
             nombre/precio/activo/marca se sobreescriben desde SIIGO. -->
        <div v-if="modalReconciliar"
             class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
             @click.self="modalReconciliar = false">
            <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6">
                <div class="flex items-start gap-3 mb-4">
                    <div class="p-2 bg-amber-50 rounded-lg">
                        <RefreshCw class="h-5 w-5 text-amber-600"/>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-semibold text-surface-800">Traer cambios de SIIGO</h3>
                        <p class="text-sm text-surface-500 mt-0.5">Modo incremental · solo trae lo cambiado desde la última sync.</p>
                    </div>
                </div>

                <div class="space-y-3 mb-5">
                    <div class="p-3 rounded-lg bg-amber-50/70 border border-amber-200 text-sm text-amber-800">
                        <strong>Importante:</strong> esta acción <strong>sobreescribe</strong> en el ERP el nombre, precio,
                        marca y estado activo de los productos cuyo SIIGO cambió recientemente. Si editaste algo manualmente,
                        verificá primero contra qué estás por sincronizar.
                    </div>
                    <Link href="/app/siigo/discrepancias"
                          class="flex items-start gap-2.5 p-3 rounded-lg border border-surface-200 hover:bg-surface-50">
                        <AlertTriangle class="h-4 w-4 text-amber-600 mt-0.5 shrink-0"/>
                        <div>
                            <div class="font-medium text-sm text-surface-800">Ver primero el reporte de discrepancias →</div>
                            <div class="text-xs text-surface-500">Comparar ERP vs SIIGO campo por campo antes de aplicar.</div>
                        </div>
                    </Link>
                </div>

                <div class="flex gap-2 justify-end">
                    <button @click="modalReconciliar = false"
                            class="text-sm px-4 py-2 rounded-lg text-surface-600 hover:bg-surface-100">Cancelar</button>
                    <button @click="sincronizarTodo"
                            class="text-sm px-4 py-2 rounded-lg bg-amber-600 text-white hover:bg-amber-700 inline-flex items-center gap-1.5">
                        <RefreshCw class="h-4 w-4"/> Entiendo, traer ahora
                    </button>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
