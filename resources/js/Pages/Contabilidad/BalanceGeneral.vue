<script setup>
/**
 * Balance general · la foto de la empresa a una fecha.
 *
 * El hub de reportes lo listaba como «no listo» desde que existe el módulo.
 * A diferencia del balance de comprobación, que muestra el movimiento de un
 * mes, éste acumula desde el primer asiento: el activo de hoy es todo lo que
 * se tiene hoy, no lo que entró en octubre.
 *
 * La utilidad del ejercicio no vive en ninguna cuenta: sale de restarle a los
 * ingresos los costos y gastos, y se suma al patrimonio. Por eso aparece como
 * una línea aparte dentro del patrimonio y no como una cuenta más.
 */
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Scale, AlertTriangle, FileText } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import FiltroEjes from '@/Components/FiltroEjes.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasReportes } from '@/composables/usePestanasContables';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    filtros: { type: Object, required: true },
    opcionesEjes: { type: Object, default: () => ({ lineas: [], destinos: [] }) },
    activo: { type: Object, required: true },
    pasivo: { type: Object, required: true },
    patrimonio: { type: Object, required: true },
    resultado_ejercicio: { type: Number, required: true },
    total_patrimonio: { type: Number, required: true },
    total_pasivo_patrimonio: { type: Number, required: true },
    descuadre: { type: Number, required: true },
    cuadra: { type: Boolean, required: true },
    sin_datos: { type: Boolean, default: false },
});

const { money } = useMoney();
const hasta = ref(props.filtros.hasta);
const incluirAnulados = ref(!!props.filtros.incluir_anulados);

/*
 * Los dos ejes. Sin esto no habia forma de sacar el balance FISCAL —el que
 * tiene que cuadrar contra SIIGO— desde ninguna pantalla: el libro diario si
 * filtraba y este no, asi que daban numeros distintos del mismo mes.
 */
const linea = ref(props.filtros.linea || 'todas');
const destino = ref(props.filtros.destino || 'todas');

const consultar = () => router.get('/app/contabilidad/balance-general', {
    linea: linea.value, destino: destino.value,
    hasta: hasta.value,
    incluir_anulados: incluirAnulados.value ? 1 : 0,
}, { preserveScroll: true, preserveState: true });

const bloques = computed(() => [
    { clave: 'activo', titulo: 'Activo', datos: props.activo, color: 'text-sky-700 dark:text-sky-300' },
    { clave: 'pasivo', titulo: 'Pasivo', datos: props.pasivo, color: 'text-amber-700 dark:text-amber-300' },
    { clave: 'patrimonio', titulo: 'Patrimonio', datos: props.patrimonio, color: 'text-emerald-700 dark:text-emerald-300' },
]);
</script>

<template>
    <Head title="Balance general"/>
    <AppLayout>
        <div class="space-y-5 max-w-5xl">
            <PestanasSeccion :pestanas="pestanasReportes"/>

            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Scale class="h-6 w-6 text-brand-600"/> Balance general
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Acumulado desde el primer asiento hasta la fecha que elijas · Activo = Pasivo + Patrimonio.
                </p>
            </div>

            <!-- Filtros -->
            <div class="card p-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="hasta" class="label">Corte a</label>
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
                <button @click="consultar" class="btn-primary min-h-[44px] sm:min-h-0">Ver balance</button>
            </div>

            <!-- Semáforo de la ecuación contable -->
            <div v-if="! sin_datos"
                 :class="['card p-4 flex items-start gap-3 border-l-4',
                          cuadra ? 'border-l-emerald-500' : 'border-l-red-500']">
                <AlertTriangle v-if="! cuadra" class="h-5 w-5 text-red-600 shrink-0 mt-0.5"/>
                <Scale v-else class="h-5 w-5 text-emerald-600 shrink-0 mt-0.5"/>
                <div class="text-sm">
                    <p class="font-semibold" :class="cuadra ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'">
                        {{ cuadra ? 'La ecuación cuadra' : 'El balance NO cuadra' }}
                    </p>
                    <p class="text-surface-600 dark:text-surface-400 mt-0.5">
                        Activo {{ money(activo.total) }} · Pasivo + Patrimonio {{ money(total_pasivo_patrimonio) }}
                        <span v-if="! cuadra"> · diferencia <strong>{{ money(descuadre) }}</strong></span>
                    </p>
                    <p v-if="! cuadra" class="text-surface-500 mt-1">
                        Una diferencia acá significa que hay asientos con una sola pata o cuentas
                        fuera del plan. Revisá el Libro diario del periodo donde aparece.
                    </p>
                </div>
            </div>

            <div v-if="sin_datos" class="card p-8 text-center">
                <FileText class="h-10 w-10 mx-auto text-surface-300 mb-3"/>
                <p class="font-semibold">Todavía no hay asientos contables hasta esa fecha.</p>
                <p class="text-sm text-surface-500 mt-1">
                    El balance se arma solo a medida que se emiten facturas, pagos y recepciones.
                </p>
                <Link href="/app/contabilidad/reportes" class="btn-ghost mt-4 inline-flex">Volver a reportes</Link>
            </div>

            <!-- Bloques -->
            <div v-for="b in bloques" :key="b.clave" class="card overflow-hidden">
                <div class="px-5 py-3 border-b border-surface-200 dark:border-surface-800 flex items-center justify-between">
                    <h2 class="font-bold" :class="b.color">{{ b.titulo }}</h2>
                    <span class="font-mono tabular-nums font-semibold">{{ money(b.datos.total) }}</span>
                </div>

                <table v-tabla-movil v-if="b.datos.cuentas.length" class="w-full text-sm">
                    <thead class="bg-surface-50 dark:bg-surface-900/60 text-xs uppercase text-surface-500">
                        <tr>
                            <th class="text-left px-5 py-2">Cuenta</th>
                            <th class="text-left px-5 py-2">Nombre</th>
                            <th class="text-right px-5 py-2">Saldo</th>
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
                <p v-else class="px-5 py-4 text-sm text-surface-500">Sin saldo en esta clase.</p>

                <!-- La utilidad del ejercicio va dentro del patrimonio, sin ser una cuenta. -->
                <div v-if="b.clave === 'patrimonio'"
                     class="px-5 py-3 border-t border-surface-200 dark:border-surface-800 space-y-1">
                    <div class="flex items-center justify-between text-sm">
                        <span>Resultado del ejercicio
                            <span class="text-surface-500">(ingresos − costos − gastos)</span>
                        </span>
                        <span class="font-mono tabular-nums"
                              :class="resultado_ejercicio < 0 ? 'text-red-600' : 'text-emerald-600'">
                            {{ money(resultado_ejercicio) }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between font-semibold pt-1 border-t border-surface-100 dark:border-surface-800">
                        <span>Total patrimonio</span>
                        <span class="font-mono tabular-nums">{{ money(total_patrimonio) }}</span>
                    </div>
                </div>
            </div>

            <div v-if="! sin_datos" class="card p-4 flex items-center justify-between font-bold">
                <span>Pasivo + Patrimonio</span>
                <span class="font-mono tabular-nums">{{ money(total_pasivo_patrimonio) }}</span>
            </div>
        </div>
    </AppLayout>
</template>
