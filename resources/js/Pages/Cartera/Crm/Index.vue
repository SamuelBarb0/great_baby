<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Users, MessageSquare, TrendingUp, Zap, Clock, User, RefreshCw, AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useEscClose } from '@/composables/useEscClose';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    segmentos: { type: Array, required: true },
    interaccionesRecientes: { type: Array, required: true },
    comisionesUltimoMes: { type: Object, required: true },
    tiposInteraccion: { type: Array, required: true },
    fuentes: { type: Array, default: () => [] },
});

const tab = ref('segmentacion'); // segmentacion | fuentes | interacciones | comisiones

const { money: fmtCOP } = useMoney();

const segColor = (color) => ({
    emerald: 'bg-emerald-50 border-emerald-500 text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200',
    blue: 'bg-blue-50 border-blue-500 text-blue-800 dark:bg-blue-950/30 dark:text-blue-200',
    amber: 'bg-amber-50 border-amber-500 text-amber-800 dark:bg-amber-950/30 dark:text-amber-200',
    purple: 'bg-purple-50 border-purple-500 text-purple-800 dark:bg-purple-950/30 dark:text-purple-200',
    red: 'bg-red-50 border-red-500 text-red-800 dark:bg-red-950/30 dark:text-red-200',
    gray: 'bg-slate-50 border-slate-400 text-slate-700 dark:bg-slate-900/30 dark:text-slate-200',
}[color] || 'bg-slate-50 border-slate-400');

// Re-audit UX #11 · modal Vue en vez de window.confirm nativo.
const segmentando = ref(false);
const modalSegmentar = ref(false);
useEscClose(modalSegmentar);
const solicitarSegmentar = () => { modalSegmentar.value = true; };
const confirmarSegmentar = () => {
    if (segmentando.value) return;
    segmentando.value = true;
    router.post('/app/crm/segmentar', {}, {
        preserveScroll: true,
        onSuccess: () => { modalSegmentar.value = false; },
        onFinish: () => { segmentando.value = false; },
    });
};

// Formulario rápido interacción
const modalNueva = ref(false);
useEscClose(modalNueva);
const form = ref({ contacto_id: '', tipo: 'llamada', asunto: '', detalle: '', resultado: '', proxima_accion_at: '', proxima_accion_nota: '' });
const contactosBusqueda = ref([]);
const buscarContacto = ref('');
const buscando = ref(false);
let busquedaTimer = null;

const doBuscarContacto = () => {
    if (busquedaTimer) clearTimeout(busquedaTimer);
    busquedaTimer = setTimeout(async () => {
        if (buscarContacto.value.length < 2) { contactosBusqueda.value = []; return; }
        buscando.value = true;
        try {
            const res = await fetch('/app/api/contactos/buscar?q=' + encodeURIComponent(buscarContacto.value), {
                headers: { 'Accept': 'application/json' },
            });
            contactosBusqueda.value = res.ok ? await res.json() : [];
        } catch (e) { contactosBusqueda.value = []; }
        buscando.value = false;
    }, 300);
};

const seleccionarContacto = (c) => {
    form.value.contacto_id = c.id;
    buscarContacto.value = c.nombre;
    contactosBusqueda.value = [];
};

const procesandoCrm = ref(false);
const guardarInteraccion = () => {
    if (procesandoCrm.value) return;  // QA-D Bloque2: doble-click guard
    procesandoCrm.value = true;
    router.post('/app/crm/interaccion', form.value, {
        preserveScroll: true,
        onSuccess: () => {
            modalNueva.value = false;
            form.value = { contacto_id: '', tipo: 'llamada', asunto: '', detalle: '', resultado: '', proxima_accion_at: '', proxima_accion_nota: '' };
            buscarContacto.value = '';
        },
        onError: (e) => {
            // Muestra el primer error de validación (asunto o contacto_id son los más comunes).
            const msg = Object.values(e).flat().find(v => typeof v === 'string') || 'Error al guardar interacción.';
            if (typeof window !== 'undefined') {
                window.dispatchEvent(new CustomEvent('gb:error', { detail: { mensaje: msg } }));
            }
        },
        onFinish: () => { procesandoCrm.value = false; },
    });
};
</script>

<template>
    <Head title="CRM"/>
    <AppLayout>
        <div class="space-y-4 max-w-6xl">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Zap class="h-6 w-6 text-brand-600"/>
                        CRM
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">Segmentación de clientes, bitácora de interacciones y comisiones.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button v-if="tab === 'segmentacion'" @click="solicitarSegmentar" :disabled="segmentando"
                            class="btn-secondary text-sm">
                        <RefreshCw :class="['h-4 w-4', segmentando ? 'animate-spin' : '']"/>
                        Re-segmentar
                    </button>
                    <button v-if="tab === 'interacciones'" @click="modalNueva = true" class="btn-primary text-sm">
                        <MessageSquare class="h-4 w-4"/> Nueva interacción
                    </button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex items-center gap-2 border-b border-surface-200 dark:border-surface-800">
                <button @click="tab = 'segmentacion'" :class="['px-4 py-2 text-sm font-semibold border-b-2', tab === 'segmentacion' ? 'border-brand-500 text-brand-600' : 'border-transparent text-surface-500']">
                    🎯 Segmentación
                </button>
                <button @click="tab = 'fuentes'" :class="['px-4 py-2 text-sm font-semibold border-b-2', tab === 'fuentes' ? 'border-brand-500 text-brand-600' : 'border-transparent text-surface-500']">
                    🧭 Fuentes
                </button>
                <button @click="tab = 'interacciones'" :class="['px-4 py-2 text-sm font-semibold border-b-2', tab === 'interacciones' ? 'border-brand-500 text-brand-600' : 'border-transparent text-surface-500']">
                    📞 Interacciones
                </button>
                <button @click="tab = 'comisiones'" :class="['px-4 py-2 text-sm font-semibold border-b-2', tab === 'comisiones' ? 'border-brand-500 text-brand-600' : 'border-transparent text-surface-500']">
                    💰 Comisiones
                </button>
            </div>

            <!-- Flash -->
            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 dark:text-emerald-300 text-sm">
                {{ $page.props.flash.success }}
            </div>

            <!-- Tab: Segmentación -->
            <div v-if="tab === 'segmentacion'">
                <div v-if="! segmentos.length" class="card p-12 text-center text-surface-500">
                    <Users class="h-10 w-10 mx-auto opacity-40"/>
                    <div class="text-sm mt-2">Todavía no se ha ejecutado la segmentación.</div>
                    <button @click="solicitarSegmentar" class="btn-primary mt-4 mx-auto">Ejecutar ahora</button>
                </div>
                <div v-else class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                    <div v-for="s in segmentos" :key="s.segmento"
                         :class="['card p-4 border-l-4', segColor(s.color)]">
                        <div class="text-3xl">{{ s.emoji }}</div>
                        <div class="text-xs uppercase tracking-wider font-bold mt-2">{{ s.label }}</div>
                        <div class="text-3xl font-black tabular-nums mt-1">{{ s.total }}</div>
                        <div class="text-xs opacity-70 mt-1">YTD: {{ fmtCOP(s.compra_ytd) }}</div>
                    </div>
                </div>
                <div class="text-xs text-surface-500 text-center py-4">
                    Reglas de segmentación configurables en <Link href="/app/reglas" class="text-brand-600 hover:underline">Reglas</Link> (grupo CRM).
                </div>
            </div>

            <!-- Tab: Fuentes (MKT-6) -->
            <div v-if="tab === 'fuentes'" class="card p-4">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">
                    🧭 ¿Por dónde llegan los clientes?
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase text-surface-500 border-b border-surface-200 dark:border-surface-800">
                                <th class="py-2 pr-3">Fuente</th>
                                <th class="py-2 px-3 text-right">Clientes</th>
                                <th class="py-2 px-3 text-right">Con pedido</th>
                                <th class="py-2 px-3 text-right">Conversión</th>
                                <th class="py-2 px-3 text-right">Pedidos</th>
                                <th class="py-2 pl-3 text-right">Ventas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="f in fuentes" :key="f.fuente ?? 'sin'" class="border-b border-surface-100 dark:border-surface-800/60"
                                :class="f.fuente === null ? 'text-surface-500' : ''">
                                <td class="py-2 pr-3 font-medium">{{ f.label }}</td>
                                <td class="py-2 px-3 text-right tabular-nums">{{ f.contactos }}</td>
                                <td class="py-2 px-3 text-right tabular-nums">{{ f.clientes_con_pedido }}</td>
                                <td class="py-2 px-3 text-right tabular-nums">{{ f.conversion }}%</td>
                                <td class="py-2 px-3 text-right tabular-nums">{{ f.pedidos }}</td>
                                <td class="py-2 pl-3 text-right tabular-nums">{{ fmtCOP(f.ventas) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="text-xs text-surface-500 mt-3">
                    La fuente se elige en la ficha del contacto («¿Cómo llegó?») y cada pedido la copia al crearse.
                    Ventas = pedidos facturados o despachados.
                </div>
            </div>

            <!-- Tab: Interacciones -->
            <div v-if="tab === 'interacciones'" class="card p-4">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">
                    📋 Últimas {{ interaccionesRecientes.length }} interacciones
                </div>
                <div v-if="! interaccionesRecientes.length" class="text-center py-6 text-surface-500 text-sm">
                    Sin interacciones registradas. Registrá la primera para arrancar la bitácora.
                </div>
                <div v-else class="space-y-2">
                    <div v-for="i in interaccionesRecientes" :key="i.id"
                         class="p-3 rounded-lg border border-surface-200 dark:border-surface-800 hover:border-brand-500/40 transition">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="uppercase font-bold text-brand-600">{{ i.tipo }}</span>
                                    <span class="text-surface-500">·</span>
                                    <Link :href="'/app/contactos/' + i.contacto_id" class="hover:underline font-medium text-surface-700 dark:text-surface-200">
                                        <User class="h-3 w-3 inline"/> {{ i.contacto }}
                                    </Link>
                                </div>
                                <div class="font-semibold mt-1">{{ i.asunto }}</div>
                                <div v-if="i.detalle" class="text-sm text-surface-600 dark:text-surface-400 mt-1">{{ i.detalle }}</div>
                                <div v-if="i.proxima_accion_at" class="text-xs mt-2 text-amber-600 dark:text-amber-400">
                                    <Clock class="h-3 w-3 inline"/> Próximo: {{ i.proxima_accion_at }} — {{ i.proxima_accion_nota }}
                                </div>
                            </div>
                            <div class="text-xs text-surface-500 flex-shrink-0 text-right">
                                <div>{{ i.usuario }}</div>
                                <div>{{ i.ocurrida_hace }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Comisiones -->
            <div v-if="tab === 'comisiones'">
                <div v-if="! comisionesUltimoMes.periodo" class="card p-12 text-center text-surface-500">
                    <TrendingUp class="h-10 w-10 mx-auto opacity-40"/>
                    <div class="text-sm mt-2">Sin comisiones calculadas.</div>
                </div>
                <div v-else class="card p-4">
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-xs uppercase tracking-widest font-bold text-emerald-600">
                            💰 Período {{ comisionesUltimoMes.periodo }} · {{ comisionesUltimoMes.items.length }} vendedores
                        </div>
                        <div class="text-lg font-bold text-emerald-600">Total a pagar: {{ fmtCOP(comisionesUltimoMes.total_a_pagar) }}</div>
                    </div>
                    <div class="overflow-x-auto">
                        <table v-tabla-movil class="w-full min-w-[700px] text-sm">
                            <thead>
                                <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                    <th class="text-left py-2">Vendedor</th>
                                    <th class="text-right">Facturado</th>
                                    <th class="text-right">Cobrado</th>
                                    <th class="text-right">Base</th>
                                    <th class="text-right">%</th>
                                    <th class="text-right">Comisión</th>
                                    <th class="text-right">Bono</th>
                                    <th class="text-right">A pagar</th>
                                    <th class="text-center">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in comisionesUltimoMes.items" :key="c.id" class="border-b border-surface-100 dark:border-surface-900">
                                    <td class="py-2 font-medium">{{ c.vendedor }}</td>
                                    <td class="text-right font-mono">{{ fmtCOP(c.total_facturado) }}</td>
                                    <td class="text-right font-mono">{{ fmtCOP(c.total_cobrado) }}</td>
                                    <td class="text-right font-mono">{{ fmtCOP(c.base) }}</td>
                                    <td class="text-right">{{ c.porcentaje }}%</td>
                                    <td class="text-right font-mono">{{ fmtCOP(c.comision) }}</td>
                                    <td class="text-right font-mono text-emerald-600">{{ c.bono > 0 ? fmtCOP(c.bono) : '—' }}</td>
                                    <td class="text-right font-bold font-mono text-emerald-600">{{ fmtCOP(c.total_a_pagar) }}</td>
                                    <td class="text-center">
                                        <span class="text-xs uppercase font-bold" :class="c.estado === 'pagada' ? 'text-emerald-600' : c.estado === 'aprobada' ? 'text-blue-600' : 'text-amber-600'">{{ c.estado }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal nueva interacción -->
        <div v-if="modalNueva" role="dialog" aria-modal="true"
             class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4"
             @click.self="modalNueva = false">
            <div class="card p-6 max-w-xl w-full max-h-[90vh] overflow-y-auto">
                <div class="text-lg font-bold mb-4">Nueva interacción</div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold uppercase text-surface-500 mb-1">Contacto</label>
                        <div class="relative">
                            <input v-model="buscarContacto" @input="doBuscarContacto" type="text"
                                   placeholder="Buscar por nombre…" class="input w-full"/>
                            <div v-if="contactosBusqueda.length" class="absolute z-10 mt-1 bg-white dark:bg-surface-900 border border-surface-200 dark:border-surface-800 rounded shadow-lg w-full max-h-40 overflow-y-auto">
                                <button v-for="c in contactosBusqueda" :key="c.id" @click="seleccionarContacto(c)"
                                        class="block w-full text-left px-3 py-1.5 text-sm hover:bg-surface-100 dark:hover:bg-surface-800">
                                    {{ c.nombre }} <span class="text-xs text-surface-500">{{ c.documento }}</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold uppercase text-surface-500 mb-1">Tipo</label>
                            <select v-model="form.tipo" class="input w-full">
                                <option v-for="t in tiposInteraccion" :key="t" :value="t">{{ t }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase text-surface-500 mb-1">Próxima acción</label>
                            <input v-model="form.proxima_accion_at" type="date" class="input w-full"/>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase text-surface-500 mb-1">Asunto</label>
                        <input v-model="form.asunto" type="text" class="input w-full" placeholder="Ej: Recordatorio de pago"/>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase text-surface-500 mb-1">Detalle</label>
                        <textarea v-model="form.detalle" rows="3" class="input w-full"/>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase text-surface-500 mb-1">Resultado</label>
                        <input v-model="form.resultado" type="text" class="input w-full" placeholder="Ej: pagó, no contestó, promete pagar el viernes"/>
                    </div>
                    <div v-if="form.proxima_accion_at">
                        <label class="block text-xs font-semibold uppercase text-surface-500 mb-1">Nota próxima acción</label>
                        <input v-model="form.proxima_accion_nota" type="text" class="input w-full"/>
                    </div>
                </div>

                <div class="flex gap-2 mt-5">
                    <button @click="modalNueva = false" class="btn-secondary flex-1">Cancelar</button>
                    <button @click="guardarInteraccion" :disabled="! form.contacto_id || ! form.asunto || procesandoCrm"
                            class="btn-primary flex-[2] disabled:opacity-40">
                        {{ procesandoCrm ? 'Guardando…' : 'Registrar' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Re-audit UX #11 · Modal re-segmentar (reemplaza window.confirm nativo). -->
        <div v-if="modalSegmentar" @click.self="modalSegmentar = false"
             class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full border-l-4 border-amber-500">
                <div class="flex items-start gap-3 mb-3">
                    <AlertTriangle class="h-6 w-6 text-amber-500 flex-shrink-0"/>
                    <div>
                        <h3 class="text-lg font-bold">Re-segmentar clientes</h3>
                        <p class="text-sm text-surface-500 dark:text-surface-400 mt-1">
                            Recalcula los segmentos VIP/Riesgo/Nuevo/Dormido de TODOS los clientes.
                            Puede tardar unos segundos. Segmentos previos se sobrescribirán.
                        </p>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 mt-4">
                    <button @click="modalSegmentar = false" class="btn-ghost">Cancelar</button>
                    <button @click="confirmarSegmentar" :disabled="segmentando" class="btn-primary bg-amber-600 hover:bg-amber-700 disabled:opacity-50">
                        {{ segmentando ? 'Segmentando…' : 'Sí, re-segmentar' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
