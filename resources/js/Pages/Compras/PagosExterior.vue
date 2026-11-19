<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Banknote, CalendarClock } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { fechaCorta } from '@/composables/useFecha';

/*
 * Control de vencimientos con China. No registra el pago contable: eso sigue en
 * Pagos a proveedor.
 */
const props = defineProps({
    filas: { type: Array, required: true },
    resumen: { type: Object, required: true },
    proyeccion: { type: Object, required: true },
    hay_tramos: Boolean,
});

const { hoy } = useBogotaDate();
const money = (n) => (n === null || n === undefined ? '—' : '$' + Number(n).toLocaleString('es-CO', { maximumFractionDigits: 0 }));
const num = (n) => Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 2 });

const tono = {
    vencida: 'bg-red-600 text-white',
    rojo: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    naranja: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-200',
    al_dia: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    sin_fecha: 'bg-surface-200 text-surface-700 dark:bg-surface-800 dark:text-surface-300',
};
const textoDias = (f) => {
    if (f.dias === null) return 'Sin fecha todavía';
    if (f.dias > 0) return `Vencida hace ${f.dias} día${f.dias === 1 ? '' : 's'}`;
    if (f.dias === 0) return 'Vence hoy';
    return `Faltan ${-f.dias} día${f.dias === -1 ? '' : 's'}`;
};

// Una sola acción abierta a la vez, para que la tabla no se llene de formularios.
const abierta = ref(null);
const form = reactive({ nota: '', fecha: '' });
const enviando = ref(false);
const abrir = (f, tipo) => {
    abierta.value = { id: f.id, tipo };
    form.nota = '';
    form.fecha = hoy();
};
const enviar = (url, datos, alTerminar) => {
    if (enviando.value) return;
    enviando.value = true;
    router.post(url, datos, {
        preserveScroll: true,
        onSuccess: alTerminar,
        onFinish: () => { enviando.value = false; },
    });
};
const guardarSeguimiento = (f) => enviar(`/app/compras/pagos-exterior/${f.id}/seguimiento`, { nota: form.nota }, () => { abierta.value = null; });
const guardarPagado = (f) => enviar(`/app/compras/pagos-exterior/${f.id}/pagado`, { fecha: form.fecha, nota: form.nota }, () => { abierta.value = null; });

const presets = {
    zarpe90: [{ concepto: 'Pago total', porcentaje: 100, base: 'zarpe', dias: 90 }],
    fabrica: [
        { concepto: 'Anticipo 30 %', porcentaje: 30, base: 'emision', dias: 0 },
        { concepto: 'Saldo 70 %', porcentaje: 70, base: 'fabricacion', dias: 0 },
    ],
    antes_llegar: [
        { concepto: 'Anticipo 30 %', porcentaje: 30, base: 'emision', dias: 0 },
        { concepto: 'Saldo 70 %', porcentaje: 70, base: 'zarpe', dias: 15 },
    ],
};
const aplicarPlan = (f, preset) => enviar(`/app/compras/orden/${f.orden_id}/plan-pago`, { cuotas: presets[preset] }, () => { abierta.value = null; });

const hayFilas = computed(() => props.filas.length > 0);
</script>

<template>
    <Head title="Pagos al exterior · Compras"/>
    <AppLayout>
        <div class="max-w-6xl mx-auto space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2"><Banknote class="h-6 w-6 text-brand-600"/> Pagos al exterior</h1>
                <p class="text-sm text-surface-500 mt-1">Qué hay que pagarle a China y cuándo. El pago contable se sigue registrando en Pagos a proveedor.</p>
            </div>

            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-sm">{{ $page.props.flash.error }}</div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="card p-4" :class="resumen.vencido > 0 ? 'ring-2 ring-red-500' : ''">
                    <div class="text-xs uppercase text-surface-500">Vencido</div>
                    <div class="text-xl font-bold mt-1" :class="resumen.vencido > 0 ? 'text-red-600' : ''">{{ money(resumen.vencido) }}</div>
                </div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Próximos 10 días</div><div class="text-xl font-bold mt-1">{{ money(resumen.en_10_dias) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Próximos 30 días</div><div class="text-xl font-bold mt-1">{{ money(resumen.en_30_dias) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Total por pagar</div><div class="text-xl font-bold mt-1">{{ money(resumen.total) }}</div></div>
            </div>

            <div class="card overflow-hidden">
                <div v-if="!hayFilas" class="p-8 text-center text-surface-500 text-sm">No hay pagos pendientes. Aparecen aquí cuando se aprueba una orden de importación.</div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                            <tr>
                                <th class="text-left p-2">Orden</th>
                                <th class="text-left p-2">Cuota</th>
                                <th class="text-left p-2">Vence</th>
                                <th class="text-right p-2">Valor</th>
                                <th class="text-left p-2">Seguimiento</th>
                                <th class="p-2"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <template v-for="f in filas" :key="f.id">
                                <tr>
                                    <td class="p-2">
                                        <a :href="`/app/compras/oc/${f.orden_id}`" class="font-mono text-xs font-bold text-brand-600 hover:underline">{{ f.orden }}</a>
                                        <span class="block text-xs text-surface-500">{{ f.proveedor }}</span>
                                        <span v-if="f.contenedor" class="block text-xs font-mono text-surface-500">{{ f.contenedor }}</span>
                                    </td>
                                    <td class="p-2 text-xs">
                                        <b>{{ f.concepto }}</b>
                                        <span class="block text-surface-500">{{ f.plazo }} días desde {{ f.base }}</span>
                                    </td>
                                    <td class="p-2 text-xs whitespace-nowrap">
                                        <span class="inline-block px-2 py-0.5 rounded font-semibold" :class="tono[f.semaforo]">{{ textoDias(f) }}</span>
                                        <span v-if="f.vence" class="block mt-0.5 text-surface-500">{{ fechaCorta(f.vence) }}</span>
                                    </td>
                                    <td class="p-2 text-right whitespace-nowrap">
                                        <b>{{ num(f.monto_moneda) }} {{ f.moneda }}</b>
                                        <span class="block text-xs text-surface-500">{{ money(f.monto_cop) }}</span>
                                        <span v-if="f.interes > 0" class="block text-xs text-red-600">Interés estimado {{ money(f.interes) }}</span>
                                    </td>
                                    <td class="p-2 text-xs max-w-[14rem]">
                                        <template v-if="f.seguimiento_en">
                                            <span class="text-surface-500">{{ fechaCorta(f.seguimiento_en) }}</span>
                                            <span class="block">{{ f.seguimiento_nota }}</span>
                                        </template>
                                        <span v-else class="text-surface-400">—</span>
                                    </td>
                                    <td class="p-2 text-xs text-right whitespace-nowrap">
                                        <button @click="abrir(f, 'seguimiento')" class="block ml-auto text-brand-600 hover:underline">Seguimiento</button>
                                        <button @click="abrir(f, 'pagado')" class="block ml-auto text-brand-600 hover:underline">Marcar pagada</button>
                                        <button @click="abrir(f, 'plan')" class="block ml-auto text-brand-600 hover:underline">Cambiar plan</button>
                                    </td>
                                </tr>
                                <tr v-if="abierta?.id === f.id" class="bg-surface-50 dark:bg-surface-900/50">
                                    <td colspan="6" class="p-3">
                                        <div v-if="abierta.tipo === 'seguimiento'" class="flex gap-2 items-end flex-wrap">
                                            <div class="flex-1 min-w-[16rem]">
                                                <label class="text-xs font-semibold">¿Qué se ha hecho con este pago?</label>
                                                <input v-model="form.nota" maxlength="500" class="input w-full"/>
                                            </div>
                                            <button @click="guardarSeguimiento(f)" :disabled="form.nota.trim().length < 3 || enviando" class="btn-primary text-sm disabled:opacity-50">Guardar</button>
                                            <button @click="abierta = null" class="btn-ghost text-sm">Cancelar</button>
                                        </div>
                                        <div v-else-if="abierta.tipo === 'pagado'" class="flex gap-2 items-end flex-wrap">
                                            <div><label class="text-xs font-semibold">Fecha del pago</label><input type="date" v-model="form.fecha" :max="hoy()" class="input w-full"/></div>
                                            <div class="flex-1 min-w-[16rem]"><label class="text-xs font-semibold">Nota (opcional)</label><input v-model="form.nota" maxlength="500" class="input w-full"/></div>
                                            <button @click="guardarPagado(f)" :disabled="!form.fecha || enviando" class="btn-primary text-sm disabled:opacity-50">Marcar pagada</button>
                                            <button @click="abierta = null" class="btn-ghost text-sm">Cancelar</button>
                                        </div>
                                        <div v-else class="flex gap-2 items-center flex-wrap text-xs">
                                            <span class="font-semibold">Plan de {{ f.orden }}:</span>
                                            <button @click="aplicarPlan(f, 'zarpe90')" :disabled="enviando" class="btn-ghost text-xs">100 % a 90 días del zarpe</button>
                                            <button @click="aplicarPlan(f, 'fabrica')" :disabled="enviando" class="btn-ghost text-xs">Fábrica: 30 % al pedir, 70 % al terminar</button>
                                            <button @click="aplicarPlan(f, 'antes_llegar')" :disabled="enviando" class="btn-ghost text-xs">30 % al pedir, 70 % a 15 días del zarpe</button>
                                            <button @click="abierta = null" class="btn-ghost text-xs">Cancelar</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <p v-if="!hay_tramos" class="text-xs text-surface-500">El interés por mora no se está estimando: faltan los tramos pactados con China en Reglas (compras.interes_tramos).</p>

            <div class="card p-4">
                <div class="text-xs uppercase font-bold text-brand-600 flex items-center gap-1 mb-2"><CalendarClock class="h-3.5 w-3.5"/>Cuánto hay que tener listo cada semana</div>
                <div v-if="proyeccion.semanas.length" class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-[10px] uppercase text-surface-500 border-b">
                            <tr>
                                <th class="text-left p-2">Semana del</th>
                                <th class="text-right p-2">China</th>
                                <th class="text-right p-2">Nacionalización (estimada)</th>
                                <th class="text-right p-2">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-for="s in proyeccion.semanas" :key="s.semana">
                                <td class="p-2">{{ fechaCorta(s.semana) }}</td>
                                <td class="p-2 text-right">{{ money(s.china) }}</td>
                                <td class="p-2 text-right">{{ money(s.nacionalizacion) }}</td>
                                <td class="p-2 text-right font-bold">{{ money(s.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-surface-500">Sin pagos con fecha todavía.</p>
                <p v-if="proyeccion.sin_fecha > 0" class="text-xs text-surface-500 mt-2">{{ money(proyeccion.sin_fecha) }} más todavía sin fecha: se ubican cuando el contenedor zarpe.</p>
            </div>
        </div>
    </AppLayout>
</template>
