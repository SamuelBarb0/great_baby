<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onBeforeUnmount, ref } from 'vue';
import { Package, Truck, Boxes, ScanLine } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import { usePedidoBadge } from '@/composables/usePedidoBadge';
import { obtenerUbicacion } from '@/composables/useUbicacion';

const props = defineProps({
    modo: { type: String, required: true },
    corte: { type: Object, default: null },
    recoleccion: { type: Array, required: true },
    empaque: { type: Array, required: true },
});

const { money } = useMoney();
const { badge } = usePedidoBadge();

const cambiarModo = (m) => router.get('/app/dropi/alistador', { modo: m }, { preserveState: true, preserveScroll: true });

// Anti doble-click: mantiene un id "en proceso" para deshabilitar botones.
const procesandoId = ref(null);

const tomar = (id) => {
    if (procesandoId.value) return;
    procesandoId.value = id;
    router.post(`/app/dropi/alistador/pedido/${id}/tomar`, {}, {
        preserveScroll: true,
        onFinish: () => (procesandoId.value = null),
    });
};

const despachar = async (id) => {
    if (procesandoId.value) return;
    procesandoId.value = id;
    // Capa de seguridad: desde dónde se despachó (ver Trazabilidad).
    const gps = await obtenerUbicacion();
    router.post(`/app/dropi/alistador/pedido/${id}/despachar`, { gps }, {
        preserveScroll: true,
        onFinish: () => (procesandoId.value = null),
    });
};

// P8 · Heartbeat: mientras el alistador tenga la pestaña abierta, mantiene vivos
// sus locks (evita que el cron dropi:liberar-locks los mate en pleno empaque).
let heartbeatInterval;
let pollInterval;
onMounted(() => {
    heartbeatInterval = setInterval(() => {
        // Re-audit UX#6 · si la pestaña está oculta no enviamos heartbeat.
        // Deja que el cron libere los locks del alistador que ya no está mirando.
        if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return;
        fetch('/app/dropi/alistador/heartbeat', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                Accept: 'application/json',
            },
        }).catch(() => {});
    }, 60_000);

    // U26 · polling suave de la cola cada 30s SÓLO en modo empaque, así dos
    // alistadores no compiten por el mismo pedido a segundos de diferencia.
    if (props.modo === 'empaque') {
        pollInterval = setInterval(() => {
            router.reload({ only: ['empaque'], preserveScroll: true });
        }, 30_000);
    }
});
onBeforeUnmount(() => {
    heartbeatInterval && clearInterval(heartbeatInterval);
    pollInterval && clearInterval(pollInterval);
});
</script>

<template>
    <Head title="Vista del Alistador"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Boxes class="h-6 w-6 text-brand-600"/>
                        Vista del Alistador
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Corte activo: <b>{{ corte?.numero ?? '—' }}</b> · {{ corte?.fecha ?? '' }}
                    </p>
                </div>
                <div class="card p-1 flex">
                    <button @click="cambiarModo('recoleccion')"
                        :class="['px-4 py-2 rounded text-sm font-semibold',
                            modo === 'recoleccion' ? 'bg-brand-600 text-white' : 'text-surface-600 dark:text-surface-300']">
                        🛒 Recolección
                    </button>
                    <button @click="cambiarModo('empaque')"
                        :class="['px-4 py-2 rounded text-sm font-semibold',
                            modo === 'empaque' ? 'bg-brand-600 text-white' : 'text-surface-600 dark:text-surface-300']">
                        📦 Empaque
                    </button>
                </div>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 dark:text-emerald-300 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-sm">
                {{ $page.props.flash.error }}
            </div>

            <!-- Modo recolección: totales por producto -->
            <div v-if="modo === 'recoleccion'" class="card p-5">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">
                    Lista de recolección — recorre bodega una sola vez
                </div>
                <div v-if="!recoleccion.length" class="text-center py-8 text-surface-500 text-sm">
                    Nada por recolectar en el corte actual.
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs text-surface-500 uppercase border-b border-surface-200 dark:border-surface-800">
                            <tr>
                                <th class="text-left p-3">SKU</th>
                                <th class="text-left p-3">Producto</th>
                                <th class="text-left p-3">Variante</th>
                                <th class="text-right p-3">Total unidades</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-for="r in recoleccion" :key="r.sku" class="hover:bg-surface-50 dark:hover:bg-surface-800/50">
                                <td class="p-3 font-mono text-xs">{{ r.sku }}</td>
                                <td class="p-3">
                                    <div class="font-medium">{{ r.producto }}</div>
                                    <div class="text-xs text-surface-500">{{ r.referencia }}</div>
                                </td>
                                <td class="p-3 text-xs">{{ r.variante }}</td>
                                <td class="p-3 text-right font-bold text-brand-600 text-lg">{{ r.total }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modo empaque: cola de pedidos. Empacar se hace SOLO en la Estación (P2). -->
            <div v-if="modo === 'empaque'" class="card p-5">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">
                    Cola de empaque compartida ({{ empaque.length }})
                </div>
                <div v-if="!empaque.length" class="text-center py-8 text-surface-500 text-sm">
                    Sin pedidos pendientes de empacar (o todos tomados por otros alistadores).
                </div>
                <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div v-for="p in empaque" :key="p.id" class="border border-surface-200 dark:border-surface-800 rounded-lg p-4 hover:shadow-md dark:bg-surface-900">
                        <div class="flex items-start justify-between mb-2">
                            <div>
                                <div class="text-xs text-surface-500">Guía</div>
                                <div class="font-mono font-bold">{{ p.guia }}</div>
                            </div>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase" :class="badge(p.estado).cls">
                                {{ badge(p.estado).label }}
                            </span>
                        </div>
                        <div class="text-sm font-medium">{{ p.cliente }}</div>
                        <div class="text-xs text-surface-500">{{ p.ciudad }}</div>
                        <div class="flex items-center justify-between mt-1">
                            <div class="text-sm font-bold text-brand-600">{{ money(p.monto) }}</div>
                            <div class="text-[11px] text-surface-500">
                                {{ p.items_count || 0 }} ítem{{ (p.items_count || 0) === 1 ? '' : 's' }} · {{ p.items_unidades || 0 }} und
                            </div>
                        </div>
                        <div class="mt-3 flex items-center gap-2">
                            <button v-if="p.estado === 'pending' || p.estado === 'pendiente_inventario'"
                                @click="tomar(p.id)"
                                :disabled="procesandoId === p.id"
                                class="btn-primary flex-1 text-xs disabled:opacity-50 disabled:cursor-not-allowed">
                                <Package class="h-3 w-3"/> {{ procesandoId === p.id ? 'Tomando…' : 'Tomar' }}
                            </button>
                            <Link v-else-if="p.estado === 'alistando'"
                                :href="`/app/estacion-empaque?pedido=${p.id}`"
                                class="btn-primary flex-1 text-xs bg-indigo-600 hover:bg-indigo-700 text-center">
                                <ScanLine class="h-3 w-3 inline"/> Ir a Estación
                            </Link>
                            <button v-if="p.estado === 'empacado'"
                                @click="despachar(p.id)"
                                :disabled="procesandoId === p.id"
                                class="btn-primary flex-1 text-xs bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed">
                                <Truck class="h-3 w-3"/> {{ procesandoId === p.id ? 'Despachando…' : 'Despachar' }}
                            </button>
                        </div>
                    </div>
                </div>
                <p class="mt-4 text-[11px] text-surface-500 italic">
                    Nota: para <b>Empacar</b> el pedido, escanea los ítems en la <b>Estación de Empaque</b> —
                    no se puede saltar ese paso.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
