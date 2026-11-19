<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { Calculator, TrendingUp, TrendingDown, CheckCircle, AlertCircle, EyeOff, Eye, Search, X, Users } from 'lucide-vue-next';
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
// Balance acumulado: todo el libro hasta `hasta`, sin importar `desde`.
const acumulado = ref(!!props.filtros.acumulado);
const agruparTercero = ref(!!props.filtros.agrupar_tercero);
const tercero = ref(props.filtros.tercero || null);
// Re-audit M5 R4 UX-M1 · loading state (paridad con Panel y ReporteDetalle).
const cargando = ref(false);

const filtrar = () => {
    router.get('/app/contabilidad', {
        desde: desde.value, hasta: hasta.value,
        incluir_anulados: incluirAnulados.value ? 1 : null,
        acumulado: acumulado.value ? 1 : null,
        agrupar_tercero: agruparTercero.value ? 1 : null,
        tercero_id: tercero.value?.id || null,
        linea: props.filtros.linea !== 'todas' ? props.filtros.linea : null,
    }, {
        preserveScroll: true, preserveState: true, replace: true,
        onStart: () => { cargando.value = true; },
        onFinish: () => { cargando.value = false; },
    });
};
watch([desde, hasta, incluirAnulados, acumulado, agruparTercero, tercero], filtrar);

// Buscador de tercero para filtrar el balance.
const terceroTexto = ref(props.filtros.tercero ? props.filtros.tercero.nombre : '');
const terceroResultados = ref([]);
let terceroDeb;
watch(terceroTexto, (q) => {
    clearTimeout(terceroDeb);
    if (tercero.value || !q || q.trim().length < 2) { terceroResultados.value = []; return; }
    terceroDeb = setTimeout(async () => {
        try {
            const res = await fetch('/app/api/contactos/buscar?q=' + encodeURIComponent(q.trim()), {
                credentials: 'same-origin', headers: { Accept: 'application/json' },
            });
            terceroResultados.value = res.ok ? await res.json() : [];
        } catch { terceroResultados.value = []; }
    }, 300);
});
const elegirTercero = (t) => {
    tercero.value = t;
    terceroTexto.value = t.nombre;
    terceroResultados.value = [];
};
const quitarTercero = () => { tercero.value = null; terceroTexto.value = ''; };

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
                    <input v-model="desde" type="date" class="input" :disabled="acumulado" :class="acumulado ? 'opacity-50' : ''"/>
                    <span class="text-surface-500">→</span>
                    <input v-model="hasta" type="date" class="input"/>
                    <label class="flex items-center gap-2 text-xs font-semibold cursor-pointer select-none"
                           title="Suma todo el libro desde el primer asiento hasta la fecha final: el saldo real de cada cuenta.">
                        <input type="checkbox" v-model="acumulado" class="rounded border-surface-300"/>
                        Acumulado (desde el inicio)
                    </label>
                    <!-- Re-audit R2 UX-M1 · label neutro (sin "DIAN" que asusta a Aracely). -->
                    <label class="ml-auto flex items-center gap-2 text-xs font-semibold cursor-pointer select-none"
                           title="La normativa fiscal exige conservar el histórico 5 años. Actívalo si necesitas ver asientos revertidos.">
                        <input type="checkbox" v-model="incluirAnulados" class="rounded border-surface-300"/>
                        <EyeOff v-if="!incluirAnulados" class="h-3 w-3 text-surface-500"/>
                        <Eye v-else class="h-3 w-3 text-amber-600"/>
                        Incluir asientos anulados
                    </label>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <label class="text-sm text-surface-500">Tercero:</label>
                    <div class="relative w-full sm:w-80">
                        <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-400"/>
                        <input v-model="terceroTexto" :readonly="!!tercero" class="input w-full pl-10 pr-9"
                               placeholder="Todos · busca por nombre o NIT" autocomplete="off"/>
                        <button v-if="tercero" type="button" @click="quitarTercero" title="Quitar filtro"
                                class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-surface-400 hover:text-red-600">
                            <X class="h-4 w-4"/>
                        </button>
                        <div v-if="terceroResultados.length"
                             class="absolute z-10 left-0 right-0 mt-1 max-h-56 overflow-y-auto rounded-lg border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900 shadow-lg">
                            <button v-for="t in terceroResultados" :key="t.id" type="button" @click="elegirTercero(t)"
                                    class="w-full text-left px-3 py-2 text-sm hover:bg-surface-50 dark:hover:bg-surface-800 border-b border-surface-100 dark:border-surface-800 last:border-b-0">
                                <span class="font-semibold">{{ t.nombre }}</span>
                                <span v-if="t.documento" class="text-xs text-surface-500 ml-1">{{ t.documento }}</span>
                            </button>
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-xs font-semibold cursor-pointer select-none"
                           title="Abre cada cuenta en una fila por tercero: cuánto le debes a cada proveedor, cuánto te debe cada cliente.">
                        <input type="checkbox" v-model="agruparTercero" class="rounded border-surface-300"/>
                        <Users class="h-3 w-3 text-surface-500"/>
                        Agrupar por tercero
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
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">
                    📊 Balance de comprobación {{ filtros.agrupar_tercero ? 'por cuenta y tercero' : 'por cuenta' }}
                    <span class="normal-case font-normal text-surface-500">
                        · {{ filtros.acumulado ? `acumulado al ${fechaCorta(filtros.hasta)}` : `${fechaCorta(filtros.desde)} → ${fechaCorta(filtros.hasta)}` }}
                        <template v-if="filtros.tercero"> · solo {{ filtros.tercero.nombre }}</template>
                    </span>
                </div>
                <div v-if="!porCuenta.length" class="text-center py-6 text-surface-500 text-sm">Sin movimientos en el período.</div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[600px] text-sm">
                        <thead>
                            <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                <th class="text-left py-2">Cuenta PUC</th>
                                <th v-if="filtros.agrupar_tercero" class="text-left">Tercero</th>
                                <th class="text-right">Débito</th>
                                <th class="text-right">Crédito</th>
                                <th class="text-right">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(c, i) in porCuenta" :key="c.cuenta_puc + '|' + (c.tercero || '') + '|' + i" class="border-b border-surface-100 dark:border-surface-900 hover:bg-surface-50 dark:hover:bg-surface-900/30">
                                <!-- Re-audit UX-A4 · label del PUC (tooltip + segunda línea). -->
                                <td class="py-2">
                                    <div class="font-mono font-semibold text-brand-600" :title="c.cuenta_nombre || pucLabel(c.cuenta_puc)">{{ c.cuenta_puc }}</div>
                                    <div class="text-[10px] text-surface-500">{{ c.cuenta_nombre || pucLabel(c.cuenta_puc) }}</div>
                                </td>
                                <td v-if="filtros.agrupar_tercero" class="text-xs" :class="c.tercero === 'Sin tercero' ? 'text-surface-400' : ''">{{ c.tercero }}</td>
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
                                <td class="py-2 uppercase text-xs" :colspan="filtros.agrupar_tercero ? 2 : 1">Totales</td>
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
                                <td class="text-xs text-surface-500" :class="m.anulado ? 'opacity-70' : ''">
                                    <a v-if="m.origen_url" :href="m.origen_url" class="text-brand-600 hover:underline" title="Ver el asiento completo y su documento">{{ m.origen }}</a>
                                    <span v-else>{{ m.origen }}</span>
                                </td>
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
