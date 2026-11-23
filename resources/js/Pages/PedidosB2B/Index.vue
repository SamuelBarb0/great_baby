<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Package, RefreshCw } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    pedidos: { type: Object, required: true },
    conteos: { type: Object, required: true },
    estado_filtro: { type: String, default: null },
    // Cuántos pedidos deben mercancía. Va aparte de `conteos` —que es el
    // GROUP BY por estado— porque un parcial sigue siendo «facturado»: si
    // entrara ahí se contaría dos veces.
    parciales_pendientes: { type: Number, default: 0 },
    solo_pendientes: { type: Boolean, default: false },
});

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);
const badgeEstado = (e) => ({
    borrador: 'bg-surface-100 text-surface-700',
    enviado: 'bg-blue-100 text-blue-800',
    retenido: 'bg-amber-100 text-amber-800 ring-1 ring-amber-400',
    aprobado: 'bg-emerald-100 text-emerald-800',
    rechazado: 'bg-red-100 text-red-800',
    facturado: 'bg-brand-100 text-brand-800',
    despachado: 'bg-indigo-100 text-indigo-800',
    anulado: 'bg-surface-200 text-surface-600',
}[e] || 'bg-surface-100');

const cargando = ref(false);
const filtrar = (e) => router.get('/app/pedidos-b2b', { estado: e || null }, {
    preserveState: true,
    onStart: () => cargando.value = true,
    onFinish: () => cargando.value = false,
});

/*
 * «Salió a medias» NO es una pestaña de estado: el pedido sigue siendo
 * «facturado». Va por su propio parámetro, y al activarlo se suelta el filtro
 * de estado para no pedir una intersección que casi siempre da vacío.
 */
const verPendientes = () => router.get('/app/pedidos-b2b',
    props.solo_pendientes ? {} : { resto_pendiente: 1 },
    { preserveState: true, onStart: () => cargando.value = true, onFinish: () => cargando.value = false });

// C-QA-D-8: auto-refresh cada 30s para que los nuevos B2B aparezcan sin acción manual.
let intervalId;
onMounted(() => {
    intervalId = setInterval(() => {
        /*
         * Pedía además una prop «badges» que el controlador NUNCA mandó, así
         * que ese nombre no refrescaba nada. Se cambia por la que sí existe:
         * el contador de pedidos que deben mercancía, que es justo el número
         * que cambia solo mientras alguien mira la pantalla.
         */
        router.reload({ only: ['pedidos', 'conteos', 'parciales_pendientes'], preserveScroll: true });
    }, 30000);
});
onBeforeUnmount(() => intervalId && clearInterval(intervalId));

const tabs = [
    { key: '', label: 'Todos' },
    { key: 'enviado', label: 'Nuevos' },
    { key: 'retenido', label: '⏸ Retenidos' },
    { key: 'aprobado', label: 'Aprobados' },
    { key: 'facturado', label: 'Facturados' },
    { key: 'despachado', label: 'Despachados' },
    { key: 'rechazado', label: 'Rechazados' },
];
</script>

<template>
    <Head title="Pedidos B2B"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Package class="h-6 w-6 text-brand-600"/>
                        Pedidos B2B recibidos
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">Pedidos enviados por clientes desde el Portal.</p>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <!-- LOG-J3 · banner ámbar prominente para que Gerencia no se pierda los retenidos.
                         Al clic filtra el listado para resolverlos. -->
                    <button v-if="Number(conteos.retenido) > 0" @click="filtrar('retenido')"
                        class="px-3 py-2 rounded-lg bg-amber-500/20 text-amber-900 dark:text-amber-200 text-sm font-bold ring-2 ring-amber-400 hover:bg-amber-500/30 transition">
                        ⏸ {{ Number(conteos.retenido) }} retenido{{ Number(conteos.retenido) > 1 ? 's' : '' }} · requiere Gerencia
                    </button>
                    <div v-if="Number(conteos.enviado) > 0" class="px-3 py-2 rounded-lg bg-blue-500/15 text-blue-800 dark:text-blue-300 text-sm font-semibold">
                        {{ Number(conteos.enviado) }} nuevo{{ Number(conteos.enviado) > 1 ? 's' : '' }} por revisar
                    </div>
                    <!--
                        Los que deben mercancía. Es una deuda abierta con el
                        cliente y hasta hoy no se veía desde ninguna pantalla:
                        el pedido vuelve a la cola de alistamiento y ahí se
                        mezcla con los que nunca han salido.
                    -->
                    <button v-if="parciales_pendientes > 0" @click="verPendientes"
                        :class="['px-3 py-2 rounded-lg text-sm font-bold transition ring-2',
                            solo_pendientes
                                ? 'bg-amber-500 text-white ring-amber-600'
                                : 'bg-amber-500/15 text-amber-900 dark:text-amber-200 ring-amber-300 hover:bg-amber-500/25']">
                        ⏳ {{ parciales_pendientes }} salió a medias · falta entregar
                    </button>
                </div>
            </div>

            <div class="card p-3 flex items-center gap-2 flex-wrap">
                <button v-for="t in tabs" :key="t.key" @click="filtrar(t.key)"
                    :class="['px-3 py-1.5 text-sm rounded-lg',
                        (estado_filtro || '') === t.key ? 'bg-brand-600 text-white' : 'bg-surface-100 dark:bg-surface-800 hover:bg-surface-200']">
                    {{ t.label }}
                    <span v-if="Number(conteos[t.key])" class="ml-1 text-xs opacity-80">({{ Number(conteos[t.key]) }})</span>
                </button>
            </div>

            <div v-if="!pedidos.data.length" class="card p-12 text-center text-surface-500">
                Sin pedidos {{ estado_filtro ? 'en ese estado' : '' }}.
            </div>
            <div v-else :class="['card overflow-x-auto transition-opacity', cargando ? 'opacity-50' : '']">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b border-surface-200 dark:border-surface-800">
                        <tr>
                            <th class="text-left p-3">Número</th>
                            <th class="text-left p-3">Cliente</th>
                            <th class="text-left p-3">Lista</th>
                            <th class="text-left p-3">Fecha</th>
                            <th class="text-right p-3">Total</th>
                            <th class="text-center p-3">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                        <tr v-for="p in pedidos.data" :key="p.id" class="hover:bg-surface-50 dark:hover:bg-surface-800">
                            <td class="p-3 font-bold">
                                <Link :href="`/app/pedidos-b2b/${p.id}`" class="text-brand-600 hover:underline">{{ p.numero }}</Link>
                            </td>
                            <td class="p-3">
                                <div class="font-medium">{{ p.cliente }}</div>
                                <div class="text-xs text-surface-500">{{ p.email }}</div>
                            </td>
                            <td class="p-3 text-xs">{{ p.lista || '—' }}</td>
                            <td class="p-3 text-xs">{{ p.fecha }}</td>
                            <td class="p-3 text-right font-bold">{{ money(p.total) }}</td>
                            <td class="p-3 text-center">
                                <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badgeEstado(p.estado)]">{{ p.estado }}</span>
                                <!--
                                    El estado NO distingue un pedido que salió a
                                    medias de uno que no ha salido: los dos dicen
                                    «facturado», y es a propósito —el parcial
                                    vuelve a la cola—. Así que la diferencia va
                                    en una marca aparte, con los números, que es
                                    lo que de verdad responde «¿qué le falta?».
                                -->
                                <span v-if="p.resto_pendiente"
                                      class="ml-1 inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 ring-1 ring-amber-300 dark:bg-amber-950 dark:text-amber-200"
                                      :title="`Salieron ${p.unidades_despachadas} de ${p.unidades_pedidas} unidades`">
                                    salió a medias · {{ p.unidades_despachadas }}/{{ p.unidades_pedidas }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
