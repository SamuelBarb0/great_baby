<script setup>
/**
 * Marco de las pantallas de Dropi que viven en Vue (la carga de productos y
 * stock a Dropi). 23-nov-2026: «que no se me vaya al ERP principal».
 *
 * Con AppLayout la persona veía el menú entero del ERP y de ahí saltaba a
 * Great Baby sin darse cuenta. Este marco sólo tiene el regreso al panel de
 * Dropi (/admin) y los avisos del servidor, con la misma cola de toasts.
 */
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { ArrowLeft } from 'lucide-vue-next';

const page = usePage();
const toasts = ref([]);
let seq = 0;

const TONOS = {
    success: { clase: 'bg-emerald-600', icono: '✓' },
    warning: { clase: 'bg-amber-600', icono: '!' },
    info: { clase: 'bg-brand-600', icono: 'i' },
    error: { clase: 'bg-red-600', icono: '⚠' },
};

const mostrar = (mensaje, tono = 'success') => {
    const id = ++seq;
    toasts.value.push({ id, mensaje, tono });
    setTimeout(() => { toasts.value = toasts.value.filter((t) => t.id !== id); }, tono === 'success' ? 4000 : 8000);
};
const cerrar = (id) => { toasts.value = toasts.value.filter((t) => t.id !== id); };

const ventana = typeof window !== 'undefined' ? window : null;
useEventListener(ventana, 'gb:error', (e) => mostrar(e.detail?.mensaje || 'Error', 'error'));
useEventListener(ventana, 'gb:aviso', (e) => mostrar(e.detail?.mensaje || '', e.detail?.tono || 'info'));

watch(() => page.props.flash, (flash) => {
    if (! flash) return;
    ['success', 'warning', 'info', 'error'].forEach((tono) => { if (flash[tono]) mostrar(flash[tono], tono); });
}, { immediate: true, deep: true });
</script>

<template>
    <div class="min-h-screen bg-surface-50 dark:bg-surface-950">
        <header class="sticky top-0 z-30 border-b border-surface-200 dark:border-surface-800 bg-white/90 dark:bg-surface-900/90 backdrop-blur">
            <div class="flex items-center gap-3 px-6 h-14">
                <a href="/admin" class="btn btn-ghost text-sm inline-flex items-center gap-1">
                    <ArrowLeft class="h-4 w-4"/> Volver a Dropi
                </a>
                <span class="rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 px-2 py-0.5 text-xs font-semibold">Modo: DROPI</span>
                <span class="font-semibold text-sm">GREAT BABY · ERP</span>
            </div>
        </header>
        <main class="p-6 overflow-x-auto">
            <slot/>
        </main>

        <div class="fixed bottom-6 right-6 z-50 space-y-2 max-w-sm w-full pointer-events-none">
            <div v-for="t in toasts" :key="t.id" role="alert"
                 :class="['pointer-events-auto px-4 py-3 rounded-lg shadow-2xl text-white text-sm font-medium flex items-center gap-3', (TONOS[t.tono] || TONOS.error).clase]">
                <span class="text-lg" aria-hidden="true">{{ (TONOS[t.tono] || TONOS.error).icono }}</span>
                <span class="flex-1">{{ t.mensaje }}</span>
                <button class="text-white/70 hover:text-white text-lg" aria-label="Cerrar" @click="cerrar(t.id)">×</button>
            </div>
        </div>
    </div>
</template>
