<script setup>
/**
 * Poner una contraseña nueva desde el enlace del correo.
 *
 * Si el enlace ya se usó o venció se dice ANTES de que la persona escriba la
 * contraseña dos veces: descubrirlo recién al pulsar el botón es perder el
 * trabajo y no entender por qué.
 */

import { useForm, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { KeyRound, Eye, EyeOff, LinkIcon, ArrowLeft } from 'lucide-vue-next';

const props = defineProps({
    token: String,
    email: String,
    vigente: Boolean,
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const ver = ref(false);

const fuerza = computed(() => {
    const v = form.password || '';

    if (! v) return null;

    let puntos = 0;
    if (v.length >= 8) puntos++;
    if (v.length >= 12) puntos++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) puntos++;
    if (/\d/.test(v)) puntos++;
    if (/[^\w\s]/.test(v)) puntos++;

    if (/great\s*baby|greatbaby|123456|qwerty|contrase|password/i.test(v)) {
        puntos = Math.min(puntos, 1);
    }

    if (puntos <= 2) return { nivel: 1, texto: 'Débil · se adivina', clase: 'bg-red-500', ancho: 'w-1/3' };
    if (puntos === 3) return { nivel: 2, texto: 'Pasable', clase: 'bg-amber-500', ancho: 'w-2/3' };

    return { nivel: 3, texto: 'Buena', clase: 'bg-emerald-500', ancho: 'w-full' };
});

const coinciden = computed(() =>
    ! form.password_confirmation || form.password === form.password_confirmation);

const submit = () => form.post('/app/restablecer-clave');
</script>

<template>
    <Head title="Poner una contraseña nueva"/>
    <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-surface-100 via-white to-brand-50 dark:from-surface-950 dark:to-surface-900 p-4">
        <div class="w-full max-w-md">
            <div class="text-center mb-8">
                <div class="inline-flex items-center gap-3">
                    <div class="h-14 w-14 rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center text-white font-black text-lg shadow-lg">
                        GB
                    </div>
                    <div class="text-left">
                        <div class="text-2xl font-black text-surface-900 dark:text-surface-100">GREAT BABY</div>
                        <div class="text-xs uppercase tracking-widest text-brand-600 font-semibold">Sistema ERP</div>
                    </div>
                </div>
            </div>

            <div class="card p-8">
                <!-- El enlace ya no sirve: se dice de entrada, no al final. -->
                <template v-if="! vigente">
                    <div class="text-center space-y-3">
                        <div class="mx-auto h-12 w-12 rounded-full bg-amber-100 dark:bg-amber-950/50 flex items-center justify-center">
                            <LinkIcon class="h-6 w-6 text-amber-600" />
                        </div>
                        <h1 class="text-xl font-bold">Este enlace ya no sirve</h1>
                        <p class="text-sm text-surface-600 dark:text-surface-300 leading-relaxed">
                            Los enlaces vencen a la hora y sirven una sola vez. Pedí uno nuevo
                            y usalo apenas te llegue.
                        </p>
                        <Link href="/app/olvide-clave" class="btn-primary inline-flex w-full justify-center py-2.5">
                            Pedir un enlace nuevo
                        </Link>
                    </div>
                </template>

                <template v-else>
                    <div class="flex items-center gap-3 mb-5">
                        <div class="h-11 w-11 rounded-full bg-brand-100 dark:bg-brand-950/50 flex items-center justify-center flex-shrink-0">
                            <KeyRound class="h-5 w-5 text-brand-600" />
                        </div>
                        <div>
                            <h1 class="text-lg font-bold">Poné tu contraseña nueva</h1>
                            <p class="text-xs text-surface-500 font-mono">{{ email }}</p>
                        </div>
                    </div>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div>
                            <label for="nueva" class="label">La nueva</label>
                            <div class="relative">
                                <input id="nueva" :type="ver ? 'text' : 'password'" v-model="form.password"
                                       required autofocus autocomplete="new-password" class="input pr-10"/>
                                <button type="button" @click="ver = ! ver"
                                        :aria-label="ver ? 'Ocultar la contraseña' : 'Ver la contraseña'"
                                        class="absolute right-2 top-1/2 -translate-y-1/2 text-surface-400 hover:text-surface-600">
                                    <EyeOff v-if="ver" class="h-4 w-4" />
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

                            <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                            <p v-else class="text-xs text-surface-500 mt-1">Mínimo ocho caracteres.</p>
                        </div>

                        <div>
                            <label for="repetir" class="label">Repetila</label>
                            <input id="repetir" type="password" v-model="form.password_confirmation"
                                   required autocomplete="new-password" class="input"/>
                            <p v-if="! coinciden" class="text-xs text-amber-600 mt-1">Todavía no coinciden.</p>
                        </div>

                        <p v-if="form.errors.token" class="text-xs text-red-600">{{ form.errors.token }}</p>
                        <p v-if="form.errors.email" class="text-xs text-red-600">{{ form.errors.email }}</p>

                        <button type="submit" class="btn-primary w-full py-2.5"
                                :disabled="form.processing || ! form.password || ! coinciden">
                            {{ form.processing ? 'Guardando…' : 'Guardar y entrar' }}
                        </button>
                    </form>
                </template>

                <Link href="/app/login"
                      class="mt-6 inline-flex items-center gap-1.5 text-sm text-brand-600 hover:underline">
                    <ArrowLeft class="h-4 w-4"/> Volver a ingresar
                </Link>
            </div>

            <p class="text-center text-xs text-surface-500 mt-6">
                © 2026 MyTech Solutions · Desarrollado para GREAT BABY S.A.S.
            </p>
        </div>
    </div>
</template>
