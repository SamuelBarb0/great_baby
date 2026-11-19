<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { FileText, DollarSign, CheckCircle2, AlertTriangle, Clock, Receipt } from 'lucide-vue-next';

defineProps({
    pendientes: Array,
    kpis: Object,
});

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);

const colorEdad = (horas) => {
    if (horas == null) return 'text-surface-500';
    if (horas < 2) return 'text-emerald-600';
    if (horas < 8) return 'text-amber-600';
    return 'text-red-600 font-bold';
};
</script>

<template>
    <Head title="Bandeja de facturación"/>
    <AppLayout>
        <div class="space-y-5 max-w-7xl mx-auto">

            <!-- Header -->
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Receipt class="h-6 w-6 text-brand-600"/>
                    Bandeja de facturación
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    <!-- Decía «aprobados + alistados» y no era cierto: se factura
                         ANTES de alistar, porque la factura acompaña la mercancía. -->
                    Pedidos aprobados listos para facturar. La factura sale primero y
                    después bodega alista con ella.
                </p>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="card p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs uppercase font-semibold text-surface-500">Pendientes de facturar</div>
                        <FileText class="h-4 w-4 text-brand-600"/>
                    </div>
                    <div class="text-2xl font-black text-brand-700">{{ kpis.pendientes }}</div>
                </div>
                <div class="card p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs uppercase font-semibold text-surface-500">Monto por facturar</div>
                        <DollarSign class="h-4 w-4 text-amber-600"/>
                    </div>
                    <div class="text-2xl font-black text-amber-700">{{ money(kpis.monto_pendiente) }}</div>
                </div>
                <div class="card p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs uppercase font-semibold text-surface-500">Facturadas hoy</div>
                        <CheckCircle2 class="h-4 w-4 text-emerald-600"/>
                    </div>
                    <div class="text-2xl font-black text-emerald-700">{{ kpis.facturadas_hoy }}</div>
                </div>
            </div>

            <!-- Tabla pendientes -->
            <div class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-800 flex items-center justify-between">
                    <h3 class="font-semibold flex items-center gap-2">
                        <Clock class="h-5 w-5 text-brand-600"/>
                        Pedidos listos para facturar · {{ pendientes.length }}
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900 border-b">
                            <tr>
                                <th class="p-3 text-left">Pedido</th>
                                <th class="p-3 text-left">Cliente</th>
                                <th class="p-3 text-left">Ciudad</th>
                                <th class="p-3 text-left">Ubicación</th>
                                <th class="p-3 text-center">Items</th>
                                <th class="p-3 text-right">Total</th>
                                <th class="p-3 text-left">Vendedor</th>
                                <th class="p-3 text-left">Alistado</th>
                                <th class="p-3 text-center">Email?</th>
                                <th class="p-3 text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-if="!pendientes.length">
                                <td colspan="10" class="text-center py-10 text-surface-400">
                                    <CheckCircle2 class="h-8 w-8 mx-auto mb-2 text-emerald-500"/>
                                    No hay pedidos esperando factura. ¡Todo al día!
                                </td>
                            </tr>
                            <tr v-for="p in pendientes" :key="p.id" class="hover:bg-brand-50/40 dark:hover:bg-brand-950/20">
                                <td class="p-3 font-mono text-xs font-bold">{{ p.numero }}</td>
                                <td class="p-3">
                                    <div class="font-semibold">{{ p.cliente }}</div>
                                    <div class="text-xs text-surface-500">NIT {{ p.cliente_doc || '—' }}</div>
                                </td>
                                <td class="p-3 text-xs text-surface-600">{{ p.ciudad || '—' }}</td>
                                <td class="p-3 text-xs font-mono">{{ p.ubicacion || '—' }}</td>
                                <td class="p-3 text-center">{{ p.items_count }}</td>
                                <td class="p-3 text-right font-bold">{{ money(p.total) }}</td>
                                <td class="p-3 text-xs">{{ p.vendedor || '—' }}</td>
                                <td class="p-3 text-xs">
                                    <div>{{ p.alistado_fin_at }}</div>
                                    <div :class="['text-[10px]', colorEdad(p.horas_desde_alistado)]">
                                        hace {{ p.horas_desde_alistado }}h
                                    </div>
                                </td>
                                <td class="p-3 text-center">
                                    <AlertTriangle v-if="!p.tiene_email_cliente" class="h-4 w-4 text-amber-500 mx-auto" title="Sin email"/>
                                    <CheckCircle2 v-else class="h-4 w-4 text-emerald-500 mx-auto"/>
                                </td>
                                <td class="p-3 text-center">
                                    <Link :href="`/app/facturacion/preview/${p.id}`"
                                          class="inline-flex items-center gap-1 px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded text-xs font-bold">
                                        Revisar →
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
