<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';

/*
 * La misma respuesta en la orden, el contenedor y la recepción: en qué paso va,
 * qué sigue, quién lo hace y con qué botón.
 */
const props = defineProps({ paso: { type: Object, required: true } });
defineEmits(['accion']);

const terminado = computed(() => props.paso.numero > props.paso.pasos.length);
const estado = (i) => (i + 1 < props.paso.numero ? 'hecho' : i + 1 === props.paso.numero ? 'actual' : 'pendiente');
const claseBoton = (a) => (a.principal ? 'btn-primary text-sm' : 'btn-ghost text-sm border border-surface-300 dark:border-surface-700');
</script>

<template>
    <section class="card p-4 space-y-3 border-l-4" :class="terminado ? 'border-emerald-500' : 'border-brand-600'" aria-label="Qué sigue">
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
            <li v-for="(nombre, i) in paso.pasos" :key="nombre" class="flex items-center gap-1.5">
                <span class="h-5 w-5 rounded-full flex items-center justify-center font-bold"
                      :class="{
                          'bg-emerald-600 text-white': estado(i) === 'hecho',
                          'bg-brand-600 text-white': estado(i) === 'actual',
                          'bg-surface-200 text-surface-600 dark:bg-surface-800 dark:text-surface-300': estado(i) === 'pendiente',
                      }">
                    <Check v-if="estado(i) === 'hecho'" class="h-3 w-3" aria-label="Hecho"/>
                    <template v-else>{{ i + 1 }}</template>
                </span>
                <span :class="estado(i) === 'actual' ? 'font-bold' : 'text-surface-500'" :aria-current="estado(i) === 'actual' ? 'step' : undefined">{{ nombre }}</span>
                <span v-if="i < paso.pasos.length - 1" class="text-surface-400 ml-0.5" aria-hidden="true">→</span>
            </li>
        </ol>

        <div>
            <div class="text-xs uppercase font-bold" :class="terminado ? 'text-emerald-700 dark:text-emerald-400' : 'text-brand-600'">{{ terminado ? 'Terminado' : 'Lo que sigue' }}</div>
            <p class="text-lg font-bold leading-snug mt-0.5">{{ paso.titulo }}</p>
            <p class="text-sm text-surface-600 dark:text-surface-400 mt-1">{{ paso.explica }}</p>
            <p v-if="paso.quien" class="text-xs text-surface-500 mt-1">Esto lo hace: <b>{{ paso.quien }}</b></p>
        </div>

        <div v-if="paso.acciones.length" class="flex flex-wrap gap-2">
            <template v-for="a in paso.acciones" :key="a.etiqueta">
                <Link v-if="a.tipo === 'enlace'" :href="a.href" :class="claseBoton(a)">{{ a.etiqueta }}</Link>
                <a v-else-if="a.tipo === 'descarga'" :href="a.href" :class="claseBoton(a)">{{ a.etiqueta }}</a>
                <button v-else type="button" @click="$emit('accion', a.accion)" :class="claseBoton(a)">{{ a.etiqueta }}</button>
            </template>
        </div>
    </section>
</template>
