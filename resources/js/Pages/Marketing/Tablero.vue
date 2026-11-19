<script setup>
import { reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { LayoutGrid, Search, Check } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import EtapaProducto from '@/Components/Marketing/EtapaProducto.vue';

/**
 * MKT-4 · En qué etapa va cada producto (compra → contenedor → bodega →
 * contenido → publicado), los productos de un contenedor y las piezas que
 * lleva cada uno.
 */
const props = defineProps({
    productos: { type: Array, required: true },
    total: Number,
    pagina: Number,
    paginas: Number,
    conteo: { type: Object, required: true },
    etapas: { type: Object, required: true },
    filtros: { type: Object, required: true },
    contenedores: { type: Array, required: true },
});

const f = reactive({
    q: props.filtros.q || '',
    etapa: props.filtros.etapa || '',
    contenedor: props.filtros.contenedor || '',
});

let espera = null;
const ir = (pagina = 1) => router.get('/app/marketing/tablero', { ...f, page: pagina > 1 ? pagina : undefined },
    { preserveState: true, preserveScroll: true, replace: true });
watch(f, () => { clearTimeout(espera); espera = setTimeout(() => ir(1), 350); });

// Lo que sigue con cada producto. «Publicar» se hace aquí; lo demás, en su ficha.
const publicando = ref(null);
const publicar = (p) => {
    publicando.value = p.id;
    router.post(`/app/marketing/producto/${p.id}/publicar`, { publicado: true, listo: true },
        { preserveScroll: true, preserveState: true, onFinish: () => publicando.value = null });
};
const principal = (p) => p.paso.acciones.find((a) => a.principal) || null;

const fmt = (n) => n == null ? '' : Number(n).toLocaleString('es-CO', { maximumFractionDigits: 0 });
</script>

<template>
    <Head title="Productos"/>
    <AppLayout>
        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2"><LayoutGrid class="h-6 w-6 text-brand-600"/>Productos</h1>
                <p class="text-sm text-surface-500 mt-1">Qué le falta a cada producto para publicarse. Elija un contenedor para ver solo lo que trae.</p>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">{{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 text-sm">{{ $page.props.flash.error }}</div>

            <!-- Conteo por etapa: también filtra -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                <button v-for="(label, clave) in etapas" :key="clave" @click="f.etapa = f.etapa === clave ? '' : clave"
                    class="card p-3 text-left transition" :class="f.etapa === clave ? 'ring-2 ring-brand-600' : ''">
                    <div class="text-xs text-surface-500">{{ label }}</div>
                    <div class="text-2xl font-bold">{{ conteo[clave] || 0 }}</div>
                </button>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                <select v-model="f.contenedor" class="input min-w-64">
                    <option value="">Todos los contenedores</option>
                    <option v-for="c in contenedores" :key="c.id" :value="c.id">{{ c.label }}</option>
                </select>
                <div class="relative flex-1 min-w-48">
                    <Search class="h-4 w-4 absolute left-3 top-1/2 -translate-y-1/2 text-surface-400"/>
                    <input v-model="f.q" type="search" placeholder="Referencia o nombre…" class="input w-full pl-10"/>
                </div>
            </div>

            <div v-if="!productos.length" class="card p-10 text-center text-sm text-surface-500">
                <template v-if="f.contenedor">Este contenedor no tiene productos en sus órdenes de compra.</template>
                <template v-else-if="f.etapa || f.q">Nada con estos filtros.</template>
                <template v-else>Aún no hay productos en camino ni con contenido. Aparecen aquí desde que se aprueba su orden de compra.</template>
            </div>

            <div v-else class="card divide-y divide-surface-100 dark:divide-surface-800">
                <div v-for="p in productos" :key="p.id" class="p-3 grid gap-3 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,2fr)_auto] items-center">
                    <div class="min-w-0">
                        <div class="text-xs font-mono text-surface-500">{{ p.referencia }}</div>
                        <Link :href="`/app/marketing/producto/${p.id}/ficha`" class="font-semibold truncate block hover:underline">{{ p.nombre }}</Link>
                        <div class="text-xs text-surface-500 mt-0.5">
                            <span v-if="p.cantidad">{{ fmt(p.cantidad) }} und en el contenedor · </span>
                            <span :class="p.publicado ? 'text-emerald-700' : 'text-amber-700'">{{ p.publicado ? 'Visible para Ventas' : 'Oculto para Ventas' }}</span>
                            <span v-if="p.listo_publicar"> · <Check class="h-3 w-3 inline"/> listo</span>
                        </div>
                    </div>

                    <div class="min-w-0 space-y-2">
                        <EtapaProducto :etapa="p" :etapas="etapas" compacto/>
                        <p class="text-sm">{{ p.paso.titulo }}</p>
                        <p v-if="p.dias_desde_llegada !== null && p.etapa !== 'publicado'" class="text-xs" :class="p.dias_desde_llegada > 7 ? 'text-amber-700 font-semibold' : 'text-surface-500'">
                            {{ p.dias_desde_llegada === 0 ? 'Llegó hoy' : `Llegó hace ${p.dias_desde_llegada} ${p.dias_desde_llegada === 1 ? 'día' : 'días'}` }}
                        </p>
                    </div>

                    <div class="lg:justify-self-end">
                        <template v-if="principal(p)">
                            <button v-if="principal(p).accion === 'publicar'" @click="publicar(p)" :disabled="publicando === p.id" class="btn-primary text-sm">{{ publicando === p.id ? 'Publicando…' : 'Publicar' }}</button>
                            <Link v-else :href="`/app/marketing/producto/${p.id}/ficha?ir=${principal(p).accion}`" class="btn-primary text-sm">{{ principal(p).etiqueta }}</Link>
                        </template>
                        <Link v-else :href="`/app/marketing/producto/${p.id}/ficha`" class="btn-ghost text-sm border border-surface-300 dark:border-surface-700">Ver ficha</Link>
                    </div>
                </div>
            </div>

            <div v-if="paginas > 1" class="flex items-center justify-center gap-2 text-sm">
                <button :disabled="pagina <= 1" @click="ir(pagina - 1)" class="btn-ghost">Anterior</button>
                <span>Página {{ pagina }} de {{ paginas }} · {{ total }} productos</span>
                <button :disabled="pagina >= paginas" @click="ir(pagina + 1)" class="btn-ghost">Siguiente</button>
            </div>
        </div>
    </AppLayout>
</template>
