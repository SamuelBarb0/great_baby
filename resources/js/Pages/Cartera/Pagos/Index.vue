<script setup>
import { ref, watch, computed } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, router, Link } from '@inertiajs/vue3';
import { useMoney } from '@/composables/useMoney';
import { useDebounceFn } from '@vueuse/core';
import { CreditCard, Search, Calendar, Plus, X } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import FiltroEjes from '@/Components/FiltroEjes.vue';
import KpiCard from '@/Components/KpiCard.vue';

/*
 * La fecha de HOY en Colombia, no en UTC.
 *
 * `new Date().toISOString().slice(0,10)` convierte a UTC antes de
 * recortar: entre las 19:00 y la medianoche hora Bogotá devuelve el día
 * siguiente. Acá eso no era un filtro incómodo sino la fecha que queda
 * escrita en el documento.
 */
const { hoy: hoyBogota } = useBogotaDate();

const props = defineProps({
    pagos: { type: Object, required: true },
    filtros: { type: Object, required: true },
    opcionesEjes: { type: Object, default: () => ({ lineas: [], destinos: [] }) },
    totales: { type: Object, required: true },
    mediosPago: { type: Array, required: true },
    clasificaciones: { type: Array, default: () => [] },
    // Catálogo real, mantenido en /app/cartera/metodos-pago.
    metodos: { type: Array, default: () => [] },
});

/*
 * Registrar un pago recibido.
 *
 * Esto sólo existía en el panel Filament; al dejar /admin para Dropi el ERP se
 * quedaba sin forma de anotar la plata que entra, que es tarea de todos los
 * días. El saldo de la factura lo recalcula el observer del pago.
 */
// Si el maestro todavía está vacío se deja un mínimo para no bloquear el cobro.
const MEDIOS = computed(() => props.metodos.length
    ? props.metodos
    : [{ codigo: 'transferencia', nombre: 'Transferencia', pide_referencia: true, pide_banco: true },
       { codigo: 'efectivo', nombre: 'Efectivo', pide_referencia: false, pide_banco: false }]);

const metodoElegido = computed(() => MEDIOS.value.find(m => m.codigo === form.value.medio_pago));

const abierto = ref(false);
const guardando = ref(false);
const buscandoFac = ref(false);
const qFactura = ref('');
const facturas = ref([]);
const facturaSel = ref(null);

const hoy = hoyBogota();
const form = ref({
    fecha: hoy,
    monto_recibido: '',
    monto_aplicado: '',
    // Se guarda el CÓDIGO del método (es con lo que `pagos_venta.medio_pago`
    // referencia el maestro), no la etiqueta que se muestra.
    medio_pago: props.metodos[0]?.codigo || 'transferencia',
    referencia: '',
    banco: '',
    clasificacion_diferencia: '',
    notas: '',
});

const buscarFacturas = async () => {
    buscandoFac.value = true;
    try {
        const r = await fetch(`/app/pagos/facturas-pendientes?q=${encodeURIComponent(qFactura.value)}`,
            { headers: { Accept: 'application/json' } });
        facturas.value = r.ok ? await r.json() : [];
    } catch {
        facturas.value = [];
    } finally {
        buscandoFac.value = false;
    }
};
const buscarFacDebounced = useDebounceFn(buscarFacturas, 350);
watch(qFactura, buscarFacDebounced);

const abrirFormulario = () => {
    abierto.value = true;
    if (! facturas.value.length) buscarFacturas();
};

/*
 * Como se va a partir este pago si la factura es un reparto.
 *
 * Se calcula igual que en el backend —fiscal primero, mirando lo que ya
 * cubrieron los abonos anteriores— para que lo que se ve sea lo que se guarda.
 */
const repartoDelPago = computed(() => {
    const f = facturaSel.value;
    if (! f || f.destino !== 'reparto' || ! f.monto_fiscal) return null;

    const aplicado = Number(form.value.monto_aplicado || 0);
    if (aplicado <= 0) return null;

    const pendienteDeclarado = Math.max(0, Number(f.monto_fiscal) - Number(f.declarado_cubierto || 0));
    const fiscal = Math.min(aplicado, pendienteDeclarado);

    return { fiscal, interno: Math.round((aplicado - fiscal) * 100) / 100 };
});

const elegirFactura = (f) => {
    facturaSel.value = f;
    // Lo normal es que paguen la factura completa: se precarga el saldo y
    // quien registra sólo corrige si recibió otra cosa.
    form.value.monto_recibido = f.saldo;
    form.value.monto_aplicado = f.saldo;
};

const diferencia = computed(() => {
    const rec = Number(form.value.monto_recibido || 0);
    const apl = Number(form.value.monto_aplicado || 0);
    return Math.round((rec - apl) * 100) / 100;
});

const excedeSaldo = computed(() =>
    !! facturaSel.value && Number(form.value.monto_aplicado || 0) > Number(facturaSel.value.saldo) + 0.01);

const puedeGuardar = computed(() =>
    !! facturaSel.value
    && Number(form.value.monto_recibido) > 0
    && Number(form.value.monto_aplicado) > 0
    && ! excedeSaldo.value
    && ! guardando.value);

const guardar = () => {
    if (! puedeGuardar.value) return;
    guardando.value = true;
    router.post('/app/pagos', { factura_id: facturaSel.value.id, ...form.value }, {
        preserveScroll: true,
        onSuccess: () => {
            abierto.value = false;
            facturaSel.value = null;
            form.value = { fecha: hoy, monto_recibido: '', monto_aplicado: '',
                           medio_pago: props.metodos[0]?.codigo || 'transferencia',
                           referencia: '', banco: '', clasificacion_diferencia: '', notas: '' };
            facturas.value = [];
        },
        onFinish: () => { guardando.value = false; },
    });
};

const q = ref(props.filtros.q || '');
const medio = ref(props.filtros.medio || 'todos');
const desde = ref(props.filtros.desde || '');
const hasta = ref(props.filtros.hasta || '');
const linea = ref(props.filtros.linea || 'todas');
const destino = ref(props.filtros.destino || 'todas');

const parametros = () => ({
    q: q.value, medio: medio.value, desde: desde.value, hasta: hasta.value,
    linea: linea.value, destino: destino.value,
});

const badgeDestino = (color) => ({
    success: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
}[color] || 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200');

const filtrar = () => {
    router.get('/app/pagos', parametros(), {
        preserveScroll: true, preserveState: true, replace: true,
    });
};
const buscarDebounced = useDebounceFn(filtrar, 400);
watch(q, buscarDebounced);
watch([medio, desde, hasta, linea, destino], filtrar);

const { money: fmtCOP } = useMoney();

const badgeDif = (clas, dif) => {
    if (! dif || Math.abs(dif) < 1) return { txt: 'Exacto', cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' };
    if (clas === 'sobrepago') return { txt: 'Sobrepago', cls: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200' };
    if (clas === 'diferencia_menor') return { txt: 'Ajuste', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' };
    if (dif < 0) return { txt: 'Faltante', cls: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' };
    return { txt: clas || '—', cls: 'bg-slate-100 text-slate-700' };
};
</script>

<template>
    <Head title="Pagos"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <CreditCard class="h-6 w-6 text-brand-600"/>
                        Pagos recibidos
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">Consultá pagos aplicados a facturas y clasificación de diferencias.</p>
                </div>
                <button @click="abrirFormulario" class="btn-primary text-sm min-h-11">
                    <Plus class="h-4 w-4"/> Registrar pago
                </button>
            </div>

            <!-- Registrar pago recibido -->
            <div v-if="abierto" class="card p-4 border-2 border-brand-300 dark:border-brand-800">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h2 class="font-bold flex items-center gap-2">
                        <CreditCard class="h-5 w-5 text-brand-600"/> Registrar pago recibido
                    </h2>
                    <button @click="abierto = false" class="btn-ghost p-2 min-h-11" aria-label="Cerrar">
                        <X class="h-4 w-4"/>
                    </button>
                </div>

                <!-- Paso 1 · a qué factura se aplica -->
                <div v-if="! facturaSel">
                    <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">¿A qué factura?</label>
                    <div class="relative mt-1">
                        <Search class="h-4 w-4 absolute left-3 top-1/2 -translate-y-1/2 text-surface-400"/>
                        <input v-model="qFactura" class="input w-full pl-10 min-h-11"
                               placeholder="Número de factura, cliente o NIT…"/>
                    </div>
                    <p class="text-xs text-surface-500 mt-1">Sólo aparecen facturas con saldo pendiente.</p>

                    <div v-if="buscandoFac" class="text-sm text-surface-500 py-4 text-center">Buscando…</div>
                    <div v-else-if="! facturas.length" class="text-sm text-surface-500 py-4 text-center italic">
                        No hay facturas con saldo que coincidan.
                    </div>
                    <div v-else class="mt-2 divide-y divide-surface-100 dark:divide-surface-800 max-h-72 overflow-y-auto">
                        <button v-for="f in facturas" :key="f.id" @click="elegirFactura(f)"
                                class="w-full text-left p-3 hover:bg-brand-50 dark:hover:bg-surface-800 rounded flex items-center justify-between gap-3 min-h-11">
                            <div class="min-w-0">
                                <div class="font-bold text-sm font-mono">{{ f.numero }}</div>
                                <div class="text-xs text-surface-500 truncate">
                                    {{ f.cliente }} <span v-if="f.vence">· vence {{ f.vence }}</span>
                                </div>
                                <!-- A donde va el pago. Se ve antes de elegir,
                                     no despues de registrar. -->
                                <span :class="['inline-block mt-1 px-2 py-0.5 rounded text-[10px] font-bold', badgeDestino(f.destino_color)]">
                                    {{ f.destino_label }}
                                </span>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="text-xs text-surface-500">debe</div>
                                <div class="font-bold text-sm">{{ fmtCOP(f.saldo) }}</div>
                            </div>
                        </button>
                    </div>
                </div>

                <!-- Paso 2 · datos del pago -->
                <div v-else class="space-y-3">
                    <div class="flex items-center justify-between gap-3 p-3 rounded-lg bg-surface-50 dark:bg-surface-900 flex-wrap">
                        <div>
                            <div class="font-mono font-bold">{{ facturaSel.numero }}</div>
                            <div class="text-xs text-surface-500">{{ facturaSel.cliente }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-surface-500">saldo</div>
                            <div class="font-bold">{{ fmtCOP(facturaSel.saldo) }}</div>
                        </div>
                        <button @click="facturaSel = null" class="btn-ghost text-xs min-h-11">Cambiar factura</button>
                    </div>

                    <!--
                        Para donde va este pago. No se elige: lo hereda de la
                        factura, porque un pago «fiscal» de una factura interna
                        dejaria la CxC fiscal en negativo. Lo que si hace falta
                        es VERLO antes de registrar.
                    -->
                    <div :class="['text-xs p-3 rounded-lg border-l-4',
                                  facturaSel.destino === 'siigo'
                                      ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-500'
                                      : 'bg-amber-50 dark:bg-amber-900/20 border-amber-500']">
                        <template v-if="repartoDelPago">
                            Esta factura es un <strong>reparto</strong>: de este abono
                            <strong>{{ fmtCOP(repartoDelPago.fiscal) }}</strong> entran al libro fiscal
                            <span v-if="repartoDelPago.interno > 0">
                                y <strong>{{ fmtCOP(repartoDelPago.interno) }}</strong> quedan internos</span>.
                            Se cubre primero lo declarado, igual que en SIIGO, donde la factura vale
                            {{ fmtCOP(facturaSel.monto_fiscal) }}.
                        </template>
                        <template v-else-if="facturaSel.destino === 'siigo'">
                            Este pago entra al <strong>libro fiscal</strong> ({{ facturaSel.linea_label }}) y
                            viaja a SIIGO como recibo de caja.
                        </template>
                        <template v-else>
                            Esta factura es <strong>{{ facturaSel.destino_label }}</strong>: el pago
                            <strong>no</strong> entra al libro fiscal ni viaja a SIIGO.
                        </template>
                        <div class="mt-1 text-[11px] opacity-75">
                            A dónde va se decide en la factura, no acá. Para cambiarlo:
                            Facturas de venta → columna «Destino».
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Fecha del pago</label>
                            <input v-model="form.fecha" type="date" :max="hoy" class="input w-full min-h-11"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Monto recibido</label>
                            <input v-model="form.monto_recibido" type="number" step="0.01" min="0" class="input w-full min-h-11 text-right"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Se aplica a la factura</label>
                            <input v-model="form.monto_aplicado" type="number" step="0.01" min="0"
                                   class="input w-full min-h-11 text-right"
                                   :class="excedeSaldo && 'border-red-500'"/>
                            <p v-if="excedeSaldo" class="text-xs text-red-600 mt-0.5">
                                Es más de lo que debe la factura ({{ fmtCOP(facturaSel.saldo) }}).
                            </p>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Medio de pago</label>
                            <select v-model="form.medio_pago" class="input w-full min-h-11">
                                <option v-for="m in MEDIOS" :key="m.codigo" :value="m.codigo">{{ m.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <!-- El maestro de métodos dice cuáles datos pide cada forma de pago. -->
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">
                                Banco <span v-if="metodoElegido?.pide_banco" class="text-brand-600">*</span>
                            </label>
                            <input v-model="form.banco" class="input w-full min-h-11" maxlength="120"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">
                                Referencia / comprobante <span v-if="metodoElegido?.pide_referencia" class="text-brand-600">*</span>
                            </label>
                            <input v-model="form.referencia" class="input w-full min-h-11" maxlength="120"/>
                        </div>
                    </div>

                    <!-- Si recibido ≠ aplicado hay que decir por qué, o la
                         diferencia queda sin explicación en la contabilidad. -->
                    <div v-if="Math.abs(diferencia) >= 0.01"
                         class="p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border-l-4 border-amber-500">
                        <div class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                            Diferencia de {{ fmtCOP(diferencia) }}
                            <span class="font-normal">({{ diferencia > 0 ? 'recibiste de más' : 'recibiste de menos' }})</span>
                        </div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300 mt-2 block">¿Por qué?</label>
                        <select v-model="form.clasificacion_diferencia" class="input w-full min-h-11">
                            <option value="">— Elegí un motivo —</option>
                            <option v-for="c in clasificaciones" :key="c.valor" :value="c.valor">{{ c.label }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Notas</label>
                        <textarea v-model="form.notas" rows="2" class="input w-full" maxlength="1000"></textarea>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button @click="abierto = false" class="btn-ghost min-h-11">Cancelar</button>
                        <button @click="guardar" :disabled="! puedeGuardar" class="btn-primary min-h-11 disabled:opacity-40">
                            {{ guardando ? 'Guardando…' : 'Registrar pago' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <KpiCard label="Total aplicado" :value="totales.aplicado" color="emerald" format="money"/>
                <KpiCard label="Total recibido" :value="totales.recibido" color="blue" format="money"/>
                <KpiCard label="Cantidad de pagos" :value="totales.count" color="amber"/>
            </div>

            <!-- Filtros -->
            <div class="card p-4 grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="relative md:col-span-2">
                    <Search class="h-4 w-4 absolute left-3 top-1/2 -translate-y-1/2 text-surface-400"/>
                    <input v-model="q" type="search" placeholder="Buscar referencia, banco, factura o cliente…" class="input w-full pl-10"/>
                </div>
                <select v-model="medio" class="input">
                    <option value="todos">Todos los medios</option>
                    <option v-for="m in mediosPago" :key="m" :value="m">{{ m }}</option>
                </select>
                <div class="flex gap-2">
                    <input v-model="desde" type="date" class="input flex-1" title="Desde"/>
                    <input v-model="hasta" type="date" class="input flex-1" title="Hasta"/>
                </div>
                <!-- Los ejes son los de la FACTURA, no del pago: un pago no
                     tiene destino propio. -->
                <div class="md:col-span-4">
                    <FiltroEjes v-model:linea="linea" v-model:destino="destino" :opciones="opcionesEjes"
                                url-export="/app/pagos/csv" :params-export="parametros()"/>
                </div>
            </div>

            <!-- Tabla -->
            <div class="card overflow-hidden">
                <div v-if="! pagos.data.length" class="text-center py-16 text-surface-500">
                    <CreditCard class="h-10 w-10 mx-auto opacity-40"/>
                    <div class="text-sm mt-2">Sin pagos con estos criterios.</div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil data-vacia="No se registraron pagos en ese periodo." class="w-full min-w-[900px] text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900">
                            <tr class="text-surface-500 text-xs uppercase">
                                <th class="text-left px-4 py-2">Fecha</th>
                                <th class="text-left">Factura</th>
                                <th class="text-left">Cliente</th>
                                <th class="text-center">Destino</th>
                                <th class="text-left">Medio</th>
                                <th class="text-left">Referencia</th>
                                <th class="text-right">Recibido</th>
                                <th class="text-right">Aplicado</th>
                                <th class="text-left">Comprobante SIIGO</th>
                                <th class="text-center">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in pagos.data" :key="p.id" class="border-t border-surface-100 dark:border-surface-900 hover:bg-surface-50 dark:hover:bg-surface-900/30">
                                <td class="px-4 py-2 text-surface-500">{{ p.fecha }}</td>
                                <td class="font-mono text-brand-600">
                                    <Link v-if="p.factura_id" :href="'/app/facturas/' + p.factura_id" class="hover:underline">{{ p.factura_numero }}</Link>
                                    <span v-else>—</span>
                                </td>
                                <td class="text-surface-800 dark:text-surface-200">{{ p.cliente || '—' }}</td>
                                <td class="text-center whitespace-nowrap">
                                    <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold', badgeDestino(p.destino_color)]">
                                        {{ p.destino }}
                                    </span>
                                    <div class="text-[10px] text-surface-400">{{ p.linea }}</div>
                                </td>
                                <td class="capitalize">{{ p.medio_pago || '—' }}</td>
                                <td class="font-mono text-xs text-surface-500">
                                    <div>{{ p.referencia || '—' }}</div>
                                    <div v-if="p.banco" class="text-[10px]">{{ p.banco }}</div>
                                </td>
                                <td class="text-right font-mono">{{ fmtCOP(p.monto_recibido) }}</td>
                                <td class="text-right font-mono font-bold text-emerald-600">{{ fmtCOP(p.monto_aplicado) }}</td>
                                <!-- Si el cobro llegó o no a la contabilidad.
                                     Se guardaba el número y no se mostraba, así
                                     que no había forma de saberlo sin entrar a
                                     SIIGO a buscarlo. -->
                                <td class="text-xs">
                                    <span v-if="p.siigo_numero" class="font-mono text-emerald-700 dark:text-emerald-400">
                                        {{ p.siigo_numero }}
                                    </span>
                                    <span v-else-if="p.en_siigo" class="font-mono text-emerald-700 dark:text-emerald-400">en SIIGO</span>
                                    <span v-else class="text-amber-600">sin enviar</span>
                                </td>
                                <td class="text-center">
                                    <span :class="['inline-block px-2 py-0.5 rounded text-xs font-bold', badgeDif(p.clasificacion, p.diferencia).cls]">
                                        {{ badgeDif(p.clasificacion, p.diferencia).txt }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="pagos.data.length" class="flex items-center justify-between px-4 py-3 border-t border-surface-200 dark:border-surface-800 text-sm">
                    <div class="text-surface-500">{{ pagos.from }}–{{ pagos.to }} de {{ pagos.total }}</div>
                    <div class="flex items-center gap-1">
                        <template v-for="link in pagos.links" :key="link.label">
                            <Link v-if="link.url" :href="link.url"
                                  :class="['px-2 py-1 rounded text-xs', link.active ? 'bg-brand-600 text-white' : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800']"
                                  v-html="link.label"/>
                            <span v-else class="px-2 py-1 rounded text-xs text-surface-400" v-html="link.label"/>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
