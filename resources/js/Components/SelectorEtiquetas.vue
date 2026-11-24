<script setup>
/**
 * Escoger qué etiquetas imprimir de un producto (bodega, 23-nov-2026): el
 * producto original (su referencia), una variante exacta, todas, o los colores
 * de Dropi de un agrupado; con copias y en hoja carta o en el rollo de la
 * térmica. El PDF se abre en otra pestaña (EtiquetasMasivasController).
 */
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { Printer, X } from 'lucide-vue-next';

const props = defineProps({
    productoId: { type: Number, required: true },
    abierto: { type: Boolean, default: false },
});
const emit = defineEmits(['cerrar']);

const cargando = ref(false);
const error = ref('');
const opciones = ref(null);
const original = ref(false);
const variantes = ref([]);
const colores = ref([]);
const copias = ref(1);
const formato = ref('carta');

watch(() => props.abierto, async (abierto) => {
    if (! abierto) return;
    cargando.value = true;
    error.value = '';
    try {
        const { data } = await axios.get(`/catalogo/etiquetas-opciones/${props.productoId}`);
        opciones.value = data;
        // Por defecto, lo mismo que imprimía el botón de antes: todas las
        //   variantes o, en un agrupado, todos sus colores (o el producto si no tiene).
        variantes.value = data.variantes.map((v) => v.id);
        colores.value = [...data.colores];
        original.value = data.variantes.length === 0 && data.colores.length === 0;
    } catch (e) {
        error.value = 'No se pudieron traer las opciones de etiquetas. Cierra y vuelve a intentar.';
    } finally {
        cargando.value = false;
    }
}, { immediate: true });

const total = computed(() => ((original.value ? 1 : 0) + variantes.value.length + colores.value.length) * (Number(copias.value) || 1));

const url = computed(() => {
    const q = new URLSearchParams();
    q.append('productos[]', props.productoId);
    q.append('seleccion', '1');
    if (original.value) q.append('original', '1');
    variantes.value.forEach((id) => q.append('variantes[]', id));
    colores.value.forEach((c) => q.append('colores[]', c));
    q.append('copias', String(Math.min(100, Math.max(1, Number(copias.value) || 1))));
    q.append('formato', formato.value);
    return `/catalogo/etiquetas-lote?${q.toString()}`;
});

const todas = (lista, destino) => { destino.value = destino.value.length === lista.length ? [] : lista.map((x) => x.id ?? x); };
</script>

<template>
    <div v-if="abierto" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @click.self="emit('cerrar')">
        <div class="card w-full max-w-lg p-5 space-y-4 max-h-[90vh] overflow-y-auto" role="dialog" aria-labelledby="sel-etq-titulo">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 id="sel-etq-titulo" class="font-semibold text-lg">Imprimir etiquetas</h3>
                    <p v-if="opciones" class="text-xs text-surface-500">{{ opciones.producto.referencia }} · {{ opciones.producto.nombre }}</p>
                </div>
                <button type="button" class="text-surface-500 hover:text-surface-800" aria-label="Cerrar" @click="emit('cerrar')"><X class="w-5 h-5"/></button>
            </div>

            <p v-if="cargando" class="text-sm text-surface-500">Cargando…</p>
            <p v-else-if="error" class="text-sm text-rose-600">{{ error }}</p>

            <template v-else-if="opciones">
                <label class="flex items-start gap-2 text-sm">
                    <input id="etq-original" v-model="original" type="checkbox" class="mt-1">
                    <span><strong>Producto original</strong> · código <span class="font-mono">{{ opciones.producto.referencia }}</span>
                        <span class="block text-xs text-surface-500">Una etiqueta con la referencia del producto, sin color.</span></span>
                </label>

                <div v-if="opciones.variantes.length" class="space-y-1">
                    <div class="flex items-center justify-between">
                        <span class="text-xs uppercase tracking-wider font-semibold text-surface-600">Variantes ({{ opciones.variantes.length }})</span>
                        <button type="button" class="text-xs text-brand-600 hover:underline" @click="todas(opciones.variantes, variantes)">
                            {{ variantes.length === opciones.variantes.length ? 'Quitar todas' : 'Marcar todas' }}
                        </button>
                    </div>
                    <label v-for="v in opciones.variantes" :key="v.id" class="flex items-center gap-2 text-sm">
                        <input :id="`etq-var-${v.id}`" v-model="variantes" :value="v.id" type="checkbox">
                        <span>{{ v.nombre }} · <span class="font-mono text-xs">{{ v.codigo }}</span></span>
                    </label>
                </div>

                <div v-if="opciones.colores.length" class="space-y-1">
                    <div class="flex items-center justify-between">
                        <span class="text-xs uppercase tracking-wider font-semibold text-surface-600">Colores de Dropi ({{ opciones.colores.length }})</span>
                        <button type="button" class="text-xs text-brand-600 hover:underline" @click="todas(opciones.colores, colores)">
                            {{ colores.length === opciones.colores.length ? 'Quitar todos' : 'Marcar todos' }}
                        </button>
                    </div>
                    <p class="text-xs text-surface-500">Llevan el color escrito y el mismo código de la referencia: este producto no tiene variantes.</p>
                    <label v-for="c in opciones.colores" :key="c" class="flex items-center gap-2 text-sm">
                        <input :id="`etq-color-${c}`" v-model="colores" :value="c" type="checkbox">
                        <span>{{ c }}</span>
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <label class="text-xs font-semibold text-surface-600">Copias de cada una
                        <input id="etq-copias" v-model.number="copias" type="number" min="1" max="100" class="input w-full mt-1">
                    </label>
                    <label class="text-xs font-semibold text-surface-600">Formato
                        <select id="etq-formato" v-model="formato" class="input w-full mt-1">
                            <option value="carta">Hoja carta</option>
                            <option value="rollo">Rollo térmica 100 × 25 mm</option>
                        </select>
                    </label>
                </div>

                <div class="flex items-center justify-between gap-3 pt-1">
                    <span class="text-sm text-surface-600">{{ total }} etiqueta(s)</span>
                    <a v-if="total > 0" :href="url" target="_blank" rel="noopener"
                       class="btn btn-primary inline-flex items-center gap-1" @click="emit('cerrar')">
                        <Printer class="w-4 h-4"/> Imprimir
                    </a>
                    <span v-else class="text-sm text-surface-500">Escoge al menos una etiqueta.</span>
                </div>
            </template>
        </div>
    </div>
</template>
