<script setup>
/**
 * «Olvidé mi contraseña», sin sesión.
 *
 * La respuesta es SIEMPRE la misma, exista o no el correo. Decir «ese correo
 * no está registrado» le confirma a quien prueba una lista cuáles aciertan, y
 * los correos de esta empresa son nombre.apellido@greatbaby.com: una lista se
 * arma sola.
 */

import { useForm, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { MailCheck, ArrowLeft } from 'lucide-vue-next';

const props = defineProps({
    flash: { type: Object, default: () => ({}) },
});

const form = useForm({ email: '' });

const enviado = computed(() => !! props.flash?.success);

const submit = () => form.post('/app/olvide-clave', { preserveScroll: true });
</script>

<template>
    <Head title="Olvidé mi contraseña"/>
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
                <!-- Ya se mandó: la pantalla cambia entera, para que nadie se
                     quede mirando el mismo formulario sin saber si pasó algo. -->
                <template v-if="enviado">
                    <div class="text-center space-y-3">
                        <div class="mx-auto h-12 w-12 rounded-full bg-emerald-100 dark:bg-emerald-950/50 flex items-center justify-center">
                            <MailCheck class="h-6 w-6 text-emerald-600" />
                        </div>
                        <h1 class="text-xl font-bold">Revisá tu correo</h1>
                        <p class="text-sm text-surface-600 dark:text-surface-300 leading-relaxed">
                            {{ flash.success }}
                        </p>
                        <p class="text-xs text-surface-500">
                            El enlace vence en una hora y sirve una sola vez.
                        </p>
                    </div>
                </template>

                <template v-else>
                    <h1 class="text-xl font-bold text-surface-900 dark:text-surface-100 mb-1">¿Olvidaste tu contraseña?</h1>
                    <p class="text-sm text-surface-500 mb-6">
                        Escribí tu correo y te mandamos un enlace para poner una nueva.
                        No hace falta pedírsela a nadie.
                    </p>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div>
                            <label for="email" class="label">Correo</label>
                            <input id="email" v-model="form.email" type="email" required autofocus
                                   autocomplete="username" class="input"
                                   placeholder="nombre@greatbaby.com"/>
                            <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                        </div>

                        <button type="submit" :disabled="form.processing || ! form.email" class="btn-primary w-full py-2.5">
                            {{ form.processing ? 'Mandando…' : 'Mandame el enlace' }}
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
