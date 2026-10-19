<script setup>
/*
 * El detalle de una solicitud.
 *
 * Acá vive la respuesta a la pregunta que hoy nadie puede contestar sin llamar
 * a alguien: «¿en qué va mi solicitud?». La bitácora de la derecha es esa
 * respuesta, y se escribe sola en cada cambio de estado.
 */
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Send, Check, X, Briefcase, History, Users } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    requisicion: { type: Object, required: true },
    bitacora: { type: Array, default: () => [] },
    candidatos: { type: Array, default: () => [] },
    puede: { type: Object, default: () => ({}) },
});

const procesando = ref(false);
const modalRechazo = ref(false);
const motivo = ref('');
const errorMotivo = ref('');

const url = (sufijo = '') => `/app/rrhh/requisiciones/${props.requisicion.id}${sufijo}`;

const enviarA = (sufijo, datos = {}, extra = {}) => {
    if (procesando.value) return;
    procesando.value = true;
    router.post(url(sufijo), datos, {
        preserveScroll: true,
        onFinish: () => (procesando.value = false),
        ...extra,
    });
};

const enviar = () => {
    if (confirm('¿Enviar la solicitud a Gestión Humana? Después ya no se puede editar.')) {
        enviarA('/enviar');
    }
};
const aprobar = () => {
    if (confirm(`¿Aprobar la solicitud ${props.requisicion.numero}?`)) enviarA('/aprobar');
};
const rechazar = () => {
    errorMotivo.value = '';
    enviarA('/rechazar', { motivo: motivo.value }, {
        onSuccess: () => { modalRechazo.value = false; motivo.value = ''; },
        onError: (e) => { errorMotivo.value = e.motivo || 'Revisá el motivo.'; },
    });
};
const abrirVacante = () => {
    if (confirm('¿Abrir la vacante con los datos de esta solicitud?')) enviarA('/vacante');
};

/*
 * Elegir de la terna.
 *
 * Es la decisión que hoy se toma por teléfono y no queda escrita en ninguna
 * parte: ni Elena puede demostrar quién pidió a quién, ni el jefe que ya
 * respondió.
 */
const elegir = (c) => {
    if (! confirm(`¿Elegir a ${c.nombre} para el cargo? Gestión Humana sigue con la contratación.`)) return;
    if (procesando.value) return;
    procesando.value = true;
    router.post(`/app/rrhh/candidatos/${c.id}/elegir`, {}, {
        preserveScroll: true,
        onFinish: () => (procesando.value = false),
    });
};

const enTerna = computed(() => props.candidatos.filter((c) => c.en_terna));

const money = (n) => (n ? '$' + Number(n).toLocaleString('es-CO', { maximumFractionDigits: 0 }) : '—');
const badge = (color) => ({
    gray: 'bg-surface-200 text-surface-700',
    warning: 'bg-amber-100 text-amber-800',
    success: 'bg-emerald-100 text-emerald-800',
    danger: 'bg-rose-100 text-rose-800',
}[color] || 'bg-surface-100 text-surface-700');
</script>

<template>
    <Head :title="requisicion.numero" />
    <AppLayout>
        <div class="space-y-4">
            <Link href="/app/rrhh/requisiciones"
                class="text-sm text-surface-500 hover:text-brand-600 inline-flex items-center gap-1">
                <ArrowLeft class="h-4 w-4" /> Volver a las solicitudes
            </Link>

            <div v-if="$page.props.flash?.success"
                class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>

            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <div class="flex items-center gap-3 flex-wrap">
                        <h1 class="text-2xl font-bold">{{ requisicion.numero }}</h1>
                        <span :class="['px-2 py-0.5 rounded text-[11px] font-bold uppercase', badge(requisicion.estado_color)]">
                            {{ requisicion.estado_label }}
                        </span>
                    </div>
                    <p class="text-surface-600 mt-1">
                        {{ requisicion.numero_vacantes }} × <strong>{{ requisicion.cargo }}</strong>
                        <span v-if="requisicion.area"> · {{ requisicion.area }}</span>
                    </p>
                    <p class="text-xs text-surface-400">Solicita: {{ requisicion.solicitante || '—' }}</p>
                </div>

                <div class="flex gap-2 flex-wrap">
                    <button v-if="puede.enviar" @click="enviar" :disabled="procesando" class="btn-primary">
                        <Send class="h-4 w-4" /> Enviar a Gestión Humana
                    </button>
                    <button v-if="puede.decidir" @click="aprobar" :disabled="procesando" class="btn-primary">
                        <Check class="h-4 w-4" /> Aprobar
                    </button>
                    <button v-if="puede.decidir" @click="modalRechazo = true" :disabled="procesando" class="btn-secondary">
                        <X class="h-4 w-4" /> Rechazar
                    </button>
                    <button v-if="puede.abrir_vacante" @click="abrirVacante" :disabled="procesando" class="btn-primary">
                        <Briefcase class="h-4 w-4" /> Abrir vacante
                    </button>
                </div>
            </div>

            <div v-if="requisicion.motivo_rechazo"
                class="p-3 rounded-lg bg-rose-500/10 border-l-4 border-rose-500 text-sm">
                <strong class="text-rose-700">Rechazada.</strong> {{ requisicion.motivo_rechazo }}
            </div>

            <div class="grid lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2 space-y-4">
                    <div class="card p-5 space-y-4">
                        <h3 class="font-bold text-sm uppercase text-surface-500">El cargo</h3>

                        <div class="grid sm:grid-cols-3 gap-4 text-sm">
                            <div>
                                <div class="text-xs text-surface-400 uppercase">Salario</div>
                                <div class="font-semibold">
                                    {{ money(requisicion.salario_min) }}
                                    <span v-if="requisicion.salario_max"> - {{ money(requisicion.salario_max) }}</span>
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-surface-400 uppercase">Contrato</div>
                                <div class="font-semibold capitalize">{{ requisicion.tipo_contrato || '—' }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-surface-400 uppercase">Se necesita</div>
                                <div class="font-semibold">{{ requisicion.fecha_requerida || '—' }}</div>
                            </div>
                        </div>

                        <div v-for="campo in [
                            { t: '¿Por qué se necesita?', v: requisicion.motivo },
                            { t: 'Funciones', v: requisicion.funciones },
                            { t: 'Competencias', v: requisicion.competencias },
                            { t: 'Observaciones', v: requisicion.observaciones },
                        ]" :key="campo.t">
                            <div v-if="campo.v">
                                <div class="text-xs text-surface-400 uppercase font-semibold">{{ campo.t }}</div>
                                <p class="text-sm whitespace-pre-line">{{ campo.v }}</p>
                            </div>
                        </div>

                        <div class="grid sm:grid-cols-2 gap-4 text-sm">
                            <div v-if="requisicion.estudios">
                                <div class="text-xs text-surface-400 uppercase">Estudios</div>
                                <div>{{ requisicion.estudios }}</div>
                            </div>
                            <div v-if="requisicion.experiencia">
                                <div class="text-xs text-surface-400 uppercase">Experiencia</div>
                                <div>{{ requisicion.experiencia }}</div>
                            </div>
                        </div>
                    </div>

                    <div v-if="requisicion.vacantes.length" class="card p-5">
                        <h3 class="font-bold text-sm uppercase text-surface-500 mb-2">
                            Vacantes abiertas de esta solicitud
                        </h3>
                        <ul class="divide-y text-sm">
                            <li v-for="v in requisicion.vacantes" :key="v.id" class="py-2 flex justify-between">
                                <Link :href="`/app/rrhh/vacantes/${v.id}`" class="text-brand-600 hover:underline font-semibold">
                                    {{ v.titulo }}
                                </Link>
                                <span class="text-xs uppercase text-surface-500">{{ v.estado }}</span>
                            </li>
                        </ul>
                    </div>

                    <!-- En qué van los candidatos · sin preguntarle a nadie -->
                    <div v-if="candidatos.length" class="card p-5">
                        <h3 class="font-bold text-sm uppercase text-surface-500 mb-1 flex items-center gap-1">
                            <Users class="h-4 w-4" /> Cómo van los candidatos
                        </h3>
                        <p v-if="enTerna.length" class="text-xs text-brand-600 font-semibold mb-2">
                            Hay {{ enTerna.length }} en la terna esperando que elijas.
                        </p>
                        <ul class="divide-y text-sm">
                            <li v-for="c in candidatos" :key="c.id" class="py-2.5">
                                <div class="flex items-start justify-between gap-2 flex-wrap">
                                    <div class="min-w-0">
                                        <div class="font-semibold">
                                            {{ c.nombre }}
                                            <span v-if="c.elegido" class="text-xs text-emerald-700 font-normal">· lo elegiste</span>
                                        </div>
                                        <div class="flex items-center gap-1 flex-wrap mt-0.5">
                                            <span :class="['px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(c.etapa_color)]">
                                                {{ c.etapa_label }}
                                            </span>
                                            <span v-if="c.valoracion_label"
                                                :class="['px-2 py-0.5 rounded text-[10px] font-bold', badge(c.valoracion_color)]">
                                                {{ c.valoracion_label }}
                                            </span>
                                        </div>
                                        <p v-if="c.valoracion_observacion" class="text-xs text-surface-500 mt-1">
                                            {{ c.valoracion_observacion }}
                                        </p>
                                    </div>
                                    <button v-if="c.en_terna && !c.elegido" @click="elegir(c)" :disabled="procesando"
                                        class="btn-primary text-xs py-1 px-3 shrink-0">
                                        Elegir a este
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- La línea de tiempo: para esto se hizo el módulo -->
                <div class="card p-5">
                    <h3 class="font-bold text-sm uppercase text-surface-500 mb-3 flex items-center gap-1">
                        <History class="h-4 w-4" /> En qué va
                    </h3>
                    <ol class="space-y-3">
                        <li v-for="(b, i) in bitacora" :key="i" class="relative pl-5 text-sm">
                            <span class="absolute left-0 top-1.5 h-2 w-2 rounded-full bg-brand-500"></span>
                            <div class="font-semibold">{{ b.resumen }}</div>
                            <div class="text-xs text-surface-400">{{ b.usuario }} · {{ b.cuando }}</div>
                        </li>
                        <li v-if="!bitacora.length" class="text-sm text-surface-400">Todavía no pasó nada.</li>
                    </ol>
                </div>
            </div>
        </div>

        <div v-if="modalRechazo" @click.self="modalRechazo = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">Rechazar {{ requisicion.numero }}</h3>
                <p class="text-xs text-surface-500 mb-3">
                    Sin motivo, quien la pidió no sabe qué corregir. Lo va a ver en su pantalla.
                </p>
                <textarea v-model="motivo" rows="4" class="input w-full" autofocus
                    placeholder="El presupuesto del área no da para un cargo más este trimestre."></textarea>
                <p v-if="errorMotivo" class="text-xs text-rose-600 mt-1">{{ errorMotivo }}</p>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalRechazo = false" class="btn-secondary">Cancelar</button>
                    <button @click="rechazar" :disabled="procesando || motivo.trim().length < 10" class="btn-primary">
                        Rechazar solicitud
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
