<script setup>
import { reactive, ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { ShoppingCart, Plus, Trash2, Save, FileSpreadsheet, Calculator } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    proveedores: { type: Array, required: true },
    // Re-audit M2 UX-A4 · antes bodega default silenciosa; ahora prop `bodegas`
    // permite elección explícita al crear OC (fallback: primera si solo hay 1).
    bodegas: { type: Array, default: () => [] },
    // Importación: parámetros y tasa de la última OC pre-liquidada, y los dos
    // destinos posibles de cada línea (Great Baby / E-commerce).
    parametrosIniciales: { type: Object, default: () => ({}) },
    // Moneda → tasa de su última OC de importación (nunca la de otra moneda).
    tasasIniciales: { type: Object, default: () => ({}) },
    empresasDestino: { type: Array, default: () => [] },
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
    descripcion: '', cantidad: 1, precio_unit: 0,
    // En importación el IVA se paga en aduana: va en la pre-liquidación, no en la OC.
    iva_pct: form?.tipo === 'importacion' ? 0 : 19, descuento_pct: 0,
    cajas: null, unidades_por_caja: null, cbm_caja: null, empresa_destino_id: null, tipo_linea: 'producto',
});

const form = reactive({
    proveedor_id: '',
    bodega_id: props.bodegas.length === 1 ? props.bodegas[0].id : '',
    tipo: 'nacional',
    moneda: 'COP',
    tasa_cambio: 1,
    fecha_esperada: '',
    observaciones: '',
    parametros_preliquidacion: { ...props.parametrosIniciales },
    items: [],
});
form.items.push(lineaNueva());
const procesando = ref(false);

/*
 * Orden de importación (Excel de Alejandro): se pide por cajas, cada caja
 * ocupa su CBM en el contenedor y cada línea va para Great Baby o para el
 * e-commerce. Las unidades salen de cajas × unidades por caja.
 */
const esImportacion = computed(() => form.tipo === 'importacion');
watch(() => form.tipo, (tipo) => {
    if (tipo !== 'importacion') return;
    if (form.moneda === 'COP') form.moneda = 'CNY';
    form.items.forEach(i => { if (!i.cajas) i.iva_pct = 0; });
});
watch(() => form.moneda, (moneda) => {
    if (form.tipo === 'importacion' && props.tasasIniciales[moneda]) form.tasa_cambio = props.tasasIniciales[moneda];
}, { immediate: false });

const sincronizarCantidad = (it) => {
    if (Number(it.cajas) > 0 && Number(it.unidades_por_caja) > 0) {
        it.cantidad = Number(it.cajas) * Number(it.unidades_por_caja);
    }
};

const cbmTotal = computed(() => form.items.reduce((s, i) => s + (Number(i.cbm_caja) || 0) * (Number(i.cajas) || 0), 0));
const capacidad = computed(() => Number(form.parametros_preliquidacion.capacidad_m3) || 0);
const ocupacion = computed(() => capacidad.value > 0 ? cbmTotal.value / capacidad.value * 100 : 0);
const cbmPorDestino = computed(() => props.empresasDestino.map(e => ({
    ...e,
    cbm: form.items
        .filter(i => (i.empresa_destino_id || props.empresasDestino[0]?.id) === e.id)
        .reduce((s, i) => s + (Number(i.cbm_caja) || 0) * (Number(i.cajas) || 0), 0),
})));

const parametrosVisibles = [
    { clave: 'comision_pct', etiqueta: 'Comisión %', paso: '0.01' },
    { clave: 'flete_m3', etiqueta: 'Flete por m³ (COP)', paso: '1' },
    { clave: 'gasto_colombia_m3', etiqueta: 'Gasto Colombia por m³ (COP)', paso: '1' },
    { clave: 'arancel_pct', etiqueta: 'Arancel %', paso: '0.01' },
    { clave: 'iva_pct', etiqueta: 'IVA %', paso: '0.01' },
    { clave: 'divisor_precio_1', etiqueta: 'Divisor precio 1', paso: '0.01' },
    { clave: 'divisor_precio_2', etiqueta: 'Divisor precio 2', paso: '0.01' },
    { clave: 'capacidad_m3', etiqueta: 'Capacidad contenedor (m³)', paso: '0.01' },
    { clave: 'valor_minimo', etiqueta: 'Valor mínimo', paso: '0.01' },
];
const verParametros = ref(false);

// Cargar el Excel del proveedor: llena el formulario, no guarda nada.
const archivoExcel = ref(null);
const leyendoExcel = ref(false);
const avisosExcel = ref([]);
const errorExcel = ref('');
const cargarExcel = async (e) => {
    const archivo = e.target.files?.[0];
    if (!archivo) return;
    leyendoExcel.value = true;
    errorExcel.value = '';
    avisosExcel.value = [];
    try {
        const fd = new FormData();
        fd.append('archivo', archivo);
        const { data } = await axios.post('/app/compras/oc/leer-excel', fd);
        form.tipo = 'importacion';
        const conDatos = form.items.filter(i => (i.descripcion || '').trim());
        form.items = [...conDatos, ...data.lineas.map(l => ({ ...lineaNueva(), ...l }))];
        avisosExcel.value = [`${data.lineas.length} línea(s) leídas de «${archivo.name}». Revisa y guarda.`, ...data.avisos];
    } catch (err) {
        errorExcel.value = err.response?.data?.message
            || err.response?.data?.errors?.archivo?.[0]
            || 'No se pudo leer el archivo.';
    } finally {
        leyendoExcel.value = false;
        if (archivoExcel.value) archivoExcel.value.value = '';
    }
};

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
const fmt = (n, d = 2) => Number(n || 0).toLocaleString('es-CO', { minimumFractionDigits: d, maximumFractionDigits: d });
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
// Ninguna moneda extranjera vale un peso: con tasa 1, 3,50 RMB saldrían a 3,50 pesos.
const faltaTasa = computed(() => form.moneda !== 'COP' && Number(form.tasa_cambio) <= 1);
const puedeGuardar = computed(() => form.proveedor_id && form.bodega_id && itemsValidos.value.length > 0 && !faltaTasa.value);

// Lo que falta, dicho junto al botón: deshabilitado y sin razón no se entiende.
const faltan = computed(() => [
    !form.proveedor_id && 'elegir el proveedor de la lista',
    !form.bodega_id && 'elegir la bodega',
    faltaTasa.value && 'escribir la tasa de cambio',
    !itemsValidos.value.length && 'agregar un producto con cantidad y precio',
].filter(Boolean));

const guardar = () => {
    if (procesando.value || !puedeGuardar.value) return;
    procesando.value = true;
    const payload = { ...form };
    if (!esImportacion.value) delete payload.parametros_preliquidacion;
    router.post('/app/compras/oc', payload, {
        onError: () => { procesando.value = false; },
        onSuccess: () => { procesando.value = false; },
    });
};
</script>

<template>
    <Head title="Nueva orden de compra"/>
    <AppLayout>
        <div class="max-w-5xl mx-auto space-y-4">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <ShoppingCart class="h-6 w-6 text-brand-600"/>
                    Nueva orden de compra
                </h1>
                <div class="flex items-center gap-2">
                    <Link href="/app/compras/calculadora" class="btn-ghost text-sm"><Calculator class="h-4 w-4"/> Calculadora</Link>
                    <label class="btn-ghost text-sm border border-surface-300 dark:border-surface-700 cursor-pointer"
                           :class="leyendoExcel && 'opacity-50 pointer-events-none'">
                        <FileSpreadsheet class="h-4 w-4"/> {{ leyendoExcel ? 'Leyendo…' : 'Cargar Excel del proveedor' }}
                        <input ref="archivoExcel" type="file" accept=".xlsx" class="hidden" @change="cargarExcel"/>
                    </label>
                </div>
            </div>
            <div v-if="errorExcel" class="p-3 rounded-lg bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 text-sm text-red-700 dark:text-red-300">
                {{ errorExcel }}
            </div>
            <div v-if="avisosExcel.length" class="p-3 rounded-lg bg-brand-50 dark:bg-brand-950/30 border-l-4 border-brand-500 text-sm space-y-1">
                <div v-for="(a, i) in avisosExcel" :key="i">{{ a }}</div>
            </div>

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
                        <input type="number" step="0.01" v-model.number="form.tasa_cambio" class="input w-full" :class="faltaTasa ? 'border-red-500' : ''"/>
                        <p v-if="faltaTasa" class="text-xs text-red-600 mt-1">Escriba cuántos pesos vale 1 {{ form.moneda }}.</p>
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

            <div v-if="esImportacion" class="card p-4">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div>
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Contenedor</div>
                        <div class="text-sm mt-1">
                            <b :class="ocupacion > 100 ? 'text-red-600' : ''">{{ fmt(cbmTotal) }} m³</b>
                            de {{ fmt(capacidad) }} m³ · {{ fmt(ocupacion, 1) }} %
                            <span v-for="d in cbmPorDestino" :key="d.id" class="text-surface-500"> · {{ d.nombre }} {{ fmt(d.cbm) }} m³</span>
                        </div>
                    </div>
                    <button type="button" @click="verParametros = !verParametros" class="btn-ghost text-xs">
                        {{ verParametros ? 'Ocultar' : 'Ver' }} parámetros de la pre-liquidación
                    </button>
                </div>
                <div class="h-2 rounded bg-surface-200 dark:bg-surface-800 mt-2 overflow-hidden">
                    <div class="h-full" :class="ocupacion > 100 ? 'bg-red-500' : 'bg-emerald-500'"
                         :style="{ width: Math.min(ocupacion, 100) + '%' }"></div>
                </div>
                <p v-if="Number(form.tasa_cambio) <= 1" class="text-xs text-red-600 mt-1">
                    Falta la tasa de {{ form.moneda }} a COP: sin ella la pre-liquidación no sirve.
                </p>
                <p v-if="ocupacion > 100" class="text-xs text-red-600 mt-1">
                    Se pasa {{ fmt(cbmTotal - capacidad) }} m³ del contenedor. Se puede guardar igual.
                </p>
                <div v-if="verParametros" class="grid grid-cols-2 md:grid-cols-3 gap-3 mt-3">
                    <div v-for="p in parametrosVisibles" :key="p.clave">
                        <label class="text-xs font-semibold">{{ p.clave === 'valor_minimo' ? `Valor mínimo (${form.moneda}, 0 = no revisar)` : p.etiqueta }}</label>
                        <input type="number" :step="p.paso" v-model.number="form.parametros_preliquidacion[p.clave]" class="input w-full"/>
                    </div>
                    <p class="col-span-full text-[11px] text-surface-500">
                        Vienen de la última orden de importación. La tasa es la de arriba ({{ form.moneda }} → COP).
                    </p>
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
                                <template v-if="esImportacion">
                                    <th class="text-right p-1">Cajas</th>
                                    <th class="text-right p-1">Uds/caja</th>
                                    <th class="text-right p-1">CBM caja</th>
                                    <th class="text-left p-1">Destino</th>
                                    <th class="text-left p-1">Tipo</th>
                                </template>
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
                                    <div v-if="it.tipo_linea === 'componente'" class="text-[10px] text-sky-700 dark:text-sky-400 font-semibold mt-0.5">
                                        componente · se cuenta al recibir, no sube al inventario y su costo va al producto de la misma referencia
                                    </div>
                                    <div v-else-if="it.producto_id" class="text-[10px] text-emerald-600 font-semibold mt-0.5">
                                        ✓ del catálogo · suma stock al recibir
                                    </div>
                                    <div v-else-if="(it.descripcion || '').trim()" class="text-[10px] text-amber-600 mt-0.5">
                                        sin catálogo · no mueve inventario
                                    </div>
                                </td>
                                <template v-if="esImportacion">
                                    <td class="p-1"><input type="number" step="1" min="0" v-model.number="it.cajas" @input="sincronizarCantidad(it)" class="input w-16 text-right text-sm"/></td>
                                    <td class="p-1"><input type="number" step="1" min="0" v-model.number="it.unidades_por_caja" @input="sincronizarCantidad(it)" class="input w-16 text-right text-sm"/></td>
                                    <td class="p-1"><input type="number" step="0.000001" min="0" v-model.number="it.cbm_caja" class="input w-24 text-right text-sm"/></td>
                                    <td class="p-1">
                                        <select v-model="it.empresa_destino_id" class="input w-28 text-sm">
                                            <option v-for="e in empresasDestino" :key="e.id" :value="e.codigo === 'great_baby' ? null : e.id">{{ e.nombre }}</option>
                                        </select>
                                    </td>
                                    <td class="p-1">
                                        <select v-model="it.tipo_linea" class="input w-28 text-sm" :aria-label="`Tipo de ${it.descripcion || 'la línea'}`">
                                            <option value="producto">Producto</option>
                                            <option value="componente">Componente</option>
                                        </select>
                                    </td>
                                </template>
                                <td class="p-1"><input type="number" step="0.001" v-model.number="it.cantidad" :readonly="esImportacion && it.cajas > 0 && it.unidades_por_caja > 0" class="input w-20 text-right text-sm"/></td>
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

            <div class="h-16" aria-hidden="true"></div>
            <div class="card p-3 fixed bottom-4 right-4 left-4 md:left-auto md:right-6 md:max-w-2xl z-30 flex items-center justify-between gap-3 flex-wrap shadow-lg">
                <p class="text-sm" :class="faltan.length ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400'">
                    <template v-if="faltan.length">Para crearla falta: {{ faltan.join(', ') }}.</template>
                    <template v-else>Lista para crear.</template>
                </p>
                <div class="flex gap-2">
                <Link href="/app/compras" class="btn-ghost">Cancelar</Link>
                <button @click="guardar" :disabled="procesando || !puedeGuardar" class="btn-primary disabled:opacity-50">
                    <Save class="h-4 w-4"/> {{ procesando ? 'Creando…' : 'Crear OC' }}
                </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
