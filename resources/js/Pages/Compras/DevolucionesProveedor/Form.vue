<script setup>
import { ref, reactive, computed } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, Link, router } from '@inertiajs/vue3';
import { Undo2, ArrowLeft, Plus, Trash2, Search, Send, Cloud, Clock, RefreshCw, X, Eye, CheckCircle2, AlertCircle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import { mensajeDeError } from '@/composables/useMensajeError';
import axios from 'axios';

/*
 * La fecha de HOY en Colombia, no en UTC.
 *
 * `new Date().toISOString().slice(0,10)` convierte a UTC antes de
 * recortar: entre las 19:00 y la medianoche hora Bogotá devuelve el día
 * siguiente. Acá eso no era un filtro incómodo sino la fecha que queda
 * escrita en el documento.
 */
const { hoy: hoyBogota } = useBogotaDate();

const { money: formato } = useMoney();

const props = defineProps({
    devolucion: { type: Object, default: null },
    proveedores: { type: Array, default: () => [] },
    ubicaciones: { type: Array, default: () => [] },
});

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

const esNueva = computed(() => !props.devolucion?.id);
const esBorrador = computed(() => !props.devolucion || props.devolucion.estado === 'borrador');
const flash = ref(null);
const guardando = ref(false);
const confirmando = ref(false);

const header = reactive({
    id: props.devolucion?.id ?? null,
    proveedor_id: props.devolucion?.proveedor_id ?? null,
    ubicacion_id: props.devolucion?.ubicacion_id ?? null,
    fecha: props.devolucion?.fecha ?? hoyBogota(),
    motivo: props.devolucion?.motivo ?? '',
});

// FIX-S0 · items y totales deben seguir reactivamente al prop (router.reload
// trae nuevos valores). Antes `ref(...)` capturaba el array inicial y los
// cambios post-reload no se reflejaban en la tabla.
const items = computed(() => props.devolucion?.items ?? []);
const totales = computed(() => ({
    subtotal: props.devolucion?.subtotal ?? 0,
    iva: props.devolucion?.iva ?? 0,
    total: props.devolucion?.total ?? 0,
}));

// --- Autocomplete items ---
const busqueda = ref('');
const resultados = ref([]);
const buscando = ref(false);
let timerBusqueda = null;
const buscar = () => {
    clearTimeout(timerBusqueda);
    if (busqueda.value.length < 2 || !header.ubicacion_id) { resultados.value = []; return; }
    timerBusqueda = setTimeout(async () => {
        buscando.value = true;
        try {
            const { data } = await axios.get('/app/compras/devoluciones/items/buscar', {
                params: { q: busqueda.value, ubicacion_id: header.ubicacion_id },
            });
            resultados.value = data;
        } finally { buscando.value = false; }
    }, 220);
};

const nuevoItem = reactive({ variante_id: null, producto_id: null, sku: '', nombre: '', detalle: '', cantidad: 1, costo_unit: 0, iva_pct: 19, motivo_item: '' });

const seleccionarItem = (r) => {
    nuevoItem.variante_id = r.variante_id;
    nuevoItem.producto_id = r.producto_id;
    nuevoItem.sku = r.sku;
    nuevoItem.nombre = r.nombre;
    nuevoItem.detalle = r.detalle;
    // FIX-S0 · autollenar costo con el promedio ponderado de la bodega
    // (antes el usuario debía memorizarlo). Puede editarlo si no coincide.
    if (r.costo_promedio && !nuevoItem.costo_unit) {
        nuevoItem.costo_unit = r.costo_promedio;
    }
    busqueda.value = '';
    resultados.value = [];
};

// FIX-S0 · extrae mensaje legible de una respuesta Laravel 422 que trae
// `errors: {campo: [msg]}` (antes se ignoraba y solo se veía "The given…").
const errorLegible = (e, fallback = 'Ocurrió un error.') => {
    const data = e.response?.data;
    if (data?.errors) {
        return Object.values(data.errors).flat().join(' · ');
    }
    return mensajeDeError(e, fallback);
};

const mostrarFlash = (type, message) => {
    flash.value = { type, message };
    setTimeout(() => { flash.value = null; }, 5000);
};

const guardarHeader = async () => {
    guardando.value = true;
    try {
        const { data } = await axios.post('/app/compras/devoluciones', header);
        if (data.ok) {
            if (esNueva.value) {
                router.visit(`/app/compras/devoluciones/${data.id}`);
            } else {
                mostrarFlash('success', data.mensaje);
            }
        }
    } catch (e) {
        mostrarFlash('error', errorLegible(e));
    } finally { guardando.value = false; }
};

const agregarItem = async () => {
    if (!header.id) { mostrarFlash('error', 'Primero guardá el encabezado.'); return; }
    if (!nuevoItem.variante_id && !nuevoItem.producto_id) { mostrarFlash('error', 'Elegí un producto del buscador primero.'); return; }
    if (!nuevoItem.cantidad || nuevoItem.cantidad <= 0) { mostrarFlash('error', 'Cantidad inválida.'); return; }
    try {
        const { data } = await axios.post(`/app/compras/devoluciones/${header.id}/items`, nuevoItem);
        if (data.ok) {
            // FIX-S0 · totales ya es computed sobre props.devolucion, no se escribe.
            router.reload({ only: ['devolucion'] });
            // Reset
            Object.assign(nuevoItem, { variante_id: null, producto_id: null, sku: '', nombre: '', detalle: '', cantidad: 1, costo_unit: 0, iva_pct: 19, motivo_item: '' });
            mostrarFlash('success', data.mensaje);
        }
    } catch (e) {
        mostrarFlash('error', errorLegible(e));
    }
};

const eliminarItem = async (itemId) => {
    modalConfirm.value = {
        titulo: '¿Eliminar este ítem?',
        mensaje: 'Se quita de la devolución. Podés volver a agregarlo.',
        color: 'rose',
        textoConfirmar: 'Eliminar',
        onConfirmar: async () => {
            modalConfirm.value = null;
            try {
                const { data } = await axios.delete(`/app/compras/devoluciones/${header.id}/items/${itemId}`);
                if (data.ok) {
                    router.reload({ only: ['devolucion'] });
                }
            } catch (e) { mostrarFlash('error', errorLegible(e)); }
        },
    };
};

// COMP-B2 · preview de asiento antes de ejecutar la confirmación.
const preview = ref(null);
const cargandoPreview = ref(false);
const abrirPreview = async () => {
    if (!items.value.length) { mostrarFlash('error', 'La devolución no tiene ítems.'); return; }
    cargandoPreview.value = true;
    try {
        const { data } = await axios.get(`/app/compras/devoluciones/${header.id}/preview-asiento`);
        if (data.ok) {
            preview.value = data;
        } else {
            mostrarFlash('error', data.mensaje);
        }
    } catch (e) {
        mostrarFlash('error', errorLegible(e));
    } finally { cargandoPreview.value = false; }
};
const confirmar = async () => {
    confirmando.value = true;
    try {
        const { data } = await axios.post(`/app/compras/devoluciones/${header.id}/confirmar`);
        if (data.ok) {
            preview.value = null;
            mostrarFlash('success', data.mensaje);
            setTimeout(() => router.reload({ only: ['devolucion'] }), 1500);
        } else {
            mostrarFlash('error', data.mensaje);
        }
    } catch (e) {
        mostrarFlash('error', errorLegible(e));
    } finally { confirmando.value = false; }
};

const reenviarSiigo = async () => {
    try {
        const { data } = await axios.post(`/app/compras/devoluciones/${header.id}/reenviar-siigo`);
        mostrarFlash(data.ok ? 'success' : 'error', data.mensaje);
    } catch (e) { mostrarFlash('error', errorLegible(e)); }
};

// FIX-S0 · descartar borrador cuando el usuario abandona la devolución.
const descartar = async () => {
    modalConfirm.value = {
        titulo: `¿Descartar el borrador ${props.devolucion.numero}?`,
        mensaje: 'Se eliminan todos los ítems cargados. No se puede deshacer.',
        color: 'rose',
        textoConfirmar: 'Descartar',
        onConfirmar: async () => {
            modalConfirm.value = null;
            try {
                const { data } = await axios.delete(`/app/compras/devoluciones/${header.id}`);
                if (data.ok) {
                    router.visit('/app/compras/devoluciones');
                }
            } catch (e) { mostrarFlash('error', errorLegible(e)); }
        },
    };
};
</script>

<template>
    <Head :title="esNueva ? 'Nueva devolución' : `Devolución ${devolucion.numero}`"/>
    <AppLayout>
        <div class="max-w-6xl mx-auto space-y-4">
            <div class="flex items-center gap-2">
                <Link href="/app/compras/devoluciones" class="text-sm text-surface-500 hover:text-brand-600 inline-flex items-center gap-1">
                    <ArrowLeft class="h-4 w-4"/> Volver al listado
                </Link>
            </div>

            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Undo2 class="h-6 w-6 text-brand-600"/>
                        {{ esNueva ? 'Nueva devolución a proveedor' : `Devolución ${devolucion.numero}` }}
                    </h1>
                    <p v-if="devolucion" class="text-sm text-surface-500 mt-1">
                        Estado:
                        <span :class="{'text-emerald-700': devolucion.estado==='confirmada', 'text-surface-600': devolucion.estado==='borrador'}" class="font-bold uppercase">{{ devolucion.estado }}</span>
                        <span v-if="devolucion.siigo_id" class="ml-2 inline-flex items-center gap-1 text-[11px] bg-emerald-50 text-emerald-700 px-1.5 py-0.5 rounded border border-emerald-200">
                            <Cloud class="h-3 w-3"/> NC SIIGO {{ devolucion.siigo_id }} · {{ devolucion.siigo_sync_at }}
                        </span>
                        <span v-else-if="devolucion.estado==='confirmada'" class="ml-2 inline-flex items-center gap-1 text-[11px] bg-amber-50 text-amber-700 px-1.5 py-0.5 rounded border border-amber-200">
                            <Clock class="h-3 w-3"/> enviando a SIIGO…
                        </span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button v-if="devolucion?.estado==='borrador'" @click="descartar"
                            class="text-sm inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-rose-500 text-rose-700 hover:bg-rose-50"
                            title="Elimina este borrador y sus items">
                        <X class="h-4 w-4"/> Descartar borrador
                    </button>
                    <button v-if="devolucion?.estado==='confirmada' && !devolucion?.siigo_id"
                            @click="reenviarSiigo"
                            class="text-sm inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-emerald-500 text-emerald-700 hover:bg-emerald-50">
                        <RefreshCw class="h-4 w-4"/> Reenviar a SIIGO
                    </button>
                </div>
            </div>

            <div v-if="flash" :class="['card p-3 border-l-4', flash.type==='success' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-rose-500 bg-rose-50 text-rose-700']">
                {{ flash.message }}
            </div>

            <!-- Encabezado -->
            <div class="card p-4">
                <h3 class="text-sm font-bold text-brand-700 uppercase mb-3">Encabezado</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div>
                        <label class="text-xs font-semibold">Fecha *</label>
                        <input v-model="header.fecha" type="date" class="input w-full" :disabled="!esBorrador"/>
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs font-semibold">Proveedor *</label>
                        <select v-model="header.proveedor_id" class="input w-full" :disabled="!esBorrador">
                            <option :value="null">— elegí proveedor —</option>
                            <option v-for="p in proveedores" :key="p.id" :value="p.id">
                                {{ p.nombre }} · {{ p.numero_documento }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Bodega origen *</label>
                        <select v-model="header.ubicacion_id" class="input w-full" :disabled="!esBorrador">
                            <option :value="null">— elegí bodega —</option>
                            <option v-for="u in ubicaciones" :key="u.id" :value="u.id">
                                {{ u.codigo }} · {{ u.nombre }}
                            </option>
                        </select>
                    </div>
                    <div class="md:col-span-4">
                        <label class="text-xs font-semibold">Motivo *</label>
                        <input v-model="header.motivo" class="input w-full" placeholder="Ej.: producto averiado en origen · lote N° 123" :disabled="!esBorrador"/>
                    </div>
                </div>
                <div v-if="esBorrador" class="mt-3 flex justify-end">
                    <button @click="guardarHeader" :disabled="guardando" class="btn-primary text-sm">
                        {{ guardando ? 'Guardando…' : (esNueva ? 'Guardar y continuar' : 'Actualizar encabezado') }}
                    </button>
                </div>
            </div>

            <!-- Items -->
            <div v-if="devolucion" class="card p-4">
                <h3 class="text-sm font-bold text-brand-700 uppercase mb-3">Ítems a devolver</h3>

                <!-- Agregar item -->
                <div v-if="esBorrador" class="mb-3 p-3 rounded-lg border border-dashed border-surface-300 space-y-2">
                    <div class="relative">
                        <label class="text-xs font-semibold">Buscar producto en bodega</label>
                        <div class="relative">
                            <input v-model="busqueda" @input="buscar" class="input w-full pr-8" placeholder="Nombre, SKU o referencia…"/>
                            <Search class="absolute right-2 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-400"/>
                        </div>
                        <ul v-if="resultados.length" class="absolute z-10 mt-1 w-full max-h-60 overflow-auto border rounded-lg bg-white shadow-lg">
                            <li v-for="r in resultados" :key="r.variante_id" @click="seleccionarItem(r)" class="p-2 text-xs cursor-pointer hover:bg-brand-50 border-b last:border-0">
                                <div class="flex justify-between">
                                    <div>
                                        <div class="font-semibold">{{ r.nombre }}</div>
                                        <div class="text-surface-500">{{ r.detalle }} · <span class="font-mono">{{ r.sku }}</span></div>
                                    </div>
                                    <div class="text-right text-[11px]">
                                        <div class="text-surface-400 uppercase">stock</div>
                                        <div class="font-bold">{{ r.stock_actual }}</div>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div v-if="nuevoItem.sku" class="bg-surface-50 p-2 rounded">
                        <div class="text-xs font-semibold">{{ nuevoItem.nombre }} <span class="text-surface-400">· {{ nuevoItem.detalle }}</span></div>
                        <div class="font-mono text-[11px] text-sky-700">{{ nuevoItem.sku }}</div>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-2">
                        <div>
                            <label class="text-xs font-semibold">Cantidad *</label>
                            <input v-model.number="nuevoItem.cantidad" type="number" step="0.01" min="0.01" class="input w-full"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Costo unit *</label>
                            <input v-model.number="nuevoItem.costo_unit" type="number" step="0.01" min="0" class="input w-full"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">IVA %</label>
                            <input v-model.number="nuevoItem.iva_pct" type="number" step="0.01" min="0" max="100" class="input w-full"/>
                        </div>
                        <div class="md:col-span-2">
                            <label class="text-xs font-semibold">Motivo del ítem</label>
                            <input v-model="nuevoItem.motivo_item" class="input w-full" placeholder="Opcional"/>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button @click="agregarItem" class="btn-primary text-xs inline-flex items-center gap-1">
                            <Plus class="h-4 w-4"/> Agregar ítem
                        </button>
                    </div>
                </div>

                <!-- Tabla items · FIX-S0 overflow-x-auto para móvil -->
                <div class="overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm min-w-[700px]">
                    <thead class="text-xs text-surface-500 uppercase border-b bg-surface-50">
                        <tr>
                            <th class="text-left p-2">Producto</th>
                            <th class="text-right p-2 w-20">Cant.</th>
                            <th class="text-right p-2 w-24">Costo</th>
                            <th class="text-right p-2 w-16">IVA %</th>
                            <th class="text-right p-2 w-28">Subtotal</th>
                            <th class="text-left p-2">Motivo</th>
                            <th v-if="esBorrador" class="w-12"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="it in items" :key="it.id" class="hover:bg-surface-50">
                            <td class="p-2">
                                <div class="font-semibold">{{ it.nombre }}</div>
                                <div class="text-[11px] text-surface-500">{{ it.detalle }} · <span class="font-mono text-sky-700">{{ it.sku || it.referencia }}</span></div>
                            </td>
                            <td class="p-2 text-right font-mono">{{ it.cantidad }}</td>
                            <td class="p-2 text-right font-mono">{{ formato(it.costo_unit) }}</td>
                            <td class="p-2 text-right">{{ it.iva_pct }}</td>
                            <td class="p-2 text-right font-mono font-semibold">{{ formato(it.subtotal) }}</td>
                            <td class="p-2 text-xs text-surface-500">{{ it.motivo_item || '—' }}</td>
                            <td v-if="esBorrador" class="p-2 text-right">
                                <button @click="eliminarItem(it.id)" class="text-rose-500 hover:text-rose-700 p-1">
                                    <Trash2 class="h-4 w-4"/>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!items.length">
                            <td :colspan="esBorrador ? 7 : 6" class="p-6 text-center text-xs text-surface-400">
                                Aún no hay ítems · agregá al menos uno.
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>

                <!-- Totales + confirmar -->
                <div class="mt-4 flex justify-between items-end gap-4 flex-wrap">
                    <div v-if="esBorrador && items.length" class="flex-1">
                        <button @click="abrirPreview" :disabled="cargandoPreview" class="btn-primary">
                            <Eye class="h-4 w-4"/>
                            {{ cargandoPreview ? 'Calculando…' : 'Revisar asiento y confirmar' }}
                        </button>
                        <p class="text-[11px] text-surface-500 mt-1">Vas a ver la partida doble antes de ejecutar · aún podés cancelar.</p>
                    </div>
                    <div class="min-w-[220px] space-y-1 text-sm">
                        <div class="flex justify-between"><span class="text-surface-500">Subtotal</span><span class="font-mono">{{ formato(totales.subtotal) }}</span></div>
                        <div class="flex justify-between"><span class="text-surface-500">IVA</span><span class="font-mono">{{ formato(totales.iva) }}</span></div>
                        <div class="flex justify-between font-bold border-t pt-1"><span>Total</span><span class="font-mono">{{ formato(totales.total) }}</span></div>
                    </div>
                </div>
            </div>

            <!-- COMP-B2 · Modal preview de asiento contable -->
            <div v-if="preview" class="fixed inset-0 bg-black/60 flex items-center justify-center p-4 z-50 overflow-y-auto"
                 @click.self="preview = null">
                <div class="bg-white rounded-xl shadow-2xl max-w-3xl w-full my-8">
                    <div class="p-5 border-b flex items-center gap-3">
                        <Eye class="h-6 w-6 text-brand-600"/>
                        <div class="flex-1">
                            <h2 class="text-lg font-bold text-surface-800">Vista previa del asiento contable</h2>
                            <p class="text-xs text-surface-500">{{ preview.numero }} · {{ preview.proveedor }} · {{ preview.ubicacion }}</p>
                        </div>
                        <button @click="preview = null" class="p-1 rounded hover:bg-surface-100">
                            <X class="h-4 w-4"/>
                        </button>
                    </div>

                    <div class="p-5 space-y-4">
                        <!-- Resumen -->
                        <div class="grid grid-cols-3 gap-3 text-sm">
                            <div class="bg-surface-50 rounded-lg p-3">
                                <div class="text-[10px] uppercase text-surface-400">Ítems</div>
                                <div class="font-bold">{{ preview.items_count }} ({{ preview.items_unidades }} unid.)</div>
                            </div>
                            <div class="bg-surface-50 rounded-lg p-3">
                                <div class="text-[10px] uppercase text-surface-400">Subtotal</div>
                                <div class="font-mono font-bold">{{ formato(preview.totales.subtotal) }}</div>
                            </div>
                            <div class="bg-surface-50 rounded-lg p-3">
                                <div class="text-[10px] uppercase text-surface-400">Total con IVA</div>
                                <div class="font-mono font-bold text-emerald-700">{{ formato(preview.totales.total) }}</div>
                            </div>
                        </div>

                        <!-- Partida doble -->
                        <div>
                            <h3 class="text-xs uppercase font-bold text-brand-700 mb-2">Movimientos contables</h3>
                            <table v-tabla-movil class="w-full text-sm border rounded-lg overflow-hidden">
                                <thead class="bg-surface-100 text-xs text-surface-600 uppercase">
                                    <tr>
                                        <th class="text-left p-2 w-20">PUC</th>
                                        <th class="text-left p-2">Concepto</th>
                                        <th class="text-right p-2 w-28">Debe</th>
                                        <th class="text-right p-2 w-28">Haber</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr v-for="(r, i) in preview.renglones" :key="i" class="hover:bg-surface-50">
                                        <td class="p-2 font-mono font-bold text-brand-600">{{ r.cuenta }}</td>
                                        <td class="p-2 text-xs">
                                            <div>{{ r.glosa }}</div>
                                            <div class="text-[10px] text-surface-400 mt-0.5">{{ r.nota }}</div>
                                        </td>
                                        <td class="p-2 text-right font-mono" :class="r.debe > 0 ? 'font-bold text-sky-700' : 'text-surface-300'">
                                            {{ r.debe > 0 ? formato(r.debe) : '—' }}
                                        </td>
                                        <td class="p-2 text-right font-mono" :class="r.haber > 0 ? 'font-bold text-rose-700' : 'text-surface-300'">
                                            {{ r.haber > 0 ? formato(r.haber) : '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="bg-surface-50 border-t-2">
                                    <tr>
                                        <td colspan="2" class="p-2 text-right font-semibold text-xs">Totales</td>
                                        <td class="p-2 text-right font-mono font-bold">{{ formato(preview.totales.debe) }}</td>
                                        <td class="p-2 text-right font-mono font-bold">{{ formato(preview.totales.haber) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Validación cuadrado -->
                        <div :class="['card p-3 border-l-4', preview.totales.cuadrado ? 'border-emerald-500 bg-emerald-50' : 'border-rose-500 bg-rose-50']">
                            <div class="flex items-center gap-2 text-sm">
                                <CheckCircle2 v-if="preview.totales.cuadrado" class="h-5 w-5 text-emerald-600 shrink-0"/>
                                <AlertCircle v-else class="h-5 w-5 text-rose-600 shrink-0"/>
                                <div>
                                    <div :class="preview.totales.cuadrado ? 'text-emerald-800 font-semibold' : 'text-rose-800 font-semibold'">
                                        {{ preview.totales.cuadrado ? 'Asiento cuadrado · DB = CR' : '⚠ Asiento DESCUADRADO · revisá los ítems' }}
                                    </div>
                                    <div class="text-xs text-surface-600 mt-0.5">
                                        Al confirmar: baja {{ preview.items_unidades }} unidades del kardex, se escriben los 3 asientos de arriba y se encola <strong>{{ preview.siigo_destino }}</strong>.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="p-4 border-t flex justify-between items-center gap-2">
                        <button @click="preview = null" class="text-sm px-4 py-2 rounded-lg hover:bg-surface-100">
                            Cancelar · seguir editando
                        </button>
                        <button @click="confirmar" :disabled="confirmando || !preview.totales.cuadrado"
                                class="btn-primary inline-flex items-center gap-1.5 disabled:opacity-50">
                            <Send class="h-4 w-4"/>
                            {{ confirmando ? 'Confirmando…' : 'Confirmar · dar baja y enviar a SIIGO' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
