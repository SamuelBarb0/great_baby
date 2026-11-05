<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { Calculator, TrendingUp, TrendingDown, CheckCircle, AlertCircle, EyeOff, Eye } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasPanel } from '@/composables/usePestanasContables';
import KpiCard from '@/Components/KpiCard.vue';
import { useMoney } from '@/composables/useMoney';
import { pucLabel } from '@/composables/pucLabels';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { fechaCorta } from '@/composables/useFecha';

const props = defineProps({
    filtros: { type: Object, required: true },
    kpis: { type: Object, required: true },
    porCuenta: { type: Array, required: true },
    movimientosRecientes: { type: Array, required: true },
});

// Re-audit M5 UX-A1 · usar composable useMoney (antes fmtCOP inline).
const { money } = useMoney();

const desde = ref(props.filtros.desde);
const hasta = ref(props.filtros.hasta);
const incluirAnulados = ref(!!props.filtros.incluir_anulados);
// Re-audit M5 R4 UX-M1 · loading state (paridad con Panel y ReporteDetalle).
const cargando = ref(false);

const filtrar = () => {
    router.get('/app/contabilidad', {
        desde: desde.value, hasta: hasta.value,
        incluir_anulados: incluirAnulados.value ? 1 : null,
    }, {
        preserveScroll: true, preserveState: true, replace: true,
        onStart: () => { cargando.value = true; },
        onFinish: () => { cargando.value = false; },
    });
};
watch([desde, hasta, incluirAnulados], filtrar);

// Re-audit M5 R2 PATRÓN γ · usar useBogotaDate compartido. Antes toISOString()
// era UTC → 19:00-24:00 Bogotá el chip "Hoy" saltaba al día siguiente y la
// tabla salía vacía. Ahora todos los atajos calculan en Colombia.
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

// Re-audit M5 FUNC-A5 · consistencia con backend (0.01, no 1).
const balanceado = computed(() => !props.kpis.unbalanced);

// Totales del balance de comprobación (tfoot — Re-audit FUNC-M2).
const totBalance = computed(() => props.porCuenta.reduce((acc, c) => {
    acc.debe += c.debe; acc.haber += c.haber; acc.saldo += c.saldo; return acc;
}, { debe: 0, haber: 0, saldo: 0 }));

const linkTodosMovs = computed(() =>
    `/app/cartera/movimientos?desde=${encodeURIComponent(desde.value)}&hasta=${encodeURIComponent(hasta.value)}`
);
</script>

<template>
    <Head title="Contabilidad"/>
    <AppLayout>
        <div class="space-y-4">
            <PestanasSeccion :pestanas="pestanasPanel"/>

            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Calculator class="h-6 w-6 text-brand-600"/>
                    Contabilidad
                </h1>
                <p class="text-sm text-surface-500 mt-1">Balance de comprobación, movimientos y validación de partida doble.</p>
            </div>

            <!-- Filtros: chips + fechas + toggle anulados -->
            <div class="card p-4 space-y-3">
                <div class="flex items-center gap-2 flex-wrap">
                    <button v-for="[key, label] in [['hoy','Hoy'],['mes','Mes actual'],['mes_ant','Mes anterior'],['trimestre','Trimestre'],['ytd','Año actual']]"
                            :key="key" @click="rangoRelativo(key)"
                            class="px-3 py-1 text-xs font-semibold rounded-full border border-surface-200 dark:border-surface-700 hover:bg-brand-50 dark:hover:bg-brand-900/30 hover:text-brand-700 dark:hover:text-brand-300 transition">
                        {{ label }}
                    </button>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <label class="text-sm text-surface-500">Período:</label>
                    <input v-model="desde" type="date" class="input"/>
                    <span class="text-surface-500">→</span>
                    <input v-model="hasta" type="date" class="input"/>
                    <!-- Re-audit R2 UX-M1 · label neutro (sin "DIAN" que asusta a Aracely). -->
                    <label class="ml-auto flex items-center gap-2 text-xs font-semibold cursor-pointer select-none"
                           title="La normativa fiscal exige conservar el histórico 5 años. Actívalo si necesitas ver asientos revertidos.">
                        <input type="checkbox" v-model="incluirAnulados" class="rounded border-surface-300"/>
                        <EyeOff v-if="!incluirAnulados" class="h-3 w-3 text-surface-500"/>
                        <Eye v-else class="h-3 w-3 text-amber-600"/>
                        Incluir asientos anulados
                    </label>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <KpiCard label="Total débitos" :value="kpis.total_debe" color="blue" format="money" :icon="TrendingUp"/>
                <KpiCard label="Total créditos" :value="kpis.total_haber" color="purple" format="money" :icon="TrendingDown"/>
                <div :class="['card p-5 border-l-4', balanceado ? 'border-l-emerald-500' : 'border-l-red-500']">
                    <div class="text-xs uppercase tracking-wider text-surface-500 font-medium">Balance</div>
                    <div :class="['text-3xl font-black mt-1 tabular-nums', balanceado ? 'text-emerald-600' : 'text-red-600']">
                        {{ money(Math.abs(kpis.balance)) }}
                    </div>
                    <div class="text-xs mt-1 flex items-center gap-1" :class="balanceado ? 'text-emerald-600' : 'text-red-600'">
                        <CheckCircle v-if="balanceado" class="h-3 w-3"/>
                        <AlertCircle v-else class="h-3 w-3"/>
                        {{ balanceado ? 'Partida doble cuadrada' : 'DESCUADRE en el período' }}
                    </div>
                </div>
                <KpiCard label="Cantidad de asientos" :value="kpis.movimientos" color="amber"/>
            </div>

            <!-- Balance de comprobación -->
            <div class="card p-4" :class="cargando ? 'opacity-60 pointer-events-none transition-opacity' : ''">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">📊 Balance de comprobación por cuenta</div>
                <div v-if="!porCuenta.length" class="text-center py-6 text-surface-500 text-sm">Sin movimientos en el período.</div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[600px] text-sm">
                        <thead>
                            <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                <th class="text-left py-2">Cuenta PUC</th>
                                <th class="text-right">Débito</th>
                                <th class="text-right">Crédito</th>
                                <th class="text-right">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in porCuenta" :key="c.cuenta_puc" class="border-b border-surface-100 dark:border-surface-900 hover:bg-surface-50 dark:hover:bg-surface-900/30">
                                <!-- Re-audit UX-A4 · label del PUC (tooltip + segunda línea). -->
                                <td class="py-2">
                                    <div class="font-mono font-semibold text-brand-600" :title="c.cuenta_nombre || pucLabel(c.cuenta_puc)">{{ c.cuenta_puc }}</div>
                                    <div class="text-[10px] text-surface-500">{{ c.cuenta_nombre || pucLabel(c.cuenta_puc) }}</div>
                                </td>
                                <td class="text-right font-mono">{{ money(c.debe) }}</td>
                                <td class="text-right font-mono">{{ money(c.haber) }}</td>
                                <td class="text-right font-mono font-bold" :class="c.saldo >= 0 ? 'text-blue-600' : 'text-purple-600'">
                                    {{ money(Math.abs(c.saldo)) }} {{ c.saldo >= 0 ? 'D' : 'C' }}
                                </td>
                            </tr>
                        </tbody>
                        <!-- Re-audit FUNC-M2 · totalizador con flag descuadre. -->
                        <tfoot class="border-t-2 border-surface-300 dark:border-surface-700 font-bold">
                            <tr>
                                <td class="py-2 uppercase text-xs">Totales</td>
                                <td class="text-right font-mono">{{ money(totBalance.debe) }}</td>
                                <td class="text-right font-mono">{{ money(totBalance.haber) }}</td>
                                <td class="text-right font-mono" :class="Math.abs(totBalance.saldo) < 0.01 ? 'text-emerald-600' : 'text-red-600'">
                                    {{ money(Math.abs(totBalance.saldo)) }}
                                    <span v-if="Math.abs(totBalance.saldo) >= 0.01" class="text-[10px] uppercase">descuadre</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Movimientos recientes -->
            <div class="card p-4">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600">
                        📝 Últimos movimientos <span class="text-surface-500 normal-case">(mostrando {{ movimientosRecientes.length }} de {{ (kpis.movimientos ?? 0).toLocaleString('es-CO') }})</span>
                    </div>
                    <!-- Re-audit UX-A9 · CTA al libro completo con el rango. -->
                    <Link :href="linkTodosMovs" class="text-xs font-semibold text-brand-600 hover:underline">Ver todos →</Link>
                </div>
                <div v-if="!movimientosRecientes.length" class="text-center py-6 text-surface-500 text-sm">Sin movimientos en el período.</div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[800px] text-sm">
                        <thead>
                            <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                <th class="text-left py-2">Fecha</th>
                                <th class="text-left">Cuenta</th>
                                <th class="text-left">Descripción</th>
                                <th class="text-left">Origen</th>
                                <th class="text-right">Debe</th>
                                <th class="text-right">Haber</th>
                                <th class="text-left">Por</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in movimientosRecientes" :key="m.id"
                                :class="['border-b border-surface-100 dark:border-surface-900', m.anulado ? 'bg-red-50/50 dark:bg-red-950/20' : '']">
                                <!-- Re-audit R4 UX-B2 · opacidad SOLO en celdas de contenido;
                                     el badge "Anulado" queda a opacity 100 para no perder contraste. -->
                                <td class="py-2 text-xs text-surface-500">
                                    <span :class="m.anulado ? 'opacity-70' : ''">{{ fechaCorta(m.fecha) }}</span>
                                    <span v-if="m.anulado" class="ml-1 text-[10px] font-bold text-red-600 uppercase">Anulado</span>
                                </td>
                                <td class="font-mono text-brand-600" :class="m.anulado ? 'opacity-70' : ''" :title="m.cuenta_nombre || pucLabel(m.cuenta_puc)">{{ m.cuenta_puc }}</td>
                                <td class="text-xs" :class="m.anulado ? 'opacity-70' : ''" :title="m.descripcion">{{ m.descripcion }}</td>
                                <td class="text-xs text-surface-500" :class="m.anulado ? 'opacity-70' : ''">{{ m.origen }}</td>
                                <td class="text-right font-mono" :class="[m.debe > 0 ? 'text-blue-600 font-bold' : 'text-surface-400', m.anulado ? 'opacity-70' : '']">{{ m.debe > 0 ? money(m.debe) : '—' }}</td>
                                <td class="text-right font-mono" :class="[m.haber > 0 ? 'text-purple-600 font-bold' : 'text-surface-400', m.anulado ? 'opacity-70' : '']">{{ m.haber > 0 ? money(m.haber) : '—' }}</td>
                                <td class="text-xs" :class="m.anulado ? 'opacity-70' : ''">{{ m.usuario || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
