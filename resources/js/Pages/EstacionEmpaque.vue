<script setup>
import { Head, router, usePage, useForm } from '@inertiajs/vue3';
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import { useIntervalFn, useEventListener } from '@vueuse/core';
import {
    Camera, CheckCircle, XCircle, Maximize2, Volume2, VolumeX, Scan,
} from 'lucide-vue-next';

import AppLayout from '@/Layouts/AppLayout.vue';
import CameraCapture from '@/Components/CameraCapture.vue';
import RankingTable from '@/Components/RankingTable.vue';
import { useSonido } from '@/composables/useSonido';
import { useConfetti } from '@/composables/useConfetti';
import { obtenerUbicacion } from '@/composables/useUbicacion';

const props = defineProps({
    pedidoActivo: { type: Object, default: null },
    registroActivo: { type: Object, default: null },
    metricas: { type: Object, required: true },
    ranking: { type: Array, required: true },
    colaProximos: { type: Array, required: true },
});

const page = usePage();
const { beep, hablar, mute, toggleMute } = useSonido();
const { dispararConfetti } = useConfetti();

// Formulario de escaneo
const form = useForm({ codigo: '' });
const scanInput = ref(null);
const modalCamara = ref(false);

// Reloj vivo
const hora = ref(new Date().toLocaleTimeString('es-CO'));
useIntervalFn(() => { hora.value = new Date().toLocaleTimeString('es-CO'); }, 1000);

// Estado visual de flash del panel
const flash = ref(null);
const doFlash = (color) => {
    flash.value = color;
    setTimeout(() => { flash.value = null; }, 500);
};

// Reproducir efectos (beep/voz/confetti) del flash empaqueResultado.
// IMPORTANTE: con preserveState:false el componente se remonta en cada POST,
// por eso NO usamos watch (que se re-instala y pierde la transición).
// En su lugar procesamos el flash en onMounted — el pageId asegura no repetir.
const procesarFlash = (r) => {
    if (! r) return;
    beep(r.sonido);
    doFlash(r.sonido);

    if (r.mensaje?.startsWith('✅')) {
        hablar(r.mensaje.replace(/[✅✓]/g, '').trim());
        if (r.confeti) dispararConfetti();
    } else if (r.sonido === 'ok' && r.mensaje?.includes('abierto')) {
        hablar('Pedido abierto');
    } else if (r.sonido === 'error') {
        hablar('Error');
    }
};
// Guard: solo procesar cada flash una vez (aunque el component se remonte).
onMounted(() => {
    const flash = page.props.flash?.empaqueResultado;
    if (! flash) return;
    // Identificador único del flash (mensaje + sonido) — sessionStorage per-request.
    const sig = JSON.stringify(flash);
    const key = 'gb.estacion.lastFlash';
    let last = null;
    try { last = sessionStorage.getItem(key); } catch (e) {}
    if (last === sig) return;  // ya lo procesamos
    try { sessionStorage.setItem(key, sig); } catch (e) {}
    procesarFlash(flash);
});

// Anti-duplicado por código idéntico en rápida sucesión (200ms) — pistola con doble Enter.
let ultimoScan = { codigo: '', ts: 0 };
const escanear = () => {
    const codigo = form.codigo.replace(/\s+/g, '').toUpperCase();
    if (! codigo) return;
    const ahora = Date.now();
    if (codigo === ultimoScan.codigo && ahora - ultimoScan.ts < 200) {
        form.reset('codigo');
        return;
    }
    ultimoScan = { codigo, ts: ahora };
    form.codigo = codigo;
    form.post('/app/estacion-empaque/escanear', {
        preserveScroll: true,
        // preserveState: false fuerza a re-crear el componente con las props frescas
        // (sin esto Vue mantenía snapshot viejo del pedidoActivo tras cada scan).
        preserveState: false,
        onSuccess: () => { form.reset('codigo'); nextTick(() => scanInput.value?.focus()); },
    });
};

// Guards contra doble-click / doble-Enter en botones críticos.
const procesandoFoto = ref(false);
const procesandoConfirmar = ref(false);
const procesandoCancelar = ref(false);

const abrirCamara = () => {
    if (! props.pedidoActivo) {
        beep('warn');
        return;
    }
    modalCamara.value = true;
};
const guardarFoto = (dataUri) => {
    if (procesandoFoto.value) return;
    if (! props.pedidoActivo) {
        modalCamara.value = false;
        beep('warn');
        return;
    }
    procesandoFoto.value = true;
    router.post('/app/estacion-empaque/foto', { dataUri }, {
        preserveScroll: true,
        preserveState: false,
        onFinish: () => { procesandoFoto.value = false; },
    });
};

// Si el pedido activo desaparece (confirmado/cancelado desde otro lugar), cerrar el modal
watch(() => props.pedidoActivo, (p) => { if (! p) modalCamara.value = false; });

const confirmar = async () => {
    if (procesandoConfirmar.value) return;
    procesandoConfirmar.value = true;
    // Capa de seguridad: desde dónde se confirmó el empaque (ver Trazabilidad).
    const gps = await obtenerUbicacion();
    router.post('/app/estacion-empaque/confirmar', { gps }, {
        preserveScroll: true,
        preserveState: false,
        onFinish: () => { procesandoConfirmar.value = false; },
    });
};
// Modal Vue (no window.confirm — evita robar el foco a la pistola en tablets)
const modalCancelar = ref(false);
const modalCancelarBtn = ref(null);
const cancelar = () => {
    if (procesandoCancelar.value) return;
    modalCancelar.value = true;
    // Enfocar el botón "Seguir empacando" al abrir → ESC funciona
    nextTick(() => modalCancelarBtn.value?.focus());
};
// ESC global cierra cualquier modal abierto (fix: el div fixed no recibía keydown).
useEventListener(typeof window !== 'undefined' ? window : null, 'keydown', (e) => {
    if (e.key === 'Escape') {
        if (modalCancelar.value) modalCancelar.value = false;
        if (modalCamara.value) modalCamara.value = false;
    }
});
const confirmarCancelar = () => {
    modalCancelar.value = false;
    procesandoCancelar.value = true;
    router.post('/app/estacion-empaque/cancelar', {}, {
        preserveScroll: true,
        preserveState: false,
        onFinish: () => {
            procesandoCancelar.value = false;
            nextTick(() => scanInput.value?.focus());
        },
    });
};

const toggleFullscreen = () => {
    const el = document.documentElement;
    const inFullscreen = document.fullscreenElement || document.webkitFullscreenElement;
    if (inFullscreen) {
        (document.exitFullscreen || document.webkitExitFullscreen).call(document);
    } else {
        const fn = el.requestFullscreen || el.webkitRequestFullscreen;
        if (fn) fn.call(el);
    }
};

// Autofocus permanente al input scanner (NO cuando hay modales abiertos —
// evita robar foco al modal de cancelar y disparar Escape imposible + escaneos accidentales).
onMounted(() => scanInput.value?.focus());
useIntervalFn(() => {
    if (modalCamara.value || modalCancelar.value) return;
    if (document.activeElement !== scanInput.value && scanInput.value) {
        scanInput.value.focus();
    }
}, 2000);

// Progreso
const progreso = computed(() => {
    if (! props.pedidoActivo?.items?.length) return { pickados: 0, total: 0, pct: 0 };
    const total = props.pedidoActivo.items.reduce((a, i) => a + i.cantidad, 0);
    const pickados = props.pedidoActivo.items.reduce((a, i) => a + i.cantidad_pickeada, 0);
    return { pickados, total, pct: total > 0 ? Math.round((pickados / total) * 100) : 0 };
});

const flashClass = computed(() => {
    if (flash.value === 'ok') return 'ring-4 ring-emerald-500/50';
    if (flash.value === 'warn') return 'ring-4 ring-amber-500/50';
    if (flash.value === 'error') return 'ring-4 ring-red-500/50';
    return '';
});

const fmtTime = (s) => {
    if (!s) return '—';
    return `${String(Math.floor(s/60)).padStart(2,'0')}:${String(s%60).padStart(2,'0')}`;
};
</script>

<template>
    <Head title="Estación de Empaque"/>
    <AppLayout>
        <div class="space-y-4">
            <!-- Panel superior: escáner + métricas -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <!-- Escáner (2 cols) -->
                <div :class="['card p-5 bg-gradient-to-br from-slate-900 to-slate-800 border-brand-700 transition-all duration-300 lg:col-span-2', flashClass]">
                    <div class="flex items-center justify-between mb-3">
                        <h1 class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-brand-500">
                            <Scan class="h-4 w-4"/> Estación de Empaque
                        </h1>
                        <div class="flex items-center gap-2">
                            <div class="text-sm text-slate-400 font-mono">{{ hora }}</div>
                            <button @click="toggleMute" class="p-1.5 border border-brand-700/50 rounded hover:bg-brand-950" :title="mute ? 'Activar sonidos' : 'Silenciar'">
                                <VolumeX v-if="mute" class="h-4 w-4 text-slate-500"/>
                                <Volume2 v-else class="h-4 w-4 text-brand-500"/>
                            </button>
                            <button @click="toggleFullscreen" class="p-1.5 border border-brand-700/50 rounded text-brand-500 hover:bg-brand-950" title="Pantalla completa">
                                <Maximize2 class="h-4 w-4"/>
                            </button>
                        </div>
                    </div>
                    <form @submit.prevent="escanear">
                        <input ref="scanInput"
                               v-model="form.codigo"
                               type="text"
                               autocomplete="off"
                               placeholder="Escanea guía o código de variante…"
                               class="w-full px-5 py-4 text-2xl font-bold font-mono tracking-widest bg-slate-950 text-brand-500 border-2 border-brand-700 rounded-xl focus:outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/20 placeholder:text-brand-800/60"/>
                    </form>

                    <!-- Resultado del último escaneo -->
                    <transition enter-active-class="animate-slide-in">
                        <div v-if="$page.props.flash?.empaqueResultado" class="mt-3 px-4 py-2 rounded font-semibold border-l-4"
                             :class="{
                                 'bg-emerald-500/15 text-emerald-400 border-emerald-500': $page.props.flash.empaqueResultado.sonido === 'ok',
                                 'bg-amber-500/15 text-amber-400 border-amber-500': $page.props.flash.empaqueResultado.sonido === 'warn',
                                 'bg-red-500/15 text-red-400 border-red-500': $page.props.flash.empaqueResultado.sonido === 'error',
                             }">
                            {{ $page.props.flash.empaqueResultado.mensaje }}
                        </div>
                    </transition>
                </div>

                <!-- Métricas -->
                <div class="grid grid-rows-2 gap-3">
                    <div class="card p-4 border-l-4 border-l-emerald-500 bg-emerald-500/5">
                        <div class="text-xs uppercase tracking-wider text-surface-500 font-medium">Empacados hoy</div>
                        <div class="text-4xl font-black text-emerald-600 leading-none mt-1 tabular-nums">{{ metricas.mis_hoy }}</div>
                        <div class="text-xs text-surface-500 mt-1">Promedio: {{ fmtTime(metricas.prom_seg) }}</div>
                    </div>
                    <div class="card p-4 border-l-4 border-l-blue-500 bg-blue-500/5">
                        <div class="text-xs uppercase tracking-wider text-surface-500 font-medium">Mejor tiempo hoy</div>
                        <div class="text-3xl font-black text-blue-600 leading-tight mt-1 font-mono tabular-nums">{{ fmtTime(metricas.mejor_seg) }}</div>
                        <div class="text-xs text-surface-500 mt-1">min:seg</div>
                    </div>
                </div>
            </div>

            <!-- Pedido activo -->
            <div v-if="pedidoActivo" class="card p-5 border-2 border-brand-500/40 bg-brand-500/5">
                <div class="flex items-start justify-between flex-wrap gap-3">
                    <div>
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-500">📦 Pedido activo</div>
                        <div class="text-2xl font-black text-brand-600 font-mono">{{ pedidoActivo.guia }}</div>
                        <div class="text-sm text-surface-700 dark:text-surface-300 mt-1">{{ pedidoActivo.cliente }} · {{ pedidoActivo.ciudad }}</div>
                        <div class="text-xs text-surface-500">{{ pedidoActivo.transportadora }} · Corte {{ pedidoActivo.corte }}</div>
                    </div>
                    <div class="flex gap-2 flex-wrap items-center">
                        <button @click="cancelar" :disabled="procesandoCancelar" class="btn-secondary disabled:opacity-50" aria-label="Cancelar pedido">Cancelar</button>
                        <span v-if="registroActivo?.foto_path" class="badge-success px-3 py-1.5">📸 Foto capturada</span>
                        <button @click="abrirCamara" :disabled="procesandoFoto" class="btn-primary disabled:opacity-50" aria-label="Tomar foto del paquete">
                            <Camera class="h-4 w-4"/> {{ registroActivo?.foto_path ? 'Cambiar foto' : 'Foto paquete' }}
                        </button>
                        <button @click="confirmar" :disabled="procesandoConfirmar" class="px-5 py-2.5 rounded-lg font-bold bg-gradient-to-r from-emerald-500 to-emerald-700 text-white shadow-lg shadow-emerald-500/30 hover:shadow-emerald-500/50 transition disabled:opacity-50 disabled:cursor-not-allowed" aria-label="Confirmar empaque">
                            <span v-if="procesandoConfirmar">⏳ Procesando…</span>
                            <span v-else>✅ CONFIRMAR EMPAQUE</span>
                        </button>
                    </div>
                </div>

                <!-- Progreso -->
                <div class="mt-4">
                    <div class="flex justify-between text-xs text-surface-500 mb-1">
                        <span>Progreso de escaneo</span>
                        <span><strong class="text-emerald-600">{{ progreso.pickados }}/{{ progreso.total }}</strong></span>
                    </div>
                    <div class="h-2 bg-surface-200 dark:bg-surface-800 rounded-full overflow-hidden">
                        <div class="h-full bg-gradient-to-r from-emerald-500 to-brand-500 transition-all duration-500"
                             :style="{ width: progreso.pct + '%' }"></div>
                    </div>
                </div>

                <!-- Ítems -->
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <div v-for="it in pedidoActivo.items" :key="it.id"
                         :class="['p-3 rounded-lg border-l-4',
                             it.completo ? 'border-l-emerald-500 bg-emerald-500/10'
                             : it.es_agregado ? 'border-l-amber-500 bg-amber-500/10'
                             : 'border-l-surface-500 bg-surface-100 dark:bg-surface-900']">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <div class="font-semibold text-surface-800 dark:text-surface-100 truncate">{{ it.producto }}</div>
                                    <!-- Fix ALTO UX/UI re-audit · badge amber para item agregado (colores surtidos).
                                         Antes el alistador no lo distinguía y buscaba color específico. -->
                                    <span v-if="it.es_agregado" class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-amber-500 text-white uppercase tracking-wide">Surtido</span>
                                </div>
                                <div class="text-xs" :class="it.es_agregado ? 'text-amber-700 dark:text-amber-300 font-medium' : 'text-surface-500'">
                                    <span v-if="it.es_agregado">Colores surtidos según disponibilidad</span>
                                    <template v-else>{{ it.color }}<span v-if="it.talla"> · T{{ it.talla }}</span></template>
                                </div>
                                <div class="font-mono text-xs text-surface-400 mt-1 truncate">{{ it.codigo }}</div>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <div class="text-lg font-bold text-brand-600">×{{ it.cantidad }}</div>
                                <div class="text-2xl">
                                    <CheckCircle v-if="it.completo" class="h-6 w-6 text-emerald-600 inline"/>
                                    <span v-else class="text-surface-400">⏳</span>
                                </div>
                                <div v-if="it.cantidad > 1 && !it.completo" class="text-xs text-surface-500">
                                    {{ it.cantidad_pickeada }}/{{ it.cantidad }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Estado vacío -->
            <div v-else class="card p-12 text-center border-2 border-dashed">
                <div class="text-5xl">📦</div>
                <div class="text-lg text-surface-500 mt-3">Escanea una guía de pedido con la pistola para empezar</div>
                <div class="text-xs text-surface-500 mt-1">o escribe el número y presiona Enter</div>
            </div>

            <!-- Cola sugerida -->
            <div v-if="colaProximos.length" class="card p-4 border border-blue-500/25 bg-blue-500/5">
                <div class="text-xs uppercase tracking-widest font-bold text-blue-600 mb-2">
                    🎯 Cola sugerida ({{ colaProximos.length }})
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                    <div v-for="q in colaProximos" :key="q.id"
                         class="p-2 bg-surface-100 dark:bg-black/40 border-l-2 border-blue-500 rounded">
                        <div class="font-mono font-bold text-blue-700 dark:text-blue-300 text-sm">{{ q.guia }}</div>
                        <div class="text-xs text-surface-700 dark:text-surface-300 truncate">{{ q.cliente }}</div>
                        <div class="text-xs text-surface-500">{{ q.ciudad }} · {{ q.items }} ítems</div>
                    </div>
                </div>
            </div>
            <div v-else-if="!pedidoActivo" class="card p-4 border border-emerald-500/30 bg-emerald-500/5 text-center">
                <div class="text-2xl">🎉</div>
                <div class="text-sm text-emerald-700 dark:text-emerald-300 font-semibold">¡Todo al día!</div>
                <div class="text-xs text-surface-500 mt-1">No hay pedidos pendientes por empacar. Buen trabajo.</div>
            </div>

            <!-- Ranking -->
            <div v-if="ranking.length" class="card p-4">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">🏆 Ranking del día</div>
                <RankingTable :filas="ranking"/>
            </div>
        </div>

        <!-- Modal cámara -->
        <CameraCapture :open="modalCamara" @close="modalCamara = false" @captured="guardarFoto"/>

        <!-- Modal Cancelar (no window.confirm — no roba foco a la pistola) -->
        <div v-if="modalCancelar" role="dialog" aria-modal="true"
             class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4"
             @click.self="modalCancelar = false" @keydown.escape="modalCancelar = false">
            <div class="card p-6 max-w-md w-full">
                <div class="text-2xl mb-2">⚠</div>
                <div class="text-lg font-bold text-surface-900 dark:text-surface-100">Descartar pedido {{ pedidoActivo?.guia }}</div>
                <div class="text-sm text-surface-600 dark:text-surface-400 mt-2">Se perderá todo el progreso de escaneo y la cola liberará el pedido.</div>
                <div class="flex gap-2 mt-5">
                    <button @click="modalCancelar = false" ref="modalCancelarBtn" class="btn-secondary flex-1">Seguir empacando</button>
                    <button @click="confirmarCancelar" class="btn-primary bg-red-600 hover:bg-red-700 flex-1">Sí, descartar</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
