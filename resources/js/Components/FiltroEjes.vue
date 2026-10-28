<script setup>
/*
 * F2c · Los dos ejes, en un control que se ve igual en todas las pantallas.
 *
 * Línea de negocio y destino fiscal se filtran en el libro, en los pagos y en
 * las facturas. Si cada pantalla lo pinta a su manera, la gente tiene que
 * aprender tres veces lo mismo — y peor, «internas» puede terminar
 * significando algo distinto en cada una.
 *
 * El botón de exportar vive acá porque lo que se baja es exactamente lo que
 * deja ver este filtro: tenerlos juntos es lo que hace evidente que uno manda
 * sobre el otro.
 */
import { computed } from 'vue';
import { Download } from 'lucide-vue-next';

const props = defineProps({
    linea: { type: String, default: 'todas' },
    destino: { type: String, default: 'todas' },
    opciones: { type: Object, default: () => ({ lineas: [], destinos: [] }) },
    // A dónde apunta «Exportar». Se le pegan los filtros actuales.
    urlExport: { type: String, default: '' },
    paramsExport: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:linea', 'update:destino']);

const hrefExport = computed(() => {
    if (! props.urlExport) return null;
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(props.paramsExport)) {
        if (v !== null && v !== undefined && v !== '' && v !== 'todas' && v !== 'todos') p.set(k, v);
    }
    if (props.linea !== 'todas') p.set('linea', props.linea);
    if (props.destino !== 'todas') p.set('destino', props.destino);
    const qs = p.toString();
    return qs ? `${props.urlExport}?${qs}` : props.urlExport;
});
</script>

<template>
    <div class="flex items-end gap-2 flex-wrap">
        <div>
            <label class="text-xs font-semibold text-surface-500">Línea de negocio</label>
            <select :value="linea" @change="emit('update:linea', $event.target.value)" class="input mt-1">
                <option value="todas">Todas</option>
                <option v-for="l in opciones.lineas" :key="l.valor" :value="l.valor">{{ l.label }}</option>
            </select>
        </div>
        <div>
            <label class="text-xs font-semibold text-surface-500">Destino fiscal</label>
            <select :value="destino" @change="emit('update:destino', $event.target.value)" class="input mt-1">
                <option v-for="d in opciones.destinos" :key="d.valor" :value="d.valor">{{ d.label }}</option>
            </select>
        </div>
        <a v-if="hrefExport" :href="hrefExport"
           class="btn-secondary text-sm inline-flex items-center gap-1 self-end"
           title="Baja exactamente lo que estás viendo, con el filtro aplicado">
            <Download class="h-4 w-4"/> Exportar
        </a>
    </div>
</template>
