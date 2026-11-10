<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, Search, Clock, AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    cotizaciones: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    resumen: { type: Object, default: () => ({}) },
});

const { money: fmtCOP } = useMoney();

const q = ref(props.filtros.q || '');
const estado = ref(props.filtros.estado || '');

let deb;
const buscar = () => {
    clearTimeout(deb);
    deb = setTimeout(() => {
        router.get('/app/cotizaciones',
            { q: q.value || undefined, estado: estado.value || undefined },
            { preserveState: true, replace: true });
    }, 350);
};

const COLOR = {
    borrador: 'bg-surface-200 text-surface-700 dark:bg-surface-800 dark:text-surface-300',
    enviada: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    aceptada: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    rechazada: 'bg-red-500/15 text-red-700 dark:text-red-300',
    convertida: 'bg-brand-500/15 text-brand-700 dark:text-brand-300',
    vencida: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
};
</script>

<template>
    <Head title="Cotizaciones"/>
    <AppLayout>
        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <FileText class="h-6 w-6 text-brand-600"/> Cotizaciones
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Lo que se le prometió a un cliente antes de que haya pedido. Una cotización
                    <strong>no compromete inventario</strong> y <strong>vence</strong>: al convertirla
                    en pedido se respetan los precios que se cotizaron, no los de hoy.
                </p>
            </div>

            <div v-if="$page.props.flash?.success"
                 class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>

            <!--
                Lo que de verdad importa del tablero: una cotización aceptada
                que vence sin convertirse es plata que el cliente YA dijo que sí.
            -->
            <div v-if="resumen.por_vencer > 0"
                 class="p-3 rounded-lg bg-amber-500/15 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200 text-sm flex gap-2">
                <AlertTriangle class="h-5 w-5 shrink-0"/>
                <div>
                    <strong>{{ resumen.por_vencer }} cotización(es) aceptada(s) por {{ fmtCOP(resumen.monto_por_vencer) }}
                    vencen en los próximos {{ resumen.dias_aviso }} días</strong> y todavía no se pasaron a pedido.
                    Es plata que el cliente ya aceptó: pasado el vencimiento hay que recotizar.
                </div>
            </div>

            <div class="card p-4 flex flex-wrap items-center gap-6">
                <div>
                    <div class="text-[10px] uppercase text-surface-500">Vigentes</div>
                    <div class="font-black text-lg">{{ resumen.vigentes }}</div>
                </div>
                <div class="h-9 border-l border-surface-200 dark:border-surface-800"></div>
                <div>
                    <div class="text-[10px] uppercase text-surface-500">Cotizado vigente</div>
                    <div class="font-black text-lg tabular-nums">{{ fmtCOP(resumen.monto_vigente) }}</div>
                </div>
                <div class="h-9 border-l border-surface-200 dark:border-surface-800"></div>
                <div>
                    <div class="text-[10px] uppercase text-surface-500">Aceptadas sin convertir</div>
                    <div class="font-black text-lg" :class="resumen.aceptadas_sin_convertir ? 'text-amber-600' : ''">
                        {{ resumen.aceptadas_sin_convertir }}
                    </div>
                </div>
            </div>

            <div class="card p-3 flex items-center gap-2 flex-wrap">
                <div class="relative flex-1 min-w-[200px]">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-500"/>
                    <input v-model="q" @input="buscar" placeholder="Número o cliente…"
                           class="input w-full pl-10"/>
                </div>
                <select v-model="estado" @change="buscar"
                        class="px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-sm">
                    <option value="">Todas</option>
                    <option value="borrador">Borrador</option>
                    <option value="enviada">Enviadas</option>
                    <option value="aceptada">Aceptadas</option>
                    <option value="rechazada">Rechazadas</option>
                    <option value="convertida">Convertidas</option>
                    <option value="vencidas">Vencidas</option>
                </select>
            </div>

            <div class="card overflow-hidden">
                <div v-if="! cotizaciones.data.length" class="text-center py-10 text-surface-500">
                    <FileText class="h-8 w-8 mx-auto mb-2 opacity-40"/>
                    <p class="text-sm">No hay cotizaciones todavía.</p>
                    <p class="text-xs mt-1">
                        Se arman desde el panel del vendedor: buscás el cliente, armás la lista y
                        elegís «Guardar como cotización» en vez de enviar el pedido.
                    </p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                <th class="text-left py-2 px-3">Número</th>
                                <th class="text-left">Cliente</th>
                                <th class="text-left">Vendedor</th>
                                <th class="text-left">Vence</th>
                                <th class="text-right">Total</th>
                                <th class="text-left pl-3">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in cotizaciones.data" :key="c.id"
                                class="border-b border-surface-100 dark:border-surface-800/60 hover:bg-surface-50 dark:hover:bg-surface-900/30">
                                <td class="py-2 px-3">
                                    <Link :href="`/app/cotizaciones/${c.id}`" class="font-medium hover:text-brand-600 font-mono">
                                        {{ c.numero }}
                                    </Link>
                                </td>
                                <td>{{ c.cliente }}</td>
                                <td class="text-surface-500 text-xs">{{ c.vendedor }}</td>
                                <td class="text-xs">
                                    {{ c.vigente_hasta }}
                                    <span v-if="c.estado !== 'convertida' && c.estado !== 'rechazada'
                                                && c.dias_para_vencer !== null && c.dias_para_vencer >= 0 && c.dias_para_vencer <= 3"
                                          class="block text-[10px] text-amber-600 flex items-center gap-1">
                                        <Clock class="h-3 w-3"/>
                                        {{ c.dias_para_vencer === 0 ? 'vence hoy' : `quedan ${c.dias_para_vencer} días` }}
                                    </span>
                                </td>
                                <td class="text-right tabular-nums font-semibold">{{ fmtCOP(c.total) }}</td>
                                <td class="pl-3">
                                    <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-bold" :class="COLOR[c.estado]">
                                        {{ c.estado }}
                                    </span>
                                    <span v-if="c.pedido" class="block text-[10px] text-surface-500 mt-0.5 font-mono">
                                        → {{ c.pedido }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
