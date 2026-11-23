<script setup>
/**
 * Lo que el cliente devolvió, y lo que falta revisar.
 *
 * Abre por «por revisar» y no por lo más reciente, y dentro ordena lo más
 * VIEJO primero: el área puso un plazo —inspección a más tardar al día
 * siguiente— y lo que lleva más días esperando es justo lo que lo incumple. Si
 * se cayera al final de la lista, nadie lo revisaría.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { Package, Plus, AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    devoluciones: { type: Array, required: true },
    conteos: { type: Object, required: true },
    filtro: { type: String, default: 'por_revisar' },
    dias_de_plazo: { type: Number, default: 1 },
});

const filtrar = (estado) => router.get('/app/logistica/devoluciones-cliente', { estado },
    { preserveScroll: true, preserveState: true });

const TABS = [
    { key: 'por_revisar', label: 'Por revisar' },
    { key: 'borrador', label: 'Borradores' },
    { key: 'revisadas', label: 'Revisadas' },
];

// Vencida es la que pasó el plazo del área, no la que lleva «muchos» días: el
// número lo manda el servidor para no tenerlo escrito en dos sitios.
const vencida = (d) => d.dias_esperando !== null && d.dias_esperando > props.dias_de_plazo;
</script>

<template>
    <Head title="Devoluciones de cliente"/>
    <AppLayout>
        <div class="p-4 md:p-6 space-y-4">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-xl font-bold flex items-center gap-2">
                        <Package class="h-5 w-5 text-brand-600"/> Devoluciones de cliente
                    </h1>
                    <p class="text-sm text-surface-500">
                        Lo devuelto entra a <b>Devoluciones y garantías de clientes</b> y queda por revisar.
                        La inspección va a más tardar al día siguiente; después se traslada al almacén que corresponda.
                    </p>
                </div>
                <Link href="/app/logistica/devoluciones-cliente/nueva" class="btn-primary">
                    <Plus class="h-4 w-4"/> Registrar devolución
                </Link>
            </div>

            <div class="flex flex-wrap gap-2">
                <button v-for="t in TABS" :key="t.key" @click="filtrar(t.key)"
                    :class="['px-3 py-2 rounded-lg text-sm border',
                        filtro === t.key ? 'bg-brand-600 text-white border-brand-600'
                        : 'border-surface-200 dark:border-surface-700']">
                    {{ t.label }}
                    <span v-if="conteos[t.key]" class="ml-1 opacity-80">{{ conteos[t.key] }}</span>
                </button>
            </div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs uppercase text-surface-500 border-b border-surface-200 dark:border-surface-800">
                        <tr>
                            <th class="text-left p-3">N.º</th>
                            <th class="text-left p-3">Cliente</th>
                            <th class="text-left p-3">Motivo</th>
                            <th class="text-center p-3">Líneas</th>
                            <th class="text-left p-3">Bodega</th>
                            <th class="text-left p-3">Estado</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in devoluciones" :key="d.id"
                            :class="['border-b border-surface-100 dark:border-surface-800/60',
                                vencida(d) ? 'bg-red-50/60 dark:bg-red-950/20' : '']">
                            <td class="p-3 font-mono text-xs">
                                <Link :href="`/app/logistica/devoluciones-cliente/${d.id}`"
                                      class="text-brand-600 hover:underline">{{ d.numero }}</Link>
                                <div class="text-surface-500">{{ d.fecha }}</div>
                            </td>
                            <td class="p-3">{{ d.cliente }}</td>
                            <td class="p-3 text-xs text-surface-600 dark:text-surface-400">{{ d.motivo }}</td>
                            <td class="p-3 text-center font-semibold">{{ d.lineas }}</td>
                            <td class="p-3 text-xs font-mono">{{ d.bodega || '—' }}</td>
                            <td class="p-3">
                                <span v-if="d.estado === 'borrador'"
                                      class="px-2 py-0.5 rounded text-xs font-bold bg-surface-200 text-surface-700">
                                    borrador
                                </span>
                                <template v-else-if="d.revisada_at">
                                    <span class="px-2 py-0.5 rounded text-xs font-bold bg-emerald-100 text-emerald-800">
                                        revisada
                                    </span>
                                    <div class="text-[11px] text-surface-500 mt-0.5">{{ d.revisada_at }}</div>
                                </template>
                                <template v-else>
                                    <span class="px-2 py-0.5 rounded text-xs font-bold bg-amber-100 text-amber-800">
                                        por revisar
                                    </span>
                                    <!--
                                        Los días son el dato, no el adorno: el
                                        plazo del área se mide con ellos y hasta
                                        hoy no lo vigilaba nada.
                                    -->
                                    <div v-if="d.dias_esperando !== null"
                                         :class="['text-[11px] mt-0.5 flex items-center gap-1',
                                             vencida(d) ? 'text-red-700 font-bold' : 'text-surface-500']">
                                        <AlertTriangle v-if="vencida(d)" class="h-3 w-3"/>
                                        {{ d.dias_esperando === 0 ? 'entró hoy' : `${d.dias_esperando} día${d.dias_esperando > 1 ? 's' : ''} esperando` }}
                                    </div>
                                </template>
                            </td>
                            <td class="p-3 text-right">
                                <Link :href="`/app/logistica/devoluciones-cliente/${d.id}`" class="btn-ghost text-xs px-2 py-1">
                                    Abrir
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="!devoluciones.length">
                            <td colspan="7" class="p-8 text-center text-surface-500">
                                {{ filtro === 'por_revisar'
                                    ? 'Nada por revisar. Si entró algo hoy, aparece acá.'
                                    : 'Nada en esta pestaña.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
