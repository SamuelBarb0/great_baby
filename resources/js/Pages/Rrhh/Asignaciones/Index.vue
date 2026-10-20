<script setup>
/*
 * Dotación y herramientas · qué tiene cada quien.
 *
 * «La persona se fue y se llevó la tablet. Y no nos ha entregado la tablet»
 * (Elena, 19-oct). Esta pantalla es la respuesta a la pregunta que ese día
 * nadie pudo contestar: ¿qué tiene esta persona?
 *
 * La abren dos: el líder, que entrega porque sabe qué necesita su gente, y
 * Gestión Humana, que arma el paz y salvo cuando alguien se va.
 */
import { ref, reactive, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Wrench, Plus, AlertTriangle, Download } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    asignaciones: { type: Array, default: () => [] },
    reportes: { type: Array, default: () => [] },
    empleados: { type: Array, default: () => [] },
    tipos: { type: Array, default: () => [] },
    filtro: { type: String, default: 'asignada' },
    es_gestion_humana: { type: Boolean, default: false },
    sin_devolver: { type: Number, default: 0 },
});

const procesando = ref(false);
const errores = ref({});

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

const estado = ref(props.filtro);
const filtrar = () => router.get('/app/rrhh/asignaciones', { estado: estado.value },
    { preserveState: true, replace: true });

/* entregar */
const modalEntrega = ref(false);
const entrega = reactive({
    empleado_id: null, tipo: 'herramienta', descripcion: '', identificador: '',
    cantidad: 1, valor: null, fecha_entrega: new Date().toISOString().slice(0, 10),
    observaciones: '',
});
const guardarEntrega = () => enviar('/app/rrhh/asignaciones', { ...entrega }, {
    onSuccess: () => {
        modalEntrega.value = false;
        Object.assign(entrega, { descripcion: '', identificador: '', cantidad: 1, valor: null, observaciones: '' });
    },
});

/* devolver */
const modalDevolucion = ref(null);
const devolucion = reactive({
    estado: 'devuelta',
    fecha_devolucion: new Date().toISOString().slice(0, 10),
    estado_devolucion: '',
});
const exigeDetalle = computed(() => devolucion.estado !== 'devuelta');
const guardarDevolucion = () => enviar(
    `/app/rrhh/asignaciones/${modalDevolucion.value.id}/devolver`, { ...devolucion },
    { onSuccess: () => { modalDevolucion.value = null; devolucion.estado_devolucion = ''; } });

/* responder un aviso */
const modalReporte = ref(null);
const respuesta = ref('');
const responder = () => enviar(`/app/rrhh/reportes/${modalReporte.value.id}/atender`,
    { respuesta: respuesta.value },
    { onSuccess: () => { modalReporte.value = null; respuesta.value = ''; } });

const money = (n) => (n ? '$' + Number(n).toLocaleString('es-CO', { maximumFractionDigits: 0 }) : '—');
const badge = (e) => ({
    asignada: 'bg-blue-100 text-blue-800',
    devuelta: 'bg-emerald-100 text-emerald-800',
    perdida: 'bg-rose-100 text-rose-800',
    dada_de_baja: 'bg-surface-200 text-surface-700',
}[e] || 'bg-surface-100');
</script>

<template>
    <Head title="Dotación y herramientas" />
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Wrench class="h-6 w-6 text-brand-600" />Dotación y herramientas
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Qué tiene cada quien, para que nadie se vaya con algo sin que nos demos cuenta.
                    </p>
                </div>
                <button @click="modalEntrega = true" class="btn-primary">
                    <Plus class="h-4 w-4" /> Registrar entrega
                </button>
            </div>

            <div v-if="$page.props.flash?.success"
                class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>

            <!-- Lo que la gente reportó y nadie ha respondido -->
            <div v-if="reportes.length" class="card p-5 border-l-4 border-amber-500">
                <h2 class="font-bold text-sm uppercase text-surface-500 mb-2 flex items-center gap-1">
                    <AlertTriangle class="h-4 w-4 text-amber-500" /> Avisos sin responder ({{ reportes.length }})
                </h2>
                <ul class="divide-y text-sm">
                    <li v-for="x in reportes" :key="x.id" class="py-2.5 flex items-start justify-between gap-2 flex-wrap">
                        <div class="min-w-0">
                            <div class="font-semibold">
                                {{ x.persona }} · {{ x.cosa }}
                                <span v-if="x.es_de_gh"
                                    class="ml-1 px-2 py-0.5 rounded bg-rose-100 text-rose-800 text-[10px] font-bold uppercase">
                                    Gestión Humana
                                </span>
                            </div>
                            <div class="text-xs text-surface-500">{{ x.novedad }} · {{ x.detalle }}</div>
                            <div class="text-xs text-surface-400">{{ x.cuando }}</div>
                        </div>
                        <button @click="modalReporte = x" class="btn-secondary text-xs py-1 px-3 shrink-0">
                            Responder
                        </button>
                    </li>
                </ul>
            </div>

            <div class="flex items-center gap-3 flex-wrap">
                <div class="card p-4 flex-1 min-w-[12rem]">
                    <div class="text-xs text-surface-500 uppercase font-semibold">Sin devolver</div>
                    <div class="text-3xl font-bold mt-1" :class="sin_devolver ? 'text-blue-600' : 'text-surface-400'">
                        {{ sin_devolver }}
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs font-semibold text-surface-500 uppercase">Estado</label>
                    <select v-model="estado" @change="filtrar" class="input text-sm">
                        <option value="asignada">Con la persona</option>
                        <option value="devuelta">Devueltas</option>
                        <option value="perdida">No devueltas</option>
                        <option value="todas">Todas</option>
                    </select>
                </div>
            </div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-3">Persona</th>
                            <th class="text-left p-3">Qué</th>
                            <th class="text-left p-3">Desde</th>
                            <th class="text-right p-3">Valor</th>
                            <th class="text-center p-3">Estado</th>
                            <th class="text-right p-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="a in asignaciones" :key="a.id" class="hover:bg-surface-50">
                            <td class="p-3">
                                <div class="font-bold">{{ a.persona }}</div>
                                <div class="text-xs text-surface-500">{{ a.cargo }} · {{ a.area }}</div>
                                <!-- El caso de la tablet: se fue y nadie se dio cuenta -->
                                <div v-if="a.persona_retirada && a.pendiente"
                                    class="text-xs text-rose-600 font-bold mt-0.5">
                                    Ya no trabaja acá y no lo devolvió
                                </div>
                            </td>
                            <td class="p-3">
                                <div class="font-semibold">{{ a.rotulo }}</div>
                                <div class="text-xs text-surface-400">
                                    {{ a.tipo }}<span v-if="a.cantidad > 1"> · {{ a.cantidad }} unidades</span>
                                </div>
                            </td>
                            <td class="p-3 text-xs">{{ a.entrega }}</td>
                            <td class="p-3 text-right text-xs">{{ money(a.valor) }}</td>
                            <td class="p-3 text-center">
                                <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(a.estado)]">
                                    {{ a.estado_label }}
                                </span>
                                <div v-if="a.estado_devolucion" class="text-xs text-surface-500 mt-0.5">
                                    {{ a.estado_devolucion }}
                                </div>
                            </td>
                            <td class="p-3 text-right">
                                <button v-if="a.pendiente" @click="modalDevolucion = a"
                                    class="btn-secondary text-xs py-1 px-3">Cerrar</button>
                            </td>
                        </tr>
                        <tr v-if="!asignaciones.length">
                            <td colspan="6" class="p-8 text-center text-surface-400 text-sm">
                                Nada acá.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Registrar entrega -->
        <div v-if="modalEntrega" @click.self="modalEntrega = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-xl w-full max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-bold mb-3">Registrar entrega</h3>
                <div class="space-y-3">
                    <div>
                        <label class="text-xs font-semibold">¿A quién?</label>
                        <select v-model.number="entrega.empleado_id" class="input w-full">
                            <option :value="null">Elegí a la persona</option>
                            <option v-for="e in empleados" :key="e.id" :value="e.id">{{ e.etiqueta }}</option>
                        </select>
                        <p v-if="errores.empleado_id" class="text-xs text-rose-600 mt-1">{{ errores.empleado_id }}</p>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs font-semibold">Tipo</label>
                            <select v-model="entrega.tipo" class="input w-full">
                                <option v-for="t in tipos" :key="t.valor" :value="t.valor">{{ t.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Fecha de entrega</label>
                            <input type="date" v-model="entrega.fecha_entrega" class="input w-full" />
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Qué se entrega</label>
                        <input v-model="entrega.descripcion" class="input w-full"
                            placeholder="Tablet Samsung Tab A8 / Camisa dotación talla M" />
                        <p v-if="errores.descripcion" class="text-xs text-rose-600 mt-1">{{ errores.descripcion }}</p>
                    </div>
                    <div class="grid sm:grid-cols-3 gap-2">
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold">Serial o placa</label>
                            <input v-model="entrega.identificador" class="input w-full" />
                            <p class="text-[10px] text-surface-400 mt-0.5">
                                Sin esto, «una tablet» después no se puede reclamar: hay que poder decir cuál.
                            </p>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Cantidad</label>
                            <input type="number" min="1" v-model.number="entrega.cantidad" class="input w-full" />
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Valor aproximado</label>
                        <input type="number" step="1000" v-model.number="entrega.valor" class="input w-full" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Observaciones</label>
                        <textarea v-model="entrega.observaciones" rows="2" class="input w-full"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button @click="modalEntrega = false" class="btn-secondary">Cancelar</button>
                    <button @click="guardarEntrega"
                        :disabled="procesando || !entrega.empleado_id || !entrega.descripcion"
                        class="btn-primary">Registrar</button>
                </div>
            </div>
        </div>

        <!-- Cerrar (devolución) -->
        <div v-if="modalDevolucion" @click.self="modalDevolucion = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">{{ modalDevolucion.rotulo }}</h3>
                <p class="text-xs text-surface-500 mb-3">{{ modalDevolucion.persona }}</p>
                <div class="space-y-3">
                    <div class="grid sm:grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs font-semibold">¿Qué pasó?</label>
                            <select v-model="devolucion.estado" class="input w-full">
                                <option value="devuelta">Lo devolvió</option>
                                <option value="perdida">No lo devolvió</option>
                                <option value="dada_de_baja">Se dio de baja</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Fecha</label>
                            <input type="date" v-model="devolucion.fecha_devolucion" class="input w-full" />
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">
                            En qué estado llegó
                            <span v-if="exigeDetalle" class="text-rose-600">· obligatorio</span>
                        </label>
                        <textarea v-model="devolucion.estado_devolucion" rows="2" class="input w-full"
                            placeholder="Completo y funcionando / pantalla rayada / no lo entregó..."></textarea>
                        <p v-if="errores.estado_devolucion" class="text-xs text-rose-600 mt-1">
                            {{ errores.estado_devolucion }}
                        </p>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalDevolucion = null" class="btn-secondary">Cancelar</button>
                    <button @click="guardarDevolucion"
                        :disabled="procesando || (exigeDetalle && !devolucion.estado_devolucion)"
                        class="btn-primary">Guardar</button>
                </div>
            </div>
        </div>

        <!-- Responder un aviso -->
        <div v-if="modalReporte" @click.self="modalReporte = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">{{ modalReporte.cosa }}</h3>
                <p class="text-sm text-surface-600 mt-1">
                    <strong>{{ modalReporte.persona }}:</strong> {{ modalReporte.detalle }}
                </p>
                <div class="mt-3">
                    <label class="text-xs font-semibold">Qué le respondemos</label>
                    <textarea v-model="respuesta" rows="3" class="input w-full" autofocus
                        placeholder="Ya se pidió el repuesto / pasá por la oficina a cambiarlo..."></textarea>
                    <p v-if="errores.respuesta" class="text-xs text-rose-600 mt-1">{{ errores.respuesta }}</p>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalReporte = null" class="btn-secondary">Cancelar</button>
                    <button @click="responder" :disabled="procesando || respuesta.trim().length < 5"
                        class="btn-primary">Responder</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
