<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    RefreshCw, Link2, PackageSearch, AlertTriangle, CheckCircle2, CircleDashed, Search, Archive, X, Hourglass,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

/*
 * «Productos de Dropi» · el catálogo propio de Dropi cruzado con el ERP, y el
 * lugar donde se vincula, sin ir a «Productos sin vincular» del /admin.
 *
 * «Vincular N» hace lo seguro (misma referencia o misma variación); cada fila
 * se puede vincular a mano con «Vincular…», contra un producto agregado (todo
 * su stock en un total) o una variante. Stock y precio de Dropi se muestran,
 * no se copian: en el ERP mandan el kardex y las listas de precios.
 */
const props = defineProps({
    leido_at: { type: String, default: null },
    resumen: { type: Object, default: () => ({}) },
    filas: { type: Array, default: () => [] },
    lectura: { type: Object, default: null },
});

const { money } = useMoney();

const ESTADOS = {
    enlazar: { label: 'Se vinculan', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300', icono: Link2 },
    vinculado: { label: 'Ya vinculados', clase: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300', icono: CheckCircle2 },
    revisar: { label: 'Revisar a mano', clase: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300', icono: AlertTriangle },
    nuevo: { label: 'No están en el ERP', clase: 'bg-surface-100 text-surface-700 dark:bg-surface-800 dark:text-surface-300', icono: CircleDashed },
    sin_catalogo: { label: 'Fuera del catálogo', clase: 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-300', icono: Archive },
};

// Se arranca por lo que frena despachos: llaves con pedidos esperando.
const soloEsperando = ref((props.resumen.con_pedidos || 0) > 0);
const filtro = ref(soloEsperando.value ? null : (props.resumen.enlazar ? 'enlazar' : 'revisar'));
const buscar = ref('');
const LIMITE = 200;

const elegirEstado = (clave) => { filtro.value = clave; soloEsperando.value = false; };
const verEsperando = () => { soloEsperando.value = true; filtro.value = null; };

const visibles = computed(() => {
    const t = buscar.value.trim().toLowerCase();
    return props.filas
        .filter((f) => soloEsperando.value ? (f.pedidos_esperando > 0 && f.estado !== 'vinculado') : f.estado === filtro.value)
        .filter((f) => ! t || [f.nombre, f.sku, f.llave, f.variacion, f.destino_label].some((x) => String(x ?? '').toLowerCase().includes(t)))
        .sort((a, b) => b.pedidos_esperando - a.pedidos_esperando);
});

const leyendo = computed(() => ['en_cola', 'leyendo'].includes(props.lectura?.estado));
const leer = () => router.post('/app/dropi/catalogo/leer', {}, { preserveScroll: true });

const vinculando = ref(false);
const vincularSeguros = () => {
    if (! confirm(`Se van a vincular ${props.resumen.enlazar} llaves de Dropi con productos del ERP. Los pedidos que esperaban esos productos los toman y, si hay stock, salen de «pendiente por inventario». ¿Seguir?`)) return;
    vinculando.value = true;
    router.post('/app/dropi/catalogo/vincular', {}, { preserveScroll: true, onFinish: () => { vinculando.value = false; } });
};

// ---- Modal «Vincular…» de una llave ----
const fila = ref(null);
const q = ref('');
const opciones = ref([]);
const buscando = ref(false);
const guardando = ref(false);
let tBusqueda = null;

const abrirVincular = (f) => {
    fila.value = f;
    // Arranca buscando por el nombre de Dropi: casi siempre es el mismo producto.
    q.value = (f.nombre || '').split(' ').slice(0, 3).join(' ');
    opciones.value = [];
};
const cerrarVincular = () => { fila.value = null; };

watch(q, (v) => {
    clearTimeout(tBusqueda);
    if (! fila.value || v.trim().length < 2) { opciones.value = []; return; }
    tBusqueda = setTimeout(async () => {
        buscando.value = true;
        try {
            const { data } = await window.axios.get('/app/dropi/catalogo/buscar', { params: { q: v } });
            opciones.value = data;
        } finally {
            buscando.value = false;
        }
    }, 250);
});

const vincularA = (op) => {
    guardando.value = true;
    router.post('/app/dropi/catalogo/vincular-llave', { llave: fila.value.llave, destino: op.valor }, {
        preserveScroll: true,
        onSuccess: () => cerrarVincular(),
        onFinish: () => { guardando.value = false; },
    });
};

// Mientras Dropi se lee en segundo plano, se pregunta cada 10 s.
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (leyendo.value) router.reload({ preserveScroll: true, preserveState: true });
    }, 10000);
});
onBeforeUnmount(() => { clearInterval(timer); clearTimeout(tBusqueda); });

const fecha = (iso) => iso ? new Date(iso).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
</script>

<template>
    <Head title="Productos de Dropi"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <PackageSearch class="h-6 w-6 text-brand-600"/>
                        Productos de Dropi
                    </h1>
                    <p class="text-sm text-surface-500 dark:text-surface-400">
                        Tu catálogo en Dropi, leído por la API, cruzado con el del ERP.
                        <template v-if="leido_at"> Última lectura: <b>{{ fecha(leido_at) }}</b>.</template>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="leer" :disabled="leyendo"
                            class="text-sm inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-700 hover:bg-surface-100 dark:hover:bg-surface-800 disabled:opacity-60 whitespace-nowrap">
                        <RefreshCw :class="['h-4 w-4', leyendo ? 'animate-spin' : '']"/>
                        {{ leyendo ? 'Leyendo Dropi…' : 'Leer de Dropi' }}
                    </button>
                    <button type="button" @click="vincularSeguros" :disabled="!resumen.enlazar || vinculando"
                            class="btn-primary inline-flex items-center gap-1.5 disabled:opacity-50 whitespace-nowrap">
                        <Link2 class="h-4 w-4"/>
                        {{ vinculando ? 'Vinculando…' : `Vincular ${resumen.enlazar || 0}` }}
                    </button>
                </div>
            </div>

            <div v-if="lectura?.estado === 'error'" class="card p-3 border border-red-300 bg-red-50 dark:bg-red-950/30 text-sm">
                La última lectura falló: {{ lectura.mensaje }}
            </div>
            <div v-else-if="leyendo" class="card p-3 border border-sky-300 bg-sky-50 dark:bg-sky-950/30 text-sm">
                Leyendo el catálogo de Dropi en segundo plano. Tarda unos minutos; esta página se actualiza sola.
            </div>

            <div v-if="!leido_at" class="card p-3 text-sm text-surface-600 dark:text-surface-300">
                Todavía no se ha leído el catálogo de Dropi (<b>Leer de Dropi</b>). Mientras tanto se ven las
                llaves que esperan en pedidos y se pueden vincular igual.
            </div>

            <template v-if="filas.length || leido_at">
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
                    <button type="button" @click="verEsperando"
                            :class="['card p-4 text-left transition border border-orange-300 dark:border-orange-800', soloEsperando ? 'ring-2 ring-brand-500' : 'hover:bg-surface-50 dark:hover:bg-surface-800/50']">
                        <div class="text-xs uppercase text-orange-700 dark:text-orange-300 flex items-center gap-1.5">
                            <Hourglass class="h-3.5 w-3.5"/> Con pedidos esperando
                        </div>
                        <div class="text-2xl font-bold mt-1">{{ resumen.con_pedidos || 0 }}</div>
                    </button>
                    <button v-for="(cfg, clave) in ESTADOS" :key="clave" type="button" @click="elegirEstado(clave)"
                            :class="['card p-4 text-left transition', !soloEsperando && filtro === clave ? 'ring-2 ring-brand-500' : 'hover:bg-surface-50 dark:hover:bg-surface-800/50']">
                        <div class="text-xs uppercase text-surface-500 flex items-center gap-1.5">
                            <component :is="cfg.icono" class="h-3.5 w-3.5"/> {{ cfg.label }}
                        </div>
                        <div class="text-2xl font-bold mt-1">{{ resumen[clave] || 0 }}</div>
                    </button>
                </div>
                <p v-if="leido_at" class="text-xs text-surface-500">
                    {{ resumen.productos }} productos en Dropi · {{ resumen.llaves }} llaves (una por variación).
                    «Pedidos esperando» = pedidos pendientes que traen esa llave sin producto del ERP.
                    «No están en el ERP» se pueden vincular a mano si el producto existe con otro nombre; si no, hay que crearlo.
                </p>

                <div class="card p-0 overflow-hidden">
                    <div class="p-3 border-b border-surface-200 dark:border-surface-800 flex items-center gap-2">
                        <Search class="h-4 w-4 text-surface-400"/>
                        <input v-model="buscar" type="search" placeholder="Buscar por nombre, SKU o referencia" class="input py-1.5 text-sm flex-1"/>
                        <span class="text-xs text-surface-500 whitespace-nowrap">{{ visibles.length }} filas</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-surface-50 dark:bg-surface-800/50 text-xs uppercase text-surface-500">
                                <tr>
                                    <th class="text-left px-3 py-2">Producto en Dropi</th>
                                    <th class="text-left px-3 py-2">Llave</th>
                                    <th class="text-right px-3 py-2">Pedidos esperando</th>
                                    <th class="text-right px-3 py-2">Stock Dropi</th>
                                    <th class="text-right px-3 py-2">Precio Dropi</th>
                                    <th class="text-left px-3 py-2">En el ERP</th>
                                    <th class="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="f in visibles.slice(0, LIMITE)" :key="f.llave" class="border-t border-surface-100 dark:border-surface-800 align-top">
                                    <td class="px-3 py-2">
                                        <div class="font-medium">{{ f.nombre }}</div>
                                        <div class="text-xs text-surface-500">
                                            <span v-if="f.variacion">{{ f.variacion }}</span><span v-if="f.variacion && f.tipo"> · </span>{{ f.tipo }}<span v-if="f.sku"> · SKU {{ f.sku }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ f.llave }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums" :class="f.pedidos_esperando ? 'font-bold text-orange-700 dark:text-orange-300' : 'text-surface-400'">
                                        {{ f.pedidos_esperando || '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ f.stock_dropi ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ f.precio_dropi != null ? money(f.precio_dropi) : '—' }}</td>
                                    <td class="px-3 py-2">
                                        <span :class="['inline-block text-[11px] px-1.5 py-0.5 rounded font-semibold', ESTADOS[f.estado].clase]">{{ ESTADOS[f.estado].label }}</span>
                                        <div v-if="f.destino_label" class="text-xs mt-0.5">{{ f.destino_label }}</div>
                                        <div v-if="f.motivo" class="text-xs text-surface-500">{{ f.motivo }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <button type="button" @click="abrirVincular(f)"
                                                class="text-xs px-2 py-1 rounded border border-brand-500 text-brand-700 dark:text-brand-300 hover:bg-brand-50 dark:hover:bg-brand-950/30 whitespace-nowrap">
                                            {{ f.estado === 'vinculado' ? 'Cambiar…' : 'Vincular…' }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="visibles.length > LIMITE" class="p-3 text-xs text-surface-500 border-t border-surface-100 dark:border-surface-800">
                        Se muestran {{ LIMITE }} de {{ visibles.length }}. Usa el buscador para encontrar uno.
                    </p>
                    <p v-if="!visibles.length" class="p-6 text-center text-sm text-surface-500">Nada en este grupo.</p>
                </div>
            </template>
        </div>

        <!-- Modal «Vincular…» -->
        <div v-if="fila" class="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-4 bg-black/40" @click.self="cerrarVincular">
            <div class="card w-full max-w-xl p-4 space-y-3 max-h-[90vh] flex flex-col">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Vincular con el ERP</h2>
                        <p class="text-xs text-surface-500">
                            {{ fila.nombre }}<span v-if="fila.variacion"> · {{ fila.variacion }}</span> · llave <span class="font-mono">{{ fila.llave }}</span>
                            <span v-if="fila.pedidos_esperando"> · {{ fila.pedidos_esperando }} pedido(s) esperando</span>
                        </p>
                    </div>
                    <button type="button" @click="cerrarVincular" class="btn-ghost p-1" aria-label="Cerrar"><X class="h-4 w-4"/></button>
                </div>
                <input v-model="q" type="search" class="input w-full" placeholder="Busca por nombre, referencia o código de barras" autofocus/>
                <div class="overflow-y-auto min-h-[6rem]">
                    <p v-if="buscando" class="text-sm text-surface-500 p-2">Buscando…</p>
                    <p v-else-if="q.trim().length >= 2 && !opciones.length" class="text-sm text-surface-500 p-2">Nada con ese texto en el ERP.</p>
                    <button v-for="op in opciones" :key="op.valor" type="button" @click="vincularA(op)" :disabled="guardando"
                            class="w-full text-left px-3 py-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 text-sm flex items-center gap-2 disabled:opacity-50">
                        <span :class="['text-[10px] uppercase font-bold px-1.5 py-0.5 rounded', op.agregado ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300']">
                            {{ op.agregado ? 'Producto' : 'Variante' }}
                        </span>
                        <span class="flex-1">{{ op.label }}</span>
                    </button>
                </div>
                <p class="text-xs text-surface-500">
                    «Producto» = lleva el stock en un solo total: todas las variaciones de Dropi descuentan de ahí.
                    Los pedidos que esperaban esta llave la toman al instante y, si hay stock, salen de «pendiente por inventario».
                </p>
            </div>
        </div>
    </AppLayout>
</template>
