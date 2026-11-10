<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Landmark, ArrowLeft, AlertTriangle, CheckCircle2 } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    conciliacion: { type: Object, required: true },
    lineas: { type: Array, default: () => [] },
});

const { money: fmtCOP } = useMoney();

const filtro = ref('solo_banco');

const ETIQUETAS = {
    conciliada: { texto: 'Conciliada', color: 'text-emerald-600', fondo: 'bg-emerald-500/10' },
    solo_banco: { texto: 'Sólo banco', color: 'text-amber-600', fondo: 'bg-amber-500/10' },
    solo_erp: { texto: 'Sólo ERP', color: 'text-red-600', fondo: 'bg-red-500/10' },
};

const visibles = computed(() =>
    filtro.value === 'todas' ? props.lineas : props.lineas.filter((l) => l.estado === filtro.value));

const cuenta = (estado) => props.lineas.filter((l) => l.estado === estado).length;

const editando = ref(null);
const cuentaPuc = ref('');
const nota = ref('');

const abrir = (l) => {
    editando.value = l.id;
    cuentaPuc.value = l.cuenta_puc ?? '';
    nota.value = l.nota ?? '';
};

const guardarLinea = (l) => {
    router.post(`/app/contabilidad/conciliacion-bancaria/${props.conciliacion.id}/lineas/${l.id}`,
        { cuenta_puc: cuentaPuc.value, nota: nota.value },
        { preserveScroll: true, onFinish: () => { editando.value = null; } });
};
</script>

<template>
    <Head :title="`Conciliación ${conciliacion.desde} · Contabilidad`"/>
    <AppLayout>
        <div class="space-y-4">
            <div>
                <Link href="/app/contabilidad/conciliacion-bancaria"
                      class="text-xs text-brand-600 hover:underline flex items-center gap-1 mb-1">
                    <ArrowLeft class="h-3 w-3"/> Volver a conciliaciones
                </Link>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Landmark class="h-6 w-6 text-brand-600"/>
                    {{ conciliacion.banco }}
                    <span class="text-sm font-normal text-surface-500">
                        · {{ conciliacion.desde }} → {{ conciliacion.hasta }}
                        <span v-if="conciliacion.cuenta">· cuenta {{ conciliacion.cuenta }}</span>
                    </span>
                </h1>
            </div>

            <div v-if="$page.props.flash?.success"
                 class="p-3 rounded-lg text-sm bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700">
                {{ $page.props.flash.success }}
            </div>

            <!-- La comprobación del extracto contra sí mismo, archivada con él. -->
            <div class="p-3 rounded-lg text-sm flex gap-2"
                 :class="conciliacion.cuadra
                    ? 'bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-800 dark:text-emerald-200'
                    : 'bg-amber-500/15 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200'">
                <component :is="conciliacion.cuadra ? CheckCircle2 : AlertTriangle" class="h-5 w-5 shrink-0"/>
                <div>
                    {{ fmtCOP(conciliacion.saldo_anterior) }}
                    + {{ fmtCOP(conciliacion.total_abonos) }}
                    − {{ fmtCOP(conciliacion.total_cargos) }}
                    = <strong>{{ fmtCOP(conciliacion.saldo_extracto) }}</strong>
                    <span v-if="conciliacion.cuadra"> · el extracto cuadra consigo mismo.</span>
                    <span v-else> · <strong>no cuadra</strong>.</span>
                </div>
            </div>

            <div v-if="conciliacion.sin_explicar > 0"
                 class="p-3 rounded-lg text-sm bg-amber-500/15 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200">
                Quedan <strong>{{ conciliacion.sin_explicar }}</strong> líneas sin explicar: no casaron
                con un pago y tampoco tienen cuenta asignada. Hasta que las tengan, esta conciliación
                no se puede asentar.
            </div>

            <div class="card overflow-hidden">
                <div class="p-3 border-b border-surface-100 dark:border-surface-800 flex flex-wrap gap-2">
                    <button v-for="e in ['solo_banco', 'solo_erp', 'conciliada', 'todas']" :key="e"
                            @click="filtro = e"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium border"
                            :class="filtro === e
                                ? 'bg-brand-600 text-white border-brand-600'
                                : 'border-surface-200 dark:border-surface-800 text-surface-600'">
                        {{ e === 'todas' ? 'Todas' : ETIQUETAS[e].texto }}
                        <span class="opacity-70">({{ e === 'todas' ? lineas.length : cuenta(e) }})</span>
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                <th class="text-left py-2 px-3">Fecha</th>
                                <th class="text-left">Descripción</th>
                                <th class="text-right">Valor</th>
                                <th class="text-left pl-3">Estado</th>
                                <th class="text-left">Cuenta / nota</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="! visibles.length">
                                <td colspan="5" class="text-center py-8 text-surface-500">
                                    Nada en este montón.
                                </td>
                            </tr>
                            <tr v-for="l in visibles" :key="l.id"
                                class="border-b border-surface-100 dark:border-surface-800/60">
                                <td class="py-1.5 px-3 text-xs tabular-nums whitespace-nowrap">{{ l.fecha }}</td>
                                <td class="text-xs">{{ l.descripcion }}</td>
                                <td class="text-right tabular-nums whitespace-nowrap"
                                    :class="l.valor < 0 ? 'text-red-600' : 'text-emerald-600'">
                                    {{ fmtCOP(l.valor) }}
                                </td>
                                <td class="pl-3">
                                    <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-bold"
                                          :class="[ETIQUETAS[l.estado].color, ETIQUETAS[l.estado].fondo]">
                                        {{ ETIQUETAS[l.estado].texto }}
                                    </span>
                                </td>
                                <td class="text-xs">
                                    <div v-if="editando === l.id" class="flex gap-1 items-center">
                                        <input v-model="cuentaPuc" placeholder="cuenta"
                                               class="w-24 px-2 py-1 rounded border border-surface-200 dark:border-surface-800 bg-transparent text-xs font-mono"/>
                                        <input v-model="nota" placeholder="nota"
                                               class="w-40 px-2 py-1 rounded border border-surface-200 dark:border-surface-800 bg-transparent text-xs"/>
                                        <button @click="guardarLinea(l)" class="text-brand-600 font-medium">Guardar</button>
                                        <button @click="editando = null" class="text-surface-400">✕</button>
                                    </div>
                                    <button v-else @click="abrir(l)" class="text-left hover:text-brand-600">
                                        <span v-if="l.cuenta_puc" class="font-mono text-brand-600">{{ l.cuenta_puc }}</span>
                                        <span v-else-if="l.estado !== 'conciliada'" class="text-amber-600">asignar cuenta…</span>
                                        <span v-else class="text-surface-400">—</span>
                                        <span v-if="l.nota" class="block text-[10px] text-surface-500">{{ l.nota }}</span>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
