<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Check, Tag } from 'lucide-vue-next';

/* Subir la etiqueta de cada producto desde la orden, sin ir a buscarlo al catálogo. */
const props = defineProps({
    lineas: { type: Array, required: true },
    puedeSubir: Boolean,
});

const subiendo = ref(null);
const faltan = computed(() => props.lineas.filter((l) => !l.tiene_etiqueta).length);
const subir = (linea, evento) => {
    const archivo = evento.target.files[0];
    if (!archivo) return;
    subiendo.value = linea.producto_id;
    router.post(`/app/catalogo/producto/${linea.producto_id}/etiqueta`, { etiqueta: archivo }, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => { subiendo.value = null; },
    });
};
</script>

<template>
    <details v-if="lineas.length" class="card p-4" :open="faltan > 0 && puedeSubir">
        <summary class="cursor-pointer text-sm font-bold flex items-center gap-2">
            <Tag class="h-4 w-4 text-brand-600"/>
            Etiquetas: {{ lineas.length - faltan }} de {{ lineas.length }} productos listos
        </summary>
        <p class="text-xs text-surface-500 mt-2">La etiqueta sale sola en el Excel para China. Es opcional: suba las que tenga.</p>
        <p v-if="$page.props.errors?.etiqueta" class="text-xs text-red-600 mt-2">{{ $page.props.errors.etiqueta }}</p>
        <div class="divide-y divide-surface-100 dark:divide-surface-800 mt-2">
            <div v-for="l in lineas" :key="l.producto_id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span>{{ l.nombre }}</span>
                <span v-if="l.tiene_etiqueta" class="text-xs text-emerald-600 flex items-center gap-1"><Check class="h-3.5 w-3.5"/> Lista</span>
                <span v-else-if="subiendo === l.producto_id" class="text-xs text-surface-500">Subiendo…</span>
                <label v-else-if="puedeSubir" class="btn-ghost text-xs cursor-pointer border border-surface-300 dark:border-surface-700">
                    Subir imagen
                    <input type="file" accept=".jpg,.jpeg,.png" class="sr-only" @change="subir(l, $event)" :aria-label="`Etiqueta de ${l.nombre}`"/>
                </label>
                <span v-else class="text-xs text-surface-500">Sin etiqueta</span>
            </div>
        </div>
    </details>
</template>
