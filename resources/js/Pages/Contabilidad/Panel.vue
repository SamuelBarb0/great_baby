<script setup>
import { ref, watch, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { Calculator, TrendingUp, TrendingDown, AlertTriangle, EyeOff, Eye, ArrowLeft } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasPanel } from '@/composables/usePestanasContables';
import { useMoney } from '@/composables/useMoney';
import { pucLabel } from '@/composables/pucLabels';
import { useBogotaDate } from '@/composables/useBogotaDate';

const props = defineProps({ periodo: Object, kpis: Object, topCuentas: Array, porOrigen: Array });
const { money } = useMoney();

const desde = ref(props.periodo.desde);
const hasta = ref(props.periodo.hasta);
const incluirAnulados = ref(!!props.periodo.incluir_anulados);
// Re-audit M5 R4 UX-M1 · loading state (paridad con ReporteDetalle).
const cargando = ref(false);

const filtrar = () => router.get('/app/contabilidad/panel', {
    desde: desde.value, hasta: hasta.value,
    incluir_anulados: incluirAnulados.value ? 1 : null,
}, {
    preserveScroll: true, preserveState: true, replace: true,
    onStart: () => { cargando.value = true; },
    onFinish: () => { cargando.value = false; },
});
watch([desde, hasta, incluirAnulados], filtrar);

// Re-audit M5 R2 PATRÓN γ · useBogotaDate + agrego Trimestre para paridad
// con Index (M5 R2 UX-M1: chips inconsistentes entre pantallas).
const bog = useBogotaDate();
const rangoRelativo = (tipo) => {
    const map = {
        hoy: [bog.hoy(), bog.hoy()],
        mes: [bog.inicioMes(), bog.hoy()],
        mes_ant: [bog.inicioMesAnterior(), bog.finMesAnterior()],
        trimestre: [bog.inicioTrimestre(), bog.hoy()],
        ytd: [bog.inicioYtd(), bog.hoy()],
    };
    if (!map[tipo]) return;
    [desde.value, hasta.value] = map[tipo];
};

// Re-audit UX-M5 · pintar signo del balance con etiqueta clara.
const signoBalance = computed(() => {
    const b = props.kpis.balance;
    if (Math.abs(b) < 0.01) return { txt: 'Cuadrado', cls: 'text-emerald-600' };
    return b > 0
        ? { txt: 'Debe excede', cls: 'text-red-600' }
        : { txt: 'Haber excede', cls: 'text-red-600' };
});
</script>

<template>
    <Head title="Panel contable"/>
    <AppLayout>
        <div class="space-y-4">
            <PestanasSeccion :pestanas="pestanasPanel"/>

            <!-- Re-audit UX-A6 · breadcrumb / volver. -->
            <Link href="/app/contabilidad/reportes" class="text-sm text-brand-600 hover:underline inline-flex items-center gap-1">
                <ArrowLeft class="h-4 w-4"/> Volver a Reportes
            </Link>
            <h1 class="text-2xl font-bold flex items-center gap-2"><Calculator class="h-6 w-6 text-brand-600"/>Panel contable</h1>

            <div class="card p-4 space-y-3">
                <div class="flex items-center gap-2 flex-wrap">
                    <button v-for="[key, label] in [['hoy','Hoy'],['mes','Mes actual'],['mes_ant','Mes anterior'],['trimestre','Trimestre'],['ytd','Año actual']]"
                            :key="key" @click="rangoRelativo(key)"
                            class="px-3 py-1 text-xs font-semibold rounded-full border border-surface-200 dark:border-surface-700 hover:bg-brand-50 dark:hover:bg-brand-900/30 hover:text-brand-700 dark:hover:text-brand-300 transition">
                        {{ label }}
                    </button>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <div><label class="text-xs text-surface-500">Desde</label><input type="date" v-model="desde" class="input"/></div>
                    <div><label class="text-xs text-surface-500">Hasta</label><input type="date" v-model="hasta" class="input"/></div>
                    <label class="ml-auto flex items-center gap-2 text-xs font-semibold cursor-pointer select-none"
                           title="La normativa fiscal exige conservar el histórico 5 años. Actívalo si necesitas ver asientos revertidos.">
                        <input type="checkbox" v-model="incluirAnulados" class="rounded border-surface-300"/>
                        <EyeOff v-if="!incluirAnulados" class="h-3 w-3 text-surface-500"/>
                        <Eye v-else class="h-3 w-3 text-amber-600"/>
                        Incluir asientos anulados
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <!-- Re-audit UX-M2 · colores unificados con Index (débito=azul, crédito=morado). -->
                <div class="card p-4"><div class="text-xs uppercase text-surface-500 flex items-center gap-1"><TrendingUp class="h-3 w-3"/>Débito</div><div class="text-xl font-bold mt-1 text-blue-600">{{ money(kpis.total_debe) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500 flex items-center gap-1"><TrendingDown class="h-3 w-3"/>Crédito</div><div class="text-xl font-bold mt-1 text-purple-600">{{ money(kpis.total_haber) }}</div></div>
                <div class="card p-4" :class="kpis.unbalanced ? 'ring-2 ring-red-500' : ''">
                    <div class="text-xs uppercase text-surface-500 flex items-center gap-1">
                        <AlertTriangle v-if="kpis.unbalanced" class="h-3 w-3 text-red-600"/>Diferencia
                    </div>
                    <div class="text-xl font-bold mt-1" :class="signoBalance.cls">{{ money(Math.abs(kpis.balance)) }}</div>
                    <div class="text-[10px] uppercase mt-0.5" :class="signoBalance.cls">{{ signoBalance.txt }}</div>
                </div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Movimientos</div><div class="text-3xl font-bold mt-1">{{ (kpis.movimientos ?? 0).toLocaleString('es-CO') }}</div></div>
            </div>

            <!-- CONT-C6 · Semáforo SIIGO: estado de sincronización contable. -->
            <Link href="/app/contabilidad/discrepancias-siigo"
                  class="card p-4 flex items-center gap-4 flex-wrap hover:ring-2 hover:ring-brand-400 transition">
                <div class="flex items-center gap-3">
                    <div :class="[
                            'w-5 h-5 rounded-full ring-4 ring-opacity-30 ring-current',
                            { verde: 'bg-emerald-500', amarillo: 'bg-amber-500', rojo: 'bg-red-500' }[kpis.siigo_semaforo] || 'bg-surface-400'
                         ]"></div>
                    <div>
                        <div class="text-xs uppercase font-bold text-brand-600">Semáforo SIIGO</div>
                        <div class="text-sm text-surface-500">{{ kpis.siigo_etiqueta }}</div>
                    </div>
                </div>
                <div class="ml-auto flex items-center gap-4">
                    <div class="text-right">
                        <div class="text-[10px] uppercase text-surface-500">% en SIIGO</div>
                        <div class="text-2xl font-black">{{ kpis.siigo_pct }}%</div>
                    </div>
                    <div class="text-brand-600 text-xs font-semibold">Ver discrepancias →</div>
                </div>
            </Link>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4" :class="cargando ? 'opacity-60 pointer-events-none transition-opacity' : ''">
                <div class="card p-4">
                    <div class="text-xs uppercase font-bold text-brand-600 mb-2">Top 10 cuentas movidas</div>
                    <div v-if="!topCuentas.length" class="text-center py-6 text-surface-500 text-sm">Sin cuentas movidas en el rango.</div>
                    <table v-tabla-movil v-else class="w-full text-sm">
                        <thead class="text-[10px] text-surface-500 uppercase border-b">
                            <tr><th class="text-left p-2">Cuenta</th><th class="text-right p-2">Débito</th><th class="text-right p-2">Crédito</th><th class="text-right p-2">Movs</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="c in topCuentas" :key="c.cuenta">
                                <td class="p-2">
                                    <div class="font-mono">{{ c.cuenta }}</div>
                                    <div class="text-[10px] text-surface-500">{{ c.cuenta_nombre || pucLabel(c.cuenta) }}</div>
                                </td>
                                <td class="p-2 text-right text-blue-600">{{ money(c.debe) }}</td>
                                <td class="p-2 text-right text-purple-600">{{ money(c.haber) }}</td>
                                <td class="p-2 text-right">{{ c.movs }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase font-bold text-brand-600 mb-2">Por origen del asiento</div>
                    <div v-if="!porOrigen.length" class="text-center py-6 text-surface-500 text-sm">Sin asientos con origen en el rango.</div>
                    <table v-tabla-movil v-else class="w-full text-sm">
                        <thead class="text-[10px] text-surface-500 uppercase border-b">
                            <!-- Re-audit M5 R4 FUNC-A1 · label corregido: la métrica es
                                 SUM(debe) por origen = monto documento (partida doble
                                 balanceada Σdebe=Σhaber). "Volumen movido" era engañoso
                                 (implica bruto contable = 2×monto). -->
                            <tr><th class="text-left p-2">Origen</th><th class="text-right p-2">Docs</th><th class="text-right p-2" title="Suma de débitos por origen. En partida doble balanceada equivale al monto del documento.">Facturado</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="o in porOrigen" :key="o.origen">
                                <td class="p-2">{{ o.origen }}</td>
                                <td class="p-2 text-right">{{ o.docs }}</td>
                                <td class="p-2 text-right font-bold">{{ money(o.volumen) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
