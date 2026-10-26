<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeftRight, ArrowLeft, Plus, Trash2, Send, PackageCheck, Ban, Search, Upload, BadgeCheck, Camera } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import ImportMasivoModal from '@/Components/ImportMasivoModal.vue';
import { useEscClose } from '@/composables/useEscClose';
import { useFecha } from '@/composables/useFecha';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';

const importMasivoAbierto = ref(false);
const importMasivoOk = () => { importMasivoAbierto.value = false; router.reload({ only: ['traslado'] }); };

const props = defineProps({
    traslado: { type: Object, required: true },
    // MKT-5 · sólo llegan cuando el traslado es de muestras de Marketing.
    equipo_marketing: { type: Array, default: () => [] },
    estados_mercancia: { type: Object, default: () => ({}) },
});

const muestra = computed(() => props.traslado.muestra);
// Las muestras que salen hacia Marketing pasan primero por aprobación.
const faltaAprobar = computed(() => !!muestra.value?.es_prestamo && !muestra.value.aprobado_at);

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);
const { fechaCorta } = useFecha();

const badge = (e) => ({
    borrador: 'bg-surface-200 text-surface-800',
    en_transito: 'bg-amber-100 text-amber-800',
    recibido: 'bg-emerald-100 text-emerald-800',
    anulado: 'bg-red-100 text-red-800',
}[e] || 'bg-surface-100');

const puedeEditar = computed(() => props.traslado.estado === 'borrador');
const puedeEnviar = computed(() => props.traslado.estado === 'borrador' && props.traslado.items.length > 0 && !faltaAprobar.value);
const puedeAprobar = computed(() => props.traslado.estado === 'borrador' && faltaAprobar.value);
const puedeRecibir = computed(() => props.traslado.estado === 'en_transito');
const puedeAnular = computed(() => ['borrador', 'en_transito', 'recibido'].includes(props.traslado.estado));

// Repeater items
const busqueda = ref('');
const resultados = ref([]);
const buscando = ref(false);
const nuevoItem = reactive({ variante_id: null, sku: '', producto: '', detalle: '', cantidad: null, notas: '' });

let debTimer;
const buscar = () => {
    clearTimeout(debTimer);
    if (busqueda.value.length < 2) { resultados.value = []; return; }
    debTimer = setTimeout(async () => {
        buscando.value = true;
        try {
            const r = await fetch(`/app/inventario/buscar-variantes?q=${encodeURIComponent(busqueda.value)}`);
            resultados.value = await r.json();
        } finally { buscando.value = false; }
    }, 200);
};

const seleccionarVariante = (v) => {
    nuevoItem.variante_id = v.id;
    nuevoItem.sku = v.sku;
    nuevoItem.producto = v.producto;
    nuevoItem.detalle = v.detalle;
    busqueda.value = v.label;
    resultados.value = [];
};

const procesandoItem = ref(false);
const guardarItem = () => {
    if (procesandoItem.value || !nuevoItem.variante_id || !nuevoItem.cantidad) return;
    procesandoItem.value = true;
    router.post(`/app/inventario/traslados/${props.traslado.id}/items`, {
        variante_id: nuevoItem.variante_id,
        cantidad: nuevoItem.cantidad,
        notas: nuevoItem.notas || null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            Object.assign(nuevoItem, { variante_id: null, sku: '', producto: '', detalle: '', cantidad: null, notas: '' });
            busqueda.value = '';
        },
        onFinish: () => (procesandoItem.value = false),
    });
};

const eliminarItem = (it) => {
    modalConfirm.value = {
        titulo: `¿Quitar ${it.producto} del traslado?`,
        mensaje: `SKU ${it.sku}.`,
        color: 'rose',
        textoConfirmar: 'Quitar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.delete(`/app/inventario/traslados/${props.traslado.id}/items/${it.id}`, { preserveScroll: true });
        },
    };
};

// State-machine actions
const enviando = ref(false);
const enviar = () => {
    const total = props.traslado.items.reduce((s, it) => s + Number(it.cantidad_solicitada || 0), 0);
    modalConfirm.value = {
        titulo: `Enviar traslado ${props.traslado.numero}?`,
        mensaje: `Se descontarán ${total} unidades del origen (${props.traslado.origen.nombre}) y quedarán En Tránsito hacia ${props.traslado.destino.nombre}. Esta acción NO se puede deshacer sin una reversa.`,
        color: 'sky',
        textoConfirmar: 'Enviar',
        onConfirmar: () => {
            modalConfirm.value = null;
            enviando.value = true;
            router.post(`/app/inventario/traslados/${props.traslado.id}/enviar`, {}, { preserveScroll: true, onFinish: () => (enviando.value = false) });
        },
    };
};

const aprobando = ref(false);
const aprobar = () => {
    aprobando.value = true;
    router.post(`/app/inventario/traslados/${props.traslado.id}/aprobar`, {}, { preserveScroll: true, onFinish: () => (aprobando.value = false) });
};

const recibiendo = ref(false);

/*
 * MKT-5 · entregar muestras (o recibir la devolución) queda firmado: quién se
 * la llevó o la trajo, en qué estado y qué novedad hubo. Por eso no va por el
 * confirm genérico sino por su propio formulario.
 */
const modalEntrega = ref(false);
useEscClose(modalEntrega);
const entrega = reactive({ recogido_por: '', estado_mercancia: 'completo', observaciones_entrega: '' });
const erroresEntrega = ref({});
const abrirEntrega = () => {
    erroresEntrega.value = {};
    Object.assign(entrega, {
        recogido_por: props.equipo_marketing.length === 1 ? props.equipo_marketing[0].id : '',
        estado_mercancia: 'completo',
        observaciones_entrega: '',
    });
    modalEntrega.value = true;
};
const entregar = () => {
    recibiendo.value = true;
    router.post(`/app/inventario/traslados/${props.traslado.id}/recibir`, { ...entrega }, {
        preserveScroll: true,
        onSuccess: () => { modalEntrega.value = false; },
        onError: (e) => { erroresEntrega.value = e; },
        onFinish: () => (recibiendo.value = false),
    });
};

const recibir = () => {
    if (muestra.value) return abrirEntrega();
    const total = props.traslado.items.reduce((s, it) => s + Number(it.cantidad_solicitada || 0), 0);
    modalConfirm.value = {
        titulo: `Confirmar recepción de traslado ${props.traslado.numero}?`,
        mensaje: `Se ingresarán ${total} unidades en el destino (${props.traslado.destino.nombre}).`,
        color: 'emerald',
        textoConfirmar: 'Confirmar recepción',
        onConfirmar: () => {
            modalConfirm.value = null;
            recibiendo.value = true;
            router.post(`/app/inventario/traslados/${props.traslado.id}/recibir`, {}, { preserveScroll: true, onFinish: () => (recibiendo.value = false) });
        },
    };
};

const modalAnular = ref(false);
useEscClose(modalAnular);
const motivoAnulacion = ref('');
const anulando = ref(false);
const anular = () => {
    if (motivoAnulacion.value.trim().length < 5) return;
    anulando.value = true;
    router.post(`/app/inventario/traslados/${props.traslado.id}/anular`, { motivo: motivoAnulacion.value }, {
        preserveScroll: true,
        onSuccess: () => { modalAnular.value = false; motivoAnulacion.value = ''; },
        onFinish: () => (anulando.value = false),
    });
};
</script>

<template>
    <Head :title="`Traslado ${traslado.numero}`" />
    <AppLayout>
        <div class="space-y-4">
            <Link href="/app/inventario/traslados" class="text-sm text-brand-600 inline-flex items-center gap-1 hover:underline">
                <ArrowLeft class="h-4 w-4" /> Volver a traslados
            </Link>

            <!-- Cabecera -->
            <div class="card p-5">
                <div class="flex items-start justify-between flex-wrap gap-3">
                    <div>
                        <div class="flex items-center gap-3 flex-wrap">
                            <h1 class="text-2xl font-bold flex items-center gap-2">
                                <ArrowLeftRight class="h-6 w-6 text-brand-600" />
                                {{ traslado.numero }}
                            </h1>
                            <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(traslado.estado)]">
                                {{ traslado.estado }}
                            </span>
                        </div>
                        <p class="text-sm text-surface-600 mt-1">
                            <span class="font-semibold">{{ traslado.origen.nombre }}</span>
                            <ArrowLeftRight class="inline h-4 w-4 mx-2" />
                            <span class="font-semibold">{{ traslado.destino.nombre }}</span>
                        </p>
                        <p class="text-xs text-surface-500 mt-1">
                            Solicitado por {{ traslado.solicitante || '—' }} · {{ fechaCorta(traslado.fecha_solicitud) }}
                            <template v-if="traslado.fecha_envio"> · Enviado {{ traslado.fecha_envio }}</template>
                            <template v-if="traslado.fecha_ejecucion"> · Recibido {{ traslado.fecha_ejecucion }}</template>
                        </p>
                        <p v-if="traslado.motivo" class="text-xs text-surface-500 mt-1">Motivo: {{ traslado.motivo }}</p>
                        <p v-if="traslado.observaciones" class="text-xs text-surface-500 mt-1 whitespace-pre-wrap">{{ traslado.observaciones }}</p>
                        <p v-if="puedeAprobar" class="text-xs text-amber-700 mt-2 font-semibold">
                            Solicitud de muestras de Marketing: aprobala antes de marcarla lista.
                        </p>
                    </div>
                    <!-- Botones state-machine -->
                    <div class="flex gap-2 flex-wrap">
                        <button v-if="puedeAprobar" @click="aprobar" :disabled="aprobando" class="btn-primary min-h-11">
                            <BadgeCheck class="h-4 w-4" /> {{ aprobando ? 'Aprobando…' : 'Aprobar solicitud' }}
                        </button>
                        <button v-if="puedeEnviar" @click="enviar" :disabled="enviando" class="btn-primary min-h-11">
                            <Send class="h-4 w-4" />
                            {{ enviando ? 'Enviando…' : (muestra?.es_prestamo ? 'Lista para recoger' : 'Enviar') }}
                        </button>
                        <button v-if="puedeRecibir" @click="recibir" :disabled="recibiendo" class="btn-primary min-h-11">
                            <PackageCheck class="h-4 w-4" />
                            {{ recibiendo ? 'Recibiendo…' : (muestra ? (muestra.es_prestamo ? 'Entregar a Marketing' : 'Recibir devolución') : 'Confirmar recepción') }}
                        </button>
                        <button v-if="puedeAnular" @click="modalAnular = true" class="btn-danger min-h-11">
                            <Ban class="h-4 w-4" /> Anular
                        </button>
                    </div>
                </div>
            </div>

            <!-- MKT-5 · responsables de las muestras -->
            <div v-if="muestra" class="card p-4">
                <h3 class="text-sm font-bold flex items-center gap-2 mb-3">
                    <Camera class="h-4 w-4 text-brand-600" />
                    {{ muestra.es_prestamo ? 'Muestras para Marketing' : 'Devolución de Marketing' }}
                    <span v-if="muestra.prestamo" class="text-xs font-normal text-surface-500">· cierra {{ muestra.prestamo }}</span>
                </h3>
                <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                    <div>
                        <dt class="text-xs text-surface-500">Solicitó</dt>
                        <dd class="font-semibold">{{ traslado.solicitante || '—' }}</dd>
                    </div>
                    <div v-if="muestra.es_prestamo">
                        <dt class="text-xs text-surface-500">Aprobó</dt>
                        <dd class="font-semibold">{{ muestra.aprobado_por || 'Pendiente' }}</dd>
                        <dd v-if="muestra.aprobado_at" class="text-xs text-surface-500">{{ muestra.aprobado_at }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-surface-500">{{ muestra.es_prestamo ? 'Entregó (bodega)' : 'Recibió (bodega)' }}</dt>
                        <dd class="font-semibold">{{ traslado.ejecutor || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-surface-500">{{ muestra.es_prestamo ? 'Recogió (Marketing)' : 'La trajo (Marketing)' }}</dt>
                        <dd class="font-semibold">{{ muestra.recogido_por || '—' }}</dd>
                    </div>
                </dl>
                <div v-if="muestra.estado_mercancia" class="mt-3 text-sm">
                    <span class="px-2 py-0.5 rounded text-xs font-semibold"
                          :class="muestra.estado_mercancia === 'completo' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'">
                        {{ muestra.estado_mercancia_label }}
                    </span>
                    <p v-if="muestra.observaciones_entrega" class="text-xs text-surface-600 mt-1 whitespace-pre-wrap">{{ muestra.observaciones_entrega }}</p>
                </div>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 text-sm">
                {{ $page.props.flash.error }}
            </div>

            <!-- Agregar ítem (solo Borrador) -->
            <div v-if="puedeEditar" class="card p-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-bold">Agregar producto</h3>
                    <button @click="importMasivoAbierto = true"
                            class="text-xs inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-emerald-500 text-emerald-700 hover:bg-emerald-50"
                            title="Cargar lista completa de ítems desde Excel">
                        <Upload class="h-3.5 w-3.5"/> Importar Excel masivo
                    </button>
                </div>
                <ImportMasivoModal
                    :abierto="importMasivoAbierto"
                    :endpoint="`/app/inventario/traslados/${traslado.id}/importar`"
                    tipo="traslado"
                    titulo="Importar items del traslado"
                    ayuda="Columna A: SKU/código de barras · Columna B: cantidad solicitada · Columna C: notas (opcional)."
                    @cerrar="importMasivoAbierto = false"
                    @ok="importMasivoOk"
                />
                <div class="grid gap-3 md:grid-cols-[2fr,1fr,1fr,auto]">
                    <div class="relative">
                        <label class="text-xs font-semibold">Buscar producto o SKU</label>
                        <div class="relative">
                            <input v-model="busqueda" @input="buscar" class="input w-full pr-8" placeholder="Nombre, referencia o código de barras"
                                autocomplete="off" inputmode="text" />
                            <Search class="absolute right-2 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-400" />
                        </div>
                        <ul v-if="resultados.length" class="absolute z-10 mt-1 w-full max-h-60 overflow-auto border rounded-lg bg-white shadow-lg">
                            <li v-for="r in resultados" :key="r.id" @click="seleccionarVariante(r)"
                                class="p-2 text-xs cursor-pointer hover:bg-brand-50 border-b last:border-0">
                                <div class="font-semibold">{{ r.producto }}</div>
                                <div class="text-surface-500">{{ r.detalle }} · <span class="font-mono">{{ r.sku }}</span></div>
                            </li>
                        </ul>
                        <p v-if="busqueda.length >= 2 && !resultados.length && !buscando" class="text-xs text-surface-400 mt-1">Sin resultados.</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Cantidad</label>
                        <input type="number" step="0.01" min="0.01" v-model.number="nuevoItem.cantidad" class="input w-full" inputmode="decimal" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Notas</label>
                        <input v-model="nuevoItem.notas" class="input w-full" placeholder="Opcional" />
                    </div>
                    <div class="flex items-end">
                        <button @click="guardarItem" :disabled="procesandoItem || !nuevoItem.variante_id || !nuevoItem.cantidad" class="btn-primary min-h-11">
                            <Plus class="h-4 w-4" /> Agregar
                        </button>
                    </div>
                </div>
                <p v-if="nuevoItem.variante_id" class="text-xs text-emerald-700 mt-2">
                    Seleccionado: <b>{{ nuevoItem.producto }}</b> · {{ nuevoItem.detalle }} · <span class="font-mono">{{ nuevoItem.sku }}</span>
                </p>
            </div>

            <!-- Tabla items -->
            <div class="card overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-3">SKU</th>
                            <th class="text-left p-3">Producto</th>
                            <th class="text-left p-3">Detalle</th>
                            <th class="text-right p-3">Cantidad</th>
                            <th class="text-right p-3">Ejecutada</th>
                            <th class="text-left p-3">Notas</th>
                            <th v-if="puedeEditar" class="text-center p-3">—</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-if="!traslado.items.length"><td :colspan="puedeEditar ? 7 : 6" class="p-6 text-center text-surface-500">Sin ítems. Agrega productos arriba para poder enviar el traslado.</td></tr>
                        <tr v-for="it in traslado.items" :key="it.id" class="hover:bg-surface-50">
                            <td class="p-3 font-mono text-xs">{{ it.sku }}</td>
                            <td class="p-3">{{ it.producto }}</td>
                            <td class="p-3 text-xs text-surface-500">{{ it.detalle }}</td>
                            <td class="p-3 text-right font-bold">{{ it.cantidad_solicitada }}</td>
                            <td class="p-3 text-right" :class="Number(it.cantidad_ejecutada) > 0 ? 'text-emerald-600 font-bold' : 'text-surface-400'">
                                {{ it.cantidad_ejecutada }}
                            </td>
                            <td class="p-3 text-xs">{{ it.notas || '—' }}</td>
                            <td v-if="puedeEditar" class="p-3 text-center">
                                <button @click="eliminarItem(it)" class="text-red-600 hover:bg-red-50 p-1 rounded">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="traslado.items.length" class="bg-surface-50 font-bold">
                            <td class="p-3" colspan="3">Total ítems</td>
                            <td class="p-3 text-right">{{ traslado.items.reduce((s, i) => s + Number(i.cantidad_solicitada || 0), 0) }}</td>
                            <td class="p-3 text-right">{{ traslado.items.reduce((s, i) => s + Number(i.cantidad_ejecutada || 0), 0) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal anular -->
        <div v-if="modalAnular" @click.self="modalAnular = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full">
                <h3 class="text-lg font-bold mb-2 text-red-700">Anular traslado {{ traslado.numero }}</h3>
                <p class="text-xs text-surface-600 mb-3">
                    Si el traslado ya fue enviado, se generará una reversa automática que devolverá el stock al origen (y lo retirará del destino, si ya recibió). El motivo queda registrado para auditoría DIAN.
                </p>
                <label class="text-xs font-semibold">Motivo (mínimo 5 caracteres)</label>
                <textarea v-model="motivoAnulacion" rows="3" class="input w-full" placeholder="Ej: error de digitación en cantidades"></textarea>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalAnular = false" class="btn-ghost min-h-11">Cancelar</button>
                    <button @click="anular" :disabled="anulando || motivoAnulacion.trim().length < 5" class="btn-danger min-h-11">
                        {{ anulando ? 'Anulando…' : 'Anular traslado' }}
                    </button>
                </div>
            </div>
        </div>
        <!-- Modal entrega de muestras (MKT-5) -->
        <div v-if="modalEntrega" @click.self="modalEntrega = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full space-y-3">
                <h3 class="text-lg font-bold">
                    {{ muestra.es_prestamo ? 'Entregar muestras a Marketing' : 'Recibir devolución de Marketing' }} · {{ traslado.numero }}
                </h3>
                <div>
                    <label class="text-xs font-semibold">{{ muestra.es_prestamo ? '¿Quién las recoge?' : '¿Quién las trajo?' }}</label>
                    <select v-model.number="entrega.recogido_por" class="input w-full">
                        <option value="" disabled>Elegí a la persona de Marketing</option>
                        <option v-for="u in equipo_marketing" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <p v-if="erroresEntrega.recogido_por" class="text-xs text-red-600 mt-1">{{ erroresEntrega.recogido_por }}</p>
                </div>
                <div>
                    <label class="text-xs font-semibold">¿En qué estado {{ muestra.es_prestamo ? 'sale' : 'llegó' }}?</label>
                    <div class="space-y-1 mt-1">
                        <label v-for="(label, clave) in estados_mercancia" :key="clave" class="flex items-center gap-2 text-sm">
                            <input type="radio" :value="clave" v-model="entrega.estado_mercancia" /> {{ label }}
                        </label>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-semibold">
                        Observaciones{{ entrega.estado_mercancia === 'completo' ? ' (opcional)' : '' }}
                    </label>
                    <textarea v-model="entrega.observaciones_entrega" rows="3" class="input w-full"
                              placeholder="Ej: falta 1 body talla 3-6; el triciclo llegó con la caja rota"></textarea>
                    <p v-if="erroresEntrega.observaciones_entrega" class="text-xs text-red-600 mt-1">{{ erroresEntrega.observaciones_entrega }}</p>
                </div>
                <p class="text-xs text-surface-500">
                    Se ingresan {{ traslado.items.reduce((s, i) => s + Number(i.cantidad_solicitada || 0), 0) }} unidades en {{ traslado.destino.nombre }} y a quien la pidió le llega el aviso.
                </p>
                <div class="flex justify-end gap-2 pt-1">
                    <button @click="modalEntrega = false" class="btn-ghost min-h-11">Cancelar</button>
                    <button @click="entregar" :disabled="recibiendo || !entrega.recogido_por" class="btn-primary min-h-11">
                        {{ recibiendo ? 'Guardando…' : (muestra.es_prestamo ? 'Confirmar entrega' : 'Confirmar recepción') }}
                    </button>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
