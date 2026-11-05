<script setup>
import { ref, reactive, computed } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, router } from '@inertiajs/vue3';
import { CreditCard, Plus, Search, X, Calculator } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';
import { useDestacar } from '@/composables/useDestacar';
import { useMoney } from '@/composables/useMoney';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';

const props = defineProps({
    filtros: { type: Object, required: true },
    pagos: { type: Object, required: true },
    kpis: { type: Object, required: true },
    // Conceptos de retención con tarifa y base · salen de las reglas activas.
    conceptos: { type: Array, default: () => [] },
});

// La Torre SIIGO enlaza aca con `?destacar=<id>` cuando el documento no tiene
// pantalla de detalle propia. Senala la fila en vez de dar un 404.
const { clasesDe } = useDestacar();

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

const { money } = useMoney();
const q = ref(props.filtros.q);

let deb;
const filtrar = () => {
    clearTimeout(deb);
    deb = setTimeout(() => {
        router.get('/app/cartera/pagos-proveedor', { q: q.value || null },
            { preserveScroll: true, preserveState: true, replace: true });
    }, 300);
};

const modal = ref(false);
const proc = ref(false);

/*
 * COMP-B5 · acciones por fila, por Inertia y no por `fetch` crudo.
 *
 * Antes iban con un `fetch` a mano y después un `router.reload()`. El fetch
 * seguía el 302 de `back()` por su cuenta, renderizaba la página en una
 * respuesta que se tiraba a la basura y ahí se consumía el flash: para cuando
 * el reload llegaba, el mensaje ya no existía. Así que confirmar, anular y
 * reenviar NUNCA mostraron su resultado — ni el bueno ni el malo.
 *
 * Se notó al probar el control de cuatro ojos: el pago correctamente no se
 * confirmaba y la pantalla no decía nada, que es la peor manera de negar algo.
 * `router.post` maneja el redirect y el flash como corresponde.
 */
const trabajando = ref(null);
const accionDeFila = (id, url, datos = {}) => {
    trabajando.value = id;
    router.post(url, datos, {
        preserveScroll: true,
        onFinish: () => (trabajando.value = null),
    });
};
const confirmar = async (p) => {
    modalConfirm.value = {
        titulo: `¿Confirmar el pago #${p.id}?`,
        mensaje: `Se registra por $${p.monto_neto.toLocaleString()} y se envía a SIIGO.`,
        color: 'emerald',
        textoConfirmar: 'Confirmar pago',
        onConfirmar: () => {
            modalConfirm.value = null;
            accionDeFila(p.id, `/app/cartera/pagos-proveedor/${p.id}/confirmar`);
        },
    };
};
const anular = async (p) => {
    modalConfirm.value = {
        titulo: `¿Anular el pago #${p.id}?`,
        // Si estaba confirmado, anularlo además revierte su asiento: la
        // cancelación del pasivo, la salida de banco y las retenciones.
        mensaje: p.estado === 'confirmado'
            ? 'No vas a poder volver atrás. También se revierten las líneas que este pago escribió en el libro, incluidas las retenciones.'
            : 'No vas a poder volver atrás.',
        color: 'rose',
        textoConfirmar: 'Anular',
        pedirMotivo: { etiqueta: '¿Por qué se anula?', minimo: 5 },
        onConfirmar: (motivo) => {
            modalConfirm.value = null;
            accionDeFila(p.id, `/app/cartera/pagos-proveedor/${p.id}/anular`, { motivo });
        },
    };
};
const reenviar = (p) => accionDeFila(p.id, `/app/cartera/pagos-proveedor/${p.id}/reenviar-siigo`);
/*
 * Va ARRIBA de `form`, que la usa para la fecha por defecto.
 *
 * Estaba declarada 86 lineas mas abajo, asi que `form` llamaba a
 * `hoyBogota()` antes de que existiera y el componente entero reventaba al
 * montar con «Cannot access before initialization». La pantalla de pagos a
 * proveedor quedaba EN BLANCO: no se podia registrar ni un pago desde la
 * interfaz, y como el error vive en la consola del navegador, desde afuera
 * parecia una pagina que no cargaba y ya.
 */
const { hoy: hoyBogota } = useBogotaDate();

const form = reactive({
    fecha: hoyBogota(),
    contacto_id: null,
    monto_bruto: 0,
    iva: 0,
    concepto_retencion: 'compras_generales',
    ciudad: 'Bogotá',
    gran_contribuyente: false,
    metodo: 'transferencia',
    cuenta_puc_egreso: '', // QA-FIX #10 · backend resuelve default desde settings
    observaciones: '',
});

/**
 * El concepto elegido, para mostrar su tarifa debajo del desplegable.
 *
 * Funcion y no `computed`: en este componente el `computed` reventaba con un
 * «Cannot access before initialization» al montar. Una funcion se resuelve
 * cuando la plantilla la llama y no depende del orden de las declaraciones.
 */
const conceptoElegido = () => props.conceptos.find((c) => c.valor === form.concepto_retencion);
const buscar = ref('');
const encontrados = ref([]);

let debS;
const buscarProv = () => {
    clearTimeout(debS);
    if (buscar.value.length < 2) { encontrados.value = []; return; }
    debS = setTimeout(async () => {
        const r = await fetch(`/app/api/contactos/buscar?q=${encodeURIComponent(buscar.value)}&rol=proveedor`, { credentials: 'same-origin' });
        if (r.ok) encontrados.value = await r.json();
    }, 300);
};

// Lo informa la ficha del proveedor: si es autorretenedor no se le practica
// retefuente, y conviene verlo ANTES de guardar.
const autorretenedor = ref(false);

const seleccionarProv = (p) => {
    form.contacto_id = p.id;

    /*
     * La ciudad decide el ReteICA. El buscador NO la devolvia —ignoraba el
     * `rol=proveedor` y mandaba solo id/nombre/documento— asi que el formulario
     * se quedaba con «Bogota» fijo y se le retenia ReteICA de Bogota a
     * cualquier proveedor del pais.
     */
    if (p.ciudad) form.ciudad = p.ciudad;

    /*
     * «Gran contribuyente» NO viene del contacto: no existe esa columna. Es una
     * casilla que marca quien registra el pago. Antes esta linea leia
     * `p.gran_contribuyente`, que siempre era undefined — parecia que se
     * autollenaba y nunca lo hizo.
     */
    autorretenedor.value = !! p.es_autorretenedor;

    buscar.value = `${p.nombre || p.nombre_completo} · NIT ${p.documento || '—'}`;
    encontrados.value = [];
};


const abrir = () => {
    modal.value = true;
    Object.assign(form, {
        fecha: hoyBogota(),
        contacto_id: null, monto_bruto: 0, iva: 0,
        concepto_retencion: 'compras_generales', ciudad: 'Bogotá',
        gran_contribuyente: false, metodo: 'transferencia',
        cuenta_puc_egreso: '', observaciones: '',
    });
    buscar.value = '';
};

// QA-FIX #11 · preview desde motor REAL vía API (evita hardcodes que mienten
// si Aracely edita las reglas de retenciones).
import { watch } from 'vue';

/*
 * La fecha de HOY en Colombia, no en UTC.
 *
 * `new Date().toISOString().slice(0,10)` convierte a UTC antes de
 * recortar: entre las 19:00 y la medianoche hora Bogotá devuelve el día
 * siguiente. Acá eso no era un filtro incómodo sino la fecha que queda
 * escrita en el documento.
 */
const preview = ref({ lineas: [], total: 0, neto: 0, es_autorretenedor: false });
let debPreview;
const cargarPreview = () => {
    clearTimeout(debPreview);
    const monto = parseFloat(form.monto_bruto || 0);
    if (monto <= 0) { preview.value = { lineas: [], total: 0, neto: monto, es_autorretenedor: false }; return; }
    debPreview = setTimeout(async () => {
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const r = await fetch('/app/api/retenciones/preview', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({
                    contacto_id: form.contacto_id,
                    monto_bruto: monto,
                    iva: parseFloat(form.iva || 0),
                    concepto: form.concepto_retencion,
                    ciudad: form.ciudad,
                    gran_contribuyente: form.gran_contribuyente,
                }),
            });
            if (r.ok) preview.value = await r.json();
        } catch (e) { /* silencioso; motor recomputa al guardar */ }
    }, 350);
};
watch(() => [form.monto_bruto, form.iva, form.concepto_retencion, form.ciudad, form.gran_contribuyente, form.contacto_id], cargarPreview, { deep: false });
const retencionPreview = computed(() => {
    const byTipo = Object.fromEntries((preview.value.lineas || []).map(l => [l.tipo, l.valor]));
    return {
        rf: byTipo.retefuente || 0,
        ri: byTipo.reteica || 0,
        riva: byTipo.reteiva || 0,
        total: preview.value.total || 0,
        neto: preview.value.neto || parseFloat(form.monto_bruto || 0),
        esAutoRet: preview.value.es_autorretenedor,
    };
});

const guardar = () => {
    if (proc.value) return;
    proc.value = true;
    router.post('/app/cartera/pagos-proveedor', form, {
        preserveScroll: true,
        onSuccess: () => { modal.value = false; },
        onFinish: () => { proc.value = false; },
    });
};
</script>

<template>
    <Head title="Pagos a proveedor · con retenciones"/>
    <AppLayout>
        <div class="space-y-4 max-w-7xl">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <CreditCard class="h-6 w-6 text-brand-600"/>
                        Pagos a proveedor
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Registro de egresos con cálculo automático de retenciones (Retefuente + Reteica + Reteiva). Cada pago genera entradas polimórficas en <code>retenciones_aplicadas</code>.
                    </p>
                </div>
                <button @click="abrir" class="btn-primary text-sm inline-flex items-center gap-1">
                    <Plus class="h-4 w-4"/> Nuevo pago
                </button>
            </div>

            <div v-if="$page.props.flash?.message" class="p-3 rounded-lg text-sm"
                 :class="$page.props.flash.type === 'error' ? 'bg-red-500/15 border-l-4 border-red-500 text-red-700' : 'bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700'">
                {{ $page.props.flash.message }}
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Pagos mes</div><div class="text-2xl font-bold mt-1">{{ kpis.pagos_mes }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Neto pagado mes</div><div class="text-2xl font-bold mt-1 text-brand-600">{{ money(kpis.total_mes) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Retenido mes</div><div class="text-2xl font-bold mt-1 text-amber-600">{{ money(kpis.retenido_mes) }}</div></div>
                <div class="card p-4" :class="kpis.sin_siigo > 0 ? 'ring-2 ring-amber-500' : ''"><div class="text-xs uppercase text-surface-500">Sin SIIGO</div><div class="text-2xl font-bold mt-1" :class="kpis.sin_siigo > 0 ? 'text-amber-600' : 'text-surface-400'">{{ kpis.sin_siigo }}</div></div>
            </div>
            <!-- COMP-B5 · banner cuando hay pagos pendientes de confirmar antes de ir a SIIGO -->
            <div v-if="kpis.pendientes_confirmar > 0" class="card p-4 border-l-4 border-indigo-500 bg-indigo-50/50">
                <div class="flex items-start gap-3">
                    <div class="text-indigo-600 text-xl">⏳</div>
                    <div class="flex-1 text-sm">
                        <div class="font-bold text-indigo-800">{{ kpis.pendientes_confirmar }} pago(s) esperando confirmación</div>
                        <div class="text-xs text-surface-600 mt-1">
                            Un pago no llega a SIIGO hasta que lo confirmes aquí. Revisa el NIT del proveedor, el monto y la cuenta antes de aprobar.
                        </div>
                    </div>
                </div>
            </div>

            <div class="card p-3">
                <div class="relative">
                    <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-surface-400"/>
                    <input v-model="q" @input="filtrar" placeholder="Buscar por proveedor…" class="input pl-9 w-full text-sm"/>
                </div>
            </div>

            <div class="card overflow-hidden">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="text-left p-2">Fecha</th>
                            <th class="text-left p-2">Proveedor</th>
                            <th class="text-right p-2">Bruto</th>
                            <th class="text-right p-2">IVA</th>
                            <th class="text-right p-2">Retenciones</th>
                            <th class="text-right p-2 bg-brand-50">Neto</th>
                            <th class="text-left p-2">Método</th>
                            <th class="text-left p-2">Estado</th>
                            <th class="text-left p-2">Detalle ret.</th>
                            <th class="text-left p-2">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="p in pagos.data" :key="p.id" :class="['hover:bg-surface-50', clasesDe(p.id)]">
                            <td class="p-2 text-xs">{{ p.fecha }}</td>
                            <td class="p-2 text-xs">{{ p.proveedor }} <span class="text-surface-400">· {{ p.ciudad }}</span></td>
                            <td class="p-2 text-right font-mono">{{ money(p.monto_bruto) }}</td>
                            <td class="p-2 text-right font-mono text-surface-500">{{ money(p.iva) }}</td>
                            <td class="p-2 text-right font-mono text-amber-600">-{{ money(p.monto_retenciones) }}</td>
                            <td class="p-2 text-right font-mono font-bold text-brand-700 bg-brand-50">{{ money(p.monto_neto) }}</td>
                            <td class="p-2 text-xs capitalize">{{ p.metodo }}</td>
                            <td class="p-2">
                                <!-- COMP-B5 · chip de estado -->
                                <span v-if="p.estado === 'pendiente'" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-indigo-100 text-indigo-800">
                                    ⏳ Pendiente
                                </span>
                                <span v-else-if="p.estado === 'anulado'" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-red-100 text-red-800">
                                    ✕ Anulado
                                </span>
                                <span v-else-if="p.siigo_voucher_id" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-100 text-emerald-800" :title="`Voucher ${p.siigo_voucher_id}`">
                                    ✓ En SIIGO
                                </span>
                                <span v-else class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-amber-100 text-amber-800">
                                    ⟳ Confirmado · encolado
                                </span>
                            </td>
                            <td class="p-2 text-[10px]">
                                <span v-for="r in p.retenciones" :key="r.tipo" class="inline-block mr-1 px-1.5 py-0.5 rounded bg-surface-200"
                                      :title="`PUC ${r.cuenta_puc}`">
                                    {{ r.tipo }} {{ r.tarifa_pct }}%: {{ money(r.valor) }}
                                </span>
                            </td>
                            <td class="p-2 whitespace-nowrap">
                                <button v-if="p.estado === 'pendiente'" @click="confirmar(p)" :disabled="trabajando === p.id"
                                        class="px-2 py-1 text-[11px] rounded bg-emerald-600 text-white font-bold hover:bg-emerald-700 disabled:opacity-50">
                                    {{ trabajando === p.id ? '…' : '✓ Confirmar y enviar a SIIGO' }}
                                </button>
                                <button v-if="p.estado === 'confirmado' && !p.siigo_voucher_id" @click="reenviar(p)"
                                        class="px-2 py-1 text-[11px] rounded bg-amber-100 text-amber-700 hover:bg-amber-200">
                                    Reenviar
                                </button>
                                <!--
                                    Anular también cuando ya está confirmado.

                                    Sólo salía en los pendientes, y un pendiente
                                    todavía no escribió nada: el caso que de
                                    verdad hay que poder deshacer —el pago
                                    confirmado por error, que ya canceló el
                                    pasivo y registró retenciones— no tenía
                                    botón. El corte real es haber llegado a
                                    SIIGO: de ahí en adelante se arregla allá
                                    con nota de ajuste, y el servidor lo frena.
                                -->
                                <button v-if="!p.siigo_voucher_id && p.estado !== 'anulado'" @click="anular(p)" :disabled="trabajando === p.id"
                                        class="ml-1 px-2 py-1 text-[11px] rounded bg-red-100 text-red-700 hover:bg-red-200 disabled:opacity-50">
                                    Anular
                                </button>
                                <span v-if="p.siigo_voucher_id || p.estado === 'anulado'" class="text-[10px] text-surface-400">—</span>
                            </td>
                        </tr>
                        <tr v-if="!pagos.data.length"><td colspan="10" class="p-6 text-center text-surface-500 text-sm">Sin pagos.</td></tr>
                    </tbody>
                </table>
            </div>
            <Paginacion :pagina="pagos" etiqueta="pagos"/>

            <div v-if="modal" @click.self="modal = false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <div class="card p-5 w-full max-w-2xl space-y-3 max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-bold">Nuevo pago a proveedor</h3>
                        <button @click="modal = false" class="text-surface-500 hover:text-surface-700"><X class="h-5 w-5"/></button>
                    </div>
                    <form @submit.prevent="guardar" class="space-y-3">
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="text-xs font-semibold">Fecha *</label>
                                <input v-model="form.fecha" type="date" required class="input w-full text-sm"/>
                            </div>
                            <div class="col-span-2 relative">
                                <label class="text-xs font-semibold">Proveedor *</label>
                                <input v-model="buscar" @input="buscarProv" required placeholder="Buscar contacto proveedor…" class="input w-full text-sm"/>
                                <div v-if="encontrados.length" class="absolute z-10 left-0 right-0 mt-1 max-h-56 overflow-y-auto rounded border bg-white dark:bg-surface-900 shadow-lg">
                                    <button v-for="p in encontrados" :key="p.id" type="button" @click="seleccionarProv(p)"
                                            class="w-full text-left px-3 py-2 hover:bg-surface-50 border-b text-sm">
                                        <div class="font-semibold">{{ p.nombre || p.nombre_completo }}</div>
                                        <div class="text-xs text-surface-500">NIT {{ p.documento || p.numero_documento || 'sin NIT' }}</div>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-semibold">Monto bruto *</label>
                                <input v-model="form.monto_bruto" type="number" step="0.01" min="0.01" required class="input w-full font-mono text-right"/>
                            </div>
                            <div>
                                <label class="text-xs font-semibold">IVA incluido</label>
                                <input v-model="form.iva" type="number" step="0.01" min="0" class="input w-full font-mono text-right"/>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="text-xs font-semibold">Concepto retención *</label>
                                <select v-model="form.concepto_retencion" required class="input w-full text-sm">
                                    <option v-for="c in conceptos" :key="c.valor" :value="c.valor">
                                        {{ c.etiqueta }}
                                    </option>
                                </select>
                                <!--
                                    Se muestra la tarifa y la base: quien paga
                                    tiene que poder ver por qué le retuvo eso,
                                    o por qué no le retuvo nada.
                                -->
                                <p v-if="conceptoElegido()" class="text-[11px] text-surface-500 mt-1">
                                    {{ conceptoElegido().tarifa }} % desde {{ money(conceptoElegido().base) }}
                                </p>
                            </div>
                            <div>
                                <label class="text-xs font-semibold">Ciudad</label>
                                <input v-model="form.ciudad" placeholder="Bogotá" class="input w-full text-sm"/>
                                <!-- La ciudad decide el ReteICA: se trae de la
                                     ficha del proveedor, no se asume Bogotá. -->
                                <p v-if="autorretenedor" class="text-[11px] text-amber-600 mt-1">
                                    Este proveedor es autorretenedor: no se le practica retefuente.
                                </p>
                            </div>
                            <div>
                                <label class="text-xs font-semibold">Método *</label>
                                <select v-model="form.metodo" required class="input w-full text-sm">
                                    <option value="transferencia">Transferencia</option>
                                    <option value="efectivo">Efectivo</option>
                                    <option value="cheque">Cheque</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="flex items-center gap-2 text-xs">
                                <input type="checkbox" v-model="form.gran_contribuyente" class="rounded"/>
                                Proveedor es gran contribuyente (aplica Reteiva)
                            </label>
                            <div>
                                <label class="text-xs font-semibold">Cuenta PUC egreso</label>
                                <input v-model="form.cuenta_puc_egreso" placeholder="1110 Bancos" class="input w-full text-sm font-mono"/>
                            </div>
                        </div>

                        <div class="p-3 bg-brand-50 dark:bg-brand-900/20 rounded border-l-4 border-brand-500 text-xs">
                            <div class="font-semibold mb-1 flex items-center gap-1"><Calculator class="h-3 w-3"/> Retenciones (motor real)</div>
                            <div class="grid grid-cols-4 gap-2 font-mono">
                                <div>Retefuente: <b>{{ money(retencionPreview.rf) }}</b></div>
                                <div>Reteica: <b>{{ money(retencionPreview.ri) }}</b></div>
                                <div>Reteiva: <b>{{ money(retencionPreview.riva) }}</b></div>
                                <div class="text-brand-700">NETO: <b>{{ money(retencionPreview.neto) }}</b></div>
                            </div>
                            <div v-if="retencionPreview.esAutoRet" class="text-[10px] text-amber-700 mt-1">
                                ⚠ Este proveedor es autorretenedor · no se le practica retefuente.
                            </div>
                        </div>

                        <textarea v-model="form.observaciones" rows="2" maxlength="500" placeholder="Observaciones (opcional)" class="input w-full text-sm"></textarea>

                        <div class="flex justify-end gap-2 pt-2 border-t">
                            <button type="button" @click="modal = false" class="btn-ghost text-sm">Cancelar</button>
                            <button type="submit" :disabled="proc || !form.contacto_id || form.monto_bruto <= 0" class="btn-primary text-sm">
                                {{ proc ? 'Registrando…' : 'Registrar pago' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
