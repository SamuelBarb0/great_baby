<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import {
    ShoppingCart, Ship, Package, DollarSign, Plus, Globe,
    Eye, Copy, Ban, Truck, Cloud, RefreshCw, Search, Download,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import KpiCard from '@/Components/KpiCard.vue';
import { useMoney } from '@/composables/useMoney';
import { fechaCorta } from '@/composables/useFecha';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppPromptModal from '@/Components/AppPromptModal.vue';
import { useEscClose } from '@/composables/useEscClose';

const props = defineProps({
    tab: { type: String, default: 'ordenes' },
    // Si el eje fiscal es editable para quien mira. Por defecto no: mejor que
    // se vea de sólo lectura a que se ofrezca y despues de 403.
    puede_destino: { type: Boolean, default: false },
    kpis: { type: Object, required: true },
    // Paginadores. Solo llega el de la pestana activa: antes se armaban las
    // tres consultas en cada carga, con sus relaciones, para mostrar una.
    ordenes: { type: Object, default: null },
    importaciones: { type: Object, default: null },
    recepciones: { type: Object, default: null },
    filtros: { type: Object, default: () => ({ q: '', desde: null, hasta: null, estado: 'todos' }) },
    estados: { type: Object, default: () => ({ ordenes: [], importaciones: [], recepciones: [] }) },
    opcionesEjes: { type: Object, default: () => ({ lineas: [], destinos: [] }) },
    // Sin «reparto»: en compras es fiscal o interna.
    destinosDeCompra: { type: Array, default: () => [] },
});

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);
// Pedido de motivo (anulaciones), en vez del prompt() nativo.
const modalMotivo = ref(null);
const tabAct = ref(props.tab);
const { money } = useMoney();

/*
 * F2c · Filtros de verdad.
 *
 * Antes cada pestana traia las ultimas 30 o 50 filas y nada mas: buscar una OC
 * de marzo era imposible desde aca. El filtro se aplica a la pestana ACTIVA, y
 * cambiar de pestana ahora va al servidor porque solo se consulta la que se ve.
 */
const q = ref(props.filtros.q || '');
const desde = ref(props.filtros.desde || '');
const hasta = ref(props.filtros.hasta || '');
const estado = ref(props.filtros.estado || 'todos');
const linea = ref(props.filtros.linea || 'todas');
const destino = ref(props.filtros.destino || 'todas');

const parametros = () => ({
    tab: tabAct.value, q: q.value, desde: desde.value, hasta: hasta.value, estado: estado.value,
    linea: linea.value, destino: destino.value,
});

const consultar = () => router.get('/app/compras', parametros(), {
    preserveState: true, preserveScroll: true, replace: true,
});

const buscar = useDebounceFn(consultar, 400);
watch(q, buscar);
watch([desde, hasta, estado, linea, destino], consultar);

const irA = (tab) => {
    tabAct.value = tab;
    // El estado es de cada entidad: «aprobada» no existe en importaciones, y
    // arrastrarlo deja la pestana nueva vacia sin motivo visible.
    estado.value = 'todos';
    consultar();
};

const pagina = computed(() => props[tabAct.value] ?? null);
const estadosDeLaPestana = computed(() => props.estados[tabAct.value] ?? []);

/*
 * Una importacion es un contenedor en ruta: lo que se declara o no son las
 * compras que vienen adentro, no el viaje. Por eso el filtro de los dos ejes
 * no se muestra en esa pestana.
 */
const tieneEjes = computed(() => tabAct.value !== 'importaciones');

/* ── Cambiar si una compra se declara ────────────────────────────────────── */
const modalDestino = ref(null);
useEscClose(modalDestino);   // { orden, destino }
const motivoDestino = ref('');
const guardandoDestino = ref(false);

const pedirDestino = (orden, nuevoDestino) => {
    if (orden.destino === nuevoDestino) return;
    motivoDestino.value = '';
    modalDestino.value = { orden, destino: nuevoDestino };
};

const confirmarDestino = () => {
    if (! modalDestino.value || guardandoDestino.value) return;
    guardandoDestino.value = true;
    router.post('/app/compras/destino', {
        orden_id: modalDestino.value.orden.id,
        destino: modalDestino.value.destino,
        motivo: motivoDestino.value || null,
    }, {
        preserveScroll: true,
        onFinish: () => { guardandoDestino.value = false; },
        onSuccess: () => { modalDestino.value = null; },
    });
};

const labelDestino = (valor) => props.destinosDeCompra.find(d => d.valor === valor)?.label ?? valor;

const hrefExport = computed(() => {
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(parametros())) {
        if (v && v !== 'todos') p.set(k, v);
    }
    p.set('tab', tabAct.value);
    return `/app/compras/csv?${p.toString()}`;
});

const badge = (color) => ({
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    success: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    danger: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    gray: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    amber: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    emerald: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
}[color] || 'bg-slate-100');

// Sprint 3 · A.1 · Acciones CRUD por fila de OC.
const anulando = ref(null);
const duplicando = ref(null);

const duplicar = (o) => {
    modalConfirm.value = {
        titulo: `¿Duplicar OC ${o.numero}?`,
        mensaje: `Se crea una nueva OC en borrador con los mismos ítems y proveedor.`,
        color: 'sky',
        textoConfirmar: 'Duplicar',
        onConfirmar: () => {
            modalConfirm.value = null;
            duplicando.value = o.id;
            router.post(`/app/compras/oc/${o.id}/duplicar`, {}, {
                preserveScroll: true,
                onFinish: () => { duplicando.value = null; },
            });
        },
    };
};

const anular = (o) => {
    // Antes era un prompt() nativo: dentro del iframe de la app de escritorio
    // devuelve null al instante, así que anular no hacía nada y sin aviso.
    modalMotivo.value = {
        titulo: `Anular la OC ${o.numero}`,
        mensaje: 'Queda registrado quién la anuló y cuándo. No se puede deshacer.',
        etiqueta: 'Motivo de la anulación',
        minimo: 10,
        color: 'rose',
        textoConfirmar: 'Anular',
        onConfirmar: (motivo) => {
            modalMotivo.value = null;
            anulando.value = o.id;
            router.post(`/app/compras/oc/${o.id}/anular`, { motivo }, {
                preserveScroll: true,
                onFinish: () => { anulando.value = null; },
            });
        },
    };
};

// Sprint 3 · D.1 · reenviar recepción a SIIGO.
const reenviandoSiigo = ref(null);
const reenviarSiigoRecepcion = (r) => {
    modalConfirm.value = {
        titulo: `¿Reenviar recepción ${r.orden_numero} a SIIGO?`,
        mensaje: `Esto encola un nuevo push manual (bypasea el kill-switch).`,
        color: 'sky',
        textoConfirmar: 'Reenviar',
        onConfirmar: () => {
            modalConfirm.value = null;
            reenviandoSiigo.value = r.id;
            router.post(`/app/compras/oc/${r.orden_id}/reenviar-siigo`, {}, {
                preserveScroll: true,
                onFinish: () => { reenviandoSiigo.value = null; },
            });
        },
    };
};

const iconoSiigo = (color) => ({
    emerald: '🟢',
    amber: '🟡',
    red: '🔴',
    gray: '⚪',
}[color] || '⚪');
</script>

<template>
    <Head title="Compras e importaciones"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <ShoppingCart class="h-6 w-6 text-brand-600"/>
                        Compras e importaciones
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">Órdenes de compra, contenedores, recepciones a bodega.</p>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <Link href="/app/compras/oc/nueva" class="btn-primary text-sm">
                        <Plus class="h-4 w-4"/> Nueva OC
                    </Link>
                    <Link href="/app/compras/importacion/nueva" class="btn-ghost text-sm">
                        <Globe class="h-4 w-4"/> Nueva importación
                    </Link>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <KpiCard label="OC abiertas" :value="kpis.oc_abiertas" color="amber" :icon="ShoppingCart"/>
                <KpiCard label="Valor por recibir" :value="kpis.oc_valor_pendiente" color="blue" format="money" :icon="DollarSign"/>
                <KpiCard label="Contenedores en ruta" :value="kpis.contenedores_en_ruta" color="purple" :icon="Ship"/>
                <KpiCard label="Recepciones del mes" :value="kpis.recepciones_mes" color="emerald" :icon="Package"/>
            </div>

            <!-- Tabs · navegan al servidor: solo se consulta la que se ve -->
            <div class="flex items-center gap-2 border-b border-surface-200 dark:border-surface-800">
                <button v-for="tb in [['ordenes','Órdenes'],['importaciones','Importaciones'],['recepciones','Recepciones']]"
                        :key="tb[0]" @click="irA(tb[0])"
                        :class="['px-4 py-2 text-sm font-semibold border-b-2', tabAct===tb[0] ? 'border-brand-500 text-brand-600' : 'border-transparent text-surface-500 hover:text-surface-800 dark:hover:text-surface-200']">
                    {{ tb[1] }}
                </button>
            </div>

            <!-- Filtros de la pestaña activa -->
            <div class="card p-3 flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[220px]">
                    <label class="text-xs font-semibold text-surface-500">Buscar</label>
                    <div class="relative mt-1">
                        <Search class="h-4 w-4 absolute left-3 top-1/2 -translate-y-1/2 text-surface-400"/>
                        <input v-model="q" type="search" class="input w-full pl-10"
                               placeholder="Número, proveedor, contenedor…"/>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-semibold text-surface-500">Estado</label>
                    <select v-model="estado" class="input mt-1">
                        <option v-for="e in estadosDeLaPestana" :key="e.valor" :value="e.valor">{{ e.label }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold text-surface-500">Desde</label>
                    <input v-model="desde" type="date" class="input mt-1"/>
                </div>
                <div>
                    <label class="text-xs font-semibold text-surface-500">Hasta</label>
                    <input v-model="hasta" type="date" class="input mt-1"/>
                </div>
                <template v-if="tieneEjes">
                    <div>
                        <label class="text-xs font-semibold text-surface-500">Línea de negocio</label>
                        <select v-model="linea" class="input mt-1">
                            <option value="todas">Todas</option>
                            <option v-for="l in opcionesEjes.lineas" :key="l.valor" :value="l.valor">{{ l.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-500">Destino fiscal</label>
                        <select v-model="destino" class="input mt-1">
                            <option v-for="d in opcionesEjes.destinos" :key="d.valor" :value="d.valor">{{ d.label }}</option>
                        </select>
                    </div>
                </template>
                <a :href="hrefExport" class="btn-secondary text-sm inline-flex items-center gap-1"
                   title="Baja exactamente lo que estás viendo, con el filtro aplicado">
                    <Download class="h-4 w-4"/> Exportar
                </a>
            </div>

            <div v-if="pagina" class="text-xs text-surface-500">
                {{ pagina.total.toLocaleString('es-CO') }} resultado(s)
                <span v-if="pagina.total > pagina.data.length"> · mostrando {{ pagina.from }}–{{ pagina.to }}</span>
            </div>

            <!-- Órdenes con acciones CRUD -->
            <div v-if="tabAct==='ordenes' && ordenes" class="card overflow-hidden">
                <div v-if="!ordenes.data.length" class="text-center py-12 text-surface-500 text-sm">Ninguna orden de compra coincide con el filtro.</div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[900px] text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900"><tr class="text-surface-500 text-xs uppercase">
                            <th class="text-left px-4 py-2">Número</th>
                            <th class="text-left">Proveedor</th>
                            <th class="text-left">Bodega</th>
                            <th class="text-right">Emisión</th>
                            <th class="text-right">Esperada</th>
                            <th class="text-right">Total</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center">Destino</th>
                            <th class="text-right pr-4">Acciones</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="o in ordenes.data" :key="o.id" class="border-t border-surface-100 dark:border-surface-900 hover:bg-surface-50 dark:hover:bg-surface-900/50">
                                <td class="px-4 py-2 font-mono font-semibold">
                                    <Link :href="`/app/compras/oc/${o.id}`" class="text-brand-600 hover:underline">{{ o.numero }}</Link>
                                </td>
                                <td>{{ o.proveedor || '—' }}</td>
                                <td class="text-xs text-surface-500">{{ o.bodega || '—' }}</td>
                                <td class="text-right text-surface-500 text-xs">{{ fechaCorta(o.fecha_emision) }}</td>
                                <td class="text-right text-surface-500 text-xs">{{ fechaCorta(o.fecha_esperada) }}</td>
                                <td class="text-right font-bold font-mono">{{ money(o.total) }}</td>
                                <td class="text-center"><span :class="['px-2 py-0.5 rounded text-xs font-bold', badge(o.estado_color)]">{{ o.estado_label }}</span></td>
                                <!--
                                    Si la compra se declara o no. Se decide aca,
                                    en la orden, que es cuando se sabe si el
                                    proveedor va a facturar; las recepciones que
                                    todavia no viajaron a SIIGO lo heredan.
                                -->
                                <td class="text-center">
                                    <select :value="o.destino"
                                            :disabled="! puede_destino"
                                            :title="puede_destino ? 'Cambiar si esta compra se declara' : 'Sólo contabilidad decide si una compra se declara'"
                                            @change="pedirDestino(o, $event.target.value)"
                                            :class="['text-xs font-bold rounded px-2 py-1 border-0 cursor-pointer', badge(o.destino_color)]">
                                        <option v-for="d in destinosDeCompra" :key="d.valor" :value="d.valor">{{ d.label }}</option>
                                    </select>
                                    <div class="text-[10px] text-surface-400">{{ o.linea_label }}</div>
                                </td>
                                <td class="text-right pr-4 whitespace-nowrap">
                                    <Link :href="`/app/compras/oc/${o.id}`" class="text-brand-600 hover:text-brand-700 p-1 inline-block" title="Ver detalle">
                                        <Eye class="h-4 w-4"/>
                                    </Link>
                                    <button v-if="o.puede_recibir" @click="router.get(`/app/compras/recepcion/nueva?oc=${o.id}`)" class="text-emerald-600 hover:text-emerald-700 p-1" title="Recibir mercancía">
                                        <Truck class="h-4 w-4"/>
                                    </button>
                                    <button v-if="o.puede_duplicar" @click="duplicar(o)" :disabled="duplicando === o.id" class="text-blue-600 hover:text-blue-700 p-1" :class="duplicando === o.id ? 'opacity-50 cursor-wait' : ''" title="Duplicar">
                                        <Copy class="h-4 w-4"/>
                                    </button>
                                    <button v-if="o.puede_anular" @click="anular(o)" :disabled="anulando === o.id" class="text-red-500 hover:text-red-700 p-1" :class="anulando === o.id ? 'opacity-50 cursor-wait' : ''" title="Anular OC">
                                        <Ban class="h-4 w-4"/>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Importaciones -->
            <div v-if="tabAct==='importaciones' && importaciones" class="card overflow-hidden">
                <div v-if="!importaciones.data.length" class="text-center py-12 text-surface-500 text-sm">
                    Ninguna importación coincide con el filtro.
                    <div class="mt-3"><Link href="/app/compras/importacion/nueva" class="btn-primary text-xs"><Plus class="h-3 w-3"/> Crear la primera</Link></div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[900px] text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900"><tr class="text-surface-500 text-xs uppercase">
                            <th class="text-left px-4 py-2">Número</th>
                            <th class="text-left">Contenedor</th>
                            <th class="text-left">Origen</th>
                            <th class="text-right">Zarpe</th>
                            <th class="text-right">ETA</th>
                            <th class="text-right">Llegada</th>
                            <th class="text-right">Costo total</th>
                            <th class="text-center">Estado</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="i in importaciones.data" :key="i.id" class="border-t border-surface-100 dark:border-surface-900 hover:bg-surface-50 dark:hover:bg-surface-900/50">
                                <td class="px-4 py-2 font-mono font-semibold">
                                    <Link :href="`/app/compras/importacion/${i.id}`" class="text-brand-600 hover:underline">{{ i.numero }}</Link>
                                </td>
                                <td class="text-xs font-mono">{{ i.contenedor || '—' }}</td>
                                <td class="text-xs">{{ i.proveedor_pais || '—' }} → {{ i.puerto_destino || '—' }}</td>
                                <td class="text-right text-xs text-surface-500">{{ fechaCorta(i.zarpe) }}</td>
                                <td class="text-right text-xs text-surface-500">{{ fechaCorta(i.eta) }}</td>
                                <td class="text-right text-xs text-surface-500">{{ fechaCorta(i.llegada) }}</td>
                                <td class="text-right font-bold font-mono">{{ i.valor_total_costo > 0 ? money(i.valor_total_costo) : '—' }}</td>
                                <td class="text-center"><span :class="['px-2 py-0.5 rounded text-xs font-bold', badge(i.estado_color)]">{{ i.estado_label }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recepciones · con badge SIIGO y botón reenviar (D.1) -->
            <div v-if="tabAct==='recepciones' && recepciones" class="card overflow-hidden">
                <div v-if="!recepciones.data.length" class="text-center py-12 text-surface-500 text-sm">Ninguna recepción coincide con el filtro.</div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[900px] text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900"><tr class="text-surface-500 text-xs uppercase">
                            <th class="text-left px-4 py-2">Fecha</th>
                            <th class="text-left">OC</th>
                            <th class="text-left">Estado</th>
                            <th class="text-center">Destino</th>
                            <th class="text-left">Recibió</th>
                            <th class="text-left">SIIGO</th>
                            <th class="text-left">Observaciones</th>
                            <th class="text-right pr-4">Acciones</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="r in recepciones.data" :key="r.id" class="border-t border-surface-100 dark:border-surface-900 hover:bg-surface-50 dark:hover:bg-surface-900/50">
                                <td class="px-4 py-2 text-surface-500">{{ fechaCorta(r.fecha_recepcion) }}</td>
                                <td class="font-mono">
                                    <Link :href="`/app/compras/recepcion/${r.id}`" class="text-brand-600 hover:underline">{{ r.orden_numero || 'R#'+r.id }}</Link>
                                </td>
                                <td class="capitalize">
                                    <span class="px-2 py-0.5 rounded text-xs font-bold" :class="r.estado === 'confirmada' ? badge('success') : badge('warning')">{{ r.estado }}</span>
                                </td>
                                <!-- Solo lectura: lo manda la orden. Cambiarlo
                                     recepcion por recepcion dejaria una orden
                                     mitad declarada y mitad no, sin decision
                                     detras. -->
                                <td class="text-center">
                                    <span :class="['px-2 py-0.5 rounded text-[10px] font-bold', badge(r.destino_color)]">
                                        {{ r.destino_label }}
                                    </span>
                                    <div class="text-[10px] text-surface-400">{{ r.linea_label }}</div>
                                </td>
                                <td class="text-xs">{{ r.receptor }}</td>
                                <td class="text-xs">
                                    <span :title="`Estado sync SIIGO · ${r.siigo_sync_hace || 'sin sincronizar'}`">
                                        {{ iconoSiigo(r.siigo_color) }}
                                        <span v-if="r.siigo_number" class="font-mono text-brand-600 ml-1">{{ r.siigo_number }}</span>
                                        <span v-else class="text-surface-400 ml-1">pendiente</span>
                                    </span>
                                </td>
                                <td class="text-xs text-surface-500 max-w-xs truncate" :title="r.observaciones">{{ r.observaciones || '—' }}</td>
                                <td class="text-right pr-4 whitespace-nowrap">
                                    <Link :href="`/app/compras/recepcion/${r.id}`" class="text-brand-600 hover:text-brand-700 p-1 inline-block" title="Ver detalle">
                                        <Eye class="h-4 w-4"/>
                                    </Link>
                                    <button v-if="r.puede_reenviar_siigo" @click="reenviarSiigoRecepcion(r)" :disabled="reenviandoSiigo === r.id"
                                            class="text-blue-600 hover:text-blue-700 p-1" :class="reenviandoSiigo === r.id ? 'opacity-50 cursor-wait' : ''"
                                            title="Reenviar a SIIGO">
                                        <RefreshCw :class="['h-4 w-4', reenviandoSiigo === r.id ? 'animate-spin' : '']"/>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Paginación · una sola para las tres pestañas, porque sólo hay
                 un paginador vivo a la vez -->
            <div v-if="pagina && pagina.total > pagina.data.length"
                 class="card flex items-center justify-between px-4 py-3 text-sm">
                <div class="text-surface-500">{{ pagina.from }}–{{ pagina.to }} de {{ pagina.total.toLocaleString('es-CO') }}</div>
                <div class="flex items-center gap-1">
                    <template v-for="link in pagina.links" :key="link.label">
                        <Link v-if="link.url" :href="link.url" preserve-scroll
                              :class="['px-2 py-1 rounded text-xs',
                                       link.active ? 'bg-brand-600 text-white' : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800']"
                              v-html="link.label"/>
                        <span v-else class="px-2 py-1 rounded text-xs text-surface-400" v-html="link.label"/>
                    </template>
                </div>
            </div>
        </div>
        <!-- Sacar una compra de lo fiscal exige decir por que -->
        <div v-if="modalDestino" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
             @click.self="modalDestino = null">
            <div class="card p-5 w-full max-w-md space-y-3">
                <h3 class="font-bold text-lg">
                    Orden {{ modalDestino.orden.numero }} → {{ labelDestino(modalDestino.destino) }}
                </h3>

                <div v-if="modalDestino.destino !== 'siigo'"
                     class="text-sm bg-amber-50 dark:bg-amber-900/20 border-l-4 border-amber-500 p-3">
                    Esta compra deja de ir a SIIGO: no se va a poder descontar su IVA, porque no hay
                    factura del proveedor con qué soportarlo. Escribí por qué — es lo que permite
                    explicarlo dentro de tres meses.
                </div>
                <div v-else class="text-sm bg-emerald-50 dark:bg-emerald-900/20 border-l-4 border-emerald-500 p-3">
                    Esta compra vuelve a lo fiscal y va a viajar a SIIGO cuando se confirme la recepción.
                </div>

                <div>
                    <label class="text-xs font-semibold text-surface-500">
                        Motivo {{ modalDestino.destino === 'siigo' ? '(opcional)' : '*' }}
                    </label>
                    <textarea v-model="motivoDestino" rows="3" maxlength="300" class="input w-full mt-1"
                              placeholder="Ej.: proveedor sin RUT · compra de contado sin factura"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button @click="modalDestino = null" class="btn-secondary text-sm">Cancelar</button>
                    <button @click="confirmarDestino"
                            :disabled="guardandoDestino || (modalDestino.destino !== 'siigo' && ! motivoDestino.trim())"
                            class="btn-primary text-sm disabled:opacity-40">
                        {{ guardandoDestino ? 'Guardando…' : 'Confirmar' }}
                    </button>
                </div>
            </div>
        </div>

        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
        <AppPromptModal :cfg="modalMotivo" @cerrar="modalMotivo = null"/>
    </AppLayout>
</template>
