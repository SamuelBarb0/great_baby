<script setup>
/*
 * Mi espacio · lo que el empleado ve de sí mismo.
 *
 * «La idea es que el empleado pues tenga su parte también de recursos humanos
 * y que tenga también sus avisos» (Elena, 19-oct).
 *
 * No muestra el salario. Está en el contrato que firmó, y una pantalla con el
 * sueldo abierta en medio de la bodega es un problema que nadie pidió.
 */
import { ref, reactive, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    User, FileText, Wrench, GraduationCap, Clock, AlertTriangle, CheckCircle2,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    yo: { type: Object, required: true },
    asignaciones: { type: Array, default: () => [] },
    novedades: { type: Array, default: () => [] },
    documentos: { type: Array, default: () => [] },
});

const procesando = ref(false);
const errores = ref({});

const modal = ref(null);
const reporte = reactive({ novedad: 'falla', detalle: '' });

const reportar = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post(`/app/mi-espacio/asignaciones/${modal.value.id}/reportar`, { ...reporte }, {
        preserveScroll: true,
        onSuccess: () => { modal.value = null; Object.assign(reporte, { novedad: 'falla', detalle: '' }); },
        onError: (e) => (errores.value = e),
        onFinish: () => (procesando.value = false),
    });
};

const enMiPoder = computed(() => props.asignaciones.filter((a) => a.pendiente));
const devueltas = computed(() => props.asignaciones.filter((a) => !a.pendiente));

const prueba = computed(() => {
    const y = props.yo;
    if (y.prueba_resultado) return null;
    if (y.prueba_dias === null || y.prueba_dias === undefined) return null;
    return y.prueba_dias < 0
        ? 'Tu período de prueba ya terminó. Gestión Humana te va a avisar.'
        : `Estás en período de prueba hasta el ${y.prueba_hasta} (${y.prueba_dias} día(s)).`;
});
</script>

<template>
    <Head title="Mi espacio" />
    <AppLayout>
        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <User class="h-6 w-6 text-brand-600" />Hola, {{ yo.nombre.split(' ')[0] }}
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    {{ yo.cargo }} · {{ yo.area }} · entraste el {{ yo.ingreso }}
                </p>
            </div>

            <div v-if="$page.props.flash?.success"
                class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>

            <div v-if="prueba" class="p-3 rounded-lg bg-amber-500/10 border-l-4 border-amber-500 text-sm text-amber-800">
                <Clock class="h-4 w-4 inline" /> {{ prueba }}
            </div>

            <div class="grid lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2 space-y-4">
                    <!-- Lo que tengo de la empresa -->
                    <div class="card p-5">
                        <h2 class="font-bold text-sm uppercase text-surface-500 mb-1 flex items-center gap-1">
                            <Wrench class="h-4 w-4" /> Lo que tengo de la empresa
                        </h2>
                        <p class="text-xs text-surface-400 mb-3">
                            Si algo se daña, se pierde o querés devolverlo, avisá acá: queda escrito con fecha.
                        </p>

                        <ul v-if="enMiPoder.length" class="divide-y text-sm">
                            <li v-for="a in enMiPoder" :key="a.id" class="py-3">
                                <div class="flex items-start justify-between gap-2 flex-wrap">
                                    <div class="min-w-0">
                                        <div class="font-semibold">
                                            {{ a.rotulo }}
                                            <span v-if="a.cantidad > 1" class="text-surface-500">× {{ a.cantidad }}</span>
                                        </div>
                                        <div class="text-xs text-surface-400">
                                            {{ a.tipo }} · desde el {{ a.entrega }}
                                        </div>
                                    </div>
                                    <button @click="modal = a" :disabled="procesando"
                                        class="btn-secondary text-xs py-1 px-3 shrink-0">Avisar algo</button>
                                </div>

                                <div v-for="(x, i) in a.reportes" :key="i"
                                    class="mt-2 pl-3 border-l-2 text-xs"
                                    :class="x.estado === 'atendido' ? 'border-emerald-400' : 'border-amber-400'">
                                    <div class="font-semibold">{{ x.novedad }} · {{ x.cuando }}</div>
                                    <div class="text-surface-500">{{ x.detalle }}</div>
                                    <div v-if="x.respuesta" class="text-emerald-700 mt-0.5">
                                        <CheckCircle2 class="h-3 w-3 inline" /> {{ x.respuesta }}
                                    </div>
                                    <div v-else class="text-amber-600 mt-0.5">Esperando respuesta</div>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-surface-400">
                            No tenés nada de la empresa a tu nombre.
                        </p>

                        <div v-if="devueltas.length" class="mt-4 pt-3 border-t">
                            <div class="text-xs font-bold uppercase text-surface-400 mb-1">Ya devuelto</div>
                            <ul class="text-xs text-surface-500 space-y-0.5">
                                <li v-for="a in devueltas" :key="a.id">
                                    {{ a.rotulo }} · {{ a.estado_label }}
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Mis papeles -->
                    <div class="card p-5">
                        <h2 class="font-bold text-sm uppercase text-surface-500 mb-3 flex items-center gap-1">
                            <FileText class="h-4 w-4" /> Mis documentos
                        </h2>
                        <ul v-if="documentos.length" class="divide-y text-sm">
                            <li v-for="d in documentos" :key="d.id" class="py-2">
                                <a :href="`/app/mi-espacio/documentos/${d.id}`" target="_blank"
                                    class="text-brand-600 hover:underline font-semibold">{{ d.nombre }}</a>
                                <div class="text-xs text-surface-400">{{ d.tipo }} · {{ d.cuando }}</div>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-surface-400">
                            Todavía no hay documentos tuyos cargados.
                        </p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="card p-5">
                        <h2 class="font-bold text-sm uppercase text-surface-500 mb-3">Mis datos</h2>
                        <dl class="text-sm space-y-2">
                            <div>
                                <dt class="text-xs text-surface-400 uppercase">Documento</dt>
                                <dd>{{ yo.documento }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-surface-400 uppercase">Contrato</dt>
                                <dd class="capitalize">{{ yo.tipo_contrato }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-surface-400 uppercase">Correo</dt>
                                <dd class="break-all">{{ yo.email || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-surface-400 uppercase">Teléfono</dt>
                                <dd>{{ yo.telefono || '—' }}</dd>
                            </div>
                        </dl>
                        <p class="text-xs text-surface-400 mt-3">
                            ¿Algo está mal? Avisale a Gestión Humana.
                        </p>
                    </div>

                    <div v-if="yo.afiliaciones" class="card p-5">
                        <h2 class="font-bold text-sm uppercase text-surface-500 mb-2">Mis afiliaciones</h2>
                        <dl class="text-sm space-y-1">
                            <div v-for="(v, k) in yo.afiliaciones" :key="k" class="flex justify-between gap-2">
                                <dt class="text-surface-500">{{ k }}</dt>
                                <dd class="font-semibold">{{ v }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="card p-5">
                        <h2 class="font-bold text-sm uppercase text-surface-500 mb-2 flex items-center gap-1">
                            <GraduationCap class="h-4 w-4" /> Mi inducción
                        </h2>
                        <div v-if="yo.induccion === 'completada'" class="text-sm">
                            <p class="text-emerald-700 text-xs font-semibold">Hecha el {{ yo.induccion_at }}</p>
                            <p class="whitespace-pre-line mt-1 text-surface-600">{{ yo.induccion_temas }}</p>
                        </div>
                        <p v-else class="text-sm text-amber-600">
                            Pendiente. Gestión Humana te va a citar.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="modal" @click.self="modal = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">{{ modal.rotulo }}</h3>
                <p class="text-xs text-surface-500 mb-3">¿Qué pasó?</p>
                <select v-model="reporte.novedad" class="input w-full">
                    <option v-for="n in novedades" :key="n.valor" :value="n.valor">{{ n.label }}</option>
                </select>
                <div class="mt-3">
                    <label class="text-xs font-semibold">Contanos</label>
                    <textarea v-model="reporte.detalle" rows="3" class="input w-full" autofocus
                        placeholder="El teclado dejó de funcionar hace dos días..."></textarea>
                    <p v-if="errores.detalle" class="text-xs text-rose-600 mt-1">{{ errores.detalle }}</p>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modal = null" class="btn-secondary">Cancelar</button>
                    <button @click="reportar" :disabled="procesando || reporte.detalle.trim().length < 5"
                        class="btn-primary">Enviar aviso</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
