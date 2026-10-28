<script setup>
import { ref, watch, computed, nextTick } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { FileSearch, ArrowLeft, Search, AlertTriangle, Eye, EyeOff, Cloud, CloudOff, ExternalLink } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasReportes } from '@/composables/usePestanasContables';
import { useMoney } from '@/composables/useMoney';
import { pucLabel } from '@/composables/pucLabels';
import { fechaCorta } from '@/composables/useFecha';

const props = defineProps({
    tipo: String, id: Number, movimientos: Array, totales: Object,
    meta: { type: Object, default: null },
    incluir_anulados: { type: Boolean, default: false },
    anulados_count: { type: Number, default: 0 },
});
const { money } = useMoney();

// Re-audit R2 UX-C1 · el tipo YA NO se fuerza al buscar. Se conserva el
// contexto original (factura/pago/nc); el select expone el cambio explícito.
const tipo = ref(props.tipo || 'factura');
const id = ref(props.id || null);
const incluirAnulados = ref(!!props.incluir_anulados);

// Re-audit R2 UX-A4 · loading state durante router.get.
const cargando = ref(false);

// Buscador con guard anti-doble-fetch (UX-C3).
const buscar = ref('');
const resultados = ref([]);
const buscando = ref(false);
const seleccionado = ref(null);
// Bandera: cuando `seleccionar()` asigna buscar.value, el watch NO debe re-fetchear.
let ignorarProximoWatch = false;
let deb;
watch(buscar, () => {
    if (ignorarProximoWatch) { ignorarProximoWatch = false; return; }
    clearTimeout(deb);
    if (!buscar.value || buscar.value.trim().length < 2) { resultados.value = []; return; }
    // Re-audit R2 UX-C2 · el scope solo cubre facturas; los pagos/NC entran
    // por el módulo Cartera. Mostramos aviso en la UI.
    deb = setTimeout(async () => {
        buscando.value = true;
        try {
            const url = '/app/api/contactos/buscar?q=' + encodeURIComponent(buscar.value.trim()) + '&scope=facturas';
            const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (res.ok) resultados.value = await res.json();
        } catch { resultados.value = []; }
        finally { buscando.value = false; }
    }, 300);
});

const seleccionar = (r) => {
    seleccionado.value = r;
    tipo.value = 'factura';
    id.value = r.factura_id;
    ignorarProximoWatch = true;
    buscar.value = `${r.numero} · ${r.contacto}`;
    resultados.value = [];
    nextTick(() => consultar());
};

const consultar = () => router.get('/app/contabilidad/reporte-detalle',
    { tipo: tipo.value, id: id.value, incluir_anulados: incluirAnulados.value ? 1 : null },
    {
        preserveScroll: true, preserveState: true, replace: true,
        onStart: () => { cargando.value = true; },
        onFinish: () => { cargando.value = false; },
    });

// Auto-recargar al cambiar el toggle si ya hay un id cargado.
watch(incluirAnulados, () => { if (id.value) consultar(); });

const tieneMeta = computed(() => props.meta && props.meta.numero);
</script>

<template>
    <Head :title="`Asiento ${meta?.numero || ''}`"/>
    <AppLayout>
        <div class="max-w-4xl mx-auto space-y-4">
            <PestanasSeccion :pestanas="pestanasReportes"/>

            <Link href="/app/contabilidad/reportes" class="text-sm text-brand-600 hover:underline inline-flex items-center gap-1">
                <ArrowLeft class="h-4 w-4"/> Volver a Reportes
            </Link>
            <h1 class="text-2xl font-bold flex items-center gap-2"><FileSearch class="h-6 w-6 text-brand-600"/>Detalle del asiento contable</h1>

            <div class="card p-4 space-y-3">
                <div class="relative">
                    <label class="block text-xs font-semibold text-surface-600 dark:text-surface-300 mb-1">Buscar factura (por número o cliente)</label>
                    <div class="relative">
                        <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-400"/>
                        <input v-model="buscar" class="input w-full pl-10" placeholder="Ej: FV-0025 o Distribuidora El Sol" autocomplete="off"/>
                    </div>
                    <div v-if="buscando" class="text-[11px] text-surface-500 mt-1">Buscando…</div>
                    <div v-if="resultados.length" class="absolute z-10 left-0 right-0 mt-1 max-h-64 overflow-y-auto rounded-lg border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900 shadow-lg">
                        <button v-for="r in resultados" :key="r.factura_id" type="button" @click="seleccionar(r)"
                                class="w-full text-left px-3 py-2 hover:bg-surface-50 dark:hover:bg-surface-800 border-b border-surface-100 dark:border-surface-800 last:border-b-0">
                            <div class="flex items-center justify-between text-sm">
                                <span class="font-mono font-semibold">{{ r.numero }}</span>
                                <span class="text-[11px] text-surface-500">saldo {{ money(r.saldo) }}</span>
                            </div>
                            <div class="text-xs text-surface-700 dark:text-surface-300 truncate">{{ r.contacto }}</div>
                        </button>
                    </div>
                    <p class="text-[11px] text-surface-500 mt-1">Para consultar asientos de pagos o notas crédito, entra desde el módulo Cartera y click en el número.</p>
                </div>
                <!-- Re-audit R4 UX-B3 · paridad visual con Index/Panel (Eye/EyeOff). -->
                <label class="flex items-center gap-2 text-xs font-semibold cursor-pointer select-none"
                       title="Incluye asientos revertidos por corrección o anulación (retención legal 5 años).">
                    <input type="checkbox" v-model="incluirAnulados" class="rounded border-surface-300"/>
                    <EyeOff v-if="!incluirAnulados" class="h-3 w-3 text-surface-500"/>
                    <Eye v-else class="h-3 w-3 text-amber-600"/>
                    Incluir asientos anulados
                </label>
            </div>

            <div v-if="tieneMeta" class="card p-4">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-2 flex items-center gap-2">
                    Documento origen
                    <span v-if="meta.anulado" class="text-[10px] font-bold text-red-600 uppercase bg-red-50 dark:bg-red-950/40 px-2 py-0.5 rounded">Documento anulado</span>
                </div>
                <div class="flex flex-wrap gap-4 text-sm">
                    <div><span class="text-surface-500">Número:</span> <span class="font-mono font-semibold">{{ meta.numero }}</span></div>
                    <div v-if="meta.fecha"><span class="text-surface-500">Fecha:</span> {{ fechaCorta(meta.fecha) }}</div>
                    <div v-if="meta.tercero !== '—'"><span class="text-surface-500">Tercero:</span> {{ meta.tercero }}</div>
                    <div v-if="meta.total"><span class="text-surface-500">Total:</span> <span class="font-bold">{{ money(meta.total) }}</span></div>
                </div>

                <!-- Sprint 3 · F.3 · badge SIIGO del documento origen -->
                <div class="mt-3 pt-3 border-t flex items-start gap-3">
                    <template v-if="meta.siigo_id">
                        <Cloud class="h-5 w-5 text-emerald-600 mt-0.5 flex-shrink-0"/>
                        <div class="flex-1 text-sm">
                            <div class="font-semibold text-emerald-700 flex items-center gap-2">
                                Sincronizado con SIIGO
                                <span v-if="meta.es_electronica && meta.stamp_status" class="text-[10px] uppercase font-bold px-2 py-0.5 rounded"
                                      :class="meta.stamp_status.toLowerCase() === 'accepted' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                                    DIAN {{ meta.stamp_status }}
                                </span>
                            </div>
                            <div class="text-xs text-surface-500 mt-1">
                                <span v-if="meta.siigo_numero">Nº <span class="font-mono">{{ meta.siigo_numero }}</span> · </span>
                                <span v-if="meta.siigo_sync_hace">{{ meta.siigo_sync_hace }}</span>
                            </div>
                            <div v-if="meta.cufe" class="text-[10px] font-mono text-surface-400 mt-1 truncate max-w-lg" :title="meta.cufe">
                                CUFE: {{ meta.cufe.slice(0, 32) }}…
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        <CloudOff class="h-5 w-5 text-amber-600 mt-0.5 flex-shrink-0"/>
                        <div class="flex-1 text-sm">
                            <div class="font-semibold text-amber-700">Pendiente de sincronizar con SIIGO</div>
                            <div class="text-xs text-surface-500 mt-1">
                                El documento existe en el ERP pero aún no tiene contraparte en SIIGO.
                                Verifica el <a href="/app/siigo" class="text-brand-600 underline">panel de sync SIIGO</a> para ver estado o reintentar.
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Re-audit R2 DATOS-A3 · aviso cuando hay reversos ocultos y el toggle está apagado. -->
            <div v-if="anulados_count > 0 && !incluirAnulados" class="card p-3 border-l-4 border-amber-500 bg-amber-50 dark:bg-amber-950/30 text-sm flex items-start gap-2">
                <AlertTriangle class="h-4 w-4 text-amber-600 mt-0.5 flex-shrink-0"/>
                <div>
                    <b>{{ anulados_count }} asiento{{ anulados_count !== 1 ? 's' : '' }} anulado{{ anulados_count !== 1 ? 's' : '' }}</b>
                    no {{ anulados_count === 1 ? 'aparece' : 'aparecen' }} en la lista. Activa "Incluir asientos anulados" para verlo{{ anulados_count !== 1 ? 's' : '' }}.
                </div>
            </div>

            <div v-if="movimientos.length" class="card p-4" :class="cargando ? 'opacity-60 pointer-events-none' : ''">
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 border-b">
                            <tr><th class="text-left p-2">Fecha</th><th class="text-left p-2">Cuenta</th><th class="text-left p-2">Descripción</th><th class="text-right p-2">Débito</th><th class="text-right p-2">Crédito</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="m in movimientos" :key="m.id"
                                :class="m.anulado ? 'bg-red-50/50 dark:bg-red-950/20 opacity-70' : ''">
                                <td class="p-2 text-xs">
                                    {{ fechaCorta(m.fecha) }}
                                    <span v-if="m.anulado" class="ml-1 text-[10px] font-bold text-red-600 uppercase">Anulado</span>
                                </td>
                                <td class="p-2">
                                    <div class="font-mono font-bold text-brand-600">{{ m.cuenta }}</div>
                                    <div class="text-[10px] text-surface-500">{{ pucLabel(m.cuenta) }}</div>
                                </td>
                                <td class="p-2 text-xs">{{ m.descripcion }}</td>
                                <td class="p-2 text-right text-blue-600">{{ m.debe > 0 ? money(m.debe) : '—' }}</td>
                                <td class="p-2 text-right text-purple-600">{{ m.haber > 0 ? money(m.haber) : '—' }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t-2 font-bold">
                            <tr>
                                <td colspan="3" class="p-2 text-right">Totales</td>
                                <td class="p-2 text-right text-blue-600">{{ money(totales.debe) }}</td>
                                <td class="p-2 text-right text-purple-600">{{ money(totales.haber) }}</td>
                            </tr>
                            <tr :class="Math.abs(totales.diff) > 0.01 ? 'text-red-600' : 'text-emerald-600'">
                                <td colspan="4" class="p-2 text-right">Diferencia (debe − haber)</td>
                                <td class="p-2 text-right">{{ money(totales.diff) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <!-- Re-audit R4 UX-M2 · mensajes específicos según caso, sin copy que asuste. -->
            <div v-else-if="tieneMeta && meta.anulado" class="card p-8 text-center text-sm">
                <AlertTriangle class="h-6 w-6 mx-auto text-red-500 mb-2"/>
                <p class="text-surface-700 dark:text-surface-300">Este documento fue anulado y su asiento contable ya no se conserva vigente.</p>
            </div>
            <div v-else-if="tieneMeta && anulados_count === 0" class="card p-8 text-center text-sm">
                <AlertTriangle class="h-6 w-6 mx-auto text-amber-500 mb-2"/>
                <p class="text-surface-700 dark:text-surface-300">No hay asiento contable para este documento.</p>
                <p class="text-xs text-surface-500 mt-1">Puede que aún no se haya contabilizado.</p>
            </div>
            <div v-else-if="!tieneMeta" class="card p-8 text-center text-surface-500 text-sm">
                Busca una factura arriba para ver su asiento contable.
            </div>
        </div>
    </AppLayout>
</template>
