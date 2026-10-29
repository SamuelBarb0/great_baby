<script setup>
/*
 * La ficha del empleado: su historia laboral.
 *
 * Lo que manda la pantalla es el período de prueba. Hoy se vence solo y la
 * persona simplemente se queda; pasada esa fecha, terminar el contrato ya no
 * se puede sin justa causa ni indemnización. Por eso va arriba y en color.
 */
import { ref, reactive, computed } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft, User, Clock, GraduationCap, FileText, Upload, History, AlertTriangle, Printer, LogOut, KeyRound,
    IdCard, Package, Trash2, Wallet,
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
    empleado: { type: Object, required: true },
    documentos: { type: Array, default: () => [] },
    tipos_documento: { type: Array, default: () => [] },
    tipos_retiro: { type: Array, default: () => [] },
    novedades: { type: Array, default: () => [] },
    tipos_examen: { type: Array, default: () => [] },
    evaluaciones_prueba: { type: Array, default: () => [] },
    motivos_salida: { type: Array, default: () => [] },
    criterios_retiro: { type: Array, default: () => [] },
    entrevista_retiro: { type: Object, default: null },
    induccion: { type: Object, default: null },
    temario_en_blanco: { type: Array, default: () => [] },
    liquidacion: { type: Object, default: null },
    puedo_liquidar: { type: Object, default: () => ({}) },
    bloques_entrega: { type: Array, default: () => [] },
    entrega_cargo: { type: Object, default: null },
    puedo_firmar: { type: Object, default: () => ({}) },
    bitacora: { type: Array, default: () => [] },
});

const procesando = ref(false);
const errores = ref({});
const url = (s = '') => `/app/rrhh/empleados/${props.empleado.id}${s}`;

/*
 * Liquidación definitiva · F10.
 *
 * Los tres ajustes que el sistema no puede saber solo —vacaciones ya
 * tomadas, cesantías ya consignadas e indemnización— se preguntan acá. Lo
 * demás sale de las fechas y del salario.
 */
const modalLiquidacion = ref(false);
const modalPagoLiq = ref(false);
const pagoLiq = reactive({ fecha_pago: hoyBogota() });
const liq = reactive({
    vacaciones_tomadas: 0, cesantias_ya_pagadas: 0,
    indemnizacion: 0, indemnizacion_concepto: '',
    otros_descuentos: 0, otros_descuentos_concepto: '',
    observaciones: '',
});

const badgeLiq = (c) => ({
    success: 'bg-emerald-500/15 text-emerald-700',
    info: 'bg-blue-500/15 text-blue-700',
    warning: 'bg-amber-500/15 text-amber-800',
}[c] || 'bg-surface-200 text-surface-700');

const abrirLiquidacion = () => {
    errores.value = {};
    Object.assign(liq, {
        vacaciones_tomadas: props.liquidacion?.vacaciones_tomadas ?? 0,
        cesantias_ya_pagadas: props.liquidacion?.cesantias_ya_pagadas ?? 0,
        indemnizacion: props.liquidacion?.indemnizacion ?? 0,
        indemnizacion_concepto: props.liquidacion?.indemnizacion_concepto ?? '',
        otros_descuentos: props.liquidacion?.otros_descuentos ?? 0,
        otros_descuentos_concepto: props.liquidacion?.otros_descuentos_concepto ?? '',
        observaciones: props.liquidacion?.observaciones ?? '',
    });
    modalLiquidacion.value = true;
};

const calcularLiquidacion = () => enviar('/liquidacion', { ...liq },
    { onSuccess: () => { modalLiquidacion.value = false; } });

const pagarLiquidacion = () => {
    enviar('/liquidacion/pagar', { ...pagoLiq });
    modalPagoLiq.value = false;
};

const enviar = (sufijo, datos = {}, extra = {}) => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post(url(sufijo), datos, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onFinish: () => (procesando.value = false),
        ...extra,
    });
};

/*
 * Datos laborales · Fase A.
 *
 * El formulario arranca con el valor EFECTIVO de cada campo, no con lo que
 * está guardado en esa columna. Las afiliaciones de la gente registrada antes
 * de la Fase A viven en la contratación, y el servidor ya las manda resueltas:
 * si la casilla apareciera vacía estando heredada, el primer guardado las
 * borraría sin que nadie lo pidiera.
 */
const editandoFicha = ref(false);
const ficha = reactive({ ...props.empleado.ficha });

const guardarFicha = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.put(url('/ficha'), { ...ficha }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => (editandoFicha.value = false),
        onFinish: () => (procesando.value = false),
    });
};

const resumenFicha = computed(() => {
    const f = props.empleado.ficha;
    const cuenta = [f.banco, f.tipo_cuenta, f.cuenta_bancaria].filter(Boolean).join(' ');
    const adicional = f.valor_adicional
        ? `${money(f.valor_adicional)}${f.concepto_adicional ? ` (${f.concepto_adicional})` : ''}`
        : null;

    return [
        { k: 'Nacimiento', v: f.fecha_nacimiento },
        { k: 'Grupo sanguíneo', v: f.grupo_sanguineo },
        { k: 'Dirección', v: [f.direccion, f.ciudad].filter(Boolean).join(', ') },
        { k: 'Teléfono', v: f.telefono },
        { k: 'Afiliado por', v: f.empresa_afiliadora },
        { k: 'Horario', v: f.horario },
        { k: 'Tipo de pago', v: f.periodicidad_pago },
        { k: 'Adicional', v: adicional },
        { k: 'Cuenta bancaria', v: cuenta },
        { k: 'EPS', v: f.eps },
        { k: 'Pensiones', v: f.pension },
        { k: 'Cesantías', v: f.cesantias },
        { k: 'ARL', v: [f.arl, f.arl_nivel_riesgo && `riesgo ${f.arl_nivel_riesgo}`].filter(Boolean).join(' · ') },
    ];
});

/*
 * Entrega de cargo · Fase F2.
 *
 * Seis listas de largo variable. Las filas se manejan como un arreglo plano
 * con su bloque: así guardar es mandar la lista entera y borrar una fila es
 * quitarla, sin un endpoint por renglón.
 */
const modalEntrega = ref(false);
const entrega = reactive({ fecha: '', recibe_id: null, recibe_nombre: '', observaciones: '', items: [] });

const cuantos = (bloque) =>
    (props.entrega_cargo?.items ?? []).filter((i) => i.bloque === bloque).length;

const filasDe = (bloque) => entrega.items.filter((i) => i.bloque === bloque);

const agregarFila = (bloque) => entrega.items.push({ bloque, nombre: '', detalle: '' });
const quitarFila = (fila) => entrega.items.splice(entrega.items.indexOf(fila), 1);

const abrirEntrega = () => {
    Object.assign(entrega, {
        fecha: props.entrega_cargo?.fecha ?? hoyBogota(),
        recibe_id: props.entrega_cargo?.recibe_id ?? null,
        recibe_nombre: props.entrega_cargo?.recibe_nombre ?? '',
        observaciones: props.entrega_cargo?.observaciones ?? '',
        // Copia, no referencia: cancelar no debe dejar a medias lo guardado.
        items: (props.entrega_cargo?.items ?? []).map((i) => ({ ...i })),
    });
    errores.value = {};
    modalEntrega.value = true;
};

const guardarEntrega = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post(url('/entrega-cargo'), { ...entrega }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => (modalEntrega.value = false),
        onFinish: () => (procesando.value = false),
    });
};

/** Cada área firma la suya; el servidor lo vuelve a comprobar. */
const confirmarEntrega = (area) => enviar(`/entrega-cargo/${area}/confirmar`);

/*
 * Entrevista de retiro · Fase F1.
 *
 * Si ya existe se precarga para corregirla: una entrevista se conversa, y es
 * normal volver a abrirla para completar lo que quedó suelto.
 */
const modalEntrevista = ref(false);
const entrevista = reactive({});

const abrirEntrevista = () => {
    const vacio = {
        fecha_entrevista: hoyBogota(),
        salida: null, motivo_principal: null, motivo_otro: '', detalle_motivo: '',
        que_valoro: '', que_mejorar: '',
        funciones_correspondian: null, funciones_explicacion: '',
        recomendaria: null, recomendaria_por_que: '',
        accesos_cerrados: false, observaciones: '',
    };
    props.criterios_retiro.forEach((c) => {
        vacio[`n_${c.clave}`] = null;
        vacio[`c_${c.clave}`] = '';
    });

    // Lo guardado pisa al vacío, nunca al revés: abrir para corregir no borra.
    Object.keys(entrevista).forEach((k) => delete entrevista[k]);
    Object.assign(entrevista, vacio, props.entrevista_retiro ?? {});
    errores.value = {};
    modalEntrevista.value = true;
};

const guardarEntrevista = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post(url('/entrevista-retiro'), { ...entrevista }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => (modalEntrevista.value = false),
        onFinish: () => (procesando.value = false),
    });
};

/* período de prueba */
const hasta = ref(props.empleado.prueba_hasta || props.empleado.prueba_sugerida);
const guardarPrueba = () => enviar('/prueba', { hasta: hasta.value });

const modalEvaluar = ref(false);
const evaluacion = reactive({ resultado: 'continua', observacion: '' });
const evaluar = () => enviar('/prueba/evaluar', { ...evaluacion },
    { onSuccess: () => (modalEvaluar.value = false) });

/* inducción */
/*
 * F8 · Inducción con temario.
 *
 * Antes era un campo de texto libre y de siete personas lo llenó una. El
 * temario viene del servidor —sale de una regla que el área edita— y cada
 * inducción guarda el suyo, así que agregar un tema no vuelve incompletas
 * las que ya se dieron.
 */
const modalInduccion = ref(false);
const ind = reactive({
    fecha: '', responsable_nombre: '', responsable_cargo: '',
    temas: [], observaciones: '', firmada_at: '',
});

const temasVistos = computed(() => ind.temas.filter((t) => t.cubierto).length);

const abrirInduccion = () => {
    errores.value = {};
    Object.assign(ind, {
        fecha: props.induccion?.fecha ?? hoyBogota(),
        responsable_nombre: props.induccion?.responsable_nombre ?? '',
        responsable_cargo: props.induccion?.responsable_cargo ?? '',
        // El temario guardado si ya existe; el vigente si es la primera vez.
        temas: JSON.parse(JSON.stringify(props.induccion?.temas ?? props.temario_en_blanco)),
        observaciones: props.induccion?.observaciones ?? '',
        firmada_at: props.induccion?.firmada_at ?? '',
    });
    modalInduccion.value = true;
};

const guardarTemario = () => enviar('/induccion-temario', { ...ind },
    { onSuccess: () => { modalInduccion.value = false; } });



/* documentos */
const modalDoc = ref(false);
const doc = reactive({ tipo: 'contrato', nombre: '', fecha_vencimiento: null });
const archivo = ref(null);
const subirDoc = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post(url('/documento'), { ...doc, archivo: archivo.value }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { modalDoc.value = false; archivo.value = null; },
        onError: (e) => (errores.value = e),
        onFinish: () => (procesando.value = false),
    });
};

/*
 * Registrar el retiro.
 *
 * Es lo que le da de comer al indicador de rotación: sin el motivo, a la
 * pregunta de la Junta —«por qué se retira la gente»— no hay con qué
 * responderle.
 */
/*
 * Crear el acceso al portal.
 *
 * El enlace se muestra en pantalla además de intentar el correo: acá el correo
 * del empleado muchas veces no existe o no lo revisa, y Gestión Humana lo
 * manda por el WhatsApp, que es como se comunican.
 */
const modalAcceso = ref(false);
const emailAcceso = ref(props.empleado.email || '');
const crearAcceso = () => enviar('/acceso', { email: emailAcceso.value },
    { onSuccess: () => (modalAcceso.value = false) });


const modalRetiro = ref(false);
const retiro = reactive({
    fecha_retiro: hoyBogota(),
    tipo_retiro: 'renuncia',
    motivo_retiro: '',
});
const guardarRetiro = () => enviar('/retiro', { ...retiro },
    { onSuccess: () => (modalRetiro.value = false) });

const money = (n) => (n ? '$' + Number(n).toLocaleString('es-CO', { maximumFractionDigits: 0 }) : '—');

/* Cómo se ve la alerta de la prueba, según cuánto falte. */
const prueba = computed(() => {
    const e = props.empleado;
    if (e.prueba_evaluada_at) {
        return {
            tono: e.prueba_resultado === 'continua' ? 'emerald' : 'surface',
            texto: e.prueba_resultado === 'continua'
                ? `Superó el período de prueba el ${e.prueba_evaluada_at}.`
                : `No continuó. ${e.prueba_observacion || ''}`,
        };
    }
    if (! e.prueba_hasta) {
        return { tono: 'amber', texto: 'Todavía no se fijó hasta cuándo va el período de prueba.' };
    }
    if (e.prueba_dias < 0) {
        return { tono: 'rose', texto: `El período de prueba se venció hace ${Math.abs(e.prueba_dias)} día(s). Terminar el contrato ya no es sin indemnización.` };
    }
    return {
        tono: e.prueba_dias <= 15 ? 'amber' : 'surface',
        texto: `Quedan ${e.prueba_dias} día(s) de período de prueba (hasta el ${e.prueba_hasta}). Hay que decidir antes.`,
    };
});

const tono = (t) => ({
    emerald: 'bg-emerald-500/10 border-emerald-500 text-emerald-700',
    amber: 'bg-amber-500/10 border-amber-500 text-amber-700',
    rose: 'bg-rose-500/10 border-rose-500 text-rose-700',
    surface: 'bg-surface-100 dark:bg-surface-900 border-surface-300 text-surface-600',
}[t]);
</script>

<template>
    <Head :title="empleado.nombre" />
    <AppLayout>
        <div class="space-y-4">
            <Link href="/app/rrhh/empleados" class="text-sm text-surface-500 hover:text-brand-600 inline-flex items-center gap-1">
                <ArrowLeft class="h-4 w-4" /> Volver a empleados
            </Link>

            <div v-if="$page.props.flash?.success"
                class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>

            <!-- El enlace de activación, para copiarlo y mandarlo por WhatsApp -->
            <div v-if="$page.props.flash?.enlace_activacion"
                class="p-3 rounded-lg bg-blue-500/10 border-l-4 border-blue-500 text-sm">
                <p class="font-semibold text-blue-800">Mandale este enlace a la persona:</p>
                <code class="block mt-1 p-2 rounded bg-white dark:bg-surface-900 text-xs break-all select-all">
                    {{ $page.props.flash.enlace_activacion }}
                </code>
                <p class="text-xs text-blue-700 mt-1">
                    Sirve una sola vez y vence en 7 días. Ahí pone su contraseña y ya entra con su correo.
                </p>
            </div>

            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <User class="h-6 w-6 text-brand-600" />{{ empleado.nombre }}
                    </h1>
                    <p class="text-sm text-surface-500">
                        {{ empleado.cargo }} · {{ empleado.area }} · {{ empleado.documento }}
                        <span v-if="empleado.estado !== 'activo'" class="text-rose-600 font-semibold"> · {{ empleado.estado }}</span>
                    </p>
                </div>

                <!--
                    La norma exige la historia laboral también en físico, así
                    que esto se imprime y se archiva. Lleva la bitácora entera.
                -->
                <div class="flex gap-2 flex-wrap">
                    <a :href="`/app/rrhh/empleados/${empleado.id}/pdf`" class="btn-secondary">
                        <Printer class="h-4 w-4" /> Historia laboral en PDF
                    </a>
                    <button v-if="empleado.estado === 'activo' && !empleado.user_id" @click="modalAcceso = true"
                        class="btn-secondary">
                        <KeyRound class="h-4 w-4" /> Crear acceso al portal
                    </button>
                    <button v-if="empleado.estado === 'activo'" @click="modalRetiro = true"
                        class="btn-secondary text-rose-600">
                        <LogOut class="h-4 w-4" /> Registrar retiro
                    </button>
                </div>
            </div>

            <!-- Período de prueba · lo que hoy se pasa de largo -->
            <div :class="['p-4 rounded-lg border-l-4 flex items-start justify-between gap-3 flex-wrap', tono(prueba.tono)]">
                <div class="flex items-start gap-2">
                    <AlertTriangle v-if="prueba.tono === 'rose'" class="h-5 w-5 shrink-0 mt-0.5" />
                    <Clock v-else class="h-5 w-5 shrink-0 mt-0.5" />
                    <div>
                        <div class="font-bold text-sm">Período de prueba</div>
                        <p class="text-sm">{{ prueba.texto }}</p>
                    </div>
                </div>
                <div v-if="!empleado.prueba_evaluada_at" class="flex items-end gap-2 flex-wrap">
                    <div>
                        <label class="text-[10px] uppercase font-semibold block">Hasta</label>
                        <input type="date" v-model="hasta" class="input text-xs py-1" />
                        <p v-if="errores.hasta" class="text-xs text-rose-600 mt-1">{{ errores.hasta }}</p>
                    </div>
                    <button @click="guardarPrueba" :disabled="procesando" class="btn-secondary text-xs">Guardar</button>
                    <button v-if="empleado.prueba_hasta" @click="modalEvaluar = true" :disabled="procesando"
                        class="btn-primary text-xs">Evaluar</button>
                </div>
            </div>

            <div class="grid lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2 space-y-4">
                    <div class="card p-5">
                        <h3 class="font-bold text-sm uppercase text-surface-500 mb-3">Datos del contrato</h3>
                        <div class="grid sm:grid-cols-3 gap-4 text-sm">
                            <div><span class="text-xs text-surface-400 uppercase block">Ingresó</span>{{ empleado.fecha_ingreso }}</div>
                            <div><span class="text-xs text-surface-400 uppercase block">Contrato</span>{{ empleado.tipo_contrato }}</div>
                            <div><span class="text-xs text-surface-400 uppercase block">Salario</span>{{ money(empleado.salario) }}</div>
                            <div>
                                <span class="text-xs text-surface-400 uppercase block">Portal</span>
                                <span v-if="empleado.activacion_pendiente" class="text-amber-600">Sin activar</span>
                                <span v-else-if="empleado.user_id" class="text-emerald-700">Activo</span>
                                <span v-else class="text-surface-400">Sin acceso</span>
                            </div>
                            <div v-if="empleado.fecha_retiro">
                                <span class="text-xs text-surface-400 uppercase block">Se retiró</span>
                                <span class="text-rose-600 font-semibold">{{ empleado.fecha_retiro }}</span>
                            </div>
                            <div><span class="text-xs text-surface-400 uppercase block">Teléfono</span>{{ empleado.telefono || '—' }}</div>
                            <div><span class="text-xs text-surface-400 uppercase block">Correo</span>{{ empleado.email || '—' }}</div>
                            <div v-if="empleado.vacante">
                                <span class="text-xs text-surface-400 uppercase block">Vino de</span>
                                <Link :href="`/app/rrhh/vacantes/${empleado.vacante.id}`" class="text-brand-600 hover:underline">
                                    {{ empleado.vacante.titulo }}
                                </Link>
                            </div>
                        </div>

                        <div v-if="empleado.afiliaciones" class="mt-4 pt-3 border-t">
                            <span class="text-xs text-surface-400 uppercase block mb-1">Afiliaciones</span>
                            <div class="flex flex-wrap gap-2 text-xs">
                                <span v-for="(v, k) in empleado.afiliaciones" :key="k"
                                    class="px-2 py-0.5 rounded bg-surface-200 dark:bg-surface-800">
                                    {{ k }}: {{ v || '—' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Fase A · los datos que pide el cuadro de novedades -->
                    <div class="card p-5">
                        <div class="flex items-center justify-between gap-2 flex-wrap mb-3">
                            <h3 class="font-bold text-sm uppercase text-surface-500 flex items-center gap-1">
                                <IdCard class="h-4 w-4" /> Datos laborales
                            </h3>
                            <button @click="editandoFicha = !editandoFicha" class="btn-secondary text-xs">
                                {{ editandoFicha ? 'Cancelar' : 'Editar' }}
                            </button>
                        </div>

                        <div v-if="empleado.faltantes.length && !editandoFicha"
                            class="mb-3 p-2.5 rounded bg-amber-500/10 border-l-4 border-amber-500 text-xs">
                            <span class="font-bold">Falta para el cuadro de novedades:</span>
                            {{ empleado.faltantes.join(', ') }}.
                        </div>
                        <div v-else-if="!editandoFicha"
                            class="mb-3 p-2.5 rounded bg-emerald-500/10 border-l-4 border-emerald-500 text-xs">
                            Ficha completa: esta persona sale entera en el cuadro del mes.
                        </div>

                        <!-- Lectura -->
                        <dl v-if="!editandoFicha" class="grid sm:grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <div v-for="campo in resumenFicha" :key="campo.k">
                                <dt class="text-xs text-surface-400 uppercase">{{ campo.k }}</dt>
                                <dd :class="campo.v ? '' : 'text-surface-400'">{{ campo.v || '—' }}</dd>
                            </div>
                        </dl>

                        <!-- Edición -->
                        <div v-else class="space-y-3">
                            <div class="grid sm:grid-cols-2 gap-3">
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">Fecha de nacimiento</span>
                                    <input v-model="ficha.fecha_nacimiento" type="date" class="input w-full mt-1" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">Grupo sanguíneo y RH</span>
                                    <input v-model="ficha.grupo_sanguineo" placeholder="O+" class="input w-full mt-1" />
                                </label>
                                <label class="block sm:col-span-2">
                                    <span class="text-xs text-surface-400 uppercase">Dirección</span>
                                    <input v-model="ficha.direccion" class="input w-full mt-1" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">Ciudad</span>
                                    <input v-model="ficha.ciudad" class="input w-full mt-1" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">Teléfono</span>
                                    <input v-model="ficha.telefono" class="input w-full mt-1" />
                                </label>
                                <label class="block sm:col-span-2">
                                    <span class="text-xs text-surface-400 uppercase">Correo</span>
                                    <input v-model="ficha.email" type="email" class="input w-full mt-1" />
                                    <span v-if="errores.email" class="text-xs text-rose-600">{{ errores.email }}</span>
                                </label>
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">Afiliado por</span>
                                    <input v-model="ficha.empresa_afiliadora" class="input w-full mt-1" />
                                    <span class="text-[11px] text-surface-400">La razón social que figura como empleador.</span>
                                </label>
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">Horario</span>
                                    <input v-model="ficha.horario" class="input w-full mt-1" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">Tipo de pago</span>
                                    <select v-model="ficha.periodicidad_pago" class="input w-full mt-1">
                                        <option :value="null">—</option>
                                        <option value="mensual">Mensual</option>
                                        <option value="quincenal">Quincenal</option>
                                    </select>
                                </label>
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="block">
                                        <span class="text-xs text-surface-400 uppercase">Adicional</span>
                                        <input v-model.number="ficha.valor_adicional" type="number" min="0" class="input w-full mt-1" />
                                    </label>
                                    <label class="block">
                                        <span class="text-xs text-surface-400 uppercase">Concepto</span>
                                        <input v-model="ficha.concepto_adicional" placeholder="SSI" class="input w-full mt-1" />
                                    </label>
                                </div>

                                <!--
                                    El auxilio de transporte no depende sólo del
                                    sueldo: tampoco lo devenga quien vive en el
                                    sitio de trabajo ni quien tiene ruta de la
                                    empresa. El monto NO se edita acá —es legal
                                    y vive en Reglas—; lo que se decide acá es
                                    si esta persona lo devenga.
                                -->
                                <div class="pt-2 border-t">
                                    <label class="flex items-center gap-2 text-sm">
                                        <input v-model="ficha.auxilio_excluido" type="checkbox" class="rounded" />
                                        <span>No devenga auxilio de transporte</span>
                                    </label>
                                    <p class="text-xs text-surface-400 mt-1">
                                        Marcar sólo si vive en el lugar de trabajo o la empresa le pone el transporte.
                                        Por sueldo se decide solo: lo pierde quien gane más de dos mínimos.
                                    </p>
                                    <template v-if="ficha.auxilio_excluido">
                                        <input v-model="ficha.auxilio_excluido_motivo" class="input w-full mt-2"
                                            placeholder="Por qué: vive en la bodega, ruta de la empresa…" />
                                        <p v-if="errores.auxilio_excluido_motivo" class="text-xs text-rose-600 mt-1">
                                            {{ errores.auxilio_excluido_motivo }}
                                        </p>
                                    </template>
                                    <p v-else-if="empleado.ficha.por_que_no_auxilio"
                                        class="text-xs text-amber-700 mt-1">
                                        Hoy no lo devenga: {{ empleado.ficha.por_que_no_auxilio }}
                                    </p>
                                </div>
                            </div>

                            <div class="grid sm:grid-cols-3 gap-3 pt-2 border-t">
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">Banco</span>
                                    <input v-model="ficha.banco" class="input w-full mt-1" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">Tipo de cuenta</span>
                                    <select v-model="ficha.tipo_cuenta" class="input w-full mt-1">
                                        <option :value="null">—</option>
                                        <option value="ahorros">Ahorros</option>
                                        <option value="corriente">Corriente</option>
                                    </select>
                                </label>
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">N° de cuenta</span>
                                    <input v-model="ficha.cuenta_bancaria" class="input w-full mt-1" />
                                </label>
                            </div>

                            <div class="grid sm:grid-cols-3 gap-3 pt-2 border-t">
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">EPS</span>
                                    <input v-model="ficha.eps" class="input w-full mt-1" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">Pensiones</span>
                                    <input v-model="ficha.pension" class="input w-full mt-1" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">Cesantías</span>
                                    <input v-model="ficha.cesantias" class="input w-full mt-1" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">Caja</span>
                                    <input v-model="ficha.caja" class="input w-full mt-1" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">ARL</span>
                                    <input v-model="ficha.arl" class="input w-full mt-1" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-surface-400 uppercase">Nivel de riesgo</span>
                                    <select v-model="ficha.arl_nivel_riesgo" class="input w-full mt-1">
                                        <option :value="null">—</option>
                                        <option v-for="n in ['I', 'II', 'III', 'IV', 'V']" :key="n" :value="n">{{ n }}</option>
                                    </select>
                                    <span v-if="errores.arl_nivel_riesgo" class="text-xs text-rose-600">
                                        {{ errores.arl_nivel_riesgo }}
                                    </span>
                                </label>
                            </div>

                            <div class="flex justify-end gap-2">
                                <button @click="editandoFicha = false" class="btn-secondary">Cancelar</button>
                                <button @click="guardarFicha" :disabled="procesando" class="btn-primary">
                                    {{ procesando ? 'Guardando…' : 'Guardar ficha' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!--
                        Entrega de cargo · Fase F2.

                        Lo que evita que el conocimiento se vaya con la
                        persona. Tres áreas firman lo suyo y ninguna puede
                        responder por la otra.
                    -->
                    <div class="card p-5">
                        <div class="flex items-center justify-between gap-2 flex-wrap mb-1">
                            <h3 class="font-bold text-sm uppercase text-surface-500 flex items-center gap-1">
                                <Package class="h-4 w-4" /> Entrega de cargo
                            </h3>
                            <div class="flex gap-2">
                                <a v-if="entrega_cargo" :href="url('/entrega-cargo/pdf')"
                                    class="btn-secondary text-xs py-1 px-2">
                                    <Printer class="h-3.5 w-3.5" /> Imprimir
                                </a>
                                <button @click="abrirEntrega" class="btn-secondary text-xs py-1 px-2">
                                    {{ entrega_cargo ? 'Editar' : 'Registrar' }}
                                </button>
                            </div>
                        </div>

                        <p v-if="!entrega_cargo" class="text-sm text-surface-400">
                            Sin registrar. Es lo que evita que lo que sabía la persona se vaya con ella.
                        </p>

                        <div v-else class="text-sm">
                            <div class="text-xs text-surface-500">
                                {{ entrega_cargo.fecha }}
                                <span v-if="entrega_cargo.quien_recibe">· recibe {{ entrega_cargo.quien_recibe }}</span>
                            </div>

                            <!-- Cuántos renglones dejó en cada bloque -->
                            <div class="grid sm:grid-cols-2 gap-x-4 gap-y-1 mt-2 text-xs">
                                <div v-for="b in bloques_entrega" :key="b.clave" class="flex justify-between gap-2">
                                    <span class="text-surface-500">{{ b.titulo }}</span>
                                    <span :class="cuantos(b.clave) ? 'font-semibold' : 'text-surface-300'">
                                        {{ cuantos(b.clave) }}
                                    </span>
                                </div>
                            </div>

                            <!-- Las tres conformidades, cada una por su lado -->
                            <div class="mt-3 pt-3 border-t space-y-1.5">
                                <div v-for="(f, clave) in entrega_cargo.firmas" :key="clave"
                                    class="flex items-center justify-between gap-2 text-xs">
                                    <span>
                                        {{ f.titulo }}
                                        <span v-if="f.cuando" class="text-emerald-700 font-semibold">
                                            · conforme {{ f.cuando }} ({{ f.quien }})
                                        </span>
                                        <span v-else class="text-rose-600">· sin conformidad</span>
                                    </span>
                                    <button v-if="!f.cuando && puedo_firmar[clave]"
                                        @click="confirmarEntrega(clave)" :disabled="procesando"
                                        class="btn-secondary text-[11px] py-0.5 px-2 shrink-0">
                                        Dar conformidad
                                    </button>
                                </div>
                                <p v-if="errores.tesoreria" class="text-xs text-rose-600">{{ errores.tesoreria }}</p>
                            </div>

                            <div v-if="entrega_cargo.completa"
                                class="mt-2 p-2 rounded bg-emerald-500/10 border-l-4 border-emerald-500 text-xs">
                                Las tres áreas dieron conformidad. El paz y salvo se puede firmar.
                            </div>

                            <!--
                                El formato pide tres revisiones independientes.
                                Si una misma persona dio dos, se dice acá y se
                                imprime: queda firmado igual, pero sin aparentar
                                una revisión que no hubo.
                            -->
                            <div v-if="entrega_cargo.firmas_repetidas?.length"
                                class="mt-2 p-2 rounded bg-amber-500/10 border-l-4 border-amber-500 text-xs text-amber-800">
                                {{ entrega_cargo.firmas_repetidas.join(', ') }} dio más de una de las tres
                                conformidades. Queda anotado en el impreso.
                            </div>
                        </div>
                    </div>

                    <!--
                        Liquidación definitiva · F10.

                        Lo último que falta cuando alguien se va, y lo que la
                        persona está esperando. Sólo aparece si ya hay fecha
                        de retiro: antes de eso no hay contrato que liquidar.
                    -->
                    <div v-if="empleado.fecha_retiro && puedo_liquidar.ver" class="card p-5">
                        <div class="flex items-center justify-between gap-2 flex-wrap mb-1">
                            <h3 class="font-bold text-sm uppercase text-surface-500 flex items-center gap-1">
                                <Wallet class="h-4 w-4" /> Liquidación definitiva
                            </h3>
                            <div class="flex gap-2">
                                <a v-if="liquidacion" :href="url('/liquidacion/pdf')" target="_blank"
                                    class="btn-secondary text-xs py-1 px-2">
                                    <Printer class="h-3.5 w-3.5" /> Imprimir
                                </a>
                                <button v-if="puedo_liquidar.calcular && (!liquidacion || liquidacion.se_puede_rehacer)"
                                    @click="abrirLiquidacion" class="btn-secondary text-xs py-1 px-2">
                                    {{ liquidacion ? 'Recalcular' : 'Calcular' }}
                                </button>
                            </div>
                        </div>

                        <p v-if="!liquidacion" class="text-sm text-surface-400">
                            Todavía sin liquidar. Se calcula con cesantías, intereses, prima y vacaciones.
                        </p>

                        <template v-else>
                            <div class="flex items-center gap-2 flex-wrap text-xs mb-2">
                                <span :class="['px-2 py-0.5 rounded font-bold uppercase', badgeLiq(liquidacion.color)]">
                                    {{ liquidacion.estado_label }}
                                </span>
                                <span class="text-surface-500">{{ liquidacion.tiempo_servido }} de servicio</span>
                            </div>

                            <!-- La cuenta con su fórmula: es lo que la persona firma -->
                            <div v-for="(c, i) in liquidacion.conceptos" :key="i"
                                class="flex justify-between gap-3 py-1 text-xs border-b last:border-0">
                                <div>
                                    <span class="font-semibold">{{ c.concepto }}</span>
                                    <div v-if="c.formula" class="text-surface-500">{{ c.formula }}</div>
                                </div>
                                <div :class="['font-mono shrink-0', c.resta ? 'text-rose-700' : '']">
                                    {{ c.resta ? '−' : '+' }} {{ money(c.valor) }}
                                </div>
                            </div>

                            <div class="flex justify-between gap-3 mt-2 pt-2 border-t font-bold">
                                <span>Neto a pagar</span>
                                <span>{{ money(liquidacion.neto) }}</span>
                            </div>

                            <p v-if="liquidacion.manos_repetidas?.length"
                                class="text-xs text-amber-700 mt-2">
                                {{ liquidacion.manos_repetidas.join(', ') }} hizo más de una de las tres
                                (calcular, aprobar, pagar).
                            </p>

                            <p v-if="liquidacion.observaciones" class="text-xs text-surface-500 mt-2">
                                {{ liquidacion.observaciones }}
                            </p>

                            <div class="flex gap-2 mt-3 flex-wrap">
                                <button v-if="puedo_liquidar.aprobar && liquidacion.estado === 'borrador'"
                                    @click="enviar('/liquidacion/aprobar')" :disabled="procesando"
                                    class="btn-primary text-xs py-1 px-3">Aprobar</button>
                                <button v-if="puedo_liquidar.pagar && liquidacion.estado === 'aprobada'"
                                    @click="modalPagoLiq = true" :disabled="procesando"
                                    class="btn-primary text-xs py-1 px-3">Marcar pagada</button>
                            </div>
                            <p v-if="errores.aprobar" class="text-xs text-rose-600 mt-1">{{ errores.aprobar }}</p>

                            <div class="text-xs text-surface-500 mt-2 pt-2 border-t space-y-0.5">
                                <div v-if="liquidacion.liquidada">
                                    Calculada {{ liquidacion.liquidada }} · {{ liquidacion.liquidada_por }}
                                </div>
                                <div v-if="liquidacion.aprobada">
                                    Aprobada {{ liquidacion.aprobada }} · {{ liquidacion.aprobada_por }}
                                </div>
                                <div v-if="liquidacion.pagada">
                                    Pagada {{ liquidacion.pagada }} · {{ liquidacion.pagada_por }}
                                    <span v-if="liquidacion.fecha_pago"> · con fecha {{ liquidacion.fecha_pago }}</span>
                                </div>
                            </div>
                        </template>

                        <p v-if="errores.liquidacion" class="text-xs text-rose-600 mt-2">{{ errores.liquidacion }}</p>
                    </div>

                    <!--
                        Entrevista de retiro · Fase F1.

                        Es el único momento del proceso en que alguien dice la
                        verdad sin costo, y el ERP lo estaba dejando pasar: al
                        salir se guardaba fecha, tipo y un detalle en texto.
                    -->
                    <div class="card p-5">
                        <div class="flex items-center justify-between gap-2 flex-wrap mb-1">
                            <h3 class="font-bold text-sm uppercase text-surface-500 flex items-center gap-1">
                                <LogOut class="h-4 w-4" /> Entrevista de retiro
                            </h3>
                            <div class="flex gap-2">
                                <a v-if="entrevista_retiro" :href="url('/entrevista-retiro/pdf')"
                                    class="btn-secondary text-xs py-1 px-2">
                                    <Printer class="h-3.5 w-3.5" /> Imprimir
                                </a>
                                <button @click="abrirEntrevista" class="btn-secondary text-xs py-1 px-2">
                                    {{ entrevista_retiro ? 'Corregir' : 'Registrar' }}
                                </button>
                            </div>
                        </div>

                        <p v-if="!entrevista_retiro" class="text-sm text-surface-400">
                            Sin hacer. Es donde se sabe por qué se va la gente — el tipo de retiro
                            sólo dice cómo lo clasifica la empresa.
                        </p>

                        <div v-else class="text-sm">
                            <div class="text-xs text-surface-500">
                                {{ entrevista_retiro.fecha_entrevista }} ·
                                {{ entrevista_retiro.entrevistador }}
                                <span v-if="entrevista_retiro.salida">· salida {{ entrevista_retiro.salida }}</span>
                            </div>
                            <div v-if="entrevista_retiro.motivo_en_palabras" class="mt-1">
                                <span class="text-surface-400 uppercase text-[10px]">Motivo</span>
                                <p class="font-semibold">{{ entrevista_retiro.motivo_en_palabras }}</p>
                            </div>
                            <p v-if="entrevista_retiro.detalle_motivo" class="text-xs mt-1">
                                {{ entrevista_retiro.detalle_motivo }}
                            </p>

                            <div v-if="entrevista_retiro.promedio !== null" class="text-xs text-surface-500 mt-2">
                                Experiencia laboral: <strong>{{ entrevista_retiro.promedio }}</strong> de 5.
                            </div>

                            <!-- Lo que de verdad se mira: dónde calificó 1 o 2 -->
                            <div v-if="entrevista_retiro.puntos_bajos.length"
                                class="mt-2 p-2.5 rounded bg-rose-500/10 border-l-4 border-rose-500 text-xs">
                                <div class="font-bold mb-1">Lo que calificó mal</div>
                                <div v-for="(p, i) in entrevista_retiro.puntos_bajos" :key="i" class="mb-1">
                                    <strong>{{ p.nota }}/5</strong> — {{ p.label }}
                                    <div v-if="p.comentario" class="text-surface-600">{{ p.comentario }}</div>
                                </div>
                            </div>

                            <div v-if="entrevista_retiro.recomendaria !== null" class="text-xs mt-2">
                                <span class="text-surface-400 uppercase text-[10px]">¿Nos recomendaría?</span>
                                <span :class="entrevista_retiro.recomendaria ? 'text-emerald-700 font-semibold' : 'text-rose-600 font-semibold'">
                                    {{ entrevista_retiro.recomendaria ? 'Sí' : 'No' }}
                                </span>
                                <p v-if="entrevista_retiro.recomendaria_por_que">
                                    {{ entrevista_retiro.recomendaria_por_que }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!--
                        El detalle del GH-F-015, que es lo que Gestión Humana
                        mira antes de decidir. Antes sólo veía «continúa» y una
                        nota de 500 caracteres: decidía casi a ciegas.
                    -->
                    <div v-if="evaluaciones_prueba.length" class="card p-5">
                        <h3 class="font-bold text-sm uppercase text-surface-500 mb-3">
                            Evaluación del período de prueba
                        </h3>

                        <div v-for="(ev, i) in evaluaciones_prueba" :key="ev.id"
                             :class="i ? 'mt-4 pt-4 border-t' : ''">
                            <div class="flex items-start justify-between gap-2 flex-wrap">
                                <div>
                                    <span :class="['px-2 py-0.5 rounded text-[10px] font-bold uppercase',
                                                   ev.resultado === 'continua'
                                                       ? 'bg-emerald-100 text-emerald-800'
                                                       : 'bg-rose-100 text-rose-800']">
                                        {{ ev.resultado === 'continua' ? 'Pasa la prueba' : 'No pasa' }}
                                    </span>
                                    <span class="text-xs text-surface-500 ml-1">
                                        {{ ev.fecha }} · {{ ev.jefe }}<span v-if="ev.jefe_cargo">, {{ ev.jefe_cargo }}</span>
                                    </span>
                                </div>
                                <a :href="url(`/prueba/${ev.id}/pdf`)" class="btn-secondary text-xs py-1 px-2">
                                    <Printer class="h-3.5 w-3.5" /> GH-F-015
                                </a>
                            </div>

                            <!-- Las nueve notas, para leerlas de un vistazo -->
                            <ul class="mt-3 space-y-1">
                                <li v-for="c in ev.calificaciones" :key="c.clave"
                                    class="flex items-center justify-between gap-2 text-xs">
                                    <span :class="c.nota === null ? 'text-surface-400' : ''">
                                        {{ c.label }}
                                        <span v-if="c.si_aplica && c.nota === null"
                                            class="text-[10px] uppercase">· no aplica</span>
                                    </span>
                                    <span class="flex gap-0.5 shrink-0">
                                        <span v-for="n in 5" :key="n"
                                            :class="['w-4 h-4 rounded-sm text-[9px] leading-4 text-center',
                                                     c.nota === n
                                                         ? 'bg-brand-600 text-white font-bold'
                                                         : 'bg-surface-100 dark:bg-surface-800 text-surface-300']">
                                            {{ n }}
                                        </span>
                                    </span>
                                </li>
                            </ul>

                            <div v-if="ev.promedio !== null" class="text-xs text-surface-500 mt-2">
                                Promedio de lo calificado: <strong>{{ ev.promedio }}</strong> de 5.
                            </div>
                            <div v-if="ev.sin_calificar.length" class="text-xs text-amber-600 mt-1">
                                El jefe dejó sin calificar: {{ ev.sin_calificar.join(', ') }}.
                            </div>

                            <div v-if="ev.resaltar" class="mt-2 text-xs">
                                <span class="text-surface-400 uppercase text-[10px]">A resaltar</span>
                                <p>{{ ev.resaltar }}</p>
                            </div>
                            <div v-if="ev.mejorar" class="mt-2 text-xs">
                                <span class="text-surface-400 uppercase text-[10px]">A mejorar</span>
                                <p>{{ ev.mejorar }}</p>
                            </div>
                            <div v-if="ev.motivo" class="mt-2 text-xs">
                                <span class="text-surface-400 uppercase text-[10px]">Por qué</span>
                                <p>{{ ev.motivo }}</p>
                            </div>
                        </div>
                    </div>

                    <!--
                        Examen médico ocupacional · Resolución 2346 de 2007.

                        El periódico lo exige la norma mientras la persona
                        trabaje; el de egreso hay que practicarlo al salir, y
                        sin él la empresa queda sin con qué demostrar en qué
                        condiciones se fue alguien.
                    -->
                    <div class="card p-5">
                        <h3 class="font-bold text-sm uppercase text-surface-500 mb-1 flex items-center gap-1">
                            <IdCard class="h-4 w-4" /> Examen médico ocupacional
                        </h3>
                        <p class="text-xs text-surface-400 mb-3">
                            Genera la carta SST-F-001 que la persona lleva a la IPS.
                        </p>
                        <div class="flex gap-2 flex-wrap">
                            <a v-for="t in tipos_examen" :key="t.valor"
                                :href="url(`/emo?tipo=${t.valor}`)" class="btn-secondary text-xs">
                                {{ t.label }}
                            </a>
                        </div>
                    </div>

                    <!-- Sus novedades: incapacidades, permisos, ausencias -->
                    <div v-if="novedades.length" class="card p-5">
                        <h3 class="font-bold text-sm uppercase text-surface-500 mb-3">
                            Novedades · {{ novedades.length }}
                        </h3>
                        <ul class="divide-y text-sm">
                            <li v-for="n in novedades" :key="n.id" class="py-2 flex justify-between gap-2 flex-wrap">
                                <div>
                                    <span class="font-semibold">{{ n.clase }}</span>
                                    <span class="text-xs text-surface-500">
                                        · {{ n.desde }}<span v-if="n.hasta"> al {{ n.hasta }}</span>
                                        · {{ n.dias ? n.dias + ' días' : n.horas + ' horas' }}
                                    </span>
                                </div>
                                <span class="text-xs text-surface-500">
                                    corte {{ n.periodo }}<span v-if="n.descuento"> · −{{ money(n.descuento) }}</span>
                                </span>
                            </li>
                        </ul>
                    </div>

                    <!-- Inducción -->
                    <div class="card p-5">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <h3 class="font-bold text-sm uppercase text-surface-500 flex items-center gap-1">
                                <GraduationCap class="h-4 w-4" /> Inducción
                            </h3>
                            <div class="flex gap-2">
                                <a v-if="induccion" :href="url('/induccion-temario/pdf')" target="_blank"
                                    class="btn-secondary text-xs py-1 px-2">
                                    <Printer class="h-3.5 w-3.5" /> Constancia
                                </a>
                                <button @click="abrirInduccion" class="btn-secondary text-xs">
                                    {{ induccion ? 'Corregir' : 'Registrar' }}
                                </button>
                            </div>
                        </div>

                        <template v-if="induccion">
                            <p class="text-xs mt-2"
                                :class="induccion.completa ? 'text-emerald-700' : 'text-amber-700'">
                                {{ induccion.cubiertos }} de {{ induccion.total }} temas ·
                                {{ induccion.fecha }} · {{ induccion.responsable_nombre }}
                            </p>

                            <!-- Lo que falta se nombra: no sirve saber sólo que falta algo -->
                            <p v-if="induccion.pendientes.length" class="text-xs text-amber-700 mt-1">
                                Faltan: {{ induccion.pendientes.join(', ') }}
                            </p>

                            <p class="text-xs mt-1" :class="induccion.firmada ? 'text-emerald-700' : 'text-rose-600'">
                                <template v-if="induccion.firmada">
                                    Constancia firmada el {{ induccion.firmada_at }}
                                </template>
                                <template v-else>
                                    Sin firmar — hasta que no firme, el papel dice lo que afirma la empresa,
                                    no lo que reconoce la persona.
                                </template>
                            </p>
                        </template>

                        <p v-else class="text-sm text-amber-600 mt-2">
                            Pendiente. Sin temario no hay con qué demostrar qué se le explicó.
                        </p>
                    </div>

                    <!-- Carpeta -->
                    <div class="card p-5">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-bold text-sm uppercase text-surface-500">Carpeta</h3>
                            <button @click="modalDoc = true" class="btn-secondary text-xs">
                                <Upload class="h-3.5 w-3.5" /> Cargar
                            </button>
                        </div>
                        <ul class="divide-y text-sm">
                            <li v-for="d in documentos" :key="d.id" class="py-2 flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <a :href="`/app/rrhh/documentos/${d.id}`" target="_blank"
                                        class="font-semibold text-brand-600 hover:underline flex items-center gap-1">
                                        <FileText class="h-3.5 w-3.5 shrink-0" />{{ d.nombre }}
                                    </a>
                                    <div class="text-xs text-surface-400">{{ d.tipo_label }} · {{ d.cuando }}</div>
                                </div>
                                <span v-if="d.vence" class="text-xs text-amber-600 shrink-0">Vence {{ d.vence }}</span>
                            </li>
                            <li v-if="!documentos.length" class="py-3 text-surface-400 text-xs">Sin documentos.</li>
                        </ul>
                    </div>
                </div>

                <div class="card p-5">
                    <h3 class="font-bold text-sm uppercase text-surface-500 mb-3 flex items-center gap-1">
                        <History class="h-4 w-4" /> Historia laboral
                    </h3>
                    <ol class="space-y-3">
                        <li v-for="(b, i) in bitacora" :key="i" class="relative pl-5 text-sm">
                            <span class="absolute left-0 top-1.5 h-2 w-2 rounded-full bg-brand-500"></span>
                            <div class="font-semibold">{{ b.resumen }}</div>
                            <div class="text-xs text-surface-400">{{ b.usuario }} · {{ b.cuando }}</div>
                        </li>
                        <li v-if="!bitacora.length" class="text-sm text-surface-400">Todavía no hay nada.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Crear acceso al portal -->
        <div v-if="modalAcceso" @click.self="modalAcceso = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">Acceso al portal para {{ empleado.nombre }}</h3>
                <p class="text-xs text-surface-500 mb-3">
                    Va a poder ver sus datos, sus documentos y lo que le entregamos, y avisarnos
                    si algo se le daña. No ve su salario.
                </p>
                <div>
                    <label class="text-xs font-semibold">Con qué correo entra</label>
                    <input type="email" v-model="emailAcceso" class="input w-full" autofocus
                        placeholder="nombre@correo.com" />
                    <p v-if="errores.email" class="text-xs text-rose-600 mt-1">{{ errores.email }}</p>
                    <p class="text-[10px] text-surface-400 mt-1">
                        Al guardar te damos un enlace para mandarle. Ahí pone su propia contraseña.
                    </p>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalAcceso = false" class="btn-secondary">Cancelar</button>
                    <button @click="crearAcceso" :disabled="procesando || !emailAcceso"
                        class="btn-primary">Crear acceso</button>
                </div>
            </div>
        </div>

        <!-- Entrega de cargo · las seis listas -->
        <div v-if="modalEntrega" @click.self="modalEntrega = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-start justify-center p-4 overflow-y-auto">
            <div class="card w-full max-w-3xl p-5 space-y-4 my-8">
                <div>
                    <h3 class="text-lg font-bold">Entrega de cargo de {{ empleado.nombre }}</h3>
                    <p class="text-xs text-surface-500 mt-0.5">
                        Los equipos y la dotación no se escriben acá: salen de «Dotación y herramientas»,
                        donde ya están con su serial.
                    </p>
                </div>

                <div class="grid sm:grid-cols-3 gap-3">
                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Fecha</span>
                        <input v-model="entrega.fecha" type="date" class="input w-full mt-1" />
                        <span v-if="errores.fecha" class="text-xs text-rose-600">{{ errores.fecha }}</span>
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Quien recibe el cargo</span>
                        <input v-model="entrega.recibe_nombre" class="input w-full mt-1"
                            placeholder="Nombre de quien queda a cargo" />
                        <span class="text-[11px] text-surface-400">
                            Muchas veces queda con el propio jefe o con alguien que todavía no es usuario.
                        </span>
                    </label>
                </div>

                <div v-for="b in bloques_entrega" :key="b.clave" class="pt-3 border-t">
                    <div class="flex items-center justify-between gap-2 mb-1.5">
                        <span class="text-xs font-semibold text-surface-500 uppercase">
                            {{ b.titulo }}
                            <span class="text-[10px] normal-case text-surface-400">· {{ b.area }}</span>
                        </span>
                        <button @click="agregarFila(b.clave)" class="btn-secondary text-[11px] py-0.5 px-2">
                            ＋ Agregar
                        </button>
                    </div>
                    <div v-for="(fila, i) in filasDe(b.clave)" :key="b.clave + i"
                         class="flex gap-2 mb-1.5">
                        <input v-model="fila.nombre" class="input flex-1 text-sm" placeholder="Nombre" />
                        <input v-model="fila.detalle" class="input w-1/3 text-sm" :placeholder="b.detalle" />
                        <button @click="quitarFila(fila)"
                            class="text-surface-400 hover:text-rose-600 px-1 shrink-0">
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </div>
                    <p v-if="!filasDe(b.clave).length" class="text-xs text-surface-400">Sin renglones.</p>
                </div>

                <label class="block pt-3 border-t">
                    <span class="text-xs font-semibold text-surface-500 uppercase">Observaciones generales</span>
                    <textarea v-model="entrega.observaciones" rows="2" class="input w-full mt-1"></textarea>
                </label>

                <div class="flex justify-end gap-2 pt-1">
                    <button @click="modalEntrega = false" class="btn-secondary">Cancelar</button>
                    <button @click="guardarEntrega" :disabled="procesando" class="btn-primary">
                        {{ procesando ? 'Guardando…' : 'Guardar entrega' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Entrevista de retiro · el formato completo -->
        <div v-if="modalEntrevista" @click.self="modalEntrevista = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-start justify-center p-4 overflow-y-auto">
            <div class="card w-full max-w-3xl p-5 space-y-4 my-8">
                <div>
                    <h3 class="text-lg font-bold">Entrevista de retiro de {{ empleado.nombre }}</h3>
                    <p class="text-xs text-surface-500 mt-0.5">
                        Se llena mientras se conversa: nada es obligatorio salvo la fecha.
                    </p>
                </div>

                <div class="grid sm:grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Fecha de la entrevista</span>
                        <input v-model="entrevista.fecha_entrevista" type="date" class="input w-full mt-1" />
                        <span v-if="errores.fecha_entrevista" class="text-xs text-rose-600">
                            {{ errores.fecha_entrevista }}
                        </span>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">La salida es</span>
                        <select v-model="entrevista.salida" class="input w-full mt-1">
                            <option :value="null">—</option>
                            <option value="voluntaria">Voluntaria</option>
                            <option value="involuntaria">Involuntaria</option>
                        </select>
                    </label>
                </div>

                <div>
                    <span class="text-xs font-semibold text-surface-500 uppercase">Motivo principal de la salida</span>
                    <div class="grid sm:grid-cols-2 gap-x-4 gap-y-1 mt-1">
                        <label v-for="m in motivos_salida" :key="m.valor"
                            class="flex items-start gap-2 text-sm cursor-pointer">
                            <input type="radio" :value="m.valor" v-model="entrevista.motivo_principal"
                                class="mt-1 shrink-0" />
                            <span>{{ m.label }}</span>
                        </label>
                    </div>
                    <input v-if="entrevista.motivo_principal === 'otro'" v-model="entrevista.motivo_otro"
                        placeholder="¿Cuál?" class="input w-full mt-2" />
                    <span v-if="errores.motivo_otro" class="text-xs text-rose-600">{{ errores.motivo_otro }}</span>
                </div>

                <label class="block">
                    <span class="text-xs font-semibold text-surface-500 uppercase">
                        Detalle del motivo · lo que cuenta la persona
                    </span>
                    <textarea v-model="entrevista.detalle_motivo" rows="2" class="input w-full mt-1"></textarea>
                </label>

                <!-- Los ocho criterios, con su comentario al lado -->
                <div class="pt-2 border-t">
                    <div class="text-xs font-semibold text-surface-500 uppercase mb-1">
                        Experiencia laboral · 1 deficiente, 5 excelente
                    </div>
                    <div v-for="c in criterios_retiro" :key="c.clave"
                        class="py-2 border-b border-surface-100 dark:border-surface-800">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm">{{ c.label }}</span>
                            <span class="flex gap-1.5 shrink-0">
                                <button v-for="n in 5" :key="n" type="button"
                                    @click="entrevista[`n_${c.clave}`] = entrevista[`n_${c.clave}`] === n ? null : n"
                                    :class="['w-6 h-6 rounded text-xs font-bold',
                                             entrevista[`n_${c.clave}`] === n
                                                 ? (n <= 2 ? 'bg-rose-600 text-white' : 'bg-brand-600 text-white')
                                                 : 'bg-surface-100 dark:bg-surface-800 text-surface-500 hover:bg-surface-200']">
                                    {{ n }}
                                </button>
                            </span>
                        </div>
                        <!-- El comentario es el valor: un 2 sin el porqué no se puede atacar -->
                        <input v-if="entrevista[`n_${c.clave}`] !== null && entrevista[`n_${c.clave}`] !== undefined"
                            v-model="entrevista[`c_${c.clave}`]"
                            :placeholder="entrevista[`n_${c.clave}`] <= 2 ? '¿Por qué? Esto es lo que se puede arreglar.' : 'Comentario (opcional)'"
                            class="input w-full mt-1.5 text-sm" />
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-3 pt-2 border-t">
                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">¿Qué valoró de su tiempo acá?</span>
                        <textarea v-model="entrevista.que_valoro" rows="3" class="input w-full mt-1"></textarea>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">¿Qué debe mejorar la empresa?</span>
                        <textarea v-model="entrevista.que_mejorar" rows="3" class="input w-full mt-1"></textarea>
                    </label>
                </div>

                <div class="grid sm:grid-cols-2 gap-3">
                    <div>
                        <span class="text-xs font-semibold text-surface-500 uppercase">
                            ¿Las funciones correspondían a lo ofrecido?
                        </span>
                        <select v-model="entrevista.funciones_correspondian" class="input w-full mt-1">
                            <option :value="null">—</option>
                            <option :value="true">Sí</option>
                            <option :value="false">No</option>
                        </select>
                        <input v-if="entrevista.funciones_correspondian === false"
                            v-model="entrevista.funciones_explicacion" placeholder="Explique"
                            class="input w-full mt-1.5 text-sm" />
                    </div>
                    <div>
                        <span class="text-xs font-semibold text-surface-500 uppercase">
                            ¿Nos recomendaría como lugar para trabajar?
                        </span>
                        <select v-model="entrevista.recomendaria" class="input w-full mt-1">
                            <option :value="null">—</option>
                            <option :value="true">Sí</option>
                            <option :value="false">No</option>
                        </select>
                        <input v-if="entrevista.recomendaria !== null" v-model="entrevista.recomendaria_por_que"
                            placeholder="¿Por qué?" class="input w-full mt-1.5 text-sm" />
                    </div>
                </div>

                <div class="pt-2 border-t">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="entrevista.accesos_cerrados" type="checkbox" class="rounded" />
                        <span>Correos y accesos a sistemas cerrados</span>
                    </label>
                    <p class="text-[11px] text-surface-400 mt-1">
                        La dotación y las herramientas no se escriben acá: salen de «Dotación y herramientas»,
                        que es donde ya están con su serial.
                    </p>
                </div>

                <label class="block">
                    <span class="text-xs font-semibold text-surface-500 uppercase">Observaciones</span>
                    <textarea v-model="entrevista.observaciones" rows="2" class="input w-full mt-1"></textarea>
                </label>

                <div class="flex justify-end gap-2 pt-1">
                    <button @click="modalEntrevista = false" class="btn-secondary">Cancelar</button>
                    <button @click="guardarEntrevista" :disabled="procesando" class="btn-primary">
                        {{ procesando ? 'Guardando…' : 'Guardar entrevista' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Registrar retiro -->
        <div v-if="modalRetiro" @click.self="modalRetiro = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">Registrar el retiro de {{ empleado.nombre }}</h3>
                <p class="text-xs text-surface-500 mb-3">
                    El motivo es lo que después responde en la Junta la pregunta de por qué se va la gente.
                </p>
                <div class="space-y-3">
                    <div class="grid sm:grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs font-semibold">Último día</label>
                            <input type="date" v-model="retiro.fecha_retiro" class="input w-full" />
                            <p v-if="errores.fecha_retiro" class="text-xs text-rose-600 mt-1">{{ errores.fecha_retiro }}</p>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Motivo</label>
                            <select v-model="retiro.tipo_retiro" class="input w-full">
                                <option v-for="t in tipos_retiro" :key="t.valor" :value="t.valor">{{ t.label }}</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Detalle</label>
                        <textarea v-model="retiro.motivo_retiro" rows="3" class="input w-full"
                            placeholder="Consiguió otro trabajo más cerca de la casa / se va a estudiar..."></textarea>
                        <p v-if="errores.motivo_retiro" class="text-xs text-rose-600 mt-1">{{ errores.motivo_retiro }}</p>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalRetiro = false" class="btn-secondary">Cancelar</button>
                    <button @click="guardarRetiro" :disabled="procesando || retiro.motivo_retiro.trim().length < 5"
                        class="btn-primary">Registrar retiro</button>
                </div>
            </div>
        </div>

        <!-- Evaluar prueba -->
        <div v-if="modalEvaluar" @click.self="modalEvaluar = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">Fin del período de prueba</h3>
                <p class="text-xs text-surface-500 mb-3">Esta decisión no se puede corregir después.</p>
                <select v-model="evaluacion.resultado" class="input w-full">
                    <option value="continua">Continúa en el cargo</option>
                    <option value="no_continua">No continúa</option>
                </select>
                <div class="mt-3">
                    <label class="text-xs font-semibold">
                        Observación
                        <span v-if="evaluacion.resultado === 'no_continua'" class="text-rose-600">· obligatoria</span>
                    </label>
                    <textarea v-model="evaluacion.observacion" rows="3" class="input w-full"></textarea>
                    <p v-if="errores.observacion" class="text-xs text-rose-600 mt-1">{{ errores.observacion }}</p>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalEvaluar = false" class="btn-secondary">Cancelar</button>
                    <button @click="evaluar"
                        :disabled="procesando || (evaluacion.resultado === 'no_continua' && !evaluacion.observacion)"
                        class="btn-primary">Guardar</button>
                </div>
            </div>
        </div>

        <!-- Inducción -->
        <div v-if="modalInduccion" @click.self="modalInduccion = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-2xl w-full max-h-[90vh] overflow-y-auto space-y-3">
                <h3 class="text-lg font-bold">Inducción de {{ empleado.nombre }}</h3>
                <p class="text-xs text-surface-500">
                    El temario sale de una regla que el área edita. Esta inducción guarda el suyo:
                    si mañana se agrega un tema, ésta no se vuelve incompleta.
                </p>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="text-xs uppercase text-surface-500">Fecha</label>
                        <input v-model="ind.fecha" type="date" class="input w-full mt-1" />
                        <p v-if="errores.fecha" class="text-xs text-rose-600 mt-1">{{ errores.fecha }}</p>
                    </div>
                    <div>
                        <label class="text-xs uppercase text-surface-500">Quién la dio</label>
                        <input v-model="ind.responsable_nombre" class="input w-full mt-1" />
                        <p v-if="errores.responsable_nombre" class="text-xs text-rose-600 mt-1">
                            {{ errores.responsable_nombre }}
                        </p>
                    </div>
                    <div>
                        <label class="text-xs uppercase text-surface-500">Cargo</label>
                        <input v-model="ind.responsable_cargo" class="input w-full mt-1" />
                    </div>
                </div>

                <div class="pt-2 border-t">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-bold uppercase text-surface-500">Temario</h4>
                        <span class="text-xs text-surface-500">{{ temasVistos }} de {{ ind.temas.length }}</span>
                    </div>
                    <div v-for="(t, i) in ind.temas" :key="i"
                        class="flex items-start gap-2 py-1.5 border-b last:border-0">
                        <input v-model="t.cubierto" type="checkbox" class="rounded mt-0.5 shrink-0" />
                        <div class="flex-1 min-w-0">
                            <div class="text-sm">{{ t.tema }}</div>
                            <div v-if="t.cubierto" class="grid grid-cols-2 gap-2 mt-1">
                                <input v-model="t.responsable" placeholder="Quién lo explicó"
                                    class="input w-full text-xs py-1" />
                                <input v-model="t.observacion" placeholder="Observación"
                                    class="input w-full text-xs py-1" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 pt-2 border-t">
                    <div>
                        <label class="text-xs uppercase text-surface-500">Firmó la constancia el</label>
                        <input v-model="ind.firmada_at" type="date" class="input w-full mt-1" />
                        <p class="text-[11px] text-surface-400 mt-1">
                            Dejar vacío si todavía no ha firmado.
                        </p>
                    </div>
                    <div>
                        <label class="text-xs uppercase text-surface-500">Observaciones</label>
                        <textarea v-model="ind.observaciones" rows="2" class="input w-full mt-1"></textarea>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button @click="modalInduccion = false" class="btn-secondary">Cancelar</button>
                    <button @click="guardarTemario" :disabled="procesando" class="btn-primary">
                        {{ procesando ? 'Guardando…' : 'Guardar inducción' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Documento -->
        <div v-if="modalDoc" @click.self="modalDoc = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold mb-3">Cargar documento</h3>
                <div class="space-y-3">
                    <div>
                        <label class="text-xs font-semibold">Tipo</label>
                        <select v-model="doc.tipo" class="input w-full">
                            <option v-for="t in tipos_documento" :key="t.valor" :value="t.valor">{{ t.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Archivo</label>
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                            @change="archivo = $event.target.files[0]" class="input w-full text-xs" />
                        <p v-if="errores.archivo" class="text-xs text-rose-600 mt-1">{{ errores.archivo }}</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Vence el (opcional)</label>
                        <input type="date" v-model="doc.fecha_vencimiento" class="input w-full" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalDoc = false" class="btn-secondary">Cancelar</button>
                    <button @click="subirDoc" :disabled="procesando || !archivo" class="btn-primary">Cargar</button>
                </div>
            </div>
        </div>
        <!--
            Liquidación definitiva · los tres datos que el sistema no sabe.
        -->
        <div v-if="modalLiquidacion" @click.self="modalLiquidacion = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-xl w-full max-h-[90vh] overflow-y-auto space-y-3">
                <h3 class="text-lg font-bold">Liquidación de {{ empleado.nombre }}</h3>
                <p class="text-xs text-surface-500">
                    Cesantías, intereses, prima y vacaciones salen de las fechas y del salario.
                    Acá van los tres datos que el sistema no puede saber solo.
                </p>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs uppercase text-surface-500">Días de vacaciones ya tomados</label>
                        <input v-model.number="liq.vacaciones_tomadas" type="number" min="0" class="input w-full mt-1" />
                        <p class="text-[11px] text-surface-400 mt-1">
                            El ERP no lleva vacaciones todavía: este dato entra a mano.
                        </p>
                    </div>
                    <div>
                        <label class="text-xs uppercase text-surface-500">Cesantías ya consignadas o anticipadas</label>
                        <input v-model.number="liq.cesantias_ya_pagadas" type="number" min="0" class="input w-full mt-1" />
                        <p class="text-[11px] text-surface-400 mt-1">
                            Lo que ya se le giró al fondo o se le anticipó.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs uppercase text-surface-500">Indemnización</label>
                        <input v-model.number="liq.indemnizacion" type="number" min="0" class="input w-full mt-1" />
                        <p class="text-[11px] text-surface-400 mt-1">
                            Sólo si el despido fue sin justa causa. El sistema no lo decide.
                        </p>
                    </div>
                    <div>
                        <label class="text-xs uppercase text-surface-500">
                            Concepto <span v-if="liq.indemnizacion > 0" class="text-rose-600">· obligatorio</span>
                        </label>
                        <input v-model="liq.indemnizacion_concepto" class="input w-full mt-1"
                            placeholder="Art. 64 CST · 30 días de salario" />
                        <p v-if="errores.indemnizacion_concepto" class="text-xs text-rose-600 mt-1">
                            {{ errores.indemnizacion_concepto }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs uppercase text-surface-500">Otros descuentos</label>
                        <input v-model.number="liq.otros_descuentos" type="number" min="0" class="input w-full mt-1" />
                    </div>
                    <div>
                        <label class="text-xs uppercase text-surface-500">
                            Concepto <span v-if="liq.otros_descuentos > 0" class="text-rose-600">· obligatorio</span>
                        </label>
                        <input v-model="liq.otros_descuentos_concepto" class="input w-full mt-1"
                            placeholder="Préstamo pendiente, dotación no devuelta…" />
                        <p v-if="errores.otros_descuentos_concepto" class="text-xs text-rose-600 mt-1">
                            {{ errores.otros_descuentos_concepto }}
                        </p>
                    </div>
                </div>

                <div>
                    <label class="text-xs uppercase text-surface-500">Observaciones</label>
                    <textarea v-model="liq.observaciones" rows="2" class="input w-full mt-1"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button @click="modalLiquidacion = false" class="btn-secondary">Cancelar</button>
                    <button @click="calcularLiquidacion" :disabled="procesando" class="btn-primary">
                        {{ procesando ? 'Calculando…' : 'Calcular' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Marcar la liquidación como pagada -->
        <div v-if="modalPagoLiq" @click.self="modalPagoLiq = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full space-y-3">
                <h3 class="text-lg font-bold">Marcar la liquidación como pagada</h3>
                <div>
                    <label class="text-xs uppercase text-surface-500">Fecha de pago</label>
                    <input v-model="pagoLiq.fecha_pago" type="date" class="input w-full mt-1" />
                </div>
                <div class="flex justify-end gap-2">
                    <button @click="modalPagoLiq = false" class="btn-secondary">Cancelar</button>
                    <button @click="pagarLiquidacion" :disabled="procesando" class="btn-primary">Confirmar pago</button>
                </div>
            </div>
        </div>

    </AppLayout>
</template>
