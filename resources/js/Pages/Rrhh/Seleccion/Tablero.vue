<script setup>
/*
 * El tablero de selección de una vacante.
 *
 * Una columna por etapa real del proceso. Las columnas salen del servidor, no
 * están escritas acá: agregar una etapa mañana no debería costar tocar la
 * pantalla.
 *
 * El candidato se mueve con el selector de su tarjeta y no arrastrando: Elena
 * trabaja también desde el móvil, y ahí arrastrar entre siete columnas no es
 * algo que se pueda hacer bien.
 */
import { ref, reactive, computed } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft, Plus, FileText, ShieldCheck, ShieldAlert, X, Star, Users, Download, Printer, Brain,
    MessageSquare, Trash2, Heart,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

/*
 * La fecha de HOY en Colombia, no en UTC.
 *
 * `toISOString()` convierte a UTC antes de recortar, asi que entre las
 * 19:00 y la medianoche hora Bogota devuelve el dia siguiente. Las fechas
 * de esta pantalla quedan escritas en registros, no son un filtro: un dia
 * de corrimiento cambia lo que dice el documento.
 */
const { hoy: hoyBogota } = useBogotaDate();

const props = defineProps({
    vacante: { type: Object, required: true },
    columnas: { type: Array, default: () => [] },
    candidatos: { type: Array, default: () => [] },
    descartados: { type: Number, default: 0 },
    fuentes: { type: Array, default: () => [] },
    valoraciones: { type: Array, default: () => [] },
    terna_llena: { type: Boolean, default: false },
    instrumentos: { type: Array, default: () => [] },
    conceptos: { type: Array, default: () => [] },
    // Los tres cortes, con sus textos. Vienen del enum para que la pantalla
    // y el impreso digan exactamente lo mismo.
    bandas: { type: Array, default: () => [] },
    cuestionarios: { type: Object, default: () => ({}) },
});

const procesando = ref(false);
const errores = ref({});
const verDescartados = ref(false);

/* ── alta de candidato ───────────────────────────────────────────── */
const modalNuevo = ref(false);
const nuevo = reactive({ nombre: '', documento: '', email: '', telefono: '', fuente: 'caja_compensacion', notas: '' });
const archivo = ref(null);

const crear = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    // Va como FormData porque lleva la hoja de vida adjunta.
    router.post(`/app/rrhh/vacantes/${props.vacante.id}/candidatos`, { ...nuevo, cv: archivo.value }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            modalNuevo.value = false;
            archivo.value = null;
            Object.assign(nuevo, { nombre: '', documento: '', email: '', telefono: '', notas: '' });
        },
        onError: (e) => (errores.value = e),
        onFinish: () => (procesando.value = false),
    });
};

/* ── acciones sobre una tarjeta ──────────────────────────────────── */
const accion = (url, datos, extra = {}) => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post(url, datos, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onFinish: () => (procesando.value = false),
        ...extra,
    });
};

const mover = (c, etapa) => {
    if (etapa === c.etapa) return;
    accion(`/app/rrhh/candidatos/${c.id}/mover`, { etapa });
};

const modalAntecedentes = ref(null);
const resultadoAnt = ref('');
const guardarAntecedentes = () => accion(
    `/app/rrhh/candidatos/${modalAntecedentes.value.id}/antecedentes`,
    { resultado: resultadoAnt.value },
    { onSuccess: () => { modalAntecedentes.value = null; resultadoAnt.value = ''; } },
);

const modalValoracion = ref(null);
const val = reactive({ valoracion: 'apto', observacion: '' });
const exigeObs = computed(() => props.valoraciones.find((v) => v.valor === val.valoracion)?.exige_observacion);
const guardarValoracion = () => accion(
    `/app/rrhh/candidatos/${modalValoracion.value.id}/valorar`,
    { ...val },
    { onSuccess: () => { modalValoracion.value = null; Object.assign(val, { valoracion: 'apto', observacion: '' }); } },
);

/*
 * ── Entrevistas · Fase C ─────────────────────────────────────────────────
 *
 * Dos formatos en el mismo modal. El cuestionario viene del servidor, así
 * que agregar o cambiar una pregunta es editar una constante en PHP y no
 * esta pantalla: el formato del área y lo que se pregunta no pueden
 * separarse.
 */
const modalEntrevista = ref(null);
const tipoEntrevista = ref('saber_hacer');
const entrevista = reactive({
    fecha: '', entrevistador_nombre: '', entrevistador_cargo: '',
    respuestas: {}, calificaciones: {}, observaciones: '',
});

const esSaberHacer = computed(() => tipoEntrevista.value === 'saber_hacer');

const totalEntrevista = computed(() => {
    const notas = Object.values(entrevista.calificaciones)
        .map(Number).filter((n) => n >= 1 && n <= 5);
    return notas.length ? notas.reduce((a, b) => a + b, 0) : null;
});

/*
 * El promedio y la banda de la entrevista del ser, mientras se califica.
 *
 * Exige los SEIS criterios, igual que el servidor: con el promedio sobre lo
 * contestado, un solo criterio en 5 daría 5,00 y «Recomendable» — media hoja
 * en blanco respaldando una contratación. Mientras falte uno, la pantalla
 * dice cuántos faltan en vez de mostrar un número que miente.
 */
const criteriosSer = computed(() => Object.keys(props.cuestionarios?.ser ?? {}));

const faltanCriterios = computed(() => criteriosSer.value.filter((k) => {
    const n = Number(entrevista.calificaciones[k]);
    return !(n >= 1 && n <= 5);
}).length);

const promedioSer = computed(() => {
    if (!criteriosSer.value.length || faltanCriterios.value > 0) return null;
    const suma = criteriosSer.value.reduce((a, k) => a + Number(entrevista.calificaciones[k]), 0);
    return Math.round((suma / criteriosSer.value.length) * 100) / 100;
});

// Los mismos cortes que `BandaValoracion::dePromedio()`. Los extremos caen
// del lado más exigente: 2,50 es Riesgo y 3,50 es Recomendable.
const bandaSer = computed(() => {
    const p = promedioSer.value;
    if (p === null) return null;
    const valor = p >= 3.5 ? 'recomendable' : (p > 2.5 ? 'aceptable_con_seguimiento' : 'riesgo');
    return props.bandas.find((b) => b.valor === valor) ?? null;
});

const abrirEntrevista = (c, tipo) => {
    errores.value = {};
    tipoEntrevista.value = tipo;
    modalEntrevista.value = c;
    Object.assign(entrevista, {
        fecha: hoyBogota(),
        entrevistador_nombre: '', entrevistador_cargo: '',
        respuestas: {}, calificaciones: {}, observaciones: '',
    });
    // Las tres tablas de la psicológica arrancan con una fila en blanco.
    if (tipo === 'psicologica') {
        ['familia', 'academica', 'laboral'].forEach((b) => {
            entrevista.respuestas[`${b}_filas`] = [{}];
        });
    }
};

const filasTabla = (bloque) => entrevista.respuestas[`${bloque}_filas`] ?? [];
const agregarFila = (bloque) => entrevista.respuestas[`${bloque}_filas`].push({});
const quitarFila = (bloque, i) => entrevista.respuestas[`${bloque}_filas`].splice(i, 1);

const guardarEntrevista = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post(`/app/rrhh/candidatos/${modalEntrevista.value.id}/entrevista`, {
        tipo: tipoEntrevista.value, ...entrevista,
    }, {
        preserveScroll: true,
        onSuccess: () => { modalEntrevista.value = null; },
        onError: (e) => { errores.value = e; },
        onFinish: () => { procesando.value = false; },
    });
};

/*
 * ── Psicotécnicas · Fase C ───────────────────────────────────────────────
 *
 * Acá se registra el CONCEPTO del profesional, no las respuestas. No es una
 * simplificación de la pantalla: el sistema no las guarda en ninguna parte.
 * Las respuestas de un MINIMULT son dato sensible de salud y los dos
 * instrumentos tienen derechos de autor.
 *
 * El informe firmado sí se adjunta, va al disco privado y se abre por una
 * ruta aparte que revisa permiso y deja anotado quién lo leyó.
 */
const modalPrueba = ref(null);
const prueba = reactive({
    instrumento: 'minimult', instrumento_otro: '', fecha_aplicacion: '',
    profesional_nombre: '', profesional_tarjeta: '', concepto: 'apto', observaciones: '',
});
const informe = ref(null);

const instrumentoSel = computed(() => props.instrumentos.find((i) => i.valor === prueba.instrumento));
const conceptoSel = computed(() => props.conceptos.find((c) => c.valor === prueba.concepto));

const abrirPrueba = (c, ya = null) => {
    errores.value = {};
    informe.value = null;
    modalPrueba.value = c;
    Object.assign(prueba, {
        instrumento: ya?.instrumento ?? 'minimult',
        instrumento_otro: '',
        fecha_aplicacion: ya?.fecha ?? hoyBogota(),
        profesional_nombre: '',
        profesional_tarjeta: '',
        concepto: ya?.concepto ?? 'apto',
        observaciones: ya?.observaciones ?? '',
    });
};

const guardarPrueba = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    // FormData porque lleva el informe adjunto.
    router.post(`/app/rrhh/candidatos/${modalPrueba.value.id}/psicotecnica`,
        { ...prueba, informe: informe.value }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { modalPrueba.value = null; },
            onError: (e) => { errores.value = e; },
            onFinish: () => { procesando.value = false; },
        });
};

/*
 * De seleccionado a contratado, pasando por la Junta.
 *
 * Que el jefe haya elegido NO alcanza: «solo ingresa la empresa realmente a un
 * contrato si viene autorizado por la junta directiva». Así que primero se
 * pide la orden y sólo cuando está autorizada se abre el expediente.
 *
 * Las dos acciones son idempotentes del lado del servidor: apretar dos veces
 * no deja dos órdenes ni dos contrataciones.
 */
const pedirOrden = (c) => {
    if (procesando.value) return;
    procesando.value = true;
    router.post(`/app/rrhh/candidatos/${c.id}/orden`, {}, {
        onFinish: () => (procesando.value = false),
    });
};

const contratar = (c) => {
    if (procesando.value) return;
    procesando.value = true;
    router.post(`/app/rrhh/candidatos/${c.id}/contratar`, {}, {
        onFinish: () => (procesando.value = false),
    });
};

const modalDescarte = ref(null);
const motivoDescarte = ref('');
const guardarDescarte = () => accion(
    `/app/rrhh/candidatos/${modalDescarte.value.id}/descartar`,
    { motivo: motivoDescarte.value },
    { onSuccess: () => { modalDescarte.value = null; motivoDescarte.value = ''; } },
);

/* ── armado del tablero ──────────────────────────────────────────── */
const enColumna = (valor) => props.candidatos.filter((c) => c.etapa === valor);
const listaDescartados = computed(() => props.candidatos.filter((c) => c.etapa === 'descartado'));
const seleccionados = computed(() => props.candidatos.filter((c) => c.etapa === 'seleccionado'));
const enProceso = computed(() => props.candidatos.filter((c) => !['descartado', 'seleccionado'].includes(c.etapa)).length);

const badge = (color) => ({
    gray: 'bg-surface-200 text-surface-700',
    warning: 'bg-amber-100 text-amber-800',
    info: 'bg-blue-100 text-blue-800',
    success: 'bg-emerald-100 text-emerald-800',
    danger: 'bg-rose-100 text-rose-800',
}[color] || 'bg-surface-100 text-surface-700');
</script>

<template>
    <Head :title="`Selección · ${vacante.titulo}`" />
    <AppLayout>
        <div class="space-y-4">
            <Link href="/app/rrhh/vacantes" class="text-sm text-surface-500 hover:text-brand-600 inline-flex items-center gap-1">
                <ArrowLeft class="h-4 w-4" /> Volver a las vacantes
            </Link>

            <div v-if="$page.props.flash?.success"
                class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="errores.etapa || errores.observacion || errores.motivo"
                class="p-3 rounded-lg bg-rose-500/10 border-l-4 border-rose-500 text-rose-700 text-sm">
                {{ errores.etapa || errores.observacion || errores.motivo }}
            </div>

            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Users class="h-6 w-6 text-brand-600" />{{ vacante.titulo }}
                    </h1>
                    <p class="text-sm text-surface-500">
                        {{ vacante.area }} ·
                        <Link v-if="vacante.requisicion" :href="`/app/rrhh/requisiciones/${vacante.requisicion.id}`"
                            class="text-brand-600 hover:underline">{{ vacante.requisicion.numero }}</Link>
                        <span v-else class="text-amber-600">Sin requisición</span>
                        · {{ enProceso }} en proceso
                    </p>
                </div>
                <div class="flex gap-2">
                    <a :href="`/app/rrhh/exportar/vacantes/${vacante.id}/candidatos`" class="btn-secondary">
                        <Download class="h-4 w-4" /> Excel
                    </a>
                    <button @click="modalNuevo = true" class="btn-primary">
                        <Plus class="h-4 w-4" /> Agregar hoja de vida
                    </button>
                </div>
            </div>

            <!-- El tablero. En móvil las columnas se apilan; en pantalla grande van en fila. -->
            <div class="flex gap-3 overflow-x-auto pb-2 flex-col lg:flex-row">
                <div v-for="col in columnas" :key="col.valor"
                    class="lg:w-72 lg:shrink-0 rounded-xl bg-surface-100 dark:bg-surface-900 p-3">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="font-bold text-sm">{{ col.label }}</h3>
                        <span :class="['px-2 py-0.5 rounded-full text-[10px] font-bold', badge(col.color)]">
                            {{ enColumna(col.valor).length }}
                        </span>
                    </div>
                    <p class="text-[10px] uppercase text-surface-400 font-semibold mb-2">{{ col.responsable }}</p>

                    <div class="space-y-2">
                        <div v-for="c in enColumna(col.valor)" :key="c.id"
                            class="card p-3 text-sm space-y-2">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-bold truncate">{{ c.nombre }}</div>
                                    <div class="text-xs text-surface-500 truncate">
                                        {{ c.documento || 'sin documento' }}<span v-if="c.telefono"> · {{ c.telefono }}</span>
                                    </div>
                                </div>
                                <button @click="modalDescarte = c" class="text-surface-400 hover:text-rose-600 shrink-0"
                                    title="Descartar">
                                    <X class="h-4 w-4" />
                                </button>
                            </div>

                            <div class="flex flex-wrap gap-1">
                                <a v-if="c.tiene_hv" :href="`/app/rrhh/candidatos/${c.id}/hoja-de-vida`" target="_blank"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-surface-200 dark:bg-surface-800 text-[10px] font-semibold hover:bg-surface-300">
                                    <FileText class="h-3 w-3" /> Hoja de vida
                                </a>
                                <!-- Su recorrido, para cuando pidan cuentas de la selección -->
                                <a :href="`/app/rrhh/candidatos/${c.id}/pdf`"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-surface-200 dark:bg-surface-800 text-[10px] font-semibold hover:bg-surface-300"
                                    title="El proceso de esta persona, en PDF">
                                    <Printer class="h-3 w-3" /> Proceso
                                </a>
                                <span v-if="c.fuente" class="px-2 py-0.5 rounded bg-surface-100 dark:bg-surface-800 text-[10px] text-surface-500">
                                    {{ c.fuente }}
                                </span>
                                <span v-if="c.valoracion"
                                    :class="['px-2 py-0.5 rounded text-[10px] font-bold', badge(c.valoracion_color)]"
                                    :title="c.valoracion_observacion">
                                    {{ c.valoracion_label }}
                                </span>
                            </div>

                            <!-- Antecedentes: la puerta a las entrevistas -->
                            <button v-if="!c.antecedentes_revisados" @click="modalAntecedentes = c"
                                class="w-full flex items-center gap-1 px-2 py-1 rounded bg-amber-100 text-amber-800 text-[11px] font-semibold hover:bg-amber-200">
                                <ShieldAlert class="h-3.5 w-3.5" /> Faltan antecedentes
                            </button>
                            <div v-else class="flex items-center gap-1 text-[11px] text-emerald-700" :title="c.antecedentes_resultado">
                                <ShieldCheck class="h-3.5 w-3.5" /> Antecedentes revisados
                            </div>

                            <!-- Entrevistas · el total; el detalle se abre aparte -->
                            <div v-if="c.entrevistas?.length" class="space-y-1">
                                <div v-for="x in c.entrevistas" :key="x.id"
                                    class="flex items-center gap-1 flex-wrap text-[11px]">
                                    <span class="text-surface-500">{{ x.tipo_label }}</span>
                                    <span v-if="x.total"
                                        class="px-1.5 py-0.5 rounded bg-blue-500/15 text-blue-800 text-[10px] font-bold">
                                        {{ x.total }}/30
                                    </span>
                                    <a :href="`/app/rrhh/entrevistas/${x.id}/pdf`" target="_blank"
                                        class="text-surface-500 hover:text-brand-600" title="Imprimir">
                                        <FileText class="h-3 w-3" />
                                    </a>
                                    <span v-if="x.sin_contestar" class="text-amber-700">
                                        · {{ x.sin_contestar }} sin contestar
                                    </span>
                                </div>
                            </div>

                            <!--
                                Psicotécnicas · el concepto, nunca el contenido.
                                El informe se abre aparte, por una ruta que
                                revisa permiso y anota quién lo leyó.
                            -->
                            <div v-if="c.psicotecnicas?.length" class="space-y-1">
                                <div v-for="p in c.psicotecnicas" :key="p.id"
                                    class="flex items-center gap-1 flex-wrap text-[11px]">
                                    <span class="text-surface-500">{{ p.prueba }}</span>
                                    <span :class="['px-1.5 py-0.5 rounded text-[10px] font-bold', badge(p.color)]"
                                        :title="p.observaciones || p.firma">
                                        {{ p.concepto_label }}
                                    </span>
                                    <a v-if="p.tiene_informe" :href="`/app/rrhh/psicotecnicas/${p.id}/informe`"
                                        target="_blank" class="text-surface-500 hover:text-brand-600"
                                        title="Informe firmado">
                                        <FileText class="h-3 w-3" />
                                    </a>
                                    <span v-if="p.falta.length" class="text-amber-700" :title="`Falta ${p.falta.join(' y ')}`">
                                        · falta {{ p.falta.join(' y ') }}
                                    </span>
                                </div>
                            </div>

                            <div class="flex gap-1">
                                <select :value="c.etapa" @change="mover(c, $event.target.value)"
                                    :disabled="procesando" class="input flex-1 min-w-0 text-xs py-1">
                                    <option v-for="o in columnas" :key="o.valor" :value="o.valor">{{ o.label }}</option>
                                    <option value="seleccionado">Seleccionado</option>
                                </select>
                                <button @click="abrirEntrevista(c, 'saber_hacer')"
                                    class="btn-secondary px-2 py-1" title="Entrevista de saber hacer">
                                    <MessageSquare class="h-3.5 w-3.5" />
                                </button>
                                <button @click="abrirEntrevista(c, 'psicologica')"
                                    class="btn-secondary px-2 py-1" title="Entrevista psicológica">
                                    <Users class="h-3.5 w-3.5" />
                                </button>
                                <button @click="abrirEntrevista(c, 'ser')"
                                    class="btn-secondary px-2 py-1" title="Entrevista del ser">
                                    <Heart class="h-3.5 w-3.5" />
                                </button>
                                <button @click="abrirPrueba(c)" class="btn-secondary px-2 py-1"
                                    title="Registrar una prueba psicotécnica">
                                    <Brain class="h-3.5 w-3.5" />
                                </button>
                                <button @click="modalValoracion = c" class="btn-secondary px-2 py-1" title="Valorar">
                                    <Star class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>

                        <p v-if="!enColumna(col.valor).length" class="text-xs text-surface-400 text-center py-3">
                            Nadie acá
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="terna_llena" class="p-3 rounded-lg bg-blue-500/10 border-l-4 border-blue-500 text-sm text-blue-800">
                La terna está completa. El jefe que pidió el personal ya fue notificado para que elija.
            </div>

            <div v-if="seleccionados.length" class="card p-4">
                <h3 class="font-bold text-sm uppercase text-surface-500 mb-2">Seleccionados</h3>
                <ul class="text-sm divide-y">
                    <li v-for="c in seleccionados" :key="c.id"
                        class="py-2.5 flex items-center justify-between gap-2 flex-wrap">
                        <div>
                            <span class="font-semibold">{{ c.nombre }}</span>
                            <span v-if="c.elegido" class="text-xs text-emerald-700"> · elegido por el jefe</span>
                            <div v-if="c.orden" class="text-xs mt-0.5">
                                <span :class="['px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(c.orden.color)]">
                                    {{ c.orden.numero }} · {{ c.orden.label }}
                                </span>
                                <span v-if="c.orden.motivo" class="text-surface-500"> · {{ c.orden.motivo }}</span>
                            </div>
                        </div>

                        <!-- Primero la Junta, después la contratación -->
                        <Link v-if="c.contratacion?.empleado_id"
                            :href="`/app/rrhh/empleados/${c.contratacion.empleado_id}`"
                            class="text-xs text-emerald-700 font-semibold hover:underline">
                            Ya trabaja acá · ver ficha
                        </Link>
                        <Link v-else-if="c.contratacion"
                            :href="`/app/rrhh/contrataciones/${c.contratacion.id}`"
                            class="btn-secondary text-xs py-1 px-3">
                            Seguir la contratación
                        </Link>
                        <button v-else-if="!c.orden" @click="pedirOrden(c)" :disabled="procesando"
                            class="btn-primary text-xs py-1 px-3">
                            Pedir orden a la Junta
                        </button>
                        <button v-else-if="c.orden.autoriza" @click="contratar(c)" :disabled="procesando"
                            class="btn-primary text-xs py-1 px-3">
                            Abrir contratación
                        </button>
                        <span v-else-if="c.orden.estado === 'pendiente'" class="text-xs text-amber-600">
                            Esperando a la Junta
                        </span>
                        <!--
                            Negada: las dos salidas que la Junta deja abiertas.

                            Acá sólo decía «No autorizada», en rojo y sin nada
                            más. La persona quedaba atascada: ni contratable
                            —la orden no autoriza— ni descartable —el botón de
                            descarte vive en las columnas del embudo y los
                            seleccionados ya no están ahí—, así que seguía
                            ocupando el puesto de elegido para siempre. El
                            propio diálogo de la Junta promete que Gestión
                            Humana puede «insistir, bajar el salario o cerrar
                            la vacante»; las tres estaban cerradas.
                        -->
                        <div v-else class="flex items-center gap-2">
                            <span class="text-xs text-rose-600">No autorizada</span>
                            <button @click="pedirOrden(c)" :disabled="procesando"
                                class="btn-secondary text-xs py-1 px-3">
                                Volver a pedirla
                            </button>
                            <button @click="modalDescarte = c"
                                class="text-xs py-1 px-3 rounded border border-surface-300 text-surface-600 hover:bg-surface-50">
                                Descartar
                            </button>
                        </div>
                    </li>
                </ul>
            </div>

            <div v-if="descartados" class="card p-4">
                <button @click="verDescartados = !verDescartados" class="text-sm font-bold text-surface-500 uppercase">
                    Descartados ({{ descartados }}) {{ verDescartados ? '▾' : '▸' }}
                </button>
                <ul v-if="verDescartados" class="text-sm divide-y mt-2">
                    <li v-for="c in listaDescartados" :key="c.id" class="py-2">
                        <div class="font-semibold">{{ c.nombre }}</div>
                        <div class="text-xs text-surface-500">{{ c.motivo_descarte }}</div>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Nueva hoja de vida -->
        <div v-if="modalNuevo" @click.self="modalNuevo = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-xl w-full max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-bold mb-3">Agregar hoja de vida</h3>
                <div class="space-y-3">
                    <div>
                        <label class="text-xs font-semibold">Nombre completo *</label>
                        <input v-model="nuevo.nombre" class="input w-full" autofocus />
                        <p v-if="errores.nombre" class="text-xs text-rose-600 mt-1">{{ errores.nombre }}</p>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs font-semibold">Cédula</label>
                            <input v-model="nuevo.documento" class="input w-full" />
                            <p class="text-[10px] text-surface-400 mt-0.5">Con esto se consultan los antecedentes.</p>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Teléfono</label>
                            <input v-model="nuevo.telefono" class="input w-full" />
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Correo</label>
                        <input type="email" v-model="nuevo.email" class="input w-full" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold">¿De dónde llegó?</label>
                        <select v-model="nuevo.fuente" class="input w-full">
                            <option v-for="f in fuentes" :key="f.valor" :value="f.valor">{{ f.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Hoja de vida (PDF o imagen)</label>
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                            @change="archivo = $event.target.files[0]" class="input w-full text-xs" />
                        <p v-if="errores.cv" class="text-xs text-rose-600 mt-1">{{ errores.cv }}</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Notas</label>
                        <textarea v-model="nuevo.notas" rows="2" class="input w-full"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button @click="modalNuevo = false" class="btn-secondary">Cancelar</button>
                    <button @click="crear" :disabled="procesando || !nuevo.nombre" class="btn-primary">
                        {{ procesando ? 'Guardando...' : 'Agregar' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Antecedentes judiciales -->
        <div v-if="modalAntecedentes" @click.self="modalAntecedentes = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">Antecedentes de {{ modalAntecedentes.nombre }}</h3>
                <p class="text-xs text-surface-500 mb-3">
                    Se consultan en la página de la rama judicial con la cédula. Acá queda escrito qué salió.
                </p>
                <input v-model="resultadoAnt" class="input w-full" autofocus
                    placeholder="Sin antecedentes / Registra proceso por..." />
                <p v-if="errores.resultado" class="text-xs text-rose-600 mt-1">{{ errores.resultado }}</p>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalAntecedentes = null" class="btn-secondary">Cancelar</button>
                    <button @click="guardarAntecedentes" :disabled="procesando || !resultadoAnt" class="btn-primary">
                        Guardar
                    </button>
                </div>
            </div>
        </div>

        <!-- Valoración -->
        <div v-if="modalValoracion" @click.self="modalValoracion = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold mb-3">Valorar a {{ modalValoracion.nombre }}</h3>
                <select v-model="val.valoracion" class="input w-full">
                    <option v-for="v in valoraciones" :key="v.valor" :value="v.valor">{{ v.label }}</option>
                </select>
                <div class="mt-3">
                    <label class="text-xs font-semibold">
                        Observación <span v-if="exigeObs" class="text-rose-600">· obligatoria</span>
                    </label>
                    <textarea v-model="val.observacion" rows="3" class="input w-full"
                        placeholder="Qué es lo que no cumple del todo"></textarea>
                    <p v-if="errores.observacion" class="text-xs text-rose-600 mt-1">{{ errores.observacion }}</p>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalValoracion = null" class="btn-secondary">Cancelar</button>
                    <button @click="guardarValoracion" :disabled="procesando || (exigeObs && !val.observacion)"
                        class="btn-primary">Guardar</button>
                </div>
            </div>
        </div>

        <!--
            Entrevista · un modal para los dos formatos. El cuestionario viene
            del servidor: el formato del área manda y la pantalla lo copia.
        -->
        <div v-if="modalEntrevista" @click.self="modalEntrevista = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-3xl w-full max-h-[90vh] overflow-y-auto space-y-3">
                <h3 class="text-lg font-bold">
                    {{ { saber_hacer: 'Entrevista de saber hacer',
                         psicologica: 'Entrevista psicológica',
                         ser: 'Entrevista del ser' }[tipoEntrevista] }}
                    · {{ modalEntrevista.nombre }}
                </h3>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="text-xs uppercase text-surface-500">Fecha</label>
                        <input v-model="entrevista.fecha" type="date" class="input w-full mt-1" />
                        <p v-if="errores.fecha" class="text-xs text-rose-600 mt-1">{{ errores.fecha }}</p>
                    </div>
                    <div>
                        <label class="text-xs uppercase text-surface-500">Quién entrevistó</label>
                        <input v-model="entrevista.entrevistador_nombre" class="input w-full mt-1" />
                        <p v-if="errores.entrevistador_nombre" class="text-xs text-rose-600 mt-1">
                            {{ errores.entrevistador_nombre }}
                        </p>
                    </div>
                    <div>
                        <label class="text-xs uppercase text-surface-500">Cargo</label>
                        <input v-model="entrevista.entrevistador_cargo" class="input w-full mt-1" />
                    </div>
                </div>

                <!-- Saber hacer: tres bloques de preguntas y seis calificaciones -->
                <template v-if="esSaberHacer">
                    <div v-for="(b, clave) in cuestionarios.saber_hacer" :key="clave" class="pt-2 border-t">
                        <h4 class="text-xs font-bold uppercase text-surface-500">{{ b.titulo }}</h4>
                        <p v-if="b.nota" class="text-[11px] text-surface-400 italic mb-1">{{ b.nota }}</p>
                        <div v-for="(pregunta, k) in b.preguntas" :key="k" class="mt-2">
                            <label class="text-xs text-surface-600">{{ pregunta }}</label>
                            <textarea v-model="entrevista.respuestas[k]" rows="2"
                                class="input w-full mt-1 text-sm"></textarea>
                        </div>
                    </div>

                    <div class="pt-2 border-t">
                        <h4 class="text-xs font-bold uppercase text-surface-500">Evaluación de la entrevista</h4>
                        <p class="text-[11px] text-surface-400 mb-1">
                            De 1 a 5, donde 1 es la menor puntuación y 5 la más alta.
                        </p>
                        <div v-for="(texto, k) in cuestionarios.items" :key="k"
                            class="flex items-center justify-between gap-3 py-1 border-b last:border-0">
                            <span class="text-xs">{{ texto }}</span>
                            <div class="flex gap-1 shrink-0">
                                <button v-for="n in 5" :key="n" type="button"
                                    @click="entrevista.calificaciones[k] = n"
                                    :class="['h-7 w-7 rounded text-xs font-bold',
                                        Number(entrevista.calificaciones[k]) === n
                                            ? 'bg-brand-600 text-white'
                                            : 'bg-surface-200 dark:bg-surface-700']">{{ n }}</button>
                            </div>
                        </div>
                        <div class="text-right text-sm font-bold mt-2">
                            Total: {{ totalEntrevista !== null ? totalEntrevista + ' de 30' : '—' }}
                        </div>
                    </div>
                </template>

                <!--
                    La del ser: seis criterios, su promedio y la banda.

                    A diferencia del saber hacer, acá el área SÍ definió qué
                    significa el puntaje, así que la pantalla lo dice mientras
                    se califica en vez de dejar un número suelto.
                -->
                <template v-else-if="tipoEntrevista === 'ser'">
                    <div class="pt-2 border-t">
                        <h4 class="text-xs font-bold uppercase text-surface-500">Criterios</h4>
                        <p class="text-[11px] text-surface-400 mb-1">
                            De 1 a 5, donde 1 es la menor puntuación y 5 la más alta,
                            de acuerdo con lo observado durante la entrevista.
                        </p>

                        <div v-for="(texto, k) in cuestionarios.ser" :key="k"
                            class="flex items-center justify-between gap-3 py-1 border-b last:border-0">
                            <span class="text-xs">{{ texto }}</span>
                            <div class="flex gap-1 shrink-0">
                                <button v-for="n in 5" :key="n" type="button"
                                    @click="entrevista.calificaciones[k] = n"
                                    :class="['h-7 w-7 rounded text-xs font-bold',
                                        Number(entrevista.calificaciones[k]) === n
                                            ? 'bg-brand-600 text-white'
                                            : 'bg-surface-200 dark:bg-surface-700']">{{ n }}</button>
                            </div>
                        </div>

                        <!-- Mientras falte un criterio no se muestra promedio: ver `promedioSer`. -->
                        <div v-if="faltanCriterios > 0"
                            class="mt-3 rounded border border-amber-300 bg-amber-50 dark:bg-amber-950/20 p-2 text-xs text-amber-800 dark:text-amber-300">
                            Falta{{ faltanCriterios === 1 ? '' : 'n' }} {{ faltanCriterios }}
                            criterio{{ faltanCriterios === 1 ? '' : 's' }} por calificar.
                            El resultado sale con los seis.
                        </div>

                        <div v-else class="mt-3 rounded border p-3"
                            :class="{
                                'border-rose-300 bg-rose-50 dark:bg-rose-950/20': bandaSer?.color === 'danger',
                                'border-amber-300 bg-amber-50 dark:bg-amber-950/20': bandaSer?.color === 'warning',
                                'border-emerald-300 bg-emerald-50 dark:bg-emerald-950/20': bandaSer?.color === 'success',
                            }">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-sm font-bold">{{ bandaSer?.label }}</span>
                                <span class="text-xs">
                                    Total {{ totalEntrevista }} de 30 · promedio
                                    <strong>{{ promedioSer?.toFixed(2).replace('.', ',') }}</strong>
                                </span>
                            </div>
                            <p class="text-[11px] mt-1">{{ bandaSer?.significa }}</p>
                            <p class="text-[11px] text-surface-500 mt-1">
                                Sugiere, no decide: la valoración final la firma quien entrevista.
                            </p>
                        </div>
                    </div>
                </template>

                <!-- Psicológica: tres tablas que crecen y los bloques abiertos -->
                <template v-else>
                    <div v-for="(b, clave) in cuestionarios.psicologica" :key="clave" class="pt-2 border-t">
                        <h4 class="text-xs font-bold uppercase text-surface-500">{{ b.titulo }}</h4>

                        <template v-if="b.tabla">
                            <div v-for="(fila, i) in filasTabla(clave)" :key="i"
                                class="flex gap-1 items-center mt-1">
                                <template v-for="(titulo, col) in b.tabla" :key="col">
                                    <select v-if="col === 'nivel'" v-model="fila[col]"
                                        class="input flex-1 min-w-0 text-xs py-1">
                                        <option value="">Nivel…</option>
                                        <option v-for="niv in cuestionarios.niveles" :key="niv" :value="niv">
                                            {{ niv }}
                                        </option>
                                    </select>
                                    <input v-else v-model="fila[col]" :placeholder="titulo"
                                        class="input flex-1 min-w-0 text-xs py-1" />
                                </template>
                                <button type="button" @click="quitarFila(clave, i)"
                                    class="text-surface-400 hover:text-rose-600 shrink-0">
                                    <Trash2 class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <button type="button" @click="agregarFila(clave)"
                                class="text-xs text-brand-600 hover:underline mt-1">+ Agregar fila</button>
                        </template>

                        <div v-for="(pregunta, k) in b.campos" :key="k" class="mt-2">
                            <label class="text-xs text-surface-600">{{ pregunta }}</label>
                            <textarea v-model="entrevista.respuestas[k]" rows="2"
                                class="input w-full mt-1 text-sm"></textarea>
                        </div>
                    </div>

                    <p class="text-[11px] text-surface-400 pt-2 border-t">
                        Este formato lleva datos del núcleo familiar del aspirante. Su manejo está sujeto
                        a la Ley 1581 de 2012: queda en la carpeta del proceso y no sale de Gestión Humana.
                    </p>
                </template>

                <div class="pt-2 border-t">
                    <label class="text-xs uppercase text-surface-500">Observaciones</label>
                    <textarea v-model="entrevista.observaciones" rows="2" class="input w-full mt-1"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button @click="modalEntrevista = null" class="btn-secondary">Cancelar</button>
                    <button @click="guardarEntrevista" :disabled="procesando" class="btn-primary">
                        {{ procesando ? 'Guardando…' : 'Guardar entrevista' }}
                    </button>
                </div>
            </div>
        </div>

        <!--
            Prueba psicotécnica · Fase C.

            Se pide el concepto, quién lo firma y el informe. No hay dónde
            escribir respuestas a propósito: no se guardan en ninguna parte.
        -->
        <div v-if="modalPrueba" @click.self="modalPrueba = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-xl w-full max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-bold">Prueba psicotécnica de {{ modalPrueba.nombre }}</h3>
                <p class="text-xs text-surface-500 mb-3">
                    Se guarda el concepto del profesional y el informe firmado.
                    Las respuestas de la prueba no se guardan: son dato sensible de salud.
                </p>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-semibold">Prueba</label>
                        <select v-model="prueba.instrumento" class="input w-full mt-1">
                            <option v-for="i in instrumentos" :key="i.valor" :value="i.valor">{{ i.label }}</option>
                        </select>
                        <p class="text-[11px] text-surface-500 mt-1">{{ instrumentoSel?.que_mide }}</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Fecha de aplicación</label>
                        <input v-model="prueba.fecha_aplicacion" type="date" class="input w-full mt-1" />
                        <p v-if="errores.fecha_aplicacion" class="text-xs text-rose-600 mt-1">
                            {{ errores.fecha_aplicacion }}
                        </p>
                    </div>
                </div>

                <div v-if="prueba.instrumento === 'otro'" class="mt-3">
                    <label class="text-xs font-semibold">¿Cuál prueba?</label>
                    <input v-model="prueba.instrumento_otro" class="input w-full mt-1"
                        placeholder="16PF, Wartegg, prueba técnica del cargo…" />
                    <p v-if="errores.instrumento_otro" class="text-xs text-rose-600 mt-1">
                        {{ errores.instrumento_otro }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3 mt-3">
                    <div>
                        <label class="text-xs font-semibold">Quién la aplicó</label>
                        <input v-model="prueba.profesional_nombre" class="input w-full mt-1"
                            placeholder="Nombre del profesional" />
                        <p v-if="errores.profesional_nombre" class="text-xs text-rose-600 mt-1">
                            {{ errores.profesional_nombre }}
                        </p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">
                            Tarjeta profesional
                            <span v-if="instrumentoSel?.exige_profesional" class="text-rose-600">· obligatoria</span>
                        </label>
                        <input v-model="prueba.profesional_tarjeta" class="input w-full mt-1" />
                        <p v-if="instrumentoSel?.exige_profesional" class="text-[11px] text-surface-500 mt-1">
                            Es prueba clínica: sólo la aplica un psicólogo con tarjeta (Ley 1090 de 2006).
                        </p>
                        <p v-if="errores.profesional_tarjeta" class="text-xs text-rose-600 mt-1">
                            {{ errores.profesional_tarjeta }}
                        </p>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="text-xs font-semibold">Concepto</label>
                    <select v-model="prueba.concepto" class="input w-full mt-1">
                        <option v-for="k in conceptos" :key="k.valor" :value="k.valor">{{ k.label }}</option>
                    </select>
                </div>

                <div class="mt-3">
                    <label class="text-xs font-semibold">
                        Observaciones
                        <span v-if="conceptoSel?.exige_observaciones" class="text-rose-600">· obligatorias</span>
                    </label>
                    <textarea v-model="prueba.observaciones" rows="3" class="input w-full mt-1"
                        placeholder="Lo que el jefe debería tener en cuenta. Sin detalles clínicos."></textarea>
                    <p v-if="errores.observaciones" class="text-xs text-rose-600 mt-1">{{ errores.observaciones }}</p>
                </div>

                <div class="mt-3">
                    <label class="text-xs font-semibold">Informe firmado (PDF)</label>
                    <input type="file" accept="application/pdf"
                        @change="informe = $event.target.files[0]" class="input w-full mt-1 text-xs" />
                    <p class="text-[11px] text-surface-500 mt-1">
                        Se guarda en el disco privado. Se abre sólo desde acá y queda anotado quién lo abrió.
                    </p>
                    <p v-if="errores.informe" class="text-xs text-rose-600 mt-1">{{ errores.informe }}</p>
                </div>

                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalPrueba = null" class="btn-secondary">Cancelar</button>
                    <button @click="guardarPrueba" :disabled="procesando" class="btn-primary">
                        {{ procesando ? 'Guardando…' : 'Guardar concepto' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Descarte -->
        <div v-if="modalDescarte" @click.self="modalDescarte = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">Descartar a {{ modalDescarte.nombre }}</h3>
                <p class="text-xs text-surface-500 mb-3">
                    Sin el motivo, en tres meses nadie sabe si se le puede volver a llamar.
                </p>
                <textarea v-model="motivoDescarte" rows="3" class="input w-full" autofocus
                    placeholder="No cumple la experiencia pedida / No se presentó a la entrevista..."></textarea>
                <p v-if="errores.motivo" class="text-xs text-rose-600 mt-1">{{ errores.motivo }}</p>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalDescarte = null" class="btn-secondary">Cancelar</button>
                    <button @click="guardarDescarte" :disabled="procesando || motivoDescarte.trim().length < 5"
                        class="btn-primary">Descartar</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
