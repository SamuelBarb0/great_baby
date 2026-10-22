<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Star, Plus, Trash2, Save, Eye, EyeOff, Images } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import CreativosGaleria from '@/Components/Marketing/CreativosGaleria.vue';
import EtapaProducto from '@/Components/Marketing/EtapaProducto.vue';

const props = defineProps({
    producto: { type: Object, required: true },
    etapa: { type: Object, required: true },
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
    router.post(`/app/marketing/producto/${props.producto.id}/publicar`, { publicado: valor },
        { preserveScroll: true, onFinish: () => cambiando.value = false });
};

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
            <Link href="/app/marketing/tablero" class="btn-ghost inline-flex items-center gap-1 text-sm"><ArrowLeft class="h-4 w-4"/> Volver al tablero</Link>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">{{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 text-sm">{{ $page.props.flash.error }}</div>

            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2"><Star class="h-6 w-6 text-brand-600"/>Ficha marketing</h1>
                    <div class="text-sm text-surface-500 mt-1">{{ producto.referencia }} · <b>{{ producto.nombre }}</b></div>
                </div>
                <button @click="guardar" :disabled="procesando" class="btn-primary">
                    <Save class="h-4 w-4"/> {{ procesando ? 'Guardando…' : 'Guardar ficha' }}
                </button>
            </div>

            <!-- Visibilidad para Ventas -->
            <div class="card p-4 flex items-center justify-between gap-4 flex-wrap"
                :class="producto.publicado ? 'border-l-4 border-emerald-500' : 'border-l-4 border-amber-500'">
                <div class="min-w-0">
                    <div class="font-semibold flex items-center gap-2">
                        <component :is="producto.publicado ? Eye : EyeOff" class="h-4 w-4"/>
                        {{ producto.publicado ? 'Visible para Ventas' : 'Oculto para Ventas' }}
                    </div>
                    <p class="text-xs text-surface-500 mt-1">
                        <template v-if="producto.publicado">Los vendedores lo pueden pedir y los clientes lo ven en el portal.</template>
                        <template v-else>No sale en el pedido del vendedor ni en el portal de clientes. Aquí y en el catálogo interno sí se ve.</template>
                        <span v-if="producto.publicado_cambiado"> · Último cambio: {{ producto.publicado_cambiado }}</span>
                    </p>
                    <p v-if="!producto.activo" class="text-xs text-red-600 mt-1">El producto está inactivo en el catálogo: no se puede publicar.</p>
                </div>
                <button v-if="producto.publicado" @click="publicar(false)" :disabled="cambiando" class="btn-ghost border border-surface-300">
                    <EyeOff class="h-4 w-4"/> Ocultar para Ventas
                </button>
                <button v-else @click="publicar(true)" :disabled="cambiando || !producto.activo" class="btn-primary">
                    <Eye class="h-4 w-4"/> Activar para Ventas
                </button>
            </div>

            <div class="card p-4">
                <EtapaProducto :etapa="etapa" :etapas="etapas"/>
            </div>

            <div class="card p-4">
                <label class="text-xs font-semibold text-brand-600 uppercase tracking-widest">Descripción actual del catálogo</label>
                <p class="text-sm text-surface-500 mt-1">{{ producto.descripcion || '(sin descripción)' }}</p>
            </div>

            <div class="card p-4">
                <label class="text-xs font-semibold">Público objetivo</label>
                <input v-model="form.publico_objetivo" maxlength="300" class="input w-full" placeholder="Mamás primerizas, bebés de 0 a 6 meses, regalo de baby shower…"/>
            </div>

            <div class="card p-4">
                <label class="text-xs font-semibold">Copy comercial (para redes/tienda)</label>
                <textarea v-model="form.copy_comercial" rows="4" class="input w-full" placeholder="Ideal para bebés de 3 a 12 meses..."></textarea>
            </div>

            <div class="card p-4">
                <label class="text-xs font-semibold">Beneficios (USPs, uno por línea)</label>
                <textarea v-model="form.beneficios" rows="4" class="input w-full" placeholder="• 100% algodón hipoalergénico&#10;• Cierre con broches de presión&#10;• Certificación OEKO-TEX"></textarea>
            </div>

            <div class="card p-4">
                <div class="flex items-center justify-between mb-2">
                    <label class="text-xs font-semibold">Especificaciones técnicas</label>
                    <button @click="addSpec" class="btn-ghost text-xs"><Plus class="h-3 w-3"/> Agregar</button>
                </div>
                <div v-for="(s, i) in form.specs" :key="i" class="flex items-center gap-2 mb-2">
                    <input v-model="s.k" placeholder="Ej: Material" class="input w-40"/>
                    <input v-model="s.v" placeholder="Ej: Algodón 100%" class="input flex-1"/>
                    <button v-if="form.specs.length > 1" @click="rmSpec(i)" class="text-red-600"><Trash2 class="h-4 w-4"/></button>
                </div>
            </div>

            <div class="card p-4">
                <label class="text-xs font-semibold">Keywords SEO (separadas por coma)</label>
                <input v-model="form.keywords_seo" class="input w-full" placeholder="ropa bebe, body algodon, ropa recien nacido"/>
            </div>

            <!-- Piezas de contenido (MKT-4) -->
            <div class="card p-4">
                <label class="text-xs font-semibold">Piezas que lleva</label>
                <div class="flex items-center gap-5 mt-2 flex-wrap text-sm">
                    <label class="inline-flex items-center gap-2"><input type="checkbox" v-model="form.piezas_video"/> Video</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" v-model="form.piezas_reel"/> Reel</label>
                    <label class="inline-flex items-center gap-2">
                        <input type="number" min="0" max="50" v-model="form.piezas_imagenes" class="input w-20"/> imágenes
                    </label>
                </div>
            </div>

            <!-- Listo para publicar (MKT-1) -->
            <div class="card p-4">
                <label class="inline-flex items-center gap-2 font-semibold">
                    <input type="checkbox" v-model="form.listo_publicar"/> Listo para publicar
                </label>
                <p class="text-xs text-surface-500 mt-1">
                    Marca que la ficha y el contenido están terminados. Para que Ventas lo vea, además tiene que estar activo arriba.
                </p>
                <p v-if="form.listo_publicar && faltaParaListo.length" class="text-xs text-amber-700 mt-1">
                    Falta: {{ faltaParaListo.join(', ') }}.
                </p>
            </div>

            <div class="flex justify-end">
                <button @click="guardar" :disabled="procesando" class="btn-primary">
                    <Save class="h-4 w-4"/> {{ procesando ? 'Guardando…' : 'Guardar ficha' }}
                </button>
            </div>

            <!-- Creativos (MKT-2) -->
            <div class="card p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold flex items-center gap-2"><Images class="h-4 w-4 text-brand-600"/> Creativos del producto</h2>
                    <Link :href="`/app/marketing/creativos?producto=${producto.id}`" class="text-sm text-brand-600 hover:underline">Ver en la nube de creativos</Link>
                </div>
                <CreativosGaleria :creativos="creativos" :gestiona="true" :max-kb="max_kb"
                    :fijos="{ producto_id: producto.id }"/>
            </div>
        </div>
    </AppLayout>
</template>
