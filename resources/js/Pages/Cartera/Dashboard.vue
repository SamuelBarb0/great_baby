<script setup>
import { ref, computed, watch } from 'vue';
import FiltroEjes from '@/Components/FiltroEjes.vue';
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import { useMoney } from '@/composables/useMoney';
import { useIntervalFn } from '@vueuse/core';
import { Wallet, TrendingUp, AlertCircle, Calendar, Phone, ExternalLink, FileText } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import KpiCard from '@/Components/KpiCard.vue';
import LineChart from '@/Components/LineChart.vue';
import DoughnutChart from '@/Components/DoughnutChart.vue';

// `auth.menu` trae lo que esta persona puede abrir de verdad (App\Auth\MenuApp),
// así los enlaces dentro de las pantallas se esconden con el mismo criterio que
// el menú y no mandan a nadie a un 403.
const puedeReglas = computed(() => (usePage().props.auth?.menu || []).includes('configuracion'));

const props = defineProps({
    semaforo: { type: Object, required: true },
    kpis: { type: Object, required: true },
    topMorosos: { type: Array, required: true },
    cobradoMes: { type: Object, required: true },
    facturadoMes: { type: Object, required: true },
    proximosVencer: { type: Array, required: true },
    refreshSeg: { type: Number, default: 30 },
    bucketDias: { type: Object, required: true },
    filtros: { type: Object, default: () => ({ linea: 'todas', destino: 'todas' }) },
    opcionesEjes: { type: Object, default: () => ({ lineas: [], destinos: [] }) },
    // El contrato pide el semáforo «por tramos Y POR VENDEDOR». Lo segundo no
    // estaba: no había forma de responder quién vende bien pero cobra mal.
    porVendedor: { type: Array, default: () => [] },
    tramos: { type: Array, default: () => [] },
});

// Línea de negocio y destino: «Dropi» = la cartera del e-commerce, sola.
const linea = ref(props.filtros.linea || 'todas');
const destino = ref(props.filtros.destino || 'todas');
watch([linea, destino], () => router.get('/app/cartera', {
    linea: linea.value, // «todas» viaja: sin línea el servidor muestra sólo Great Baby
    destino: destino.value !== 'todas' ? destino.value : null,
}, { preserveScroll: true, preserveState: true, replace: true }));

const sincronizando = ref(false);
const ultimoSync = ref(new Date());

useIntervalFn(() => {
    if (document.visibilityState !== 'visible') return;
    sincronizando.value = true;
    router.reload({
        only: ['semaforo', 'kpis', 'topMorosos', 'cobradoMes', 'facturadoMes', 'proximosVencer', 'porVendedor'],
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { ultimoSync.value = new Date(); },
        onFinish: () => { sincronizando.value = false; },
    });
}, () => Math.max(10, props.refreshSeg) * 1000);

const { money: fmtCOP } = useMoney();
const fmtHora = (d) => d ? d.toLocaleTimeString('es-CO') : '—';

const cobradoDatasets = computed(() => [{
    label: 'Cobrado ($COP)', data: props.cobradoMes.valores, color: '#10b981',
}]);
const facturadoDatasets = computed(() => [{
    label: 'Facturado ($COP)', data: props.facturadoMes.valores, color: '#f59e0b',
}]);

// WhatsApp cobranza — abrir plantilla de mensaje
const abrirWhatsApp = (telefono, nombre, saldo, dias) => {
    if (! telefono) return;
    const tel = String(telefono).replace(/\D/g, '');
    if (tel.length < 10) return;
    const num = tel.startsWith('57') ? tel : '57' + tel;
    const msg = encodeURIComponent(
        `Hola ${nombre}, te escribimos de GREAT BABY. Tenés un saldo pendiente de ${fmtCOP(saldo)}` +
        (dias > 0 ? ` con ${dias} días de vencimiento` : '') +
        `. ¿Cuándo podríamos coordinar el pago? Gracias.`
    );
    window.open(`https://wa.me/${num}?text=${msg}`, '_blank');
};
</script>

<template>
    <Head title="Cartera · Dashboard"/>
    <AppLayout>
        <div class="space-y-5">
            <!-- Header -->
            <div class="card p-5 flex items-start justify-between bg-gradient-to-r from-surface-900/5 to-brand-500/5">
                <div>
                    <h1 class="text-xs uppercase tracking-widest font-bold text-brand-600 flex items-center gap-2">
                        <Wallet class="h-4 w-4"/> Cartera · Dashboard
                    </h1>
                    <div class="text-sm text-surface-500 mt-1">Semáforo de cobros al día, top morosos y proyección del mes.</div>
                    <div class="mt-3">
                        <FiltroEjes v-model:linea="linea" v-model:destino="destino" :opciones="opcionesEjes"/>
                    </div>
                </div>
                <div class="text-xs uppercase tracking-widest flex items-center gap-1.5">
                    <span v-if="sincronizando" class="inline-block h-2 w-2 rounded-full bg-brand-500 animate-pulse"></span>
                    <span v-else class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>
                    <span :class="sincronizando ? 'text-brand-600' : 'text-surface-500'">
                        {{ sincronizando ? 'Sincronizando…' : 'Actualizado ' + fmtHora(ultimoSync) }}
                    </span>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <KpiCard label="Total por cobrar" :value="kpis.total_pendiente" color="amber" format="money" :icon="Wallet"/>
                <KpiCard label="Facturas vencidas" :value="kpis.vencidas_count" color="red" :icon="AlertCircle" subtitle="requieren gestión"/>
                <KpiCard label="Cobrado en el mes" :value="kpis.cobrado_mes" color="emerald" format="money" :icon="TrendingUp"/>
                <KpiCard label="Facturado en el mes" :value="kpis.facturado_mes" color="blue" format="money" :icon="FileText"/>
            </div>

            <!-- Semáforo + Top morosos -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card p-4 lg:col-span-1">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">🚦 Semáforo antigüedad</div>
                    <div class="text-3xl font-black text-brand-600 tabular-nums">{{ fmtCOP(semaforo.total) }}</div>
                    <div class="text-xs text-surface-500 mb-3">{{ semaforo.total_facturas }} facturas pendientes</div>
                    <DoughnutChart
                        :labels="semaforo.labels"
                        :values="semaforo.data"
                        :colors="semaforo.colors"
                        height="200px"/>
                    <!-- `/app/reglas` es sólo de gerencia: a la Contadora este
                         enlace le respondía 403. Se muestra a quien puede abrirlo
                         y al resto se le dice a quién pedírselo. -->
                    <div class="text-xs text-surface-500 mt-3 text-center">
                        <template v-if="puedeReglas">
                            Umbrales configurables en <Link href="/app/reglas" class="text-brand-600 hover:underline">Reglas</Link>
                        </template>
                        <template v-else>
                            Umbrales de antigüedad definidos por gerencia.
                        </template>
                    </div>
                </div>

                <div class="card p-4 lg:col-span-2">
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-xs uppercase tracking-widest font-bold text-red-600">👤 Top morosos</div>
                        <Link href="/app/facturas?filtro=vencidas" class="text-xs text-brand-600 hover:underline flex items-center gap-1">
                            Ver todas <ExternalLink class="h-3 w-3"/>
                        </Link>
                    </div>
                    <div v-if="! topMorosos.length" class="text-center py-8 text-surface-500">
                        <div class="text-3xl">🎉</div>
                        <div class="text-sm mt-2">¡Nadie con mora! Cartera al día.</div>
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table v-tabla-movil class="w-full min-w-[500px] text-sm">
                            <thead>
                                <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                    <th class="text-left py-2">Cliente</th>
                                    <th class="text-right">Saldo</th>
                                    <th class="text-right">Facts</th>
                                    <th class="text-right">Máx. mora</th>
                                    <th class="text-right w-10"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="m in topMorosos" :key="m.id" class="border-b border-surface-100 dark:border-surface-900 hover:bg-surface-50 dark:hover:bg-surface-900/30">
                                    <td class="py-2 font-medium">
                                        <Link :href="'/app/contactos/' + m.id" class="hover:text-brand-600">{{ m.nombre }}</Link>
                                    </td>
                                    <td class="text-right font-bold text-red-600 tabular-nums">{{ fmtCOP(m.saldo) }}</td>
                                    <td class="text-right text-surface-500">{{ m.facturas }}</td>
                                    <td class="text-right">
                                        <span class="px-2 py-0.5 rounded text-xs font-bold" :class="m.dias_max > 90 ? 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' : m.dias_max > 30 ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'">
                                            {{ m.dias_max }}d
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <button v-if="m.telefono" @click="abrirWhatsApp(m.telefono, m.nombre, m.saldo, m.dias_max)"
                                                class="text-emerald-600 hover:text-emerald-800" title="Cobrar por WhatsApp" aria-label="Cobrar por WhatsApp">
                                            <Phone class="h-4 w-4"/>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Cartera por vendedor · lo más vencido arriba -->
            <div class="card p-4">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">
                    🧑‍💼 Cartera por vendedor
                </div>
                <div v-if="! porVendedor.length" class="text-center py-6 text-surface-500 text-sm">
                    Sin cartera pendiente.
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[860px] text-sm">
                        <thead>
                            <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                <th class="text-left py-2">Vendedor</th>
                                <th v-for="t in tramos" :key="t.valor" class="text-right">{{ t.label }}</th>
                                <th class="text-right">Vencido</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="v in porVendedor" :key="v.vendedor_id ?? 'sin'"
                                class="border-b border-surface-100 dark:border-surface-800/60"
                                :class="v.vendedor_id ? '' : 'bg-amber-50/60 dark:bg-amber-900/10'">
                                <td class="py-2">
                                    {{ v.vendedor }}
                                    <!--
                                        Lo que no tiene vendedor va aparte y señalado, no
                                        repartido: esconderlo haría cuadrar el total mintiendo.
                                    -->
                                    <span v-if="! v.vendedor_id" class="text-[10px] text-amber-700 block">
                                        facturas viejas sin vendedor asignado
                                    </span>
                                </td>
                                <td v-for="t in tramos" :key="t.valor" class="text-right tabular-nums"
                                    :style="{ color: v.por_tramo[t.valor]?.monto ? t.color : '' }">
                                    {{ v.por_tramo[t.valor]?.monto ? fmtCOP(v.por_tramo[t.valor].monto) : '—' }}
                                </td>
                                <td class="text-right tabular-nums font-semibold"
                                    :class="v.vencido > 0 ? 'text-red-600' : ''">
                                    {{ fmtCOP(v.vencido) }}
                                </td>
                                <td class="text-right tabular-nums font-bold">{{ fmtCOP(v.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Charts -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="card p-4">
                    <div class="text-xs uppercase tracking-widest font-bold text-emerald-600 mb-3">💰 Cobrado últimos 30 días</div>
                    <LineChart :labels="cobradoMes.labels" :datasets="cobradoDatasets" height="220px"/>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">📄 Facturado últimos 30 días</div>
                    <LineChart :labels="facturadoMes.labels" :datasets="facturadoDatasets" height="220px"/>
                </div>
            </div>

            <!-- Próximos a vencer -->
            <div class="card p-4">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs uppercase tracking-widest font-bold text-amber-600 flex items-center gap-2">
                        <Calendar class="h-4 w-4"/> Próximos a vencer (7 días)
                    </div>
                    <div class="text-xs text-surface-500">{{ proximosVencer.length }} facturas</div>
                </div>
                <div v-if="! proximosVencer.length" class="text-center py-6 text-surface-500 text-sm">
                    Sin facturas por vencer en la próxima semana.
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[600px] text-sm">
                        <thead>
                            <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                <th class="text-left py-2">Factura</th>
                                <th class="text-left">Cliente</th>
                                <th class="text-right">Vence</th>
                                <th class="text-right">Saldo</th>
                                <th class="text-right w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in proximosVencer" :key="p.id" class="border-b border-surface-100 dark:border-surface-900">
                                <td class="py-2 font-mono text-brand-600 font-semibold">
                                    <Link :href="'/app/facturas/' + p.id" class="hover:underline">{{ p.numero }}</Link>
                                </td>
                                <td class="text-surface-800 dark:text-surface-200">{{ p.nombre }}</td>
                                <td class="text-right text-surface-500">{{ p.vence }}</td>
                                <td class="text-right font-bold tabular-nums">{{ fmtCOP(p.saldo) }}</td>
                                <td class="text-right">
                                    <button v-if="p.telefono" @click="abrirWhatsApp(p.telefono, p.nombre, p.saldo, 0)"
                                            class="text-emerald-600 hover:text-emerald-800" title="Recordar por WhatsApp" aria-label="Recordar por WhatsApp">
                                        <Phone class="h-4 w-4"/>
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
