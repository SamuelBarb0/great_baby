<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Star, Plus, Trash2, Save, Eye, EyeOff, Images } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import CreativosGaleria from '@/Components/Marketing/CreativosGaleria.vue';
import SiguientePaso from '@/Pages/Compras/SiguientePaso.vue';

const props = defineProps({
    producto: { type: Object, required: true },
    etapa: { type: Object, required: true },
    paso: { type: Object, required: true },
    etapas: { type: Object, required: true },
    creativos: { type: Array, default: () => [] },
    max_kb: { type: Number, required: true },
});

const form = reactive({
    copy_comercial: props.producto.copy_comercial || '',
    specs: (Array.isArray(props.producto.specs) ? props.producto.specs : []).length ? props.producto.specs : [{ k: '', v: '' }],
    keywords_seo: props.producto.keywords_seo || '',
    beneficios: props.producto.beneficios || '',
    publico_objetivo: props.producto.publico_objetivo || '',
    listo_publicar: props.producto.listo_publicar,
    piezas_video: props.producto.piezas_video,
    piezas_reel: props.producto.piezas_reel,
    piezas_imagenes: props.producto.piezas_imagenes,
});
const procesando = ref(false);

// «Lo que sigue» lleva a la parte de la ficha donde se hace.
const textosAbiertos = ref(false);
const estaPublicado = computed(() => props.producto.listo_publicar && props.producto.publicado);
const llevarA = (parte) => {
    if (parte === 'textos') textosAbiertos.value = true;
    nextTick(() => document.getElementById(parte)?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
};
onMounted(() => {
    const parte = new URLSearchParams(window.location.search).get('ir');
    if (parte) llevarA(parte);
});

// Las piezas se guardan solas, como antes en el tablero.
const guardarPiezas = () => router.put(`/app/marketing/producto/${props.producto.id}/piezas`, {
    piezas_video: !!form.piezas_video, piezas_reel: !!form.piezas_reel, piezas_imagenes: Number(form.piezas_imagenes) || 0,
}, { preserveScroll: true });

// Lo que se suba cuenta como la pieza que falta.
const tipoQueFalta = computed(() => ['video', 'reel', 'imagen'].find((t) => props.etapa.piezas.faltan[t] > 0) || 'imagen');

const addSpec = () => form.specs.push({ k: '', v: '' });
const rmSpec = (i) => form.specs.splice(i, 1);

const guardar = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post(`/app/marketing/producto/${props.producto.id}/ficha`, {
        _method: 'put',
        ...form,
        specs: form.specs.filter(s => s.k && s.v),
        piezas_imagenes: Number(form.piezas_imagenes) || 0,
    }, { preserveScroll: true, onFinish: () => procesando.value = false });
};

// MKT-1 · El botón de activar o desactivar para Ventas va aparte de «Guardar»:
// es una decisión que se toma sola y queda registrada con quién y cuándo.
const cambiando = ref(false);
const publicar = (valor) => {
    if (cambiando.value) return;
    cambiando.value = true;
    router.post(`/app/marketing/producto/${props.producto.id}/publicar`, { publicado: valor, listo: valor },
        { preserveScroll: true, onFinish: () => cambiando.value = false });
};

const hacer = (accion) => (accion === 'publicar' ? publicar(true) : llevarA(accion));

const faltaParaListo = computed(() => {
    const f = [];
    if (!form.copy_comercial.trim()) f.push('copy comercial');
    if (!form.publico_objetivo.trim()) f.push('público objetivo');
    if (props.etapa.piezas?.completas === false) f.push('piezas de contenido');
    return f;
});
</script>

<template>
    <Head :title="`Ficha marketing · ${producto.nombre}`"/>
    <AppLayout>
        <div class="max-w-4xl mx-auto space-y-4">
            <Link href="/app/marketing/tablero" class="btn-ghost inline-flex items-center gap-1 text-sm"><ArrowLeft class="h-4 w-4"/> Volver a Productos</Link>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">{{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 text-sm">{{ $page.props.flash.error }}</div>

            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2"><Star class="h-6 w-6 text-brand-600"/>{{ producto.nombre }}</h1>
                <div class="text-sm text-surface-500 mt-1">{{ producto.referencia }}</div>
            </div>

            <SiguientePaso :paso="paso" @accion="hacer"/>

            <!-- 1. Qué lleva -->
            <section id="piezas" class="card p-4">
                <h2 class="font-semibold">1. Qué piezas lleva</h2>
                <div class="flex items-center gap-5 mt-2 flex-wrap text-sm">
                    <label class="inline-flex items-center gap-2"><input type="checkbox" v-model="form.piezas_video" @change="guardarPiezas"/> Video</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" v-model="form.piezas_reel" @change="guardarPiezas"/> Reel</label>
                    <label class="inline-flex items-center gap-2">
                        <input type="number" min="0" max="50" v-model="form.piezas_imagenes" @change="guardarPiezas" class="input w-20"/> imágenes
                    </label>
                </div>
                <p class="text-xs text-surface-500 mt-2">Subidas: {{ etapa.piezas.hechas.video }} video · {{ etapa.piezas.hechas.reel }} reel · {{ etapa.piezas.hechas.imagen }} imágenes. Se guarda solo.</p>
            </section>

            <!-- 2. Subirlas -->
            <section id="creativos" class="card p-4 space-y-3">
                <div class="flex items-center justify-between gap-2 flex-wrap">
                    <h2 class="font-semibold flex items-center gap-2"><Images class="h-4 w-4 text-brand-600"/> 2. Subir las piezas</h2>
                    <Link :href="`/app/marketing/creativos?producto=${producto.id}`" class="text-sm text-brand-600 hover:underline">Ver en Creativos</Link>
                </div>
                <CreativosGaleria :creativos="creativos" :gestiona="true" :max-kb="max_kb"
                    :fijos="{ producto_id: producto.id }" :tipo-inicial="tipoQueFalta"/>
            </section>

            <!-- 3. Textos -->
            <details id="textos" class="card p-4" :open="textosAbiertos">
                <summary class="cursor-pointer font-semibold">3. Textos para Ventas</summary>
                <div class="space-y-4 mt-3">
                    <div>
                        <label class="text-xs font-semibold">A quién va dirigido</label>
                        <input v-model="form.publico_objetivo" maxlength="300" class="input w-full" placeholder="Mamás primerizas, bebés de 0 a 6 meses, regalo de baby shower…"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Copy comercial (para redes y tienda)</label>
                        <textarea v-model="form.copy_comercial" rows="4" class="input w-full" placeholder="Ideal para bebés de 3 a 12 meses..."></textarea>
                    </div>
                    <details class="text-sm">
                        <summary class="cursor-pointer text-xs font-semibold text-surface-600 dark:text-surface-300">Más campos (opcionales)</summary>
                        <div class="space-y-4 mt-3">
                            <div>
                                <label class="text-xs font-semibold">Beneficios (uno por línea)</label>
                                <textarea v-model="form.beneficios" rows="4" class="input w-full" placeholder="• 100% algodón hipoalergénico&#10;• Cierre con broches de presión&#10;• Certificación OEKO-TEX"></textarea>
                            </div>
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <label class="text-xs font-semibold">Especificaciones técnicas</label>
                                    <button @click="addSpec" class="btn-ghost text-xs"><Plus class="h-3 w-3"/> Agregar</button>
                                </div>
                                <div v-for="(s, i) in form.specs" :key="i" class="flex items-center gap-2 mb-2">
                                    <input v-model="s.k" placeholder="Ej: Material" class="input w-40"/>
                                    <input v-model="s.v" placeholder="Ej: Algodón 100%" class="input flex-1"/>
                                    <button v-if="form.specs.length > 1" @click="rmSpec(i)" class="text-red-600" :aria-label="`Quitar ${s.k || 'especificación'}`"><Trash2 class="h-4 w-4"/></button>
                                </div>
                            </div>
                            <div>
                                <label class="text-xs font-semibold">Palabras clave SEO (separadas por coma)</label>
                                <input v-model="form.keywords_seo" class="input w-full" placeholder="ropa bebe, body algodon, ropa recien nacido"/>
                            </div>
                            <div>
                                <div class="text-xs font-semibold">Descripción actual del catálogo</div>
                                <p class="text-sm text-surface-500 mt-1">{{ producto.descripcion || '(sin descripción)' }}</p>
                            </div>
                        </div>
                    </details>
                    <div class="flex justify-end">
                        <button @click="guardar" :disabled="procesando" class="btn-primary">
                            <Save class="h-4 w-4"/> {{ procesando ? 'Guardando…' : 'Guardar textos' }}
                        </button>
                    </div>
                </div>
            </details>

            <!-- 4. Publicar -->
            <section id="publicar" class="card p-4 flex items-center justify-between gap-4 flex-wrap border-l-4"
                :class="estaPublicado ? 'border-emerald-500' : 'border-amber-500'">
                <div class="min-w-0">
                    <h2 class="font-semibold flex items-center gap-2">
                        <component :is="estaPublicado ? Eye : EyeOff" class="h-4 w-4"/>
                        4. {{ estaPublicado ? 'Publicado' : (producto.publicado ? 'Sin publicar, pero ya visible para Ventas' : 'Sin publicar') }}
                    </h2>
                    <p class="text-xs text-surface-500 mt-1">
                        <template v-if="producto.publicado">Los vendedores lo pueden pedir y los clientes lo ven en el portal.</template>
                        <template v-else>No sale en el pedido del vendedor ni en el portal de clientes.</template>
                        <span v-if="producto.publicado_cambiado"> · Último cambio: {{ producto.publicado_cambiado }}</span>
                    </p>
                    <p v-if="!estaPublicado && faltaParaListo.length" class="text-xs text-amber-700 mt-1">Todavía falta: {{ faltaParaListo.join(', ') }}.</p>
                    <p v-if="!producto.activo" class="text-xs text-red-600 mt-1">El producto está inactivo en el catálogo: no se puede publicar.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button v-if="!estaPublicado" @click="publicar(true)" :disabled="cambiando || !producto.activo" class="btn-primary">
                        <Eye class="h-4 w-4"/> Publicar
                    </button>
                    <button v-if="producto.publicado" @click="publicar(false)" :disabled="cambiando" class="btn-ghost border border-surface-300">
                        <EyeOff class="h-4 w-4"/> Ocultar para Ventas
                    </button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
