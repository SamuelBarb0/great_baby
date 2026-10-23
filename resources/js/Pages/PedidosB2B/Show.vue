<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { ArrowLeft, CheckCircle, XCircle, FileText, User } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    pedido: { type: Object, required: true },
    items: { type: Array, required: true },
    // Multiempresa · razones sociales activas para cambiar quién factura.
    empresas: { type: Array, default: () => [] },
});

const cambiarEmpresa = (e) => {
    router.post(`/app/pedidos-b2b/${props.pedido.id}/empresa`, { empresa_id: Number(e.target.value) }, { preserveScroll: true });
};

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);
const modalRechazar = ref(false);
const modalConfirmar = ref(null); // { tipo: 'aprobar'|'facturar', msg }
const motivo = ref('');
const motivoError = ref('');
const procesando = ref(false);
// LOG-J7
const modalDespachar = ref(false);
const despachoForm = ref({ guia_transportadora: '', transportadora: '' });
const despachoError = ref('');

const notifyError = (mensaje) => {
    if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('gb:error', { detail: { mensaje } }));
    }
};

const pedirAprobar = () => modalConfirmar.value = {
    tipo: 'aprobar',
    msg: props.pedido.estado === 'retenido'
        ? '¿Liberar este pedido retenido? Confirmás que Gerencia autoriza vender aunque el semáforo de cartera lo bloqueó. Quedará listo para facturar.'
        : '¿Aprobar este pedido? Podrás facturarlo después.',
};
const pedirFacturar = () => modalConfirmar.value = { tipo: 'facturar', msg: `Facturar por ${money(props.pedido.total)}?` };

const ejecutarConfirmacion = () => {
    if (!modalConfirmar.value || procesando.value) return;
    const url = modalConfirmar.value.tipo === 'aprobar'
        ? `/app/pedidos-b2b/${props.pedido.id}/aprobar`
        : `/app/pedidos-b2b/${props.pedido.id}/facturar`;
    procesando.value = true;
    router.post(url, {}, {
        preserveScroll: true,
        onSuccess: () => { modalConfirmar.value = null; },
        onError: (e) => {
            const msg = Object.values(e).flat().find(v => typeof v === 'string') || 'No se pudo completar la acción.';
            notifyError(msg);
            modalConfirmar.value = null;
        },
        onFinish: () => { procesando.value = false; },
    });
};

// ESC cierra cualquier modal abierto
useEventListener(typeof window !== 'undefined' ? window : null, 'keydown', (e) => {
    if (e.key === 'Escape') {
        modalConfirmar.value = null;
        modalRechazar.value = false;
        modalDespachar.value = false;
    }
});

// LOG-J7 · despachar pedido con guía. El backend ya tiene el guard duro
// (sin factura = 422), pero igual validamos acá el input mínimo.
const despachar = () => {
    despachoError.value = '';
    if (despachoForm.value.guia_transportadora.trim().length < 3) {
        despachoError.value = 'La guía es obligatoria (mínimo 3 caracteres).';
        return;
    }
    procesando.value = true;
    router.post(`/app/pedidos-b2b/${props.pedido.id}/despachar`, { ...despachoForm.value }, {
        preserveScroll: true,
        onSuccess: () => {
            modalDespachar.value = false;
            despachoForm.value = { guia_transportadora: '', transportadora: '' };
        },
        onError: (e) => {
            const msg = Object.values(e).flat().find(v => typeof v === 'string') || 'No se pudo despachar.';
            despachoError.value = msg;
        },
        onFinish: () => { procesando.value = false; },
    });
};

const rechazar = () => {
    motivoError.value = '';
    if (motivo.value.trim().length < 10) {
        motivoError.value = 'El motivo debe tener al menos 10 caracteres.';
        return;
    }
    procesando.value = true;
    router.post(`/app/pedidos-b2b/${props.pedido.id}/rechazar`, { motivo: motivo.value }, {
        preserveScroll: true,
        onSuccess: () => { modalRechazar.value = false; motivo.value = ''; },
        onError: (e) => { motivoError.value = e.motivo || 'No se pudo rechazar. Reintenta.'; },
        onFinish: () => { procesando.value = false; },
    });
};
</script>

<template>
    <Head :title="'Pedido ' + pedido.numero"/>
    <AppLayout>
        <div class="max-w-5xl mx-auto space-y-4">
            <Link href="/app/pedidos-b2b" class="btn-ghost inline-flex items-center gap-1 text-sm">
                <ArrowLeft class="h-4 w-4"/> Volver
            </Link>

            <div class="card p-6">
                <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
                    <div>
                        <div class="text-xs text-surface-500 uppercase">Pedido B2B</div>
                        <h1 class="text-2xl font-bold">{{ pedido.numero }}</h1>
                        <div class="text-xs text-surface-500 mt-1">Recibido {{ pedido.creado }}</div>
                        <!-- Multiempresa · quién factura; se cambia sólo antes de facturar. -->
                        <div class="text-sm mt-2 flex items-center gap-2">
                            <span class="text-xs text-surface-500 uppercase">Factura</span>
                            <select v-if="empresas.length > 1 && !pedido.factura && pedido.estado !== 'rechazado'"
                                    :value="pedido.empresa_id" @change="cambiarEmpresa"
                                    class="input py-1 text-sm">
                                <option v-for="e in empresas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                            </select>
                            <b v-else>{{ pedido.empresa }}</b>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-surface-500 uppercase">Total</div>
                        <div class="text-3xl font-bold text-brand-600">{{ money(pedido.total) }}</div>
                    </div>
                </div>

                <!-- Acciones -->
                <div class="flex items-center gap-2 flex-wrap border-t border-surface-200 dark:border-surface-800 pt-4">
                    <!-- LOG-J3 · Gerencia puede aprobar también un retenido (es "liberar") -->
                    <button v-if="['enviado','retenido'].includes(pedido.estado)" @click="pedirAprobar" :disabled="procesando"
                            :class="pedido.estado === 'retenido' ? 'btn-primary bg-amber-600 hover:bg-amber-700' : 'btn-primary'">
                        <CheckCircle class="h-4 w-4"/>
                        {{ pedido.estado === 'retenido' ? 'Liberar (Gerencia)' : 'Aprobar' }}
                    </button>
                    <button v-if="['enviado','retenido','aprobado'].includes(pedido.estado)" @click="modalRechazar = true" :disabled="procesando" class="btn-ghost text-red-600 border border-red-300">
                        <XCircle class="h-4 w-4"/> Rechazar
                    </button>
                    <button v-if="pedido.estado === 'aprobado'" @click="pedirFacturar" :disabled="procesando" class="btn-primary bg-emerald-600 hover:bg-emerald-700">
                        <FileText class="h-4 w-4"/> Facturar
                    </button>
                    <Link v-if="pedido.factura" :href="`/app/facturas/${pedido.factura.id}`" class="btn-ghost">
                        <FileText class="h-4 w-4"/> Ver factura {{ pedido.factura.numero }}
                    </Link>
                    <!-- LOG-J7 · Despachar · basta con factura emitida (la sync SIIGO va en segundo plano) -->
                    <button v-if="pedido.factura && !pedido.despachado_at && pedido.estado !== 'rechazado'"
                            @click="modalDespachar = true"
                            :disabled="procesando"
                            class="btn-primary bg-indigo-600 hover:bg-indigo-700">
                        🚚 Despachar
                    </button>
                    <!-- Nota blanda sobre SIIGO · informativa, no bloquea -->
                    <span v-if="pedido.factura && !pedido.factura.siigo_id && !pedido.despachado_at"
                          class="text-[10px] text-surface-500 italic"
                          title="La sync con SIIGO se completa automáticamente; no detiene el despacho.">
                        · factura todavía sin SIIGO (sync en curso)
                    </span>
                    <!-- Advertencia dura solo si NO hay factura (caso raíz inventario negativo) -->
                    <span v-else-if="pedido.estado === 'aprobado' && !pedido.factura" class="text-xs text-amber-700 bg-amber-50 border-l-2 border-amber-500 px-2 py-1 rounded">
                        ⚠ Facturá antes de despachar · no sale mercancía sin documento
                    </span>
                    <span class="ml-auto text-sm text-surface-500">Estado: <b>{{ pedido.estado }}</b></span>
                </div>

                <div v-if="pedido.motivo_rechazo" class="mt-4 p-3 rounded-lg bg-red-50 border-l-4 border-red-500 text-red-700 text-sm">
                    <b>Motivo de rechazo:</b> {{ pedido.motivo_rechazo }}
                </div>

                <!-- LOG-J2 · Banner de retención automática por cartera.
                     Don Jorge NO ve este pedido en su cola hasta que Gerencia
                     libere o rechace. El motivo es visible y accionable. -->
                <div v-if="pedido.estado === 'retenido'" class="mt-4 p-3 rounded-lg bg-amber-50 border-l-4 border-amber-500 text-sm">
                    <div class="font-bold text-amber-800">⏸ Pedido retenido por cartera</div>
                    <div class="text-amber-700 mt-1">{{ pedido.motivo_retencion || 'Pendiente de autorización de Gerencia.' }}</div>
                    <div class="text-xs text-amber-600 mt-2">
                        Mientras esté retenido no entra a la cola de alistamiento.
                        Gerencia debe aprobarlo o rechazarlo desde "Excepciones de crédito".
                    </div>
                </div>

                <!-- LOG-J7 · info del despacho una vez hecho -->
                <div v-if="pedido.despachado_at" class="mt-4 p-3 rounded-lg bg-indigo-50 border-l-4 border-indigo-500 text-sm">
                    <b class="text-indigo-800">✓ Despachado</b> · {{ pedido.despachado_at }}
                    <span v-if="pedido.transportadora"> · <b>{{ pedido.transportadora }}</b></span>
                    · guía <code class="bg-white px-1 rounded">{{ pedido.guia_transportadora }}</code>
                </div>
            </div>

            <!-- LOG-J10 · Timeline único del pedido · todos los hitos sin saltar módulo -->
            <div v-if="pedido.timeline && pedido.timeline.length" class="card p-5">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-4 flex items-center gap-2">
                    📍 Línea de tiempo del pedido
                </div>
                <ol class="relative border-l-2 border-surface-200 dark:border-surface-700 ml-3 space-y-5">
                    <li v-for="(h, idx) in pedido.timeline" :key="idx" class="pl-6 relative">
                        <span class="absolute -left-[13px] top-0 inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold ring-4 ring-white dark:ring-surface-950"
                              :class="{
                                'bg-surface-200 text-surface-800': h.color === 'surface',
                                'bg-blue-100 text-blue-700': h.color === 'blue',
                                'bg-emerald-100 text-emerald-700': h.color === 'emerald',
                                'bg-red-100 text-red-700': h.color === 'red',
                                'bg-brand-100 text-brand-700': h.color === 'brand',
                                'bg-indigo-100 text-indigo-700': h.color === 'indigo',
                              }">
                            {{ h.icon }}
                        </span>
                        <div class="flex items-baseline justify-between gap-3">
                            <div>
                                <div class="font-bold text-sm">{{ h.hito }}</div>
                                <div class="text-xs text-surface-500 mt-0.5">
                                    {{ h.actor }} · <time class="font-mono">{{ h.at }}</time>
                                </div>
                                <div class="text-xs text-surface-600 dark:text-surface-400 mt-1">{{ h.detalle }}</div>
                            </div>
                        </div>
                    </li>
                </ol>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="card p-4 md:col-span-2">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">Ítems ({{ items.length }})</div>
                    <div class="overflow-x-auto">
                        <table v-tabla-movil class="w-full text-sm">
                            <thead class="text-xs text-surface-500 uppercase">
                                <tr>
                                    <th class="text-left p-2">SKU / Producto</th>
                                    <th class="text-right p-2">Cant.</th>
                                    <th class="text-right p-2">Precio</th>
                                    <th class="text-right p-2">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                                <tr v-for="(it, idx) in items" :key="idx">
                                    <td class="p-2">
                                        <div class="font-mono text-xs text-surface-500">{{ it.sku }}</div>
                                        <div>{{ it.desc }}</div>
                                    </td>
                                    <td class="p-2 text-right">{{ it.cantidad }}</td>
                                    <td class="p-2 text-right">{{ money(it.precio) }}</td>
                                    <td class="p-2 text-right font-bold">{{ money(it.total) }}</td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t border-surface-200 dark:border-surface-800">
                                <tr><td colspan="3" class="p-2 text-right text-xs text-surface-500">Subtotal</td><td class="p-2 text-right">{{ money(pedido.subtotal) }}</td></tr>
                                <tr><td colspan="3" class="p-2 text-right text-xs text-surface-500">IVA</td><td class="p-2 text-right">{{ money(pedido.iva) }}</td></tr>
                                <tr class="font-bold"><td colspan="3" class="p-2 text-right">Total</td><td class="p-2 text-right text-brand-600">{{ money(pedido.total) }}</td></tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="card p-4">
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3 flex items-center gap-2">
                            <User class="h-3 w-3"/> Cliente
                        </div>
                        <div class="text-sm space-y-1">
                            <div class="font-semibold">{{ pedido.contacto.nombre }}</div>
                            <div class="text-xs text-surface-500">{{ pedido.contacto.email }}</div>
                            <div class="text-xs text-surface-500">{{ pedido.contacto.telefono }}</div>
                            <div class="text-xs text-surface-500">{{ pedido.contacto.ciudad }}</div>
                            <div class="text-xs text-surface-500 pt-2 border-t border-surface-200 dark:border-surface-800 mt-2">
                                Lista: <b>{{ pedido.lista || '—' }}</b>
                            </div>
                        </div>
                    </div>

                    <div v-if="pedido.notas_cliente" class="card p-4">
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-2">Notas del cliente</div>
                        <p class="text-sm">{{ pedido.notas_cliente }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal rechazar -->
        <div v-if="modalRechazar" @click.self="modalRechazar = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-surface-900 rounded-xl shadow-2xl p-6 max-w-md w-full">
                <h3 class="text-lg font-bold mb-3">Rechazar pedido</h3>
                <label class="block text-xs font-semibold text-surface-600 mb-1">Motivo (mín 10 caracteres, visible para el cliente)</label>
                <textarea v-model="motivo" rows="3" maxlength="500" class="input w-full" autofocus
                    placeholder="Ej: Sin stock disponible en las tallas solicitadas."></textarea>
                <div v-if="motivoError" class="mt-2 text-sm text-red-600">{{ motivoError }}</div>
                <div class="flex items-center justify-end gap-2 mt-4">
                    <button @click="modalRechazar = false" class="btn-ghost">Cancelar</button>
                    <button @click="rechazar" :disabled="procesando" class="btn-primary bg-red-600 hover:bg-red-700">
                        {{ procesando ? 'Rechazando...' : 'Rechazar pedido' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal confirmar aprobar/facturar (reemplaza window.confirm que robaba foco pistola) -->
        <div v-if="modalConfirmar" @click.self="modalConfirmar = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-surface-900 rounded-xl shadow-2xl p-6 max-w-md w-full">
                <h3 class="text-lg font-bold mb-3">Confirmar acción</h3>
                <p class="text-sm text-surface-700 dark:text-surface-300">{{ modalConfirmar.msg }}</p>
                <div class="flex items-center justify-end gap-2 mt-4">
                    <button @click="modalConfirmar = null" class="btn-ghost">Cancelar</button>
                    <button @click="ejecutarConfirmacion" :disabled="procesando" class="btn-primary">
                        {{ procesando ? 'Procesando...' : 'Confirmar' }}
                    </button>
                </div>
            </div>

        </div>

        <!--
            LOG-J7 · Modal despachar · exige guía antes de registrar la salida.

            Estaba ANIDADO dentro del modal «Confirmar acción» de arriba, así que su
            `v-if` sólo se evaluaba cuando ese otro modal estaba abierto: nunca. El
            botón «Despachar» no hacía absolutamente nada y un pedido B2B no se podía
            despachar desde esta pantalla, así que el descuento de stock al despachar
            jamás llegó a correr en la práctica.
        -->
        <div v-if="modalDespachar" @click.self="modalDespachar = false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="bg-white dark:bg-surface-900 rounded-lg shadow-xl w-full max-w-md p-6">
                <h3 class="text-lg font-bold mb-1 flex items-center gap-2">🚚 Despachar pedido</h3>
                <p class="text-sm text-surface-500 mb-4">
                    Registrás la salida física al transportador. El inventario queda con huella.
                </p>
                <div class="space-y-3">
                    <div>
                        <label class="text-xs font-semibold">Transportadora <span class="text-surface-400">(opcional)</span></label>
                        <input v-model="despachoForm.transportadora" type="text" placeholder="Servientrega, Interrapidísimo…" class="input w-full text-sm" maxlength="80"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">N° de guía <span class="text-red-600">*</span></label>
                        <input v-model="despachoForm.guia_transportadora" type="text" placeholder="Número que da la transportadora" class="input w-full text-sm font-mono" maxlength="60" required/>
                    </div>
                    <div v-if="despachoError" class="text-xs text-red-600 bg-red-50 border border-red-200 p-2 rounded">
                        {{ despachoError }}
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 mt-5">
                    <button @click="modalDespachar = false" :disabled="procesando" class="btn-ghost">Cancelar</button>
                    <button @click="despachar" :disabled="procesando" class="btn-primary bg-indigo-600 hover:bg-indigo-700">
                        {{ procesando ? 'Despachando…' : 'Confirmar despacho' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
