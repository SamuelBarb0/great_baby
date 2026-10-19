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
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft, Plus, FileText, ShieldCheck, ShieldAlert, X, Star, Users, Download, Printer,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    vacante: { type: Object, required: true },
    columnas: { type: Array, default: () => [] },
    candidatos: { type: Array, default: () => [] },
    descartados: { type: Number, default: 0 },
    fuentes: { type: Array, default: () => [] },
    valoraciones: { type: Array, default: () => [] },
    terna_llena: { type: Boolean, default: false },
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

                            <div class="flex gap-1">
                                <select :value="c.etapa" @change="mover(c, $event.target.value)"
                                    :disabled="procesando" class="input flex-1 min-w-0 text-xs py-1">
                                    <option v-for="o in columnas" :key="o.valor" :value="o.valor">{{ o.label }}</option>
                                    <option value="seleccionado">Seleccionado</option>
                                </select>
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
                        <span v-else class="text-xs text-rose-600">No autorizada</span>
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
