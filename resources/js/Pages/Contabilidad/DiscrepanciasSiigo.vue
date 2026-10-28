<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasSiigo } from '@/composables/usePestanasContables';
import { mensajeDeError } from '@/composables/useMensajeError';
import { AlertTriangle, RefreshCw, Eye, Activity, CheckCircle2, Ghost, Scale, XCircle } from 'lucide-vue-next';

const props = defineProps({
    rango: Object,
    huerfanos: Array,
    deltas: Array,
    fallidos: Array,
    kpis: Object,
});

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);

const desde = ref(props.rango.desde);
const hasta = ref(props.rango.hasta);
const filtrar = () => router.get('/app/contabilidad/discrepancias-siigo',
    { desde: desde.value, hasta: hasta.value },
    { preserveState: true, preserveScroll: true });

const semaforoColor = {
    verde: 'bg-emerald-500',
    amarillo: 'bg-amber-500',
    rojo: 'bg-red-500',
}[props.kpis.semaforo] || 'bg-surface-400';

const verSiigoDiff = ref(null);
const verSiigoLoading = ref(false);
const verSiigo = async (asientoId) => {
    verSiigoLoading.value = true;
    verSiigoDiff.value = null;
    try {
        const r = await fetch(`/app/contabilidad/asientos-manuales/${asientoId}/ver-siigo`, {
            headers: { Accept: 'application/json' },
        });
        verSiigoDiff.value = await r.json();
    } catch (e) {
        verSiigoDiff.value = { ok: false, mensaje: mensajeDeError(e, 'No pude traer el asiento de SIIGO') };
    } finally {
        verSiigoLoading.value = false;
    }
};

const reintentar = (id) => {
    router.post(`/app/contabilidad/asientos-manuales/${id}/reenviar-siigo`,
        {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Discrepancias contables SIIGO"/>
    <AppLayout>
        <div class="space-y-5 max-w-7xl mx-auto">
            <PestanasSeccion :pestanas="pestanasSiigo"/>

            <!-- Header + filtros -->
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <AlertTriangle class="h-6 w-6 text-amber-600"/>
                        Discrepancias ERP ↔ SIIGO · Contabilidad
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Asientos que no cuadran contra SIIGO en el rango seleccionado.
                    </p>
                </div>
                <div class="flex items-end gap-2 flex-wrap">
                    <div>
                        <label class="block text-[10px] uppercase text-surface-500 mb-0.5">Desde</label>
                        <input type="date" v-model="desde" class="px-2 py-1.5 border rounded text-xs"/>
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase text-surface-500 mb-0.5">Hasta</label>
                        <input type="date" v-model="hasta" class="px-2 py-1.5 border rounded text-xs"/>
                    </div>
                    <button @click="filtrar" class="px-3 py-1.5 bg-brand-600 text-white rounded text-xs">Aplicar</button>
                </div>
            </div>

            <!-- Semáforo + KPIs -->
            <div class="card p-5 flex items-center gap-4 flex-wrap">
                <div class="flex items-center gap-3">
                    <div :class="['w-5 h-5 rounded-full ring-4 ring-opacity-30 ring-current', semaforoColor]"></div>
                    <div>
                        <div class="text-xs uppercase font-semibold text-surface-500">Semáforo</div>
                        <div class="text-xl font-black uppercase">{{ kpis.semaforo }}</div>
                    </div>
                </div>
                <div class="h-10 border-l border-surface-200 dark:border-surface-800"></div>
                <div class="flex items-center gap-6 flex-wrap text-sm">
                    <div>
                        <div class="text-[10px] uppercase text-surface-500">% sincronizado</div>
                        <div class="font-black text-lg">{{ kpis.pct_sync }}%</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase text-surface-500">Aprobados</div>
                        <div class="font-black text-lg">{{ kpis.total_aprobados }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase text-surface-500">En SIIGO</div>
                        <div class="font-black text-lg text-emerald-700">{{ kpis.sincronizados }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase text-surface-500">Huérfanos</div>
                        <div class="font-black text-lg text-red-700">{{ kpis.huerfanos }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase text-surface-500">Con delta $</div>
                        <div class="font-black text-lg text-amber-700">{{ kpis.con_delta }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase text-surface-500">Fallidos 14d</div>
                        <div class="font-black text-lg text-red-700">{{ kpis.fallidos_14d }}</div>
                    </div>
                </div>
            </div>

            <!-- HUÉRFANOS: aprobados sin siigo_journal_id -->
            <div class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-800 flex items-center justify-between">
                    <h3 class="font-semibold flex items-center gap-2">
                        <Ghost class="h-5 w-5 text-red-600"/>
                        Huérfanos · aprobados sin llegar a SIIGO
                    </h3>
                    <span class="px-2 py-0.5 bg-red-100 text-red-800 text-xs rounded font-semibold">
                        {{ huerfanos.length }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900">
                            <tr>
                                <th class="p-3 text-left">#</th>
                                <th class="p-3 text-left">Fecha</th>
                                <th class="p-3 text-left">Glosa</th>
                                <th class="p-3 text-right">Valor</th>
                                <th class="p-3 text-right">Edad</th>
                                <th class="p-3 text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-if="!huerfanos.length">
                                <td colspan="6" class="text-center py-6 text-emerald-600">
                                    <CheckCircle2 class="h-6 w-6 mx-auto mb-2"/>
                                    Todo aprobado tiene su contraparte en SIIGO. ✨
                                </td>
                            </tr>
                            <tr v-for="a in huerfanos" :key="a.id" class="hover:bg-red-50/40 dark:hover:bg-red-950/20">
                                <td class="p-3 font-mono text-xs">AM-{{ a.id }}</td>
                                <td class="p-3 text-xs">{{ a.fecha }}</td>
                                <td class="p-3 text-xs truncate max-w-xs">{{ a.glosa }}</td>
                                <td class="p-3 text-right font-bold">{{ money(a.valor_total) }}</td>
                                <td class="p-3 text-right text-xs text-red-700 font-semibold">
                                    {{ a.edad_horas }}h
                                </td>
                                <td class="p-3 text-center">
                                    <button @click="reintentar(a.id)"
                                            class="inline-flex items-center gap-1 px-2 py-1 bg-brand-50 hover:bg-brand-100 text-brand-700 rounded text-xs font-semibold">
                                        <RefreshCw class="h-3 w-3"/> Reintentar
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- DELTAS: cuadre interno distinto al total -->
            <div class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-800 flex items-center justify-between">
                    <h3 class="font-semibold flex items-center gap-2">
                        <Scale class="h-5 w-5 text-amber-600"/>
                        Delta $ · asiento con cuadre interno distinto
                    </h3>
                    <span class="px-2 py-0.5 bg-amber-100 text-amber-800 text-xs rounded font-semibold">
                        {{ deltas.length }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900">
                            <tr>
                                <th class="p-3 text-left">#</th>
                                <th class="p-3 text-left">Fecha</th>
                                <th class="p-3 text-right">Valor cabecera</th>
                                <th class="p-3 text-right">Suma debe</th>
                                <th class="p-3 text-right">Suma haber</th>
                                <th class="p-3 text-right">Δ</th>
                                <th class="p-3 text-left">SIIGO ID</th>
                                <th class="p-3 text-center">Ver</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-if="!deltas.length">
                                <td colspan="8" class="text-center py-6 text-emerald-600">
                                    <CheckCircle2 class="h-6 w-6 mx-auto mb-2"/>
                                    No hay asientos con delta. Cuadra todo. ✨
                                </td>
                            </tr>
                            <tr v-for="a in deltas" :key="a.id" class="hover:bg-amber-50/40 dark:hover:bg-amber-950/20">
                                <td class="p-3 font-mono text-xs">AM-{{ a.id }}</td>
                                <td class="p-3 text-xs">{{ a.fecha }}</td>
                                <td class="p-3 text-right font-bold">{{ money(a.valor_total) }}</td>
                                <td class="p-3 text-right">{{ money(a.suma_debe) }}</td>
                                <td class="p-3 text-right">{{ money(a.suma_haber) }}</td>
                                <td class="p-3 text-right font-bold text-amber-700">{{ money(a.delta) }}</td>
                                <td class="p-3 font-mono text-[10px] text-surface-600">{{ a.siigo_journal_id }}</td>
                                <td class="p-3 text-center">
                                    <button @click="verSiigo(a.id)"
                                            class="inline-flex items-center gap-1 px-2 py-1 bg-surface-100 hover:bg-surface-200 text-surface-700 rounded text-xs font-semibold">
                                        <Eye class="h-3 w-3"/> SIIGO
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- FALLIDOS: errores recientes del log -->
            <div class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-800 flex items-center justify-between">
                    <h3 class="font-semibold flex items-center gap-2">
                        <XCircle class="h-5 w-5 text-red-600"/>
                        Errores recientes de SIIGO (últimos 14 días)
                    </h3>
                    <span class="px-2 py-0.5 bg-red-100 text-red-800 text-xs rounded font-semibold">
                        {{ fallidos.length }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900">
                            <tr>
                                <th class="p-3 text-left">Asiento</th>
                                <th class="p-3 text-left">Mensaje</th>
                                <th class="p-3 text-left">Cuándo</th>
                                <th class="p-3 text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-if="!fallidos.length">
                                <td colspan="4" class="text-center py-6 text-emerald-600 italic">
                                    Sin errores recientes.
                                </td>
                            </tr>
                            <tr v-for="f in fallidos" :key="f.log_id" class="hover:bg-red-50/40 dark:hover:bg-red-950/20">
                                <td class="p-3 font-mono text-xs">AM-{{ f.asiento_id }}</td>
                                <td class="p-3 text-xs text-red-700">{{ f.mensaje }}</td>
                                <td class="p-3 text-xs text-surface-600">{{ f.when }}</td>
                                <td class="p-3 text-center">
                                    <button @click="reintentar(f.asiento_id)"
                                            class="inline-flex items-center gap-1 px-2 py-1 bg-brand-50 hover:bg-brand-100 text-brand-700 rounded text-xs font-semibold">
                                        <RefreshCw class="h-3 w-3"/> Reintentar
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal diff "Ver en SIIGO" -->
            <div v-if="verSiigoDiff || verSiigoLoading"
                 class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4"
                 @click.self="verSiigoDiff = null">
                <div class="bg-white dark:bg-surface-900 rounded-xl shadow-2xl max-w-2xl w-full max-h-[85vh] overflow-y-auto">
                    <div class="px-5 py-3 border-b border-surface-200 dark:border-surface-800 flex items-center justify-between">
                        <h3 class="font-semibold flex items-center gap-2">
                            <Activity class="h-5 w-5 text-brand-600"/>
                            Diff ERP ↔ SIIGO
                        </h3>
                        <button @click="verSiigoDiff = null" class="text-surface-500 hover:text-surface-700">✕</button>
                    </div>
                    <div class="p-5">
                        <div v-if="verSiigoLoading" class="text-center py-6 text-surface-500">
                            Consultando SIIGO…
                        </div>
                        <div v-else-if="verSiigoDiff && !verSiigoDiff.ok" class="text-sm">
                            <div class="p-3 bg-red-50 border-l-4 border-red-500 text-red-900 rounded">
                                <div class="font-bold">⚠ {{ verSiigoDiff.reason || 'Error' }}</div>
                                <div class="mt-1 text-xs">{{ verSiigoDiff.mensaje }}</div>
                            </div>
                        </div>
                        <div v-else-if="verSiigoDiff && verSiigoDiff.ok" class="text-sm space-y-3">
                            <div class="text-xs text-surface-500">
                                SIIGO journal: <span class="font-mono">{{ verSiigoDiff.siigo_journal_id }}</span>
                            </div>
                            <table v-tabla-movil class="w-full text-sm">
                                <thead class="text-xs uppercase text-surface-500 border-b">
                                    <tr>
                                        <th class="p-2 text-left">Campo</th>
                                        <th class="p-2 text-right">ERP</th>
                                        <th class="p-2 text-right">SIIGO</th>
                                        <th class="p-2 text-center">OK</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="border-b">
                                        <td class="p-2">Fecha</td>
                                        <td class="p-2 text-right font-mono text-xs">{{ verSiigoDiff.diff.fecha.erp }}</td>
                                        <td class="p-2 text-right font-mono text-xs">{{ verSiigoDiff.diff.fecha.siigo }}</td>
                                        <td class="p-2 text-center">
                                            <CheckCircle2 v-if="verSiigoDiff.diff.fecha.coincide" class="h-4 w-4 text-emerald-600 inline"/>
                                            <XCircle v-else class="h-4 w-4 text-red-600 inline"/>
                                        </td>
                                    </tr>
                                    <tr class="border-b">
                                        <td class="p-2">Total</td>
                                        <td class="p-2 text-right font-bold">{{ money(verSiigoDiff.diff.total.erp) }}</td>
                                        <td class="p-2 text-right font-bold">{{ money(verSiigoDiff.diff.total.siigo) }}</td>
                                        <td class="p-2 text-center">
                                            <CheckCircle2 v-if="verSiigoDiff.diff.total.coincide" class="h-4 w-4 text-emerald-600 inline"/>
                                            <XCircle v-else class="h-4 w-4 text-red-600 inline"/>
                                        </td>
                                    </tr>
                                    <tr v-if="verSiigoDiff.diff.total.delta !== 0" class="border-b">
                                        <td class="p-2 text-xs text-amber-700">↳ delta</td>
                                        <td colspan="2" class="p-2 text-right font-bold text-amber-700">
                                            {{ money(verSiigoDiff.diff.total.delta) }}
                                        </td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td class="p-2">Líneas</td>
                                        <td class="p-2 text-right">{{ verSiigoDiff.diff.lineas.erp }}</td>
                                        <td class="p-2 text-right">{{ verSiigoDiff.diff.lineas.siigo }}</td>
                                        <td class="p-2 text-center">
                                            <CheckCircle2 v-if="verSiigoDiff.diff.lineas.coincide" class="h-4 w-4 text-emerald-600 inline"/>
                                            <XCircle v-else class="h-4 w-4 text-red-600 inline"/>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <details class="mt-3 text-xs">
                                <summary class="cursor-pointer text-surface-500 hover:text-surface-700">Ver respuesta SIIGO cruda</summary>
                                <pre class="mt-2 p-2 bg-surface-100 dark:bg-surface-800 rounded text-[10px] overflow-x-auto">{{ JSON.stringify(verSiigoDiff.siigo, null, 2) }}</pre>
                            </details>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
