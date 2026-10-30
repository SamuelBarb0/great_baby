<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle, XCircle, Truck, ShoppingCart, AlertTriangle, Cloud, CloudOff, CloudCog, RefreshCw, Container } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import { fechaCorta } from '@/composables/useFecha';
import { useEscClose } from '@/composables/useEscClose';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';

const props = defineProps({ orden: { type: Object, required: true } });

const modalAnular = ref(false);
const modalAprobar = ref(false);
useEscClose(modalAnular);
useEscClose(modalAprobar);
const motivo = ref('');
const procesando = ref(false);

// Re-audit M2 UX-C2 · useMoney (paridad con Cartera/Contabilidad).
const { money } = useMoney();

// Re-audit M2 UX-C4 · modal propio en lugar de confirm() nativo (consistencia
// con Anular). Muestra el monto que se compromete al aprobar.
const abrirAprobar = () => { modalAprobar.value = true; };
const confirmarAprobar = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post(`/app/compras/oc/${props.orden.id}/aprobar`, {}, {
        preserveScroll: true,
        onSuccess: () => { modalAprobar.value = false; },
        onFinish: () => procesando.value = false,
    });
};
const anular = () => {
    if (motivo.value.trim().length < 10 || procesando.value) return;
    procesando.value = true;
    router.post(`/app/compras/oc/${props.orden.id}/anular`, { motivo: motivo.value }, {
        preserveScroll: true,
        onSuccess: () => { modalAnular.value = false; motivo.value = ''; },
        onFinish: () => procesando.value = false,
    });
};

// Re-audit M2 UX-B11 · dark mode consistente (paridad con Index.vue badges).
const badge = (e) => ({
    borrador: 'bg-surface-100 text-surface-700 dark:bg-surface-800 dark:text-surface-300',
    enviada: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    aprobada: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    parcial: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    recibida: 'bg-brand-100 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200',
    cerrada: 'bg-surface-200 text-surface-600 dark:bg-surface-800 dark:text-surface-400',
    anulada: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
}[e] || 'bg-surface-100 dark:bg-surface-800');

/*
 * Pre-liquidación (sólo OC de importación): costo por unidad puesto en
 * Colombia con la fórmula del Excel de Alejandro. Los parámetros se pueden
 * corregir mientras la OC es borrador; después quedan fijos.
 */
const pre = computed(() => props.orden.preliquidacion);
const parametros = reactive(pre.value ? { ...pre.value.parametros, tasa_cambio: pre.value.tasa_cambio } : {});
const camposParametros = [
    ['tasa_cambio', 'Tasa (COP por unidad de moneda)', '0.01'],
    ['comision_pct', 'Comisión %', '0.01'],
    ['flete_m3', 'Flete por m³', '1'],
    ['gasto_colombia_m3', 'Gasto Colombia por m³', '1'],
    ['arancel_pct', 'Arancel %', '0.01'],
    ['iva_pct', 'IVA %', '0.01'],
    ['divisor_precio_1', 'Divisor precio 1', '0.01'],
    ['divisor_precio_2', 'Divisor precio 2', '0.01'],
    ['capacidad_m3', 'Capacidad (m³)', '0.01'],
    ['valor_minimo', 'Valor mínimo (0 = no revisar)', '0.01'],
];
const recalculando = ref(false);
const recalcular = () => {
    if (recalculando.value) return;
    recalculando.value = true;
    router.post(`/app/compras/oc/${props.orden.id}/parametros`, parametros, {
        preserveScroll: true,
        onFinish: () => recalculando.value = false,
    });
};
const fmt = (n, d = 2) => Number(n || 0).toLocaleString('es-CO', { minimumFractionDigits: d, maximumFractionDigits: d });

// COMP-B6 · semáforo SIIGO agregado de la OC.
const siigoBadge = (e) => ({
    ok:              { cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200', label: 'Sincronizado', icon: Cloud },
    parcial:         { cls: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',          label: 'Parcial',       icon: CloudCog },
    pendiente:       { cls: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',                   label: 'Pendiente',     icon: CloudOff },
    sin_recepciones: { cls: 'bg-surface-100 text-surface-600 dark:bg-surface-800 dark:text-surface-400',      label: 'Sin recepciones', icon: Cloud },
}[e] || { cls: 'bg-surface-100', label: '—', icon: Cloud });

// COMP-B8 · reenvío manual a SIIGO desde la OC/Show.
//
// Solo se pregunta cuando la recepción YA está en SIIGO, que es el caso en el
// que reenviar puede dejar el documento duplicado allá. Si todavía no subió,
// el reenvío es inofensivo y va directo. Antes esto era un confirm() nativo,
// que queda bloqueado dentro del iframe de la app de escritorio.
const reenviando = ref(null);
const modalConfirm = ref(null);
const reenviarRecepcion = (rec) => {
    const enviar = () => {
        reenviando.value = rec.id;
        router.post(`/app/compras/recepcion/${rec.id}/reenviar-siigo`, {}, {
            preserveScroll: true,
            onFinish: () => reenviando.value = null,
        });
    };

    if (! rec.siigo_id) {
        enviar();
        return;
    }

    modalConfirm.value = {
        titulo: `¿Reenviar la recepción ${rec.numero}?`,
        mensaje: `Ya está sincronizada con SIIGO (${rec.siigo_number || rec.siigo_id}). Reenviarla encola un push manual nuevo.`,
        color: 'amber',
        textoConfirmar: 'Reenviar igual',
        onConfirmar: () => {
            modalConfirm.value = null;
            enviar();
        },
    };
};
</script>

<template>
    <Head :title="`OC ${orden.numero}`"/>
    <AppLayout>
        <div class="max-w-5xl mx-auto space-y-4">
            <Link href="/app/compras" class="btn-ghost inline-flex items-center gap-1 text-sm">
                <ArrowLeft class="h-4 w-4"/> Volver
            </Link>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>

            <div class="card p-5">
                <div class="flex items-start justify-between mb-3 flex-wrap gap-3">
                    <div>
                        <div class="text-xs text-surface-500 uppercase">Orden de compra</div>
                        <h1 class="text-2xl font-bold font-mono">{{ orden.numero }}</h1>
                        <div class="text-xs text-surface-500 mt-1">{{ orden.proveedor }} · {{ orden.tipo }} · {{ orden.moneda }} @ {{ orden.tasa_cambio }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-surface-500 uppercase">Total</div>
                        <div class="text-2xl font-bold text-brand-600">{{ money(orden.total) }}</div>
                        <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(orden.estado)]">{{ orden.estado }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-2 border-t border-surface-200 dark:border-surface-800 pt-3 flex-wrap">
                    <button v-if="orden.estado === 'borrador' || orden.estado === 'enviada'" @click="abrirAprobar" :disabled="procesando" class="btn-primary">
                        <CheckCircle class="h-4 w-4"/> Aprobar
                    </button>
                    <Link v-if="['aprobada','parcial'].includes(orden.estado)" :href="`/app/compras/recepcion/nueva?oc=${orden.id}`" class="btn-primary bg-emerald-600 hover:bg-emerald-700">
                        <Truck class="h-4 w-4"/> Recibir mercancía
                    </Link>
                    <button v-if="!['recibida','cerrada','anulada'].includes(orden.estado)" @click="modalAnular = true" class="btn-ghost text-red-600 border border-red-300">
                        <XCircle class="h-4 w-4"/> Anular
                    </button>
                    <a :href="`/compras/orden/${orden.id}/pdf`" target="_blank" rel="noopener" class="btn-ghost">PDF</a>
                    <a v-if="orden.preliquidacion" :href="`/compras/orden/${orden.id}/preliquidacion/pdf`" target="_blank" rel="noopener" class="btn-ghost">PDF pre-liquidación</a>
                </div>
            </div>

            <div class="card p-4">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">Ítems ({{ orden.items.length }})</div>
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-[10px] text-surface-500 uppercase border-b">
                            <tr>
                                <th class="text-left p-2">Descripción</th>
                                <th class="text-right p-2">Cant</th>
                                <th class="text-right p-2">Recibida</th>
                                <th class="text-right p-2">Precio</th>
                                <th class="text-right p-2">Subtotal</th>
                                <th class="text-right p-2">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="it in orden.items" :key="it.id">
                                <td class="p-2">{{ it.descripcion }}</td>
                                <td class="p-2 text-right">{{ it.cantidad }}</td>
                                <td class="p-2 text-right" :class="it.cantidad_recibida < it.cantidad ? 'text-amber-600' : 'text-emerald-600'">{{ it.cantidad_recibida }}</td>
                                <td class="p-2 text-right">{{ money(it.precio_unit) }}</td>
                                <td class="p-2 text-right">{{ money(it.subtotal) }}</td>
                                <td class="p-2 text-right font-bold">{{ money(it.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="pre" class="card p-4 space-y-3">
                <div class="flex items-start justify-between flex-wrap gap-3">
                    <div class="flex items-center gap-2">
                        <Container class="h-5 w-5 text-brand-600"/>
                        <div>
                            <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Pre-liquidación</div>
                            <div class="text-xs text-surface-500">Costo estimado puesto en Colombia, antes de pedir</div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-surface-500 uppercase">Costo total</div>
                        <div class="text-xl font-bold text-brand-600">{{ money(pre.totales.costo_total) }}</div>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div><div class="text-[10px] uppercase text-surface-500">Cajas</div><b>{{ fmt(pre.totales.cajas, 0) }}</b></div>
                    <div><div class="text-[10px] uppercase text-surface-500">Unidades</div><b>{{ fmt(pre.totales.unidades, 0) }}</b></div>
                    <div>
                        <div class="text-[10px] uppercase text-surface-500">Volumen</div>
                        <b :class="pre.totales.ocupacion_pct > 100 ? 'text-red-600' : ''">{{ fmt(pre.totales.cbm) }} m³</b>
                        <span class="text-surface-500"> de {{ fmt(pre.totales.capacidad_m3) }} ({{ fmt(pre.totales.ocupacion_pct, 1) }} %)</span>
                    </div>
                    <div><div class="text-[10px] uppercase text-surface-500">Valor</div><b>{{ orden.moneda }} {{ fmt(pre.totales.valor_moneda) }}</b></div>
                </div>
                <div class="h-2 rounded bg-surface-200 dark:bg-surface-800 overflow-hidden">
                    <div class="h-full" :class="pre.totales.ocupacion_pct > 100 ? 'bg-red-500' : 'bg-emerald-500'"
                         :style="{ width: Math.min(pre.totales.ocupacion_pct || 0, 100) + '%' }"></div>
                </div>
                <div class="text-xs text-surface-500">
                    <span v-for="(d, i) in pre.por_destino" :key="d.empresa_id"><template v-if="i"> · </template><b>{{ d.nombre }}</b> {{ fmt(d.cajas, 0) }} cajas, {{ fmt(d.cbm) }} m³</span>
                </div>
                <div v-for="(a, i) in pre.avisos" :key="i" class="p-2 rounded bg-amber-50 dark:bg-amber-950/40 border-l-4 border-amber-500 text-xs text-amber-800 dark:text-amber-200">
                    {{ a }}
                </div>

                <details class="text-sm" :open="orden.estado === 'borrador'">
                    <summary class="cursor-pointer text-xs font-semibold text-surface-600 dark:text-surface-300">
                        Parámetros {{ orden.estado === 'borrador' ? '(se pueden corregir mientras es borrador)' : '(fijos: la OC ya no es borrador)' }}
                    </summary>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-2 mt-2">
                        <div v-for="[clave, etiqueta, paso] in camposParametros" :key="clave">
                            <label class="text-[10px] font-semibold">{{ etiqueta }}</label>
                            <input type="number" :step="paso" v-model.number="parametros[clave]" :disabled="orden.estado !== 'borrador'" class="input w-full text-sm"/>
                        </div>
                    </div>
                    <div v-if="orden.estado === 'borrador'" class="flex justify-end mt-2">
                        <button @click="recalcular" :disabled="recalculando" class="btn-primary text-sm">
                            {{ recalculando ? 'Recalculando…' : 'Recalcular' }}
                        </button>
                    </div>
                </details>

                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-xs">
                        <thead class="text-[10px] text-surface-500 uppercase border-b">
                            <tr>
                                <th class="text-left p-1">Referencia</th>
                                <th class="text-right p-1">Cajas</th>
                                <th class="text-right p-1">Uds/caja</th>
                                <th class="text-right p-1">CBM caja</th>
                                <th class="text-right p-1">China c/com.</th>
                                <th class="text-right p-1">Flete</th>
                                <th class="text-right p-1">Arancel</th>
                                <th class="text-right p-1">IVA</th>
                                <th class="text-right p-1">Gasto Col.</th>
                                <th class="text-right p-1">Costo</th>
                                <th class="text-right p-1">Precio {{ pre.parametros.divisor_precio_1 }}</th>
                                <th class="text-right p-1">Precio {{ pre.parametros.divisor_precio_2 }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="l in pre.lineas" :key="l.id">
                                <td class="p-1">{{ l.descripcion }}</td>
                                <td class="p-1 text-right">{{ fmt(l.cajas, 0) }}</td>
                                <td class="p-1 text-right">{{ fmt(l.unidades_por_caja, 0) }}</td>
                                <td class="p-1 text-right">{{ fmt(l.cbm_caja, 4) }}</td>
                                <template v-if="l.unidad">
                                    <td class="p-1 text-right">{{ money(l.unidad.costo_china_comision) }}</td>
                                    <td class="p-1 text-right">{{ money(l.unidad.flete) }}</td>
                                    <td class="p-1 text-right">{{ money(l.unidad.arancel) }}</td>
                                    <td class="p-1 text-right">{{ money(l.unidad.iva) }}</td>
                                    <td class="p-1 text-right">{{ money(l.unidad.gasto_colombia) }}</td>
                                    <td class="p-1 text-right font-bold">{{ money(l.unidad.costo) }}</td>
                                    <td class="p-1 text-right">{{ money(l.unidad.precio_1) }}</td>
                                    <td class="p-1 text-right">{{ money(l.unidad.precio_2) }}</td>
                                </template>
                                <td v-else colspan="8" class="p-1 text-amber-600">Sin unidades por caja o sin CBM</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- COMP-B6 · Semáforo SIIGO + recepciones asociadas (+ reenvío manual COMP-B8). -->
            <div v-if="orden.siigo" class="card p-4">
                <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <component :is="siigoBadge(orden.siigo.estado).icon" class="h-5 w-5"/>
                        <div>
                            <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Estado SIIGO</div>
                            <div class="text-xs text-surface-500">{{ orden.siigo.sincronizadas }} de {{ orden.siigo.total }} recepciones sincronizadas</div>
                        </div>
                    </div>
                    <span :class="['px-3 py-1 rounded-full text-xs font-bold uppercase', siigoBadge(orden.siigo.estado).cls]">
                        {{ siigoBadge(orden.siigo.estado).label }}
                    </span>
                </div>
                <div v-if="orden.siigo.total === 0" class="text-sm text-surface-500 italic">Aún sin recepciones confirmadas.</div>
                <ul v-else class="divide-y divide-surface-200 dark:divide-surface-800 text-sm">
                    <li v-for="rec in orden.siigo.recepciones" :key="rec.id" class="flex items-center justify-between py-2 gap-3 flex-wrap">
                        <div class="flex items-center gap-2 min-w-0">
                            <Cloud v-if="rec.siigo_id" class="h-4 w-4 text-emerald-600 flex-shrink-0"/>
                            <CloudOff v-else class="h-4 w-4 text-red-500 flex-shrink-0"/>
                            <div class="min-w-0">
                                <div class="font-mono text-xs truncate">{{ rec.numero }}</div>
                                <div class="text-[11px] text-surface-500">
                                    {{ rec.fecha }}
                                    <template v-if="rec.siigo_number"> · FC {{ rec.siigo_number }}</template>
                                    <template v-else-if="rec.siigo_id"> · {{ rec.siigo_id.substring(0, 12) }}…</template>
                                    <template v-else> · <span class="text-red-600">sin SIIGO</span></template>
                                </div>
                            </div>
                        </div>
                        <button @click="reenviarRecepcion(rec)" :disabled="reenviando === rec.id" class="btn-ghost text-xs">
                            <RefreshCw :class="['h-3 w-3', reenviando === rec.id && 'animate-spin']"/>
                            {{ reenviando === rec.id ? 'Enviando…' : (rec.siigo_id ? 'Reenviar' : 'Enviar a SIIGO') }}
                        </button>
                    </li>
                </ul>
            </div>

            <div v-if="orden.observaciones" class="card p-4">
                <div class="text-xs uppercase font-bold text-brand-600 mb-2">Observaciones</div>
                <p class="text-sm whitespace-pre-wrap">{{ orden.observaciones }}</p>
            </div>
        </div>

        <!-- Re-audit M2 UX-C4 · modal Aprobar con contexto financiero. -->
        <div v-if="modalAprobar" @click.self="modalAprobar = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full">
                <div class="flex items-start gap-3">
                    <CheckCircle class="h-6 w-6 text-emerald-600 mt-1 flex-shrink-0"/>
                    <div>
                        <h3 class="text-lg font-bold mb-1">¿Aprobar OC {{ orden.numero }}?</h3>
                        <p class="text-sm text-surface-600 dark:text-surface-400 mb-3">
                            Comprometes <b>{{ money(orden.total) }}</b> con <b>{{ orden.proveedor }}</b>. Después de aprobar la OC ya no admite edición de precios/cantidades — solo recepción.
                        </p>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalAprobar = false" class="btn-ghost" :disabled="procesando">Cancelar</button>
                    <button @click="confirmarAprobar" :disabled="procesando" class="btn-primary">
                        {{ procesando ? 'Aprobando…' : 'Sí, aprobar' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Re-audit M2 UX-C3 · modal Anular AHORA MUESTRA MONTO + PROVEEDOR (antes solo pedía motivo, Aracely podía anular OC de $52M a ciegas). -->
        <div v-if="modalAnular" @click.self="modalAnular = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full">
                <div class="flex items-start gap-3">
                    <AlertTriangle class="h-6 w-6 text-red-600 mt-1 flex-shrink-0"/>
                    <div class="flex-1">
                        <h3 class="text-lg font-bold mb-1">¿Anular esta OC?</h3>
                        <div class="text-xs bg-red-50 dark:bg-red-950/40 rounded p-2 mb-3 space-y-1">
                            <div class="flex justify-between"><span class="text-surface-500">OC:</span> <b class="font-mono">{{ orden.numero }}</b></div>
                            <div class="flex justify-between"><span class="text-surface-500">Proveedor:</span> <b>{{ orden.proveedor }}</b></div>
                            <div class="flex justify-between text-red-700 dark:text-red-400 pt-1 border-t border-red-200 dark:border-red-900"><span>Total comprometido:</span> <b>{{ money(orden.total) }}</b></div>
                        </div>
                        <p class="text-[11px] text-surface-500 mb-2">La anulación es definitiva y queda auditada. Se guarda tu usuario, fecha y motivo.</p>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Motivo (obligatorio, mín. 10 caracteres)</label>
                        <textarea v-model="motivo" rows="3" class="input w-full mt-1" placeholder="Explica por qué se anula esta OC…" autofocus/>
                        <p class="text-[11px] mt-1" :class="motivo.trim().length >= 10 ? 'text-emerald-600' : 'text-red-600'">
                            {{ motivo.trim().length }}/10 caracteres
                        </p>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalAnular = false" class="btn-ghost" :disabled="procesando">Cancelar</button>
                    <button @click="anular" :disabled="procesando || motivo.trim().length < 10" class="btn-primary bg-red-600 hover:bg-red-700 disabled:opacity-50">
                        {{ procesando ? 'Anulando…' : 'Sí, anular' }}
                    </button>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
