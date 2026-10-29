<script setup>
import { ref, reactive } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, DollarSign, HelpCircle, Plus } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import { usePedidoBadge } from '@/composables/usePedidoBadge';
import { useEscClose } from '@/composables/useEscClose';

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
    pedidosSinCobro: { type: Array, required: true },
    movimientosHuerfanos: { type: Array, required: true },
    kpis: { type: Object, required: true },
    periodo: { type: Object, required: true },
    dias_espera_cobro: { type: Number, default: 40 },
});

const { money } = useMoney();
const { badge } = usePedidoBadge();

// U27 · filtros de fecha.
const desde = ref(props.periodo.desde);
const hasta = ref(props.periodo.hasta);
const filtrar = () => router.get('/app/dropi/discrepancias', { desde: desde.value, hasta: hasta.value }, { preserveState: true });

// U9 · CTA "Crear ajuste" abre el modal Wallet con el pedido/guía prellenados
// y hace POST al mismo endpoint de walletCrear.
const modalAjuste = ref(false);
useEscClose(modalAjuste);
const ajusteForm = reactive({
    pedido_id: null, guia: '', monto: 0, categoria: 'ajuste_discrepancia',
    fecha: hoyBogota(), tipo: 'pago_guia',
});
const abrirAjuste = (p) => {
    ajusteForm.pedido_id = p.id;
    ajusteForm.guia = p.guia;
    ajusteForm.monto = p.monto;
    // Re-audit UX#11 · refrescar fecha al abrir (evita quedar con fecha de ayer
    // si la pestaña quedó abierta cruzando medianoche).
    ajusteForm.fecha = hoyBogota();
    modalAjuste.value = true;
};
const procesandoAjuste = ref(false);
const guardarAjuste = () => {
    if (procesandoAjuste.value) return;
    procesandoAjuste.value = true;
    router.post('/app/dropi/wallet', ajusteForm, {
        preserveScroll: true,
        onSuccess: () => { modalAjuste.value = false; },
        onFinish: () => { procesandoAjuste.value = false; },
    });
};
</script>

<template>
    <Head title="Discrepancias wallet"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <AlertTriangle class="h-6 w-6 text-brand-600"/>
                        Discrepancias wallet Dropi
                    </h1>
                    <p class="text-sm text-surface-500 dark:text-surface-400">
                        Cruce entre pedidos pagados y wallet + entregados sin cobro > {{ dias_espera_cobro }} días.
                        Periodo: <b>{{ periodo.desde }}</b> → <b>{{ periodo.hasta }}</b>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <input type="date" v-model="desde" class="input"/>
                    <input type="date" v-model="hasta" class="input"/>
                    <button @click="filtrar" class="btn-primary">Aplicar</button>
                </div>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 dark:text-emerald-300 text-sm">
                {{ $page.props.flash.success }}
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="card p-4" :class="kpis.pedidos_sin_cobro > 0 ? 'ring-2 ring-red-500' : ''">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400 flex items-center gap-2"><AlertTriangle class="h-3 w-3"/> Pedidos sin cobro</div>
                    <div class="text-3xl font-bold mt-1" :class="kpis.pedidos_sin_cobro > 0 ? 'text-red-600' : ''">{{ kpis.pedidos_sin_cobro }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400 flex items-center gap-2"><DollarSign class="h-3 w-3"/> Monto faltante</div>
                    <div class="text-2xl font-bold mt-1 text-red-600">{{ money(kpis.monto_faltante) }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400 flex items-center gap-2"><HelpCircle class="h-3 w-3"/> Movimientos huérfanos</div>
                    <div class="text-3xl font-bold mt-1">{{ kpis.movimientos_huerfanos }}</div>
                </div>
            </div>

            <div class="card p-4">
                <div class="text-xs uppercase tracking-widest font-bold text-red-600 mb-3">Pedidos sin movimiento en wallet</div>
                <div v-if="!pedidosSinCobro.length" class="text-center py-6 text-emerald-600 text-sm">✅ Todo cuadra — cada pedido pagado tiene su cobro registrado.</div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs text-surface-500 dark:text-surface-400 uppercase border-b border-surface-200 dark:border-surface-800">
                            <tr>
                                <th class="text-left p-2">Guía</th>
                                <th class="text-left p-2">Estado</th>
                                <th class="text-left p-2">Cliente</th>
                                <th class="text-left p-2">Ref fecha</th>
                                <th class="text-right p-2">Monto</th>
                                <th class="text-right p-2">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-for="p in pedidosSinCobro" :key="p.id" class="hover:bg-surface-50 dark:hover:bg-surface-800/50">
                                <td class="p-2 font-mono text-xs">{{ p.guia }}</td>
                                <td class="p-2">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase" :class="badge(p.estado).cls">
                                        {{ badge(p.estado).label }}
                                    </span>
                                </td>
                                <td class="p-2">{{ p.cliente }}</td>
                                <td class="p-2 text-xs">{{ p.pagado ?? p.entregado }}</td>
                                <td class="p-2 text-right font-bold text-red-600">{{ money(p.monto) }}</td>
                                <td class="p-2 text-right">
                                    <button @click="abrirAjuste(p)" class="text-xs font-semibold text-brand-600 hover:underline">
                                        <Plus class="h-3 w-3 inline"/> Crear ajuste
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card p-4">
                <div class="text-xs uppercase tracking-widest font-bold text-amber-600 mb-3">Movimientos wallet SIN pedido asociado</div>
                <div v-if="!movimientosHuerfanos.length" class="text-center py-6 text-surface-500 text-sm">Sin movimientos huérfanos.</div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs text-surface-500 dark:text-surface-400 uppercase border-b border-surface-200 dark:border-surface-800">
                            <tr>
                                <th class="text-left p-2">Fecha</th>
                                <th class="text-left p-2">Tipo</th>
                                <th class="text-left p-2">Categoría</th>
                                <th class="text-left p-2">Ref Dropi</th>
                                <th class="text-right p-2">Monto</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-for="m in movimientosHuerfanos" :key="m.id" class="hover:bg-surface-50 dark:hover:bg-surface-800/50">
                                <td class="p-2 text-xs">{{ m.fecha }}</td>
                                <td class="p-2 text-xs">{{ m.tipo }}</td>
                                <td class="p-2 text-xs">{{ m.categoria }}</td>
                                <td class="p-2 font-mono text-xs">{{ m.referencia }}</td>
                                <td class="p-2 text-right font-bold">{{ money(m.monto) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- U9 · Modal ajuste (prellenado desde la fila) -->
        <div v-if="modalAjuste" @click.self="modalAjuste = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full">
                <h3 class="text-lg font-bold mb-3">Registrar cobro faltante</h3>
                <p class="text-sm text-surface-500 dark:text-surface-400 mb-3">
                    Se creará un movimiento wallet tipo <b>pago_guia</b> vinculado a la guía <b class="font-mono">{{ ajusteForm.guia }}</b>.
                </p>
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="text-xs font-semibold">Fecha</label><input v-model="ajusteForm.fecha" type="date" class="input w-full"/></div>
                        <div><label class="text-xs font-semibold">Monto</label><input v-model.number="ajusteForm.monto" type="number" step="0.01" class="input w-full"/></div>
                    </div>
                    <div><label class="text-xs font-semibold">Categoría (opcional)</label><input v-model="ajusteForm.categoria" class="input w-full"/></div>
                </div>
                <div class="flex items-center justify-end gap-2 mt-4">
                    <button @click="modalAjuste = false" class="btn-ghost">Cancelar</button>
                    <button @click="guardarAjuste" :disabled="procesandoAjuste" class="btn-primary disabled:opacity-50">
                        {{ procesandoAjuste ? '…' : 'Crear ajuste' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
