<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { BarChart3, Package, DollarSign, AlertCircle, Cloud, CloudOff, TrendingUp, TrendingDown, Calendar, Filter } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import ReporteContenedores from './ReporteContenedores.vue';

/*
 * COMP-B9 · Reporte de compras con filtros fecha + comparativa mes anterior
 * y cruce de CxP contra `/v1/accounts-payable` SIIGO por NIT de proveedor.
 */
const props = defineProps({
    periodo: { type: Object, required: true },
    kpis: { type: Object, required: true },
    topProveedores: { type: Array, required: true },
    contenedores: { type: Object, required: true },
    siigo: { type: Object, required: true },
});

const { money } = useMoney();

// Filtros fecha editables; submit vía GET al mismo endpoint.
const desde = ref(props.periodo.inicio);
const hasta = ref(props.periodo.fin);
const aplicar = () => router.get('/app/compras/reporte', { desde: desde.value, hasta: hasta.value }, { preserveScroll: true });

// Comparativa % con el periodo anterior.
const deltaCls = (pct) => {
    if (pct === null || pct === undefined) return 'text-surface-500';
    return pct >= 0 ? 'text-emerald-600' : 'text-red-600';
};
const deltaIcon = (pct) => (pct ?? 0) >= 0 ? TrendingUp : TrendingDown;

// Saldo SIIGO por NIT (índice rápido).
const saldoSiigo = (nit) => props.siigo?.por_nit?.[nit];
const totalSaldoSiigo = computed(() =>
    Object.values(props.siigo?.por_nit ?? {}).reduce((s, x) => s + (x.saldo || 0), 0)
);
const totalCompras = computed(() => props.topProveedores.reduce((s, p) => s + p.total, 0));
</script>

<template>
    <Head title="Reporte compras"/>
    <AppLayout>
        <div class="max-w-7xl mx-auto space-y-4">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <BarChart3 class="h-6 w-6 text-brand-600"/>
                    Reporte de compras
                </h1>
                <!-- Filtros fecha -->
                <form @submit.prevent="aplicar" class="card p-3 flex items-end gap-2 flex-wrap">
                    <div>
                        <label class="text-[10px] uppercase text-surface-500 flex items-center gap-1"><Calendar class="h-3 w-3"/> Desde</label>
                        <input v-model="desde" type="date" class="input text-sm"/>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-surface-500 flex items-center gap-1"><Calendar class="h-3 w-3"/> Hasta</label>
                        <input v-model="hasta" type="date" class="input text-sm"/>
                    </div>
                    <button type="submit" class="btn-primary text-sm"><Filter class="h-3 w-3"/> Aplicar</button>
                </form>
            </div>
            <p class="text-xs text-surface-500">
                Periodo: <b>{{ periodo.inicio }}</b> → <b>{{ periodo.fin }}</b>
                · comparando contra {{ periodo.inicio_prev }} → {{ periodo.fin_prev }}
            </p>

            <!-- KPIs con delta vs mes anterior -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="card p-4" :class="kpis.oc_pendientes > 0 ? 'ring-2 ring-blue-500' : ''">
                    <div class="text-xs uppercase text-surface-500 flex items-center gap-2"><Package class="h-3 w-3"/> OCs pendientes</div>
                    <div class="text-3xl font-bold mt-1">{{ kpis.oc_pendientes }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 flex items-center gap-2"><DollarSign class="h-3 w-3"/> Compras periodo</div>
                    <div class="text-2xl font-bold mt-1 text-brand-600">{{ money(kpis.oc_periodo) }}</div>
                    <div v-if="kpis.oc_delta_pct !== null" :class="['text-[11px] mt-1 flex items-center gap-1', deltaCls(kpis.oc_delta_pct)]">
                        <component :is="deltaIcon(kpis.oc_delta_pct)" class="h-3 w-3"/>
                        {{ kpis.oc_delta_pct > 0 ? '+' : '' }}{{ kpis.oc_delta_pct }}% vs anterior
                    </div>
                    <div v-else class="text-[11px] text-surface-500 mt-1">sin histórico</div>
                </div>
                <div class="card p-4" :class="kpis.contenedores_por_liquidar > 0 ? 'ring-2 ring-amber-500' : ''">
                    <div class="text-xs uppercase text-surface-500 flex items-center gap-2"><AlertCircle class="h-3 w-3"/> Por liquidar</div>
                    <div class="text-3xl font-bold mt-1 text-amber-600">{{ kpis.contenedores_por_liquidar }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500">FOB periodo</div>
                    <div class="text-2xl font-bold mt-1">{{ money(kpis.total_fob_periodo) }}</div>
                </div>
            </div>

            <!-- Top proveedores con columna comparativa SIIGO -->
            <div class="card p-4">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs uppercase font-bold text-brand-600">Top 10 proveedores</div>
                    <div v-if="siigo.disponible" class="text-[11px] text-surface-500 flex items-center gap-1">
                        <Cloud class="h-3 w-3 text-emerald-500"/>
                        Cruce con SIIGO disponible · saldo total CxP: <b>{{ money(totalSaldoSiigo) }}</b>
                    </div>
                    <div v-else class="text-[11px] text-amber-600 flex items-center gap-1">
                        <CloudOff class="h-3 w-3"/>
                        SIIGO no respondió · {{ siigo.mensaje ? '('+siigo.mensaje+')' : 'reintentar más tarde' }}
                    </div>
                </div>

                <div v-if="!topProveedores.length" class="text-center py-6 text-surface-500 text-sm">
                    Sin compras en el periodo seleccionado.
                </div>
                <table v-tabla-movil v-else class="w-full text-sm">
                    <thead class="text-[10px] text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-2">Proveedor</th>
                            <th class="text-left p-2">NIT</th>
                            <th class="text-right p-2">OCs ERP</th>
                            <th class="text-right p-2">Compras ERP</th>
                            <th class="text-right p-2" v-if="siigo.disponible">Facturas SIIGO</th>
                            <th class="text-right p-2" v-if="siigo.disponible">Saldo CxP SIIGO</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="p in topProveedores" :key="p.proveedor_id" class="hover:bg-surface-50 dark:hover:bg-surface-900/40">
                            <td class="p-2 font-medium">{{ p.nombre }}</td>
                            <td class="p-2 font-mono text-[11px] text-surface-500">{{ p.nit || '—' }}</td>
                            <td class="p-2 text-right">{{ p.ocs }}</td>
                            <td class="p-2 text-right font-bold text-brand-600">{{ money(p.total) }}</td>
                            <template v-if="siigo.disponible">
                                <template v-if="saldoSiigo(p.nit)">
                                    <td class="p-2 text-right">{{ saldoSiigo(p.nit).facturas }}</td>
                                    <td class="p-2 text-right" :class="saldoSiigo(p.nit).saldo > 0 ? 'text-red-600 font-semibold' : 'text-emerald-600'">
                                        {{ money(saldoSiigo(p.nit).saldo) }}
                                    </td>
                                </template>
                                <template v-else>
                                    <td class="p-2 text-right text-surface-400 italic">—</td>
                                    <td class="p-2 text-right text-surface-400 italic">sin datos</td>
                                </template>
                            </template>
                        </tr>
                        <tr v-if="topProveedores.length > 1" class="border-t-2 font-bold">
                            <td class="p-2" colspan="3">Total</td>
                            <td class="p-2 text-right text-brand-600">{{ money(totalCompras) }}</td>
                            <template v-if="siigo.disponible">
                                <td></td>
                                <td class="p-2 text-right" :class="totalSaldoSiigo > 0 ? 'text-red-600' : 'text-emerald-600'">{{ money(totalSaldoSiigo) }}</td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ReporteContenedores :reporte="contenedores"/>
        </div>
    </AppLayout>
</template>
