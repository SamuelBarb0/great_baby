<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useMoney } from '@/composables/useMoney';
import { BarChart3, AlertTriangle, DollarSign, Users } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import FiltroEjes from '@/Components/FiltroEjes.vue';

const props = defineProps({
    kpis: { type: Object, required: true },
    edades: { type: Object, required: true },
    topMorosos: { type: Array, required: true },
    consignaciones: { type: Array, required: true },
    filtros: { type: Object, default: () => ({ linea: 'todas', destino: 'todas' }) },
    opcionesEjes: { type: Object, default: () => ({ lineas: [], destinos: [] }) },
});

const { money } = useMoney();

// Línea de negocio y destino: «Dropi» = la cartera del e-commerce, sola.
const linea = ref(props.filtros.linea || 'todas');
const destino = ref(props.filtros.destino || 'todas');
watch([linea, destino], () => router.get('/app/cartera/reportes', {
    linea: linea.value, // «todas» viaja: sin línea el servidor muestra sólo Great Baby
    destino: destino.value !== 'todas' ? destino.value : null,
}, { preserveScroll: true, preserveState: true, replace: true }));
</script>

<template>
    <Head title="Reportes cartera"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-end justify-between flex-wrap gap-3">
                <h1 class="text-2xl font-bold flex items-center gap-2"><BarChart3 class="h-6 w-6 text-brand-600"/>Reportes de cartera</h1>
                <FiltroEjes v-model:linea="linea" v-model:destino="destino" :opciones="opcionesEjes"/>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div class="card p-4"><div class="text-xs uppercase text-surface-500 flex items-center gap-1"><DollarSign class="h-3 w-3"/>Total cartera</div><div class="text-2xl font-bold mt-1 text-brand-600">{{ money(kpis.total_cartera) }}</div></div>
                <div class="card p-4" :class="kpis.vencidas_count > 0 ? 'ring-2 ring-red-500' : ''"><div class="text-xs uppercase text-surface-500 flex items-center gap-1"><AlertTriangle class="h-3 w-3"/>Vencidas</div><div class="text-3xl font-bold mt-1" :class="kpis.vencidas_count > 0 ? 'text-red-600' : ''">{{ kpis.vencidas_count }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Cobrado 30d</div><div class="text-2xl font-bold mt-1 text-emerald-600">{{ money(kpis.cobrado_30d) }}</div></div>
            </div>

            <div class="card p-4">
                <div class="text-xs uppercase font-bold text-brand-600 mb-3">Edad de saldos</div>
                <div class="grid grid-cols-5 gap-3">
                    <div v-for="(v, k) in edades" :key="k" class="text-center">
                        <div class="text-xs text-surface-500">{{ k }} días</div>
                        <div class="text-lg font-bold" :class="k === '120+' ? 'text-red-600' : (k === '91-120' ? 'text-amber-600' : '')">{{ money(v) }}</div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="card p-4">
                    <div class="text-xs uppercase font-bold text-brand-600 mb-2 flex items-center gap-2"><Users class="h-3 w-3"/>Top 20 morosos</div>
                    <div v-if="!topMorosos.length" class="text-center py-4 text-emerald-600 text-sm">✅ Sin morosos</div>
                    <table v-tabla-movil v-else class="w-full text-sm">
                        <thead class="text-[10px] uppercase text-surface-500 border-b">
                            <tr><th class="text-left p-2">Cliente</th><th class="text-right p-2">Facturas</th><th class="text-right p-2">Saldo</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="m in topMorosos" :key="m.contacto_id">
                                <td class="p-2">
                                    <div class="font-medium truncate max-w-[200px]" :title="m.nombre">{{ m.nombre }}</div>
                                    <a v-if="m.telefono" :href="`https://wa.me/57${m.telefono.replace(/\D/g,'')}`" target="_blank" class="text-xs text-emerald-600 hover:underline">{{ m.telefono }}</a>
                                </td>
                                <td class="p-2 text-right">{{ m.facturas }}</td>
                                <td class="p-2 text-right font-bold text-red-600">{{ money(m.saldo) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="card p-4">
                    <div class="text-xs uppercase font-bold text-brand-600 mb-2">Consignaciones últimos 30 días</div>
                    <div v-if="!consignaciones.length" class="text-center py-4 text-surface-500 text-sm">Sin pagos</div>
                    <table v-tabla-movil v-else class="w-full text-sm">
                        <thead class="text-[10px] uppercase text-surface-500 border-b">
                            <tr><th class="text-left p-2">Medio</th><th class="text-right p-2">N°</th><th class="text-right p-2">Total</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="c in consignaciones" :key="c.medio">
                                <td class="p-2 capitalize">{{ c.medio }}</td>
                                <td class="p-2 text-right">{{ c.n }}</td>
                                <td class="p-2 text-right font-bold text-emerald-600">{{ money(c.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
