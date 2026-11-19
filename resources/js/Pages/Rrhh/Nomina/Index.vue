<script setup>
/*
 * La nómina del mes.
 *
 * Tres botones y tres manos distintas: Gestión Humana liquida, gerencia
 * aprueba y tesorería paga. Cada quien ve el suyo y nada más — quien calcula
 * lo que se paga no es quien autoriza pagarlo.
 *
 * Cada concepto se muestra con su fórmula en palabras, igual que en el cuadro
 * de novedades: el número solo obliga a creerle al sistema.
 */
import { ref, reactive, computed } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, router } from '@inertiajs/vue3';
import { Wallet, Download, Printer, AlertTriangle, RefreshCw, Check, Banknote } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

/*
 * La fecha de HOY en Colombia, no en UTC.
 *
 * `toISOString()` convierte a UTC antes de recortar, asi que entre las
 * 19:00 y la medianoche hora Bogota devuelve el dia siguiente. Las fechas
 * de esta pantalla quedan escritas en registros, no son un filtro: un dia
 * de corrimiento cambia lo que dice el documento.
 */
const { hoy: hoyBogota } = useBogotaDate();

const props = defineProps({
    periodo: { type: String, required: true },
    periodos: { type: Array, default: () => [] },
    nomina: { type: Object, default: null },
    por_liquidar: { type: Number, default: null },
    /*
     * Gente que califica para el período y no quedó en la nómina. Es el aviso
     * que antes era imposible de dar: `por_liquidar` se mandaba en null en
     * cuanto el período existía, así que la pantalla no podía señalar el hueco.
     */
    faltantes: { type: Array, default: () => [] },
    puedo: { type: Object, default: () => ({}) },
});

const procesando = ref(false);
const errores = ref({});
const abierta = ref(null);

const money = (v) => '$' + Number(v || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });

const periodoSel = ref(props.periodo);
const cambiarPeriodo = () => router.get('/app/rrhh/nomina', { periodo: periodoSel.value },
    { preserveState: true, replace: true });

const accion = (url, datos = {}) => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post(url, datos, {
        preserveScroll: true,
        onError: (e) => { errores.value = e; },
        onFinish: () => { procesando.value = false; },
    });
};

const liquidar = () => accion('/app/rrhh/nomina/liquidar', { periodo: props.periodo });
const aprobar = () => accion(`/app/rrhh/nomina/${props.nomina.id}/aprobar`);

const modalPago = ref(false);
const pago = reactive({ fecha_pago: hoyBogota() });
const pagar = () => {
    accion(`/app/rrhh/nomina/${props.nomina.id}/pagar`, { ...pago });
    modalPago.value = false;
};

const badge = (c) => ({
    success: 'bg-emerald-500/15 text-emerald-700',
    info: 'bg-blue-500/15 text-blue-700',
    warning: 'bg-amber-500/15 text-amber-800',
}[c] || 'bg-surface-200 text-surface-700');

const gente = computed(() => props.nomina?.lineas?.length ?? 0);
</script>

<template>
    <Head title="Nómina" />
    <AppLayout>
        <div class="space-y-5 max-w-[90rem]">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Wallet class="h-6 w-6 text-brand-600" />Nómina
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Lo que se le consigna a cada quien. La liquida Gestión Humana, la aprueba
                        gerencia y la paga tesorería.
                    </p>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <select v-model="periodoSel" @change="cambiarPeriodo" class="input text-sm">
                        <option v-for="p in periodos" :key="p.valor" :value="p.valor">{{ p.label }}</option>
                    </select>
                    <a v-if="nomina" :href="`/app/rrhh/nomina/${nomina.id}/exportar`" class="btn-secondary">
                        <Download class="h-4 w-4" /> Excel
                    </a>
                </div>
            </div>

            <!-- Todavía sin liquidar -->
            <div v-if="!nomina" class="card p-6 text-center space-y-3">
                <p class="text-sm text-surface-500">
                    Este mes todavía no se ha liquidado.
                    <template v-if="por_liquidar">
                        Entrarían <strong>{{ por_liquidar }}</strong>
                        {{ por_liquidar === 1 ? 'persona' : 'personas' }}.
                    </template>
                    <template v-else>No hay nadie vinculado en este mes.</template>
                </p>
                <button v-if="puedo.liquidar && por_liquidar" @click="liquidar" :disabled="procesando"
                    class="btn-primary">
                    <RefreshCw class="h-4 w-4" /> {{ procesando ? 'Liquidando…' : 'Liquidar el mes' }}
                </button>
                <p v-if="errores.periodo" class="text-sm text-rose-600">{{ errores.periodo }}</p>
            </div>

            <template v-else>
                <!-- Gente que califica para el mes y no quedó adentro -->
                <div v-if="faltantes.length"
                     class="card p-4 border-l-4 border-amber-500 bg-amber-50 dark:bg-amber-950/30">
                    <p class="font-semibold text-amber-900 dark:text-amber-200">
                        {{ faltantes.length === 1 ? 'Falta una persona' : `Faltan ${faltantes.length} personas` }}
                        en esta nómina
                    </p>
                    <p class="text-sm text-amber-800 dark:text-amber-300 mt-1">
                        Están vinculadas en el período y no tienen línea. Casi siempre es
                        que entraron después de liquidar.
                    </p>
                    <ul class="mt-2 space-y-0.5">
                        <li v-for="f in faltantes" :key="f.id"
                            class="text-sm text-amber-900 dark:text-amber-200">
                            &bull; <strong>{{ f.nombre }}</strong>
                            <span class="text-amber-700 dark:text-amber-400"> &middot; ingresó {{ f.ingreso }}</span>
                        </li>
                    </ul>
                    <p v-if="!nomina.se_puede_rehacer" class="text-sm text-amber-800 dark:text-amber-300 mt-2">
                        Esta nómina ya no se puede rehacer. Hay que resolverlo con Gerencia.
                    </p>
                </div>

                <!-- Estado y totales -->
                <div class="card p-4 space-y-3">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <div class="flex items-center gap-2">
                            <span :class="['px-2.5 py-1 rounded text-xs font-bold uppercase', badge(nomina.color)]">
                                {{ nomina.estado_label }}
                            </span>
                            <span class="text-sm text-surface-500">
                                {{ gente }} {{ gente === 1 ? 'persona' : 'personas' }} · {{ nomina.titulo }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <button v-if="puedo.liquidar && nomina.se_puede_rehacer" @click="liquidar"
                                :disabled="procesando" class="btn-secondary text-sm">
                                <RefreshCw class="h-4 w-4" /> Volver a liquidar
                            </button>
                            <button v-if="puedo.aprobar && nomina.estado === 'borrador'" @click="aprobar"
                                :disabled="procesando" class="btn-primary text-sm">
                                <Check class="h-4 w-4" /> Aprobar
                            </button>
                            <button v-if="puedo.pagar && nomina.estado === 'aprobada'" @click="modalPago = true"
                                :disabled="procesando" class="btn-primary text-sm">
                                <Banknote class="h-4 w-4" /> Marcar pagada
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                        <div class="p-3 rounded bg-surface-100 dark:bg-surface-800">
                            <div class="text-xs uppercase text-surface-500">Devengado</div>
                            <div class="font-bold text-lg">{{ money(nomina.total_devengado) }}</div>
                        </div>
                        <div class="p-3 rounded bg-surface-100 dark:bg-surface-800">
                            <div class="text-xs uppercase text-surface-500">Deducido</div>
                            <div class="font-bold text-lg text-rose-700">{{ money(nomina.total_deducido) }}</div>
                        </div>
                        <div class="p-3 rounded bg-amber-500/10 border-l-4 border-amber-500">
                            <div class="text-xs uppercase text-amber-800">Neto a pagar</div>
                            <div class="font-bold text-lg">{{ money(nomina.total_neto) }}</div>
                        </div>
                    </div>

                    <!--
                        Lo que pone la empresa encima del sueldo.

                        Los APORTES salen este mes a la PILA; las PROVISIONES
                        se guardan para cuando llegue la prima o las cesantías.
                        Separados a propósito: sumarlos en un número haría
                        creer que todo se paga ya.
                    -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                        <div class="p-3 rounded bg-surface-100 dark:bg-surface-800">
                            <div class="text-xs uppercase text-surface-500">Aportes de la empresa</div>
                            <div class="font-bold">{{ money(nomina.total_aportes) }}</div>
                            <div class="text-[11px] text-surface-400">salen este mes a la PILA</div>
                        </div>
                        <div class="p-3 rounded bg-surface-100 dark:bg-surface-800">
                            <div class="text-xs uppercase text-surface-500">Provisiones</div>
                            <div class="font-bold">{{ money(nomina.total_provisiones) }}</div>
                            <div class="text-[11px] text-surface-400">se guardan para prima y cesantías</div>
                        </div>
                        <div class="p-3 rounded bg-brand-500/10 border-l-4 border-brand-500">
                            <div class="text-xs uppercase text-surface-600">Costo total del mes</div>
                            <div class="font-bold text-lg">{{ money(nomina.total_costo_empresa) }}</div>
                            <div class="text-[11px] text-surface-500">neto + aportes + provisiones</div>
                        </div>
                    </div>

                    <div class="text-xs text-surface-500 space-y-0.5 pt-2 border-t">
                        <div v-if="nomina.liquidada">Liquidada {{ nomina.liquidada }} · {{ nomina.liquidada_por }}</div>
                        <div v-if="nomina.aprobada">Aprobada {{ nomina.aprobada }} · {{ nomina.aprobada_por }}</div>
                        <div v-if="nomina.pagada">
                            Pagada {{ nomina.pagada }} · {{ nomina.pagada_por }}
                            <span v-if="nomina.fecha_pago"> · con fecha {{ nomina.fecha_pago }}</span>
                        </div>
                    </div>
                </div>

                <!--
                    El control son tres decisiones de tres personas. Si una
                    sola las hizo todas, el papel afirma tres revisiones donde
                    hubo una: se dice en vez de disimularlo.
                -->
                <div v-if="nomina.manos_repetidas?.length"
                    class="p-3 rounded bg-amber-500/10 border-l-4 border-amber-500 text-sm text-amber-900">
                    <strong>{{ nomina.manos_repetidas.join(', ') }}</strong>
                    hizo más de una de las tres: liquidar, aprobar y pagar son decisiones separadas
                    a propósito.
                </div>

                <div v-if="nomina.en_cero.length"
                    class="p-3 rounded bg-amber-500/10 border-l-4 border-amber-500 text-sm text-amber-900 flex gap-2">
                    <AlertTriangle class="h-5 w-5 shrink-0" />
                    <span>
                        <strong>{{ nomina.en_cero.join(', ') }}</strong>
                        {{ nomina.en_cero.length === 1 ? 'queda' : 'quedan' }} en cero.
                        Casi siempre es una ficha sin salario. No se puede aprobar así:
                        arreglá la ficha y volvé a liquidar.
                    </span>
                </div>

                <p v-if="errores.aprobar" class="text-sm text-rose-600">{{ errores.aprobar }}</p>

                <!-- El detalle, persona por persona -->
                <div class="card p-0 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase text-surface-400 border-b">
                                <th class="p-3">Colaborador</th>
                                <th class="p-3 w-16 text-right">Días</th>
                                <th class="p-3 w-32 text-right">Devengado</th>
                                <th class="p-3 w-32 text-right">Deducido</th>
                                <th class="p-3 w-32 text-right">Neto</th>
                                <th class="p-3 w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="l in nomina.lineas" :key="l.id">
                                <tr class="border-b cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800"
                                    @click="abierta = abierta === l.id ? null : l.id">
                                    <td class="p-3">
                                        <div class="font-semibold">{{ l.nombre }}</div>
                                        <div class="text-xs text-surface-500">
                                            {{ l.documento || 'sin documento' }}<span v-if="l.cargo"> · {{ l.cargo }}</span>
                                        </div>
                                    </td>
                                    <td class="p-3 text-right">{{ l.dias }}</td>
                                    <td class="p-3 text-right">{{ money(l.devengado) }}</td>
                                    <td class="p-3 text-right text-rose-700">{{ money(l.deducido) }}</td>
                                    <td class="p-3 text-right font-bold"
                                        :class="l.neto <= 0 ? 'text-amber-700' : ''">{{ money(l.neto) }}</td>
                                    <td class="p-3 text-right">
                                        <a :href="`/app/rrhh/nomina/linea/${l.id}/desprendible`" target="_blank"
                                            @click.stop class="text-surface-400 hover:text-brand-600"
                                            title="Desprendible de pago">
                                            <Printer class="h-4 w-4" />
                                        </a>
                                    </td>
                                </tr>
                                <!-- La cuenta, para no tener que creerle al sistema -->
                                <tr v-if="abierta === l.id" class="border-b bg-surface-50 dark:bg-surface-900">
                                    <td colspan="6" class="p-3">
                                        <div v-for="(c, i) in l.conceptos" :key="i"
                                            class="flex justify-between gap-4 py-1 text-xs border-b last:border-0">
                                            <div>
                                                <span class="font-semibold">{{ c.concepto }}</span>
                                                <div v-if="c.formula" class="text-surface-500">{{ c.formula }}</div>
                                            </div>
                                            <div :class="['font-mono shrink-0', c.resta ? 'text-rose-700' : '']">
                                                {{ c.resta ? '−' : '+' }} {{ money(c.valor) }}
                                            </div>
                                        </div>

                                        <!-- Lo que pone la empresa por esta persona -->
                                        <div class="grid sm:grid-cols-2 gap-4 mt-3 pt-3 border-t">
                                            <div>
                                                <div class="text-[11px] uppercase text-surface-500 font-semibold mb-1">
                                                    Aportes de la empresa
                                                </div>
                                                <div v-for="(a, i) in l.empresa.aportes" :key="i"
                                                    class="flex justify-between gap-3 text-xs py-0.5">
                                                    <span>
                                                        {{ a.concepto }}
                                                        <div v-if="a.formula" class="text-surface-500">{{ a.formula }}</div>
                                                    </span>
                                                    <span class="font-mono shrink-0">{{ money(a.valor) }}</span>
                                                </div>
                                                <p v-if="l.empresa.exoneracion" class="text-[11px] text-surface-500 mt-1">
                                                    {{ l.empresa.exoneracion }}
                                                </p>
                                            </div>
                                            <div>
                                                <div class="text-[11px] uppercase text-surface-500 font-semibold mb-1">
                                                    Provisiones
                                                </div>
                                                <div v-for="(a, i) in l.empresa.provisiones" :key="i"
                                                    class="flex justify-between gap-3 text-xs py-0.5">
                                                    <span>{{ a.concepto }}</span>
                                                    <span class="font-mono shrink-0">{{ money(a.valor) }}</span>
                                                </div>
                                                <p v-if="l.empresa.nota_provisiones" class="text-[11px] text-surface-500 mt-1">
                                                    {{ l.empresa.nota_provisiones }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="flex justify-between gap-3 mt-2 pt-2 border-t text-xs font-bold">
                                            <span>Lo que cuesta esta persona</span>
                                            <span>{{ money(l.costo_empresa) }}</span>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>

        <!-- Marcar pagada -->
        <div v-if="modalPago" @click.self="modalPago = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full space-y-3">
                <h3 class="text-lg font-bold">Marcar la nómina como pagada</h3>
                <p class="text-xs text-surface-500">
                    Con qué fecha salió la consignación. Después de esto la nómina no se vuelve a tocar.
                </p>
                <div>
                    <label class="text-xs uppercase text-surface-500">Fecha de pago</label>
                    <input v-model="pago.fecha_pago" type="date" class="input w-full mt-1" />
                    <p v-if="errores.fecha_pago" class="text-xs text-rose-600 mt-1">{{ errores.fecha_pago }}</p>
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <button @click="modalPago = false" class="btn-secondary">Cancelar</button>
                    <button @click="pagar" :disabled="procesando" class="btn-primary">Confirmar pago</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
