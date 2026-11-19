<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Calendar, Plus, ChevronLeft, ChevronRight, Clapperboard, Images } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';

const props = defineProps({
    anio: Number, mes: Number,
    diasDelMes: Number,
    porDia: { type: Object, required: true },
    total: Number,
    conteos: { type: Object, required: true },
    productos: { type: Array, required: true },
    contenedores: { type: Array, default: () => [] },
});

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

const MESES = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

const irMes = (delta) => {
    let m = props.mes + delta;
    let a = props.anio;
    if (m < 1) { m = 12; a--; }
    if (m > 12) { m = 1; a++; }
    router.get('/app/marketing/parrilla', { anio: a, mes: m });
};

const modal = ref(false);
const form = reactive({
    fecha_publicacion: '', hora_publicacion: '', titulo: '', copy: '',
    canal: 'instagram', tipo: 'post', estado: 'idea',
    producto_id: null, importacion_id: null, produccion: false, url_publicacion: '', notas: '',
});
const procesando = ref(false);
const abrir = (fecha) => {
    form.fecha_publicacion = fecha;
    modal.value = true;
};
const crear = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post('/app/marketing/parrilla', form, {
        preserveScroll: true,
        onSuccess: () => { modal.value = false; Object.assign(form, { titulo: '', copy: '', notas: '' }); },
        onFinish: () => procesando.value = false,
    });
};
// MKT-3 · Producción del contenido: grabación → edición → entrega.
const ETAPAS = { grabacion: 'Grabación', edicion: 'Edición', entregado: 'Entregado' };
const ACCION = { grabacion: 'Empezar grabación', edicion: 'Pasar a edición', entregado: 'Entregar a mayorista y dropship' };
const detalle = ref(null);
const avanzando = ref(false);
const avanzar = (p) => {
    if (avanzando.value) return;
    avanzando.value = true;
    router.post(`/app/marketing/parrilla/${p.id}/etapa`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            // El post se vuelve a leer del día para que el modal muestre la etapa nueva.
            const dia = Object.values(props.porDia).flat().find(x => x.id === p.id);
            detalle.value = dia || null;
        },
        onFinish: () => avanzando.value = false,
    });
};
const etapaBadge = (e) => ({
    grabacion: 'bg-amber-200 text-amber-900', edicion: 'bg-blue-200 text-blue-900', entregado: 'bg-emerald-200 text-emerald-900',
}[e] || '');

const eliminar = (id) => {
    modalConfirm.value = {
        titulo: '¿Eliminar el post?',
        mensaje: 'Se borra de la parrilla. No se puede deshacer.',
        color: 'rose',
        textoConfirmar: 'Eliminar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.delete(`/app/marketing/parrilla/${id}`, { preserveScroll: true });
        },
    };
};

const canalBadge = (c) => ({
    instagram: 'bg-pink-100 text-pink-800',
    facebook: 'bg-blue-100 text-blue-800',
    tiktok: 'bg-surface-800 text-white',
    whatsapp_status: 'bg-emerald-100 text-emerald-800',
    email: 'bg-amber-100 text-amber-800',
    web: 'bg-brand-100 text-brand-800',
}[c] || 'bg-surface-100');
const estadoBadge = (e) => ({
    idea: 'bg-surface-100', produccion: 'bg-amber-100 text-amber-800',
    programado: 'bg-blue-100 text-blue-800', publicado: 'bg-emerald-100 text-emerald-800',
    cancelado: 'bg-red-100 text-red-800',
}[e] || 'bg-surface-100');

const dias = computed(() => Array.from({ length: props.diasDelMes }, (_, i) => i + 1));
// Huecos antes del día 1 para que cada fecha caiga bajo su día de la semana:
// la grilla empieza en lunes y el día 1 se pintaba siempre ahí.
const huecos = computed(() => (new Date(props.anio, props.mes - 1, 1).getDay() + 6) % 7);
const diaKey = (d) => `${props.anio}-${String(props.mes).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
</script>

<template>
    <Head title="Parrilla marketing"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2"><Calendar class="h-6 w-6 text-brand-600"/>Parrilla de contenido</h1>
                    <p class="text-sm text-surface-500 mt-1">{{ total }} posts · {{ conteos.publicado || 0 }} publicados · {{ conteos.idea || 0 }} en idea</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="irMes(-1)" class="btn-ghost"><ChevronLeft class="h-4 w-4"/></button>
                    <div class="text-lg font-bold min-w-40 text-center">{{ MESES[mes-1] }} {{ anio }}</div>
                    <button @click="irMes(1)" class="btn-ghost"><ChevronRight class="h-4 w-4"/></button>
                </div>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">{{ $page.props.flash.success }}</div>

            <div class="grid grid-cols-7 gap-1">
                <div v-for="(d, i) in ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom']" :key="i"
                    class="text-xs uppercase text-surface-500 text-center py-2 font-bold">{{ d }}</div>
                <div v-for="h in huecos" :key="'h' + h" aria-hidden="true"></div>
                <div v-for="d in dias" :key="d" class="card p-2 min-h-32 flex flex-col">
                    <div class="flex items-start justify-between">
                        <div class="text-xs font-bold text-surface-700">{{ d }}</div>
                        <button @click="abrir(diaKey(d))" class="text-brand-600 hover:bg-brand-50 rounded p-0.5"><Plus class="h-3 w-3"/></button>
                    </div>
                    <div class="space-y-1 mt-1 flex-1 overflow-y-auto">
                        <div v-for="p in (porDia[diaKey(d)] || [])" :key="p.id" @click="detalle = p"
                            class="text-[10px] rounded p-1 cursor-pointer group relative"
                            :class="canalBadge(p.canal)">
                            <div class="font-semibold truncate" :title="p.titulo">{{ p.titulo }}</div>
                            <div v-if="p.etapa" class="mt-0.5"><span class="text-[9px] rounded px-1" :class="etapaBadge(p.etapa)">{{ ETAPAS[p.etapa] }}</span></div>
                            <div class="flex items-center justify-between">
                                <span class="text-[9px] opacity-75">{{ p.tipo }} · {{ p.estado }}</span>
                                <button @click.stop="eliminar(p.id)" class="opacity-0 group-hover:opacity-100 text-red-600 text-[9px]">✕</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="modal" @click.self="modal = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-bold mb-3">Nuevo post · {{ form.fecha_publicacion }}</h3>
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="text-xs font-semibold">Hora</label><input type="time" v-model="form.hora_publicacion" class="input w-full"/></div>
                        <div><label class="text-xs font-semibold">Canal</label>
                            <select v-model="form.canal" class="input w-full">
                                <option value="instagram">Instagram</option><option value="facebook">Facebook</option>
                                <option value="tiktok">TikTok</option><option value="whatsapp_status">WA Status</option>
                                <option value="email">Email</option><option value="web">Web</option><option value="otros">Otros</option>
                            </select>
                        </div>
                        <div><label class="text-xs font-semibold">Tipo</label>
                            <select v-model="form.tipo" class="input w-full">
                                <option value="post">Post</option><option value="reel">Reel</option><option value="story">Story</option>
                                <option value="live">Live</option><option value="email">Email</option><option value="blog">Blog</option>
                            </select>
                        </div>
                        <div><label class="text-xs font-semibold">Estado</label>
                            <select v-model="form.estado" class="input w-full">
                                <option value="idea">Idea</option><option value="produccion">Producción</option>
                                <option value="programado">Programado</option><option value="publicado">Publicado</option>
                            </select>
                        </div>
                    </div>
                    <div><label class="text-xs font-semibold">Título</label><input v-model="form.titulo" class="input w-full" autofocus/></div>
                    <div><label class="text-xs font-semibold">Copy</label><textarea v-model="form.copy" rows="3" class="input w-full"></textarea></div>
                    <div><label class="text-xs font-semibold">Producto (opcional)</label>
                        <select v-model="form.producto_id" class="input w-full">
                            <option :value="null">—</option>
                            <option v-for="p in productos" :key="p.id" :value="p.id">{{ p.label }}</option>
                        </select>
                    </div>
                    <div><label class="text-xs font-semibold">Contenedor (opcional)</label>
                        <select v-model="form.importacion_id" class="input w-full">
                            <option :value="null">—</option>
                            <option v-for="c in contenedores" :key="c.id" :value="c.id">{{ c.label }}</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" v-model="form.produccion"/>
                        Hay que grabarlo (arranca en grabación → edición → entrega)
                    </label>
                    <div><label class="text-xs font-semibold">URL publicación (si ya se publicó)</label><input v-model="form.url_publicacion" class="input w-full"/></div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modal = false" class="btn-ghost">Cancelar</button>
                    <button @click="crear" :disabled="procesando" class="btn-primary">{{ procesando ? '…' : 'Agregar' }}</button>
                </div>
            </div>
        </div>
        <!-- Detalle del post: producción del contenido (MKT-3) -->
        <div v-if="detalle" @click.self="detalle = null" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full max-h-[90vh] overflow-y-auto space-y-4">
                <div>
                    <h3 class="text-lg font-bold">{{ detalle.titulo }}</h3>
                    <div class="text-xs text-surface-500 mt-1">{{ detalle.canal }} · {{ detalle.tipo }} · {{ detalle.estado }}</div>
                    <div v-if="detalle.producto" class="text-sm mt-2">Producto: <b>{{ detalle.producto }}</b></div>
                    <div v-if="detalle.contenedor" class="text-sm">Contenedor: <b>{{ detalle.contenedor }}</b></div>
                </div>

                <div>
                    <div class="text-xs font-semibold mb-2 flex items-center gap-1"><Clapperboard class="h-3 w-3"/> Producción</div>
                    <ol class="flex items-center gap-1 text-xs">
                        <li v-for="(label, clave, i) in ETAPAS" :key="clave" class="flex items-center gap-1">
                            <span class="rounded-full px-2 py-0.5 font-semibold"
                                :class="detalle.fechas_etapa[clave] ? 'bg-emerald-100 text-emerald-800' : 'bg-surface-100 text-surface-400'">
                                {{ label }}<span v-if="detalle.fechas_etapa[clave]" class="font-normal"> · {{ detalle.fechas_etapa[clave] }}</span>
                            </span>
                            <span v-if="i < 2" class="text-surface-300">›</span>
                        </li>
                    </ol>
                    <p class="text-xs text-surface-500 mt-2">
                        Material subido: <b>{{ detalle.creativos }}</b>.
                        <template v-if="detalle.siguiente_etapa === 'entregado'">Al entregar, lo interno pasa a «solo ver» para mayorista y dropship; lo marcado con descarga se queda así.</template>
                    </p>
                </div>

                <div class="flex items-center justify-between gap-2 flex-wrap">
                    <Link :href="`/app/marketing/creativos?post=${detalle.id}`" class="btn-ghost text-sm"><Images class="h-4 w-4"/> Subir o ver material</Link>
                    <button v-if="detalle.siguiente_etapa" @click="avanzar(detalle)" :disabled="avanzando || (detalle.siguiente_etapa === 'entregado' && !detalle.creativos)"
                        class="btn-primary text-sm" :title="detalle.siguiente_etapa === 'entregado' && !detalle.creativos ? 'Suba el material antes de entregar' : ''">
                        {{ ACCION[detalle.siguiente_etapa] }}
                    </button>
                    <span v-else class="text-sm text-emerald-700 font-semibold">Entregado</span>
                </div>
                <div v-if="$page.props.flash?.error" class="text-xs text-red-600">{{ $page.props.flash.error }}</div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
