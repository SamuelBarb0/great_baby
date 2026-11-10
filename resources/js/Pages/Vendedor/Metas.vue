<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Target, Save, CopyPlus } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    anio: Number,
    mes: Number,
    vendedores: { type: Array, default: () => [] },
    totales: { type: Object, default: () => ({}) },
    avance_del_mes: { type: Number, default: 0 },
});

const { money: fmtCOP } = useMoney();

const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

const anio = ref(props.anio);
const mes = ref(props.mes);
const guardando = ref(false);

const borrador = reactive({});
const sembrar = () => {
    Object.keys(borrador).forEach((k) => delete borrador[k]);
    props.vendedores.forEach((v) => { borrador[v.id] = v.meta || ''; });
};
sembrar();
watch(() => props.vendedores, sembrar);

const totalBorrador = computed(() =>
    props.vendedores.reduce((s, v) => s + (parseFloat(borrador[v.id]) || 0), 0));

const cambiarMes = () => {
    router.get('/app/vendedor/metas', { anio: anio.value, mes: mes.value }, { preserveState: false });
};

const guardar = () => {
    guardando.value = true;
    router.post('/app/vendedor/metas', {
        anio: anio.value,
        mes: mes.value,
        metas: props.vendedores.map((v) => ({
            user_id: v.id,
            monto: borrador[v.id] === '' ? 0 : borrador[v.id],
        })),
    }, { preserveScroll: true, onFinish: () => { guardando.value = false; } });
};

const copiar = () => {
    router.post('/app/vendedor/metas/copiar', { anio: anio.value, mes: mes.value },
        { preserveScroll: true });
};
</script>

<template>
    <Head title="Metas del equipo comercial"/>
    <AppLayout>
        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Target class="h-6 w-6 text-brand-600"/> Metas del equipo comercial
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Cada vendedor ve su meta en su panel, con cuánto le falta y cuánto tiene que
                    vender por día hábil para llegar. Van <strong>por mes</strong>: diciembre no se
                    parece a febrero, y una meta que se sobreescribe borra la historia del cumplimiento.
                </p>
            </div>

            <div v-if="$page.props.flash?.success"
                 class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash?.warning"
                 class="p-3 rounded-lg bg-amber-500/15 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200 text-sm">
                {{ $page.props.flash.warning }}
            </div>

            <div class="card p-3 flex flex-wrap gap-3 items-end">
                <label class="text-sm">
                    <span class="block text-xs text-surface-500 mb-1">Mes</span>
                    <select v-model.number="mes" @change="cambiarMes"
                            class="px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-sm">
                        <option v-for="(m, i) in MESES" :key="i" :value="i + 1">{{ m }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="block text-xs text-surface-500 mb-1">Año</span>
                    <input v-model.number="anio" @change="cambiarMes" type="number" min="2020" max="2100"
                           class="w-24 px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-sm tabular-nums"/>
                </label>
                <button @click="copiar"
                        class="px-3 py-2 rounded-lg border border-brand-600 text-brand-600 text-sm font-medium flex items-center gap-1">
                    <CopyPlus class="h-4 w-4"/> Copiar las del mes anterior
                </button>
                <div class="flex-1"></div>
                <div class="text-right">
                    <div class="text-[10px] uppercase text-surface-500">Meta del equipo</div>
                    <div class="text-lg font-black tabular-nums">{{ fmtCOP(totalBorrador) }}</div>
                </div>
            </div>

            <div v-if="totales.sin_meta > 0"
                 class="p-3 rounded-lg bg-amber-500/15 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200 text-sm">
                <strong>{{ totales.sin_meta }} vendedor(es) sin meta este mes.</strong>
                En su panel van a ver que no tienen contra qué medirse.
            </div>

            <div class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead>
                            <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                <th class="text-left py-2 px-3">Vendedor</th>
                                <th class="text-right px-3">Meta del mes</th>
                                <th class="text-right">Vendido</th>
                                <th class="text-right">Cumplimiento</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="! vendedores.length">
                                <td colspan="4" class="text-center py-8 text-surface-500">
                                    No hay usuarios con acceso de vendedor.
                                </td>
                            </tr>
                            <tr v-for="v in vendedores" :key="v.id"
                                class="border-b border-surface-100 dark:border-surface-800/60">
                                <td class="py-2 px-3 font-medium">{{ v.nombre }}</td>
                                <td class="px-3">
                                    <div class="flex justify-end">
                                        <input v-model="borrador[v.id]" type="number" min="0" step="100000"
                                               placeholder="sin meta"
                                               class="w-40 px-2 py-1 text-right tabular-nums rounded border bg-transparent text-sm"
                                               :class="(borrador[v.id] === '' || ! borrador[v.id])
                                                    ? 'border-amber-400' : 'border-surface-200 dark:border-surface-800'"/>
                                    </div>
                                </td>
                                <td class="text-right tabular-nums">{{ fmtCOP(v.vendido) }}</td>
                                <td class="text-right">
                                    <span v-if="v.cumplimiento === null" class="text-surface-400 text-xs">—</span>
                                    <span v-else class="font-bold tabular-nums"
                                          :class="v.va_en_ritmo ? 'text-emerald-600' : 'text-amber-600'">
                                        {{ v.cumplimiento }}%
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="p-3 border-t border-surface-100 dark:border-surface-800 flex items-center justify-between gap-3">
                    <div class="text-xs text-surface-500">
                        Van {{ avance_del_mes }}% del mes (en días hábiles). Dejar una meta en blanco
                        es quitarla: una meta de $0 cumpliría siempre al 100%.
                    </div>
                    <button @click="guardar" :disabled="guardando"
                            class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium flex items-center gap-1 disabled:opacity-50">
                        <Save class="h-4 w-4"/> {{ guardando ? 'Guardando…' : 'Guardar metas' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
