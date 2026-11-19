<script setup>
/**
 * Estado de resultados · de ingresos a utilidad neta, en un periodo.
 *
 * Separa el costo de ventas de los gastos de operación a propósito: la
 * utilidad BRUTA sólo descuenta el costo, y es la que dice si el negocio
 * compra y vende bien. Si se mezclan, no se distingue un problema de margen
 * del producto de uno de estructura de la empresa.
 */
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { TrendingUp, FileText } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import FiltroEjes from '@/Components/FiltroEjes.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasReportes } from '@/composables/usePestanasContables';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    filtros: { type: Object, required: true },
    opcionesEjes: { type: Object, default: () => ({ lineas: [], destinos: [] }) },
    ingresos: { type: Object, required: true },
    costo_ventas: { type: Object, required: true },
    costos_produccion: { type: Object, required: true },
    gastos: { type: Object, required: true },
    utilidad_bruta: { type: Number, required: true },
    utilidad_neta: { type: Number, required: true },
    margen_bruto: { type: Number, default: null },
    margen_neto: { type: Number, default: null },
    sin_datos: { type: Boolean, default: false },
});

const { money } = useMoney();
const desde = ref(props.filtros.desde);
const hasta = ref(props.filtros.hasta);
const incluirAnulados = ref(!!props.filtros.incluir_anulados);

/*
 * Los dos ejes. Sin esto no habia forma de sacar el balance FISCAL —el que
 * tiene que cuadrar contra SIIGO— desde ninguna pantalla: el libro diario si
 * filtraba y este no, asi que daban numeros distintos del mismo mes.
 */
const linea = ref(props.filtros.linea || 'todas');
const destino = ref(props.filtros.destino || 'todas');

const consultar = () => router.get('/app/contabilidad/estado-resultados', {
    linea: linea.value, destino: destino.value,
    desde: desde.value,
    hasta: hasta.value,
    incluir_anulados: incluirAnulados.value ? 1 : 0,
}, { preserveScroll: true, preserveState: true });

const pct = (v) => v === null ? '—' : `${v.toFixed(2)} %`;

const bloques = computed(() => [
    { titulo: 'Ingresos operacionales', datos: props.ingresos, signo: '+', color: 'text-emerald-700 dark:text-emerald-300' },
    { titulo: 'Costo de ventas', datos: props.costo_ventas, signo: '−', color: 'text-amber-700 dark:text-amber-300' },
    { titulo: 'Costos de producción', datos: props.costos_produccion, signo: '−', color: 'text-amber-700 dark:text-amber-300' },
    { titulo: 'Gastos de operación', datos: props.gastos, signo: '−', color: 'text-rose-700 dark:text-rose-300' },
].filter(b => b.datos.cuentas.length || b.datos.total !== 0));
</script>

<template>
    <Head title="Estado de resultados"/>
    <AppLayout>
        <div class="space-y-5 max-w-5xl">
            <PestanasSeccion :pestanas="pestanasReportes"/>

            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <TrendingUp class="h-6 w-6 text-brand-600"/> Estado de resultados
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Lo que entró y lo que costó, en el periodo que elijas.
                </p>
            </div>

            <div class="card p-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="desde" class="label">Desde</label>
                    <input id="desde" v-model="desde" type="date" class="input"/>
                </div>
                <div>
                    <label for="hasta" class="label">Hasta</label>
                    <input id="hasta" v-model="hasta" type="date" class="input"/>
                </div>
                <label class="inline-flex items-center gap-2 text-sm cursor-pointer min-h-[44px] sm:min-h-0">
                    <input v-model="incluirAnulados" type="checkbox"
                           class="h-5 w-5 rounded border-surface-300 text-brand-600 focus:ring-brand-500"/>
                    Incluir anulados
                </label>
                <!-- Los dos ejes: sin esto el balance FISCAL no se podia sacar de
                     ninguna pantalla, aunque es el que cuadra contra SIIGO. -->
                <FiltroEjes v-model:linea="linea" v-model:destino="destino" :opciones="opcionesEjes"/>
                <button @click="consultar" class="btn-primary min-h-[44px] sm:min-h-0">Ver resultado</button>
            </div>

            <div v-if="sin_datos" class="card p-8 text-center">
                <FileText class="h-10 w-10 mx-auto text-surface-300 mb-3"/>
                <p class="font-semibold">No hubo movimiento contable en ese rango.</p>
                <p class="text-sm text-surface-500 mt-1">Probá con un periodo más amplio.</p>
                <Link href="/app/contabilidad/reportes" class="btn-ghost mt-4 inline-flex">Volver a reportes</Link>
            </div>

            <template v-else>
                <!-- Resumen arriba: es lo que la gerencia mira primero. -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="card p-4">
                        <div class="text-xs uppercase tracking-wide text-surface-500">Ingresos</div>
                        <div class="text-xl font-bold font-mono tabular-nums mt-1">{{ money(ingresos.total) }}</div>
                    </div>
                    <div class="card p-4">
                        <div class="text-xs uppercase tracking-wide text-surface-500">Utilidad bruta</div>
                        <div class="text-xl font-bold font-mono tabular-nums mt-1"
                             :class="utilidad_bruta < 0 ? 'text-red-600' : ''">{{ money(utilidad_bruta) }}</div>
                        <div class="text-xs text-surface-500">margen {{ pct(margen_bruto) }}</div>
                    </div>
                    <div class="card p-4">
                        <div class="text-xs uppercase tracking-wide text-surface-500">Utilidad neta</div>
                        <div class="text-xl font-bold font-mono tabular-nums mt-1"
                             :class="utilidad_neta < 0 ? 'text-red-600' : 'text-emerald-600'">{{ money(utilidad_neta) }}</div>
                        <div class="text-xs text-surface-500">margen {{ pct(margen_neto) }}</div>
                    </div>
                </div>

                <div v-for="b in bloques" :key="b.titulo" class="card overflow-hidden">
                    <div class="px-5 py-3 border-b border-surface-200 dark:border-surface-800 flex items-center justify-between">
                        <h2 class="font-bold" :class="b.color">{{ b.signo }} {{ b.titulo }}</h2>
                        <span class="font-mono tabular-nums font-semibold">{{ money(b.datos.total) }}</span>
                    </div>
                    <table v-tabla-movil v-if="b.datos.cuentas.length" class="w-full text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900/60 text-xs uppercase text-surface-500">
                            <tr>
                                <th class="text-left px-5 py-2">Cuenta</th>
                                <th class="text-left px-5 py-2">Nombre</th>
                                <th class="text-right px-5 py-2">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in b.datos.cuentas" :key="c.codigo"
                                class="border-t border-surface-100 dark:border-surface-800">
                                <td class="px-5 py-2 font-mono">{{ c.codigo }}</td>
                                <td class="px-5 py-2">{{ c.nombre }}</td>
                                <td class="px-5 py-2 text-right font-mono tabular-nums">{{ money(c.saldo) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="card p-4 space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span>Utilidad bruta <span class="text-surface-500">(ingresos − costos)</span></span>
                        <span class="font-mono tabular-nums">{{ money(utilidad_bruta) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span>− Gastos de operación</span>
                        <span class="font-mono tabular-nums">{{ money(gastos.total) }}</span>
                    </div>
                    <div class="flex items-center justify-between font-bold pt-2 border-t border-surface-200 dark:border-surface-800">
                        <span>Utilidad neta del periodo</span>
                        <span class="font-mono tabular-nums"
                              :class="utilidad_neta < 0 ? 'text-red-600' : 'text-emerald-600'">{{ money(utilidad_neta) }}</span>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
