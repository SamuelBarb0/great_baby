<script setup>
/**
 * «Mi espacio» cuando el usuario todavía no tiene ficha de empleado.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE ESTA PANTALLA
 *
 * Antes acá salía un 403. Pasó en producción el 30-oct con Ana Pérez: tenía
 * su usuario, entraba bien, y lo primero que abría le respondía con un código
 * de error. Fue a preguntar si el sistema estaba caído.
 *
 * No estaba caído ni era culpa suya: a su usuario le faltaba el enlace con su
 * ficha, que es un paso de Gestión Humana. La pantalla ahora lo dice con esas
 * palabras, y le da el nombre y el correo de a quién pedírselo — porque
 * «avisale a Gestión Humana» no sirve si no sabés quién es.
 * ───────────────────────────────────────────────────────────────────────────
 */

import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { UserRoundX, Mail } from 'lucide-vue-next';

defineProps({
    correo: String,
    nombre: String,
    a_quien_pedirle: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Mi espacio" />
    <AppLayout>
        <div class="max-w-2xl mx-auto py-10 px-4">
            <div class="card p-6 sm:p-8 text-center space-y-5">
                <div class="mx-auto h-14 w-14 rounded-full bg-amber-100 dark:bg-amber-950/50 flex items-center justify-center">
                    <UserRoundX class="h-7 w-7 text-amber-600 dark:text-amber-400" />
                </div>

                <div class="space-y-2">
                    <h1 class="text-xl font-bold">Todavía no tenés tu ficha de empleado</h1>
                    <p class="text-surface-600 dark:text-surface-300 text-sm leading-relaxed">
                        Tu usuario <span class="font-mono text-surface-800 dark:text-surface-100">{{ correo }}</span>
                        entró bien. Lo que falta es enlazarlo con tu ficha, y eso lo hace
                        Gestión Humana desde la pantalla de Empleados.
                    </p>
                    <p class="text-surface-600 dark:text-surface-300 text-sm leading-relaxed">
                        Mientras tanto no podés ver tus documentos ni lo que te entregaron.
                        <b>No es un error del sistema</b> y no se arregla volviendo a entrar.
                    </p>
                </div>

                <div v-if="a_quien_pedirle.length"
                     class="text-left rounded-lg border border-surface-200 dark:border-surface-800 p-4 space-y-2">
                    <div class="text-xs uppercase font-bold text-brand-600">A quién pedírselo</div>
                    <ul class="space-y-1.5">
                        <li v-for="p in a_quien_pedirle" :key="p.correo"
                            class="text-sm flex items-center gap-2 flex-wrap">
                            <Mail class="h-3.5 w-3.5 text-surface-400 flex-shrink-0" />
                            <span class="font-medium">{{ p.nombre }}</span>
                            <a :href="`mailto:${p.correo}?subject=${encodeURIComponent('Falta enlazar mi ficha de empleado')}`"
                               class="text-brand-600 hover:underline font-mono text-xs">{{ p.correo }}</a>
                        </li>
                    </ul>
                    <p class="text-xs text-surface-500 pt-1">
                        Decile que tu usuario es <span class="font-mono">{{ correo }}</span> y que
                        hay que enlazarlo a tu ficha en Gestión Humana &rsaquo; Empleados.
                    </p>
                </div>

                <!-- Sin nadie con el acceso de RRHH tampoco se deja a la persona
                     sin salida: se le dice qué pedir, aunque no a quién. -->
                <p v-else class="text-sm text-surface-500">
                    Pedile a Gestión Humana que enlace tu usuario
                    <span class="font-mono">{{ correo }}</span> con tu ficha de empleado.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
