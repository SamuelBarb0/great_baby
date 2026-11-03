<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Check, CircleDot, Circle, Container, Pencil, Plus, ShieldAlert, Trash2, FileCheck } from 'lucide-vue-next';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { useEscClose } from '@/composables/useEscClose';
import { fechaCorta } from '@/composables/useFecha';

/*
 * Armar y seguir el contenedor: qué órdenes lleva, por qué etapa va, qué le
 * salió a cada declaración en la selectividad y qué novedades ha tenido.
 */
const props = defineProps({
    importacion: { type: Object, required: true },
    seguimiento: { type: Object, required: true },
    puedeEditar: Boolean,
    selectividades: { type: Object, default: () => ({}) },
    tiposInspeccion: { type: Object, default: () => ({}) },
});

const { hoy } = useBogotaDate();
const base = computed(() => `/app/compras/importacion/${props.importacion.id}`);
const cerrado = computed(() => ['liquidada', 'cerrada'].includes(props.importacion.estado));
const confirmar = ref(null);
const enviando = ref(false);

const opciones = { preserveScroll: true, onFinish: () => { enviando.value = false; } };
const enviar = (metodo, url, datos = {}, extra = {}) => {
    if (enviando.value) return;
    enviando.value = true;
    router[metodo](url, datos, { ...opciones, ...extra });
};
const pedirConfirmacion = (titulo, mensaje, textoConfirmar, accion) => {
    confirmar.value = {
        titulo, mensaje, textoConfirmar, color: 'rose',
        onConfirmar: () => { confirmar.value = null; accion(); },
    };
};

// Órdenes
const ordenElegida = ref('');
const ligarOrden = () => {
    if (!ordenElegida.value) return;
    enviar('post', `${base.value}/orden`, { orden_id: ordenElegida.value }, { onSuccess: () => { ordenElegida.value = ''; } });
};
const soltarOrden = (o) => pedirConfirmacion(
    `¿Quitar la orden ${o.numero}?`,
    'La orden queda libre para otro contenedor. No se borra.',
    'Quitar',
    () => enviar('delete', `${base.value}/orden/${o.id}`),
);
const ocupacion = computed(() => {
    const { volumen_total: volumen, capacidad_m3: capacidad } = props.seguimiento;
    return capacidad > 0 ? Math.round((volumen / capacidad) * 100) : 0;
});
const fmt = (n, d = 2) => Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: d });

// Etapas
const etapaAbierta = ref(null);
const etapaForm = reactive({ fecha: '', notas: '' });
const abrirEtapa = (e) => {
    etapaAbierta.value = e.etapa;
    etapaForm.fecha = e.fecha || hoy();
    etapaForm.notas = e.notas || '';
};
const marcarEtapa = (e) => enviar('post', `${base.value}/etapa`, { etapa: e.etapa, ...etapaForm }, {
    onSuccess: () => { etapaAbierta.value = null; },
});
const quitarEtapa = (e) => pedirConfirmacion(
    `¿Quitar «${e.label}»?`,
    'Úselo sólo para corregir un error: el contenedor vuelve a la etapa anterior.',
    'Quitar',
    () => enviar('delete', `${base.value}/etapa/${e.etapa}`, {}, { onSuccess: () => { etapaAbierta.value = null; } }),
);
const dias = (n) => `${n} ${n === 1 ? 'día' : 'días'}`;
const estadoEtapa = (e) => {
    if (e.etapa === props.seguimiento.resumen.etapa) return 'actual';
    if (e.fecha) return 'hecha';
    return 'pendiente';
};

// Declaraciones
const declaracionForm = reactive({ id: null, numero: '', selectividad: 'pendiente', fecha_cita: '', fecha_levante: '', notas: '' });
const formDeclaracion = ref(false);
const nuevaDeclaracion = () => {
    Object.assign(declaracionForm, { id: null, numero: '', selectividad: 'pendiente', fecha_cita: '', fecha_levante: '', notas: '' });
    formDeclaracion.value = true;
};
const editarDeclaracion = (d) => {
    Object.assign(declaracionForm, { id: d.id, numero: d.numero || '', selectividad: d.selectividad, fecha_cita: d.fecha_cita || '', fecha_levante: d.fecha_levante || '', notas: d.notas || '' });
    formDeclaracion.value = true;
};
const guardarDeclaracion = () => {
    const { id, ...datos } = declaracionForm;
    const fin = { onSuccess: () => { formDeclaracion.value = false; } };
    if (id) enviar('put', `${base.value}/declaracion/${id}`, datos, fin);
    else enviar('post', `${base.value}/declaracion`, datos, fin);
};
const quitarDeclaracion = (d) => pedirConfirmacion(
    `¿Quitar la declaración ${d.numero || ''}?`,
    'Se pierde lo que le salió en la selectividad.',
    'Quitar',
    () => enviar('delete', `${base.value}/declaracion/${d.id}`),
);
const listasParaLevante = computed(() => {
    const { declaraciones, etapas } = props.seguimiento;
    const levante = etapas.find((e) => e.etapa === 'levante');
    return declaraciones.length > 0 && declaraciones.every((d) => d.fecha_levante) && !levante?.fecha;
});
const conInspeccion = computed(() => declaracionForm.selectividad === 'fisica' || declaracionForm.selectividad === 'documental');
const colorSelectividad = (s) => ({
    pendiente: 'bg-surface-200 text-surface-700 dark:bg-surface-800 dark:text-surface-300',
    automatica: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    fisica: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    documental: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
}[s]);

// Inspecciones
const inspeccionForm = reactive({ tipo: 'forzosa_puerto', fecha: '', notas: '' });
const formInspeccion = ref(false);
const nuevaInspeccion = () => {
    Object.assign(inspeccionForm, { tipo: 'forzosa_puerto', fecha: hoy(), notas: '' });
    formInspeccion.value = true;
};
const registrarInspeccion = () => enviar('post', `${base.value}/inspeccion`, { ...inspeccionForm }, {
    onSuccess: () => { formInspeccion.value = false; },
});
const resolverInspeccion = (i) => enviar('post', `${base.value}/inspeccion/${i.id}/resolver`);

// Datos del contenedor
const modalDatos = ref(false);
useEscClose(modalDatos);
const datosForm = reactive({});
const camposDatos = ['contenedor', 'bl_awb', 'proveedor_pais', 'puerto_origen', 'puerto_destino', 'incoterm', 'tasa_cambio_liquidacion', 'eta'];
const abrirDatos = () => {
    for (const c of camposDatos) datosForm[c] = props.importacion[c] ?? '';
    modalDatos.value = true;
};
const guardarDatos = () => enviar('put', `${base.value}/datos`, { ...datosForm }, { onSuccess: () => { modalDatos.value = false; } });

watch(() => props.importacion.id, () => { etapaAbierta.value = null; });
</script>

<template>
    <div class="space-y-4">
        <div v-if="seguimiento.resumen.dias_atraso > 0" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-sm text-red-800 dark:text-red-200 flex items-start gap-2">
            <AlertTriangle class="h-4 w-4 mt-0.5 flex-shrink-0"/>
            <div>
                <b>{{ seguimiento.resumen.dias_atraso }} día(s) de atraso.</b>
                Lleva {{ seguimiento.resumen.dias_en_etapa }} días en «{{ seguimiento.resumen.etapa_label }}» y lo normal son {{ seguimiento.resumen.esperados }} para «{{ seguimiento.resumen.siguiente_label }}».
            </div>
        </div>

        <!-- Órdenes del contenedor -->
        <div class="card p-4 space-y-3">
            <div class="flex items-center justify-between gap-2 flex-wrap">
                <div class="text-xs uppercase font-bold text-brand-600 flex items-center gap-1"><Container class="h-3.5 w-3.5"/>Órdenes del contenedor ({{ seguimiento.ordenes.length }})</div>
                <button v-if="puedeEditar && !cerrado" @click="abrirDatos" class="btn-ghost text-xs"><Pencil class="h-3 w-3"/> Editar datos del contenedor</button>
            </div>

            <div v-if="seguimiento.ordenes.length">
                <div class="flex justify-between text-xs mb-1">
                    <span :class="ocupacion > 100 ? 'text-red-600 font-semibold' : 'text-surface-500'">{{ fmt(seguimiento.volumen_total) }} m³ de {{ seguimiento.capacidad_m3 }} m³</span>
                    <span :class="ocupacion > 100 ? 'text-red-600 font-semibold' : 'text-surface-500'">{{ ocupacion }} %</span>
                </div>
                <div class="h-2 rounded bg-surface-200 dark:bg-surface-800 overflow-hidden">
                    <div class="h-full" :class="ocupacion > 100 ? 'bg-red-500' : 'bg-emerald-500'" :style="{ width: Math.min(ocupacion, 100) + '%' }"></div>
                </div>
                <p v-if="ocupacion > 100" class="text-xs text-red-600 mt-1">Se pasa de la capacidad del contenedor.</p>
            </div>

            <div v-if="seguimiento.ordenes.length" class="overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] text-surface-500 uppercase border-b border-surface-200 dark:border-surface-800">
                        <tr>
                            <th class="text-left p-2">Orden</th>
                            <th class="text-left p-2">Proveedor</th>
                            <th class="text-right p-2">Cajas</th>
                            <th class="text-right p-2">m³</th>
                            <th class="text-right p-2">Peso kg</th>
                            <th class="text-right p-2">Valor</th>
                            <th class="text-left p-2">Recibido</th>
                            <th class="text-left p-2">Destino</th>
                            <th v-if="puedeEditar && !cerrado" class="p-2"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                        <tr v-for="o in seguimiento.ordenes" :key="o.id">
                            <td class="p-2 font-mono text-xs font-bold"><a :href="`/app/compras/oc/${o.id}`" class="text-brand-600 hover:underline">{{ o.numero }}</a></td>
                            <td class="p-2">{{ o.proveedor || '—' }}</td>
                            <td class="p-2 text-right">{{ fmt(o.cajas, 0) }}</td>
                            <td class="p-2 text-right">{{ fmt(o.volumen_m3) }}</td>
                            <td class="p-2 text-right">{{ fmt(o.peso_kg) }}</td>
                            <td class="p-2 text-right">{{ fmt(o.subtotal) }} {{ o.moneda }}</td>
                            <td class="p-2 text-xs">
                                {{ fmt(o.recibido, 0) }} de {{ fmt(o.cantidad, 0) }}
                                <a v-if="o.recibido < o.cantidad" :href="`/app/compras/recepcion/nueva?oc=${o.id}`" class="block text-brand-600 hover:underline">Recibir</a>
                            </td>
                            <td class="p-2 text-xs"><div v-for="d in o.destinos" :key="d.nombre">{{ d.nombre }}: {{ fmt(d.cajas, 0) }} cajas</div></td>
                            <td v-if="puedeEditar && !cerrado" class="p-2 text-right">
                                <button @click="soltarOrden(o)" class="text-red-600 hover:text-red-700" :aria-label="`Quitar la orden ${o.numero}`"><Trash2 class="h-3.5 w-3.5"/></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-sm text-surface-500">Sin órdenes todavía. Agregue las órdenes aprobadas que viajan en este contenedor: sin ellas no se puede liquidar.</p>

            <div v-if="puedeEditar && !cerrado" class="flex gap-2 items-end flex-wrap">
                <div class="flex-1 min-w-[16rem]">
                    <label class="text-xs font-semibold" for="orden-elegida">Agregar orden</label>
                    <select id="orden-elegida" v-model="ordenElegida" class="input w-full">
                        <option value="">{{ seguimiento.ordenes_disponibles.length ? 'Elija una orden aprobada…' : 'No hay órdenes aprobadas libres' }}</option>
                        <option v-for="o in seguimiento.ordenes_disponibles" :key="o.id" :value="o.id">{{ o.numero }} · {{ o.proveedor || 'Sin proveedor' }} · {{ o.moneda }}</option>
                    </select>
                </div>
                <button @click="ligarOrden" :disabled="!ordenElegida || enviando" class="btn-primary text-sm disabled:opacity-50"><Plus class="h-4 w-4"/> Agregar</button>
            </div>
        </div>

        <!-- Línea de tiempo -->
        <div class="card p-4">
            <div class="flex items-start justify-between gap-2 flex-wrap mb-3">
                <div class="text-xs uppercase font-bold text-brand-600">Línea de tiempo</div>
                <div v-if="seguimiento.resumen.llegada_estimada" class="text-xs text-right">
                    <span class="text-surface-500">Llegada estimada a bodega: </span><b>{{ fechaCorta(seguimiento.resumen.llegada_estimada) }}</b>
                </div>
            </div>
            <ol>
                <li v-for="e in seguimiento.etapas" :key="e.etapa" class="relative pl-7 pb-3 last:pb-0">
                    <span class="absolute left-[7px] top-5 bottom-0 w-px bg-surface-200 dark:bg-surface-800" aria-hidden="true"></span>
                    <component :is="estadoEtapa(e) === 'hecha' ? Check : estadoEtapa(e) === 'actual' ? CircleDot : Circle"
                               class="absolute left-0 top-0.5 h-4 w-4"
                               :class="estadoEtapa(e) === 'hecha' ? 'text-emerald-600' : estadoEtapa(e) === 'actual' ? 'text-brand-600' : 'text-surface-400'"/>
                    <div class="flex items-baseline justify-between gap-2 flex-wrap">
                        <div class="text-sm" :class="estadoEtapa(e) === 'pendiente' ? 'text-surface-500' : 'font-semibold'">{{ e.label }}</div>
                        <div class="flex items-baseline gap-3 text-xs text-surface-500">
                            <span v-if="e.fecha">{{ fechaCorta(e.fecha) }}</span>
                            <span v-if="e.dias !== null" :class="e.esperados !== null && e.dias > e.esperados ? 'text-red-600 font-semibold' : ''">{{ dias(e.dias) }}</span>
                            <span v-if="e.muestras >= 3">prom. {{ e.promedio }}</span>
                            <span v-else-if="e.esperados !== null && !e.fecha">normal {{ dias(e.esperados) }}</span>
                            <button v-if="puedeEditar" @click="etapaAbierta === e.etapa ? etapaAbierta = null : abrirEtapa(e)" class="text-brand-600 hover:underline">{{ e.fecha ? 'Corregir' : 'Marcar' }}</button>
                        </div>
                    </div>
                    <p v-if="e.notas" class="text-xs italic text-surface-500 mt-0.5">{{ e.notas }}</p>
                    <div v-if="etapaAbierta === e.etapa" class="mt-2 grid grid-cols-1 md:grid-cols-[10rem_1fr_auto] gap-2 items-end">
                        <div><label class="text-xs font-semibold">Fecha</label><input type="date" v-model="etapaForm.fecha" :max="hoy()" class="input w-full"/></div>
                        <div><label class="text-xs font-semibold">Nota (opcional)</label><input v-model="etapaForm.notas" maxlength="1000" class="input w-full"/></div>
                        <div class="flex gap-2">
                            <button @click="marcarEtapa(e)" :disabled="!etapaForm.fecha || enviando" class="btn-primary text-sm disabled:opacity-50">Guardar</button>
                            <button v-if="e.quitable" @click="quitarEtapa(e)" class="btn-ghost text-sm text-red-600">Quitar</button>
                        </div>
                    </div>
                </li>
            </ol>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <!-- Declaraciones -->
            <div class="card p-4 space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <div class="text-xs uppercase font-bold text-brand-600 flex items-center gap-1"><FileCheck class="h-3.5 w-3.5"/>Declaraciones ({{ seguimiento.declaraciones.length }})</div>
                    <button v-if="puedeEditar" @click="nuevaDeclaracion" class="btn-ghost text-xs"><Plus class="h-3 w-3"/> Agregar</button>
                </div>
                <div v-if="seguimiento.declaraciones.length" class="divide-y divide-surface-100 dark:divide-surface-800">
                    <div v-for="d in seguimiento.declaraciones" :key="d.id" class="py-2 text-sm">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <span class="font-mono text-xs font-bold">{{ d.numero || 'Sin número' }}</span>
                            <span class="px-2 py-0.5 rounded text-[10px] uppercase font-semibold" :class="colorSelectividad(d.selectividad)">{{ selectividades[d.selectividad] }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-2 text-xs text-surface-500 mt-1 flex-wrap">
                            <span>
                                <template v-if="d.fecha_cita">Cita {{ fechaCorta(d.fecha_cita) }} · </template>
                                {{ d.fecha_levante ? `Levante ${fechaCorta(d.fecha_levante)}` : 'Sin levante' }}
                            </span>
                            <span v-if="puedeEditar" class="flex gap-2">
                                <button @click="editarDeclaracion(d)" class="text-brand-600 hover:underline">Editar</button>
                                <button @click="quitarDeclaracion(d)" class="text-red-600 hover:underline">Quitar</button>
                            </span>
                        </div>
                        <p v-if="d.notas" class="text-xs italic text-surface-500 mt-0.5">{{ d.notas }}</p>
                    </div>
                </div>
                <p v-else class="text-sm text-surface-500">Cuando la aduana genere las declaraciones, registre qué le salió a cada una.</p>
                <p v-if="listasParaLevante" class="text-xs text-emerald-700 dark:text-emerald-300">Todas tienen levante. Si ya no faltan declaraciones, marque «Levante» en la línea de tiempo.</p>

                <div v-if="formDeclaracion" class="border-t border-surface-200 dark:border-surface-800 pt-3 grid grid-cols-2 gap-2">
                    <div><label class="text-xs font-semibold">Número</label><input v-model="declaracionForm.numero" maxlength="40" class="input w-full"/></div>
                    <div><label class="text-xs font-semibold">Selectividad</label>
                        <select v-model="declaracionForm.selectividad" class="input w-full">
                            <option v-for="(label, clave) in selectividades" :key="clave" :value="clave">{{ label }}</option>
                        </select>
                    </div>
                    <div v-if="conInspeccion"><label class="text-xs font-semibold">Cita con el inspector</label><input type="date" v-model="declaracionForm.fecha_cita" class="input w-full"/></div>
                    <div><label class="text-xs font-semibold">Levante</label><input type="date" v-model="declaracionForm.fecha_levante" class="input w-full"/></div>
                    <div class="col-span-2"><label class="text-xs font-semibold">Nota (opcional)</label><input v-model="declaracionForm.notas" maxlength="1000" class="input w-full"/></div>
                    <div class="col-span-2 flex justify-end gap-2">
                        <button @click="formDeclaracion = false" class="btn-ghost text-sm">Cancelar</button>
                        <button @click="guardarDeclaracion" :disabled="enviando" class="btn-primary text-sm">Guardar</button>
                    </div>
                </div>
            </div>

            <!-- Inspecciones y novedades -->
            <div class="card p-4 space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <div class="text-xs uppercase font-bold text-brand-600 flex items-center gap-1"><ShieldAlert class="h-3.5 w-3.5"/>Inspecciones y novedades ({{ seguimiento.inspecciones.length }})</div>
                    <button v-if="puedeEditar" @click="nuevaInspeccion" class="btn-ghost text-xs"><Plus class="h-3 w-3"/> Marcar</button>
                </div>
                <div v-if="seguimiento.inspecciones.length" class="divide-y divide-surface-100 dark:divide-surface-800">
                    <div v-for="i in seguimiento.inspecciones" :key="i.id" class="py-2 text-sm">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <span class="font-semibold">{{ tiposInspeccion[i.tipo] }}</span>
                            <span v-if="i.resuelta_en" class="text-xs text-emerald-600">Resuelta {{ fechaCorta(i.resuelta_en) }}</span>
                            <button v-else-if="puedeEditar" @click="resolverInspeccion(i)" :disabled="enviando" class="text-xs text-brand-600 hover:underline">Marcar resuelta</button>
                            <span v-else class="text-xs text-amber-600">Abierta</span>
                        </div>
                        <div class="text-xs text-surface-500 mt-0.5">{{ fechaCorta(i.fecha) }}<template v-if="i.registrado_por"> · {{ i.registrado_por }}</template></div>
                        <p v-if="i.notas" class="text-xs italic text-surface-500 mt-0.5">{{ i.notas }}</p>
                    </div>
                </div>
                <p v-else class="text-sm text-surface-500">Si van a abrir el contenedor, márquelo aquí: Compras recibe el aviso en la campana.</p>

                <div v-if="formInspeccion" class="border-t border-surface-200 dark:border-surface-800 pt-3 grid grid-cols-2 gap-2">
                    <div><label class="text-xs font-semibold">Tipo</label>
                        <select v-model="inspeccionForm.tipo" class="input w-full">
                            <option v-for="(label, clave) in tiposInspeccion" :key="clave" :value="clave">{{ label }}</option>
                        </select>
                    </div>
                    <div><label class="text-xs font-semibold">Fecha</label><input type="date" v-model="inspeccionForm.fecha" class="input w-full"/></div>
                    <div class="col-span-2"><label class="text-xs font-semibold">Nota (opcional)</label><input v-model="inspeccionForm.notas" maxlength="1000" class="input w-full"/></div>
                    <div class="col-span-2 flex justify-end gap-2">
                        <button @click="formInspeccion = false" class="btn-ghost text-sm">Cancelar</button>
                        <button @click="registrarInspeccion" :disabled="!inspeccionForm.fecha || enviando" class="btn-primary text-sm">Guardar y avisar</button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="modalDatos" @click.self="modalDatos = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold mb-3">Datos del contenedor</h3>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="text-xs font-semibold">Número de contenedor</label><input v-model="datosForm.contenedor" maxlength="30" class="input w-full"/></div>
                    <div><label class="text-xs font-semibold">BL / AWB</label><input v-model="datosForm.bl_awb" maxlength="60" class="input w-full"/></div>
                    <div><label class="text-xs font-semibold">País de origen</label><input v-model="datosForm.proveedor_pais" maxlength="60" class="input w-full"/></div>
                    <div><label class="text-xs font-semibold">Incoterm</label><input v-model="datosForm.incoterm" maxlength="10" class="input w-full"/></div>
                    <div><label class="text-xs font-semibold">Puerto de origen</label><input v-model="datosForm.puerto_origen" maxlength="60" class="input w-full"/></div>
                    <div><label class="text-xs font-semibold">Puerto de destino</label><input v-model="datosForm.puerto_destino" maxlength="60" class="input w-full"/></div>
                    <div><label class="text-xs font-semibold">Tasa de cambio</label><input type="number" step="0.000001" min="0" v-model="datosForm.tasa_cambio_liquidacion" class="input w-full"/></div>
                    <div><label class="text-xs font-semibold">ETA</label><input type="date" v-model="datosForm.eta" class="input w-full"/></div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalDatos = false" class="btn-ghost">Cancelar</button>
                    <button @click="guardarDatos" :disabled="enviando" class="btn-primary">Guardar</button>
                </div>
            </div>
        </div>

        <AppConfirmModal :cfg="confirmar" @cerrar="confirmar = null"/>
    </div>
</template>
