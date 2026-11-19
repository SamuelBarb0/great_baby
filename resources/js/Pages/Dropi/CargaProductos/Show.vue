<script setup>
import { computed, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { ArrowLeft, PackageOpen, AlertTriangle, CheckCircle2, XCircle, Loader2, Plus, Trash2, RotateCcw, Send, Download } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    carga: { type: Object, required: true },
    operaciones: { type: Array, default: () => [] },
    resumen: { type: Object, default: () => ({}) },
    categorias: { type: Array, default: () => [] },
});
const page = usePage();
const base = computed(() => `/app/dropi/carga-productos/${props.carga.id}`);

// ── Borradores locales por fila (se rehacen cuando el servidor responde) ──
// Solo se rehace la fila que el servidor cambió: guardar una fila no borra lo
// que se esté escribiendo en otra.
const borradores = reactive({});
const vistos = {};
const clonar = (o) => JSON.parse(JSON.stringify(o));
const rehacer = () => {
    const ids = new Set(props.operaciones.map((o) => o.id));
    for (const k of Object.keys(borradores)) if (!ids.has(Number(k))) delete borradores[k];
    for (const op of props.operaciones) {
        const firma = JSON.stringify([op.detalle, op.estado, op.tipo, op.dropi_id]);
        if (vistos[op.id] !== firma || !borradores[op.id]) {
            borradores[op.id] = { detalle: clonar(op.detalle), otroId: '', razon: op.razon ?? '' };
            vistos[op.id] = firma;
        }
    }
};
watch(() => props.operaciones, rehacer, { immediate: true });

const cargaForm = reactive({ concepto: props.carga.concepto, modo: props.carga.modo, defaults: { ...props.carga.defaults, categoria_id: props.carga.defaults?.categoria?.id ?? null } });
watch(() => props.carga, (c) => {
    cargaForm.concepto = c.concepto;
    cargaForm.modo = c.modo;
    cargaForm.defaults = { ...c.defaults, categoria_id: c.defaults?.categoria?.id ?? null };
});

// ── Filtros ──
const filtro = ref('todos');
const filtros = computed(() => [
    ['todos', 'Todas', props.operaciones.length],
    ['listo', 'Listas', props.resumen.listo || 0],
    ['revisar', 'Revisar', props.resumen.revisar || 0],
    ['crear', 'A crear', props.operaciones.filter((o) => o.tipo === 'crear' && o.estado !== 'omitido').length],
    ['omitido', 'Omitidas', props.resumen.omitido || 0],
    ['ok', 'Hechas', props.resumen.ok || 0],
    ['error', 'Con error', props.resumen.error || 0],
].filter(([k, , n]) => k === 'todos' || n > 0));
const visibles = computed(() => props.operaciones.filter((o) => filtro.value === 'todos' || (filtro.value === 'crear' ? o.tipo === 'crear' && o.estado !== 'omitido' : o.estado === filtro.value)));
const hayCrear = computed(() => props.operaciones.some((o) => o.tipo === 'crear' && o.estado !== 'omitido'));

// ── Acciones ──
const guardando = ref(null);
const enviar = (op, payload) => {
    guardando.value = op.id;
    router.put(`${base.value}/operaciones/${op.id}`, payload, {
        preserveScroll: true,
        onFinish: () => { guardando.value = null; },
    });
};
const guardar = (op) => {
    const b = borradores[op.id];
    if (op.tipo === 'actualizar') {
        enviar(op, { accion: 'guardar', lineas: b.detalle.lineas.map((l) => ({ cantidad: l.cantidad === '' || l.cantidad === null ? null : Number(l.cantidad) })) });
    } else {
        const d = b.detalle;
        enviar(op, { accion: 'guardar', detalle: {
            nombre: d.nombre, sku: d.sku, descripcion: d.descripcion,
            precio: d.precio === '' ? null : d.precio, sugerido: d.sugerido === '' ? null : d.sugerido,
            cantidad: Number(d.cantidad || 0),
            variaciones: (d.variaciones || []).map((v) => ({ valor: v.valor, sku: v.sku, cantidad: Number(v.cantidad || 0) })),
        } });
    }
};
const accion = (op, a, extra = {}) => enviar(op, { accion: a, ...extra });

// La razón se puede editar siempre, también después de mandar a Dropi.
const guardandoRazon = ref(null);
const guardarRazon = (op) => {
    guardandoRazon.value = op.id;
    router.put(`${base.value}/operaciones/${op.id}/razon`, { razon: borradores[op.id].razon }, {
        preserveScroll: true,
        onFinish: () => { guardandoRazon.value = null; },
    });
};
const agregarColor = (op) => {
    const d = borradores[op.id].detalle;
    d.variaciones = d.variaciones || [];
    d.variaciones.push({ valor: '', sku: `${d.sku || ''}-${d.variaciones.length}`, cantidad: 0 });
};
const quitarColor = (op, i) => borradores[op.id].detalle.variaciones.splice(i, 1);

const guardarCarga = () => router.put(base.value, cargaForm, { preserveScroll: true });
const confirmando = ref(false);
const confirmar = () => {
    confirmando.value = true;
    router.post(`${base.value}/confirmar`, {}, {
        preserveScroll: true,
        onSuccess: () => procesarTodo(),
        onFinish: () => { confirmando.value = false; },
    });
};

// ── Proceso por tandas ──
const avance = ref(null);
const procesando = ref(false);
const errorProceso = ref('');
const procesarTodo = async () => {
    if (procesando.value) return;
    procesando.value = true;
    errorProceso.value = '';
    try {
        for (let i = 0; i < 500; i++) {
            const { data } = await axios.post(`${base.value}/procesar`);
            avance.value = data;
            if (data.ocupado) await new Promise((r) => setTimeout(r, 3000));
            if (data.estado !== 'procesando') break;
        }
    } catch (e) {
        errorProceso.value = e?.response?.data?.message || 'Se cortó la conexión. Vuelve a abrir la carga para seguir donde iba.';
    } finally {
        procesando.value = false;
        router.reload({ preserveScroll: true });
    }
};
// ── Lectura del catálogo de Dropi (una página por llamada) ──
const lectura = ref(props.carga.lectura);
const leyendo = ref(false);
const errorLectura = ref('');
const leerTodo = async () => {
    if (leyendo.value) return;
    leyendo.value = true;
    errorLectura.value = '';
    let fallos = 0;
    try {
        for (let i = 0; i < 400; i++) {
            try {
                const { data } = await axios.post(`${base.value}/leer`);
                lectura.value = data;
                fallos = 0;
                if (data.ocupado) await new Promise((r) => setTimeout(r, 2000));
                if (data.estado !== 'leyendo') break;
            } catch (e) {
                // Dropi a veces tarda o se cae un momento: se reintenta la misma página.
                if (++fallos >= 3) throw e;
                await new Promise((r) => setTimeout(r, 3000));
            }
        }
    } catch (e) {
        errorLectura.value = e?.response?.data?.message || 'Dropi no respondió. Dale a «Seguir» para continuar desde donde iba.';
    } finally {
        leyendo.value = false;
        router.reload({ preserveScroll: true });
    }
};

onMounted(() => {
    if (props.carga.estado === 'procesando') procesarTodo();
    if (props.carga.estado === 'leyendo') leerTodo();
});
const salirSinTerminar = (e) => { if (procesando.value || leyendo.value) { e.preventDefault(); e.returnValue = ''; } };
onMounted(() => window.addEventListener('beforeunload', salirSinTerminar));
onBeforeUnmount(() => window.removeEventListener('beforeunload', salirSinTerminar));

const totalOps = computed(() => (avance.value ? avance.value.pendientes + avance.value.ok + avance.value.errores : 0));
const pct = computed(() => (totalOps.value ? Math.round(((avance.value.ok + avance.value.errores) / totalOps.value) * 100) : 0));

const estadoClase = (e) => ({
    listo: 'bg-brand-100 text-brand-800',
    revisar: 'bg-amber-100 text-amber-900',
    omitido: 'bg-surface-100 text-surface-600 dark:bg-surface-800 dark:text-surface-400',
    ok: 'bg-emerald-100 text-emerald-800',
    error: 'bg-red-100 text-red-800',
}[e] || 'bg-surface-100');
const estadoTexto = { listo: 'Lista', revisar: 'Revisar', omitido: 'Omitida', ok: 'Hecha', error: 'Error' };
const movClase = (m) => (m === 'ENTRADA' ? 'text-emerald-700' : m === 'SALIDA' ? 'text-red-700' : 'text-surface-500');
const errores = computed(() => page.props.errors || {});
</script>

<template>
    <Head :title="`Carga Dropi ${carga.numero}`"/>
    <AppLayout>
        <div class="max-w-6xl mx-auto space-y-4 p-4">
            <Link href="/app/dropi/carga-productos" class="text-sm text-brand-600 hover:underline inline-flex items-center gap-1">
                <ArrowLeft class="h-4 w-4"/> Productos y stock en Dropi
            </Link>

            <!-- Encabezado -->
            <div class="card p-5 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <PackageOpen class="h-6 w-6 text-brand-600"/>
                        Carga <span class="font-mono">{{ carga.numero }}</span>
                    </h1>
                    <span class="text-sm px-3 py-1 rounded-full" :class="{ leyendo: 'bg-amber-100 text-amber-900', borrador: 'bg-surface-100 dark:bg-surface-800', procesando: 'bg-amber-100 text-amber-900', terminado: 'bg-emerald-100 text-emerald-800' }[carga.estado]">
                        {{ { leyendo: 'Leyendo Dropi para armar la vista previa…', borrador: 'Vista previa: todavía no se manda nada a Dropi', procesando: 'Mandando a Dropi…', terminado: 'Terminada' }[carga.estado] }}
                    </span>
                </div>
                <p class="text-sm text-surface-500">
                    {{ carga.origen === 'excel' ? (carga.archivo || 'Excel') : 'Un producto' }} · {{ carga.fecha }} · {{ carga.usuario || '—' }} · bodega Dropi {{ carga.bodega }}
                    <span v-if="carga.terminado"> · terminada {{ carga.terminado }}</span>
                </p>
                <a v-if="carga.estado !== 'leyendo'" :href="`${base}/excel`" class="btn btn-secondary inline-flex items-center gap-2 text-sm w-fit">
                    <Download class="h-4 w-4"/> Descargar Excel del resultado
                </a>

                <!-- Concepto, modo y datos para crear -->
                <form v-if="carga.editable" class="grid md:grid-cols-2 gap-4 pt-2" @submit.prevent="guardarCarga">
                    <div class="space-y-2">
                        <label class="text-sm font-medium block">Concepto (va al historial de stock de Dropi)</label>
                        <input v-model="cargaForm.concepto" type="text" maxlength="100" class="input w-full"/>
                        <p v-if="errores.concepto" class="text-sm text-red-600">{{ errores.concepto }}</p>
                        <label class="text-sm font-medium block pt-2">Las cantidades del Excel…</label>
                        <select v-model="cargaForm.modo" class="input w-full">
                            <option value="reemplazar">…dicen cuánto debe quedar en Dropi</option>
                            <option value="sumar">…son lo que entra (se suma a lo que hay)</option>
                        </select>
                    </div>
                    <div v-if="hayCrear" class="space-y-2">
                        <p class="text-sm font-medium">Para los productos nuevos</p>
                        <select v-model="cargaForm.defaults.categoria_id" class="input w-full">
                            <option :value="null">Categoría…</option>
                            <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <div class="grid grid-cols-4 gap-2">
                            <label class="text-xs">Peso (kg)<input v-model="cargaForm.defaults.peso" type="number" min="0" step="0.01" class="input w-full"/></label>
                            <label class="text-xs">Largo (cm)<input v-model="cargaForm.defaults.largo" type="number" min="0" step="0.1" class="input w-full"/></label>
                            <label class="text-xs">Ancho (cm)<input v-model="cargaForm.defaults.ancho" type="number" min="0" step="0.1" class="input w-full"/></label>
                            <label class="text-xs">Alto (cm)<input v-model="cargaForm.defaults.alto" type="number" min="0" step="0.1" class="input w-full"/></label>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <label class="text-xs col-span-2">Texto de las garantías (Dropi lo exige)<input v-model="cargaForm.defaults.garantia_texto" type="text" maxlength="200" placeholder="Ej: Se aceptan con video de apertura" class="input w-full"/></label>
                            <label class="text-xs">Días de garantía<input v-model="cargaForm.defaults.garantia_dias" type="number" min="10" max="365" class="input w-full"/></label>
                        </div>
                        <label class="text-sm inline-flex items-center gap-2"><input v-model="cargaForm.defaults.privado" type="checkbox"/> Crear como privados (solo los ve Great Baby)</label>
                        <p class="text-xs text-surface-500">Las fotos se suben después en Dropi. Las medidas aplican a todos los productos nuevos de esta carga.</p>
                    </div>
                    <div class="md:col-span-2">
                        <button type="submit" class="btn btn-secondary">Guardar concepto y datos</button>
                    </div>
                </form>
                <p v-else class="text-sm"><strong>Concepto:</strong> {{ carga.concepto }} · <strong>Modo:</strong> {{ carga.modo === 'sumar' ? 'sumar lo que entra' : 'el Excel dice cuánto debe quedar' }}</p>
            </div>

            <!-- Lectura del catálogo -->
            <div v-if="carga.estado === 'leyendo'" class="card p-5 space-y-2">
                <p class="font-semibold flex items-center gap-2">
                    <Loader2 v-if="leyendo" class="h-4 w-4 animate-spin text-brand-600"/>
                    Leyendo el catálogo de Dropi para cruzarlo con las {{ carga.filas_excel }} filas del Excel…
                </p>
                <p class="text-sm text-surface-500">
                    {{ lectura?.leidos || 0 }} productos leídos<span v-if="lectura?.privados"> · ahora los privados</span>.
                    Se lee de a una página para que no se corte; toma alrededor de medio minuto.
                </p>
                <p class="text-xs text-surface-500">Si cierras esta página, al volver sigue donde iba. Todavía no se cambia nada en Dropi.</p>
                <p v-if="errorLectura" class="text-sm text-red-600">{{ errorLectura }}</p>
                <button v-if="!leyendo" type="button" class="btn btn-primary" @click="leerTodo">Seguir</button>
            </div>

            <!-- Avance -->
            <div v-if="carga.estado === 'procesando' || procesando" class="card p-5 space-y-2">
                <p class="font-semibold flex items-center gap-2"><Loader2 class="h-4 w-4 animate-spin text-brand-600"/> Mandando a Dropi y verificando cada producto…</p>
                <div class="h-2 rounded bg-surface-100 dark:bg-surface-800 overflow-hidden"><div class="h-2 bg-brand-600 transition-all" :style="{ width: `${pct}%` }"/></div>
                <p v-if="avance" class="text-sm text-surface-500">{{ avance.ok }} hechas · {{ avance.errores }} con error · {{ avance.pendientes }} pendientes</p>
                <p class="text-xs text-surface-500">Si cierras esta página se pausa; al volver a abrir la carga sigue donde iba.</p>
                <p v-if="errorProceso" class="text-sm text-red-600">{{ errorProceso }}</p>
                <button v-if="!procesando" type="button" class="btn btn-primary" @click="procesarTodo">Seguir</button>
            </div>

            <!-- Resultado -->
            <div v-if="carga.estado === 'terminado'" class="card p-5 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm">
                    <CheckCircle2 class="h-4 w-4 inline text-emerald-600"/> <strong>{{ resumen.ok || 0 }}</strong> productos quedaron en Dropi como se pidió.
                    <span v-if="resumen.error"><XCircle class="h-4 w-4 inline text-red-600 ml-2"/> <strong>{{ resumen.error }}</strong> con error.</span>
                    <span v-if="resumen.revisar || resumen.omitido" class="text-surface-500"> {{ (resumen.revisar || 0) + (resumen.omitido || 0) }} no se mandaron (revisar u omitidas).</span>
                </p>
                <button v-if="resumen.error" type="button" class="btn btn-secondary inline-flex items-center gap-2" @click="router.post(`${base}/reintentar`, {}, { onSuccess: procesarTodo })">
                    <RotateCcw class="h-4 w-4"/> Reintentar las que fallaron
                </button>
            </div>

            <!-- Filtros -->
            <div class="flex flex-wrap gap-2">
                <button v-for="[k, txt, n] in filtros" :key="k" type="button" class="text-sm px-3 py-1 rounded-full border"
                        :class="filtro === k ? 'bg-brand-600 text-white border-brand-600' : 'border-surface-200 dark:border-surface-700'" @click="filtro = k">
                    {{ txt }} <span class="opacity-75">{{ n }}</span>
                </button>
            </div>

            <!-- Filas -->
            <div v-for="op in visibles" :key="op.id" class="card p-4 space-y-3">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-semibold">
                            <span class="font-mono text-sm text-surface-500 mr-1">{{ op.fila ? `#${op.fila}` : '' }}</span>
                            <span class="font-mono">{{ op.ref || '—' }}</span> {{ op.nombre }}
                        </p>
                        <p v-if="op.cantidad !== null" class="text-xs text-surface-500">Excel: {{ op.cantidad }} unidades<span v-if="op.variaciones"> · {{ op.variaciones }}</span></p>
                    </div>
                    <span class="text-xs px-2 py-0.5 rounded-full flex-shrink-0" :class="estadoClase(op.estado)">
                        {{ op.tipo === 'crear' ? 'Crear · ' : '' }}{{ estadoTexto[op.estado] }}
                    </span>
                </div>
                <p v-if="op.motivo" class="text-sm flex gap-2" :class="op.estado === 'revisar' ? 'text-amber-900 dark:text-amber-300' : 'text-surface-500'">
                    <AlertTriangle v-if="op.estado === 'revisar'" class="h-4 w-4 flex-shrink-0 mt-0.5"/> {{ op.motivo }}
                </p>
                <p v-if="op.error" class="text-sm text-red-700 flex gap-2"><XCircle class="h-4 w-4 flex-shrink-0 mt-0.5"/> {{ op.error }}</p>

                <!-- Razón de la fila: editable siempre -->
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <label :for="`razon-${op.id}`" class="text-xs font-medium text-surface-600 dark:text-surface-400">Razón</label>
                    <input :id="`razon-${op.id}`" v-model="borradores[op.id].razon" type="text" maxlength="200" :placeholder="carga.concepto" class="input flex-1 min-w-[16rem]"/>
                    <button type="button" class="btn btn-ghost text-xs" :disabled="guardandoRazon === op.id || (borradores[op.id].razon ?? '') === (op.razon ?? '')" @click="guardarRazon(op)">
                        {{ guardandoRazon === op.id ? 'Guardando…' : 'Guardar razón' }}
                    </button>
                    <span v-if="op.razon_enviada && op.razon_enviada !== op.razon_efectiva" class="w-full text-xs text-surface-500">
                        En el historial de Dropi quedó: «{{ op.razon_enviada }}» (Dropi no deja cambiarlo; la razón de arriba es la del ERP).
                    </span>
                </div>

                <!-- Sin producto elegido: candidato -->
                <div v-if="op.tipo === 'actualizar' && !op.dropi_id && carga.editable" class="rounded bg-surface-50 dark:bg-surface-800 p-3 space-y-2 text-sm">
                    <p v-if="op.candidato">Parecido en Dropi: <strong>{{ op.candidato.nombre }}</strong> <span class="text-surface-500">#{{ op.candidato.id }} · {{ op.candidato.sku || 'sin SKU' }}</span></p>
                    <div class="flex flex-wrap gap-2 items-center">
                        <button v-if="op.candidato" type="button" class="btn btn-primary" :disabled="guardando === op.id" @click="accion(op, 'usar_producto', { dropi_producto_id: op.candidato.id })">Es este</button>
                        <input v-model="borradores[op.id].otroId" type="number" min="1" placeholder="Otro id de Dropi" class="input w-40"/>
                        <button type="button" class="btn btn-secondary" :disabled="!borradores[op.id].otroId || guardando === op.id" @click="accion(op, 'usar_producto', { dropi_producto_id: Number(borradores[op.id].otroId) })">Usar ese id</button>
                        <button type="button" class="btn btn-secondary" :disabled="guardando === op.id" @click="accion(op, 'crear_nuevo')">Es nuevo: crearlo</button>
                    </div>
                </div>

                <!-- Actualizar: variaciones -->
                <div v-if="op.tipo === 'actualizar' && op.dropi_id" class="space-y-2">
                    <p class="text-sm">→ Dropi <strong>#{{ op.dropi_id }}</strong> {{ op.dropi_nombre }} <span class="text-xs text-surface-500">({{ op.detalle.como }})</span></p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-xs text-surface-500 text-left">
                                <tr>
                                    <th class="py-1 pr-3">Variación</th>
                                    <th class="py-1 pr-3">SKU</th>
                                    <th class="py-1 pr-3 text-right">Stock en Dropi al planear</th>
                                    <th class="py-1 pr-3">{{ carga.modo === 'sumar' ? 'Unidades que entran' : 'Stock que debe quedar' }}</th>
                                    <th v-if="!carga.editable" class="py-1 pr-3">Resultado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(l, i) in borradores[op.id].detalle.lineas" :key="i" class="border-t border-surface-100 dark:border-surface-800">
                                    <td class="py-1 pr-3">{{ l.etiqueta }}</td>
                                    <td class="py-1 pr-3 font-mono text-xs">{{ l.sku || '—' }}</td>
                                    <td class="py-1 pr-3 text-right">{{ l.stock_plan ?? '—' }}</td>
                                    <td class="py-1 pr-3">
                                        <input v-if="carga.editable" v-model="l.cantidad" type="number" min="0" placeholder="no cambiar" class="input w-32"/>
                                        <span v-else>{{ l.cantidad ?? 'no se cambió' }}</span>
                                    </td>
                                    <td v-if="!carga.editable" class="py-1 pr-3 text-xs">
                                        <span v-if="l.movimiento && l.movimiento !== 'NINGUNO'" :class="movClase(l.movimiento)">{{ l.movimiento }} {{ l.unidades }}</span>
                                        <span v-else-if="l.movimiento === 'NINGUNO'" class="text-surface-500">ya estaba</span>
                                        <span v-if="l.antes !== undefined" class="text-surface-500"> · {{ l.antes }} → {{ l.despues ?? '?' }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Crear -->
                <div v-if="op.tipo === 'crear'" class="space-y-2">
                    <template v-if="carga.editable">
                        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-2">
                            <label class="text-xs sm:col-span-2">Nombre en Dropi<input v-model="borradores[op.id].detalle.nombre" type="text" maxlength="200" class="input w-full"/></label>
                            <label class="text-xs">SKU<input v-model="borradores[op.id].detalle.sku" type="text" maxlength="100" class="input w-full"/></label>
                            <label class="text-xs" v-if="!borradores[op.id].detalle.variaciones?.length">Stock inicial<input v-model="borradores[op.id].detalle.cantidad" type="number" min="0" class="input w-full"/></label>
                            <label class="text-xs">Precio proveedor<input v-model="borradores[op.id].detalle.precio" type="number" min="0" step="100" class="input w-full"/></label>
                            <label class="text-xs">Precio sugerido<input v-model="borradores[op.id].detalle.sugerido" type="number" min="0" step="100" class="input w-full"/></label>
                            <label class="text-xs sm:col-span-2">Descripción (opcional)<input v-model="borradores[op.id].detalle.descripcion" type="text" maxlength="2000" class="input w-full"/></label>
                        </div>
                        <div class="space-y-1">
                            <p class="text-xs font-medium">Colores <span class="text-surface-500 font-normal">(sin colores = producto simple)</span></p>
                            <div v-for="(v, i) in borradores[op.id].detalle.variaciones || []" :key="i" class="flex flex-wrap gap-2 items-center">
                                <input v-model="v.valor" type="text" maxlength="60" placeholder="Color" class="input w-36"/>
                                <input v-model="v.sku" type="text" maxlength="100" placeholder="SKU" class="input w-40"/>
                                <input v-model="v.cantidad" type="number" min="0" placeholder="Stock" class="input w-24"/>
                                <button type="button" class="btn btn-ghost" title="Quitar color" @click="quitarColor(op, i)"><Trash2 class="h-4 w-4"/></button>
                            </div>
                            <button type="button" class="btn btn-ghost inline-flex items-center gap-1 text-sm" @click="agregarColor(op)"><Plus class="h-4 w-4"/> Agregar color</button>
                        </div>
                    </template>
                    <div v-else class="text-sm space-y-1">
                        <p><strong>{{ op.detalle.nombre }}</strong> · SKU {{ op.detalle.sku || '—' }} · ${{ op.detalle.precio }} / sugerido ${{ op.detalle.sugerido }}
                            <span v-if="op.dropi_id"> → Dropi <strong>#{{ op.dropi_id }}</strong></span></p>
                        <p v-for="(l, i) in op.detalle.lineas || []" :key="i" class="text-xs">
                            {{ l.etiqueta }}: <span :class="movClase(l.movimiento)">{{ l.movimiento === 'NINGUNO' ? 'sin stock inicial' : `ENTRADA ${l.unidades}` }}</span>
                            <span v-if="l.despues !== undefined" class="text-surface-500"> · quedó {{ l.despues }}</span>
                        </p>
                    </div>
                </div>

                <!-- Botones de la fila -->
                <div v-if="carga.editable" class="flex flex-wrap gap-2 pt-1">
                    <button v-if="op.estado !== 'omitido' && (op.dropi_id || op.tipo === 'crear')" type="button" class="btn btn-primary" :disabled="guardando === op.id" @click="guardar(op)">
                        {{ guardando === op.id ? 'Guardando…' : 'Guardar fila' }}
                    </button>
                    <button v-if="op.estado !== 'omitido'" type="button" class="btn btn-ghost" :disabled="guardando === op.id" @click="accion(op, 'omitir')">No mandar esta fila</button>
                    <button v-else type="button" class="btn btn-ghost" @click="accion(op, 'reactivar')">Volver a incluir</button>
                </div>
            </div>

            <!-- Confirmar -->
            <div v-if="carga.editable" class="card p-5 sticky bottom-2 flex flex-wrap items-center justify-between gap-3 shadow-lg">
                <p class="text-sm">
                    <strong>{{ resumen.listo || 0 }}</strong> filas listas para mandar
                    <span v-if="resumen.crear_listos">({{ resumen.crear_listos }} productos nuevos)</span>.
                    <span v-if="resumen.revisar" class="text-amber-800"> {{ resumen.revisar }} en revisar no se mandan.</span>
                </p>
                <div class="flex flex-col items-end gap-1">
                    <button type="button" class="btn btn-primary inline-flex items-center gap-2" :disabled="!resumen.listo || confirmando" @click="confirmar">
                        <Send class="h-4 w-4"/> Mandar a Dropi
                    </button>
                    <p v-if="errores.confirmar" class="text-sm text-red-600">{{ errores.confirmar }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
