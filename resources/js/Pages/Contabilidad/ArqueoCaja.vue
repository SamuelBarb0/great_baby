<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Wallet, Plus, Trash2, Lock, ArrowLeft } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    caja: { type: Object, required: true },
    fecha: { type: String, required: true },
    arqueo: { type: Object, default: null },
    movimientos: { type: Array, required: true },
    totales: { type: Object, required: true },
    billetes: { type: Array, required: true },
    monedas: { type: Array, required: true },
    destinos: { type: Array, default: () => [] },
});

const money = (n) => '$' + Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });

const cerrado = computed(() => !! props.arqueo && props.arqueo.estado !== 'abierto');

/* ─────────────────────── Movimientos del día ─────────────────────── */

const nuevo = reactive({ tipo: 'gasto', descripcion: '', entregado_a: '', valor: null });

const puedeAgregar = computed(() =>
    !! nuevo.descripcion.trim()
    && Number(nuevo.valor) > 0
    && (nuevo.tipo === 'ingreso' || !! nuevo.entregado_a.trim()));

const agregar = () => {
    if (! puedeAgregar.value) return;
    router.post(`/app/contabilidad/cajas/${props.caja.id}/movimientos`, {
        fecha: props.fecha, ...nuevo,
    }, {
        preserveScroll: true,
        onSuccess: () => { Object.assign(nuevo, { descripcion: '', entregado_a: '', valor: null }); },
    });
};

const quitar = (m) => router.post(
    `/app/contabilidad/cajas/${props.caja.id}/movimientos/${m.id}/quitar`, {}, { preserveScroll: true });

const ingresos = computed(() => props.movimientos.filter(m => m.tipo === 'ingreso'));
const gastos = computed(() => props.movimientos.filter(m => m.tipo === 'gasto'));

/* ──────────────────── Traslado a otra caja ──────────────────── */

const traslado = reactive({ destino_id: null, valor: null, motivo: '' });

const saldoDelDia = computed(() =>
    props.totales.esperado);

const trasladoExcede = computed(() =>
    Number(traslado.valor) > saldoDelDia.value + 0.01);

const puedeTrasladar = computed(() =>
    !! traslado.destino_id && Number(traslado.valor) > 0 && ! trasladoExcede.value && ! cerrado.value);

const trasladar = () => {
    if (! puedeTrasladar.value) return;
    router.post(`/app/contabilidad/cajas/${props.caja.id}/trasladar`, {
        fecha: props.fecha, ...traslado,
    }, {
        preserveScroll: true,
        onSuccess: () => { Object.assign(traslado, { destino_id: null, valor: null, motivo: '' }); },
    });
};

/* ───────────────────────── Conteo físico ───────────────────────── */

const conteo = reactive({});
const iniciarConteo = () => {
    [...props.billetes.map(d => `billete-${d}`), ...props.monedas.map(d => `moneda-${d}`)]
        .forEach(k => { conteo[k] = props.arqueo?.denominaciones?.[k] ?? 0; });
};
iniciarConteo();

const subtotal = (especie, d) => d * (Number(conteo[`${especie}-${d}`]) || 0);

const totalBilletes = computed(() => props.billetes.reduce((s, d) => s + subtotal('billete', d), 0));
const totalMonedas = computed(() => props.monedas.reduce((s, d) => s + subtotal('moneda', d), 0));
const totalContado = computed(() => totalBilletes.value + totalMonedas.value);

const diferencia = computed(() => totalContado.value - props.totales.esperado);

/* ─────────── Lo que explica el descuadre ─────────── */

const conceptos = ref(props.arqueo?.conceptos?.map(c => ({ ...c })) ?? []);
const agregarConcepto = () => conceptos.value.push({ concepto: '', valor: null });
const quitarConcepto = (i) => conceptos.value.splice(i, 1);

const explicado = computed(() => conceptos.value.reduce((s, c) => s + (Number(c.valor) || 0), 0));
const sinExplicar = computed(() => Math.round((diferencia.value - explicado.value) * 100) / 100);

const observaciones = ref(props.arqueo?.observaciones ?? '');
const procesando = ref(false);

const puedeCerrar = computed(() => Math.abs(sinExplicar.value) < 0.01 && ! cerrado.value);

const cerrar = () => {
    if (! puedeCerrar.value || procesando.value) return;
    procesando.value = true;
    router.post(`/app/contabilidad/cajas/${props.caja.id}/cerrar`, {
        fecha: props.fecha,
        conteo,
        conceptos: conceptos.value.filter(c => c.concepto.trim()),
        observaciones: observaciones.value,
    }, {
        preserveScroll: true,
        onFinish: () => { procesando.value = false; },
    });
};

const cambiarFecha = (e) =>
    router.get(`/app/contabilidad/cajas/${props.caja.id}/dia`, { fecha: e.target.value });
</script>

<template>
    <Head :title="`Caja ${caja.codigo} · ${fecha}`"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between flex-wrap gap-2">
                <div>
                    <a href="/app/contabilidad/cajas" class="text-xs text-brand-600 inline-flex items-center gap-1">
                        <ArrowLeft class="h-3 w-3"/> Cajas y bancos
                    </a>
                    <h1 class="text-2xl font-bold flex items-center gap-2 mt-1">
                        <Wallet class="h-6 w-6 text-brand-600"/> {{ caja.codigo }} · {{ caja.nombre }}
                    </h1>
                </div>
                <div class="text-right">
                    <label class="block text-xs font-bold text-surface-600 mb-1">Día</label>
                    <input type="date" :value="fecha" @change="cambiarFecha" class="input"/>
                </div>
            </div>

            <div v-if="$page.props.flash?.message" class="p-3 rounded-lg text-sm"
                 :class="$page.props.flash.type === 'error'
                    ? 'bg-red-500/15 border-l-4 border-red-500 text-red-700'
                    : 'bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700'">
                {{ $page.props.flash.message }}
            </div>

            <div v-if="cerrado" class="p-3 rounded-lg bg-surface-500/15 border-l-4 border-surface-500 text-sm">
                <strong>Arqueo {{ arqueo.numero }} cerrado</strong> el {{ arqueo.cerrado_at }} por
                {{ arqueo.realizado_por }} · {{ arqueo.resultado }}
                <span v-if="arqueo.diferencia"> por {{ money(Math.abs(arqueo.diferencia)) }}</span>.
                Los movimientos del día quedaron amarrados y ya no se pueden quitar.
            </div>

            <!-- Resumen del día -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="card p-3">
                    <div class="text-[11px] uppercase text-surface-500">Saldo apertura</div>
                    <div class="text-xl font-bold font-mono">{{ money(totales.apertura) }}</div>
                </div>
                <div class="card p-3">
                    <div class="text-[11px] uppercase text-surface-500">Ingresos</div>
                    <div class="text-xl font-bold font-mono text-emerald-600">{{ money(totales.ingresos) }}</div>
                </div>
                <div class="card p-3">
                    <div class="text-[11px] uppercase text-surface-500">Gastos</div>
                    <div class="text-xl font-bold font-mono text-rose-600">{{ money(totales.gastos) }}</div>
                </div>
                <div class="card p-3 border-2 border-brand-500">
                    <div class="text-[11px] uppercase text-surface-500">Saldo esperado</div>
                    <div class="text-xl font-bold font-mono">{{ money(totales.esperado) }}</div>
                </div>
            </div>

            <!-- Registrar movimiento -->
            <div v-if="!cerrado" class="card p-3 space-y-2">
                <div class="text-xs uppercase font-bold text-brand-600">Registrar movimiento</div>
                <div class="grid grid-cols-1 md:grid-cols-5 gap-2">
                    <select v-model="nuevo.tipo" class="input">
                        <option value="gasto">Gasto / pago</option>
                        <option value="ingreso">Ingreso</option>
                    </select>
                    <input v-model="nuevo.descripcion" maxlength="200" class="input md:col-span-2"
                           placeholder="Cancelación de domicilios"/>
                    <input v-model="nuevo.entregado_a" maxlength="120" class="input"
                           :disabled="nuevo.tipo === 'ingreso'"
                           :placeholder="nuevo.tipo === 'ingreso' ? '—' : 'Entregado a…'"/>
                    <div class="flex gap-2">
                        <input v-model="nuevo.valor" type="number" min="1" class="input w-full font-mono" placeholder="0"/>
                        <button @click="agregar" :disabled="!puedeAgregar" class="btn-primary px-3">
                            <Plus class="h-4 w-4"/>
                        </button>
                    </div>
                </div>
                <p v-if="nuevo.tipo === 'gasto' && !nuevo.entregado_a.trim()" class="text-xs text-surface-500">
                    Un gasto de caja necesita a quién se le entregó la plata: sin eso no se le puede pedir el soporte.
                </p>
            </div>

            <!-- Pasar plata a otra caja -->
            <div v-if="!cerrado && destinos.length" class="card p-3 space-y-2">
                <div class="text-xs uppercase font-bold text-brand-600">Pasar plata a otra caja</div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
                    <select v-model="traslado.destino_id" class="input">
                        <option :value="null">¿A qué caja?</option>
                        <option v-for="d in destinos" :key="d.id" :value="d.id">{{ d.nombre }}</option>
                    </select>
                    <input v-model="traslado.motivo" maxlength="200" class="input md:col-span-2"
                           placeholder="Motivo (opcional): cierre de semana…"/>
                    <div class="flex gap-2">
                        <input v-model="traslado.valor" type="number" min="1" class="input w-full font-mono" placeholder="0"/>
                        <button @click="trasladar" :disabled="!puedeTrasladar" class="btn-primary px-3 whitespace-nowrap">
                            Pasar
                        </button>
                    </div>
                </div>
                <p v-if="trasladoExcede" class="text-xs text-rose-600">
                    En esta caja hay {{ money(saldoDelDia) }} hoy. No se puede pasar más de lo que hay.
                </p>
                <p v-else class="text-xs text-surface-500">
                    Queda registrado en las dos cajas: sale de acá y entra allá, amarrados entre sí.
                </p>
            </div>

            <!-- Las dos listas -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="card overflow-hidden">
                    <div class="p-2 border-b text-xs uppercase font-bold text-emerald-600">
                        Ingresos · {{ ingresos.length }}
                    </div>
                    <table class="w-full text-sm">
                        <tbody class="divide-y">
                            <tr v-for="m in ingresos" :key="m.id">
                                <td class="p-2">{{ m.descripcion }}</td>
                                <td class="p-2 text-right font-mono">{{ money(m.valor) }}</td>
                                <td class="p-2 w-8">
                                    <button v-if="!m.en_arqueo" @click="quitar(m)" class="text-rose-600 p-1" title="Quitar">
                                        <Trash2 class="h-3 w-3"/>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!ingresos.length"><td colspan="3" class="p-4 text-center text-surface-500 text-xs">Sin ingresos.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="card overflow-hidden">
                    <div class="p-2 border-b text-xs uppercase font-bold text-rose-600">
                        Gastos · {{ gastos.length }}
                    </div>
                    <table class="w-full text-sm">
                        <tbody class="divide-y">
                            <tr v-for="m in gastos" :key="m.id">
                                <td class="p-2">
                                    {{ m.descripcion }}
                                    <span v-if="m.entregado_a" class="block text-[11px] text-surface-500">
                                        → {{ m.entregado_a }}
                                    </span>
                                </td>
                                <td class="p-2 text-right font-mono">{{ money(m.valor) }}</td>
                                <td class="p-2 w-8">
                                    <button v-if="!m.en_arqueo" @click="quitar(m)" class="text-rose-600 p-1" title="Quitar">
                                        <Trash2 class="h-3 w-3"/>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!gastos.length"><td colspan="3" class="p-4 text-center text-surface-500 text-xs">Sin gastos.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Conteo físico -->
            <div class="card p-3 space-y-3">
                <div class="text-xs uppercase font-bold text-brand-600">Conteo físico</div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <div class="text-[11px] uppercase text-surface-500 mb-1">Billetes</div>
                        <div v-for="d in billetes" :key="`b${d}`" class="flex items-center gap-2 mb-1">
                            <span class="font-mono text-sm w-20 text-right">{{ money(d) }}</span>
                            <input v-model="conteo[`billete-${d}`]" type="number" min="0" :disabled="cerrado"
                                   class="input w-20 font-mono text-center"/>
                            <span class="font-mono text-sm text-surface-500">= {{ money(subtotal('billete', d)) }}</span>
                        </div>
                        <div class="text-right font-mono font-bold border-t pt-1">{{ money(totalBilletes) }}</div>
                    </div>

                    <div>
                        <div class="text-[11px] uppercase text-surface-500 mb-1">Monedas</div>
                        <div v-for="d in monedas" :key="`m${d}`" class="flex items-center gap-2 mb-1">
                            <span class="font-mono text-sm w-20 text-right">{{ money(d) }}</span>
                            <input v-model="conteo[`moneda-${d}`]" type="number" min="0" :disabled="cerrado"
                                   class="input w-20 font-mono text-center"/>
                            <span class="font-mono text-sm text-surface-500">= {{ money(subtotal('moneda', d)) }}</span>
                        </div>
                        <div class="text-right font-mono font-bold border-t pt-1">{{ money(totalMonedas) }}</div>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3 pt-2 border-t">
                    <div>
                        <div class="text-[11px] uppercase text-surface-500">Total contado</div>
                        <div class="text-lg font-bold font-mono">{{ money(totalContado) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] uppercase text-surface-500">Saldo esperado</div>
                        <div class="text-lg font-bold font-mono">{{ money(totales.esperado) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] uppercase text-surface-500">Diferencia</div>
                        <div class="text-lg font-bold font-mono"
                             :class="Math.abs(diferencia) < 0.01 ? 'text-emerald-600' : 'text-rose-600'">
                            {{ money(diferencia) }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Qué explica el descuadre -->
            <div v-if="Math.abs(diferencia) >= 0.01 || conceptos.length" class="card p-3 space-y-2">
                <div class="flex justify-between items-center">
                    <div class="text-xs uppercase font-bold text-amber-600">Qué explica la diferencia</div>
                    <button v-if="!cerrado" @click="agregarConcepto" class="btn-ghost text-xs inline-flex items-center gap-1">
                        <Plus class="h-3 w-3"/> Agregar concepto
                    </button>
                </div>

                <div v-for="(c, i) in conceptos" :key="i" class="flex gap-2">
                    <input v-model="c.concepto" maxlength="150" :disabled="cerrado" class="input flex-1"
                           placeholder="ICA Janderson, vueltos dados…"/>
                    <input v-model="c.valor" type="number" :disabled="cerrado" class="input w-36 font-mono" placeholder="0"/>
                    <button v-if="!cerrado" @click="quitarConcepto(i)" class="text-rose-600 p-1"><Trash2 class="h-4 w-4"/></button>
                </div>

                <p v-if="Math.abs(sinExplicar) >= 0.01" class="text-sm text-rose-600 font-semibold">
                    Faltan {{ money(Math.abs(sinExplicar)) }} por explicar.
                    <span class="font-normal text-surface-500">
                        Los conceptos suman {{ money(explicado) }} y la diferencia es {{ money(diferencia) }}.
                    </span>
                </p>
                <p v-else-if="conceptos.length" class="text-sm text-emerald-600 font-semibold">
                    La diferencia queda explicada por completo.
                </p>
            </div>

            <!-- Cerrar -->
            <div v-if="!cerrado" class="card p-3 space-y-2">
                <label class="block text-xs font-bold text-surface-600">Observaciones</label>
                <textarea v-model="observaciones" rows="2" maxlength="1000" class="input w-full"
                          placeholder="Lo que haya que dejar dicho del día."></textarea>
                <div class="flex justify-end items-center gap-2">
                    <p v-if="!puedeCerrar" class="text-xs text-amber-600 mr-auto">
                        No se puede cerrar con plata sin explicar.
                    </p>
                    <button @click="cerrar" :disabled="!puedeCerrar || procesando"
                            class="btn-primary inline-flex items-center gap-1">
                        <Lock class="h-4 w-4"/> {{ procesando ? 'Cerrando…' : 'Cerrar el día' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
