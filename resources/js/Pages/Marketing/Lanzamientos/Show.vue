<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Rocket, ArrowLeft, CheckSquare, User, CalendarDays, AlertTriangle, Check, X, MessageSquare, Lock } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Tablero de lanzamiento de un contenedor: 5 listas y 10 tarjetas.
 * Las tarjetas se mueven arrastrándolas o desde su ventana; el servidor aplica
 * las reglas (checklist completo, bloqueo con motivo, aprobación de Aracely) y
 * si un movimiento no se puede, avisa y la tarjeta se queda donde estaba.
 */
const props = defineProps({
    lanzamiento: { type: Object, required: true },
    tarjetas: { type: Array, required: true },
    listas: { type: Object, required: true },
    puede_aprobar: Boolean,
    compras: { type: Object, default: null },
    usuarios: { type: Array, required: true },
});

const COLORES = {
    por_hacer: 'bg-emerald-600',
    en_proceso: 'bg-amber-500',
    pendiente_aprobacion: 'bg-violet-600',
    bloqueado: 'bg-red-600',
    finalizado: 'bg-emerald-700',
};
const FONDOS = {
    por_hacer: 'bg-emerald-500/5',
    en_proceso: 'bg-amber-500/5',
    pendiente_aprobacion: 'bg-violet-500/5',
    bloqueado: 'bg-red-500/5',
    finalizado: 'bg-emerald-500/5',
};

const porLista = computed(() => Object.fromEntries(
    Object.keys(props.listas).map(l => [l, props.tarjetas.filter(t => t.lista === l)]),
));
const hechos = (t) => t.items.filter(i => i.hecho).length;

// ─── ventana de la tarjeta ───
const abiertaId = ref(null);
const abierta = computed(() => props.tarjetas.find(t => t.id === abiertaId.value) || null);
const datos = reactive({ responsable_id: null, vence_en: '' });
const abrir = (t) => {
    abiertaId.value = t.id;
    datos.responsable_id = t.responsable?.id ?? null;
    datos.vence_en = t.vence_en ?? '';
    comentario.value = '';
    bloqueo.activo = false;
    devolucion.activo = false;
};
const opciones = { preserveScroll: true, preserveState: true };

const marcar = (item, hecho) => router.post(`/app/marketing/lanzamientos/item/${item.id}`, { hecho }, opciones);
const guardarDatos = () => router.put(`/app/marketing/lanzamientos/tarjeta/${abierta.value.id}`,
    { responsable_id: datos.responsable_id, vence_en: datos.vence_en || null }, opciones);
const mover = (t, lista, extra = {}) => router.post(`/app/marketing/lanzamientos/tarjeta/${t.id}/mover`, { lista, ...extra }, opciones);

// Bloquear exige motivo, quién lo resuelve y la acción.
const bloqueo = reactive({ activo: false, tarjeta: null, motivo: '', responsable_id: null, accion: '' });
const pedirBloqueo = (t) => {
    abrir(t);
    Object.assign(bloqueo, { activo: true, tarjeta: t, motivo: '', responsable_id: null, accion: '' });
};
const confirmarBloqueo = () => mover(bloqueo.tarjeta, 'bloqueado', {
    motivo: bloqueo.motivo, responsable_id: bloqueo.responsable_id, accion: bloqueo.accion,
});

const aprobar = (t) => router.post(`/app/marketing/lanzamientos/tarjeta/${t.id}/aprobar`, {}, opciones);
const devolucion = reactive({ activo: false, cambios: '' });
const devolver = (t) => router.post(`/app/marketing/lanzamientos/tarjeta/${t.id}/devolver`, { cambios: devolucion.cambios }, {
    ...opciones, onSuccess: () => { devolucion.activo = false; devolucion.cambios = ''; },
});

const comentario = ref('');
const comentar = () => router.post(`/app/marketing/lanzamientos/tarjeta/${abierta.value.id}/comentario`, { cuerpo: comentario.value }, {
    ...opciones, onSuccess: () => { comentario.value = ''; },
});

// ─── arrastrar y soltar ───
const arrastrada = ref(null);
const sobre = ref(null);
const soltar = (lista) => {
    const t = props.tarjetas.find(x => x.id === arrastrada.value);
    arrastrada.value = null;
    sobre.value = null;
    if (!t || t.lista === lista) return;
    if (lista === 'bloqueado') return pedirBloqueo(t);
    mover(t, lista);
};

// Botones de la ventana: a dónde puede ir la tarjeta desde donde está.
const destinos = (t) => {
    if (!t.puede_trabajar || t.lista === 'finalizado' || t.lista === 'pendiente_aprobacion') return [];
    const d = [];
    if (t.lista === 'bloqueado') d.push(['en_proceso', 'Desbloquear']);
    else if (t.lista !== 'en_proceso') d.push(['en_proceso', 'Tomar / En proceso']);
    if (t.lista === 'en_proceso') {
        d.push(['pendiente_aprobacion', 'Enviar a aprobación']);
        if (!t.requiere_aprobacion) d.push(['finalizado', 'Finalizar']);
    }
    return d;
};
const ICONO_COMENTARIO = { movimiento: '↦', bloqueo: '⛔', aprobacion: '✅', devolucion: '↩', comentario: '💬' };
</script>

<template>
    <Head :title="`Lanzamiento ${lanzamiento.numero}`"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <Link href="/app/marketing/lanzamientos" class="text-sm text-surface-500 flex items-center gap-1 hover:text-brand-600"><ArrowLeft class="h-4 w-4"/>Lanzamientos</Link>
                    <h1 class="text-2xl font-bold flex items-center gap-2 mt-1"><Rocket class="h-6 w-6 text-brand-600"/>Lanzamiento · {{ lanzamiento.numero }}</h1>
                    <p class="text-sm text-surface-500">
                        {{ lanzamiento.contenedor || 'Sin número de contenedor' }}<span v-if="lanzamiento.eta"> · ETA {{ lanzamiento.eta }}</span>
                        <span v-if="lanzamiento.finalizado_en" class="text-emerald-700 font-semibold"> · Lanzado el {{ lanzamiento.finalizado_en }}</span>
                    </p>
                </div>
            </div>

            <section v-if="compras" class="card p-3 flex flex-wrap items-center justify-between gap-3 text-sm" aria-label="Desde Compras">
                <div>
                    <b>En Compras:</b> {{ compras.etapa || 'Aún sin etapas marcadas' }}
                    <span v-if="compras.llegada_estimada"> · Llegada estimada a bodega: {{ compras.llegada_estimada }}</span>
                    <span> · Recibidas {{ compras.recibidas.toLocaleString('es-CO') }} de {{ compras.unidades.toLocaleString('es-CO') }} unidades</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link v-if="compras.puede_productos" :href="`/app/marketing/tablero?contenedor=${compras.importacion_id}`" class="btn-ghost text-xs border border-surface-300 dark:border-surface-700">Ver sus productos</Link>
                    <Link v-if="compras.puede_contenedor" :href="`/app/compras/importacion/${compras.importacion_id}`" class="btn-ghost text-xs border border-surface-300 dark:border-surface-700">Ver el contenedor</Link>
                </div>
            </section>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">{{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 text-sm">{{ $page.props.flash.error }}</div>

            <!-- Las 5 listas -->
            <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-start">
                <section v-for="(label, lista) in listas" :key="lista"
                         class="rounded-xl overflow-hidden border border-surface-200 transition"
                         :class="[FONDOS[lista], sobre === lista ? 'ring-2 ring-brand-500' : '']"
                         @dragover.prevent="sobre = lista" @dragleave="sobre = null" @drop.prevent="soltar(lista)">
                    <header class="px-3 py-2 text-white text-sm font-bold flex justify-between" :class="COLORES[lista]">
                        <span>{{ label }}</span><span class="opacity-80">{{ porLista[lista].length }}</span>
                    </header>
                    <div class="p-2 space-y-2 min-h-[120px]">
                        <button v-for="t in porLista[lista]" :key="t.id" type="button"
                                :draggable="t.puede_trabajar && t.lista !== 'finalizado'"
                                @dragstart="arrastrada = t.id" @dragend="arrastrada = null; sobre = null"
                                @click="abrir(t)"
                                class="card p-3 w-full text-left space-y-1 hover:ring-2 hover:ring-brand-300 transition"
                                :class="t.puede_trabajar && t.lista !== 'finalizado' ? 'cursor-grab' : 'cursor-pointer'">
                            <div class="text-sm font-semibold leading-tight" :class="t.lista === 'finalizado' ? 'line-through text-surface-500' : ''">
                                {{ t.numero }}. {{ t.titulo }}
                            </div>
                            <div class="text-xs text-surface-500">{{ t.area_label }}</div>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-surface-500">
                                <span class="flex items-center gap-1"><CheckSquare class="h-3 w-3"/>{{ hechos(t) }}/{{ t.items.length }}</span>
                                <span v-if="t.responsable" class="flex items-center gap-1"><User class="h-3 w-3"/>{{ t.responsable.nombre }}</span>
                                <span v-if="t.vence_en" class="flex items-center gap-1" :class="t.vencida ? 'text-red-600 font-semibold' : ''">
                                    <CalendarDays class="h-3 w-3"/>{{ t.vence_en }}
                                </span>
                                <span v-if="t.requiere_aprobacion && t.lista !== 'finalizado'" class="text-violet-700">requiere aprobación</span>
                            </div>
                            <div v-if="t.bloqueo" class="text-xs text-red-700 bg-red-500/10 rounded p-1.5">{{ t.bloqueo.motivo }}</div>
                        </button>
                    </div>
                </section>
            </div>

            <div class="card p-3 text-xs text-surface-500 grid sm:grid-cols-2 lg:grid-cols-4 gap-2">
                <div>• Cada tarjeta tiene un responsable: quien la toma queda asignado.</div>
                <div>• Solo pasa a Finalizado cuando su checklist está completo.</div>
                <div>• Si requiere aprobación, pasa por Pendiente de aprobación (aprueba Aracely).</div>
                <div>• Si no puede continuar, va a Bloqueado con motivo, responsable y acción.</div>
            </div>
        </div>

        <!-- Ventana de la tarjeta -->
        <div v-if="abierta" @click.self="abiertaId = null" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-5 max-w-2xl w-full max-h-[90vh] overflow-y-auto space-y-4">
                <div class="flex justify-between gap-3">
                    <div>
                        <div class="text-xs text-surface-500">Tarjeta {{ abierta.numero }} · {{ abierta.area_label }}</div>
                        <h3 class="text-lg font-bold">{{ abierta.titulo }}</h3>
                        <span class="inline-block mt-1 text-xs text-white px-2 py-0.5 rounded-full" :class="COLORES[abierta.lista]">{{ listas[abierta.lista] }}</span>
                    </div>
                    <button @click="abiertaId = null" class="btn-ghost p-1 h-fit" aria-label="Cerrar"><X class="h-5 w-5"/></button>
                </div>

                <div class="grid sm:grid-cols-2 gap-3 text-sm">
                    <div><div class="text-xs font-semibold text-surface-500">Objetivo</div>{{ abierta.objetivo }}</div>
                    <div><div class="text-xs font-semibold text-surface-500">Entregable</div>{{ abierta.entregable }}</div>
                </div>

                <div v-if="abierta.bloqueo" class="p-3 rounded-lg bg-red-500/10 border-l-4 border-red-500 text-sm space-y-1">
                    <div class="font-semibold text-red-700 flex items-center gap-1"><AlertTriangle class="h-4 w-4"/>Bloqueada</div>
                    <div><b>Motivo:</b> {{ abierta.bloqueo.motivo }}</div>
                    <div><b>Resuelve:</b> {{ abierta.bloqueo.responsable }}</div>
                    <div><b>Acción requerida:</b> {{ abierta.bloqueo.accion }}</div>
                </div>
                <div v-if="abierta.aprobada" class="text-sm text-emerald-700">✅ Aprobada por {{ abierta.aprobada.por }} el {{ abierta.aprobada.en }}</div>

                <!-- Checklist -->
                <div>
                    <div class="text-sm font-semibold mb-1">Checklist · {{ hechos(abierta) }}/{{ abierta.items.length }}</div>
                    <ul class="space-y-1">
                        <li v-for="i in abierta.items" :key="i.id">
                            <label class="flex items-start gap-2 text-sm"
                                   :class="abierta.puede_trabajar && ['por_hacer', 'en_proceso', 'bloqueado'].includes(abierta.lista) ? 'cursor-pointer' : 'opacity-80'">
                                <input type="checkbox" class="mt-0.5" :checked="i.hecho"
                                       :disabled="!abierta.puede_trabajar || !['por_hacer', 'en_proceso', 'bloqueado'].includes(abierta.lista)"
                                       @change="marcar(i, $event.target.checked)"/>
                                <span>
                                    <span v-if="i.grupo" class="text-xs text-surface-500 mr-1">[{{ i.grupo === 'marketing' ? 'Marketing' : 'Dropshipping' }}]</span>
                                    <span :class="i.hecho ? 'line-through text-surface-500' : ''">{{ i.texto }}</span>
                                    <span v-if="i.hecho" class="block text-xs text-surface-400">{{ i.por }} · {{ i.en }}</span>
                                </span>
                            </label>
                        </li>
                    </ul>
                </div>

                <!-- Responsable y vencimiento -->
                <div class="grid sm:grid-cols-[1fr_1fr_auto] gap-2 items-end">
                    <div>
                        <label class="text-xs font-semibold">Responsable</label>
                        <select v-model="datos.responsable_id" class="input w-full" :disabled="!abierta.puede_trabajar">
                            <option :value="null">Sin asignar</option>
                            <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.nombre }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Vence</label>
                        <input type="date" v-model="datos.vence_en" class="input w-full" :disabled="!abierta.puede_trabajar"/>
                    </div>
                    <button v-if="abierta.puede_trabajar" @click="guardarDatos" class="btn-ghost border border-brand-300 text-sm">Guardar</button>
                </div>

                <!-- Movimientos -->
                <div class="flex flex-wrap gap-2">
                    <button v-for="[lista, texto] in destinos(abierta)" :key="lista" @click="mover(abierta, lista)" class="btn-primary text-sm">{{ texto }}</button>
                    <button v-if="abierta.puede_trabajar && !['finalizado', 'bloqueado', 'pendiente_aprobacion'].includes(abierta.lista)"
                            @click="pedirBloqueo(abierta)" class="btn-ghost border border-red-300 text-red-700 text-sm">Bloquear</button>
                    <template v-if="abierta.lista === 'pendiente_aprobacion'">
                        <template v-if="puede_aprobar">
                            <button @click="aprobar(abierta)" class="btn-primary text-sm"><Check class="h-4 w-4"/>Aprobar</button>
                            <button @click="devolucion.activo = true" class="btn-ghost border border-amber-300 text-amber-700 text-sm">Pedir cambios</button>
                        </template>
                        <span v-else class="text-sm text-violet-700 flex items-center gap-1"><Lock class="h-4 w-4"/>Esperando la aprobación de Aracely</span>
                    </template>
                    <span v-if="!abierta.puede_trabajar && abierta.lista !== 'finalizado'" class="text-sm text-surface-500 flex items-center gap-1">
                        <Lock class="h-4 w-4"/>Esta tarjeta la trabaja {{ abierta.area_label }}
                    </span>
                </div>

                <!-- Formulario de bloqueo -->
                <div v-if="bloqueo.activo" class="p-3 rounded-lg border border-red-300 space-y-2">
                    <div class="text-sm font-semibold text-red-700">Bloquear tarjeta</div>
                    <div><label class="text-xs font-semibold">Motivo del bloqueo</label><textarea v-model="bloqueo.motivo" rows="2" class="input w-full"></textarea></div>
                    <div>
                        <label class="text-xs font-semibold">Responsable de resolverlo</label>
                        <select v-model="bloqueo.responsable_id" class="input w-full">
                            <option :value="null" disabled>Elija una persona</option>
                            <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.nombre }}</option>
                        </select>
                    </div>
                    <div><label class="text-xs font-semibold">Acción requerida</label><textarea v-model="bloqueo.accion" rows="2" class="input w-full"></textarea></div>
                    <div class="flex gap-2">
                        <button @click="confirmarBloqueo" :disabled="!bloqueo.motivo.trim() || !bloqueo.responsable_id || !bloqueo.accion.trim()"
                                class="btn-primary text-sm disabled:opacity-50">Bloquear</button>
                        <button @click="bloqueo.activo = false" class="btn-ghost text-sm">Cancelar</button>
                    </div>
                </div>

                <!-- Pedir cambios (Aracely) -->
                <div v-if="devolucion.activo" class="p-3 rounded-lg border border-amber-300 space-y-2">
                    <label class="text-xs font-semibold">¿Qué cambios necesita?</label>
                    <textarea v-model="devolucion.cambios" rows="2" class="input w-full"></textarea>
                    <div class="flex gap-2">
                        <button @click="devolver(abierta)" :disabled="!devolucion.cambios.trim()" class="btn-primary text-sm disabled:opacity-50">Devolver a En proceso</button>
                        <button @click="devolucion.activo = false" class="btn-ghost text-sm">Cancelar</button>
                    </div>
                </div>

                <!-- Comentarios e historia -->
                <div class="space-y-2">
                    <div class="text-sm font-semibold flex items-center gap-1"><MessageSquare class="h-4 w-4"/>Comentarios</div>
                    <div class="flex gap-2">
                        <input v-model="comentario" @keydown.enter="comentario.trim() && comentar()" class="input w-full" placeholder="Escriba un comentario…"/>
                        <button @click="comentar" :disabled="!comentario.trim()" class="btn-primary text-sm disabled:opacity-50">Enviar</button>
                    </div>
                    <ul class="space-y-1.5 text-sm">
                        <li v-for="c in abierta.comentarios" :key="c.id" class="border-l-2 pl-2" :class="c.tipo === 'comentario' ? 'border-brand-300' : 'border-surface-200 text-surface-500'">
                            <span class="mr-1">{{ ICONO_COMENTARIO[c.tipo] }}</span>{{ c.cuerpo }}
                            <div class="text-xs text-surface-400">{{ c.autor }} · {{ c.en }}</div>
                        </li>
                        <li v-if="!abierta.comentarios.length" class="text-xs text-surface-400">Sin comentarios todavía.</li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
