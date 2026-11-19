<script setup>
/*
 * Los controles de paginación, una sola vez.
 *
 * Diez listados paginaban en el backend y no pintaban los controles: el Libro
 * contable mostraba «3.482 filas · mostrando 100 de esta página» y no había
 * botón de siguiente. Las otras 3.382 sólo se alcanzaban escribiendo `?page=2`
 * en la barra del navegador. Con 25 asientos manuales al mes, en enero ya no
 * se llegaba a los de diciembre.
 *
 * Existe como componente y no copiado en cada pantalla para que la próxima
 * tabla lo herede en vez de olvidarlo otra vez.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    // El paginador tal como lo manda Laravel: { data, links, from, to, total }.
    pagina: { type: Object, default: null },
    // Qué se está contando, para que el texto diga algo («movimientos», «gastos»).
    etiqueta: { type: String, default: 'resultados' },
});

/*
 * Se muestra sólo si hay más de una página. Pintar «1–30 de 30» con un único
 * botón deshabilitado es ruido: la tabla ya se ve entera.
 */
const hayMas = computed(() => {
    const p = props.pagina;
    return !! p && Array.isArray(p.data) && typeof p.total === 'number' && p.total > p.data.length;
});
</script>

<template>
    <div v-if="hayMas"
         class="flex items-center justify-between gap-3 px-4 py-3 border-t border-surface-200 dark:border-surface-800 text-sm flex-wrap">
        <div class="text-surface-500">
            {{ pagina.from }}–{{ pagina.to }} de {{ pagina.total.toLocaleString('es-CO') }} {{ etiqueta }}
        </div>
        <div class="flex items-center gap-1 flex-wrap">
            <template v-for="link in pagina.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" preserve-scroll preserve-state
                      :class="['px-2 py-1 rounded text-xs min-h-[32px] inline-flex items-center',
                               link.active
                                   ? 'bg-brand-600 text-white'
                                   : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800']"
                      v-html="link.label"/>
                <span v-else class="px-2 py-1 rounded text-xs text-surface-400" v-html="link.label"/>
            </template>
        </div>
    </div>
</template>
