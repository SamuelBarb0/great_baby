<script setup>
/*
 * Donde el empleado pone su contraseña por primera vez.
 *
 * No usa el layout de la app porque todavía no tiene sesión: entra con el
 * enlace que Gestión Humana le mandó por WhatsApp.
 */
import { ref, reactive, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({ token: { type: String, default: '' } });

const procesando = ref(false);
const errores = ref({});
const form = reactive({ password: '', password_confirmation: '' });

const coincide = computed(() =>
    form.password.length >= 8 && form.password === form.password_confirmation);

const activar = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post('/app/activar-portal', { token: props.token, ...form }, {
        onError: (e) => (errores.value = e),
        onFinish: () => (procesando.value = false),
    });
};
</script>

<template>
    <Head title="Activar mi acceso" />
    <div class="min-h-screen bg-surface-50 dark:bg-surface-950 flex items-center justify-center p-4">
        <div class="card p-7 max-w-md w-full">
            <div class="text-center mb-5">
                <div class="h-12 w-12 rounded-xl bg-brand-600 text-white font-black text-lg
                            flex items-center justify-center mx-auto mb-3">GB</div>
                <h1 class="text-xl font-bold">Activá tu acceso</h1>
                <p class="text-sm text-surface-500 mt-1">
                    Poné una contraseña para entrar a tu espacio en Great Baby.
                </p>
            </div>

            <div v-if="errores.token" class="p-3 rounded-lg bg-rose-500/10 border-l-4 border-rose-500 text-rose-700 text-sm mb-4">
                {{ errores.token }}
            </div>

            <div class="space-y-3">
                <div>
                    <label class="text-xs font-semibold">Contraseña</label>
                    <input type="password" v-model="form.password" class="input w-full" autofocus
                        @keyup.enter="coincide && activar()" />
                    <p class="text-[10px] text-surface-400 mt-0.5">Mínimo 8 caracteres.</p>
                    <p v-if="errores.password" class="text-xs text-rose-600 mt-1">{{ errores.password }}</p>
                </div>
                <div>
                    <label class="text-xs font-semibold">Repetila</label>
                    <input type="password" v-model="form.password_confirmation" class="input w-full"
                        @keyup.enter="coincide && activar()" />
                    <p v-if="form.password_confirmation && !coincide" class="text-xs text-amber-600 mt-1">
                        Todavía no coinciden.
                    </p>
                </div>
            </div>

            <button @click="activar" :disabled="procesando || !coincide" class="btn-primary w-full mt-5 justify-center">
                {{ procesando ? 'Guardando...' : 'Activar mi acceso' }}
            </button>
        </div>
    </div>
</template>
