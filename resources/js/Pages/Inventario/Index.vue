<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Warehouse, AlertCircle, ArrowRightLeft, ClipboardCheck, Package, FileDown, Cloud } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import KpiCard from '@/Components/KpiCard.vue';
import BuscadorInventario from '@/Components/BuscadorInventario.vue';

const props = defineProps({
    tab: { type: String, default: 'stock' },
    kpis: { type: Object, required: true },
    stock: { type: Array, required: true },
    traslados: { type: Array, required: true },
    tomas: { type: Array, required: true },
    alertas: { type: Array, required: true },
});
const tabAct = ref(props.tab);

const badgeEstado = (e) => ({
    en_transito: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    ejecutado: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    borrador: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    cerrada: 'bg-emerald-100 text-emerald-800',
    en_curso: 'bg-amber-100 text-amber-800',
    cancelado: 'bg-red-100 text-red-800',
}[e] || 'bg-slate-100 text-slate-700');
</script>

<template>
    <Head title="Inventario y logística"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Warehouse class="h-6 w-6 text-brand-600"/>
                        Inventario y logística
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">Stock por bodega, traslados, tomas físicas y alertas de mínimo/máximo.</p>
                </div>
                <!-- INV-B1 · buscador global · tecla "/" lo enfoca -->
                <div class="w-full md:w-96">
                    <BuscadorInventario/>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <KpiCard label="Unidades vendibles" :value="kpis.unidades_stock" color="emerald" :icon="Package"
                         :subtitle="kpis.unidades_no_vendibles ? `+ ${kpis.unidades_no_vendibles.toLocaleString('es-CO')} en avería o garantía` : ''"/>
                <KpiCard label="Alertas activas" :value="kpis.alertas_activas" color="red" :icon="AlertCircle"/>
                <KpiCard label="Traslados en tránsito" :value="kpis.traslados_transito" color="blue" :icon="ArrowRightLeft"/>
                <KpiCard label="Reservas activas" :value="kpis.reservas_activas" color="amber" :icon="ClipboardCheck"/>
            </div>

            <!-- UBIC-10 · acceso directo al export de saldos iniciales SIIGO -->
            <div class="card p-3 flex items-center justify-between gap-3 flex-wrap border-l-4 border-sky-500 bg-sky-50/40">
                <div class="flex items-center gap-3 flex-1 min-w-[220px]">
                    <Cloud class="h-5 w-5 text-sky-600 shrink-0"/>
                    <div>
                        <div class="text-sm font-bold text-sky-900">Enviar inventario actual a SIIGO</div>
                        <div class="text-xs text-surface-600">
                            Descargá el Excel con el layout de "Saldos iniciales de inventario" de SIIGO y pegalo allá.
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="/app/siigo/saldos-iniciales.xlsx"
                       class="text-sm inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-sky-600 text-white hover:bg-sky-700">
                        <FileDown class="h-4 w-4"/> Descargar Excel SIIGO
                    </a>
                </div>
            </div>

            <div class="flex items-center gap-2 border-b border-surface-200 dark:border-surface-800 overflow-x-auto">
                <button v-for="k in ['stock','traslados','tomas','alertas']" :key="k" @click="tabAct=k"
                        :class="['px-4 py-2 text-sm font-semibold border-b-2 capitalize whitespace-nowrap', tabAct===k ? 'border-brand-500 text-brand-600' : 'border-transparent text-surface-500']">
                    {{ k }}
                </button>
            </div>

            <!-- Stock -->
            <div v-if="tabAct==='stock'" class="card overflow-hidden">
                <div v-if="!stock.length" class="text-center py-12 text-surface-500 text-sm">Aún no hay movimientos de inventario registrados.</div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[700px] text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900"><tr class="text-surface-500 text-xs uppercase">
                            <th class="text-left px-4 py-2">Ref</th>
                            <th class="text-left">Producto</th>
                            <th class="text-left">Color</th>
                            <th class="text-left">Talla</th>
                            <th class="text-left">Bodega</th>
                            <th class="text-right">Stock</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="s in stock" :key="s.codigo + s.bodega" class="border-t border-surface-100 dark:border-surface-900">
                                <td class="px-4 py-2 font-mono text-xs">{{ s.referencia }}</td>
                                <td>{{ s.nombre }}</td>
                                <td>{{ s.color || '—' }}</td>
                                <td>{{ s.talla || '—' }}</td>
                                <td class="text-xs">{{ s.bodega }}</td>
                                <td class="text-right font-bold tabular-nums" :class="s.stock < 10 ? 'text-red-600' : 'text-emerald-600'">{{ s.stock }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Traslados -->
            <div v-if="tabAct==='traslados'" class="card overflow-hidden">
                <div v-if="!traslados.length" class="text-center py-12 text-surface-500 text-sm">Sin traslados registrados.</div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[700px] text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900"><tr class="text-surface-500 text-xs uppercase">
                            <th class="text-left px-4 py-2">Origen</th>
                            <th class="text-left">→ Destino</th>
                            <th class="text-left">Motivo</th>
                            <th class="text-right">Solicitud</th>
                            <th class="text-right">Ejecución</th>
                            <th class="text-center">Estado</th>
                            <th class="text-left">Por</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="t in traslados" :key="t.id" class="border-t border-surface-100 dark:border-surface-900">
                                <td class="px-4 py-2">{{ t.origen }}</td>
                                <td class="text-brand-600">→ {{ t.destino }}</td>
                                <td class="text-xs text-surface-500">{{ t.motivo || '—' }}</td>
                                <td class="text-right text-xs text-surface-500">{{ t.fecha_solicitud || '—' }}</td>
                                <td class="text-right text-xs text-surface-500">{{ t.fecha_ejecucion || '—' }}</td>
                                <td class="text-center"><span :class="['px-2 py-0.5 rounded text-xs font-bold', badgeEstado(t.estado)]">{{ t.estado }}</span></td>
                                <td class="text-xs">{{ t.creador || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tomas -->
            <div v-if="tabAct==='tomas'" class="card overflow-hidden">
                <div v-if="!tomas.length" class="text-center py-12 text-surface-500 text-sm">Sin tomas físicas.</div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[600px] text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900"><tr class="text-surface-500 text-xs uppercase">
                            <th class="text-left px-4 py-2">Bodega</th>
                            <th class="text-left">Alcance</th>
                            <th class="text-right">Fecha</th>
                            <th class="text-center">Estado</th>
                            <th class="text-left">Por</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="t in tomas" :key="t.id" class="border-t border-surface-100 dark:border-surface-900">
                                <td class="px-4 py-2">{{ t.bodega }}</td>
                                <td class="text-xs">{{ t.alcance || '—' }}</td>
                                <td class="text-right text-surface-500">{{ t.fecha_conteo || '—' }}</td>
                                <td class="text-center"><span :class="['px-2 py-0.5 rounded text-xs font-bold', badgeEstado(t.estado)]">{{ t.estado }}</span></td>
                                <td class="text-xs">{{ t.creador || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Alertas -->
            <div v-if="tabAct==='alertas'" class="card overflow-hidden">
                <div v-if="!alertas.length" class="text-center py-12 text-emerald-600 text-sm">
                    <div class="text-3xl">✅</div>
                    <div class="mt-2">Sin alertas de stock activas. Todo dentro de los umbrales.</div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[700px] text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900"><tr class="text-surface-500 text-xs uppercase">
                            <th class="text-left px-4 py-2">Producto</th>
                            <th class="text-left">Talla</th>
                            <th class="text-left">Bodega</th>
                            <th class="text-center">Tipo</th>
                            <th class="text-right">Stock actual</th>
                            <th class="text-right">Umbral</th>
                            <th class="text-right">Creada</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="a in alertas" :key="a.id" class="border-t border-surface-100 dark:border-surface-900">
                                <td class="px-4 py-2">
                                    <div class="font-medium">{{ a.producto }}</div>
                                    <div class="text-xs text-surface-500 font-mono">{{ a.referencia }}</div>
                                </td>
                                <td>{{ a.talla || '—' }}</td>
                                <td class="text-xs">{{ a.bodega }}</td>
                                <td class="text-center"><span class="text-xs uppercase font-bold text-red-600">{{ a.tipo }}</span></td>
                                <td class="text-right font-bold tabular-nums text-red-600">{{ a.stock_actual }}</td>
                                <td class="text-right text-surface-500">{{ a.umbral }}</td>
                                <td class="text-right text-xs text-surface-500">{{ a.creada }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
