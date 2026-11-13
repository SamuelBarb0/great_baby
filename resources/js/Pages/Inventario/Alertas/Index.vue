<script setup>
import { ref, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bell, Plus, Pencil, Trash2, Search, Upload } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import ImportMasivoModal from '@/Components/ImportMasivoModal.vue';
import { useEscClose } from '@/composables/useEscClose';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';

const importMasivoAbierto = ref(false);
const importMasivoUbicacion = ref(null);
const importMasivoOk = () => {
    importMasivoAbierto.value = false;
    router.reload({ only: ['configs'] });
};

const props = defineProps({
    alertas: { type: Object, required: true },
    ubicaciones: { type: Array, default: () => [] },
    can_importar_alertas: { type: Boolean, default: false },
});

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

const modal = ref(false);
useEscClose(modal);

/*
 * La alerta es de una variante O de un producto agregado, nunca de los dos.
 *
 * `producto_id` faltaba. El backend lo acepta desde INV-A5 —«soporta producto
 * agregado (producto_id) además de variante»— y el buscador los devuelve
 * primero, pero el formulario sólo guardaba la variante: elegir un agregado
 * dejaba el botón gris, sin mensaje.
 */
const form = reactive({
    id: null,
    variante_id: null,
    producto_id: null,
    variante_label: '',
    ubicacion_id: null,
    stock_minimo: 0,
    punto_reorden: null,
    cantidad_reorden: null,
    notificar_email: true,
    notificar_whatsapp: false,
    activa: true,
});
const errores = ref({});
const procesando = ref(false);

// Autocomplete variante
const busqueda = ref('');
const resultados = ref([]);
let debTimer;
const buscar = () => {
    clearTimeout(debTimer);
    if (busqueda.value.length < 2) { resultados.value = []; return; }
    debTimer = setTimeout(async () => {
        const r = await fetch(`/app/inventario/buscar-variantes?q=${encodeURIComponent(busqueda.value)}`);
        resultados.value = await r.json();
    }, 200);
};
const seleccionarVariante = (v) => {
    // El buscador devuelve uno de los dos: `id` para la variante,
    // `producto_id` para el agregado. El otro viene null, que es lo que el
    // servidor exige (XOR).
    form.variante_id = v.id ?? null;
    form.producto_id = v.producto_id ?? null;
    form.variante_label = v.label;
    busqueda.value = v.label;
    resultados.value = [];
};

const abrirCrear = () => {
    Object.assign(form, {
        id: null, variante_id: null, producto_id: null, variante_label: '', ubicacion_id: null,
        stock_minimo: 0, punto_reorden: null, cantidad_reorden: null,
        notificar_email: true, notificar_whatsapp: false, activa: true,
    });
    busqueda.value = '';
    errores.value = {};
    modal.value = true;
};
const abrirEditar = (a) => {
    Object.assign(form, {
        id: a.id, variante_id: a.variante_id, producto_id: a.producto_id ?? null,
        variante_label: `${a.producto} · ${a.sku}`,
        ubicacion_id: a.ubicacion_id, stock_minimo: a.minimo,
        punto_reorden: a.reorden || null, cantidad_reorden: a.cantidad_reorden || null,
        notificar_email: a.notificar_email, notificar_whatsapp: a.notificar_whatsapp, activa: a.activa,
    });
    busqueda.value = form.variante_label;
    errores.value = {};
    modal.value = true;
};

const guardar = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post('/app/inventario/alertas', form, {
        preserveScroll: true,
        onSuccess: () => { modal.value = false; },
        onError: (e) => { errores.value = e; },
        onFinish: () => (procesando.value = false),
    });
};

const eliminar = (a) => {
    modalConfirm.value = {
        titulo: `¿Eliminar la alerta de ${a.producto}?`,
        mensaje: `SKU ${a.sku}. Deja de avisarte cuando el stock baje del mínimo.`,
        color: 'rose',
        textoConfirmar: 'Eliminar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.delete(`/app/inventario/alertas/${a.id}`, { preserveScroll: true });
        },
    };
};
</script>

<template>
    <Head title="Alertas de stock"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <h1 class="text-2xl font-bold flex items-center gap-2"><Bell class="h-6 w-6 text-brand-600"/>Alertas de stock</h1>
                <div class="flex items-center gap-2">
                    <!-- FIX-S0 · gate: solo gerencia carga alertas masivas -->
                    <button v-if="can_importar_alertas" @click="importMasivoAbierto = true" class="text-sm inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-emerald-500 text-emerald-700 hover:bg-emerald-50 min-h-11"
                            title="Cargar alertas en lote desde Excel">
                        <Upload class="h-4 w-4"/> Importar Excel
                    </button>
                    <button @click="abrirCrear" class="btn-primary min-h-11"><Plus class="h-4 w-4"/> Nueva alerta</button>
                </div>
            </div>

            <ImportMasivoModal
                :abierto="importMasivoAbierto"
                endpoint="/app/inventario/alertas/importar"
                tipo="alerta"
                titulo="Importar alertas de stock"
                ayuda="Columna A: SKU/referencia · Columna B: stock mínimo · Columna C (opcional): stock máximo. Si eliges una ubicación abajo, se aplicará sólo a esa; vacío = alerta global."
                :extraPayload="{ ubicacion_id: importMasivoUbicacion }"
                @cerrar="importMasivoAbierto = false"
                @ok="importMasivoOk"
            />

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-3">SKU</th>
                            <th class="text-left p-3">Producto</th>
                            <th class="text-left p-3">Ubicación</th>
                            <th class="text-right p-3">Mínimo</th>
                            <th class="text-right p-3">Punto reorden</th>
                            <th class="text-right p-3">Cant. reorden</th>
                            <th class="text-center p-3">Activa</th>
                            <th class="text-center p-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-if="!alertas.data.length"><td colspan="8" class="p-6 text-center text-surface-500">Sin alertas configuradas. Crea una para recibir aviso cuando un SKU baje del mínimo.</td></tr>
                        <tr v-for="a in alertas.data" :key="a.id" class="hover:bg-surface-50">
                            <td class="p-3 font-mono text-xs">{{ a.sku }}</td>
                            <td class="p-3">
                                {{ a.producto }}
                                <!--
                                    Igual que lo rotula el panel de Filament: una
                                    alerta de producto agregado vale para todas
                                    sus presentaciones, y hay que poder saberlo
                                    sin abrirla.
                                -->
                                <span v-if="a.es_agregado"
                                    class="ml-1 px-1.5 py-0.5 rounded bg-sky-500/15 text-sky-800 text-[10px] font-bold">
                                    agregado
                                </span>
                            </td>
                            <td class="p-3 text-xs">{{ a.ubicacion }}</td>
                            <td class="p-3 text-right font-bold text-red-600">{{ a.minimo }}</td>
                            <td class="p-3 text-right font-bold text-amber-600">{{ a.reorden || '—' }}</td>
                            <td class="p-3 text-right">{{ a.cantidad_reorden || '—' }}</td>
                            <td class="p-3 text-center">
                                <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold', a.activa ? 'bg-emerald-100 text-emerald-800' : 'bg-surface-200 text-surface-500']">
                                    {{ a.activa ? 'ACTIVA' : 'INACTIVA' }}
                                </span>
                            </td>
                            <td class="p-3 text-center">
                                <div class="flex justify-center gap-1">
                                    <button @click="abrirEditar(a)" class="text-brand-600 hover:bg-brand-50 p-1 rounded"><Pencil class="h-4 w-4"/></button>
                                    <button @click="eliminar(a)" class="text-red-600 hover:bg-red-50 p-1 rounded"><Trash2 class="h-4 w-4"/></button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="alertas.links && alertas.links.length > 3" class="flex justify-center gap-1 flex-wrap">
                <Link v-for="l in alertas.links" :key="l.label" :href="l.url || '#'" preserve-scroll
                    v-html="l.label"
                    :class="['px-3 py-1 text-xs rounded', l.active ? 'bg-brand-600 text-white' : 'bg-surface-100 hover:bg-surface-200', !l.url && 'opacity-40 pointer-events-none']"/>
            </div>
        </div>

        <div v-if="modal" @click.self="modal = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold mb-3">{{ form.id ? 'Editar alerta' : 'Nueva alerta de stock' }}</h3>
                <div class="space-y-3">
                    <div class="relative">
                        <label class="text-xs font-semibold">Producto/SKU</label>
                        <div class="relative">
                            <input v-model="busqueda" @input="buscar" class="input w-full pr-8" placeholder="Buscar por nombre, referencia o código de barras" autocomplete="off" :disabled="!!form.id" />
                            <Search class="absolute right-2 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-400" />
                        </div>
                        <ul v-if="resultados.length" class="absolute z-10 mt-1 w-full max-h-60 overflow-auto border rounded-lg bg-white shadow-lg">
                            <li v-for="r in resultados" :key="r.id" @click="seleccionarVariante(r)" class="p-2 text-xs cursor-pointer hover:bg-brand-50 border-b last:border-0">
                                <div class="font-semibold">{{ r.producto }}</div>
                                <div class="text-surface-500">{{ r.detalle }} · <span class="font-mono">{{ r.sku }}</span></div>
                            </li>
                        </ul>
                        <p v-if="errores.variante_id" class="text-xs text-red-600 mt-1">{{ errores.variante_id }}</p>
                    </div>

                    <div>
                        <label class="text-xs font-semibold">Ubicación (opcional)</label>
                        <select v-model.number="form.ubicacion_id" class="input w-full">
                            <option :value="null">— Global (todas las bodegas) —</option>
                            <option v-for="u in ubicaciones" :key="u.id" :value="u.id">{{ u.codigo }} · {{ u.nombre }}</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="text-xs font-semibold">Mínimo</label>
                            <input type="number" min="0" v-model.number="form.stock_minimo" class="input w-full" />
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Punto reorden</label>
                            <input type="number" min="0" v-model.number="form.punto_reorden" class="input w-full" />
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Cant. reorden</label>
                            <input type="number" min="0" v-model.number="form.cantidad_reorden" class="input w-full" />
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-4 text-xs">
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.notificar_email" /> Notificar por email</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.notificar_whatsapp" /> Notificar por WhatsApp</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.activa" /> Activa</label>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modal = false" class="btn-ghost min-h-11">Cancelar</button>
                    <button @click="guardar" :disabled="procesando || !(form.variante_id || form.producto_id)" class="btn-primary min-h-11">
                        {{ procesando ? 'Guardando…' : (form.id ? 'Actualizar' : 'Crear') }}
                    </button>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
