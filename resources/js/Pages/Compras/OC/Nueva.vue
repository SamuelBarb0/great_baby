<script setup>
import { reactive, ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ShoppingCart, Plus, Trash2, Save } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    proveedores: { type: Array, required: true },
    // Re-audit M2 UX-A4 · antes bodega default silenciosa; ahora prop `bodegas`
    // permite elección explícita al crear OC (fallback: primera si solo hay 1).
    bodegas: { type: Array, default: () => [] },
});

const { money } = useMoney();

/*
 * Cada línea de la OC apunta a un producto del catálogo.
 *
 * Antes sólo se mandaba `descripcion` (texto libre). El backend ya aceptaba
 * `producto_id` y la tabla ya tenía la columna, pero el formulario nunca la
 * llenaba, así que la línea llegaba a bodega sin forma de saber a qué producto
 * pertenecía: `RecibirMercancia` se salta el kardex cuando el ítem no trae ni
 * producto ni variante, y la mercancía recibida NO entraba al inventario.
 * Comprobado el 2026-10-14 recibiendo una OC real de 20 unidades: recepción
 * confirmada, stock igual que antes.
 *
 * Se permite igual la línea sin producto —fletes, seguros, servicios— pero se
 * avisa, porque esa línea no va a mover existencias.
 */
const lineaNueva = () => ({
    producto_id: null, variante_id: null,
    descripcion: '', cantidad: 1, precio_unit: 0, iva_pct: 19, descuento_pct: 0,
});

const form = reactive({
    proveedor_id: '',
    bodega_id: props.bodegas.length === 1 ? props.bodegas[0].id : '',
    tipo: 'nacional',
    moneda: 'COP',
    tasa_cambio: 1,
    fecha_esperada: '',
    observaciones: '',
    items: [lineaNueva()],
});
const procesando = ref(false);

// COMP-B10 · autocomplete proveedor
const busquedaProveedor = ref('');
const proveedorAbierto = ref(false);
const proveedoresFiltrados = computed(() => {
    const q = busquedaProveedor.value.trim().toLowerCase();
    if (q.length < 2) return [];
    return props.proveedores
        .filter(p => (p.nombre || '').toLowerCase().includes(q)
                   || (p.numero_documento || '').toLowerCase().includes(q))
        .slice(0, 15);
});
const elegirProveedor = (p) => {
    form.proveedor_id = p.id;
    busquedaProveedor.value = p.nombre + (p.numero_documento ? ` · ${p.numero_documento}` : '');
    proveedorAbierto.value = false;
};
// Cierra el panel al clicar fuera.
if (typeof window !== 'undefined') {
    window.addEventListener('click', (e) => {
        if (!e.target.closest('.md\\:col-span-2.relative')) proveedorAbierto.value = false;
    });
}

const addItem = () => form.items.push(lineaNueva());
const rmItem = (i) => form.items.splice(i, 1);

/*
 * Buscador único encima de la tabla.
 *
 * El primer intento puso el desplegable dentro de la celda, pero el contenedor
 * de la tabla lleva `overflow-x-auto` y recorta cualquier posicionado absoluto:
 * la lista existía en el DOM y no se veía. Acá arriba no hay nada que la corte,
 * y además es el mismo gesto que ya conoce la gente del carrito del vendedor.
 */
const busquedaProducto = ref('');
const sugerencias = ref([]);

const buscarProducto = async () => {
    const q = busquedaProducto.value.trim();
    if (q.length < 2) { sugerencias.value = []; return; }
    try {
        const r = await fetch(`/app/catalogo/productos-buscar?q=${encodeURIComponent(q)}`,
            { headers: { Accept: 'application/json' } });
        sugerencias.value = r.ok ? await r.json() : [];
    } catch { sugerencias.value = []; }
};

const agregarProducto = (p) => {
    // Si la primera línea está vacía la reusamos, para no dejar una fila muerta.
    const vacia = form.items.findIndex(i => !(i.descripcion || '').trim());
    const linea = { ...lineaNueva(), producto_id: p.id, descripcion: `${p.referencia} · ${p.nombre}` };
    if (vacia >= 0) form.items.splice(vacia, 1, linea);
    else form.items.push(linea);
    busquedaProducto.value = '';
    sugerencias.value = [];
};

/** Líneas que no van a mover inventario porque no apuntan a un producto. */
const lineasSinProducto = computed(() =>
    form.items.filter(i => (i.descripcion || '').trim() && !i.producto_id).length);

const subtotal = computed(() => form.items.reduce((s, i) => s + (i.cantidad * i.precio_unit * (1 - (i.descuento_pct || 0) / 100)), 0));
const iva = computed(() => form.items.reduce((s, i) => {
    const sub = i.cantidad * i.precio_unit * (1 - (i.descuento_pct || 0) / 100);
    return s + sub * ((i.iva_pct || 0) / 100);
}, 0));
const total = computed(() => subtotal.value + iva.value);

// Re-audit M2 UX-B8 · validar items con cantidad>0 y precio>0 antes de submit.
const itemsValidos = computed(() => form.items.filter(i =>
    i.descripcion?.trim() && Number(i.cantidad) > 0 && Number(i.precio_unit) > 0
));
const puedeGuardar = computed(() => form.proveedor_id && form.bodega_id && itemsValidos.value.length > 0);

const guardar = () => {
    if (procesando.value || !puedeGuardar.value) return;
    procesando.value = true;
    router.post('/app/compras/oc', form, {
        onError: () => { procesando.value = false; },
        onSuccess: () => { procesando.value = false; },
    });
};
</script>

<template>
    <Head title="Nueva orden de compra"/>
    <AppLayout>
        <div class="max-w-5xl mx-auto space-y-4">
            <h1 class="text-2xl font-bold flex items-center gap-2">
                <ShoppingCart class="h-6 w-6 text-brand-600"/>
                Nueva orden de compra
            </h1>

            <div class="card p-5 space-y-3">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <!-- COMP-B10 · Autocomplete de proveedor
                        Antes era `<select>` plano con todos los proveedores · con 200+ la UX era inviable.
                        Ahora: input de búsqueda incremental (nombre o NIT) + lista desplegable con hits.
                    -->
                    <div class="md:col-span-2 relative">
                        <label class="text-xs font-semibold">Proveedor</label>
                        <input
                            type="text" class="input w-full"
                            :placeholder="form.proveedor_id ? '' : 'Busca por nombre o NIT…'"
                            v-model="busquedaProveedor"
                            @focus="proveedorAbierto = true"
                            @input="proveedorAbierto = true; form.proveedor_id = ''"
                            autocomplete="off"
                            required
                        />
                        <button v-if="form.proveedor_id" type="button"
                            @click="form.proveedor_id = ''; busquedaProveedor = ''"
                            class="absolute right-2 top-7 text-surface-400 hover:text-red-500 text-sm"
                            title="Limpiar">×</button>
                        <ul v-if="proveedorAbierto && proveedoresFiltrados.length > 0"
                            class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-surface-200 dark:border-surface-800 bg-white dark:bg-surface-900 shadow-lg text-sm">
                            <li v-for="p in proveedoresFiltrados" :key="p.id"
                                @click="elegirProveedor(p)"
                                class="px-3 py-2 cursor-pointer hover:bg-brand-50 dark:hover:bg-surface-800">
                                <div class="font-semibold">{{ p.nombre }}</div>
                                <div class="text-[11px] text-surface-500" v-if="p.numero_documento">NIT {{ p.numero_documento }}</div>
                            </li>
                        </ul>
                        <p v-if="proveedorAbierto && busquedaProveedor.length >= 2 && proveedoresFiltrados.length === 0"
                           class="absolute z-10 mt-1 w-full rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-950/40 px-3 py-2 text-[11px] text-amber-700">
                            Sin coincidencias con «{{ busquedaProveedor }}».
                        </p>
                    </div>
                    <!-- Re-audit M2 UX-A4 · selector de bodega destino. -->
                    <div>
                        <label class="text-xs font-semibold">Bodega destino <span class="text-red-500">*</span></label>
                        <select v-model="form.bodega_id" class="input w-full" required>
                            <option value="">— Seleccionar —</option>
                            <option v-for="b in bodegas" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                        </select>
                        <p v-if="!bodegas.length" class="text-[10px] text-red-600 mt-1">Sin bodegas configuradas. Crea una en Inventario primero.</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Tipo</label>
                        <select v-model="form.tipo" class="input w-full">
                            <option value="nacional">Nacional</option>
                            <option value="importacion">Importación</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Moneda</label>
                        <select v-model="form.moneda" class="input w-full">
                            <option value="COP">COP</option>
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                            <option value="CNY">CNY</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Tasa cambio</label>
                        <input type="number" step="0.01" v-model.number="form.tasa_cambio" class="input w-full"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Fecha esperada</label>
                        <input type="date" v-model="form.fecha_esperada" class="input w-full"/>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-semibold">Observaciones</label>
                    <textarea v-model="form.observaciones" rows="2" class="input w-full"></textarea>
                </div>
            </div>

            <div class="card p-4">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Ítems</div>
                    <button @click="addItem" class="btn-ghost text-xs"><Plus class="h-3 w-3"/> Agregar ítem</button>
                </div>
                <div class="relative mb-3">
                    <label class="block text-xs font-semibold text-surface-500 mb-1">Agregar producto del catálogo</label>
                    <input v-model="busquedaProducto" @input="buscarProducto"
                           class="input w-full text-sm" autocomplete="off"
                           placeholder="Escribí la referencia o el nombre y elegilo de la lista…"/>
                    <ul v-if="sugerencias.length"
                        class="absolute z-30 left-0 right-0 mt-1 bg-white dark:bg-surface-900 border border-surface-300 dark:border-surface-700 rounded shadow-lg max-h-56 overflow-y-auto">
                        <li v-for="p in sugerencias" :key="p.id" @click="agregarProducto(p)"
                            class="px-3 py-2 text-sm hover:bg-brand-50 dark:hover:bg-brand-950/30 cursor-pointer">
                            <span class="font-mono text-xs">{{ p.referencia }}</span> · {{ p.nombre }}
                        </li>
                    </ul>
                    <p class="text-[11px] text-surface-500 mt-1">
                        Lo que agregues así queda enlazado al catálogo y suma existencias cuando bodega reciba.
                        Para fletes o servicios, escribí la línea a mano en la tabla.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-[10px] text-surface-500 uppercase">
                            <tr>
                                <th class="text-left p-1">Descripción</th>
                                <th class="text-right p-1">Cant</th>
                                <th class="text-right p-1">Precio</th>
                                <th class="text-right p-1">Desc%</th>
                                <th class="text-right p-1">IVA%</th>
                                <th class="text-right p-1">Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(it, i) in form.items" :key="i" class="border-t">
                                <td class="p-1">
                                    <input v-model="it.descripcion" class="input w-full text-sm"
                                           :class="it.producto_id ? 'border-emerald-400' : ''"
                                           placeholder="Descripción libre…"/>
                                    <div v-if="it.producto_id" class="text-[10px] text-emerald-600 font-semibold mt-0.5">
                                        ✓ del catálogo · suma stock al recibir
                                    </div>
                                    <div v-else-if="(it.descripcion || '').trim()" class="text-[10px] text-amber-600 mt-0.5">
                                        sin catálogo · no mueve inventario
                                    </div>
                                </td>
                                <td class="p-1"><input type="number" step="0.001" v-model.number="it.cantidad" class="input w-20 text-right text-sm"/></td>
                                <td class="p-1"><input type="number" step="0.01" v-model.number="it.precio_unit" class="input w-28 text-right text-sm"/></td>
                                <td class="p-1"><input type="number" step="0.01" v-model.number="it.descuento_pct" class="input w-16 text-right text-sm"/></td>
                                <td class="p-1"><input type="number" step="0.01" v-model.number="it.iva_pct" class="input w-16 text-right text-sm"/></td>
                                <td class="p-1 text-right font-bold">{{ money(it.cantidad * it.precio_unit * (1 - (it.descuento_pct || 0) / 100) * (1 + (it.iva_pct || 0) / 100)) }}</td>
                                <td class="p-1"><button v-if="form.items.length > 1" @click="rmItem(i)" class="text-red-600"><Trash2 class="h-4 w-4"/></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="lineasSinProducto" class="mt-3 p-2 rounded bg-amber-50 border-l-4 border-amber-500 text-xs text-amber-800">
                    ⚠ Hay <b>{{ lineasSinProducto }}</b> línea(s) sin producto del catálogo. Se pueden dejar así
                    —fletes, seguros, servicios— pero <b>no van a sumar existencias</b> cuando bodega reciba la mercancía.
                    Para que sumen, escribí la referencia y elegila de la lista.
                </div>

                <div class="text-right pt-3 border-t mt-3 space-y-1">
                    <div class="text-sm text-surface-500">Subtotal: <b>{{ money(subtotal) }}</b></div>
                    <div class="text-sm text-surface-500">IVA: <b>{{ money(iva) }}</b></div>
                    <div class="text-xl font-bold text-brand-600">Total: {{ money(total) }}</div>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <Link href="/app/compras" class="btn-ghost">Cancelar</Link>
                <button @click="guardar" :disabled="procesando || !puedeGuardar" class="btn-primary disabled:opacity-50"
                        :title="!puedeGuardar ? 'Selecciona proveedor, bodega y agrega al menos un ítem con cantidad y precio > 0' : ''">
                    <Save class="h-4 w-4"/> {{ procesando ? 'Creando…' : 'Crear OC' }}
                </button>
            </div>
        </div>
    </AppLayout>
</template>
