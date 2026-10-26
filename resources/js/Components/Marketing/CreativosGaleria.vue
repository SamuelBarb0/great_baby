<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Upload, Link2, Download, Trash2, Film, Image as ImageIcon, FileText, ExternalLink, Lock, Eye } from 'lucide-vue-next';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';

/**
 * MKT-2 · Galería de creativos con su formulario de subida.
 *
 * La usan la ficha del producto, la nube de creativos y la entrega de un
 * post de la parrilla. `fijos` trae lo que ya está decidido por la pantalla
 * (el producto de la ficha, el post): no se vuelve a preguntar.
 */
const props = defineProps({
    creativos: { type: Array, default: () => [] },
    gestiona: { type: Boolean, default: false },
    maxKb: { type: Number, required: true },
    fijos: { type: Object, default: () => ({}) },
    productos: { type: Array, default: () => [] },
    contenedores: { type: Array, default: () => [] },
});

const PERMISOS = {
    interno: { label: 'Interno', clase: 'bg-surface-100 text-surface-700', ayuda: 'Solo Marketing' },
    solo_ver: { label: 'Solo ver', clase: 'bg-blue-100 text-blue-800', ayuda: 'Ventas lo ve, no lo descarga' },
    descarga: { label: 'Descarga', clase: 'bg-emerald-100 text-emerald-800', ayuda: 'Ventas lo ve y lo descarga' },
};
const TIPOS = { video: 'Video', reel: 'Reel', imagen: 'Imagen', otro: 'Otro' };

const form = reactive({ tipo: 'imagen', titulo: '', permiso: 'interno', enlace_externo: '', producto_id: null, importacion_id: null });
const archivos = ref([]);
const inputArchivos = ref(null);
const modo = ref('archivo');
const subiendo = ref(false);
const progreso = ref(null);
const errores = ref({});
const modalConfirm = ref(null);

const elegir = (e) => { archivos.value = Array.from(e.target.files || []); };

const mb = (bytes) => bytes == null ? '' : (bytes / 1048576).toFixed(bytes < 1048576 ? 2 : 1) + ' MB';
const pesados = () => archivos.value.filter(f => f.size > props.maxKb * 1024).map(f => f.name);

const subir = () => {
    if (subiendo.value) return;
    errores.value = {};
    if (modo.value === 'archivo' && pesados().length) {
        errores.value = { archivos: `Pesan más de ${props.maxKb / 1024} MB: ${pesados().join(', ')}. Súbalos como enlace (Drive, Dropbox, YouTube).` };
        return;
    }
    subiendo.value = true;
    router.post('/app/marketing/creativos', {
        ...form,
        ...props.fijos,
        enlace_externo: modo.value === 'enlace' ? form.enlace_externo : null,
        archivos: modo.value === 'archivo' ? archivos.value : [],
    }, {
        forceFormData: true,
        preserveScroll: true,
        onProgress: (e) => { progreso.value = e?.percentage ?? null; },
        onSuccess: () => {
            archivos.value = [];
            form.titulo = '';
            form.enlace_externo = '';
            if (inputArchivos.value) inputArchivos.value.value = '';
        },
        onError: (e) => { errores.value = e; },
        onFinish: () => { subiendo.value = false; progreso.value = null; },
    });
};

const cambiarPermiso = (c, permiso) => {
    router.put(`/app/marketing/creativos/${c.id}`, { permiso }, { preserveScroll: true });
};

const eliminar = (c) => {
    modalConfirm.value = {
        titulo: '¿Eliminar el creativo?',
        mensaje: `«${c.titulo}» deja de verse para todas las áreas.`,
        color: 'rose',
        textoConfirmar: 'Eliminar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.delete(`/app/marketing/creativos/${c.id}`, { preserveScroll: true });
        },
    };
};

const primerError = () => errores.value.archivos || errores.value.enlace_externo
    || Object.entries(errores.value).find(([k]) => k.startsWith('archivos.'))?.[1]
    || Object.values(errores.value)[0];
</script>

<template>
    <div class="space-y-3">
        <!-- Subir -->
        <div v-if="gestiona" class="rounded-lg border border-dashed border-surface-300 p-3 space-y-3">
            <div class="flex items-center gap-2 text-sm">
                <button @click="modo = 'archivo'" class="px-3 py-1 rounded-full" :class="modo === 'archivo' ? 'bg-brand-600 text-white' : 'bg-surface-100'">
                    <Upload class="h-3 w-3 inline"/> Archivo
                </button>
                <button @click="modo = 'enlace'" class="px-3 py-1 rounded-full" :class="modo === 'enlace' ? 'bg-brand-600 text-white' : 'bg-surface-100'">
                    <Link2 class="h-3 w-3 inline"/> Enlace externo
                </button>
                <span class="text-xs text-surface-500 ml-auto">Hasta {{ maxKb / 1024 }} MB por archivo; lo más pesado, por enlace.</span>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <div>
                    <label class="text-xs font-semibold">Tipo</label>
                    <select v-model="form.tipo" class="input w-full">
                        <option v-for="(l, t) in TIPOS" :key="t" :value="t">{{ l }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold">Permiso</label>
                    <select v-model="form.permiso" class="input w-full" :title="PERMISOS[form.permiso].ayuda">
                        <option v-for="(p, k) in PERMISOS" :key="k" :value="k">{{ p.label }} · {{ p.ayuda }}</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="text-xs font-semibold">Título (opcional)</label>
                    <input v-model="form.titulo" maxlength="200" class="input w-full" placeholder="Si lo deja vacío, el nombre del archivo"/>
                </div>
                <div v-if="!fijos.producto_id && !fijos.post_id && productos.length" class="col-span-2">
                    <label class="text-xs font-semibold">Producto</label>
                    <select v-model="form.producto_id" class="input w-full">
                        <option :value="null">—</option>
                        <option v-for="p in productos" :key="p.id" :value="p.id">{{ p.label }}</option>
                    </select>
                </div>
                <div v-if="!fijos.importacion_id && !fijos.post_id && contenedores.length" class="col-span-2">
                    <label class="text-xs font-semibold">Contenedor</label>
                    <select v-model="form.importacion_id" class="input w-full">
                        <option :value="null">—</option>
                        <option v-for="c in contenedores" :key="c.id" :value="c.id">{{ c.label }}</option>
                    </select>
                </div>
            </div>

            <div v-if="modo === 'archivo'">
                <input ref="inputArchivos" type="file" multiple @change="elegir"
                    accept="image/*,video/*,.pdf,.zip,.heic" class="text-sm"/>
                <div v-if="archivos.length" class="text-xs text-surface-500 mt-1">
                    {{ archivos.length }} {{ archivos.length === 1 ? 'archivo' : 'archivos' }} · {{ mb(archivos.reduce((s, f) => s + f.size, 0)) }}
                </div>
            </div>
            <div v-else>
                <input v-model="form.enlace_externo" type="url" class="input w-full" placeholder="https://drive.google.com/…"/>
            </div>

            <div v-if="Object.keys(errores).length" class="text-xs text-red-600">{{ primerError() }}</div>

            <div class="flex items-center justify-end gap-3">
                <span v-if="progreso !== null" class="text-xs text-surface-500">Subiendo… {{ progreso }} %</span>
                <button @click="subir" :disabled="subiendo || (modo === 'archivo' ? !archivos.length : !form.enlace_externo)" class="btn-primary">
                    <Upload class="h-4 w-4"/> {{ subiendo ? 'Subiendo…' : 'Subir' }}
                </button>
            </div>
        </div>

        <!-- Galería -->
        <div v-if="!creativos.length" class="text-sm text-surface-500 text-center py-6">Sin creativos todavía.</div>
        <div v-else class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            <div v-for="c in creativos" :key="c.id" class="rounded-lg border border-surface-200 overflow-hidden flex flex-col">
                <div class="aspect-square bg-surface-50 flex items-center justify-center overflow-hidden">
                    <img v-if="c.es_imagen && c.ver_url" :src="c.ver_url" :alt="c.titulo" loading="lazy" class="w-full h-full object-cover"/>
                    <video v-else-if="c.es_video && c.ver_url" :src="c.ver_url" controls preload="metadata"
                        :controlslist="c.descargar_url ? '' : 'nodownload'" class="w-full h-full object-contain bg-black"></video>
                    <a v-else-if="c.enlace_externo" :href="c.enlace_externo" target="_blank" rel="noopener" class="flex flex-col items-center gap-1 text-brand-600 text-xs p-2 text-center">
                        <ExternalLink class="h-8 w-8"/> Abrir enlace
                    </a>
                    <a v-else-if="c.ver_url" :href="c.ver_url" target="_blank" rel="noopener" class="flex flex-col items-center gap-1 text-surface-500 text-xs">
                        <FileText class="h-8 w-8"/> {{ c.archivo_nombre }}
                    </a>
                </div>
                <div class="p-2 space-y-1 flex-1 flex flex-col">
                    <div class="text-sm font-semibold truncate" :title="c.titulo">{{ c.titulo }}</div>
                    <div class="text-[11px] text-surface-500 flex items-center gap-1 flex-wrap">
                        <component :is="c.tipo === 'imagen' ? ImageIcon : Film" class="h-3 w-3"/>
                        {{ TIPOS[c.tipo] }}<span v-if="c.tamano"> · {{ mb(c.tamano) }}</span>
                    </div>
                    <div v-if="c.producto && !fijos.producto_id" class="text-[11px] text-surface-500 truncate">{{ c.producto.nombre }}</div>
                    <div v-if="c.contenedor" class="text-[11px] text-surface-500 truncate">{{ c.contenedor.numero }}<span v-if="c.contenedor.contenedor"> · {{ c.contenedor.contenedor }}</span></div>
                    <div class="mt-auto pt-1 flex items-center gap-1 flex-wrap">
                        <select v-if="gestiona" :value="c.permiso" @change="cambiarPermiso(c, $event.target.value)"
                            class="text-[11px] rounded px-1 py-0.5 border-0" :class="PERMISOS[c.permiso].clase" :title="PERMISOS[c.permiso].ayuda">
                            <option v-for="(p, k) in PERMISOS" :key="k" :value="k">{{ p.label }}</option>
                        </select>
                        <span v-else class="text-[11px] rounded px-1.5 py-0.5 inline-flex items-center gap-1" :class="PERMISOS[c.permiso].clase">
                            <component :is="c.permiso === 'descarga' ? Download : (c.permiso === 'solo_ver' ? Eye : Lock)" class="h-3 w-3"/>
                            {{ PERMISOS[c.permiso].label }}
                        </span>
                        <a v-if="c.descargar_url" :href="c.descargar_url" class="ml-auto text-brand-600" title="Descargar"><Download class="h-4 w-4"/></a>
                        <a v-else-if="c.enlace_externo && c.permiso === 'descarga'" :href="c.enlace_externo" target="_blank" rel="noopener" class="ml-auto text-brand-600" title="Abrir enlace"><ExternalLink class="h-4 w-4"/></a>
                        <button v-if="gestiona" @click="eliminar(c)" class="text-red-600" :class="c.descargar_url || c.enlace_externo ? '' : 'ml-auto'" title="Eliminar"><Trash2 class="h-4 w-4"/></button>
                    </div>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </div>
</template>
