<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { FileText, User, Truck, Package, Boxes, Zap, Search, Command } from 'lucide-vue-next';
import { useEscClose } from '@/composables/useEscClose';

const abierta = ref(false);
useEscClose(abierta);
const q = ref('');
const resultados = ref([]);
const cursor = ref(0);
const buscando = ref(false);
const inputRef = ref(null);

const iconMap = { FileText, User, Truck, Package, Boxes, Zap };

const toggle = () => {
    abierta.value = !abierta.value;
    if (abierta.value) {
        q.value = '';
        resultados.value = [];
        cursor.value = 0;
        nextTick(() => inputRef.value?.focus());
    }
};
const cerrar = () => { abierta.value = false; };

// Atajo global Ctrl+K / Cmd+K
useEventListener(typeof window !== 'undefined' ? window : null, 'keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        toggle();
    } else if (e.key === 'Escape' && abierta.value) {
        cerrar();
    }
});

// Debounced search
let deb;
watch(q, (v) => {
    clearTimeout(deb);
    if (!v || v.length < 2) { resultados.value = []; return; }
    buscando.value = true;
    deb = setTimeout(async () => {
        try {
            const res = await fetch('/app/buscar?q=' + encodeURIComponent(v), {
                headers: { 'Accept': 'application/json' },
            });
            const data = await res.json();
            resultados.value = data.resultados || [];
            cursor.value = 0;
        } catch (_) {
            resultados.value = [];
        } finally {
            buscando.value = false;
        }
    }, 200);
});

const irA = (r) => {
    cerrar();
    router.visit(r.url);
};

const onKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); cursor.value = Math.min(cursor.value + 1, resultados.value.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); cursor.value = Math.max(cursor.value - 1, 0); }
    else if (e.key === 'Enter' && resultados.value[cursor.value]) { e.preventDefault(); irA(resultados.value[cursor.value]); }
};

defineExpose({ toggle });
</script>

<template>
    <!-- Botón flotante para el trigger manual (no requerido, para descubribilidad) -->
    <button v-if="!abierta" @click="toggle" class="hidden md:flex items-center gap-2 bg-surface-100 hover:bg-surface-200 dark:bg-surface-800 dark:hover:bg-surface-700 rounded-lg px-3 py-1.5 text-sm text-surface-600">
        <Search class="h-4 w-4"/>
        <span>Buscar en el ERP</span>
        <kbd class="text-xs px-1.5 py-0.5 rounded bg-white dark:bg-surface-900 text-surface-600 dark:text-surface-400 border border-surface-200 dark:border-surface-700">⌘K</kbd>
    </button>

    <!-- Overlay + palette -->
    <div v-if="abierta" @click.self="cerrar" class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-start justify-center pt-24 px-4">
        <div class="bg-white dark:bg-surface-900 rounded-xl shadow-2xl max-w-2xl w-full overflow-hidden">
            <div class="flex items-center gap-3 border-b border-surface-200 dark:border-surface-800 px-4 py-3">
                <Command class="h-5 w-5 text-brand-600"/>
                <input ref="inputRef" v-model="q" @keydown="onKey" type="text"
                    placeholder="Busca facturas, contactos, guías, pedidos, productos…"
                    class="flex-1 bg-transparent border-0 focus:ring-0 text-base p-0 outline-none"/>
                <kbd class="text-xs px-1.5 py-0.5 rounded bg-surface-100 dark:bg-surface-800 text-surface-600">ESC</kbd>
            </div>
            <div class="max-h-96 overflow-y-auto">
                <div v-if="buscando" class="p-4 text-center text-sm text-surface-500">Buscando…</div>
                <div v-else-if="!q || q.length < 2" class="p-6 text-center text-xs text-surface-500">
                    Escribe al menos 2 caracteres. Usa ↑↓ para navegar, Enter para abrir.
                </div>
                <div v-else-if="!resultados.length" class="p-6 text-center text-sm text-surface-500">Sin resultados para "{{ q }}"</div>
                <div v-else>
                    <button v-for="(r, i) in resultados" :key="i" @click="irA(r)"
                        @mouseenter="cursor = i"
                        :class="['w-full text-left px-4 py-3 flex items-center gap-3 border-b border-surface-100 dark:border-surface-800 last:border-b-0',
                            cursor === i ? 'bg-brand-50 dark:bg-brand-900/30' : 'hover:bg-surface-50 dark:hover:bg-surface-800']">
                        <component :is="iconMap[r.icon] || FileText" class="h-4 w-4 text-brand-600 flex-shrink-0"/>
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-sm">{{ r.label }}</div>
                            <div v-if="r.sub" class="text-xs text-surface-500 truncate">{{ r.sub }}</div>
                        </div>
                        <span class="text-[10px] uppercase font-bold text-surface-400">{{ r.tipo }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
