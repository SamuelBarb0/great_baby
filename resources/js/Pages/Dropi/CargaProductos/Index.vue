<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { PackageOpen, Upload, Search, PackagePlus, Loader2, ChevronRight } from 'lucide-vue-next';
import DropiLayout from '@/Layouts/DropiLayout.vue';

defineProps({
    cargas: { type: Array, default: () => [] },
});

// ── Excel ──
const excel = useForm({ archivo: null, modo: 'reemplazar', concepto: '' });
const enviarExcel = () => excel.post('/app/dropi/carga-productos/excel', { forceFormData: true });

// ── Un producto que ya existe ──
const q = ref('');
const resultados = ref([]);
const buscando = ref(false);
const elegido = ref(null);
const individual = useForm({ dropi_producto_id: null, concepto: '' });
let t = null;
watch(q, (v) => {
    clearTimeout(t);
    if (v.trim().length < 2) { resultados.value = []; return; }
    t = setTimeout(async () => {
        buscando.value = true;
        try {
            resultados.value = (await axios.get('/app/dropi/carga-productos/buscar', { params: { q: v } })).data;
        } finally { buscando.value = false; }
    }, 350);
});
const elegir = (p) => { elegido.value = p; individual.dropi_producto_id = p.id; };
const enviarIndividual = () => individual.post('/app/dropi/carga-productos/individual');

// ── Un producto nuevo ──
const nuevo = useForm({ nombre: '', sku: '', concepto: '' });
const enviarNuevo = () => nuevo.post('/app/dropi/carga-productos/individual-crear');

const estadoClase = (e) => ({
    leyendo: 'bg-amber-100 text-amber-800',
    borrador: 'bg-surface-100 text-surface-700 dark:bg-surface-800 dark:text-surface-300',
    procesando: 'bg-amber-100 text-amber-800',
    terminado: 'bg-emerald-100 text-emerald-800',
}[e] || 'bg-surface-100 text-surface-700');
</script>

<template>
    <Head title="Productos y stock en Dropi"/>
    <DropiLayout>
        <div class="max-w-5xl mx-auto space-y-4 p-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <PackageOpen class="h-6 w-6 text-brand-600"/>
                    Productos y stock en Dropi
                </h1>
                <p class="text-sm text-surface-500 dark:text-surface-400 mt-1">
                    Crea productos y carga su stock en Dropi desde el ERP, sin las plantillas de Dropi.
                    Siempre ves una vista previa antes de mandar nada, y cada producto se verifica releyendo Dropi.
                </p>
            </div>

            <!-- Excel -->
            <form class="card p-5 space-y-4" @submit.prevent="enviarExcel">
                <h2 class="font-semibold flex items-center gap-2"><Upload class="h-4 w-4 text-brand-600"/> Cargar un Excel</h2>
                <p class="text-sm text-surface-500 dark:text-surface-400">
                    Columnas <strong>PRODUCTO</strong> («REF NOMBRE»), <strong>CANT</strong> y <strong>VARIACIONES</strong>
                    («8 ROJO- 9 AZUL» o «NO HAY VARIABLE»). Es el formato del inventario del cliente.
                </p>
                <div>
                    <input type="file" accept=".xls,.xlsx" class="block text-sm" @change="excel.archivo = $event.target.files[0]"/>
                    <p v-if="excel.errors.archivo" class="text-sm text-red-600 mt-1">{{ excel.errors.archivo }}</p>
                </div>
                <fieldset class="grid sm:grid-cols-2 gap-3">
                    <label class="rounded-lg border p-3 cursor-pointer flex gap-2" :class="excel.modo === 'reemplazar' ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20' : 'border-surface-200 dark:border-surface-700'">
                        <input v-model="excel.modo" type="radio" value="reemplazar" class="mt-1"/>
                        <span><strong>El Excel dice cuánto debe quedar</strong><br/>
                            <span class="text-sm text-surface-500">Si Dropi tiene 432 y el Excel dice 332, sale una SALIDA de 100.</span></span>
                    </label>
                    <label class="rounded-lg border p-3 cursor-pointer flex gap-2" :class="excel.modo === 'sumar' ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20' : 'border-surface-200 dark:border-surface-700'">
                        <input v-model="excel.modo" type="radio" value="sumar" class="mt-1"/>
                        <span><strong>El Excel trae lo que entra</strong><br/>
                            <span class="text-sm text-surface-500">Si Dropi tiene 432 y el Excel dice 332, queda en 764.</span></span>
                    </label>
                </fieldset>
                <div>
                    <label class="text-sm font-medium">Concepto <span class="text-surface-500 font-normal">(queda en el historial de stock de Dropi)</span></label>
                    <input v-model="excel.concepto" type="text" maxlength="100" placeholder="Ej: Inventario físico noviembre 2026" class="input w-full mt-1"/>
                    <p v-if="excel.errors.concepto" class="text-sm text-red-600 mt-1">{{ excel.errors.concepto }}</p>
                </div>
                <button type="submit" class="btn btn-primary inline-flex items-center gap-2" :disabled="excel.processing || !excel.archivo || excel.concepto.trim().length < 5">
                    <Loader2 v-if="excel.processing" class="h-4 w-4 animate-spin"/>
                    {{ excel.processing ? 'Subiendo el Excel…' : 'Ver vista previa' }}
                </button>
                <p v-if="excel.processing" class="text-xs text-surface-500">Después se lee Dropi en la siguiente pantalla, con su avance.</p>
            </form>

            <div class="grid md:grid-cols-2 gap-4">
                <!-- Un producto que existe -->
                <form class="card p-5 space-y-3" @submit.prevent="enviarIndividual">
                    <h2 class="font-semibold flex items-center gap-2"><Search class="h-4 w-4 text-brand-600"/> Cambiar el stock de un producto</h2>
                    <input v-model="q" type="search" placeholder="Nombre, SKU o id de Dropi" class="input w-full"/>
                    <p v-if="buscando" class="text-xs text-surface-500">Buscando…</p>
                    <ul v-if="resultados.length && !elegido" class="divide-y divide-surface-100 dark:divide-surface-800 max-h-64 overflow-y-auto rounded border border-surface-200 dark:border-surface-700">
                        <li v-for="p in resultados" :key="p.id">
                            <button type="button" class="w-full text-left px-3 py-2 hover:bg-surface-50 dark:hover:bg-surface-800 text-sm" @click="elegir(p)">
                                <span class="font-medium">{{ p.nombre }}</span>
                                <span class="block text-xs text-surface-500">#{{ p.id }} · {{ p.sku || 'sin SKU' }} · {{ p.tipo === 'VARIABLE' ? 'con variaciones' : 'simple' }} · stock {{ p.stock }}<span v-if="p.privado"> · privado</span></span>
                            </button>
                        </li>
                    </ul>
                    <p v-else-if="q.trim().length >= 2 && !buscando && !elegido" class="text-xs text-surface-500">Sin resultados.</p>
                    <div v-if="elegido" class="rounded bg-surface-50 dark:bg-surface-800 p-3 text-sm flex justify-between gap-2">
                        <span><strong>{{ elegido.nombre }}</strong><br/><span class="text-xs text-surface-500">#{{ elegido.id }} · stock {{ elegido.stock }}</span></span>
                        <button type="button" class="text-xs text-brand-600 hover:underline" @click="elegido = null; individual.dropi_producto_id = null">Cambiar</button>
                    </div>
                    <input v-model="individual.concepto" type="text" maxlength="100" placeholder="Concepto (ej: Ajuste por conteo)" class="input w-full"/>
                    <p v-if="individual.errors.concepto" class="text-sm text-red-600">{{ individual.errors.concepto }}</p>
                    <button type="submit" class="btn btn-primary" :disabled="individual.processing || !elegido || individual.concepto.trim().length < 5">Continuar</button>
                </form>

                <!-- Un producto nuevo -->
                <form class="card p-5 space-y-3" @submit.prevent="enviarNuevo">
                    <h2 class="font-semibold flex items-center gap-2"><PackagePlus class="h-4 w-4 text-brand-600"/> Crear un producto nuevo</h2>
                    <input v-model="nuevo.nombre" type="text" maxlength="200" placeholder="Nombre del producto" class="input w-full"/>
                    <p v-if="nuevo.errors.nombre" class="text-sm text-red-600">{{ nuevo.errors.nombre }}</p>
                    <input v-model="nuevo.sku" type="text" maxlength="100" placeholder="SKU / referencia (opcional)" class="input w-full"/>
                    <input v-model="nuevo.concepto" type="text" maxlength="100" placeholder="Concepto (ej: Producto nuevo importación)" class="input w-full"/>
                    <p v-if="nuevo.errors.concepto" class="text-sm text-red-600">{{ nuevo.errors.concepto }}</p>
                    <p class="text-xs text-surface-500">Precios, colores, medidas y stock se llenan en el paso siguiente.</p>
                    <button type="submit" class="btn btn-primary" :disabled="nuevo.processing || nuevo.nombre.trim().length < 3 || nuevo.concepto.trim().length < 5">Continuar</button>
                </form>
            </div>

            <!-- Historial -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3">Cargas anteriores</h2>
                <p v-if="!cargas.length" class="text-sm text-surface-500">Todavía no hay cargas.</p>
                <ul v-else class="divide-y divide-surface-100 dark:divide-surface-800">
                    <li v-for="c in cargas" :key="c.id">
                        <Link :href="`/app/dropi/carga-productos/${c.id}`" class="flex items-center justify-between gap-3 py-2 hover:bg-surface-50 dark:hover:bg-surface-800 px-2 rounded">
                            <span class="min-w-0">
                                <span class="font-mono text-sm">{{ c.numero }}</span>
                                <span class="ml-2 text-sm">{{ c.concepto }}</span>
                                <span class="block text-xs text-surface-500 truncate">
                                    {{ c.fecha }} · {{ c.usuario || '—' }} · {{ c.origen === 'excel' ? (c.archivo || 'Excel') : 'Un producto' }}
                                    · {{ c.operaciones }} filas<span v-if="c.ok"> · {{ c.ok }} ok</span><span v-if="c.errores" class="text-red-600"> · {{ c.errores }} con error</span>
                                </span>
                            </span>
                            <span class="flex items-center gap-2 flex-shrink-0">
                                <span class="text-xs px-2 py-0.5 rounded-full" :class="estadoClase(c.estado)">{{ c.estado }}</span>
                                <ChevronRight class="h-4 w-4 text-surface-400"/>
                            </span>
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
    </DropiLayout>
</template>
