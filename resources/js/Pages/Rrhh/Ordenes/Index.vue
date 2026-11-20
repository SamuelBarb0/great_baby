<script setup>
/*
 * Órdenes de contratación · lo que autoriza la Junta Directiva.
 *
 * La pantalla la abren dos lados: Gestión Humana, que pide, y la Junta, que
 * decide. Los botones de autorizar y negar sólo los ve la Junta — y el
 * servidor lo vuelve a comprobar, porque esconder un botón no es un permiso.
 */
import { ref, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { BadgeCheck, Check, X, Printer, Pencil, AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useEscClose } from '@/composables/useEscClose';

const props = defineProps({
    ordenes: { type: Object, required: true },
    filtro: { type: String, default: 'pendiente' },
    pendientes: { type: Number, default: 0 },
    es_junta: { type: Boolean, default: false },
    es_gestion_humana: { type: Boolean, default: false },
    periodicidades: { type: Array, default: () => [] },
});

const procesando = ref(false);
const errores = ref({});

const estado = ref(props.filtro);
const filtrar = () => router.get('/app/rrhh/ordenes', { estado: estado.value },
    { preserveState: true, replace: true });

const enviar = (url, datos, extra = {}) => {
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

/* autorizar · la Junta puede bajar el salario propuesto */
const modalAutorizar = ref(null);
useEscClose(modalAutorizar);
const salarioAutorizado = ref(null);
const notaAutorizacion = ref('');
const autorizar = () => enviar(`/app/rrhh/ordenes/${modalAutorizar.value.id}/autorizar`,
    { salario_autorizado: salarioAutorizado.value, motivo: notaAutorizacion.value },
    { onSuccess: () => { modalAutorizar.value = null; notaAutorizacion.value = ''; } });

/*
 * Diligenciar el formato GH-F-018.
 *
 * El papel que firma la Junta pide once casillas y la orden guardaba cinco.
 * Las otras seis se escribían a mano sobre el impreso — y una casilla
 * completada DESPUÉS de la firma es una autorización que dice una cosa y un
 * contrato que dice otra.
 *
 * Sólo se edita mientras está pendiente: lo que la Junta ya autorizó no se
 * toca.
 */
const modalFormato = ref(null);
useEscClose(modalFormato);
const formato = reactive({
    area: '', fecha_inicio: '', horario: '', ciudad: '',
    periodicidad_pago: null, salario_propuesto: null, observaciones: '',
});

const abrirFormato = (o) => {
    Object.assign(formato, {
        area: o.area ?? '',
        fecha_inicio: o.fecha_inicio ?? '',
        horario: o.horario ?? '',
        ciudad: o.ciudad ?? '',
        periodicidad_pago: o.periodicidad_pago ?? null,
        salario_propuesto: o.salario,
        observaciones: o.observaciones ?? '',
    });
    errores.value = {};
    modalFormato.value = o;
};

const guardarFormato = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.put(`/app/rrhh/ordenes/${modalFormato.value.id}`, { ...formato }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => (modalFormato.value = null),
        onFinish: () => (procesando.value = false),
    });
};

/* negar · siempre con motivo */
const modalNegar = ref(null);
useEscClose(modalNegar);
const motivo = ref('');
const negar = () => enviar(`/app/rrhh/ordenes/${modalNegar.value.id}/negar`,
    { motivo: motivo.value },
    { onSuccess: () => { modalNegar.value = null; motivo.value = ''; } });

const money = (n) => (n ? '$' + Number(n).toLocaleString('es-CO', { maximumFractionDigits: 0 }) : '—');
const badge = (c) => ({
    warning: 'bg-amber-100 text-amber-800',
    success: 'bg-emerald-100 text-emerald-800',
    danger: 'bg-rose-100 text-rose-800',
}[c] || 'bg-surface-200 text-surface-700');
</script>

<template>
    <Head title="Órdenes de contratación" />
    <AppLayout>
        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <BadgeCheck class="h-6 w-6 text-brand-600" />Órdenes de contratación
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    {{ es_junta
                        ? 'La empresa sólo entra a un contrato si la Junta lo autoriza. Acá se decide.'
                        : 'Lo que se le pidió a la Junta Directiva para poder contratar.' }}
                </p>
            </div>

            <div v-if="$page.props.flash?.success"
                class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.errors?.orden"
                class="p-3 rounded-lg bg-amber-500/10 border-l-4 border-amber-500 text-amber-800 text-sm">
                {{ $page.props.errors.orden }}
            </div>

            <div class="flex items-center gap-3 flex-wrap">
                <div class="card p-4 flex-1 min-w-[12rem]">
                    <div class="text-xs text-surface-500 uppercase font-semibold">Esperando a la Junta</div>
                    <div class="text-3xl font-bold mt-1" :class="pendientes ? 'text-amber-600' : 'text-surface-400'">
                        {{ pendientes }}
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs font-semibold text-surface-500 uppercase">Estado</label>
                    <select v-model="estado" @change="filtrar" class="input text-sm">
                        <option value="pendiente">Pendientes</option>
                        <option value="autorizada">Autorizadas</option>
                        <option value="negada">Negadas</option>
                        <option value="todas">Todas</option>
                    </select>
                </div>
            </div>

            <div class="space-y-3">
                <div v-for="o in ordenes.data" :key="o.id" class="card p-4">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-bold">{{ o.numero }}</span>
                                <span :class="['px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(o.estado_color)]">
                                    {{ o.estado_label }}
                                </span>
                            </div>
                            <div class="font-semibold mt-1">{{ o.candidato }}
                                <span class="text-surface-500 font-normal text-sm">· {{ o.cargo }}</span>
                            </div>
                            <div class="text-xs text-surface-500">
                                {{ o.documento || 'sin cédula' }} ·
                                pedida por {{ o.solicitada_por }} el {{ o.solicitada }}
                            </div>
                            <p v-if="o.justificacion" class="text-sm mt-1">{{ o.justificacion }}</p>
                            <p v-if="o.motivo_decision" class="text-sm mt-1 text-surface-600">
                                <strong>{{ o.decidida_por }}:</strong> {{ o.motivo_decision }}
                            </p>

                            <!-- Las casillas del formato GH-F-018 -->
                            <dl class="grid sm:grid-cols-3 gap-x-4 gap-y-1 mt-2 text-xs">
                                <div v-for="c in [
                                    { k: 'Área', v: o.area },
                                    { k: 'Inicia', v: o.fecha_inicio },
                                    { k: 'Horario', v: o.horario },
                                    { k: 'Ciudad', v: o.ciudad },
                                    { k: 'Tipo de pago', v: o.periodicidad_pago },
                                ]" :key="c.k">
                                    <dt class="text-surface-400 uppercase text-[10px]">{{ c.k }}</dt>
                                    <dd :class="c.v ? 'font-semibold' : 'text-rose-500 italic'">
                                        {{ c.v || 'sin diligenciar' }}
                                    </dd>
                                </div>
                            </dl>

                            <!-- Si se firma así, alguien completa el papel a mano después -->
                            <div v-if="o.estado === 'pendiente' && o.faltantes.length"
                                class="mt-2 text-xs text-amber-700 flex items-start gap-1">
                                <AlertTriangle class="h-3.5 w-3.5 shrink-0 mt-0.5" />
                                <span>
                                    El formato va a firma incompleto. Falta: {{ o.faltantes.join(', ') }}.
                                </span>
                            </div>
                        </div>

                        <div class="text-right shrink-0">
                            <div class="text-xs text-surface-400 uppercase">Salario</div>
                            <div class="text-lg font-bold">{{ money(o.salario) }}</div>
                            <div class="text-xs text-surface-400">{{ o.tipo_contrato }}</div>

                            <div v-if="es_junta && o.estado === 'pendiente'" class="flex gap-2 mt-2">
                                <button @click="modalAutorizar = o; salarioAutorizado = o.salario"
                                    :disabled="procesando" class="btn-primary text-xs py-1 px-3">
                                    <Check class="h-3.5 w-3.5" /> Autorizar
                                </button>
                                <button @click="modalNegar = o" :disabled="procesando"
                                    class="btn-secondary text-xs py-1 px-3">
                                    <X class="h-3.5 w-3.5" /> Negar
                                </button>
                            </div>
                            <div v-else-if="o.estado !== 'pendiente'" class="text-xs text-surface-400 mt-2">
                                {{ o.decidida }}
                            </div>

                            <div class="flex gap-2 mt-2 justify-end">
                                <!-- Gestión Humana diligencia; la Junta sólo lee y firma -->
                                <button v-if="es_gestion_humana && o.estado === 'pendiente'"
                                    @click="abrirFormato(o)" class="btn-secondary text-xs py-1 px-3">
                                    <Pencil class="h-3.5 w-3.5" /> Formato
                                </button>
                                <a :href="`/app/rrhh/ordenes/${o.id}/pdf`" class="btn-secondary text-xs py-1 px-3">
                                    <Printer class="h-3.5 w-3.5" /> GH-F-018
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="!ordenes.data.length" class="card p-8 text-center text-surface-400 text-sm">
                    No hay órdenes acá.
                </div>
            </div>
        </div>

        <!-- Autorizar -->
        <!-- Diligenciar el formato GH-F-018 antes de llevarlo a firma -->
        <div v-if="modalFormato" @click.self="modalFormato = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-start justify-center p-4 overflow-y-auto">
            <div class="card w-full max-w-2xl p-5 space-y-4 my-8">
                <div>
                    <h2 class="text-lg font-bold">Formato de autorización · {{ modalFormato.numero }}</h2>
                    <p class="text-xs text-surface-500 mt-0.5">
                        GH-F-018 · {{ modalFormato.candidato }} como {{ modalFormato.cargo }}.
                        Esto es lo que la Junta firma, así que se completa antes.
                    </p>
                </div>

                <div class="grid sm:grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Área</span>
                        <input v-model="formato.area" class="input w-full mt-1" />
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Fecha de inicio</span>
                        <input v-model="formato.fecha_inicio" type="date" class="input w-full mt-1" />
                        <span v-if="errores.fecha_inicio" class="text-xs text-rose-600">{{ errores.fecha_inicio }}</span>
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Horario de trabajo</span>
                        <input v-model="formato.horario" placeholder="Lunes a viernes 8:00 a 17:00"
                            class="input w-full mt-1" />
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Ciudad donde va a laborar</span>
                        <input v-model="formato.ciudad" class="input w-full mt-1" />
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Tipo de pago</span>
                        <select v-model="formato.periodicidad_pago" class="input w-full mt-1">
                            <option :value="null">—</option>
                            <option v-for="p in periodicidades" :key="p.valor" :value="p.valor">{{ p.label }}</option>
                        </select>
                        <span v-if="errores.periodicidad_pago" class="text-xs text-rose-600">
                            {{ errores.periodicidad_pago }}
                        </span>
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="text-xs font-semibold text-surface-500 uppercase">Salario a devengar</span>
                        <input v-model.number="formato.salario_propuesto" type="number" min="0"
                            class="input w-full mt-1" />
                        <span class="text-[11px] text-surface-400">
                            Es el techo: después no se puede firmar el contrato por más.
                        </span>
                    </label>
                </div>

                <label class="block">
                    <span class="text-xs font-semibold text-surface-500 uppercase">Observaciones adicionales</span>
                    <textarea v-model="formato.observaciones" rows="3" class="input w-full mt-1"></textarea>
                </label>

                <div class="flex justify-end gap-2 pt-1">
                    <button @click="modalFormato = null" class="btn-secondary">Cancelar</button>
                    <button @click="guardarFormato" :disabled="procesando" class="btn-primary">
                        {{ procesando ? 'Guardando…' : 'Guardar formato' }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="modalAutorizar" @click.self="modalAutorizar = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">Autorizar {{ modalAutorizar.numero }}</h3>
                <p class="text-xs text-surface-500 mb-3">
                    {{ modalAutorizar.candidato }} · {{ modalAutorizar.cargo }}
                </p>
                <div>
                    <label class="text-xs font-semibold">Salario que se autoriza</label>
                    <input type="number" step="1000" v-model.number="salarioAutorizado" class="input w-full" />
                    <p class="text-[10px] text-surface-400 mt-0.5">
                        Es un techo: después no se puede firmar por más que esto.
                    </p>
                </div>
                <div class="mt-3">
                    <label class="text-xs font-semibold">Nota (opcional)</label>
                    <textarea v-model="notaAutorizacion" rows="2" class="input w-full"></textarea>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalAutorizar = null" class="btn-secondary">Cancelar</button>
                    <button @click="autorizar" :disabled="procesando" class="btn-primary">Autorizar</button>
                </div>
            </div>
        </div>

        <!-- Negar -->
        <div v-if="modalNegar" @click.self="modalNegar = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">Negar {{ modalNegar.numero }}</h3>
                <p class="text-xs text-surface-500 mb-3">
                    Sin motivo, Gestión Humana no sabe si insistir, bajar el salario o cerrar la vacante.
                </p>
                <textarea v-model="motivo" rows="3" class="input w-full" autofocus
                    placeholder="El presupuesto del trimestre no da para un cargo más..."></textarea>
                <p v-if="errores.motivo" class="text-xs text-rose-600 mt-1">{{ errores.motivo }}</p>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalNegar = null" class="btn-secondary">Volver</button>
                    <button @click="negar" :disabled="procesando || motivo.trim().length < 10" class="btn-primary">
                        Negar orden
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
