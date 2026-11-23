<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { useMoney } from '@/composables/useMoney';
import { useDebounceFn } from '@vueuse/core';
import { FileText, Search, Filter, Phone, ExternalLink, Download } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import ModalFacturaManual from './ModalFacturaManual.vue';
import ModalImportarExcel from './ModalImportarExcel.vue';
import { useEscClose } from '@/composables/useEscClose';

const props = defineProps({
    facturas: { type: Object, required: true },
    filtros: { type: Object, required: true },
    totales: { type: Object, required: true },
    opciones: { type: Object, default: () => ({ destinos: [], lineas: [] }) },
});

const q = ref(props.filtros.q || '');
const filtro = ref(props.filtros.filtro || 'todas');
const destino = ref(props.filtros.destino || 'todas');
const linea = ref(props.filtros.linea || 'todas');

const consultar = () => router.get('/app/facturas', {
    q: q.value, filtro: filtro.value, destino: destino.value, linea: linea.value,
}, { preserveScroll: true, preserveState: true, replace: true });

const buscar = useDebounceFn(consultar, 400);

watch(q, buscar);
watch([filtro, destino, linea], consultar);

const { money: fmtCOP } = useMoney();

/*
 * F2 · Las PESTAÑAS son el destino fiscal: es la decisión que el contador toma
 * a diario. El estado de la factura bajó al filtro, y la línea de negocio va
 * aparte porque es el otro eje — cruzarlos como pestañas daría seis.
 */
const tabs = computed(() => [
    { key: 'todas', label: 'Todas', count: props.totales.destino_todas },
    { key: 'siigo', label: 'A SIIGO', count: props.totales.destino_siigo },
    { key: 'interna', label: 'Internas', count: props.totales.destino_interna },
]);

const estados = [
    { valor: 'todas', label: 'Todos los estados' },
    { valor: 'pendientes', label: 'Pendientes' },
    { valor: 'vencidas', label: 'Vencidas' },
    { valor: 'pagadas', label: 'Pagadas' },
];

/* ── Selección múltiple para cambiar el destino de varias de una ── */
const seleccion = ref([]);
const todasMarcadas = computed(() =>
    props.facturas.data.length > 0 && seleccion.value.length === props.facturas.data.length);

const alternarTodas = () => {
    seleccion.value = todasMarcadas.value ? [] : props.facturas.data.map((f) => f.id);
};

watch(() => props.facturas.data, () => { seleccion.value = []; });

/*
 * F2b · las dos puertas para crear facturas que no vienen de un pedido. Van
 * como modales sobre esta misma pantalla: la idea era no sumar entradas al
 * menu, y quien crea una factura a mano ya esta mirando el listado.
 */
const modalManual = ref(false);
const modalExcel = ref(false);

const modal = ref(null);
useEscClose(modal);  // { ids, destino, total } mientras se pide el motivo
const motivo = ref('');
const montoFiscal = ref(null);
const enviando = ref(false);

const esReparto = computed(() => modal.value?.destino === 'reparto');

/*
 * El total de la factura, para poder validar el monto declarado contra él.
 * Sólo tiene sentido con una seleccionada, que es lo único que el reparto
 * admite: el monto declarado es distinto en cada factura.
 */
const totalDeLaFactura = computed(() => {
    if (modal.value?.ids?.length !== 1) return null;
    return props.facturas.data.find((f) => f.id === modal.value.ids[0])?.total ?? null;
});

const montoInvalido = computed(() => {
    if (! esReparto.value) return null;
    const m = Number(montoFiscal.value);
    if (! m || m <= 0) return 'Hay que decir cuánto se declara.';
    if (totalDeLaFactura.value && m >= totalDeLaFactura.value) {
        return 'No puede ser igual ni mayor al total. Si se declara todo, el destino es «Fiscal».';
    }
    return null;
});

const pedirDestino = (ids, nuevoDestino) => {
    motivo.value = '';
    montoFiscal.value = null;
    modal.value = { ids, destino: nuevoDestino };
};

const confirmarDestino = () => {
    if (! modal.value || enviando.value || montoInvalido.value) return;
    enviando.value = true;
    router.post('/app/facturas/destino', {
        ids: modal.value.ids,
        destino: modal.value.destino,
        motivo: motivo.value || null,
        monto_fiscal: esReparto.value ? Number(montoFiscal.value) : null,
    }, {
        preserveScroll: true,
        onFinish: () => { enviando.value = false; },
        onSuccess: () => { modal.value = null; seleccion.value = []; },
    });
};

const labelDestino = (valor) =>
    props.opciones.destinos.find((d) => d.valor === valor)?.label ?? valor;

const badgeDestino = (color) => ({
    success: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
}[color] || 'bg-slate-100 text-slate-700');

const badgeEstado = (color) => ({
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    success: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    danger: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    gray: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
}[color] || 'bg-slate-100 text-slate-700');

const abrirWhatsApp = (tel, nombre, numero, saldo) => {
    if (! tel) return;
    const t = String(tel).replace(/\D/g, '');
    if (t.length < 10) return;
    const num = t.startsWith('57') ? t : '57' + t;
    const msg = encodeURIComponent(`Hola ${nombre}, recordate del pago de la factura ${numero} por ${fmtCOP(saldo)}. Gracias.`);
    window.open(`https://wa.me/${num}?text=${msg}`, '_blank');
};
</script>

<template>
    <Head title="Facturas de venta"/>
    <AppLayout>
        <div class="space-y-4">
            <!-- Header -->
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <FileText class="h-6 w-6 text-brand-600"/>
                        Facturas de venta
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">Consultá el estado, gestioná cobros y descargá PDF/QR DIAN.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="modalManual = true" class="btn-primary text-sm">+ Manual</button>
                    <button @click="modalExcel = true" class="btn-secondary text-sm">↑ Excel</button>
                </div>
            </div>

            <!-- Filtro · los dos ejes y el estado, todo en una barra -->
            <div class="card p-3 flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[220px]">
                    <label class="text-xs font-semibold text-surface-500">Buscar</label>
                    <div class="relative mt-1">
                        <Search class="h-4 w-4 absolute left-3 top-1/2 -translate-y-1/2 text-surface-400"/>
                        <input v-model="q" type="search"
                               placeholder="Número, cliente o documento…"
                               class="input w-full pl-10"/>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-semibold text-surface-500">Línea de negocio</label>
                    <select v-model="linea" class="input mt-1">
                        <option value="todas">Consolidado (Great Baby + Dropi)</option>
                        <option v-for="l in opciones.lineas" :key="l.valor" :value="l.valor">{{ l.label }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold text-surface-500">Estado</label>
                    <select v-model="filtro" class="input mt-1">
                        <option v-for="e in estados" :key="e.valor" :value="e.valor">{{ e.label }}</option>
                    </select>
                </div>
            </div>

            <!-- Pestañas · el destino fiscal -->
            <div class="flex items-center gap-2 border-b border-surface-200 dark:border-surface-800 overflow-x-auto">
                <button v-for="t in tabs" :key="t.key" @click="destino = t.key"
                        :class="['px-4 py-2 text-sm font-semibold border-b-2 whitespace-nowrap transition',
                                 destino === t.key
                                 ? 'border-brand-500 text-brand-600'
                                 : 'border-transparent text-surface-500 hover:text-surface-800 dark:hover:text-surface-200']">
                    {{ t.label }}
                    <span class="ml-1 text-xs opacity-70">({{ t.count }})</span>
                </button>
            </div>

            <!-- Acciones sobre lo seleccionado -->
            <div v-if="seleccion.length"
                 class="card p-3 bg-brand-50 dark:bg-brand-900/20 flex flex-wrap items-center gap-3">
                <span class="text-sm font-semibold">{{ seleccion.length }} seleccionada(s) · mandarlas a:</span>
                <button v-for="d in opciones.destinos" :key="d.valor"
                        @click="pedirDestino(seleccion, d.valor)"
                        class="btn-secondary text-xs">{{ d.label }}</button>
                <button @click="seleccion = []" class="text-xs text-surface-500 hover:underline ml-auto">
                    Quitar selección
                </button>
            </div>

            <!-- Tabla -->
            <div class="card overflow-hidden">
                <div v-if="! facturas.data.length" class="text-center py-16 text-surface-500">
                    <FileText class="h-10 w-10 mx-auto opacity-40"/>
                    <div class="text-sm mt-2">No hay facturas con estos criterios.</div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil data-vacia="No hay facturas que coincidan con la búsqueda." class="w-full min-w-[900px] text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900">
                            <tr class="text-surface-500 text-xs uppercase">
                                <th class="px-3 py-2 w-8">
                                    <input type="checkbox" :checked="todasMarcadas" @change="alternarTodas"
                                           aria-label="Seleccionar todas"/>
                                </th>
                                <th class="text-left px-4 py-2">Número</th>
                                <th class="text-left">Cliente</th>
                                <th class="text-center">Destino</th>
                                <th class="text-right">Emisión</th>
                                <th class="text-right">Vence</th>
                                <th class="text-right">Total</th>
                                <th class="text-right">Saldo</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Mora</th>
                                <th class="text-right px-2">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="f in facturas.data" :key="f.id"
                                class="border-t border-surface-100 dark:border-surface-900 hover:bg-surface-50 dark:hover:bg-surface-900/30">
                                <td class="px-3">
                                    <input type="checkbox" :value="f.id" v-model="seleccion"
                                           :aria-label="'Seleccionar ' + f.numero"/>
                                </td>
                                <td class="px-4 py-2 font-mono text-brand-600 font-semibold">
                                    <Link :href="'/app/facturas/' + f.id" class="hover:underline">{{ f.numero }}</Link>
                                    <div v-if="f.linea_negocio === 'dropi'" class="text-[10px] font-bold text-amber-600">DROPI</div>
                                </td>
                                <td>
                                    <div class="font-medium text-surface-800 dark:text-surface-200">{{ f.contacto }}</div>
                                    <div v-if="f.telefono" class="text-xs text-surface-500 font-mono">{{ f.telefono }}</div>
                                </td>
                                <!--
                                    El destino se cambia desde acá, sin entrar a la factura: el
                                    contador revisa el corte del día de arriba abajo y va marcando.
                                    Si ya se emitió en SIIGO queda fijo — eso se deshace con nota
                                    crédito, no borrando la decisión.
                                -->
                                <td class="text-center">
                                    <select v-if="! f.siigo_id"
                                            :value="f.destino_fiscal"
                                            @change="pedirDestino([f.id], $event.target.value)"
                                            :class="['text-xs font-bold rounded px-2 py-1 border-0 cursor-pointer', badgeDestino(f.destino_color)]">
                                        <option v-for="d in opciones.destinos" :key="d.valor" :value="d.valor">
                                            {{ d.label }}
                                        </option>
                                    </select>
                                    <span v-else
                                          :class="['inline-block px-2 py-0.5 rounded text-xs font-bold', badgeDestino(f.destino_color)]"
                                          title="Ya emitida en SIIGO · se revierte con nota crédito">
                                        {{ f.destino_label }} 🔒
                                    </span>
                                </td>
                                <td class="text-right text-surface-500">{{ f.fecha_emision }}</td>
                                <td class="text-right text-surface-500">{{ f.fecha_vencimiento }}</td>
                                <td class="text-right font-bold tabular-nums">{{ fmtCOP(f.total) }}</td>
                                <td class="text-right font-bold tabular-nums" :class="f.saldo > 0 ? 'text-red-600' : 'text-emerald-600'">
                                    {{ fmtCOP(f.saldo) }}
                                </td>
                                <td class="text-center">
                                    <span :class="['inline-block px-2 py-0.5 rounded text-xs font-bold', badgeEstado(f.estado_color)]">
                                        {{ f.estado_label }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span v-if="f.dias_mora > 0" class="text-xs font-bold text-red-600">{{ f.dias_mora }}d</span>
                                    <span v-else class="text-xs text-emerald-600">—</span>
                                </td>
                                <td class="text-right px-2">
                                    <div class="inline-flex items-center gap-1">
                                        <a :href="'/cartera/factura/' + f.id + '/pdf'" target="_blank"
                                           class="text-brand-600 hover:text-brand-800 p-1" title="Descargar PDF" aria-label="Descargar PDF">
                                            <Download class="h-4 w-4"/>
                                        </a>
                                        <button v-if="f.telefono && f.saldo > 0"
                                                @click="abrirWhatsApp(f.telefono, f.contacto, f.numero, f.saldo)"
                                                class="text-emerald-600 hover:text-emerald-800 p-1" title="Cobrar por WhatsApp" aria-label="Cobrar por WhatsApp">
                                            <Phone class="h-4 w-4"/>
                                        </button>
                                        <Link :href="'/app/facturas/' + f.id"
                                              class="text-surface-500 hover:text-brand-600 p-1" title="Ver detalle" aria-label="Ver detalle">
                                            <ExternalLink class="h-4 w-4"/>
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación -->
                <div v-if="facturas.data.length" class="flex items-center justify-between px-4 py-3 border-t border-surface-200 dark:border-surface-800 text-sm">
                    <div class="text-surface-500">{{ facturas.from }}–{{ facturas.to }} de {{ facturas.total }}</div>
                    <div class="flex items-center gap-1">
                        <template v-for="link in facturas.links" :key="link.label">
                            <Link v-if="link.url" :href="link.url"
                                  :class="['px-2 py-1 rounded text-xs',
                                          link.active ? 'bg-brand-600 text-white' : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800']"
                                  v-html="link.label"/>
                            <span v-else class="px-2 py-1 rounded text-xs text-surface-400" v-html="link.label"/>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Confirmar el cambio de destino · el motivo es obligatorio al salir de lo fiscal -->
            <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                 @click.self="modal = null">
                <div class="card p-5 w-full max-w-md space-y-3">
                    <h3 class="font-bold text-lg">
                        {{ modal.ids.length }} factura(s) → {{ labelDestino(modal.destino) }}
                    </h3>

                    <div v-if="modal.destino !== 'siigo'"
                         class="text-sm bg-amber-50 dark:bg-amber-900/20 border-l-4 border-amber-500 p-3">
                        <template v-if="esReparto">
                            Se declara sólo una parte. El ERP conserva la venta completa —es lo que
                            el cliente debe y lo que movió inventario— y a SIIGO va la porción que digas.
                        </template>
                        <template v-else>
                            Estas facturas dejan de ir a SIIGO. Escribí por qué: es lo que permite
                            explicarlo dentro de tres meses.
                        </template>
                    </div>

                    <!-- Cuánto se declara · sólo en reparto -->
                    <div v-if="esReparto">
                        <label class="text-xs font-semibold text-surface-500">Monto que se declara en SIIGO *</label>
                        <input v-model="montoFiscal" type="number" step="0.01" min="0"
                               class="input w-full mt-1 font-mono" placeholder="20000000"/>
                        <p v-if="totalDeLaFactura" class="text-[11px] text-surface-500 mt-1">
                            Total de la factura: <strong>{{ fmtCOP(totalDeLaFactura) }}</strong>
                            <span v-if="Number(montoFiscal) > 0 && ! montoInvalido">
                                · queda interno {{ fmtCOP(totalDeLaFactura - Number(montoFiscal)) }}
                            </span>
                        </p>
                        <p v-if="montoInvalido" class="text-[11px] text-red-600 font-semibold mt-1">
                            {{ montoInvalido }}
                        </p>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-surface-500">
                            Motivo {{ modal.destino === 'siigo' ? '(opcional)' : '' }}
                        </label>
                        <textarea v-model="motivo" rows="3" class="input w-full mt-1"
                                  placeholder="Ej.: acuerdo comercial · se factura el 20 % · autorizado por gerencia"></textarea>
                    </div>

                    <div class="flex justify-end gap-2 pt-1">
                        <button @click="modal = null" class="btn-secondary text-sm">Cancelar</button>
                        <button @click="confirmarDestino" :disabled="enviando || !! montoInvalido"
                                class="btn-primary text-sm disabled:opacity-40">
                            {{ enviando ? 'Guardando…' : 'Confirmar' }}
                        </button>
                    </div>
                </div>
            </div>
            <ModalFacturaManual v-if="modalManual" :opciones="opciones" @cerrar="modalManual = false"/>
            <ModalImportarExcel v-if="modalExcel" @cerrar="modalExcel = false"/>
        </div>
    </AppLayout>
</template>
