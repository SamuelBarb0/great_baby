<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import { LogIn, CircleCheck } from 'lucide-vue-next';

/*
 * El mensaje que trae quien llega redirigido.
 *
 * Dos caminos terminan acá con algo que decir —el empleado que acaba de
 * activar su acceso y quien acaba de restablecer su contraseña— y hasta hoy
 * ninguno de los dos se veía: la pantalla salía igual que siempre y la
 * persona no sabía si lo que hizo había servido.
 */
defineProps({
    flash: { type: Object, default: () => ({}) },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => form.post('/app/login', {
    onFinish: () => form.reset('password'),
});
</script>

<template>
    <Head title="Iniciar sesión"/>
    <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-surface-100 via-white to-brand-50 dark:from-surface-950 dark:to-surface-900 p-4">
        <div class="w-full max-w-md">
            <!-- Brand -->
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
                <div v-if="flash?.success"
                     class="mb-5 rounded-lg border-l-4 border-emerald-500 bg-emerald-50 dark:bg-emerald-950/40 p-3 flex items-start gap-2">
                    <CircleCheck class="h-4 w-4 text-emerald-600 flex-shrink-0 mt-0.5"/>
                    <p class="text-sm text-emerald-900 dark:text-emerald-200">{{ flash.success }}</p>
                </div>

                <h1 class="text-xl font-bold text-surface-900 dark:text-surface-100 mb-1">Bienvenido de vuelta</h1>
                <p class="text-sm text-surface-500 mb-6">Ingresá con tu correo y contraseña</p>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="label">Correo</label>
                        <input v-model="form.email" type="email" required autofocus autocomplete="username" class="input" placeholder="aracely@greatbaby.com"/>
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label class="label">Contraseña</label>
                        <input v-model="form.password" type="password" required autocomplete="current-password" class="input"/>
                        <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                    </div>
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <label class="flex items-center gap-2 text-sm text-surface-700 dark:text-surface-300">
                            <input v-model="form.remember" type="checkbox" class="rounded border-surface-300 text-brand-600 focus:ring-brand-500"/>
                            Recordarme
                        </label>
                        <!-- Hasta hoy no había salida: quien olvidaba la clave tenía
                             que escribirle a alguien para que se la cambiara a mano. -->
                        <Link href="/app/olvide-clave" class="text-sm text-brand-600 hover:underline">
                            Olvidé mi contraseña
                        </Link>
                    </div>

                    <button type="submit" :disabled="form.processing" class="btn-primary w-full py-2.5">
                        <LogIn class="h-4 w-4"/>
                        <span v-if="form.processing">Ingresando…</span>
                        <span v-else>Ingresar</span>
                    </button>
                </form>
            </div>

            <p class="text-center text-xs text-surface-500 mt-6">
                © 2026 MyTech Solutions · Desarrollado para GREAT BABY S.A.S.
            </p>
        </div>
    </div>
</template>
