<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Search, Filter, ShoppingBag } from 'lucide-vue-next';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    productos: { type: Array, required: true },
    meta: { type: Object, required: true },
    filtros: { type: Object, required: true },
    lista_nombre: { type: String, default: null },
    marcas: { type: Array, required: true },
});

const q = ref(props.filtros.q || '');
const marcaId = ref(props.filtros.marca_id || '');

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);

let deb;
const buscar = () => {
    clearTimeout(deb);
    deb = setTimeout(() => {
        router.get('/portal/catalogo',
            { q: q.value, marca_id: marcaId.value || null },
            { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
};

const rangoPrecio = (p) => {
    if (!p.precio_desde) return null;
    if (p.precio_desde === p.precio_hasta) return money(p.precio_desde);
    return `${money(p.precio_desde)} – ${money(p.precio_hasta)}`;
};
</script>

<template>
    <Head title="Catálogo · Portal"/>
    <PortalLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <ShoppingBag class="h-6 w-6 text-brand-600"/>
                        Catálogo
                    </h1>
                    <p v-if="lista_nombre" class="text-xs text-surface-500 mt-1">
                        Precios según tu lista: <b>{{ lista_nombre }}</b>
                    </p>
                    <p v-else class="text-xs text-amber-600 mt-1">
                        Aún no tienes lista de precios asignada. Contacta al comercial para verla.
                    </p>
                </div>
                <div class="text-sm text-surface-500">{{ meta.total }} productos</div>
            </div>

            <!-- Filtros -->
            <div class="card p-3 flex items-center gap-2 flex-wrap">
                <div class="relative flex-1 min-w-[200px]">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-500"/>
                    <input v-model="q" @input="buscar" placeholder="Buscar por referencia o nombre…" class="input w-full pl-10"/>
                </div>
                <select v-model="marcaId" @change="buscar" class="input">
                    <option value="">Todas las marcas</option>
                    <option v-for="m in marcas" :key="m.id" :value="m.id">{{ m.nombre }}</option>
                </select>
            </div>

            <!-- Grid productos -->
            <div v-if="!productos.length" class="text-center py-16 text-surface-500 text-sm">
                No hay productos con esos criterios.
            </div>
            <div v-else class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                <Link v-for="p in productos" :key="p.id"
                    :href="`/portal/producto/${p.id}`"
                    class="card p-4 hover:shadow-lg hover:-translate-y-0.5 transition-all flex flex-col">
                    <!-- Fix D2 · imagen del producto (backend provee data:image
                         SVG estable por referencia si no hay foto real) -->
                    <div class="aspect-square rounded-lg mb-3 overflow-hidden bg-surface-100 dark:bg-surface-800 flex items-center justify-center">
                        <img v-if="p.imagen" :src="p.imagen" :alt="p.nombre" class="w-full h-full object-cover"/>
                        <ShoppingBag v-else class="h-12 w-12 text-surface-400"/>
                    </div>
                    <div class="text-[10px] font-mono text-surface-500 flex items-center gap-1">
                        {{ p.referencia }}
                        <!-- C-F-QA4 · badge visible para productos agregados (colores surtidos) -->
                        <span v-if="p.es_agregado" class="px-1 py-px rounded text-[9px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">SURTIDO</span>
                    </div>
                    <div class="font-semibold text-sm line-clamp-2 mt-0.5">{{ p.nombre }}</div>
                    <div class="text-xs text-surface-500 mt-1">
                        <span v-if="p.es_agregado">Colores surtidos</span>
                        <span v-else>{{ p.variantes_count }} variantes</span>
                    </div>
                    <!--
                        Cuánto hay. El catálogo no mostraba stock de ninguna
                        clase: el cliente pedía lo que quisiera y el problema
                        salía el día del alistamiento.
                    -->
                    <div class="text-xs mt-1 font-semibold" :class="[
                        p.disponible === 0 ? 'text-red-600'
                        : p.disponible <= 10 ? 'text-amber-600' : 'text-emerald-600',
                    ]">
                        <template v-if="p.disponible === 0">Agotado</template>
                        <template v-else-if="p.disponible <= 10">Quedan {{ p.disponible }}</template>
                        <template v-else>{{ p.disponible }} disponibles</template>
                    </div>
                    <div class="mt-auto pt-2">
                        <div v-if="rangoPrecio(p)" class="font-bold text-brand-600">{{ rangoPrecio(p) }}</div>
                        <a v-else :href="`https://wa.me/573001234567?text=${encodeURIComponent('Hola GREAT BABY, quisiera cotizar el producto ' + p.referencia + ' - ' + p.nombre)}`"
                            target="_blank" rel="noopener"
                            @click.stop
                            class="text-xs text-emerald-600 hover:text-emerald-700 font-semibold flex items-center gap-1">
                            📱 Cotizar por WhatsApp
                        </a>
                    </div>
                </Link>
            </div>

            <!-- Paginación simple -->
            <div v-if="meta.last_page > 1" class="flex items-center justify-center gap-2 pt-4">
                <button v-for="n in meta.last_page" :key="n"
                    @click="router.get('/portal/catalogo', { q, marca_id: marcaId, page: n }, { preserveState: true })"
                    :class="['px-3 py-1 rounded text-sm', n === meta.current_page ? 'bg-brand-600 text-white' : 'bg-surface-100 hover:bg-surface-200 dark:bg-surface-800']">
                    {{ n }}
                </button>
            </div>
        </div>
    </PortalLayout>
</template>
