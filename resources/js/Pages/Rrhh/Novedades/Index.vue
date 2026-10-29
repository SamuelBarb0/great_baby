<script setup>
/*
 * Cuadro de novedades · el corte mensual que se le manda al contador.
 *
 * Son seis bloques. Los dos primeros —ingresos y retiros— NO se capturan acá:
 * salen de la ficha del empleado, porque la fecha de ingreso y la de retiro ya
 * están guardadas y tenerlas dos veces es la forma en que este sistema se ha
 * roto antes. Los otros cuatro sí se registran.
 *
 * El descuento no se calcula en esta pantalla a propósito. La regla vive en el
 * servidor —auxilio de transporte proporcional para incapacidades, salario
 * sobre 30 días para ausencias— y repetirla en JavaScript sería tener la misma
 * cuenta en dos lados, que es exactamente lo que después se desincroniza. Acá
 * el campo se deja vacío y el servidor responde con el número Y LA FÓRMULA,
 * que es lo que se muestra en la tabla.
 */
import { ref, reactive, computed } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, router } from '@inertiajs/vue3';
import { ClipboardList, Plus, Download, AlertTriangle, Paperclip, Trash2, Pencil } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';

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
    periodo: { type: String, required: true },
    periodos: { type: Array, default: () => [] },
    resumen: { type: Object, default: () => ({}) },
    ingresos: { type: Array, default: () => [] },
    retiros: { type: Array, default: () => [] },
    bloques: { type: Object, default: () => ({}) },
    catalogo: { type: Object, default: () => ({}) },
    empleados: { type: Array, default: () => [] },
});

const procesando = ref(false);
const errores = ref({});

const periodoSel = ref(props.periodo);
const cambiarPeriodo = () => router.get('/app/rrhh/novedades', { periodo: periodoSel.value },
    { preserveState: true, replace: true });

const money = (v) => (v == null ? '—' : '$' + Number(v).toLocaleString('es-CO', { maximumFractionDigits: 0 }));
const num = (v) => (v == null ? '—' : Number(v).toLocaleString('es-CO', { maximumFractionDigits: 2 }));

/* ── alta y edición ──────────────────────────────────────────────────────── */
const modal = ref(false);
const editando = ref(null);

const vacio = () => ({
    empleado_id: null,
    tipo: 'incapacidad',
    clase: 'EG',
    fecha_inicio: hoyBogota(),
    fecha_fin: '',
    fecha_reintegro: '',
    dias: null,
    horas: null,
    nocturna: false,
    remunerada: true,
    observaciones: '',
    descuento: null,
    periodo: props.periodo,
});

const form = reactive(vacio());

const tipoActual = computed(() => props.catalogo[form.tipo] ?? { clases: {}, en_horas: false });
const clasesDelTipo = computed(() => Object.entries(tipoActual.value.clases ?? {}));

// Al cambiar de tipo, la clase anterior deja de ser válida: el servidor la
// rechaza con un 422, así que se elige la primera del tipo nuevo.
const alCambiarTipo = () => {
    const primera = clasesDelTipo.value[0];
    form.clase = primera ? primera[0] : '';
    if (tipoActual.value.en_horas) form.dias = null;
    else form.horas = null;
};

const abrirNueva = () => {
    editando.value = null;
    Object.assign(form, vacio());
    errores.value = {};
    modal.value = true;
};

const abrirEdicion = (n) => {
    editando.value = n.id;
    Object.assign(form, {
        empleado_id: n.empleado_id,
        tipo: n.tipo,
        clase: n.clase,
        fecha_inicio: n.fecha_inicio ?? '',
        fecha_fin: n.fecha_fin ?? '',
        fecha_reintegro: n.fecha_reintegro ?? '',
        dias: n.dias,
        horas: n.horas,
        nocturna: n.nocturna,
        remunerada: n.remunerada,
        observaciones: n.observaciones ?? '',
        // Sólo se precarga si alguien lo había corregido: si no, se deja vacío
        // para que el servidor lo vuelva a calcular con las reglas vigentes.
        descuento: n.descuento_manual ? n.descuento : null,
        periodo: n.periodo,
    });
    errores.value = {};
    modal.value = true;
};

const archivo = ref(null);
const alElegirArchivo = (e) => (archivo.value = e.target.files?.[0] ?? null);

const guardar = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};

    const datos = { ...form };
    if (archivo.value) datos.soporte = archivo.value;
    // Inertia manda PUT como POST con _method cuando hay archivos.
    if (editando.value) datos._method = 'put';

    const url = editando.value
        ? `/app/rrhh/novedades/${editando.value}`
        : '/app/rrhh/novedades';

    router.post(url, datos, {
        forceFormData: true,
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => {
            modal.value = false;
            archivo.value = null;
        },
        onFinish: () => (procesando.value = false),
    });
};

/*
 * `confirm()` nativo no funciona dentro del iframe de la app de escritorio:
 * devuelve false sin avisar. Acá eso dejaba el botón de borrar inerte, que es
 * el lado menos malo del fallo — pero igual significa que alguien aprieta y no
 * pasa nada, sin entender por qué.
 */
const modalConfirm = ref(null);

const eliminar = (n) => {
    modalConfirm.value = {
        titulo: `¿Eliminar esta ${n.clase_label.toLowerCase()} de ${n.persona}?`,
        mensaje: 'Queda escrita en su bitácora: se borra de la lista, no del historial.',
        color: 'rose',
        textoConfirmar: 'Eliminar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.delete(`/app/rrhh/novedades/${n.id}`, { preserveScroll: true });
        },
    };
};

const colorBloque = {
    incapacidad: 'border-amber-500',
    horas_extra: 'border-emerald-500',
    permiso: 'border-sky-500',
    ausencia: 'border-rose-500',
};
</script>

<template>
    <Head title="Cuadro de novedades" />
    <AppLayout>
        <div class="space-y-5 max-w-[100rem]">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <ClipboardList class="h-6 w-6 text-brand-600" />Cuadro de novedades
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Lo que cada mes se le manda al contador. De acá sale el ausentismo de los indicadores.
                    </p>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <select v-model="periodoSel" @change="cambiarPeriodo" class="input text-sm">
                        <option v-for="p in periodos" :key="p.valor" :value="p.valor">{{ p.label }}</option>
                    </select>
                    <a :href="`/app/rrhh/novedades/exportar?periodo=${periodo}`" class="btn-secondary">
                        <Download class="h-4 w-4" /> Exportar
                    </a>
                    <button @click="abrirNueva" class="btn-primary">
                        <Plus class="h-4 w-4" /> Registrar novedad
                    </button>
                </div>
            </div>

            <div v-if="$page.props.flash?.success"
                class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>

            <!-- Los números del corte -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <div class="card p-4">
                    <div class="text-xs text-surface-500 uppercase font-semibold">Ingresos</div>
                    <div class="text-3xl font-bold mt-1">{{ resumen.ingresos }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs text-surface-500 uppercase font-semibold">Retiros</div>
                    <div class="text-3xl font-bold mt-1">{{ resumen.retiros }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs text-surface-500 uppercase font-semibold">Días perdidos</div>
                    <div class="text-3xl font-bold mt-1 text-amber-600">{{ num(resumen.dias_perdidos) }}</div>
                    <div class="text-[11px] text-surface-400">un permiso de 2 horas cuenta 0,25</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs text-surface-500 uppercase font-semibold">Horas extra</div>
                    <div class="text-3xl font-bold mt-1 text-emerald-600">{{ num(resumen.horas_extra) }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs text-surface-500 uppercase font-semibold">Descuentos</div>
                    <div class="text-2xl font-bold mt-1">{{ money(resumen.descuentos) }}</div>
                </div>
            </div>

            <!-- Fichas incompletas: es lo que hace que el Excel llegue con huecos -->
            <div v-if="resumen.fichas_incompletas"
                class="card p-4 border-l-4 border-amber-500 text-sm flex items-start gap-2">
                <AlertTriangle class="h-5 w-5 text-amber-500 shrink-0 mt-0.5" />
                <div>
                    <div class="font-bold">
                        {{ resumen.fichas_incompletas }}
                        {{ resumen.fichas_incompletas === 1 ? 'ingreso tiene' : 'ingresos tienen' }} la ficha incompleta
                    </div>
                    <div class="text-surface-500">
                        Esas celdas le llegan vacías al contador. Abajo, en el bloque de ingresos, se ve qué falta de cada quien.
                    </div>
                </div>
            </div>

            <!-- ── Ingresos ─────────────────────────────────────────────── -->
            <div class="card overflow-x-auto border-l-4 border-brand-500">
                <div class="p-3 border-b font-bold text-sm uppercase text-surface-500">
                    Ingresos · {{ ingresos.length }}
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-3">Persona</th>
                            <th class="text-left p-3">Ingreso</th>
                            <th class="text-left p-3">Contrato</th>
                            <th class="text-left p-3">Afiliado por</th>
                            <th class="text-right p-3">Salario</th>
                            <th class="text-left p-3">Afiliaciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="e in ingresos" :key="e.id" class="hover:bg-surface-50">
                            <td class="p-3">
                                <a :href="`/app/rrhh/empleados/${e.id}`" class="font-bold hover:underline">{{ e.nombre }}</a>
                                <div class="text-xs text-surface-500">{{ e.documento }} · {{ e.cargo }}</div>
                                <div v-if="e.faltantes.length" class="text-xs text-amber-600 font-semibold mt-0.5">
                                    Falta: {{ e.faltantes.join(', ') }}
                                </div>
                            </td>
                            <td class="p-3 text-xs">{{ e.fecha_ingreso }}</td>
                            <td class="p-3 text-xs">{{ e.tipo_contrato || '—' }}</td>
                            <td class="p-3 text-xs">{{ e.empresa || '—' }}</td>
                            <td class="p-3 text-right text-xs whitespace-nowrap">{{ e.salario }}</td>
                            <td class="p-3 text-xs">
                                <div>{{ e.eps || '—' }}</div>
                                <div class="text-surface-400">
                                    {{ e.pension || '—' }} · riesgo {{ e.arl_nivel_riesgo || '—' }}
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!ingresos.length">
                            <td colspan="6" class="p-6 text-center text-surface-400">Nadie entró este mes.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ── Retiros ──────────────────────────────────────────────── -->
            <div class="card overflow-x-auto border-l-4 border-surface-400">
                <div class="p-3 border-b font-bold text-sm uppercase text-surface-500">
                    Retiros · {{ retiros.length }}
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-3">Persona</th>
                            <th class="text-left p-3">Cargo</th>
                            <th class="text-left p-3">Razón comercial / ciudad</th>
                            <th class="text-left p-3">Fecha</th>
                            <th class="text-left p-3">Motivo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="e in retiros" :key="e.id" class="hover:bg-surface-50">
                            <td class="p-3">
                                <a :href="`/app/rrhh/empleados/${e.id}`" class="font-bold hover:underline">{{ e.nombre }}</a>
                                <div class="text-xs text-surface-500">{{ e.documento }}</div>
                            </td>
                            <td class="p-3 text-xs">{{ e.cargo }}</td>
                            <td class="p-3 text-xs">
                                {{ [e.empresa, e.ciudad].filter(Boolean).join(' / ') || '—' }}
                            </td>
                            <td class="p-3 text-xs">{{ e.fecha_retiro }}</td>
                            <td class="p-3 text-xs">{{ e.motivo || '—' }}</td>
                        </tr>
                        <tr v-if="!retiros.length">
                            <td colspan="5" class="p-6 text-center text-surface-400">Nadie salió este mes.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ── Los cuatro bloques que sí se registran ───────────────── -->
            <div v-for="(def, clave) in catalogo" :key="clave" class="card overflow-x-auto border-l-4"
                :class="colorBloque[clave]">
                <div class="p-3 border-b font-bold text-sm uppercase text-surface-500">
                    {{ def.label }} · {{ (bloques[clave] || []).length }}
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-3">Persona</th>
                            <th class="text-left p-3">Clase</th>
                            <th class="text-left p-3">Desde</th>
                            <th class="text-left p-3">Hasta</th>
                            <th class="text-right p-3">{{ def.en_horas ? 'Horas' : 'Días' }}</th>
                            <th class="text-left p-3">Reintegro</th>
                            <th class="text-right p-3">Descuento</th>
                            <th class="text-right p-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="n in (bloques[clave] || [])" :key="n.id" class="hover:bg-surface-50">
                            <td class="p-3">
                                <div class="font-bold">{{ n.persona }}</div>
                                <div class="text-xs text-surface-500">{{ n.documento }} · {{ n.cargo }}</div>
                                <div v-if="n.observaciones" class="text-xs text-surface-400 mt-0.5">{{ n.observaciones }}</div>
                            </td>
                            <td class="p-3 text-xs">
                                {{ n.clase_label }}
                                <span v-if="n.nocturna" class="ml-1 text-indigo-600 font-semibold">nocturna</span>
                                <span v-if="!n.remunerada" class="ml-1 text-rose-600 font-semibold">sin remunerar</span>
                            </td>
                            <td class="p-3 text-xs">{{ n.fecha_inicio }}</td>
                            <td class="p-3 text-xs">{{ n.fecha_fin || '—' }}</td>
                            <td class="p-3 text-right text-xs font-semibold">
                                {{ def.en_horas ? num(n.horas) : (n.horas ? num(n.horas) + ' h' : num(n.dias)) }}
                            </td>
                            <td class="p-3 text-xs">{{ n.fecha_reintegro || '—' }}</td>
                            <td class="p-3 text-right text-xs">
                                <div class="font-semibold">{{ money(n.descuento) }}</div>
                                <!-- La cuenta al lado del número: nadie tiene que creerle al sistema -->
                                <div class="text-[11px] text-surface-400">{{ n.descuento_formula }}</div>
                                <div v-if="n.descuento_manual" class="text-[11px] text-amber-600 font-semibold">
                                    corregido a mano
                                </div>
                            </td>
                            <td class="p-3 text-right whitespace-nowrap">
                                <a v-if="n.soporte" :href="`/app/rrhh/novedades/${n.id}/soporte`" target="_blank"
                                    class="inline-flex text-surface-500 hover:text-brand-600 p-1" :title="n.soporte">
                                    <Paperclip class="h-4 w-4" />
                                </a>
                                <button @click="abrirEdicion(n)" class="text-surface-500 hover:text-brand-600 p-1">
                                    <Pencil class="h-4 w-4" />
                                </button>
                                <button @click="eliminar(n)" class="text-surface-500 hover:text-rose-600 p-1">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!(bloques[clave] || []).length">
                            <td colspan="8" class="p-6 text-center text-surface-400">Sin registros en este corte.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Alta / edición ──────────────────────────────────────────── -->
        <div v-if="modal" class="fixed inset-0 z-50 bg-black/50 flex items-start justify-center p-4 overflow-y-auto"
            @click.self="modal = false">
            <div class="card w-full max-w-2xl p-5 space-y-4 my-8">
                <h2 class="text-lg font-bold">
                    {{ editando ? 'Editar novedad' : 'Registrar novedad' }}
                </h2>

                <div class="grid md:grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Persona</span>
                        <select v-model="form.empleado_id" class="input w-full mt-1">
                            <option :value="null">Elegir…</option>
                            <option v-for="e in empleados" :key="e.id" :value="e.id">
                                {{ e.nombre }} — {{ e.documento }}
                            </option>
                        </select>
                        <span v-if="errores.empleado_id" class="text-xs text-rose-600">{{ errores.empleado_id }}</span>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Corte</span>
                        <select v-model="form.periodo" class="input w-full mt-1">
                            <option v-for="p in periodos" :key="p.valor" :value="p.valor">{{ p.label }}</option>
                        </select>
                        <span class="text-[11px] text-surface-400">
                            A qué nómina va. No se deduce de la fecha: una incapacidad que llega tarde entra al corte siguiente.
                        </span>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Tipo</span>
                        <select v-model="form.tipo" @change="alCambiarTipo" class="input w-full mt-1">
                            <option v-for="(def, clave) in catalogo" :key="clave" :value="clave">{{ def.label }}</option>
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Clase</span>
                        <select v-model="form.clase" class="input w-full mt-1">
                            <option v-for="[valor, etiqueta] in clasesDelTipo" :key="valor" :value="valor">
                                {{ etiqueta }}
                            </option>
                        </select>
                        <span v-if="errores.clase" class="text-xs text-rose-600">{{ errores.clase }}</span>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Desde</span>
                        <input v-model="form.fecha_inicio" type="date" class="input w-full mt-1" />
                        <span v-if="errores.fecha_inicio" class="text-xs text-rose-600">{{ errores.fecha_inicio }}</span>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Hasta</span>
                        <input v-model="form.fecha_fin" type="date" class="input w-full mt-1" />
                        <span v-if="errores.fecha_fin" class="text-xs text-rose-600">{{ errores.fecha_fin }}</span>
                    </label>

                    <label v-if="!tipoActual.en_horas" class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Días</span>
                        <input v-model.number="form.dias" type="number" step="0.5" min="0" class="input w-full mt-1" />
                        <span class="text-[11px] text-surface-400">Si fue por horas, dejalo vacío y usá el campo de horas.</span>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Horas</span>
                        <input v-model.number="form.horas" type="number" step="0.5" min="0" class="input w-full mt-1" />
                        <span class="text-[11px] text-surface-400">8 horas = 1 día para el ausentismo.</span>
                    </label>

                    <label v-if="!tipoActual.en_horas" class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Día de reintegro</span>
                        <input v-model="form.fecha_reintegro" type="date" class="input w-full mt-1" />
                    </label>

                    <label v-if="tipoActual.en_horas" class="flex items-center gap-2 mt-6">
                        <input v-model="form.nocturna" type="checkbox" class="rounded" />
                        <span class="text-sm">Nocturnas</span>
                    </label>

                    <label v-else class="flex items-center gap-2 mt-6">
                        <input v-model="form.remunerada" type="checkbox" class="rounded" />
                        <span class="text-sm">Remunerada (no se descuenta)</span>
                    </label>
                </div>

                <label class="block">
                    <span class="text-xs font-semibold text-surface-500 uppercase">Observaciones</span>
                    <textarea v-model="form.observaciones" rows="2" class="input w-full mt-1"></textarea>
                </label>

                <div class="grid md:grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Descuento</span>
                        <input v-model.number="form.descuento" type="number" step="0.01" min="0"
                            placeholder="lo calcula el sistema" class="input w-full mt-1" />
                        <span class="text-[11px] text-surface-400">
                            Vacío = lo calcula el sistema y muestra la fórmula. Si escribís un valor distinto,
                            queda marcado como corregido a mano y no se vuelve a recalcular.
                        </span>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Soporte</span>
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="alElegirArchivo"
                            class="input w-full mt-1 text-xs" />
                        <span class="text-[11px] text-surface-400">La incapacidad sin soporte no la reconoce la EPS.</span>
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button @click="modal = false" class="btn-secondary">Cancelar</button>
                    <button @click="guardar" :disabled="procesando || !form.empleado_id" class="btn-primary">
                        {{ procesando ? 'Guardando…' : 'Guardar' }}
                    </button>
                </div>
            </div>
        </div>

        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
