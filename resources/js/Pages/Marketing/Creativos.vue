<script setup>
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Images, Search, ArrowLeft } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import CreativosGaleria from '@/Components/Marketing/CreativosGaleria.vue';

/**
 * MKT-2 · La nube de creativos. Marketing sube y decide quién ve qué;
 * vendedores (mayorista) y dropship ven lo compartido y bajan lo que tiene
 * permiso de descarga.
 */
const props = defineProps({
    creativos: { type: Object, required: true },
    filtros: { type: Object, required: true },
    gestiona: { type: Boolean, default: false },
    post: { type: Object, default: null },
    producto: { type: Object, default: null },
    productos: { type: Array, default: () => [] },
    contenedores: { type: Array, default: () => [] },
    max_kb: { type: Number, required: true },
});

const f = reactive({
    q: props.filtros.q || '',
    tipo: props.filtros.tipo || '',
    contenedor: props.filtros.contenedor || '',
});

let espera = null;
watch(f, () => {
    clearTimeout(espera);
    espera = setTimeout(() => router.get('/app/marketing/creativos', {
        ...f,
        producto: props.filtros.producto || undefined,
        post: props.filtros.post || undefined,
    }, { preserveState: true, replace: true }), 350);
});

const fijos = props.post ? { post_id: props.post.id } : (props.producto ? { producto_id: props.producto.id } : {});
</script>

<template>
    <Head title="Creativos de marketing"/>
    <AppLayout>
        <div class="space-y-4">
            <Link v-if="post" href="/app/marketing/parrilla" class="btn-ghost inline-flex items-center gap-1 text-sm"><ArrowLeft class="h-4 w-4"/> Volver a la parrilla</Link>
            <Link v-else-if="producto && gestiona" :href="`/app/marketing/producto/${producto.id}/ficha`" class="btn-ghost inline-flex items-center gap-1 text-sm"><ArrowLeft class="h-4 w-4"/> Volver a la ficha</Link>

            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2"><Images class="h-6 w-6 text-brand-600"/>Creativos</h1>
                <p class="text-sm text-surface-500 mt-1">
                    <template v-if="post">Material del post «{{ post.titulo }}». Al entregarlo en la parrilla, lo interno pasa a «solo ver» para mayorista y dropship.</template>
                    <template v-else-if="producto">{{ producto.nombre }} · {{ producto.referencia }}</template>
                    <template v-else-if="gestiona">Fotos, videos y reels por producto y contenedor. Cada uno con su permiso: interno, solo ver o descarga.</template>
                    <template v-else>El material que Marketing comparte para vender. Lo que tiene descarga lo puede bajar para sus clientes.</template>
                </p>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">{{ $page.props.flash.success }}</div>

            <div class="flex items-center gap-2 flex-wrap">
                <div class="relative flex-1 min-w-48">
                    <Search class="h-4 w-4 absolute left-3 top-1/2 -translate-y-1/2 text-surface-400"/>
                    <input v-model="f.q" type="search" placeholder="Buscar por título o producto…" class="input w-full pl-10"/>
                </div>
                <select v-model="f.tipo" class="input">
                    <option value="">Todos los tipos</option>
                    <option value="imagen">Imágenes</option><option value="video">Videos</option>
                    <option value="reel">Reels</option><option value="otro">Otros</option>
                </select>
                <select v-if="contenedores.length" v-model="f.contenedor" class="input">
                    <option value="">Todos los contenedores</option>
                    <option v-for="c in contenedores" :key="c.id" :value="c.id">{{ c.label }}</option>
                </select>
            </div>

            <div class="card p-4">
                <CreativosGaleria :creativos="creativos.data" :gestiona="gestiona" :max-kb="max_kb"
                    :fijos="fijos" :productos="productos" :contenedores="contenedores"/>
            </div>

            <div v-if="creativos.last_page > 1" class="flex justify-center gap-1 flex-wrap">
                <template v-for="l in creativos.links" :key="l.label">
                    <Link v-if="l.url" :href="l.url" preserve-scroll class="px-3 py-1 rounded text-sm"
                        :class="l.active ? 'bg-brand-600 text-white' : 'bg-surface-100'" v-html="l.label"/>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
