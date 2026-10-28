<script setup>
import { computed } from 'vue';

/**
 * MKT-4 · Dónde va el producto: compra → contenedor → bodega → contenido →
 * publicado. La etapa la calcula el servidor (EtapasProducto); aquí solo se
 * dibuja, con los días que lleva desde que llegó si Marketing aún no termina.
 */
const props = defineProps({
    etapa: { type: Object, required: true },
    etapas: { type: Object, required: true },
    compacto: { type: Boolean, default: false },
});

const claves = computed(() => Object.keys(props.etapas));
const indice = computed(() => claves.value.indexOf(props.etapa.etapa));

const PIEZAS = { video: 'video', reel: 'reel', imagen: 'imágenes' };
const faltan = computed(() => Object.entries(props.etapa.piezas?.faltan || {})
    .map(([t, n]) => t === 'imagen' ? `${n} ${PIEZAS[t]}` : PIEZAS[t]));

// Lo que llegó hace más de una semana y sigue sin publicarse es lo que se
// queja Marketing: se resalta.
const atrasado = computed(() => props.etapa.dias_desde_llegada !== null
    && props.etapa.dias_desde_llegada > 7
    && ['bodega', 'contenido'].includes(props.etapa.etapa));
</script>

<template>
    <div>
        <ol class="flex items-center gap-1" :class="compacto ? 'text-[10px]' : 'text-xs'">
            <li v-for="(c, i) in claves" :key="c" class="flex items-center gap-1 min-w-0">
                <span class="rounded-full px-2 py-0.5 font-semibold whitespace-nowrap"
                    :class="i < indice ? 'bg-emerald-100 text-emerald-800'
                        : i === indice ? 'bg-brand-600 text-white'
                        : 'bg-surface-100 text-surface-400'">
                    {{ etapas[c] }}
                </span>
                <span v-if="i < claves.length - 1" class="text-surface-300">›</span>
            </li>
        </ol>
        <div v-if="!compacto" class="text-xs text-surface-500 mt-2 space-y-0.5">
            <div v-if="etapa.etapa === null">Sin compra registrada ni contenido todavía.</div>
            <div v-if="etapa.orden">Orden de compra {{ etapa.orden }}<span v-if="etapa.contenedor"> · contenedor {{ etapa.contenedor.numero }}<span v-if="etapa.contenedor.contenedor"> ({{ etapa.contenedor.contenedor }})</span><span v-if="etapa.contenedor.eta && !etapa.llego_en"> · ETA {{ etapa.contenedor.eta }}</span></span></div>
            <div v-if="etapa.llego_en" :class="atrasado ? 'text-amber-700 font-semibold' : ''">
                Llegó a bodega el {{ etapa.llego_en }} · hace {{ etapa.dias_desde_llegada }} {{ etapa.dias_desde_llegada === 1 ? 'día' : 'días' }}
            </div>
            <div v-if="faltan.length" class="text-amber-700">Faltan piezas: {{ faltan.join(', ') }}</div>
            <div v-else-if="etapa.piezas?.completas" class="text-emerald-700">Piezas completas.</div>
        </div>
    </div>
</template>
