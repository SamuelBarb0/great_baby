<script setup>
import { onMounted, onBeforeUnmount, ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Boxes, Clock, CheckCheck, PackageCheck } from 'lucide-vue-next';

const props = defineProps({
    en_cola: { type: Array, required: true },
    en_alistamiento: { type: Array, required: true },
    con_novedad: { type: Array, default: () => [] },
    listos: { type: Array, required: true },
    alistadores: { type: Array, required: true },
    rol: { type: Object, required: true },
    kpis: { type: Object, required: true },
});

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);
const procesando = ref(false);

// LOG-J5 · recarga suave cada 10s para que Jorge vea el movimiento sin F5.
let timer;
onMounted(() => {
    timer = setInterval(() => {
        router.reload({ only: ['en_cola', 'en_alistamiento', 'con_novedad', 'listos', 'kpis'], preserveScroll: true });
    }, 10000);
});
onBeforeUnmount(() => timer && clearInterval(timer));

const asignar = (pedidoId, alistadorId) => {
    if (!alistadorId) return;
    procesando.value = true;
    router.post(`/app/logistica/cola-jorge/${pedidoId}/asignar`, { alistador_id: alistadorId }, {
        preserveScroll: true,
        onFinish: () => procesando.value = false,
    });
};
const iniciar = (pedidoId) => {
    procesando.value = true;
    router.post(`/app/logistica/cola-jorge/${pedidoId}/iniciar`, {}, {
        preserveScroll: true,
        onFinish: () => procesando.value = false,
    });
};

// Modal de finalizar · ahora obliga a declarar tipo de novedad.
const finalizando = ref(null);
const notasFin = ref('');
const tipoNovedad = ref('sin_novedad');
const errFin = ref(null);
const abrirFinalizar = (p) => {
    finalizando.value = p;
    notasFin.value = '';
    tipoNovedad.value = 'sin_novedad';
    errFin.value = null;
};
const confirmarFinalizar = () => {
    if (!finalizando.value) return;
    errFin.value = null;
    if (tipoNovedad.value !== 'sin_novedad' && !notasFin.value.trim()) {
        errFin.value = 'Si marcás una novedad, describí qué pasó.';
        return;
    }
    procesando.value = true;
    router.post(`/app/logistica/cola-jorge/${finalizando.value.id}/finalizar`, {
        notas: notasFin.value || null,
        tipo_novedad: tipoNovedad.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { finalizando.value = null; notasFin.value = ''; tipoNovedad.value = 'sin_novedad'; },
        onError: (e) => { errFin.value = Object.values(e)[0] || 'No se pudo terminar.'; },
        onFinish: () => procesando.value = false,
    });
};

// Modal de resolver novedad.
const resolviendo = ref(null);
const resolucion = ref('');
const errRes = ref(null);
const abrirResolver = (p) => { resolviendo.value = p; resolucion.value = ''; errRes.value = null; };
const confirmarResolver = () => {
    if (!resolviendo.value) return;
    if (resolucion.value.trim().length < 10) {
        errRes.value = 'Escribí al menos 10 caracteres explicando cómo se resolvió.';
        return;
    }
    procesando.value = true;
    router.post(`/app/logistica/cola-jorge/${resolviendo.value.id}/resolver-novedad`, {
        resolucion: resolucion.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { resolviendo.value = null; resolucion.value = ''; },
        onError: (e) => { errRes.value = Object.values(e)[0] || 'No se pudo resolver.'; },
        onFinish: () => procesando.value = false,
    });
};

// Etiquetas de los tipos de novedad.
const tipoNovedadLabel = (t) => ({
    faltante: 'FALTANTE',
    averia: 'AVERÍA',
    revision: 'REVISIÓN',
    otro: 'OTRO',
}[t] || t?.toUpperCase() || '');

// Cronómetro vivo para los pedidos en alistamiento.
const now = ref(Date.now());
setInterval(() => now.value = Date.now(), 30000);
/*
 * Cuánto tardó el alistamiento, en palabras.
 *
 * Llegaba en minutos desde el servidor y se pintaba crudo: un alistamiento de
 * 19 segundos decía «0.31666666666666665 min» en la tarjeta.
 */
const duracion = (seg) => {
    if (seg == null) return '';
    if (seg < 60) return `${seg} s`;
    const min = Math.round(seg / 60);
    if (min < 60) return `${min} min`;
    return `${Math.floor(min / 60)} h ${min % 60} min`;
};

const minutosDesde = (hhmm) => {
    if (!hhmm) return 0;
    const [h, m] = hhmm.split(':').map(Number);
    const inicio = new Date(); inicio.setHours(h, m, 0, 0);
    return Math.max(0, Math.round((now.value - inicio.getTime()) / 60000));
};
</script>

<template>
    <Head title="Cola de alistamiento"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Boxes class="h-6 w-6 text-brand-600"/>
                        Cola de alistamiento
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Pedidos facturados listos para picking · contado y local tienen prioridad · todas las bodegas.
                    </p>
                </div>
                <div class="flex items-center gap-2 flex-wrap text-sm">
                    <span class="px-3 py-1.5 rounded-lg bg-blue-500/15 text-blue-800 dark:text-blue-300 font-semibold">
                        {{ kpis.total_en_cola }} en cola
                    </span>
                    <span class="px-3 py-1.5 rounded-lg bg-amber-500/15 text-amber-800 dark:text-amber-200 font-semibold">
                        {{ kpis.total_en_curso }} en alistamiento
                    </span>
                    <span class="px-3 py-1.5 rounded-lg bg-emerald-500/15 text-emerald-800 dark:text-emerald-200 font-semibold">
                        {{ kpis.total_listos }} listos
                    </span>
                    <span v-if="kpis.contado_pendiente > 0" class="px-3 py-1.5 rounded-lg bg-red-500/15 text-red-800 dark:text-red-200 font-bold ring-1 ring-red-400">
                        ⚡ {{ kpis.contado_pendiente }} contado pendiente
                    </span>
                    <span v-if="kpis.total_con_novedad > 0" class="px-3 py-1.5 rounded-lg bg-rose-600/20 text-rose-900 dark:text-rose-200 font-bold ring-2 ring-rose-500 animate-pulse">
                        ⚠ {{ kpis.total_con_novedad }} CON NOVEDAD · bloquea despacho
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
                <!-- Columna 1 · En cola -->
                <div class="card p-4 min-h-[400px]">
                    <div class="flex items-center justify-between mb-3">
                        <div class="font-bold flex items-center gap-2 text-blue-700 dark:text-blue-300">
                            <Clock class="h-4 w-4"/> En cola · {{ en_cola.length }}
                        </div>
                    </div>
                    <div v-if="!en_cola.length" class="text-center text-sm text-surface-400 py-10">
                        Sin pedidos esperando.
                    </div>
                    <div v-else class="space-y-3">
                        <div v-for="p in en_cola" :key="p.id" class="p-3 rounded-lg border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 space-y-2">
                            <div class="flex items-center justify-between gap-2 flex-wrap">
                                <Link :href="`/app/pedidos-b2b/${p.id}`" class="font-bold text-brand-700 hover:underline">
                                    {{ p.numero }}
                                </Link>
                                <div class="flex items-center gap-1">
                                    <span v-if="p.tipo_pago === 'contado'" class="text-[10px] px-1.5 py-0.5 rounded bg-red-100 text-red-700 font-bold">CONTADO</span>
                                    <span v-else class="text-[10px] px-1.5 py-0.5 rounded bg-surface-200 text-surface-700">crédito</span>
                                    <span v-if="p.es_local" class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700 font-bold">LOCAL</span>
                                </div>
                            </div>
                            <div class="text-sm">{{ p.cliente }}</div>
                            <div class="text-xs text-surface-500">{{ p.ciudad }} · factura {{ p.factura }} · {{ money(p.total) }}</div>
                            <!--
                                El `select` llevaba `flex-1` sin `min-w-0`: en flexbox el ancho
                                mínimo por defecto es el del contenido, así que con el nombre de un
                                alistador largo el desplegable se negaba a encogerse y empujaba el
                                botón «Tomar» FUERA de la tarjeta. Quedaba medio tapado por la
                                columna de al lado y no se podía hacer clic en él: la acción
                                principal de la pantalla de bodega era inalcanzable con el mouse.
                                Encontrado el 2026-10-13 alistando un pedido real.
                            -->
                            <div class="flex flex-col gap-2 pt-1">
                                <select v-if="rol.puede_reasignar" :disabled="procesando" @change="e => asignar(p.id, e.target.value)" class="w-full min-w-0 text-xs border border-surface-300 rounded px-2 py-1 bg-white dark:bg-surface-900">
                                    <option value="">Asignar a…</option>
                                    <option v-for="a in alistadores" :key="a.id" :value="a.id">{{ a.name }}</option>
                                </select>
                                <button @click="iniciar(p.id)" :disabled="procesando" class="btn-primary w-full justify-center text-xs px-2 py-1">
                                    ▶ Tomar
                                </button>
                            </div>
                            <!-- LOG-J6 · botón de hoja de picking imprimible -->
                            <a :href="`/app/logistica/cola-jorge/${p.id}/picking?auto=1`" target="_blank" rel="noopener"
                               class="block text-center text-xs mt-1 bg-surface-800 text-white rounded py-1 hover:bg-black">
                                🖨 Imprimir hoja de picking
                            </a>
                            <div v-if="p.alistador" class="text-xs text-amber-700">
                                Asignado a <b>{{ p.alistador.name }}</b> · esperando que arranque
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Columna 2 · En alistamiento -->
                <div class="card p-4 min-h-[400px]">
                    <div class="flex items-center justify-between mb-3">
                        <div class="font-bold flex items-center gap-2 text-amber-700 dark:text-amber-300">
                            <PackageCheck class="h-4 w-4"/> En alistamiento · {{ en_alistamiento.length }}
                        </div>
                    </div>
                    <div v-if="!en_alistamiento.length" class="text-center text-sm text-surface-400 py-10">
                        Nadie alistando ahora.
                    </div>
                    <div v-else class="space-y-3">
                        <div v-for="p in en_alistamiento" :key="p.id" class="p-3 rounded-lg border-l-4 border-amber-400 bg-amber-50 dark:bg-amber-950/30 space-y-2">
                            <div class="flex items-center justify-between gap-2 flex-wrap">
                                <Link :href="`/app/pedidos-b2b/${p.id}`" class="font-bold text-brand-700 hover:underline">
                                    {{ p.numero }}
                                </Link>
                                <span class="text-xs font-mono bg-amber-200 text-amber-900 px-2 py-0.5 rounded">
                                    ⏱ {{ minutosDesde(p.inicio_at) }} min
                                </span>
                            </div>
                            <div class="text-sm">{{ p.cliente }}</div>
                            <div class="text-xs text-surface-500">
                                {{ p.alistador?.name }} · arrancó {{ p.inicio_at }}
                            </div>
                            <a :href="`/app/logistica/cola-jorge/${p.id}/picking?auto=1`" target="_blank" rel="noopener"
                               class="block text-center text-xs bg-surface-800 text-white rounded py-1 hover:bg-black">
                                🖨 Re-imprimir hoja de picking
                            </a>
                            <button @click="abrirFinalizar(p)" :disabled="procesando" class="btn-primary bg-emerald-600 hover:bg-emerald-700 text-xs w-full py-1.5">
                                ✓ Terminar alistamiento
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Columna 3 · ⚠ Con novedad (bloquea despacho hasta resolver) -->
                <div class="card p-4 min-h-[400px] ring-2 ring-rose-400/60 bg-rose-50/40 dark:bg-rose-950/20">
                    <div class="flex items-center justify-between mb-3">
                        <div class="font-bold flex items-center gap-2 text-rose-700 dark:text-rose-300">
                            ⚠ Con novedad · {{ con_novedad.length }}
                        </div>
                    </div>
                    <div v-if="!con_novedad.length" class="text-center text-sm text-surface-400 py-10">
                        Ninguno con problema. ✓
                    </div>
                    <div v-else class="space-y-3">
                        <div v-for="p in con_novedad" :key="p.id" class="p-3 rounded-lg border-l-4 border-rose-500 bg-white dark:bg-surface-900 space-y-2 shadow-sm">
                            <div class="flex items-center justify-between gap-2 flex-wrap">
                                <Link :href="`/app/pedidos-b2b/${p.id}`" class="font-bold text-brand-700 hover:underline">
                                    {{ p.numero }}
                                </Link>
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-rose-100 text-rose-800 font-bold">
                                    {{ tipoNovedadLabel(p.tipo_novedad) }}
                                </span>
                            </div>
                            <div class="text-sm">{{ p.cliente }}</div>
                            <div class="text-xs text-surface-500">
                                {{ p.alistador?.name }} · terminó {{ p.fin_at }}
                            </div>
                            <div v-if="p.notas" class="text-xs italic bg-rose-100 dark:bg-rose-900/40 text-rose-900 dark:text-rose-200 border-l-2 border-rose-500 p-2 rounded">
                                "{{ p.notas }}"
                            </div>
                            <div class="text-[11px] text-rose-700 dark:text-rose-300 font-semibold pt-1">
                                🚫 No sale a despacho hasta que se resuelva
                            </div>
                            <button v-if="rol.puede_resolver" @click="abrirResolver(p)" :disabled="procesando"
                                    class="btn-primary bg-rose-600 hover:bg-rose-700 text-xs w-full py-1.5">
                                ⚑ Resolver novedad
                            </button>
                            <div v-else class="text-xs text-center text-surface-500 italic pt-1">
                                Esperando a Gerencia / admin de bodega…
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Columna 4 · Listos -->
                <div class="card p-4 min-h-[400px]">
                    <div class="flex items-center justify-between mb-3">
                        <div class="font-bold flex items-center gap-2 text-emerald-700 dark:text-emerald-300">
                            <CheckCheck class="h-4 w-4"/> Listos para empaque · {{ listos.length }}
                        </div>
                    </div>
                    <div v-if="!listos.length" class="text-center text-sm text-surface-400 py-10">
                        Nada terminado todavía.
                    </div>
                    <div v-else class="space-y-3">
                        <div v-for="p in listos" :key="p.id" class="p-3 rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/30 space-y-1">
                            <div class="flex items-center justify-between gap-2 flex-wrap">
                                <Link :href="`/app/pedidos-b2b/${p.id}`" class="font-bold text-brand-700 hover:underline">
                                    {{ p.numero }}
                                </Link>
                                <span v-if="p.duracion_seg !== null" class="text-xs font-mono bg-emerald-200 text-emerald-900 px-2 py-0.5 rounded">
                                    ⏱ {{ duracion(p.duracion_seg) }}
                                </span>
                            </div>
                            <div class="text-sm">{{ p.cliente }}</div>
                            <div class="text-xs text-surface-500">
                                {{ p.alistador?.name }} · {{ p.inicio_at }} → {{ p.fin_at }}
                            </div>
                            <div v-if="p.notas" class="text-xs italic text-surface-600 dark:text-surface-400 border-l-2 border-emerald-400 pl-2 mt-1">
                                "{{ p.notas }}"
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal finalizar · obliga a declarar si hubo novedad -->
        <div v-if="finalizando" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @click.self="finalizando = null">
            <div class="bg-white dark:bg-surface-900 rounded-lg p-5 w-full max-w-lg space-y-4">
                <div class="font-bold text-lg">Terminar alistamiento · {{ finalizando.numero }}</div>

                <!-- Pregunta clave -->
                <div class="space-y-2">
                    <label class="text-sm font-semibold">¿Cómo cerró el pedido?</label>
                    <div class="grid grid-cols-1 gap-2">
                        <label class="flex items-start gap-2 p-2 rounded border cursor-pointer hover:bg-emerald-50 dark:hover:bg-emerald-950/30"
                               :class="tipoNovedad === 'sin_novedad' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/40' : 'border-surface-300'">
                            <input type="radio" value="sin_novedad" v-model="tipoNovedad" class="mt-0.5"/>
                            <div>
                                <div class="font-semibold text-sm text-emerald-700 dark:text-emerald-300">✓ Sin novedad · todo completo</div>
                                <div class="text-xs text-surface-500">Pasa directo a empaque y despacho.</div>
                            </div>
                        </label>
                        <label class="flex items-start gap-2 p-2 rounded border cursor-pointer hover:bg-rose-50 dark:hover:bg-rose-950/30"
                               :class="tipoNovedad === 'faltante' ? 'border-rose-500 bg-rose-50 dark:bg-rose-950/40' : 'border-surface-300'">
                            <input type="radio" value="faltante" v-model="tipoNovedad" class="mt-0.5"/>
                            <div>
                                <div class="font-semibold text-sm text-rose-700 dark:text-rose-300">⚠ Faltante · no hay stock suficiente</div>
                                <div class="text-xs text-surface-500">Queda retenido · gerencia debe resolver antes del despacho.</div>
                            </div>
                        </label>
                        <label class="flex items-start gap-2 p-2 rounded border cursor-pointer hover:bg-rose-50 dark:hover:bg-rose-950/30"
                               :class="tipoNovedad === 'averia' ? 'border-rose-500 bg-rose-50 dark:bg-rose-950/40' : 'border-surface-300'">
                            <input type="radio" value="averia" v-model="tipoNovedad" class="mt-0.5"/>
                            <div>
                                <div class="font-semibold text-sm text-rose-700 dark:text-rose-300">⚠ Avería · mercancía en mal estado</div>
                                <div class="text-xs text-surface-500">Queda retenido · no sale producto averiado al cliente.</div>
                            </div>
                        </label>
                        <label class="flex items-start gap-2 p-2 rounded border cursor-pointer hover:bg-amber-50 dark:hover:bg-amber-950/30"
                               :class="tipoNovedad === 'revision' ? 'border-amber-500 bg-amber-50 dark:bg-amber-950/40' : 'border-surface-300'">
                            <input type="radio" value="revision" v-model="tipoNovedad" class="mt-0.5"/>
                            <div>
                                <div class="font-semibold text-sm text-amber-700 dark:text-amber-300">⚠ Requiere revisión</div>
                                <div class="text-xs text-surface-500">Hay dudas que alguien debe confirmar.</div>
                            </div>
                        </label>
                        <label class="flex items-start gap-2 p-2 rounded border cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-800"
                               :class="tipoNovedad === 'otro' ? 'border-surface-500 bg-surface-100 dark:bg-surface-800' : 'border-surface-300'">
                            <input type="radio" value="otro" v-model="tipoNovedad" class="mt-0.5"/>
                            <div>
                                <div class="font-semibold text-sm">⚠ Otro problema</div>
                                <div class="text-xs text-surface-500">Describilo en las notas.</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="text-sm font-semibold">
                        Observación
                        <span v-if="tipoNovedad !== 'sin_novedad'" class="text-rose-600">· obligatoria</span>
                        <span v-else class="text-surface-400 font-normal">· opcional</span>
                    </label>
                    <textarea v-model="notasFin" rows="3" class="w-full border rounded p-2 text-sm dark:bg-surface-800 mt-1"
                              :placeholder="tipoNovedad === 'sin_novedad'
                                ? 'Opcional · si querés dejar un detalle'
                                : 'ej: faltó 1 unidad del SKU 25-10-02, caja llegó mojada'"></textarea>
                </div>

                <div v-if="errFin" class="text-sm text-rose-600 bg-rose-50 dark:bg-rose-950/40 p-2 rounded">
                    {{ errFin }}
                </div>

                <div v-if="tipoNovedad !== 'sin_novedad'" class="text-xs bg-rose-100 dark:bg-rose-950/40 text-rose-800 dark:text-rose-200 p-2 rounded border-l-4 border-rose-500">
                    🚫 Al confirmar, el pedido NO pasará a empaque. Quedará en "⚠ Con novedad" bloqueando el despacho hasta que Gerencia lo resuelva.
                </div>

                <div class="flex justify-end gap-2">
                    <button @click="finalizando = null" class="btn-ghost text-sm">Cancelar</button>
                    <button @click="confirmarFinalizar" :disabled="procesando"
                            :class="['btn-primary text-sm', tipoNovedad === 'sin_novedad' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700']">
                        <span v-if="tipoNovedad === 'sin_novedad'">✓ Terminar · listo para empaque</span>
                        <span v-else>⚠ Cerrar con novedad</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal resolver novedad -->
        <div v-if="resolviendo" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @click.self="resolviendo = null">
            <div class="bg-white dark:bg-surface-900 rounded-lg p-5 w-full max-w-md space-y-3">
                <div class="font-bold text-lg text-rose-700 dark:text-rose-300">
                    ⚑ Resolver novedad · {{ resolviendo.numero }}
                </div>
                <div class="text-sm bg-rose-50 dark:bg-rose-950/40 border-l-4 border-rose-500 p-2 rounded">
                    <div class="font-semibold">{{ tipoNovedadLabel(resolviendo.tipo_novedad) }}</div>
                    <div v-if="resolviendo.notas" class="italic text-rose-800 dark:text-rose-200 mt-1">
                        "{{ resolviendo.notas }}"
                    </div>
                </div>
                <p class="text-sm text-surface-500">
                    ¿Cómo se resolvió? (reposición de stock, cambio por otro SKU, se despacha incompleto con autorización, etc.)
                </p>
                <textarea v-model="resolucion" rows="3" class="w-full border rounded p-2 text-sm dark:bg-surface-800"
                          placeholder="ej: Compras repuso el SKU · pedido completo. OK para empaque."></textarea>
                <div v-if="errRes" class="text-sm text-rose-600">{{ errRes }}</div>
                <div class="flex justify-end gap-2">
                    <button @click="resolviendo = null" class="btn-ghost text-sm">Cancelar</button>
                    <button @click="confirmarResolver" :disabled="procesando" class="btn-primary bg-emerald-600 hover:bg-emerald-700 text-sm">
                        ✓ Liberar al empaque
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
