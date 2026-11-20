<script setup>
/*
 * El expediente de contratación de una persona.
 *
 * La lista de pendientes de arriba la manda el servidor, y es el MISMO cálculo
 * que frena el cierre: no hay manera de que la pantalla diga «ya está» y el
 * botón diga otra cosa.
 */
import { ref, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft, ClipboardCheck, Check, AlertTriangle, FileText, Upload, History, X, Stethoscope,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import { useEscClose } from '@/composables/useEscClose';

const props = defineProps({
    contratacion: { type: Object, required: true },
    falta: { type: Array, default: () => [] },
    frenada: { type: Boolean, default: false },
    documentos: { type: Array, default: () => [] },
    tipos_documento: { type: Array, default: () => [] },
    documentos_faltantes: { type: Array, default: () => [] },
    resultados_examen: { type: Array, default: () => [] },
    bitacora: { type: Array, default: () => [] },
});

const procesando = ref(false);
const errores = ref({});
const url = (s = '') => `/app/rrhh/contrataciones/${props.contratacion.id}${s}`;
const enProceso = computed(() => props.contratacion.estado === 'en_proceso');

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

/* acuerdo */
const acuerdo = reactive({
    salario: props.contratacion.salario || null,
    tipo_contrato: props.contratacion.tipo_contrato || 'indefinido',
    fecha_ingreso: props.contratacion.fecha_ingreso || null,
});
const guardarAcuerdo = () => enviar('/acuerdo', { ...acuerdo });

/* examen médico */
const modalExamen = ref(false);
useEscClose(modalExamen);
const examen = reactive({ resultado: 'apto', observacion: '' });
const examenExigeObs = computed(() => examen.resultado !== 'apto');
const guardarExamen = () => enviar('/examen', { ...examen }, { onSuccess: () => (modalExamen.value = false) });

/* afiliaciones */
const modalAfiliacion = ref(null);
useEscClose(modalAfiliacion);
const entidad = ref('');
const guardarAfiliacion = () => enviar('/afiliacion',
    { campo: modalAfiliacion.value.campo, entidad: entidad.value },
    { onSuccess: () => { modalAfiliacion.value = null; entidad.value = ''; } });

/* documentos */
const modalDoc = ref(false);
useEscClose(modalDoc);
const doc = reactive({ tipo: 'cedula', nombre: '', fecha_vencimiento: null });
const archivo = ref(null);
const subirDoc = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post(url('/documento'), { ...doc, archivo: archivo.value }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { modalDoc.value = false; archivo.value = null; doc.nombre = ''; },
        onError: (e) => (errores.value = e),
        onFinish: () => (procesando.value = false),
    });
};

/* cierre y cancelación */

/*
 * `confirm()` nativo está bloqueado dentro del iframe de la app de escritorio:
 * devuelve false sin avisar y el botón no hace nada. Cerrar la contratación es
 * lo que CREA al empleado, así que un botón mudo acá deja a alguien contratado
 * en el papel y sin ficha en el sistema.
 */
const modalConfirm = ref(null);

const completar = () => {
    modalConfirm.value = {
        titulo: '¿Cerrar la contratación y crear al empleado?',
        mensaje: 'Se le abre la ficha de empleado con lo pactado. Después esto no se deshace.',
        color: 'emerald',
        textoConfirmar: 'Cerrar y crear',
        onConfirmar: () => { modalConfirm.value = null; enviar('/completar'); },
    };
};
const modalCancelar = ref(false);
useEscClose(modalCancelar);
const motivo = ref('');
const cancelar = () => enviar('/cancelar', { motivo: motivo.value },
    { onSuccess: () => { modalCancelar.value = false; motivo.value = ''; } });

const money = (n) => (n ? '$' + Number(n).toLocaleString('es-CO', { maximumFractionDigits: 0 }) : '—');
</script>

<template>
    <Head :title="`Contratación · ${contratacion.candidato.nombre}`" />
    <AppLayout>
        <div class="space-y-4">
            <Link href="/app/rrhh/contrataciones" class="text-sm text-surface-500 hover:text-brand-600 inline-flex items-center gap-1">
                <ArrowLeft class="h-4 w-4" /> Volver a contrataciones
            </Link>

            <div v-if="$page.props.flash?.success"
                class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="errores.cierre" class="p-3 rounded-lg bg-rose-500/10 border-l-4 border-rose-500 text-rose-700 text-sm">
                {{ errores.cierre }}
            </div>

            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <ClipboardCheck class="h-6 w-6 text-brand-600" />{{ contratacion.candidato.nombre }}
                </h1>
                <p class="text-sm text-surface-500">
                    {{ contratacion.cargo }} · {{ contratacion.area }}
                    <span v-if="contratacion.candidato.documento"> · CC {{ contratacion.candidato.documento }}</span>
                </p>
            </div>

            <div v-if="frenada" class="p-4 rounded-lg bg-rose-500/10 border-l-4 border-rose-500">
                <div class="font-bold text-rose-700 flex items-center gap-2">
                    <AlertTriangle class="h-5 w-5" /> Examen médico NO APTO
                </div>
                <p class="text-sm text-rose-700 mt-1">
                    {{ contratacion.examen_observacion }} · Esta contratación no se puede cerrar.
                </p>
            </div>

            <div v-else-if="contratacion.estado === 'completada'"
                class="p-4 rounded-lg bg-emerald-500/10 border-l-4 border-emerald-500 text-sm">
                <strong class="text-emerald-700">Contratación cerrada.</strong>
                <Link v-if="contratacion.empleado" :href="`/app/rrhh/empleados/${contratacion.empleado.id}`"
                    class="text-brand-600 hover:underline ml-1">Ver la ficha de {{ contratacion.empleado.nombre }}</Link>
            </div>

            <div v-else-if="contratacion.estado === 'cancelada'"
                class="p-4 rounded-lg bg-surface-200 dark:bg-surface-800 text-sm">
                <strong>Cancelada.</strong> {{ contratacion.motivo_cancelacion }}
            </div>

            <!-- Lo que falta · el mismo cálculo que frena el cierre -->
            <div v-else-if="falta.length" class="card p-4 border-l-4 border-amber-500">
                <h3 class="font-bold text-sm uppercase text-surface-500 mb-2">Todavía falta</h3>
                <ul class="text-sm space-y-1">
                    <li v-for="f in falta" :key="f" class="flex items-center gap-2 text-amber-700">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>{{ f }}
                    </li>
                </ul>
            </div>
            <div v-else class="card p-4 border-l-4 border-emerald-500 flex items-center justify-between gap-3 flex-wrap">
                <span class="text-sm font-semibold text-emerald-700">
                    Está todo. Se puede cerrar y crear al empleado.
                </span>
                <button @click="completar" :disabled="procesando" class="btn-primary">
                    <Check class="h-4 w-4" /> Cerrar contratación
                </button>
            </div>

            <div class="grid lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2 space-y-4">
                    <!-- Lo acordado -->
                    <div class="card p-5">
                        <h3 class="font-bold text-sm uppercase text-surface-500 mb-3">Lo que se acordó</h3>
                        <div v-if="enProceso" class="grid sm:grid-cols-3 gap-2">
                            <div>
                                <label class="text-xs font-semibold">Salario</label>
                                <input type="number" step="1000" v-model.number="acuerdo.salario" class="input w-full" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold">Tipo de contrato</label>
                                <select v-model="acuerdo.tipo_contrato" class="input w-full">
                                    <option value="indefinido">Indefinido</option>
                                    <option value="temporal">Término fijo</option>
                                    <option value="obra_labor">Obra o labor</option>
                                    <option value="aprendizaje">Aprendiz SENA</option>
                                    <option value="prestacion_servicios">Prestación de servicios</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-semibold">Entra el</label>
                                <input type="date" v-model="acuerdo.fecha_ingreso" class="input w-full" />
                            </div>
                        </div>
                        <div v-else class="grid sm:grid-cols-3 gap-4 text-sm">
                            <div><span class="text-xs text-surface-400 uppercase block">Salario</span>{{ money(contratacion.salario) }}</div>
                            <div><span class="text-xs text-surface-400 uppercase block">Contrato</span>{{ contratacion.tipo_contrato }}</div>
                            <div><span class="text-xs text-surface-400 uppercase block">Entra</span>{{ contratacion.fecha_ingreso || '—' }}</div>
                        </div>
                        <button v-if="enProceso" @click="guardarAcuerdo" :disabled="procesando" class="btn-secondary mt-3 text-xs">
                            Guardar condiciones
                        </button>
                    </div>

                    <!-- Examen y contrato -->
                    <div class="card p-5 space-y-3">
                        <h3 class="font-bold text-sm uppercase text-surface-500">Examen médico y contrato</h3>

                        <div class="flex items-center justify-between gap-2 flex-wrap text-sm">
                            <div>
                                <div class="font-semibold">Examen médico de ingreso</div>
                                <div v-if="contratacion.examen_at" class="text-xs"
                                    :class="frenada ? 'text-rose-600' : 'text-emerald-700'">
                                    {{ contratacion.examen_resultado }} · {{ contratacion.examen_at }}
                                </div>
                                <div v-else class="text-xs text-surface-400">Sin registrar</div>
                            </div>
                            <div class="flex gap-2">
                                <!--
                                    La carta que la persona lleva a la IPS. Hasta
                                    ahora el sistema guardaba la fecha y el
                                    resultado del examen, pero no tenía con qué
                                    pedirlo: la carta se escribía por fuera.
                                -->
                                <a :href="`/app/rrhh/contrataciones/${contratacion.id}/emo`"
                                    class="btn-secondary text-xs">
                                    <Stethoscope class="h-3.5 w-3.5" /> Carta SST-F-001
                                </a>
                                <button v-if="enProceso" @click="modalExamen = true" class="btn-secondary text-xs">
                                    {{ contratacion.examen_at ? 'Corregir' : 'Registrar' }}
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-2 flex-wrap text-sm border-t pt-3">
                            <div>
                                <div class="font-semibold">Firma del contrato</div>
                                <div v-if="contratacion.contrato_firmado_at" class="text-xs text-emerald-700">
                                    Firmado · {{ contratacion.contrato_firmado_at }}
                                </div>
                                <div v-else class="text-xs text-surface-400">Sin firmar</div>
                            </div>
                            <button v-if="enProceso && !contratacion.contrato_firmado_at"
                                @click="enviar('/firmar')" :disabled="procesando" class="btn-secondary text-xs">
                                Marcar firmado
                            </button>
                        </div>
                    </div>

                    <!-- Afiliaciones -->
                    <div class="card p-5">
                        <h3 class="font-bold text-sm uppercase text-surface-500 mb-1">Afiliaciones de ley</h3>
                        <p class="text-xs text-surface-400 mb-3">
                            Si falta la ARL y la persona se accidenta el primer día, responde la empresa.
                        </p>
                        <ul class="divide-y text-sm">
                            <li v-for="a in contratacion.afiliaciones" :key="a.campo"
                                class="py-2.5 flex items-center justify-between gap-2 flex-wrap">
                                <div>
                                    <div class="font-semibold">{{ a.label }}</div>
                                    <div v-if="a.hecha" class="text-xs text-emerald-700 flex items-center gap-1">
                                        <Check class="h-3 w-3" />{{ a.entidad }}
                                    </div>
                                    <div v-else class="text-xs text-amber-600">Pendiente</div>
                                </div>
                                <button v-if="enProceso" @click="modalAfiliacion = a; entidad = a.entidad || ''"
                                    class="btn-secondary text-xs">
                                    {{ a.hecha ? 'Cambiar' : 'Registrar' }}
                                </button>
                            </li>
                        </ul>
                    </div>

                    <!-- Documentos -->
                    <div class="card p-5">
                        <div class="flex items-center justify-between mb-3 gap-2 flex-wrap">
                            <h3 class="font-bold text-sm uppercase text-surface-500">Carpeta de documentos</h3>
                            <div class="flex gap-2">
                                <!--
                                    La carta GH-F-007 con la lista de lo que
                                    tiene que traer. Marca lo ya recibido, para
                                    que en la segunda entrega no vuelva a traer
                                    lo que ya trajo.
                                -->
                                <a :href="`/app/rrhh/contrataciones/${contratacion.id}/documentos-solicitud`"
                                    class="btn-secondary text-xs">
                                    <FileText class="h-3.5 w-3.5" /> Carta GH-F-007
                                </a>
                                <button @click="modalDoc = true" class="btn-secondary text-xs">
                                    <Upload class="h-3.5 w-3.5" /> Cargar
                                </button>
                            </div>
                        </div>

                        <!-- Lo obligatorio que todavía no llegó -->
                        <div v-if="documentos_faltantes.length"
                            class="mb-3 p-2.5 rounded bg-amber-500/10 border-l-4 border-amber-500 text-xs">
                            <span class="font-bold">Faltan por recibir:</span>
                            {{ documentos_faltantes.join(', ') }}.
                        </div>

                        <ul class="divide-y text-sm">
                            <li v-for="d in documentos" :key="d.id" class="py-2 flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <a :href="`/app/rrhh/documentos/${d.id}`" target="_blank"
                                        class="font-semibold text-brand-600 hover:underline flex items-center gap-1">
                                        <FileText class="h-3.5 w-3.5 shrink-0" />{{ d.nombre }}
                                    </a>
                                    <div class="text-xs text-surface-400">
                                        {{ d.tipo_label }} · {{ d.subido_por }} · {{ d.cuando }}
                                    </div>
                                </div>
                                <span v-if="d.vence" class="text-xs text-amber-600 shrink-0">Vence {{ d.vence }}</span>
                            </li>
                            <li v-if="!documentos.length" class="py-3 text-surface-400 text-xs">Sin documentos.</li>
                        </ul>
                    </div>

                    <button v-if="enProceso" @click="modalCancelar = true" class="btn-secondary text-xs text-rose-600">
                        <X class="h-3.5 w-3.5" /> Cancelar esta contratación
                    </button>
                </div>

                <div class="card p-5">
                    <h3 class="font-bold text-sm uppercase text-surface-500 mb-3 flex items-center gap-1">
                        <History class="h-4 w-4" /> Qué ha pasado
                    </h3>
                    <ol class="space-y-3">
                        <li v-for="(b, i) in bitacora" :key="i" class="relative pl-5 text-sm">
                            <span class="absolute left-0 top-1.5 h-2 w-2 rounded-full bg-brand-500"></span>
                            <div class="font-semibold">{{ b.resumen }}</div>
                            <div class="text-xs text-surface-400">{{ b.usuario }} · {{ b.cuando }}</div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Examen médico -->
        <div v-if="modalExamen" @click.self="modalExamen = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold mb-3">Examen médico de ingreso</h3>
                <select v-model="examen.resultado" class="input w-full">
                    <option v-for="r in resultados_examen" :key="r.valor" :value="r.valor">{{ r.label }}</option>
                </select>
                <div class="mt-3">
                    <label class="text-xs font-semibold">
                        Observación <span v-if="examenExigeObs" class="text-rose-600">· obligatoria</span>
                    </label>
                    <textarea v-model="examen.observacion" rows="3" class="input w-full"
                        placeholder="Restricción para levantar peso mayor a 15 kg..."></textarea>
                    <p v-if="errores.observacion" class="text-xs text-rose-600 mt-1">{{ errores.observacion }}</p>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalExamen = false" class="btn-secondary">Cancelar</button>
                    <button @click="guardarExamen" :disabled="procesando || (examenExigeObs && !examen.observacion)"
                        class="btn-primary">Guardar</button>
                </div>
            </div>
        </div>

        <!-- Afiliación -->
        <div v-if="modalAfiliacion" @click.self="modalAfiliacion = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">{{ modalAfiliacion.label }}</h3>
                <p class="text-xs text-surface-500 mb-3">
                    ¿A qué entidad? Sin el nombre, después nadie sabe a quién llamar.
                </p>
                <input v-model="entidad" class="input w-full" autofocus placeholder="Sura, Nueva EPS, Colpensiones, Compensar..." />
                <p v-if="errores.entidad" class="text-xs text-rose-600 mt-1">{{ errores.entidad }}</p>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalAfiliacion = null" class="btn-secondary">Cancelar</button>
                    <button @click="guardarAfiliacion" :disabled="procesando || !entidad" class="btn-primary">Guardar</button>
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

        <!-- Cancelar -->
        <div v-if="modalCancelar" @click.self="modalCancelar = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">Cancelar la contratación</h3>
                <p class="text-xs text-surface-500 mb-3">
                    El candidato vuelve a la terna: que esto no haya salido no lo hace mal candidato.
                </p>
                <textarea v-model="motivo" rows="3" class="input w-full" autofocus
                    placeholder="No aceptó la oferta / consiguió otro trabajo..."></textarea>
                <p v-if="errores.motivo" class="text-xs text-rose-600 mt-1">{{ errores.motivo }}</p>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalCancelar = false" class="btn-secondary">Volver</button>
                    <button @click="cancelar" :disabled="procesando || motivo.trim().length < 5" class="btn-primary">
                        Cancelar contratación
                    </button>
                </div>
            </div>
        </div>

        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
