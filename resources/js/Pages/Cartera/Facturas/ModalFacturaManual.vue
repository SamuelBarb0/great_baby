<script setup>
/*
 * F2b · Crear una factura que no viene de un pedido.
 *
 * Va como modal sobre la bandeja y no como pantalla aparte: la idea era no
 * sumar entradas al menú. Quien crea una factura a mano ya está mirando el
 * listado, y al guardar vuelve ahí con la factura nueva arriba.
 */
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Trash2, Search, X } from 'lucide-vue-next';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    opciones: { type: Object, default: () => ({ destinos: [], lineas: [], ivas: [] }) },
});
const emit = defineEmits(['cerrar']);

const { money } = useMoney();

const ivaPorDefecto = computed(() => {
    const diecinueve = props.opciones.ivas?.find((i) => Number(i.valor) === 19);
    return diecinueve ? 19 : Number(props.opciones.ivas?.[0]?.valor ?? 0);
});

const lineaNueva = () => ({
    producto_id: null, descripcion: '', cantidad: 1,
    precio_unit: 0, descuento_pct: 0, impuesto_pct: ivaPorDefecto.value,
    sinSiigo: false,
});

const hoy = new Date().toISOString().slice(0, 10);

const form = reactive({
    contacto_id: null,
    fecha_emision: hoy,
    fecha_vencimiento: '',
    linea_negocio: 'great_baby',
    destino_fiscal: 'siigo',
    monto_fiscal: null,
    motivo_destino: '',
    observaciones: '',
    items: [],
});

const guardando = ref(false);
const errorServidor = ref('');

/* ── Cliente ──────────────────────────────────────────────────────────────── */
const buscaCliente = ref('');
const clientes = ref([]);
const clienteAbierto = ref(false);
const clienteElegido = ref(null);

const buscarCliente = useDebounceFn(async () => {
    const q = buscaCliente.value.trim();
    if (q.length < 2) { clientes.value = []; return; }
    try {
        const { data } = await window.axios.get('/app/api/contactos/buscar', { params: { q } });
        clientes.value = data;
        clienteAbierto.value = true;
    } catch { clientes.value = []; }
}, 350);

watch(buscaCliente, () => {
    // Si se edita el texto después de haber elegido, la elección ya no vale.
    if (clienteElegido.value && buscaCliente.value !== etiquetaCliente(clienteElegido.value)) {
        clienteElegido.value = null;
        form.contacto_id = null;
    }
    buscarCliente();
});

const etiquetaCliente = (c) => `${c.nombre}${c.documento ? ' · ' + c.documento : ''}`;

const elegirCliente = (c) => {
    clienteElegido.value = c;
    form.contacto_id = c.id;
    buscaCliente.value = etiquetaCliente(c);
    clienteAbierto.value = false;
};

/* ── Productos ────────────────────────────────────────────────────────────────
 * El buscador va ENCIMA de la tabla, no dentro de la celda: el contenedor de la
 * tabla lleva overflow-x-auto y recorta cualquier desplegable posicionado.
 * Mismo problema ya resuelto así en la orden de compra.
 */
const buscaProducto = ref('');
const productos = ref([]);

const buscarProducto = useDebounceFn(async () => {
    const q = buscaProducto.value.trim();
    if (q.length < 2) { productos.value = []; return; }
    try {
        const { data } = await window.axios.get('/app/catalogo/productos-buscar', { params: { q } });
        productos.value = data;
    } catch { productos.value = []; }
}, 350);

watch(buscaProducto, buscarProducto);

const agregarProducto = (p) => {
    const linea = {
        ...lineaNueva(),
        producto_id: p.id,
        descripcion: `${p.referencia ? p.referencia + ' · ' : ''}${p.nombre}`,
        precio_unit: p.precio ?? 0,
        impuesto_pct: p.iva_pct ?? ivaPorDefecto.value,
        sinSiigo: p.en_siigo === false,
    };
    form.items.push(linea);

    buscaProducto.value = '';
    productos.value = [];
};

const quitarLinea = (i) => form.items.splice(i, 1);

const num = (v) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };
const round2 = (n) => Math.round(n * 100) / 100;
const clamp = (n, a, b) => Math.min(b, Math.max(a, n));
const subtotalLinea = (i) => round2(round2(num(i.cantidad) * num(i.precio_unit))
    * (1 - clamp(num(i.descuento_pct), 0, 100) / 100));

/* ── Totales ──────────────────────────────────────────────────────────────────
 * Se calculan igual que en el backend —bruto, descuento, IVA sobre el neto— para
 * que lo que se ve sea lo que se guarda. Si acá se hiciera distinto, la factura
 * guardada saldría por otro valor que el del botón.
 */
const totales = computed(() => {
    let subtotal = 0, descuento = 0, impuestos = 0;
    for (const i of form.items) {
        const bruto = round2(num(i.cantidad) * num(i.precio_unit));
        const desc = round2(bruto * clamp(num(i.descuento_pct), 0, 100) / 100);
        const neto = round2(bruto - desc);
        subtotal += bruto;
        descuento += desc;
        impuestos += round2(neto * Math.max(0, num(i.impuesto_pct)) / 100);
    }
    subtotal = round2(subtotal); descuento = round2(descuento); impuestos = round2(impuestos);
    return { subtotal, descuento, impuestos, total: round2(subtotal - descuento + impuestos) };
});

/* ── Validación ───────────────────────────────────────────────────────────── */
const esReparto = computed(() => form.destino_fiscal === 'reparto');
const vaASiigo = computed(() => form.destino_fiscal === 'siigo' || esReparto.value);

const problema = computed(() => {
    if (! form.contacto_id) return 'Falta elegir el cliente.';
    if (! form.fecha_emision) return 'Falta la fecha de emisión.';
    if (form.fecha_vencimiento && form.fecha_vencimiento < form.fecha_emision) {
        return 'El vencimiento no puede ser anterior a la emisión.';
    }
    const utiles = form.items.filter((i) => (i.descripcion || '').trim() && num(i.cantidad) > 0);
    if (utiles.length === 0) return 'Agregá al menos un producto con cantidad.';
    if (totales.value.total <= 0) return 'La factura quedaría en $0. Revisá cantidades y precios.';
    if (form.destino_fiscal !== 'siigo' && ! form.motivo_destino.trim()) {
        return 'Para que no vaya a lo fiscal hay que escribir por qué.';
    }
    if (esReparto.value) {
        const m = num(form.monto_fiscal);
        if (m <= 0) return 'Hay que decir cuánto se declara en SIIGO.';
        if (m >= totales.value.total) {
            return 'Lo declarado no puede ser igual ni mayor al total. Si se declara todo, el destino es «Fiscal».';
        }
    }
    return null;
});

/* Lo que no impide guardar pero conviene saber antes. */
const avisos = computed(() => {
    const out = [];
    if (vaASiigo.value && form.items.some((i) => i.sinSiigo)) {
        out.push('Hay productos que todavía no están sincronizados con SIIGO. Sincronizalos en '
            + 'Catálogo → Productos antes de emitir, o la emisión va a fallar.');
    }
    return out;
});

const guardar = () => {
    if (guardando.value || problema.value) return;
    guardando.value = true;
    errorServidor.value = '';

    router.post('/app/facturas/manual', {
        contacto_id: form.contacto_id,
        fecha_emision: form.fecha_emision,
        fecha_vencimiento: form.fecha_vencimiento || null,
        linea_negocio: form.linea_negocio,
        destino_fiscal: form.destino_fiscal,
        monto_fiscal: esReparto.value ? num(form.monto_fiscal) : null,
        motivo_destino: form.motivo_destino || null,
        observaciones: form.observaciones || null,
        items: form.items
            .filter((i) => i.producto_id && (i.descripcion || '').trim() && num(i.cantidad) > 0)
            .map((i) => ({
                producto_id: i.producto_id,
                descripcion: i.descripcion.trim(),
                cantidad: num(i.cantidad),
                precio_unit: num(i.precio_unit),
                descuento_pct: num(i.descuento_pct),
                impuesto_pct: num(i.impuesto_pct),
            })),
    }, {
        onFinish: () => { guardando.value = false; },
        onError: (e) => { errorServidor.value = Object.values(e)[0] ?? 'No se pudo guardar.'; },
    });
};
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 p-4 overflow-y-auto"
         @click.self="emit('cerrar')">
        <div class="card p-5 w-full max-w-5xl space-y-4 my-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="font-bold text-lg">Factura de venta manual</h3>
                    <p class="text-xs text-surface-500 mt-0.5">
                        Para lo que no nace de un pedido B2B: un servicio, un flete, una venta
                        cerrada por fuera. No mueve inventario — el stock baja en el despacho.
                    </p>
                </div>
                <button @click="emit('cerrar')" class="text-surface-400 hover:text-surface-700" aria-label="Cerrar">
                    <X class="h-5 w-5"/>
                </button>
            </div>

            <!-- Cliente y fechas -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="md:col-span-2 relative" data-busca="cliente">
                    <label class="text-xs font-semibold text-surface-500">Cliente *</label>
                    <div class="relative mt-1">
                        <Search class="h-4 w-4 absolute left-3 top-1/2 -translate-y-1/2 text-surface-400"/>
                        <input v-model="buscaCliente" type="search" class="input w-full pl-10"
                               placeholder="Nombre o documento…" autocomplete="off"/>
                    </div>
                    <div v-if="clienteAbierto && clientes.length"
                         class="absolute z-20 mt-1 w-full card p-1 max-h-56 overflow-y-auto shadow-lg">
                        <button v-for="c in clientes" :key="c.id" @click="elegirCliente(c)"
                                class="w-full text-left px-3 py-2 text-sm rounded hover:bg-surface-100 dark:hover:bg-surface-800">
                            <span class="font-medium">{{ c.nombre }}</span>
                            <span v-if="c.documento" class="text-xs text-surface-500 font-mono ml-2">{{ c.documento }}</span>
                        </button>
                    </div>
                    <p v-if="buscaCliente.length >= 2 && ! clienteElegido && ! clientes.length"
                       class="text-[11px] text-amber-600 mt-1">
                        Sin resultados. El cliente tiene que existir en Contactos antes de facturarle.
                    </p>
                </div>
                <div>
                    <label class="text-xs font-semibold text-surface-500">Emisión *</label>
                    <input v-model="form.fecha_emision" type="date" class="input w-full mt-1"/>
                </div>
                <div>
                    <label class="text-xs font-semibold text-surface-500">Vencimiento</label>
                    <input v-model="form.fecha_vencimiento" type="date" class="input w-full mt-1"/>
                    <p class="text-[11px] text-surface-500 mt-1">Vacío = el plazo del cliente.</p>
                </div>
            </div>

            <!-- Los dos ejes -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                <div>
                    <label class="text-xs font-semibold text-surface-500">Línea de negocio</label>
                    <select v-model="form.linea_negocio" class="input w-full mt-1">
                        <option v-for="l in opciones.lineas" :key="l.valor" :value="l.valor">{{ l.label }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold text-surface-500">Destino</label>
                    <select v-model="form.destino_fiscal" class="input w-full mt-1">
                        <option v-for="d in opciones.destinos" :key="d.valor" :value="d.valor">{{ d.label }}</option>
                    </select>
                </div>
                <div v-if="esReparto">
                    <label class="text-xs font-semibold text-surface-500">Se declara en SIIGO *</label>
                    <input v-model="form.monto_fiscal" type="number" step="0.01" min="0"
                           class="input w-full mt-1 font-mono" placeholder="20000000"/>
                </div>
                <div :class="esReparto ? '' : 'md:col-span-2'">
                    <label class="text-xs font-semibold text-surface-500">
                        Motivo {{ form.destino_fiscal === 'siigo' ? '(opcional)' : '*' }}
                    </label>
                    <input v-model="form.motivo_destino" type="text" maxlength="300" class="input w-full mt-1"
                           placeholder="Ej.: acuerdo comercial · autorizado por gerencia"/>
                </div>
            </div>

            <!-- Buscador de productos · arriba de la tabla a propósito -->
            <div>
                <label class="text-xs font-semibold text-surface-500">Agregar ítem *</label>
                <div class="relative mt-1">
                    <Search class="h-4 w-4 absolute left-3 top-1/2 -translate-y-1/2 text-surface-400"/>
                    <input v-model="buscaProducto" type="search" class="input w-full pl-10"
                           placeholder="Buscá por referencia o nombre…" autocomplete="off"/>
                </div>
                <p v-if="buscaProducto.trim().length >= 2 && ! productos.length"
                   class="text-[11px] text-amber-600 mt-1">
                    Sin resultados. Todo ítem tiene que salir del catálogo — lo exige la base y lo
                    exige SIIGO. Si es un flete o un servicio, creá el producto una vez en
                    Catálogo → Productos (sin control de stock) y queda para siempre.
                </p>
                <div v-if="productos.length" class="card p-1 mt-1 max-h-48 overflow-y-auto">
                    <button v-for="p in productos" :key="p.id" @click="agregarProducto(p)"
                            class="w-full text-left px-3 py-2 text-sm rounded hover:bg-surface-100 dark:hover:bg-surface-800 flex items-center gap-2">
                        <span class="font-mono text-xs text-surface-500">{{ p.referencia || '—' }}</span>
                        <span class="font-medium flex-1 truncate">{{ p.nombre }}</span>
                        <span v-if="p.precio" class="text-xs tabular-nums">{{ money(p.precio) }}</span>
                        <span v-if="p.en_siigo === false"
                              class="text-[10px] font-bold text-amber-600" title="No está sincronizado con SIIGO">
                            sin SIIGO
                        </span>
                    </button>
                </div>
            </div>

            <!-- Ítems -->
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-surface-50 dark:bg-surface-900 text-surface-500 text-xs uppercase">
                        <tr>
                            <th class="text-left px-2 py-2">Descripción</th>
                            <th class="w-20">Cant.</th>
                            <th class="w-28">Precio</th>
                            <th class="w-20">Dcto %</th>
                            <th class="w-24">IVA</th>
                            <th class="w-28 text-right">Subtotal</th>
                            <th class="w-8"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(i, idx) in form.items" :key="idx"
                            class="border-t border-surface-100 dark:border-surface-900">
                            <td class="px-2 py-1">
                                <!-- La descripción es editable: es el texto que queda impreso en
                                     la factura, y a veces hay que precisarlo. El producto detrás
                                     no cambia. -->
                                <input v-model="i.descripcion" type="text" maxlength="255"
                                       class="input w-full text-sm"/>
                                <span v-if="i.sinSiigo && vaASiigo"
                                      class="text-[10px] text-amber-600 font-semibold">
                                    sin sincronizar con SIIGO
                                </span>
                            </td>
                            <td class="px-1">
                                <input v-model="i.cantidad" type="number" step="0.001" min="0"
                                       class="input w-full text-sm text-right"/>
                            </td>
                            <td class="px-1">
                                <input v-model="i.precio_unit" type="number" step="0.01" min="0"
                                       class="input w-full text-sm text-right font-mono"/>
                            </td>
                            <td class="px-1">
                                <input v-model="i.descuento_pct" type="number" step="0.01" min="0" max="100"
                                       class="input w-full text-sm text-right"/>
                            </td>
                            <td class="px-1">
                                <select v-model.number="i.impuesto_pct" class="input w-full text-sm">
                                    <option v-for="iv in opciones.ivas" :key="iv.valor" :value="Number(iv.valor)">
                                        {{ iv.label }}
                                    </option>
                                </select>
                            </td>
                            <td class="px-2 text-right font-bold tabular-nums">{{ money(subtotalLinea(i)) }}</td>
                            <td class="px-1">
                                <button @click="quitarLinea(idx)" class="text-red-500 hover:text-red-700 p-1"
                                        aria-label="Quitar línea">
                                    <Trash2 class="h-4 w-4"/>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="! form.items.length">
                            <td colspan="7" class="text-center py-8 text-surface-500 text-sm">
                                Todavía no hay ítems. Buscá un producto arriba para agregar el primero.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Totales y observaciones -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs font-semibold text-surface-500">Observaciones</label>
                    <textarea v-model="form.observaciones" rows="4" maxlength="1000" class="input w-full mt-1"
                              placeholder="Lo que haya que recordar de esta factura dentro de tres meses."></textarea>
                </div>
                <div class="space-y-1 text-sm">
                    <div class="flex justify-between"><span class="text-surface-500">Subtotal</span>
                        <span class="tabular-nums">{{ money(totales.subtotal) }}</span></div>
                    <div class="flex justify-between"><span class="text-surface-500">Descuento</span>
                        <span class="tabular-nums">− {{ money(totales.descuento) }}</span></div>
                    <div class="flex justify-between"><span class="text-surface-500">IVA</span>
                        <span class="tabular-nums">{{ money(totales.impuestos) }}</span></div>
                    <div class="flex justify-between font-bold text-base border-t border-surface-200 dark:border-surface-800 pt-1">
                        <span>Total</span><span class="tabular-nums">{{ money(totales.total) }}</span>
                    </div>
                    <div v-if="esReparto && num(form.monto_fiscal) > 0 && num(form.monto_fiscal) < totales.total"
                         class="text-xs bg-amber-50 dark:bg-amber-900/20 border-l-4 border-amber-500 p-2 mt-2">
                        A SIIGO va <strong>{{ money(num(form.monto_fiscal)) }}</strong> ·
                        queda interno <strong>{{ money(totales.total - num(form.monto_fiscal)) }}</strong>.
                    </div>
                </div>
            </div>

            <!-- Avisos y errores -->
            <div v-if="avisos.length" class="text-xs bg-amber-50 dark:bg-amber-900/20 border-l-4 border-amber-500 p-3 space-y-1">
                <div v-for="(a, k) in avisos" :key="k">{{ a }}</div>
            </div>
            <p v-if="errorServidor" class="text-sm text-red-600 font-semibold">{{ errorServidor }}</p>

            <div class="flex items-center justify-end gap-3 pt-1">
                <p v-if="problema" class="text-xs text-red-600 font-semibold mr-auto">{{ problema }}</p>
                <button @click="emit('cerrar')" class="btn-secondary text-sm">Cancelar</button>
                <button @click="guardar" :disabled="guardando || !! problema"
                        class="btn-primary text-sm disabled:opacity-40">
                    {{ guardando ? 'Guardando…' : 'Crear factura' }}
                </button>
            </div>
        </div>
    </div>
</template>
