<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Lock, Mail, Eye, EyeOff, KeyRound } from 'lucide-vue-next';

const page = usePage();
const form = reactive({ email: '', password: '', remember: false, processing: false });

/** Todo lo que el servidor haya devuelto, en el orden en que llegó. */
const erroresVisibles = computed(() => Object.values(page.props.errors ?? {}).filter(Boolean));

// H3 · toggle visibilidad + panel reset password.
const showPassword = ref(false);
const modoReset = ref(false);
const resetForm = reactive({ email: '', processing: false, enviado: false, error: '' });

const submit = () => {
    if (form.processing) return;
    form.processing = true;
    router.post('/portal/login', {
        email: form.email,
        password: form.password,
        remember: form.remember,
    }, {
        onFinish: () => { form.processing = false; form.password = ''; },
    });
};

const enviarReset = async () => {
    if (resetForm.processing) return;
    resetForm.processing = true;
    resetForm.error = '';
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const res = await fetch('/portal/password/olvide', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ email: resetForm.email }),
        });
        if (res.ok) resetForm.enviado = true;
        else {
            const j = await res.json().catch(() => ({}));
            resetForm.error = j.message || 'No pudimos procesar la solicitud.';
        }
    } catch { resetForm.error = 'Error de conexión.'; }
    finally { resetForm.processing = false; }
};
</script>

<template>
    <Head title="Portal B2B · Ingresar"/>
    <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-brand-50 to-white dark:from-surface-950 dark:to-surface-900 px-4">
        <div class="w-full max-w-md">
            <div class="text-center mb-6">
                <div class="mx-auto h-14 w-14 rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center text-white font-bold text-lg mb-3">
                    GB
                </div>
                <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-100">Portal B2B GREAT BABY</h1>
                <p class="text-sm text-surface-500 mt-1">Ingresa con las credenciales que te compartimos.</p>
            </div>

            <div class="card p-6 space-y-4">
                <!--
                    Se muestran TODOS los errores, no sólo el del correo.

                    El servidor exige ocho caracteres de clave y ese mensaje
                    llega en `errors.password`, que esta pantalla no pintaba:
                    el cliente escribía una clave corta, apretaba Ingresar y no
                    pasaba absolutamente nada. Sin mensaje, sin recarga, sin
                    pista. La conclusión razonable es que el portal está roto,
                    y la llamada entra por WhatsApp.
                -->
                <div v-if="erroresVisibles.length"
                     class="p-3 rounded-lg bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-sm space-y-1">
                    <p v-for="(mensaje, i) in erroresVisibles" :key="i">{{ mensaje }}</p>
                </div>

                <!-- H3 · form de login (oculto si estamos en reset) -->
                <form v-if="!modoReset" @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-surface-600 dark:text-surface-400 mb-1">Correo</label>
                        <div class="relative">
                            <Mail class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-500"/>
                            <input v-model="form.email" type="email" required autofocus autocomplete="username"
                                class="input w-full pl-10" placeholder="cliente@empresa.com"/>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-surface-600 dark:text-surface-400 mb-1">Contraseña</label>
                        <div class="relative">
                            <Lock class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-500"/>
                            <input v-model="form.password" :type="showPassword ? 'text' : 'password'" required autocomplete="current-password"
                                class="input w-full pl-10 pr-10" placeholder="••••••••"/>
                            <!-- H3 · toggle ojito -->
                            <button type="button" @click="showPassword = !showPassword"
                                class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-surface-500 hover:text-surface-800 dark:hover:text-surface-200"
                                :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'">
                                <EyeOff v-if="showPassword" class="h-4 w-4"/>
                                <Eye v-else class="h-4 w-4"/>
                            </button>
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-400 cursor-pointer">
                            <input v-model="form.remember" type="checkbox" class="rounded border-surface-300"/>
                            Recordarme
                        </label>
                        <button type="button" @click="modoReset = true; resetForm.email = form.email"
                            class="text-xs text-brand-600 hover:underline font-semibold inline-flex items-center gap-1">
                            <KeyRound class="h-3 w-3"/> Olvidé la contraseña
                        </button>
                    </div>
                    <button type="submit" :disabled="form.processing" class="btn-primary w-full">
                        {{ form.processing ? 'Ingresando…' : 'Ingresar' }}
                    </button>
                </form>

                <!-- H3 · panel de reset -->
                <div v-else class="space-y-4">
                    <div class="text-sm text-surface-600 dark:text-surface-300">
                        Te enviaremos un correo con las instrucciones para recuperar tu contraseña.
                    </div>
                    <div v-if="resetForm.enviado" class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 border-l-4 border-emerald-500 text-emerald-800 dark:text-emerald-200 text-sm">
                        Si el correo existe, en unos minutos recibirás un enlace de recuperación.
                    </div>
                    <template v-else>
                        <div>
                            <label class="block text-xs font-semibold text-surface-600 dark:text-surface-400 mb-1">Correo</label>
                            <div class="relative">
                                <Mail class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-500"/>
                                <input v-model="resetForm.email" type="email" required autofocus
                                    class="input w-full pl-10" placeholder="cliente@empresa.com"/>
                            </div>
                        </div>
                        <div v-if="resetForm.error" class="text-xs text-red-600">{{ resetForm.error }}</div>
                        <button @click="enviarReset" :disabled="resetForm.processing || !resetForm.email" class="btn-primary w-full">
                            {{ resetForm.processing ? 'Enviando…' : 'Enviar instrucciones' }}
                        </button>
                    </template>
                    <button type="button" @click="modoReset = false; resetForm.enviado = false; resetForm.error = ''"
                        class="w-full text-xs text-surface-500 hover:text-brand-600 font-semibold">
                        ← Volver al login
                    </button>
                </div>

                <div class="text-center text-xs text-surface-500 pt-4 border-t border-surface-200 dark:border-surface-800">
                    ¿Sin acceso? Comunícate al WhatsApp comercial.
                </div>
            </div>
        </div>
    </div>
</template>
