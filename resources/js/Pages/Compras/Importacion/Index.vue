<script setup>
import { ref } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { Ship, Plus, Search, Package, DollarSign, Anchor } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    filtros: { type: Object, required: true },
    importaciones: { type: Object, required: true },
    kpis: { type: Object, required: true },
});

const { money } = useMoney();
const q = ref(props.filtros.q);
const estado = ref(props.filtros.estado);

let deb;
const filtrar = () => {
    clearTimeout(deb);
    deb = setTimeout(() => {
        router.get('/app/compras/importacion', {
            q: q.value || null, estado: estado.value || null,
        }, { preserveScroll: true, preserveState: true, replace: true });
    }, 300);
};

const badgeEstado = (e) => ({
    en_transito: 'bg-blue-500/20 text-blue-700',
    en_puerto: 'bg-amber-500/20 text-amber-700',
    nacionalizada: 'bg-indigo-500/20 text-indigo-700',
    liquidada: 'bg-emerald-500/20 text-emerald-700',
    cerrada: 'bg-surface-500/20 text-surface-700',
}[e] || 'bg-surface-500/20');
</script>

<template>
    <Head title="Importaciones · Compras"/>
    <AppLayout>
        <div class="space-y-4 max-w-7xl">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Ship class="h-6 w-6 text-brand-600"/>
                        Importaciones · contenedores
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Contenedores en tránsito, gastos de nacionalización, liquidación de costos por prorrateo.
                    </p>
                </div>
                <Link href="/app/compras/importacion/nueva" class="btn-primary text-sm inline-flex items-center gap-1">
                    <Plus class="h-4 w-4"/> Nuevo contenedor
                </Link>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500">Total contenedores</div>
                    <div class="text-2xl font-bold mt-1 flex items-center gap-1"><Package class="h-5 w-5 text-brand-600"/>{{ kpis.total }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500">En tránsito<span v-if="kpis.atrasados" class="ml-1 font-semibold normal-case text-red-600">· {{ kpis.atrasados }} atrasado(s)</span></div>
                    <div class="text-2xl font-bold mt-1 flex items-center gap-1 text-blue-600"><Ship class="h-5 w-5"/>{{ kpis.en_transito }}</div>
                </div>
                <div class="card p-4" :class="kpis.por_liquidar > 0 ? 'ring-2 ring-amber-500' : ''">
                    <div class="text-xs uppercase text-surface-500">Por liquidar</div>
                    <div class="text-2xl font-bold mt-1 flex items-center gap-1" :class="kpis.por_liquidar > 0 ? 'text-amber-600' : 'text-surface-400'">
                        <Anchor class="h-5 w-5"/>{{ kpis.por_liquidar }}
                    </div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500">Liquidados mes</div>
                    <div class="text-2xl font-bold mt-1 flex items-center gap-1 text-emerald-600"><DollarSign class="h-5 w-5"/>{{ kpis.liquidados_mes }}</div>
                </div>
            </div>

            <div class="card p-3">
                <div class="flex gap-2 items-center flex-wrap">
                    <div class="relative flex-1 min-w-64">
                        <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-surface-400"/>
                        <input v-model="q" @input="filtrar" placeholder="Buscar por número, contenedor o BL/AWB…" class="input pl-9 w-full text-sm"/>
                    </div>
                    <select v-model="estado" @change="filtrar" class="input text-sm">
                        <option :value="null">Todos los estados</option>
                        <option value="en_transito">En tránsito</option>
                        <option value="en_puerto">En puerto</option>
                        <option value="nacionalizada">Nacionalizada</option>
                        <option value="liquidada">Liquidada</option>
                        <option value="cerrada">Cerrada</option>
                    </select>
                </div>
            </div>

            <div class="card overflow-hidden">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="text-left p-2">Número</th>
                            <th class="text-left p-2">Contenedor</th>
                            <th class="text-left p-2">BL/AWB</th>
                            <th class="text-left p-2">Estado</th>
                            <th class="text-left p-2">Etapa</th>
                            <th class="text-center p-2">Líneas</th>
                            <th class="text-right p-2">FOB</th>
                            <th class="text-right p-2">Gastos</th>
                            <th class="text-left p-2">ETA</th>
                            <th class="text-left p-2">Llegada</th>
                            <th class="text-left p-2">Liquidación</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="i in importaciones.data" :key="i.id" class="hover:bg-surface-50 cursor-pointer"
                            @click="router.visit(`/app/compras/importacion/${i.id}`)">
                            <td class="p-2 font-mono font-bold text-brand-600">{{ i.numero }}</td>
                            <td class="p-2 font-mono text-xs">{{ i.contenedor || '—' }}</td>
                            <td class="p-2 font-mono text-xs">{{ i.bl_awb || '—' }}</td>
                            <td class="p-2"><span class="text-[10px] px-2 py-0.5 rounded uppercase font-semibold" :class="badgeEstado(i.estado)">{{ i.estado }}</span></td>
                            <td class="p-2 text-xs">
                                <span v-if="i.seguimiento?.etapa_label">{{ i.seguimiento.etapa_label }}</span>
                                <span v-else class="text-surface-400">—</span>
                                <span v-if="i.seguimiento?.dias_atraso > 0" class="block font-semibold text-red-600">{{ i.seguimiento.dias_atraso }} día(s) de atraso</span>
                            </td>
                            <td class="p-2 text-center">{{ i.lineas_count }}</td>
                            <td class="p-2 text-right font-mono">{{ money(i.total_fob) }} <span class="text-[10px] text-surface-500">{{ i.moneda_origen }}</span></td>
                            <td class="p-2 text-right font-mono">{{ money(i.total_gastos) }}</td>
                            <td class="p-2 text-xs">{{ i.eta || '—' }}</td>
                            <td class="p-2 text-xs">{{ i.fecha_llegada || '—' }}</td>
                            <td class="p-2 text-xs">
                                <span v-if="i.fecha_liquidacion" class="text-emerald-600 font-semibold">{{ i.fecha_liquidacion }}</span>
                                <span v-else class="text-amber-600">Pendiente</span>
                            </td>
                        </tr>
                        <tr v-if="!importaciones.data.length"><td colspan="11" class="p-6 text-center text-surface-500 text-sm">Sin contenedores.</td></tr>
                    </tbody>
                </table>
            </div>
            <Paginacion :pagina="importaciones" etiqueta="importaciones"/>
        </div>
    </AppLayout>
</template>
