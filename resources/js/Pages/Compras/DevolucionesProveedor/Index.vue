<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Undo2, Plus, Eye, Cloud, CloudOff, Clock } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';
import { useMoney } from '@/composables/useMoney';

const { money: formato } = useMoney();
defineProps({ items: { type: Object, required: true } });

const badge = (e) => ({
    borrador: 'bg-surface-100 text-surface-700',
    confirmada: 'bg-emerald-100 text-emerald-700',
    anulada: 'bg-rose-100 text-rose-700',
}[e] || 'bg-surface-100 text-surface-700');
</script>

<template>
    <Head title="Devoluciones a proveedor"/>
    <AppLayout>
        <div class="space-y-4 max-w-7xl">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Undo2 class="h-6 w-6 text-brand-600"/>
                        Devoluciones a proveedor
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Baja stock de la bodega + reversa CxP + Nota Crédito de compra en SIIGO.
                    </p>
                </div>
                <Link href="/app/compras/devoluciones/nueva" class="btn-primary">
                    <Plus class="h-4 w-4"/> Nueva devolución
                </Link>
            </div>

            <div class="card overflow-hidden">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b bg-surface-50">
                        <tr>
                            <th class="text-left p-2 w-32">Número</th>
                            <th class="text-left p-2 w-24">Fecha</th>
                            <th class="text-left p-2">Proveedor</th>
                            <th class="text-left p-2">Bodega</th>
                            <th class="text-left p-2">Motivo</th>
                            <th class="text-right p-2 w-28">Total</th>
                            <th class="text-center p-2 w-24">Estado</th>
                            <th class="text-center p-2 w-32">SIIGO</th>
                            <th class="text-right p-2 w-20">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="d in items.data" :key="d.id" class="hover:bg-surface-50">
                            <td class="p-2 font-mono font-bold text-brand-600">{{ d.numero }}</td>
                            <td class="p-2 text-xs">{{ d.fecha }}</td>
                            <td class="p-2">
                                <div>{{ d.proveedor || '—' }}</div>
                                <div class="text-[11px] text-surface-400 font-mono">{{ d.proveedor_doc }}</div>
                            </td>
                            <td class="p-2 text-xs">{{ d.ubicacion || '—' }}</td>
                            <td class="p-2 text-xs">{{ d.motivo }}</td>
                            <td class="p-2 text-right font-mono">{{ formato(d.total) }}</td>
                            <td class="p-2 text-center">
                                <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(d.estado)]">{{ d.estado }}</span>
                            </td>
                            <td class="p-2 text-center">
                                <span v-if="d.siigo_id" class="inline-flex items-center gap-1 text-[11px] bg-emerald-50 text-emerald-700 px-1.5 py-0.5 rounded border border-emerald-200" :title="`NC compra SIIGO · ${d.siigo_sync_at}`">
                                    <Cloud class="h-3 w-3"/> NC {{ d.siigo_id }}
                                </span>
                                <span v-else-if="d.estado === 'confirmada'" class="inline-flex items-center gap-1 text-[11px] bg-amber-50 text-amber-700 px-1.5 py-0.5 rounded border border-amber-200" title="Confirmada pero aún no sincronizada">
                                    <Clock class="h-3 w-3"/> en cola
                                </span>
                                <span v-else class="text-[11px] text-surface-300 inline-flex items-center gap-1">
                                    <CloudOff class="h-3 w-3"/> —
                                </span>
                            </td>
                            <td class="p-2 text-right">
                                <Link :href="`/app/compras/devoluciones/${d.id}`" class="text-brand-600 hover:text-brand-700 p-1 inline-block" title="Abrir">
                                    <Eye class="h-4 w-4"/>
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="!items.data.length">
                            <td colspan="9" class="p-8 text-center text-sm text-surface-500">
                                No hay devoluciones registradas · creá la primera con el botón de arriba.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Paginacion :pagina="items" etiqueta="devoluciones"/>
        </div>
    </AppLayout>
</template>
