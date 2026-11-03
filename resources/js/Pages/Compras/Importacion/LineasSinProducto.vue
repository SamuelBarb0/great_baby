<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { PackagePlus } from 'lucide-vue-next';

/*
 * Una línea sin producto se recibe pero no sube al inventario. Las que traen
 * referencia se crean solas; las que no, necesitan que alguien se la ponga.
 */
const props = defineProps({
    ordenId: { type: Number, required: true },
    lineas: { type: Array, required: true },
});

const referencias = reactive(Object.fromEntries(props.lineas.map((l) => [l.id, l.referencia || ''])));
const enviando = ref(false);
const listas = computed(() => props.lineas.filter((l) => (referencias[l.id] || '').trim()).length);

const crear = () => {
    if (enviando.value || !listas.value) return;
    enviando.value = true;
    router.post(`/app/compras/orden/${props.ordenId}/productos`, { referencias: { ...referencias } }, {
        preserveScroll: true,
        onFinish: () => { enviando.value = false; },
    });
};
</script>

<template>
    <div v-if="lineas.length" class="card p-4 space-y-3 border-l-4 border-amber-500">
        <div>
            <div class="text-xs uppercase font-bold text-amber-700 dark:text-amber-400 flex items-center gap-1"><PackagePlus class="h-3.5 w-3.5"/>{{ lineas.length }} línea(s) sin producto</div>
            <p class="text-sm mt-1">Sin producto no suben al inventario cuando bodega las reciba. Las que tienen referencia se crean solas al recibir, o puede crearlas ya. Si la referencia existe, se usa ese producto.</p>
        </div>
        <div class="divide-y divide-surface-100 dark:divide-surface-800">
            <div v-for="l in lineas" :key="l.id" class="grid grid-cols-1 md:grid-cols-[1fr_12rem] gap-2 py-2 items-center">
                <span class="text-sm">{{ l.descripcion }}</span>
                <input v-model="referencias[l.id]" maxlength="60" class="input w-full text-sm" :aria-label="`Referencia de ${l.descripcion}`" placeholder="Referencia"/>
            </div>
        </div>
        <div class="flex justify-end">
            <button @click="crear" :disabled="!listas || enviando" class="btn-primary text-sm disabled:opacity-50">{{ enviando ? 'Creando…' : `Crear ${listas} producto(s)` }}</button>
        </div>
    </div>
</template>
