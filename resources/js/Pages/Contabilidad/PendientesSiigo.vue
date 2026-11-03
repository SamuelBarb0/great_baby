<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Cloud, CloudOff, AlertTriangle, AlertOctagon, RefreshCw, ExternalLink, Filter } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasSiigo } from '@/composables/usePestanasContables';
import { useMoney } from '@/composables/useMoney';
import { fechaCorta } from '@/composables/useFecha';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import { mensajeDeError } from '@/composables/useMensajeError';

/*
 * CONT-C1 · Dashboard de documentos pendientes de SIIGO.
 * Aracely entra aquí y ve TODO lo que no llegó a SIIGO en un solo lugar
 * (facturas de venta, recepciones, devoluciones, pagos, NC, ND, asientos). Puede reenviar
 * manual sin ir módulo por módulo.
 */
const props = defineProps({
    resumen: { type: Object, required: true },
    pendientes: { type: Array, required: true },
});

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

const { money } = useMoney();

const filtroTipo = ref('todos');
const filtroError = ref(false); // true = solo con error reciente
const filtroPermanente = ref(false); // CONT-C7 · solo fallas permanentes
const busqueda = ref('');

const tiposLabel = {
    factura: 'Factura de venta',
    recepcion: 'Recepción compra',
    devolucion: 'Devolución proveedor',
    pago_proveedor: 'Pago proveedor',
    nota_credito: 'Nota crédito',
    nota_debito: 'Nota débito',
    asiento_manual: 'Asiento manual',
};
const tiposClass = {
    // Rojo fuerte: es la única con plazo legal ante la DIAN.
    factura: 'bg-rose-100 text-rose-900 font-bold dark:bg-rose-900/50 dark:text-rose-100',
    recepcion: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    devolucion: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    pago_proveedor: 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200',
    nota_credito: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    nota_debito: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    asiento_manual: 'bg-surface-100 text-surface-700 dark:bg-surface-800 dark:text-surface-300',
};

const filtrados = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    return props.pendientes.filter(p => {
        if (filtroTipo.value !== 'todos' && p.tipo !== filtroTipo.value) return false;
        if (filtroError.value && !p.ultimo_error) return false;
        if (filtroPermanente.value && !p.falla_permanente) return false;
        if (q && !p.numero.toLowerCase().includes(q)) return false;
        return true;
    });
});

const reenviando = ref(null);
const toast = ref(null); // {type:'success'|'error', text:string}

// Usamos fetch (no router.post Inertia) porque algunos controllers de reenviar
// devuelven JSON (no un redirect Inertia). Fetch tolera ambos formatos y nos
// deja mostrar un toast + refrescar la lista con router.reload.
const reenviar = async (p) => {
    reenviando.value = `${p.tipo}:${p.id}`;
    try {
        const resp = await fetch(p.url_reenviar, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
        });
        if (resp.ok) {
            toast.value = { type: 'success', text: `${p.numero} encolado a SIIGO · se refrescará automáticamente` };
            setTimeout(() => router.reload({ preserveScroll: true }), 2500);
        } else {
            const body = await resp.text().catch(() => '');
            toast.value = { type: 'error', text: `No se pudo encolar ${p.numero}: HTTP ${resp.status} ${body.slice(0, 120)}` };
        }
    } catch (e) {
        toast.value = { type: 'error', text: mensajeDeError(e, 'No pude reenviar el documento a SIIGO') };
    } finally {
        reenviando.value = null;
        setTimeout(() => { toast.value = null; }, 6000);
    }
};

// Reenviar en lote todos los pendientes visibles (respeta filtros).
const enviandoLote = ref(false);
const enviarTodosVisibles = async () => {
    if (enviandoLote.value) return;
    modalConfirm.value = {
        titulo: `¿Enviar a SIIGO ${filtrados.value.length} documento(s)?`,
        mensaje: 'Se dispara un envío por documento, uno por uno.',
        color: 'sky',
        textoConfirmar: 'Enviar todos',
        onConfirmar: async () => {
            modalConfirm.value = null;
            enviandoLote.value = true;
            try {
                for (const p of filtrados.value) {
                    await fetch(p.url_reenviar, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                    });
                }
                router.reload({ preserveScroll: true });
            } finally {
                enviandoLote.value = false;
            }
        },
    };
};
</script>

<template>
    <Head title="Pendientes SIIGO · Contabilidad"/>
    <AppLayout>
        <!-- Toast flotante · aviso de reenvío. -->
        <Transition enter-active-class="transition-all duration-200" enter-from-class="opacity-0 translate-y-2"
                    leave-active-class="transition-all duration-200" leave-to-class="opacity-0 translate-y-2">
            <div v-if="toast" :class="['fixed bottom-6 right-6 z-50 card p-4 shadow-xl max-w-sm border-l-4',
                                        toast.type === 'success' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/40'
                                                                 : 'border-red-500 bg-red-50 dark:bg-red-950/40']">
                <div class="flex items-start gap-2">
                    <Cloud v-if="toast.type === 'success'" class="h-5 w-5 text-emerald-600 flex-shrink-0 mt-0.5"/>
                    <AlertTriangle v-else class="h-5 w-5 text-red-600 flex-shrink-0 mt-0.5"/>
                    <div class="text-sm" :class="toast.type === 'success' ? 'text-emerald-800 dark:text-emerald-200' : 'text-red-800 dark:text-red-200'">
                        {{ toast.text }}
                    </div>
                </div>
            </div>
        </Transition>

        <div class="max-w-7xl mx-auto space-y-4">
            <PestanasSeccion :pestanas="pestanasSiigo"/>

            <!-- Header + KPIs -->
            <div class="card p-5">
                <div class="flex items-center gap-3 mb-4">
                    <CloudOff class="h-6 w-6 text-red-500"/>
                    <div>
                        <h1 class="text-2xl font-bold">Pendientes SIIGO</h1>
                        <p class="text-xs text-surface-500">Documentos confirmados en el ERP que aún no llegaron a SIIGO.</p>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-8 gap-2 text-center">
                    <button @click="filtroTipo = 'todos'; filtroPermanente = false" :class="['card p-3 border-2', filtroTipo==='todos' && !filtroPermanente ? 'border-brand-500' : 'border-transparent']">
                        <div class="text-2xl font-bold text-red-600">{{ resumen.total }}</div>
                        <div class="text-[10px] uppercase text-surface-500">Total</div>
                    </button>
                    <!-- CONT-C7 · KPI de fallas permanentes · click lo filtra. -->
                    <button @click="filtroPermanente = true; filtroTipo = 'todos'" :class="['card p-3 border-2', filtroPermanente ? 'border-red-500' : 'border-transparent', resumen.fallas_permanentes > 0 && !filtroPermanente ? 'ring-2 ring-red-200 animate-pulse' : '']">
                        <div class="text-2xl font-bold" :class="resumen.fallas_permanentes > 0 ? 'text-red-700' : 'text-surface-400'">
                            {{ resumen.fallas_permanentes }}
                        </div>
                        <div class="text-[10px] uppercase text-surface-500">Falla perm.</div>
                    </button>
                    <button @click="filtroTipo = 'factura'"
                            :class="['card p-3 border-2', filtroTipo==='factura' ? 'border-rose-500' : 'border-transparent',
                                     resumen.facturas > 0 ? 'ring-2 ring-rose-200' : '']">
                        <div class="text-xl font-bold" :class="resumen.facturas > 0 ? 'text-rose-700' : ''">
                            {{ resumen.facturas }}
                        </div>
                        <div class="text-[10px] uppercase text-surface-500">Facturas</div>
                    </button>
                    <button @click="filtroTipo = 'recepcion'" :class="['card p-3 border-2', filtroTipo==='recepcion' ? 'border-brand-500' : 'border-transparent']">
                        <div class="text-xl font-bold">{{ resumen.recepciones }}</div>
                        <div class="text-[10px] uppercase text-surface-500">Recepciones</div>
                    </button>
                    <button @click="filtroTipo = 'devolucion'" :class="['card p-3 border-2', filtroTipo==='devolucion' ? 'border-brand-500' : 'border-transparent']">
                        <div class="text-xl font-bold">{{ resumen.devoluciones }}</div>
                        <div class="text-[10px] uppercase text-surface-500">Devoluciones</div>
                    </button>
                    <button @click="filtroTipo = 'pago_proveedor'" :class="['card p-3 border-2', filtroTipo==='pago_proveedor' ? 'border-brand-500' : 'border-transparent']">
                        <div class="text-xl font-bold">{{ resumen.pagos }}</div>
                        <div class="text-[10px] uppercase text-surface-500">Pagos prov.</div>
                    </button>
                    <button @click="filtroTipo = 'nota_credito'" :class="['card p-3 border-2', filtroTipo==='nota_credito' ? 'border-brand-500' : 'border-transparent']">
                        <div class="text-xl font-bold">{{ resumen.notas_credito }}</div>
                        <div class="text-[10px] uppercase text-surface-500">NC</div>
                    </button>
                    <button @click="filtroTipo = 'nota_debito'" :class="['card p-3 border-2', filtroTipo==='nota_debito' ? 'border-brand-500' : 'border-transparent']">
                        <div class="text-xl font-bold">{{ resumen.notas_debito }}</div>
                        <div class="text-[10px] uppercase text-surface-500">ND</div>
                    </button>
                    <button @click="filtroTipo = 'asiento_manual'" :class="['card p-3 border-2', filtroTipo==='asiento_manual' ? 'border-brand-500' : 'border-transparent']">
                        <div class="text-xl font-bold">{{ resumen.asientos }}</div>
                        <div class="text-[10px] uppercase text-surface-500">Asientos</div>
                    </button>
                </div>
            </div>

            <!-- CONT-C7 · Banner falla permanente -->
            <div v-if="resumen.fallas_permanentes > 0" class="card p-4 border-l-4 border-red-600 bg-red-50/60 dark:bg-red-950/20">
                <div class="flex items-start gap-3">
                    <AlertOctagon class="h-6 w-6 text-red-600 flex-shrink-0 mt-0.5 animate-pulse"/>
                    <div class="flex-1">
                        <h2 class="font-bold text-red-800 dark:text-red-200">
                            {{ resumen.fallas_permanentes }} documento(s) con falla permanente
                        </h2>
                        <p class="text-xs text-surface-600 dark:text-surface-400 mt-1">
                            Han fallado 3+ veces en los últimos 7 días. Laravel dejó de reintentar — requieren intervención manual.
                            Revisa el error, corrige la causa (config SIIGO, datos, mapeo PUC) y reenvía desde el botón de cada fila.
                        </p>
                        <button @click="filtroPermanente = true; filtroTipo = 'todos'" class="btn-ghost text-xs mt-2 border border-red-300">
                            <AlertOctagon class="h-3 w-3"/> Ver solo fallas permanentes
                        </button>
                    </div>
                </div>
            </div>

            <!-- Filtros + acción en lote -->
            <div class="card p-3 flex items-center gap-3 flex-wrap">
                <Filter class="h-4 w-4 text-surface-500"/>
                <input v-model="busqueda" type="search" placeholder="Buscar número…" class="input text-sm flex-1 min-w-[180px]"/>
                <label class="flex items-center gap-2 text-xs text-surface-600 dark:text-surface-300">
                    <input type="checkbox" v-model="filtroError"/>
                    Con error reciente
                </label>
                <label class="flex items-center gap-2 text-xs text-red-700 dark:text-red-300 font-semibold">
                    <input type="checkbox" v-model="filtroPermanente"/>
                    Fallas permanentes
                </label>
                <div class="text-xs text-surface-500">{{ filtrados.length }} resultado(s)</div>
                <button @click="enviarTodosVisibles" :disabled="enviandoLote || filtrados.length === 0"
                        class="btn-primary text-xs ml-auto">
                    <RefreshCw :class="['h-3 w-3', enviandoLote && 'animate-spin']"/>
                    {{ enviandoLote ? 'Enviando…' : `Enviar ${filtrados.length} a SIIGO` }}
                </button>
            </div>

            <!-- Tabla · acciones en la PRIMERA columna (siempre visibles) + scroll
                 horizontal del resto si el viewport es angosto. -->
            <div class="card p-0 overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm min-w-[900px]">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900/50">
                        <tr>
                            <th class="text-left p-3 w-[200px] sticky left-0 bg-surface-50 dark:bg-surface-900/50 z-10">Acciones</th>
                            <th class="text-left p-3">Tipo</th>
                            <th class="text-left p-3">Documento</th>
                            <th class="text-left p-3">Fecha</th>
                            <th class="text-right p-3">Monto</th>
                            <th class="text-left p-3">Último error</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-200 dark:divide-surface-800">
                        <tr v-for="p in filtrados" :key="`${p.tipo}:${p.id}`"
                            :class="['hover:bg-surface-50 dark:hover:bg-surface-900/40',
                                     p.falla_permanente && 'bg-red-50/50 dark:bg-red-950/10']">
                            <td class="p-3 sticky left-0 bg-white dark:bg-surface-950 z-10 border-r border-surface-100 dark:border-surface-800"
                                :class="p.falla_permanente && 'bg-red-50/80 dark:bg-red-950/30'">
                                <div class="flex items-center gap-1">
                                    <button @click="reenviar(p)" :disabled="reenviando === `${p.tipo}:${p.id}`" class="btn-primary text-xs">
                                        <RefreshCw :class="['h-3 w-3', reenviando === `${p.tipo}:${p.id}` && 'animate-spin']"/>
                                        {{ reenviando === `${p.tipo}:${p.id}` ? '…' : 'Enviar a SIIGO' }}
                                    </button>
                                    <Link :href="p.url_ver" class="btn-ghost text-xs" title="Ver detalle">
                                        <ExternalLink class="h-3 w-3"/>
                                    </Link>
                                </div>
                            </td>
                            <td class="p-3">
                                <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', tiposClass[p.tipo]]">{{ tiposLabel[p.tipo] }}</span>
                                <div v-if="p.falla_permanente" class="mt-1 inline-flex items-center gap-1 text-[10px] font-bold text-red-700 dark:text-red-300" :title="`${p.fallos} intentos`">
                                    <AlertOctagon class="h-3 w-3"/> Falla permanente ({{ p.fallos }}×)
                                </div>
                            </td>
                            <td class="p-3 font-mono text-xs">{{ p.numero }}</td>
                            <td class="p-3 text-surface-600 dark:text-surface-400">{{ fechaCorta(p.fecha) }}</td>
                            <td class="p-3 text-right font-semibold">{{ money(p.monto) }}</td>
                            <td class="p-3 max-w-md">
                                <div v-if="p.ultimo_error" class="flex items-start gap-1">
                                    <AlertTriangle class="h-3 w-3 text-red-500 flex-shrink-0 mt-0.5"/>
                                    <div class="min-w-0">
                                        <div class="text-[11px] text-red-700 dark:text-red-400 truncate" :title="p.ultimo_error">{{ p.ultimo_error }}</div>
                                        <div class="text-[10px] text-surface-500">{{ fechaCorta(p.ultimo_error_at) }}</div>
                                    </div>
                                </div>
                                <span v-else class="text-xs text-surface-400 italic">sin intento aún</span>
                            </td>
                        </tr>
                        <tr v-if="filtrados.length === 0">
                            <td colspan="6" class="p-10 text-center text-surface-500">
                                <Cloud class="h-10 w-10 mx-auto mb-2 text-emerald-500"/>
                                <div class="font-bold">¡Todo sincronizado!</div>
                                <div class="text-xs">No hay documentos pendientes con los filtros actuales.</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
