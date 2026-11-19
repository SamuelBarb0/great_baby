<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { CalendarCheck, Check, Ban } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import { useBogotaDate } from '@/composables/useBogotaDate';

/*
 * La fecha de HOY en Colombia, no en UTC.
 *
 * `toISOString()` convierte a UTC antes de recortar, así que entre las 19:00
 * y la medianoche hora Bogotá devuelve el día siguiente. Acá eso programaría
 * el desembolso para mañana sin que nadie lo pidiera.
 */
const { hoy: hoyBogota } = useBogotaDate();

const props = defineProps({
    porPagar: { type: Array, required: true },
    programaciones: { type: Array, required: true },
    puedeAutorizar: { type: Boolean, default: false },
});

const money = (n) => '$' + Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });

const modalConfirm = ref(null);

/* ─────────────── Armar una programación nueva ─────────────── */

/*
 * El monto por factura arranca en su saldo completo, que es el caso normal.
 * Bajarlo es el abono parcial que en la hoja va escrito al margen («ARSUTEX
 * tiene un abono de 20.000.000»).
 */
const elegidas = reactive({});
const montos = reactive({});

const alternar = (f) => {
    if (elegidas[f.recepcion_id]) {
        delete elegidas[f.recepcion_id];
        delete montos[f.recepcion_id];
        return;
    }
    elegidas[f.recepcion_id] = true;
    montos[f.recepcion_id] = f.saldo;
};

const seleccion = computed(() =>
    props.porPagar.filter(f => elegidas[f.recepcion_id]));

const totalSeleccion = computed(() =>
    seleccion.value.reduce((s, f) => s + (Number(montos[f.recepcion_id]) || 0), 0));

const excedidas = computed(() =>
    seleccion.value.filter(f => (Number(montos[f.recepcion_id]) || 0) > f.saldo + 0.01));

const nueva = reactive({
    fecha_programada: hoyBogota(),
    monto_autorizado: null,
    notas: '',
});

const sinRepartir = computed(() => {
    const aut = Number(nueva.monto_autorizado);
    if (! aut) return 0;
    return Math.round((aut - totalSeleccion.value) * 100) / 100;
});

const puedeGuardar = computed(() =>
    seleccion.value.length > 0 && ! excedidas.value.length && !! nueva.fecha_programada);

const procesando = ref(false);

const guardar = () => {
    if (! puedeGuardar.value || procesando.value) return;
    procesando.value = true;
    router.post('/app/compras/programacion-pagos', {
        ...nueva,
        items: seleccion.value.map(f => ({ recepcion_id: f.recepcion_id, monto: montos[f.recepcion_id] })),
    }, {
        preserveScroll: true,
        onSuccess: () => {
            Object.keys(elegidas).forEach(k => delete elegidas[k]);
            Object.keys(montos).forEach(k => delete montos[k]);
            nueva.monto_autorizado = null;
            nueva.notas = '';
        },
        onFinish: () => { procesando.value = false; },
    });
};

/* ─────────────── Autorizar y anular ─────────────── */

const autorizar = (p) => {
    modalConfirm.value = {
        titulo: `¿Autorizar ${p.numero}?`,
        mensaje: `Se autoriza el desembolso de ${money(p.total)} sobre ${p.items.length} factura(s).\n`
            + 'Queda tu nombre y la hora: es la firma que hoy va a lápiz al margen de la hoja.',
        color: 'emerald',
        textoConfirmar: 'Autorizar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.post(`/app/compras/programacion-pagos/${p.id}/autorizar`, {}, { preserveScroll: true });
        },
    };
};

const anular = (p) => {
    modalConfirm.value = {
        titulo: `¿Anular ${p.numero}?`,
        mensaje: 'Deja de contar para el flujo de caja. Queda en la lista con el motivo.',
        color: 'rose',
        textoConfirmar: 'Anular',
        pedirMotivo: { etiqueta: '¿Por qué se anula?', minimo: 10 },
        onConfirmar: (motivo) => {
            modalConfirm.value = null;
            router.post(`/app/compras/programacion-pagos/${p.id}/anular`, { motivo }, { preserveScroll: true });
        },
    };
};

const badge = (e) => ({
    borrador: 'bg-surface-500/20 text-surface-700',
    autorizada: 'bg-emerald-500/20 text-emerald-700',
    ejecutada: 'bg-blue-500/20 text-blue-700',
    anulada: 'bg-rose-500/20 text-rose-700 line-through',
}[e] ?? 'bg-surface-500/20');
</script>

<template>
    <Head title="Programación de pagos"/>
    <AppLayout>
        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <CalendarCheck class="h-6 w-6 text-brand-600"/> Programación de pagos
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Qué facturas se pagan, cuándo, y con la firma de quién. Reemplaza la hoja impresa que se marca con
                    resaltador y se autoriza a lápiz al margen.
                </p>
            </div>

            <div v-if="$page.props.flash?.message" class="p-3 rounded-lg text-sm"
                 :class="$page.props.flash.type === 'error'
                    ? 'bg-red-500/15 border-l-4 border-red-500 text-red-700'
                    : 'bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700'">
                {{ $page.props.flash.message }}
            </div>

            <!-- Elegir facturas -->
            <div class="card overflow-hidden">
                <div class="p-3 border-b flex justify-between items-center flex-wrap gap-2">
                    <div class="text-xs uppercase font-bold text-brand-600">
                        Facturas por pagar
                        <span class="normal-case font-normal text-surface-500">
                            · {{ seleccion.length }} elegida(s) por {{ money(totalSeleccion) }}
                        </span>
                    </div>
                </div>

                <table class="w-full text-sm">
                    <thead class="bg-surface-50 dark:bg-surface-900 text-[11px] uppercase text-surface-500">
                        <tr>
                            <th class="p-2 w-8"></th>
                            <th class="p-2 text-left">Proveedor</th>
                            <th class="p-2 text-left">Factura</th>
                            <th class="p-2 text-left">Vence</th>
                            <th class="p-2 text-right">Saldo</th>
                            <th class="p-2 text-right">A pagar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="f in porPagar" :key="f.recepcion_id"
                            :class="elegidas[f.recepcion_id] ? 'bg-amber-500/10' : ''">
                            <td class="p-2">
                                <input type="checkbox" :checked="!!elegidas[f.recepcion_id]"
                                       @change="alternar(f)" class="rounded"/>
                            </td>
                            <td class="p-2">
                                {{ f.proveedor }}
                                <span class="block text-[11px] text-surface-500">{{ f.numero }}</span>
                            </td>
                            <td class="p-2 font-mono text-xs">{{ f.factura ?? '—' }}</td>
                            <td class="p-2 text-xs">
                                {{ f.vencimiento }}
                                <span v-if="f.dias_mora > 0" class="block text-[11px] text-red-600">
                                    {{ f.dias_mora }} día(s) de mora
                                </span>
                            </td>
                            <td class="p-2 text-right font-mono">{{ money(f.saldo) }}</td>
                            <td class="p-2 text-right">
                                <input v-if="elegidas[f.recepcion_id]" v-model="montos[f.recepcion_id]"
                                       type="number" min="1" :max="f.saldo"
                                       class="input w-32 font-mono text-right text-sm"
                                       :class="(Number(montos[f.recepcion_id]) || 0) > f.saldo + 0.01 ? 'border-rose-500' : ''"/>
                                <span v-else class="text-surface-400">—</span>
                            </td>
                        </tr>
                        <tr v-if="!porPagar.length">
                            <td colspan="6" class="p-6 text-center text-surface-500 text-sm">
                                No hay facturas de proveedor pendientes.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Armar la programación -->
            <div v-if="seleccion.length" class="card p-3 space-y-3">
                <div class="text-xs uppercase font-bold text-brand-600">Programar el desembolso</div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-surface-600 mb-1">¿Para cuándo?</label>
                        <input v-model="nueva.fecha_programada" type="date" class="input w-full"/>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-surface-600 mb-1">Monto autorizado (opcional)</label>
                        <input v-model="nueva.monto_autorizado" type="number" min="0" class="input w-full font-mono"
                               placeholder="22000000"/>
                        <p class="text-[11px] text-surface-500 mt-1">
                            La cifra redonda que autoriza gerencia, antes de repartirla.
                        </p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-surface-600 mb-1">Notas</label>
                        <input v-model="nueva.notas" maxlength="1000" class="input w-full"
                               placeholder="Pago semana del 27…"/>
                    </div>
                </div>

                <div class="flex items-center gap-4 flex-wrap text-sm pt-2 border-t">
                    <span>Suma de lo elegido: <strong class="font-mono">{{ money(totalSeleccion) }}</strong></span>
                    <span v-if="nueva.monto_autorizado" :class="sinRepartir === 0 ? 'text-emerald-600' : 'text-amber-600'">
                        <template v-if="sinRepartir > 0">Quedan {{ money(sinRepartir) }} autorizados sin repartir</template>
                        <template v-else-if="sinRepartir < 0">Se pasa {{ money(-sinRepartir) }} de lo autorizado</template>
                        <template v-else>Coincide con lo autorizado</template>
                    </span>

                    <div class="ml-auto flex items-center gap-2">
                        <p v-if="excedidas.length" class="text-xs text-rose-600">
                            Hay {{ excedidas.length }} factura(s) con un monto mayor al saldo.
                        </p>
                        <button @click="guardar" :disabled="!puedeGuardar || procesando" class="btn-primary text-sm">
                            {{ procesando ? 'Guardando…' : 'Crear programación' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Las programaciones -->
            <div class="card overflow-hidden">
                <div class="p-3 border-b text-xs uppercase font-bold text-brand-600">Programaciones</div>
                <table class="w-full text-sm">
                    <thead class="bg-surface-50 dark:bg-surface-900 text-[11px] uppercase text-surface-500">
                        <tr>
                            <th class="p-2 text-left">Número</th>
                            <th class="p-2 text-left">Para</th>
                            <th class="p-2 text-right">Total</th>
                            <th class="p-2 text-left">Estado</th>
                            <th class="p-2 text-left">Autorizó</th>
                            <th class="p-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="p in programaciones" :key="p.id">
                            <td class="p-2 font-mono font-semibold">
                                {{ p.numero }}
                                <span class="block text-[11px] font-normal text-surface-500">
                                    {{ p.items.length }} factura(s) · armó {{ p.creada_por }}
                                </span>
                            </td>
                            <td class="p-2 text-xs">{{ p.fecha_programada }}</td>
                            <td class="p-2 text-right font-mono font-bold">
                                {{ money(p.total) }}
                                <span v-if="p.sin_repartir" class="block text-[11px] font-normal text-amber-600">
                                    {{ money(p.monto_autorizado) }} autorizados
                                </span>
                            </td>
                            <td class="p-2">
                                <span class="text-[10px] px-2 py-0.5 rounded uppercase font-semibold" :class="badge(p.estado)">
                                    {{ p.estado }}
                                </span>
                            </td>
                            <td class="p-2 text-xs">
                                <template v-if="p.autorizada_por">
                                    {{ p.autorizada_por }}
                                    <span class="block text-[11px] text-surface-500">{{ p.autorizada_at }}</span>
                                </template>
                                <span v-else class="text-surface-400">sin autorizar</span>
                            </td>
                            <td class="p-2 text-right whitespace-nowrap">
                                <button v-if="puedeAutorizar && p.estado === 'borrador'" @click="autorizar(p)"
                                        class="text-emerald-600 p-1" title="Autorizar el desembolso">
                                    <Check class="h-4 w-4"/>
                                </button>
                                <button v-if="p.estado !== 'ejecutada' && p.estado !== 'anulada'" @click="anular(p)"
                                        class="text-rose-600 p-1" title="Anular">
                                    <Ban class="h-4 w-4"/>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!programaciones.length">
                            <td colspan="6" class="p-6 text-center text-surface-500 text-sm">
                                Todavía no hay programaciones. Elegí facturas arriba para armar la primera.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
