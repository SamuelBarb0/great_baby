<script setup>
/**
 * Registrar lo que un cliente devolvió.
 *
 * Dos pasos, y el orden importa: primero se guarda la cabecera —de quién es y
 * en qué bodega se recibe—, porque de esa bodega sale a qué costo entra cada
 * línea. Después se arman las líneas y se confirma.
 *
 * Mientras está en borrador no se tocó el inventario. Al confirmar, la
 * mercancía entra al almacén de devoluciones y ya no se edita: el kardex es
 * append-only y una corrección se hace con otro movimiento, no borrando éste.
 */
import { ref, computed, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Package, Search, Trash2, Check, ClipboardCheck } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    devolucion: { type: Object, default: null },
    bodegas: { type: Array, required: true },
});

const esNueva = computed(() => ! props.devolucion);
const esBorrador = computed(() => ! props.devolucion || props.devolucion.estado === 'borrador');

const hoy = new Date().toISOString().slice(0, 10);

const form = useForm({
    id: props.devolucion?.id ?? null,
    contacto_id: props.devolucion?.contacto_id ?? null,
    pedido_id: props.devolucion?.pedido_id ?? null,
    factura_id: props.devolucion?.factura_id ?? null,
    ubicacion_origen_id: props.devolucion?.ubicacion_origen_id ?? (props.bodegas[0]?.id ?? null),
    fecha: props.devolucion?.fecha ?? hoy,
    motivo: props.devolucion?.motivo ?? '',
});

// ── Cliente ────────────────────────────────────────────────────────────────
const buscaCliente = ref('');
const clientes = ref([]);
const clienteElegido = ref(props.devolucion?.cliente ?? '');

const buscarCliente = async () => {
    const r = await fetch(`/app/logistica/devoluciones-cliente/clientes?q=${encodeURIComponent(buscaCliente.value)}`);
    clientes.value = r.ok ? await r.json() : [];
};

const elegirCliente = (c) => {
    form.contacto_id = c.id;
    clienteElegido.value = c.etiqueta;
    clientes.value = [];
    cargarPedidos();
};

// ── Pedido de origen ───────────────────────────────────────────────────────
const pedidos = ref([]);

const cargarPedidos = async () => {
    if (! form.contacto_id) return;
    const r = await fetch(`/app/logistica/devoluciones-cliente/pedidos?contacto_id=${form.contacto_id}`);
    pedidos.value = r.ok ? await r.json() : [];
};

// Elegir el pedido trae su factura: es la que después necesita contabilidad
// para la nota crédito, y pedirla dos veces es pedir que no coincidan.
watch(() => form.pedido_id, (id) => {
    const p = pedidos.value.find((x) => x.id === Number(id));
    if (p) form.factura_id = p.factura_id;
});

if (props.devolucion?.contacto_id) cargarPedidos();

const guardar = () => form.post('/app/logistica/devoluciones-cliente', { preserveScroll: true });

// ── Líneas ─────────────────────────────────────────────────────────────────
const busca = ref('');
const resultados = ref([]);
const buscando = ref(false);

const buscarProducto = async () => {
    if (busca.value.trim().length < 2) { resultados.value = []; return; }
    buscando.value = true;
    try {
        const r = await fetch(`/app/catalogo/productos-buscar?q=${encodeURIComponent(busca.value)}&variantes=1`);
        resultados.value = r.ok ? await r.json() : [];
    } finally {
        buscando.value = false;
    }
};

const linea = useForm({
    variante_id: null, producto_id: null, cantidad: 1, costo_unit: null, motivo_item: '',
});

const elegido = ref(null);

const elegirProducto = (p) => {
    elegido.value = p;
    linea.variante_id = p.variante_id ?? null;
    linea.producto_id = p.variante_id ? null : p.id;
    resultados.value = [];
    busca.value = '';
};

const agregar = () => {
    if (! elegido.value) return;
    linea.post(`/app/logistica/devoluciones-cliente/${props.devolucion.id}/items`, {
        preserveScroll: true,
        onSuccess: () => { linea.reset(); elegido.value = null; },
    });
};

const quitar = (itemId) => router.delete(
    `/app/logistica/devoluciones-cliente/${props.devolucion.id}/items/${itemId}`,
    { preserveScroll: true }
);

// ── Cerrar el documento ────────────────────────────────────────────────────
const confirmar = () => router.post(
    `/app/logistica/devoluciones-cliente/${props.devolucion.id}/confirmar`, {}, { preserveScroll: true }
);

const marcarRevisada = () => router.post(
    `/app/logistica/devoluciones-cliente/${props.devolucion.id}/revisada`, {}, { preserveScroll: true }
);

const money = (n) => n === null || n === undefined
    ? '—'
    : new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n);

const totalUnidades = computed(() =>
    (props.devolucion?.items ?? []).reduce((a, i) => a + (i.cantidad || 0), 0));

// Una línea sin costo deja el promedio de la bodega de devoluciones en cero, y
// ese cero viaja al almacén de destino cuando la mercancía se traslade.
const sinCosto = computed(() =>
    (props.devolucion?.items ?? []).filter((i) => i.costo_unit === null).length);
</script>

<template>
    <Head :title="esNueva ? 'Registrar devolución' : `Devolución ${devolucion.numero}`"/>
    <AppLayout>
        <div class="p-4 md:p-6 space-y-4 max-w-5xl mx-auto">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-xl font-bold flex items-center gap-2">
                        <Package class="h-5 w-5 text-brand-600"/>
                        {{ esNueva ? 'Registrar devolución de cliente' : devolucion.numero }}
                    </h1>
                    <p v-if="! esNueva" class="text-sm text-surface-500">
                        <template v-if="devolucion.estado === 'borrador'">
                            Borrador · el inventario todavía no se tocó.
                        </template>
                        <template v-else-if="devolucion.revisada_at">
                            Revisada el {{ devolucion.revisada_at }} por {{ devolucion.revisada_por }}.
                        </template>
                        <template v-else>
                            La mercancía entró a <b class="font-mono">{{ devolucion.destino }}</b> y está por revisar.
                        </template>
                    </p>
                </div>
                <Link href="/app/logistica/devoluciones-cliente" class="btn-ghost text-sm">Volver</Link>
            </div>

            <div v-if="form.errors.confirmar || $page.props.errors?.confirmar"
                 class="p-3 rounded-lg bg-red-50 border-l-4 border-red-500 text-red-800 text-sm">
                {{ form.errors.confirmar || $page.props.errors.confirmar }}
            </div>

            <!--
                Sin bodegas no hay dónde recibir, y hay que decirlo.
                Pasa cuando alguien entra con permiso de logística pero no
                responde por ninguna bodega de venta: el selector salía vacío
                y la pantalla no explicaba por qué no se podía guardar.
            -->
            <div v-if="! bodegas.length"
                 class="p-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 border-l-4 border-amber-500 text-amber-900 dark:text-amber-200 text-sm">
                <b>No tenés ninguna bodega a cargo donde recibir.</b>
                Una devolución entra contra la bodega de la que salió la mercancía, y acá sólo
                aparecen las que vos operás. Pedile a Gerencia que te asigne una, o que registre
                la devolución quien responda por esa bodega.
            </div>

            <!-- Cabecera -->
            <div v-else class="card p-5 space-y-4">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Quién devuelve</div>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-semibold">Cliente *</label>
                        <div v-if="clienteElegido && ! esBorrador" class="input bg-surface-100">{{ clienteElegido }}</div>
                        <template v-else>
                            <div class="flex gap-2">
                                <input v-model="buscaCliente" @keyup.enter="buscarCliente"
                                       class="input w-full" placeholder="Nombre o documento"/>
                                <button type="button" @click="buscarCliente" class="btn-ghost px-3">
                                    <Search class="h-4 w-4"/>
                                </button>
                            </div>
                            <div v-if="clienteElegido" class="text-xs text-emerald-700 mt-1">✓ {{ clienteElegido }}</div>
                            <ul v-if="clientes.length" class="mt-1 border rounded-lg divide-y max-h-40 overflow-auto">
                                <li v-for="c in clientes" :key="c.id" @click="elegirCliente(c)"
                                    class="px-2 py-1.5 text-sm hover:bg-surface-100 cursor-pointer">
                                    {{ c.etiqueta }}
                                </li>
                            </ul>
                            <div v-if="form.errors.contacto_id" class="text-xs text-red-600 mt-1">{{ form.errors.contacto_id }}</div>
                        </template>
                    </div>

                    <div>
                        <label class="text-xs font-semibold">Bodega donde se recibe *</label>
                        <select v-model="form.ubicacion_origen_id" :disabled="! esBorrador" class="input w-full">
                            <option v-for="b in bodegas" :key="b.id" :value="b.id">{{ b.etiqueta }}</option>
                        </select>
                        <!-- De acá sale a qué costo entra cada línea, y de acá
                             hereda la bodega de devoluciones su responsable. -->
                        <p class="text-[11px] text-surface-500 mt-1">
                            La devolución entra al almacén de devoluciones de esta sede.
                        </p>
                        <div v-if="form.errors.ubicacion_origen_id" class="text-xs text-red-600 mt-1">{{ form.errors.ubicacion_origen_id }}</div>
                    </div>

                    <div>
                        <label class="text-xs font-semibold">Pedido de origen</label>
                        <select v-model="form.pedido_id" :disabled="! esBorrador" class="input w-full">
                            <option :value="null">— sin pedido —</option>
                            <option v-for="p in pedidos" :key="p.id" :value="p.id">
                                {{ p.numero }} · {{ p.fecha }}{{ p.factura ? ` · ${p.factura}` : '' }}
                            </option>
                        </select>
                        <p class="text-[11px] text-surface-500 mt-1">
                            Opcional, pero es lo que después necesita contabilidad para la nota crédito.
                        </p>
                    </div>

                    <div>
                        <label class="text-xs font-semibold">Fecha *</label>
                        <input v-model="form.fecha" type="date" :disabled="! esBorrador" class="input w-full"/>
                        <div v-if="form.errors.fecha" class="text-xs text-red-600 mt-1">{{ form.errors.fecha }}</div>
                    </div>
                </div>

                <div>
                    <label class="text-xs font-semibold">Motivo *</label>
                    <input v-model="form.motivo" :disabled="! esBorrador" maxlength="500"
                           class="input w-full" placeholder="Por qué la devolvió el cliente"/>
                    <div v-if="form.errors.motivo" class="text-xs text-red-600 mt-1">{{ form.errors.motivo }}</div>
                </div>

                <div v-if="esBorrador" class="flex justify-end">
                    <button @click="guardar" :disabled="form.processing" class="btn-primary">
                        {{ esNueva ? 'Guardar y seguir' : 'Guardar cambios' }}
                    </button>
                </div>
            </div>

            <!-- Líneas -->
            <div v-if="! esNueva" class="card p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600">
                        Qué devolvió ({{ devolucion.items.length }})
                    </div>
                    <div class="text-sm"><b>{{ totalUnidades }}</b> unidades</div>
                </div>

                <div v-if="esBorrador" class="rounded-lg border border-surface-200 dark:border-surface-700 p-3 space-y-2">
                    <div class="flex gap-2">
                        <input v-model="busca" @keyup.enter="buscarProducto" class="input w-full"
                               placeholder="Buscar por referencia, nombre o código de barras"/>
                        <button type="button" @click="buscarProducto" :disabled="buscando" class="btn-ghost px-3">
                            <Search class="h-4 w-4"/>
                        </button>
                    </div>

                    <ul v-if="resultados.length" class="border rounded-lg divide-y max-h-48 overflow-auto">
                        <li v-for="(p, i) in resultados" :key="i" @click="elegirProducto(p)"
                            class="px-2 py-1.5 text-sm hover:bg-surface-100 cursor-pointer flex justify-between gap-2">
                            <span><b class="font-mono text-xs">{{ p.referencia }}</b> · {{ p.nombre }}</span>
                            <span v-if="p.variante_id" class="text-xs text-surface-500">variante</span>
                        </li>
                    </ul>

                    <div v-if="elegido" class="grid sm:grid-cols-4 gap-2 items-end">
                        <div class="sm:col-span-2">
                            <div class="text-xs font-semibold">{{ elegido.referencia }}</div>
                            <div class="text-xs text-surface-600">{{ elegido.nombre }}</div>
                        </div>
                        <div>
                            <label class="text-[11px] font-semibold">Cantidad</label>
                            <input v-model.number="linea.cantidad" type="number" min="1" class="input w-full"/>
                        </div>
                        <div>
                            <label class="text-[11px] font-semibold">Costo unit.</label>
                            <input v-model.number="linea.costo_unit" type="number" min="0" step="0.01"
                                   class="input w-full" placeholder="auto"/>
                        </div>
                        <div class="sm:col-span-3">
                            <input v-model="linea.motivo_item" maxlength="200" class="input w-full"
                                   placeholder="Qué le pasa a esta línea (opcional)"/>
                        </div>
                        <button @click="agregar" :disabled="linea.processing" class="btn-primary">Agregar</button>
                    </div>
                    <p v-if="elegido" class="text-[11px] text-surface-500">
                        Si dejás el costo vacío, se usa el promedio de la bodega de la que salió.
                    </p>
                </div>

                <table v-if="devolucion.items.length" v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs uppercase text-surface-500 border-b">
                        <tr>
                            <th class="text-left p-2">Producto</th>
                            <th class="text-center p-2">Cant.</th>
                            <th class="text-right p-2">Costo unit.</th>
                            <th class="text-left p-2">Motivo</th>
                            <th class="p-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                        <tr v-for="it in devolucion.items" :key="it.id">
                            <td class="p-2">
                                <div class="font-medium">{{ it.descripcion }}</div>
                                <div class="text-xs text-surface-500 font-mono">
                                    {{ it.sku }}
                                    <template v-if="it.color || it.talla"> · {{ it.color }} {{ it.talla }}</template>
                                    <span v-if="it.es_agregado" class="italic"> · agregado</span>
                                </div>
                            </td>
                            <td class="p-2 text-center font-bold">{{ it.cantidad }}</td>
                            <td class="p-2 text-right">
                                <span :class="it.costo_unit === null ? 'text-red-600 font-bold' : ''">
                                    {{ money(it.costo_unit) }}
                                </span>
                            </td>
                            <td class="p-2 text-xs text-surface-600">{{ it.motivo_item || '—' }}</td>
                            <td class="p-2 text-right">
                                <button v-if="esBorrador" @click="quitar(it.id)" class="btn-ghost p-1 text-red-600">
                                    <Trash2 class="h-4 w-4"/>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-sm text-surface-500 text-center py-4">Todavía no hay líneas.</p>

                <!--
                    Una línea sin costo no es un detalle cosmético: el promedio
                    de una ubicación se calcula sobre las entradas de su kardex,
                    y la que entra sin costo queda fuera. Cuando esa mercancía
                    se traslade al almacén bueno, el cero viaja con ella.
                -->
                <div v-if="sinCosto" class="p-3 rounded-lg bg-amber-50 border-l-4 border-amber-500 text-amber-900 text-sm">
                    <b>{{ sinCosto }}</b> línea{{ sinCosto > 1 ? 's' : '' }} sin costo.
                    Esa mercancía entra sin valor, y cuando se traslade al almacén que corresponda
                    se lo lleva en cero. Conviene escribirlo antes de confirmar.
                </div>
            </div>

            <!-- Cerrar -->
            <div v-if="! esNueva" class="flex flex-wrap items-center justify-end gap-2">
                <button v-if="esBorrador && devolucion.items.length" @click="confirmar" class="btn-primary">
                    <Check class="h-4 w-4"/> Confirmar · la mercancía entra a la bodega
                </button>
                <button v-if="devolucion.estado === 'confirmada' && ! devolucion.revisada_at"
                        @click="marcarRevisada" class="btn-primary bg-emerald-600 hover:bg-emerald-700">
                    <ClipboardCheck class="h-4 w-4"/> Marcar revisada
                </button>
            </div>
        </div>
    </AppLayout>
</template>
