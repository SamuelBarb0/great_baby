<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Tag, Trash2, Upload } from 'lucide-vue-next';

/*
 * La etiqueta de importación anclada al producto: se sube una vez y sale sola en
 * cada orden. Y las otras referencias con que se conoce, para que un cambio de
 * proveedor no termine creando un producto repetido.
 */
const props = defineProps({ producto: { type: Object, required: true } });

const archivo = ref(null);
const enviando = ref(false);
const form = reactive({ referencia: '', motivo: '' });
const opciones = (fin) => ({ preserveScroll: true, onSuccess: fin, onFinish: () => { enviando.value = false; } });

const subir = () => {
    if (!archivo.value || enviando.value) return;
    enviando.value = true;
    router.post(`/app/catalogo/producto/${props.producto.id}/etiqueta`, { etiqueta: archivo.value }, { forceFormData: true, ...opciones(() => { archivo.value = null; }) });
};
const quitar = () => router.delete(`/app/catalogo/producto/${props.producto.id}/etiqueta`, { preserveScroll: true });
const agregar = () => {
    if (!form.referencia.trim() || enviando.value) return;
    enviando.value = true;
    router.post(`/app/catalogo/producto/${props.producto.id}/referencia`, { ...form }, opciones(() => { form.referencia = ''; form.motivo = ''; }));
};
const quitarReferencia = (r) => router.delete(`/app/catalogo/referencia/${r.id}`, { preserveScroll: true });
</script>

<template>
    <div class="border-t pt-4 grid grid-cols-1 md:grid-cols-2 gap-5">
        <div>
            <div class="text-xs font-semibold text-surface-600 flex items-center gap-1 mb-1"><Tag class="h-3.5 w-3.5"/>Etiqueta de importación</div>
            <img v-if="producto.etiqueta_importacion_url" :src="producto.etiqueta_importacion_url" alt="Etiqueta de importación del producto" class="max-h-40 rounded border border-surface-200 dark:border-surface-800 mb-2"/>
            <p v-else class="text-xs text-surface-500 mb-2">Sin etiqueta. Súbala una vez y saldrá sola en el Excel y las etiquetas de cada orden.</p>
            <div class="flex items-center gap-2 flex-wrap">
                <input type="file" accept=".jpg,.jpeg,.png" @change="archivo = $event.target.files[0] || null" class="input text-xs flex-1 min-w-[12rem]" aria-label="Archivo de la etiqueta"/>
                <button type="button" @click="subir" :disabled="!archivo || enviando" class="btn-primary text-xs disabled:opacity-50"><Upload class="h-3.5 w-3.5"/>Subir</button>
                <button v-if="producto.etiqueta_importacion_url" type="button" @click="quitar" class="btn-ghost text-xs text-red-600">Quitar</button>
            </div>
        </div>
        <div>
            <div class="text-xs font-semibold text-surface-600 mb-1">Otras referencias de este producto</div>
            <ul v-if="producto.referencias_alternas?.length" class="text-sm mb-2 space-y-1">
                <li v-for="r in producto.referencias_alternas" :key="r.id" class="flex items-center gap-2">
                    <span class="font-mono text-xs font-bold">{{ r.referencia }}</span>
                    <span v-if="r.motivo" class="text-xs text-surface-500 truncate">{{ r.motivo }}</span>
                    <button type="button" @click="quitarReferencia(r)" class="text-red-600 ml-auto" :aria-label="`Quitar ${r.referencia}`"><Trash2 class="h-3.5 w-3.5"/></button>
                </li>
            </ul>
            <p v-else class="text-xs text-surface-500 mb-2">Si un proveedor o un cambio le da otra referencia, agréguela: el sistema la leerá como este mismo producto.</p>
            <div class="grid grid-cols-[1fr_1fr_auto] gap-2">
                <input v-model="form.referencia" maxlength="100" placeholder="Referencia" class="input text-sm" aria-label="Otra referencia"/>
                <input v-model="form.motivo" maxlength="200" placeholder="Motivo (opcional)" class="input text-sm" aria-label="Motivo"/>
                <button type="button" @click="agregar" :disabled="!form.referencia.trim() || enviando" class="btn-ghost text-xs disabled:opacity-50">Agregar</button>
            </div>
        </div>
    </div>
</template>
