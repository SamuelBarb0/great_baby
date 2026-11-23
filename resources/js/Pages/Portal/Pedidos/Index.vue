<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText } from 'lucide-vue-next';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    pedidos: { type: Object, required: true },
    conteos: { type: Object, required: true },
    estado_filtro: { type: String, default: null },
});

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);

const badgeEstado = (e) => ({
    borrador: 'bg-surface-100 text-surface-700',
    enviado: 'bg-blue-100 text-blue-800',
    aprobado: 'bg-emerald-100 text-emerald-800',
    rechazado: 'bg-red-100 text-red-800',
    facturado: 'bg-brand-100 text-brand-800',
    // Faltaba: un pedido ya despachado caía al gris de «estado desconocido».
    despachado: 'bg-indigo-100 text-indigo-800',
    anulado: 'bg-surface-200 text-surface-600',
}[e] || 'bg-surface-100 text-surface-700');

const filtrar = (e) => router.get('/portal/pedidos', { estado: e || null }, { preserveState: true });

const tabs = [
    { key: '', label: 'Todos' },
    { key: 'enviado', label: 'Enviados' },
    { key: 'aprobado', label: 'Aprobados' },
    { key: 'facturado', label: 'Facturados' },
    // El controlador ya contaba los despachados y nadie los pintaba: el número
    // llegaba y se tiraba, y el cliente no tenía cómo filtrar «qué me enviaron».
    { key: 'despachado', label: 'Despachados' },
    { key: 'rechazado', label: 'Rechazados' },
];
</script>

<template>
    <Head title="Mis pedidos"/>
    <PortalLayout>
        <div class="space-y-4">
            <h1 class="text-2xl font-bold flex items-center gap-2">
                <FileText class="h-6 w-6 text-brand-600"/>
                Mis pedidos
            </h1>

            <div class="card p-3 flex items-center gap-2 flex-wrap">
                <button v-for="t in tabs" :key="t.key" @click="filtrar(t.key)"
                    :class="['px-3 py-1.5 text-sm rounded-lg',
                        (estado_filtro || '') === t.key ? 'bg-brand-600 text-white' : 'bg-surface-100 dark:bg-surface-800 hover:bg-surface-200']">
                    {{ t.label }}
                    <span v-if="Number(conteos[t.key])" class="ml-1 text-xs opacity-80">({{ Number(conteos[t.key]) }})</span>
                </button>
            </div>

            <div v-if="!pedidos.data.length" class="card p-12 text-center text-surface-500">
                No hay pedidos {{ estado_filtro ? 'con ese estado' : 'aún' }}.
            </div>
            <div v-else class="card divide-y divide-surface-100 dark:divide-surface-800">
                <Link v-for="p in pedidos.data" :key="p.id"
                    :href="`/portal/pedidos/${p.id}`"
                    class="flex items-center justify-between p-4 hover:bg-surface-50 dark:hover:bg-surface-800">
                    <div>
                        <div class="font-bold">{{ p.numero }}</div>
                        <div class="text-xs text-surface-500">{{ p.fecha }} · {{ p.items_count }} ítems</div>
                        <!-- Que ya salió de bodega, en la lista: antes había que
                             abrir el pedido para enterarse, y ni ahí se decía. -->
                        <div v-if="p.salio_at" class="text-xs text-surface-500 mt-0.5">
                            Despachado el {{ p.salio_at }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="font-bold text-brand-600">{{ money(p.total) }}</div>
                        <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase mt-1', badgeEstado(p.estado)]">
                            {{ p.estado }}
                        </span>
                        <!-- El estado dice «facturado» igual que un pedido que no
                             ha salido. Para quien está esperando la mercancía, esa
                             diferencia es la única que importa. -->
                        <div v-if="p.resto_pendiente"
                             class="text-[10px] font-bold text-amber-700 dark:text-amber-300 mt-1">
                            salió en partes · {{ p.unidades_despachadas }}/{{ p.unidades_pedidas }}
                        </div>
                    </div>
                </Link>
            </div>
        </div>
    </PortalLayout>
</template>
