<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Lightbulb, Search } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    filas: { type: Array, required: true },
    resumen: { type: Object, required: true },
    filtros: { type: Object, required: true },
});

const form = reactive({ ...props.filtros });
const num = (n) => Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 1 });
const rotaciones = {
    alta: ['Alta', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'],
    media: ['Media', 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200'],
    baja: ['Baja', 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'],
    sin_movimiento: ['Sin movimiento', 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200'],
    sin_datos: ['Sin datos', 'bg-surface-200 text-surface-700 dark:bg-surface-800 dark:text-surface-300'],
};
const queHacer = (f) => {
    if (f.sugerido > 0) return ['Reforzar', 'text-emerald-700 dark:text-emerald-400'];
    if (f.de_linea) return ['Mantener', 'text-blue-700 dark:text-blue-400'];
    if (f.rotacion === 'sin_datos') return ['—', 'text-surface-500'];
    return ['No pedir', 'text-surface-500'];
};
const cambiarLinea = (f) => router.post(`/app/compras/sugerido/${f.id}/de-linea`, { de_linea: !f.de_linea }, { preserveScroll: true });
const filtrar = () => router.get('/app/compras/sugerido', { dias: form.dias, objetivo: form.objetivo, q: form.q || null }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Sugerido de pedido · Compras"/>
    <AppLayout>
        <div class="max-w-6xl mx-auto space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2"><Lightbulb class="h-6 w-6 text-brand-600"/> Sugerido de pedido</h1>
                <p class="text-sm text-surface-500 mt-1">Lo vendido desde que llegó, lo que hay en bodega de venta y lo que ya viene en camino. Es una sugerencia: no cuenta novedades ni la decisión comercial.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Por reforzar</div><div class="text-xl font-bold mt-1">{{ resumen.por_reforzar }}</div></div>
                <div class="card p-4" :class="resumen.bajo_minimo > 0 ? 'ring-2 ring-amber-500' : ''"><div class="text-xs uppercase text-surface-500">Bajo el mínimo</div><div class="text-xl font-bold mt-1">{{ resumen.bajo_minimo }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Con stock y sin ventas</div><div class="text-xl font-bold mt-1">{{ resumen.sin_movimiento }}</div></div>
            </div>

            <form @submit.prevent="filtrar" class="card p-3 grid grid-cols-1 md:grid-cols-[1fr_11rem_11rem_auto] gap-2 items-end">
                <div>
                    <label class="text-xs font-semibold" for="sugerido-q">Buscar</label>
                    <input id="sugerido-q" v-model="form.q" placeholder="Referencia o nombre" class="input w-full"/>
                </div>
                <div>
                    <label class="text-xs font-semibold" for="sugerido-dias">Si nunca ha llegado, ventas de los últimos</label>
                    <select id="sugerido-dias" v-model.number="form.dias" class="input w-full">
                        <option v-for="d in [30, 60, 90, 180, 365]" :key="d" :value="d">{{ d }} días</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold" for="sugerido-objetivo">Stock para (días)</label>
                    <input id="sugerido-objetivo" v-model.number="form.objetivo" type="number" min="15" max="365" class="input w-full"/>
                </div>
                <button class="btn-primary text-sm"><Search class="h-4 w-4"/> Calcular</button>
            </form>

            <div class="card overflow-hidden overflow-x-auto">
                <table v-if="filas.length" v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="text-left p-2">Producto</th>
                            <th class="text-left p-2">Rotación</th>
                            <th class="text-right p-2">Vendido</th>
                            <th class="text-right p-2">Al mes</th>
                            <th class="text-right p-2">En bodega</th>
                            <th class="text-right p-2">En camino</th>
                            <th class="text-right p-2">Alcanza para</th>
                            <th class="text-right p-2">Sugerido</th>
                            <th class="text-left p-2">Qué hacer</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                        <tr v-for="f in filas" :key="f.id">
                            <td class="p-2">
                                <a :href="`/app/catalogo/productos/${f.id}`" class="font-mono text-xs font-bold text-brand-600 hover:underline">{{ f.referencia }}</a>
                                <span class="block text-xs text-surface-500">{{ f.nombre }}</span>
                                <label class="inline-flex items-center gap-1 text-xs text-surface-500 mt-1 cursor-pointer">
                                    <input type="checkbox" :checked="f.de_linea" @change="cambiarLinea(f)" :aria-label="`${f.referencia} es de línea`"/> De línea
                                </label>
                            </td>
                            <td class="p-2 text-xs"><span class="inline-block px-2 py-0.5 rounded font-semibold whitespace-nowrap" :class="rotaciones[f.rotacion][1]">{{ rotaciones[f.rotacion][0] }}</span></td>
                            <td class="p-2 text-right">
                                {{ num(f.vendido) }}
                                <span v-if="f.llego_en" class="block text-xs text-surface-500 whitespace-nowrap">en {{ f.dias_medidos }} días</span>
                            </td>
                            <td class="p-2 text-right">{{ num(f.por_mes) }}</td>
                            <td class="p-2 text-right" :class="f.bajo_minimo ? 'text-red-600 font-semibold' : ''">
                                {{ num(f.stock) }}
                                <span v-if="f.bajo_minimo" class="block text-xs">Mínimo {{ num(f.minimo) }}</span>
                            </td>
                            <td class="p-2 text-right">{{ num(f.por_llegar) }}</td>
                            <td class="p-2 text-right whitespace-nowrap">{{ f.cobertura_dias === null ? '—' : `${f.cobertura_dias} días` }}</td>
                            <td class="p-2 text-right whitespace-nowrap font-bold">
                                {{ f.sugerido > 0 ? num(f.sugerido) : '—' }}
                                <span v-if="f.sugerido > 0 && f.cajas" class="block text-xs font-normal text-surface-500">{{ f.cajas }} cajas</span>
                            </td>
                            <td class="p-2 text-xs font-semibold whitespace-nowrap" :class="queHacer(f)[1]">{{ queHacer(f)[0] }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="p-8 text-center text-surface-500 text-sm">Sin productos con ventas, stock ni pedidos todavía.</p>
            </div>
        </div>
    </AppLayout>
</template>
