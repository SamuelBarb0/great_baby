<script setup>
/*
 * Barra de pestañas de una sección.
 *
 * Nació de un reclamo concreto: el menú lateral tenía 34 entradas y nadie
 * encontraba nada. Las pantallas que son del mismo tema —las cinco de SIIGO,
 * las cuatro de configuración contable— dejan de ser cinco entradas de menú y
 * pasan a ser una sola, con estas pestañas adentro.
 *
 * No fusiona las pantallas: cada una sigue siendo la suya, con su ruta y su
 * controller. Lo único que cambia es por dónde se navega entre ellas. Eso
 * mantiene el cambio barato y sin riesgo de romper lo que ya funcionaba.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    // [{ label, href, badge? }]
    pestanas: { type: Array, required: true },
});

const page = usePage();

/*
 * La activa es la de ruta MÁS LARGA que coincida con la URL actual. Hace falta
 * así porque `/app/contabilidad/siigo/conciliacion` también empieza por
 * `/app/contabilidad`, y con un `startsWith` a secas se encenderían dos.
 */
const activa = computed(() => {
    const url = (page.url || '').split('?')[0];
    let mejor = null;
    for (const p of props.pestanas) {
        if (url === p.href || url.startsWith(p.href + '/')) {
            if (! mejor || p.href.length > mejor.href.length) mejor = p;
        }
    }
    return mejor?.href ?? null;
});
</script>

<template>
    <div class="flex items-center gap-1 border-b border-surface-200 dark:border-surface-800 overflow-x-auto">
        <Link v-for="p in pestanas" :key="p.href" :href="p.href"
              :class="['px-4 py-2 text-sm font-semibold border-b-2 whitespace-nowrap transition',
                       activa === p.href
                       ? 'border-brand-500 text-brand-600'
                       : 'border-transparent text-surface-500 hover:text-surface-800 dark:hover:text-surface-200']">
            {{ p.label }}
            <span v-if="p.badge !== undefined && p.badge !== null"
                  class="ml-1 text-xs opacity-70">({{ p.badge }})</span>
        </Link>
    </div>
</template>
