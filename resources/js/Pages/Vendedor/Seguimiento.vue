<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Clock, DollarSign, History, Eye, CheckCircle2, PackageX } from 'lucide-vue-next';

defineProps({
    pendientes: Array,
    /*
     * Facturados completos a los que les falta mercancía.
     *
     * Va como grupo aparte y NO dentro de los conteos por estado: un parcial
     * sigue siendo «facturado», y meterlo ahí lo contaría dos veces. Para el
     * vendedor es el grupo que explica por qué varios «por cobrar» no se pagan.
     */
    incompletos: { type: Array, default: () => [] },
    por_cobrar: Array,
    ultimos: Array,
    totales: Object,
    es_super: Boolean,
});

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);

const estadoChip = (e) => ({
    borrador: 'bg-surface-200 text-surface-700',
    enviado: 'bg-blue-100 text-blue-800',
    retenido: 'bg-amber-100 text-amber-900 ring-1 ring-amber-400',
    aprobado: 'bg-emerald-100 text-emerald-800',
    facturado: 'bg-fuchsia-100 text-fuchsia-800',
    despachado: 'bg-indigo-100 text-indigo-800',
    rechazado: 'bg-red-100 text-red-800',
}[e] || 'bg-surface-100');

const fechaCorta = (iso) => {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' });
};
</script>

<template>
    <Head title="Seguimiento de pedidos"/>
    <AppLayout>
        <div class="space-y-5 max-w-7xl mx-auto">

            <div>
                <h1 class="text-2xl font-bold">Seguimiento de pedidos</h1>
                <p class="text-sm text-surface-500 mt-1">Qué hay abierto, qué está por cobrar y los últimos movimientos.</p>
            </div>

            <!-- Pedidos pendientes (sin facturar) -->
            <div class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-800 flex items-center justify-between">
                    <h3 class="font-semibold flex items-center gap-2">
                        <Clock class="h-5 w-5 text-surface-500"/>
                        Pedidos sin facturar
                    </h3>
                    <span class="px-2 py-0.5 bg-surface-200 dark:bg-surface-700 text-xs rounded font-semibold">
                        {{ totales.pendientes }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900">
                            <tr>
                                <th class="p-3 text-left">N.º</th>
                                <th class="p-3 text-left">Cliente</th>
                                <th class="p-3 text-left">Estado</th>
                                <th class="p-3 text-right">Monto</th>
                                <th class="p-3 text-left">Creado</th>
                                <th class="p-3 text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-if="!pendientes.length">
                                <td colspan="6" class="text-center text-surface-400 py-8">
                                    <CheckCircle2 class="h-7 w-7 mx-auto mb-2 text-emerald-500"/>
                                    No tienes pedidos pendientes. ¡Todo al día!
                                </td>
                            </tr>
                            <tr v-for="p in pendientes" :key="p.id" class="hover:bg-surface-50 dark:hover:bg-surface-900">
                                <td class="p-3 font-mono text-xs font-bold">{{ p.numero }}</td>
                                <td class="p-3">
                                    <div>{{ p.cliente }}</div>
                                    <div v-if="p.motivo_retencion" class="text-[10px] text-amber-700 italic mt-0.5">
                                        ⏸ {{ p.motivo_retencion }}
                                    </div>
                                </td>
                                <td class="p-3">
                                    <span class="px-2 py-0.5 rounded text-xs font-semibold" :class="estadoChip(p.estado)">
                                        {{ p.estado }}
                                    </span>
                                </td>
                                <td class="p-3 text-right font-bold">{{ money(p.total) }}</td>
                                <td class="p-3 text-xs text-surface-600">{{ p.fecha }}</td>
                                <td class="p-3 text-center">
                                    <Link :href="`/app/pedidos-b2b/${p.id}`"
                                          class="inline-flex items-center gap-1 px-2 py-1 bg-brand-50 hover:bg-brand-100 text-brand-700 rounded text-xs font-semibold">
                                        <Eye class="h-3 w-3"/> Ver
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!--
                FACTURADO COMPLETO, DESPACHADO A MEDIAS.

                El vendedor es quien llama a cobrar, y este caso es la razón
                número uno de que un cliente no pague: la factura llegó entera
                y la mercancía no. Antes esto no se veía desde ninguna parte —el
                pedido decía «facturado» igual que los demás— así que el
                vendedor insistía sobre una mora que no era mora.

                Sólo se dibuja si hay alguno: no es un apartado permanente, es
                una alarma.
            -->
            <div v-if="incompletos.length" class="card overflow-hidden ring-2 ring-amber-400">
                <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-800 flex items-center justify-between bg-amber-50/60 dark:bg-amber-950/30">
                    <h3 class="font-semibold flex items-center gap-2">
                        <PackageX class="h-5 w-5 text-amber-600"/>
                        Les falta mercancía
                    </h3>
                    <span class="px-2 py-0.5 bg-amber-100 text-amber-900 text-xs rounded font-semibold">
                        {{ totales.incompletos }} pedido{{ totales.incompletos === 1 ? '' : 's' }}
                        · {{ totales.unidades_debidas }} unidades
                    </span>
                </div>
                <div class="px-4 py-2 text-xs text-surface-600 dark:text-surface-400 border-b border-surface-100 dark:border-surface-800">
                    Se facturó todo y salió una parte. Antes de cobrar, revisa qué falta.
                </div>
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900">
                            <tr>
                                <th class="p-3 text-left">N.º</th>
                                <th class="p-3 text-left">Cliente</th>
                                <th class="p-3 text-center">Salieron</th>
                                <th class="p-3 text-right">Monto</th>
                                <th class="p-3 text-left">Salió el</th>
                                <th class="p-3 text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-for="p in incompletos" :key="p.id" class="hover:bg-amber-50/40 dark:hover:bg-amber-950/20">
                                <td class="p-3 font-mono text-xs font-bold">{{ p.numero }}</td>
                                <td class="p-3">
                                    <div>{{ p.cliente }}</div>
                                    <div v-if="p.factura" class="text-[10px] text-surface-500">{{ p.factura }}</div>
                                </td>
                                <td class="p-3 text-center">
                                    <span class="font-bold">{{ p.unidades_despachadas }}</span>
                                    <span class="text-surface-500">/{{ p.unidades_pedidas }}</span>
                                    <div class="text-[10px] font-bold text-amber-700 dark:text-amber-300">
                                        faltan {{ p.unidades_pendientes }}
                                    </div>
                                </td>
                                <td class="p-3 text-right font-bold">{{ money(p.total) }}</td>
                                <td class="p-3 text-xs text-surface-600">{{ p.salio_at || '—' }}</td>
                                <td class="p-3 text-center">
                                    <Link :href="`/app/pedidos-b2b/${p.id}`"
                                          class="inline-flex items-center gap-1 px-2 py-1 bg-brand-50 hover:bg-brand-100 text-brand-700 rounded text-xs font-semibold">
                                        <Eye class="h-3 w-3"/> Ver
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagos por cobrar -->
            <div class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-800 flex items-center justify-between">
                    <h3 class="font-semibold flex items-center gap-2">
                        <DollarSign class="h-5 w-5 text-amber-600"/>
                        Pagos por cobrar
                    </h3>
                    <span class="px-2 py-0.5 bg-amber-100 text-amber-800 text-xs rounded font-semibold">
                        {{ money(totales.por_cobrar) }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900">
                            <tr>
                                <th class="p-3 text-left">Pedido</th>
                                <th class="p-3 text-left">Factura</th>
                                <th class="p-3 text-left">Cliente</th>
                                <th class="p-3 text-right">Saldo</th>
                                <th class="p-3 text-left">Facturado</th>
                                <th class="p-3 text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-if="!por_cobrar.length">
                                <td colspan="6" class="text-center text-surface-400 py-8">
                                    <CheckCircle2 class="h-7 w-7 mx-auto mb-2 text-emerald-500"/>
                                    No tienes pagos por cobrar. ¡Todo cobrado!
                                </td>
                            </tr>
                            <tr v-for="p in por_cobrar" :key="p.id" class="hover:bg-amber-50/40 dark:hover:bg-amber-950/20 bg-amber-50/20">
                                <td class="p-3 font-mono text-xs font-bold">{{ p.numero }}</td>
                                <td class="p-3 text-xs text-surface-600">{{ p.factura || '—' }}</td>
                                <td class="p-3">
                                    {{ p.cliente }}
                                    <!-- Acá es donde el vendedor está parado cuando
                                         llama a cobrar: si falta mercancía, tiene que
                                         saberlo ANTES de levantar el teléfono. -->
                                    <div v-if="p.resto_pendiente"
                                         class="text-[10px] font-bold text-amber-700 dark:text-amber-300 mt-0.5">
                                        ⏳ le faltan {{ p.unidades_pendientes }} unidades
                                    </div>
                                </td>
                                <td class="p-3 text-right font-bold text-amber-700">{{ money(p.saldo_factura) }}</td>
                                <td class="p-3 text-xs text-surface-600">{{ p.facturado_at || '—' }}</td>
                                <td class="p-3 text-center">
                                    <Link :href="`/app/pedidos-b2b/${p.id}`"
                                          class="inline-flex items-center gap-1 px-2 py-1 bg-brand-50 hover:bg-brand-100 text-brand-700 rounded text-xs font-semibold">
                                        <Eye class="h-3 w-3"/> Ver
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Últimos movimientos -->
            <div class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-800 flex items-center justify-between">
                    <h3 class="font-semibold flex items-center gap-2">
                        <History class="h-5 w-5 text-brand-600"/>
                        Últimos movimientos
                    </h3>
                    <span class="px-2 py-0.5 bg-brand-100 text-brand-800 text-xs rounded font-semibold">
                        {{ totales.ultimos }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900">
                            <tr>
                                <th class="p-3 text-left">N.º</th>
                                <th class="p-3 text-left">Cliente</th>
                                <th class="p-3 text-right">Monto</th>
                                <th class="p-3 text-left">Estado</th>
                                <th class="p-3 text-left">Fecha</th>
                                <th class="p-3 text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-if="!ultimos.length">
                                <td colspan="6" class="text-center text-surface-400 py-6 italic">
                                    Sin movimientos recientes
                                </td>
                            </tr>
                            <tr v-for="p in ultimos" :key="p.id" class="hover:bg-surface-50 dark:hover:bg-surface-900">
                                <td class="p-3 font-mono text-xs">{{ p.numero }}</td>
                                <td class="p-3">{{ p.cliente }}</td>
                                <td class="p-3 text-right font-bold">{{ money(p.total) }}</td>
                                <td class="p-3">
                                    <span class="px-2 py-0.5 rounded text-xs font-semibold" :class="estadoChip(p.estado)">
                                        {{ p.estado }}
                                    </span>
                                    <!-- El chip de estado dice «facturado» tanto para
                                         el que salió completo como para el que salió a
                                         medias. La marca va al lado, no dentro. -->
                                    <span v-if="p.resto_pendiente"
                                          class="ml-1 px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                                        falta resto
                                    </span>
                                </td>
                                <td class="p-3 text-xs text-surface-600">{{ p.fecha }}</td>
                                <td class="p-3 text-center">
                                    <Link :href="`/app/pedidos-b2b/${p.id}`"
                                          class="inline-flex items-center gap-1 px-2 py-1 bg-brand-50 hover:bg-brand-100 text-brand-700 rounded text-xs font-semibold">
                                        <Eye class="h-3 w-3"/> Ver
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
