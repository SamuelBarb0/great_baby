<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useMoney } from '@/composables/useMoney';
import { BookOpen } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';

import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import FiltroEjes from '@/Components/FiltroEjes.vue';
import { pestanasLibro } from '@/composables/usePestanasContables';
const props = defineProps({
    movimientos: { type: Object, required: true },
    filtros: { type: Object, required: true },
    opcionesEjes: { type: Object, default: () => ({ lineas: [], destinos: [] }) },
    // H8 · totales calculados en el backend sobre TODO el rango filtrado,
    // no sólo la página actual. Sin esto la conciliación mensual era imposible.
    totales: { type: Object, default: () => ({ suma_debe: 0, suma_haber: 0, diferencia: 0, total_filas: 0 }) },
});

const desde = ref(props.filtros.desde);
const hasta = ref(props.filtros.hasta);
const cuenta = ref(props.filtros.cuenta || '');
const linea = ref(props.filtros.linea || 'todas');
const destino = ref(props.filtros.destino || 'todas');

const parametros = () => ({
    desde: desde.value, hasta: hasta.value, cuenta: cuenta.value || null,
    cuentas: props.filtros.cuentas || null,
    incluir_anulados: props.filtros.incluir_anulados ? 1 : null,
    linea: linea.value, destino: destino.value,
});

const filtrar = () => router.get('/app/cartera/movimientos', parametros(), { preserveState: true });

// Los ejes filtran al instante: son dos opciones, no hay nada que terminar de
// escribir. Las fechas y la cuenta siguen esperando el boton.
watch([linea, destino], filtrar);

const { money } = useMoney();

// H8 · usar totales de backend (todo el rango), no de la página.
const totalDebe = computed(() => props.totales.suma_debe);
const totalHaber = computed(() => props.totales.suma_haber);
const diferencia = computed(() => props.totales.diferencia);

const badge = (color) => ({
    success: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
}[color] || 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200');
</script>

<template>
    <Head title="Movimientos contables"/>
    <AppLayout>
        <div class="space-y-4">
            <PestanasSeccion :pestanas="pestanasLibro"/>

            <h1 class="text-2xl font-bold flex items-center gap-2"><BookOpen class="h-6 w-6 text-brand-600"/>Movimientos contables</h1>

            <div class="card p-3 flex items-center gap-2 flex-wrap">
                <div><label class="text-xs">Desde</label><input type="date" v-model="desde" class="input"/></div>
                <div><label class="text-xs">Hasta</label><input type="date" v-model="hasta" class="input"/></div>
                <div class="flex-1 min-w-[160px]"><label class="text-xs">Cuenta PUC (prefijo)</label><input v-model="cuenta" placeholder="13, 1305, 130505..." class="input w-full font-mono"/></div>
                <button @click="filtrar" class="btn-primary self-end">Filtrar</button>
                <FiltroEjes v-model:linea="linea" v-model:destino="destino" :opciones="opcionesEjes"
                            url-export="/app/cartera/movimientos/csv" :params-export="parametros()"/>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="card p-3"><div class="text-xs text-surface-500">Total débito (rango)</div><div class="text-xl font-bold">{{ money(totalDebe) }}</div></div>
                <div class="card p-3"><div class="text-xs text-surface-500">Total crédito (rango)</div><div class="text-xl font-bold">{{ money(totalHaber) }}</div></div>
                <div class="card p-3" :class="Math.abs(diferencia) > 0.01 ? 'ring-2 ring-red-500' : ''">
                    <div class="text-xs text-surface-500">Diferencia</div>
                    <div class="text-xl font-bold" :class="Math.abs(diferencia) > 0.01 ? 'text-red-600' : 'text-emerald-600'">{{ money(diferencia) }}</div>
                </div>
                <div class="card p-3">
                    <div class="text-xs text-surface-500">Filas en el rango</div>
                    <div class="text-xl font-bold">{{ totales.total_filas.toLocaleString('es-CO') }}</div>
                    <div class="text-[10px] text-surface-400 mt-0.5">mostrando {{ movimientos.data.length }} de esta página</div>
                </div>
            </div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil data-vacia="No hubo movimiento contable en ese rango de fechas." class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-2">Fecha</th>
                            <th class="text-left p-2">Cuenta</th>
                            <th class="text-right p-2">Débito</th>
                            <th class="text-right p-2">Crédito</th>
                            <th class="text-center p-2">Destino</th>
                            <th class="text-left p-2">Descripción</th>
                            <th class="text-left p-2">Origen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="m in movimientos.data" :key="m.id" class="hover:bg-surface-50">
                            <td class="p-2 text-xs">{{ m.fecha }}</td>
                            <td class="p-2 font-mono font-bold">{{ m.cuenta }}</td>
                            <td class="p-2 text-right" :class="m.debe > 0 ? 'text-emerald-600 font-bold' : 'text-surface-300'">{{ m.debe > 0 ? money(m.debe) : '—' }}</td>
                            <td class="p-2 text-right" :class="m.haber > 0 ? 'text-red-600 font-bold' : 'text-surface-300'">{{ m.haber > 0 ? money(m.haber) : '—' }}</td>
                            <td class="p-2 text-center whitespace-nowrap">
                                <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold', badge(m.destino_color)]">
                                    {{ m.destino }}
                                </span>
                                <div class="text-[10px] text-surface-400">{{ m.linea }}</div>
                            </td>
                            <td class="p-2 text-xs">{{ m.descripcion }}</td>
                            <td class="p-2 text-xs font-mono">{{ m.origen }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Paginacion :pagina="movimientos" etiqueta="movimientos"/>
        </div>
    </AppLayout>
</template>
