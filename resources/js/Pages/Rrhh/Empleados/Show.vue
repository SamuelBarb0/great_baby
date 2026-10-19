<script setup>
/*
 * La ficha del empleado: su historia laboral.
 *
 * Lo que manda la pantalla es el período de prueba. Hoy se vence solo y la
 * persona simplemente se queda; pasada esa fecha, terminar el contrato ya no
 * se puede sin justa causa ni indemnización. Por eso va arriba y en color.
 */
import { ref, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft, User, Clock, GraduationCap, FileText, Upload, History, AlertTriangle, Printer, LogOut,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    empleado: { type: Object, required: true },
    documentos: { type: Array, default: () => [] },
    tipos_documento: { type: Array, default: () => [] },
    tipos_retiro: { type: Array, default: () => [] },
    bitacora: { type: Array, default: () => [] },
});

const procesando = ref(false);
const errores = ref({});
const url = (s = '') => `/app/rrhh/empleados/${props.empleado.id}${s}`;

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

/* período de prueba */
const hasta = ref(props.empleado.prueba_hasta || props.empleado.prueba_sugerida);
const guardarPrueba = () => enviar('/prueba', { hasta: hasta.value });

const modalEvaluar = ref(false);
const evaluacion = reactive({ resultado: 'continua', observacion: '' });
const evaluar = () => enviar('/prueba/evaluar', { ...evaluacion },
    { onSuccess: () => (modalEvaluar.value = false) });

/* inducción */
const modalInduccion = ref(false);
const temas = ref('');
const guardarInduccion = () => enviar('/induccion', { temas: temas.value },
    { onSuccess: () => { modalInduccion.value = false; temas.value = ''; } });

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
const modalRetiro = ref(false);
const retiro = reactive({
    fecha_retiro: new Date().toISOString().slice(0, 10),
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

                    <!-- Inducción -->
                    <div class="card p-5">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <h3 class="font-bold text-sm uppercase text-surface-500 flex items-center gap-1">
                                <GraduationCap class="h-4 w-4" /> Inducción
                            </h3>
                            <button v-if="empleado.estado_induccion !== 'completada'" @click="modalInduccion = true"
                                class="btn-secondary text-xs">Registrar</button>
                        </div>
                        <div v-if="empleado.induccion_at" class="mt-2 text-sm">
                            <p class="text-emerald-700 text-xs font-semibold">
                                Dada el {{ empleado.induccion_at }} por {{ empleado.induccion_por }}
                            </p>
                            <p class="whitespace-pre-line mt-1">{{ empleado.induccion_temas }}</p>
                        </div>
                        <p v-else class="text-sm text-amber-600 mt-2">
                            Pendiente. Sin fecha ni responsable no hay con qué demostrar que se dio.
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
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">Registrar la inducción</h3>
                <p class="text-xs text-surface-500 mb-3">
                    Qué se vio. Queda con tu nombre y la fecha de hoy.
                </p>
                <textarea v-model="temas" rows="5" class="input w-full" autofocus
                    placeholder="Reglamento interno, seguridad y salud en el trabajo, manejo del montacargas, recorrido por bodega..."></textarea>
                <p v-if="errores.temas" class="text-xs text-rose-600 mt-1">{{ errores.temas }}</p>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalInduccion = false" class="btn-secondary">Cancelar</button>
                    <button @click="guardarInduccion" :disabled="procesando || temas.trim().length < 10"
                        class="btn-primary">Guardar</button>
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
    </AppLayout>
</template>
