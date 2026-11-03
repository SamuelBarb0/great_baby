<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Truck, Cloud, CloudOff, RefreshCw } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import { fechaCorta } from '@/composables/useFecha';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import SiguientePaso from '../SiguientePaso.vue';

const props = defineProps({
    recepcion: { type: Object, required: true },
    paso: { type: Object, default: null },
    contenedor: { type: Object, default: null },
});

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);
const { money } = useMoney();

const badgeEstado = (e) => ({
    confirmada: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    borrador: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    parcial: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    rechazada: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
}[e] || 'bg-surface-100 dark:bg-surface-800');

// Sprint 3 · D.1 · reenvío manual a SIIGO desde detalle.
const reenviando = ref(false);
const reenviarSiigo = () => {
    modalConfirm.value = {
        titulo: '¿Reenviar esta recepción a SIIGO?',
        mensaje: 'Se encola un job manual (bypasea el kill-switch).',
        color: 'sky',
        textoConfirmar: 'Reenviar',
        onConfirmar: () => {
            modalConfirm.value = null;
            reenviando.value = true;
            router.post(`/app/compras/recepcion/${props.recepcion.id}/reenviar-siigo`, {}, {
                preserveScroll: true,
                onFinish: () => { reenviando.value = false; },
            });
        },
    };
};
</script>

<template>
    <Head :title="`Recepción ${recepcion.numero}`"/>
    <AppLayout>
        <div class="max-w-4xl mx-auto space-y-4">
            <Link :href="contenedor ? `/app/compras/importacion/${contenedor.id}` : (recepcion.orden_id ? `/app/compras/oc/${recepcion.orden_id}` : '/app/compras')" class="btn-ghost inline-flex items-center gap-1 text-sm">
                <ArrowLeft class="h-4 w-4"/> {{ contenedor ? `Volver al contenedor ${contenedor.nombre}` : 'Volver a la orden' }}
            </Link>

            <SiguientePaso v-if="paso" :paso="paso"/>

            <div class="card p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs text-surface-500 uppercase">Recepción</div>
                        <h1 class="text-2xl font-bold font-mono">{{ recepcion.numero }}</h1>
                        <div class="text-xs text-surface-500 mt-1">OC: {{ recepcion.orden_numero }} · {{ fechaCorta(recepcion.fecha_recepcion) }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-surface-500 uppercase">Total recibido</div>
                        <div class="text-2xl font-bold text-brand-600">{{ money(recepcion.total_recibido) }}</div>
                        <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badgeEstado(recepcion.estado)]">{{ recepcion.estado }}</span>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4 text-sm border-t pt-3">
                    <div><b>Remisión:</b> {{ recepcion.remision_proveedor || '—' }}</div>
                    <div><b>Factura:</b> {{ recepcion.factura_proveedor || '—' }}</div>
                    <div><b>Transportista:</b> {{ recepcion.transportista || '—' }}</div>
                </div>
                <a v-if="recepcion.id" :href="`/compras/recepcion/${recepcion.id}/pdf`" target="_blank" rel="noopener" class="btn-ghost mt-4">PDF Recepción</a>

                <!-- Sprint 3 · D.1 · badge estado SIIGO -->
                <div class="mt-4 pt-4 border-t flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-sm">
                        <template v-if="recepcion.siigo_id">
                            <Cloud class="h-5 w-5 text-emerald-600"/>
                            <div>
                                <div class="font-semibold text-emerald-700">Sincronizada con SIIGO</div>
                                <div class="text-xs text-surface-500">
                                    <span class="font-mono">{{ recepcion.siigo_number || recepcion.siigo_id }}</span>
                                    <span v-if="recepcion.siigo_sync_hace"> · {{ recepcion.siigo_sync_hace }}</span>
                                </div>
                            </div>
                        </template>
                        <template v-else>
                            <CloudOff class="h-5 w-5 text-amber-600"/>
                            <div>
                                <div class="font-semibold text-amber-700">Pendiente de SIIGO</div>
                                <div class="text-xs text-surface-500">
                                    Se enviará automáticamente al confirmar (con kill-switch encendido).
                                </div>
                            </div>
                        </template>
                    </div>
                    <button v-if="recepcion.estado === 'confirmada'" @click="reenviarSiigo" :disabled="reenviando"
                            class="btn-ghost text-sm inline-flex items-center gap-1"
                            :class="reenviando ? 'opacity-50 cursor-wait' : ''">
                        <RefreshCw :class="['h-4 w-4', reenviando ? 'animate-spin' : '']"/>
                        {{ recepcion.siigo_id ? 'Reenviar' : 'Enviar ahora' }}
                    </button>
                </div>
            </div>

            <div class="card p-4">
                <div class="text-xs uppercase font-bold text-brand-600 mb-2">Ítems ({{ recepcion.items.length }})</div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-right p-2">Cantidad</th>
                            <th class="text-right p-2">Costo unit</th>
                            <th class="text-right p-2">Subtotal</th>
                            <th class="text-left p-2">Lote</th>
                            <th class="text-left p-2">Destino</th>
                            <th class="text-left p-2">Motivo</th>
                            <th class="text-left p-2">Obs</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="it in recepcion.items" :key="it.id"
                            :class="it.clasificacion && it.clasificacion !== 'apto' ? 'bg-amber-50/40 dark:bg-amber-950/20' : ''">
                            <td class="p-2 text-right">{{ it.cantidad_recibida }}</td>
                            <td class="p-2 text-right">{{ money(it.costo_unit) }}</td>
                            <td class="p-2 text-right font-bold">{{ money(it.subtotal) }}</td>
                            <td class="p-2 text-xs">{{ it.lote || '—' }}</td>
                            <td class="p-2">
                                <!-- LOG-J8 · chip del destino -->
                                <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold uppercase"
                                      :class="{
                                        'bg-emerald-100 text-emerald-800': (it.clasificacion || 'apto') === 'apto',
                                        'bg-red-100 text-red-800': it.clasificacion === 'averia',
                                        'bg-amber-100 text-amber-800': it.clasificacion === 'cuarentena',
                                        'bg-blue-100 text-blue-800': it.clasificacion === 'revision',
                                        'bg-surface-200 text-surface-700': it.clasificacion === 'faltante',
                                      }">
                                    {{ { apto:'✓ Apto', averia:'⚠ Avería', cuarentena:'⏳ Cuarent.', revision:'🔍 Revisión', faltante:'✕ Faltante' }[it.clasificacion || 'apto'] }}
                                </span>
                            </td>
                            <td class="p-2 text-xs">{{ it.motivo_clasificacion || '—' }}</td>
                            <td class="p-2 text-xs">{{ it.observaciones || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="recepcion.observaciones" class="card p-4">
                <div class="text-xs uppercase font-bold text-brand-600 mb-2">Observaciones</div>
                <p class="text-sm whitespace-pre-wrap">{{ recepcion.observaciones }}</p>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
