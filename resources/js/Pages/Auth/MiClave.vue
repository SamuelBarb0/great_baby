<script setup>
/**
 * Cambiar la propia contraseña, estando adentro.
 *
 * Hasta hoy no se podía: quien sospechaba que alguien vio su clave no tenía
 * nada que hacer al respecto, y una clave repartida en una demo se quedaba
 * puesta para siempre.
 *
 * La medida de fuerza no es un adorno: es lo único que hace que alguien se
 * detenga a pensar la clave en vez de escribir el nombre de la empresa y un
 * número. No bloquea —el servidor sólo exige ocho caracteres— pero sí avisa.
 */

import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { KeyRound, Eye, EyeOff, ShieldCheck } from 'lucide-vue-next';

const form = useForm({
    actual: '',
    password: '',
    password_confirmation: '',
});

const verActual = ref(false);
const verNueva = ref(false);

/** Cuánto cuesta adivinarla, en palabras y no en reglas. */
const fuerza = computed(() => {
    const v = form.password || '';

    if (! v) return null;

    let puntos = 0;
    if (v.length >= 8) puntos++;
    if (v.length >= 12) puntos++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) puntos++;
    if (/\d/.test(v)) puntos++;
    if (/[^\w\s]/.test(v)) puntos++;

    // Lo que la gente escribe cuando la pantalla la apura.
    if (/great\s*baby|greatbaby|123456|qwerty|contrase|password/i.test(v)) {
        puntos = Math.min(puntos, 1);
    }

    if (puntos <= 2) return { nivel: 1, texto: 'Débil · se adivina', clase: 'bg-red-500', ancho: 'w-1/3' };
    if (puntos === 3) return { nivel: 2, texto: 'Pasable', clase: 'bg-amber-500', ancho: 'w-2/3' };

    return { nivel: 3, texto: 'Buena', clase: 'bg-emerald-500', ancho: 'w-full' };
});

const coinciden = computed(() =>
    ! form.password_confirmation || form.password === form.password_confirmation);

const guardar = () => {
    form.post('/app/mi-clave', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Mi contraseña" />
    <AppLayout>
        <div class="max-w-lg mx-auto py-8 px-4">
            <div class="card p-6 space-y-5">
                <div class="flex items-center gap-3">
                    <div class="h-11 w-11 rounded-full bg-brand-100 dark:bg-brand-950/50 flex items-center justify-center flex-shrink-0">
                        <KeyRound class="h-5 w-5 text-brand-600" />
                    </div>
                    <div>
                        <h1 class="text-lg font-bold">Mi contraseña</h1>
                        <p class="text-xs text-surface-500">Cambiala cuando quieras. Nadie más puede hacerlo por vos.</p>
                    </div>
                </div>

                <form @submit.prevent="guardar" class="space-y-4">
                    <div>
                        <label for="actual" class="block text-sm font-semibold mb-1">Tu contraseña actual</label>
                        <div class="relative">
                            <input id="actual" :type="verActual ? 'text' : 'password'"
                                   v-model="form.actual" autocomplete="current-password"
                                   class="input w-full pr-10" />
                            <button type="button" @click="verActual = ! verActual"
                                    :aria-label="verActual ? 'Ocultar la contraseña' : 'Ver la contraseña'"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 text-surface-400 hover:text-surface-600">
                                <EyeOff v-if="verActual" class="h-4 w-4" />
                                <Eye v-else class="h-4 w-4" />
                            </button>
                        </div>
                        <p v-if="form.errors.actual" class="text-xs text-red-600 mt-1">{{ form.errors.actual }}</p>
                        <p v-else class="text-xs text-surface-500 mt-1">
                            Se pide para que una sesión olvidada en un computador compartido no alcance
                            para quedarse con tu cuenta.
                        </p>
                    </div>

                    <div>
                        <label for="nueva" class="block text-sm font-semibold mb-1">La nueva</label>
                        <div class="relative">
                            <input id="nueva" :type="verNueva ? 'text' : 'password'"
                                   v-model="form.password" autocomplete="new-password"
                                   class="input w-full pr-10" />
                            <button type="button" @click="verNueva = ! verNueva"
                                    :aria-label="verNueva ? 'Ocultar la contraseña' : 'Ver la contraseña'"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 text-surface-400 hover:text-surface-600">
                                <EyeOff v-if="verNueva" class="h-4 w-4" />
                                <Eye v-else class="h-4 w-4" />
                            </button>
                        </div>

                        <div v-if="fuerza" class="mt-2 flex items-center gap-2">
                            <div class="h-1.5 flex-1 rounded-full bg-surface-200 dark:bg-surface-800 overflow-hidden">
                                <div class="h-full rounded-full transition-all" :class="[fuerza.clase, fuerza.ancho]"></div>
                            </div>
                            <span class="text-xs font-medium whitespace-nowrap"
                                  :class="fuerza.nivel === 1 ? 'text-red-600' : fuerza.nivel === 2 ? 'text-amber-600' : 'text-emerald-600'">
                                {{ fuerza.texto }}
                            </span>
                        </div>

                        <p v-if="form.errors.password" class="text-xs text-red-600 mt-1">{{ form.errors.password }}</p>
                        <p v-else class="text-xs text-surface-500 mt-1">Mínimo ocho caracteres.</p>
                    </div>

                    <div>
                        <label for="repetir" class="block text-sm font-semibold mb-1">Repetila</label>
                        <input id="repetir" type="password" v-model="form.password_confirmation"
                               autocomplete="new-password" class="input w-full" />
                        <p v-if="! coinciden" class="text-xs text-amber-600 mt-1">Todavía no coinciden.</p>
                    </div>

                    <!-- Se dice ANTES de pulsar, no después: alguien con el
                         ERP abierto en el celular y en el computador tiene que
                         saber que va a tener que entrar de nuevo. -->
                    <div class="flex items-start gap-2 text-xs text-surface-600 dark:text-surface-300 rounded-lg bg-surface-100 dark:bg-surface-900 p-3">
                        <ShieldCheck class="h-4 w-4 flex-shrink-0 mt-0.5 text-brand-600" />
                        <span>Al cambiarla se cierran tus otras sesiones. Si tenés el ERP abierto en el
                        celular o en otro computador, vas a tener que entrar de nuevo con la nueva.</span>
                    </div>

                    <button type="submit" class="btn-primary w-full min-h-11"
                            :disabled="form.processing || ! form.actual || ! form.password || ! coinciden">
                        {{ form.processing ? 'Cambiando…' : 'Cambiar mi contraseña' }}
                    </button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
