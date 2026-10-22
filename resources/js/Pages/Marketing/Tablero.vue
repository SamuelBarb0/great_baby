<script setup>
import { reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { LayoutGrid, Search, Eye, EyeOff, Pencil, Images, Check } from 'lucide-vue-next';
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

// Piezas: se editan en la fila y se guardan al salir del campo.
const piezas = reactive(Object.fromEntries(props.productos.map(p => [p.id, { ...p.piezas.pedidas }])));
watch(() => props.productos, (ps) => ps.forEach(p => { piezas[p.id] = { ...p.piezas.pedidas }; }));
const guardando = ref(null);
const guardarPiezas = (p) => {
    const x = piezas[p.id];
    guardando.value = p.id;
    router.put(`/app/marketing/producto/${p.id}/piezas`, {
        piezas_video: !!x.video, piezas_reel: !!x.reel, piezas_imagenes: Number(x.imagen) || 0,
    }, { preserveScroll: true, preserveState: true, onFinish: () => guardando.value = null });
};

const publicar = (p, valor) => router.post(`/app/marketing/producto/${p.id}/publicar`, { publicado: valor },
    { preserveScroll: true, preserveState: true });

const fmt = (n) => n == null ? '' : Number(n).toLocaleString('es-CO', { maximumFractionDigits: 0 });
</script>

<template>
    <Head title="Tablero de marketing"/>
    <AppLayout>
        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2"><LayoutGrid class="h-6 w-6 text-brand-600"/>Tablero de marketing</h1>
                <p class="text-sm text-surface-500 mt-1">Dónde va cada producto, de la compra a la publicación. Elija un contenedor para ver lo que trae.</p>
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
                <div v-for="p in productos" :key="p.id" class="p-3 grid gap-3 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,2fr)_auto] items-start">
                    <div class="min-w-0">
                        <div class="text-xs font-mono text-surface-500">{{ p.referencia }}</div>
                        <div class="font-semibold truncate">{{ p.nombre }}</div>
                        <div class="text-xs text-surface-500 mt-0.5">
                            <span v-if="p.cantidad">{{ fmt(p.cantidad) }} und en el contenedor · </span>
                            <span :class="p.publicado ? 'text-emerald-700' : 'text-amber-700'">{{ p.publicado ? 'Visible para Ventas' : 'Oculto para Ventas' }}</span>
                            <span v-if="p.listo_publicar"> · <Check class="h-3 w-3 inline"/> listo</span>
                        </div>
                    </div>

                    <div class="min-w-0 space-y-2">
                        <EtapaProducto :etapa="p" :etapas="etapas"/>
                        <div class="flex items-center gap-3 text-xs flex-wrap">
                            <span class="text-surface-500">Piezas:</span>
                            <label class="inline-flex items-center gap-1"><input type="checkbox" v-model="piezas[p.id].video" @change="guardarPiezas(p)"/> video</label>
                            <label class="inline-flex items-center gap-1"><input type="checkbox" v-model="piezas[p.id].reel" @change="guardarPiezas(p)"/> reel</label>
                            <label class="inline-flex items-center gap-1">
                                <input type="number" min="0" max="50" v-model="piezas[p.id].imagen" @change="guardarPiezas(p)" class="input w-16 py-0.5 text-xs"/> imágenes
                            </label>
                            <span class="text-surface-400">subidas: {{ p.piezas.hechas.video }} video · {{ p.piezas.hechas.reel }} reel · {{ p.piezas.hechas.imagen }} img</span>
                            <span v-if="guardando === p.id" class="text-surface-400">guardando…</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-1 lg:justify-end flex-wrap">
                        <Link :href="`/app/marketing/producto/${p.id}/ficha`" class="btn-ghost text-xs"><Pencil class="h-3 w-3"/> Ficha</Link>
                        <Link :href="`/app/marketing/creativos?producto=${p.id}`" class="btn-ghost text-xs"><Images class="h-3 w-3"/> Creativos</Link>
                        <button v-if="p.publicado" @click="publicar(p, false)" class="btn-ghost text-xs" title="Ocultar para Ventas"><EyeOff class="h-3 w-3"/> Ocultar</button>
                        <button v-else @click="publicar(p, true)" :disabled="!p.activo" class="btn-ghost text-xs text-emerald-700" title="Activar para Ventas"><Eye class="h-3 w-3"/> Activar</button>
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
