<script setup>
import { ref, computed } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, router } from '@inertiajs/vue3';
import { BarChart3, TrendingUp, TrendingDown, AlertTriangle, Banknote, Download } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

/*
 * La fecha de HOY en Colombia, no en UTC.
 *
 * `toISOString()` convierte a UTC antes de recortar, asi que entre las
 * 19:00 y la medianoche hora Bogota devuelve el dia siguiente. Las fechas
 * de esta pantalla quedan escritas en registros, no son un filtro: un dia
 * de corrimiento cambia lo que dice el documento.
 */
const { hoy: hoyBogota } = useBogotaDate();

const props = defineProps({
    periodo: { type: Object, required: true },
    kpis: { type: Object, required: true },
    transportadoras: { type: Array, required: true },
    ciudades: { type: Array, required: true },
    vendedores: { type: Array, required: true },
});

const { money } = useMoney();

// U19 · selector de periodo.
const desde = ref(props.periodo.inicio ?? hoyBogota());
const hasta = ref(props.periodo.fin ?? hoyBogota());
const filtrar = () => router.get('/app/dropi/reportes', { desde: desde.value, hasta: hasta.value }, { preserveState: true });

// U19 · export CSV — arma el archivo en el cliente, no requiere endpoint extra.
const exportarCsv = (rows, nombre) => {
    if (!rows.length) return;
    const cols = Object.keys(rows[0]);
    const csv = [
        cols.join(','),
        ...rows.map(r => cols.map(c => `"${String(r[c] ?? '').replace(/"/g, '""')}"`).join(',')),
    ].join('\n');
    const blob = new Blob(["﻿" + csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    // Re-audit UX#9 · timestamp evita colisión al re-exportar el mismo periodo.
    const ts = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
    a.download = `dropi_${nombre}_${desde.value}_${hasta.value}_${ts}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
};
</script>

<template>
    <Head title="Reportes Dropi"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <BarChart3 class="h-6 w-6 text-brand-600"/>
                        Reportes Dropi
                    </h1>
                    <p class="text-sm text-surface-500 dark:text-surface-400">
                        Periodo: <b>{{ periodo.inicio }}</b> → <b>{{ periodo.fin }}</b>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <input type="date" v-model="desde" class="input"/>
                    <input type="date" v-model="hasta" class="input"/>
                    <button @click="filtrar" class="btn-primary">Aplicar</button>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400 flex items-center gap-2"><TrendingUp class="h-3 w-3"/> Ventas mes</div>
                    <div class="text-2xl font-bold mt-1 text-emerald-600">{{ money(kpis.ventas) }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400 flex items-center gap-2"><TrendingDown class="h-3 w-3"/> Devoluciones</div>
                    <div class="text-2xl font-bold mt-1 text-red-600">{{ money(kpis.devoluciones) }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400 flex items-center gap-2"><Banknote class="h-3 w-3"/> Retiros banco</div>
                    <div class="text-2xl font-bold mt-1">{{ money(kpis.retiros) }}</div>
                </div>
                <div class="card p-4" :class="kpis.sanciones > 0 ? 'ring-2 ring-red-500' : ''"
                    title="Suma de diferencias por sanción Dropi (dif. precio, indemnizaciones, pagos sobre devueltos)">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400 flex items-center gap-2"><AlertTriangle class="h-3 w-3"/> Sanciones</div>
                    <div class="text-2xl font-bold mt-1" :class="kpis.sanciones > 0 ? 'text-red-600' : ''">{{ money(kpis.sanciones) }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="card p-4">
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-600">🚚 Por transportadora</div>
                        <button @click="exportarCsv(transportadoras, 'transportadoras')" v-if="transportadoras.length"
                            class="text-[10px] text-brand-600 hover:underline">
                            <Download class="h-3 w-3 inline"/> CSV
                        </button>
                    </div>
                    <div v-if="!transportadoras.length" class="text-center py-4 text-surface-500 text-xs">Sin datos</div>
                    <table v-tabla-movil v-else class="w-full text-sm">
                        <thead class="text-[10px] text-surface-500 uppercase">
                            <tr>
                                <th class="text-left p-1">Nombre</th>
                                <th class="text-right p-1">Ped.</th>
                                <th class="text-right p-1">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="t in transportadoras" :key="t.nombre" class="border-t border-surface-100 dark:border-surface-800">
                                <td class="p-1 text-xs">{{ t.nombre }}</td>
                                <td class="p-1 text-right text-xs">{{ t.pedidos }}</td>
                                <td class="p-1 text-right font-bold text-xs">{{ money(t.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="card p-4">
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-600">🏙 Top ciudades</div>
                        <button @click="exportarCsv(ciudades, 'ciudades')" v-if="ciudades.length"
                            class="text-[10px] text-brand-600 hover:underline">
                            <Download class="h-3 w-3 inline"/> CSV
                        </button>
                    </div>
                    <div v-if="!ciudades.length" class="text-center py-4 text-surface-500 text-xs">Sin datos</div>
                    <table v-tabla-movil v-else class="w-full text-sm">
                        <thead class="text-[10px] text-surface-500 uppercase">
                            <tr>
                                <th class="text-left p-1">Ciudad</th>
                                <th class="text-right p-1">Ped.</th>
                                <th class="text-right p-1">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in ciudades" :key="c.nombre" class="border-t border-surface-100 dark:border-surface-800">
                                <td class="p-1 text-xs">{{ c.nombre }}</td>
                                <td class="p-1 text-right text-xs">{{ c.pedidos }}</td>
                                <td class="p-1 text-right font-bold text-xs">{{ money(c.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="card p-4">
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-600">👤 Top vendedores</div>
                        <button @click="exportarCsv(vendedores, 'vendedores')" v-if="vendedores.length"
                            class="text-[10px] text-brand-600 hover:underline">
                            <Download class="h-3 w-3 inline"/> CSV
                        </button>
                    </div>
                    <div v-if="!vendedores.length" class="text-center py-4 text-surface-500 text-xs">Sin datos</div>
                    <table v-tabla-movil v-else class="w-full text-sm">
                        <thead class="text-[10px] text-surface-500 uppercase">
                            <tr>
                                <th class="text-left p-1">Nombre</th>
                                <th class="text-right p-1">Ped.</th>
                                <th class="text-right p-1">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="v in vendedores" :key="v.nombre" class="border-t border-surface-100 dark:border-surface-800">
                                <td class="p-1 text-xs truncate max-w-[140px]" :title="v.nombre">{{ v.nombre }}</td>
                                <td class="p-1 text-right text-xs">{{ v.pedidos }}</td>
                                <td class="p-1 text-right font-bold text-xs">{{ money(v.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
