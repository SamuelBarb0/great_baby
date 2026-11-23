<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { ArrowLeft, CheckCircle, XCircle, FileText, User } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useEscClose } from '@/composables/useEscClose';

const props = defineProps({
    pedido: { type: Object, required: true },
    items: { type: Array, required: true },
    // Multiempresa · razones sociales activas para cambiar quién factura.
    empresas: { type: Array, default: () => [] },
    // De qué bodega puede salir el pedido. Sin bodega no se aparta mercancía
    // y bodega nunca lo ve en su cola, así que esto no es un detalle.
    bodegas_origen: { type: Array, default: () => [] },
    puede_cambiar_bodega: { type: Boolean, default: false },
    // Las que configuró el área en /app/reglas. Se eligen, no se teclean:
    // escrita de tres formas distintas, la misma transportadora no se puede
    // medir ni rastrear.
    transportadoras: { type: Array, default: () => [] },
    /*
     * Qué puede apretar QUIEN está mirando. Antes los botones salían sólo por
     * `pedido.estado`, así que un despachador veía «Aprobar», «Rechazar» y
     * «Facturar» — y el botón de un retenido le decía «Liberar (Gerencia)».
     * El default en false es a propósito: si el controlador algún día deja de
     * mandarlo, el botón desaparece en vez de quedar abierto.
     */
    puedo: {
        type: Object,
        default: () => ({ aprobar: false, facturar: false, despachar: false }),
    },

});

const cambiarEmpresa = (e) => {
    router.post(`/app/pedidos-b2b/${props.pedido.id}/empresa`, { empresa_id: Number(e.target.value) }, { preserveScroll: true });
};

const errorBodega = ref(null);

const cambiarBodega = (e) => {
    const elegido = Number(e.target.value);
    errorBodega.value = null;
    router.post(`/app/pedidos-b2b/${props.pedido.id}/bodega`, { ubicacion_origen_id: elegido }, {
        preserveScroll: true,
        // Sin esto, un rechazo del servidor —pedido ya despachado, bodega
        // desactivada— dejaba el select mostrando lo que la persona eligió y
        // ningún mensaje: parecía que había quedado guardado.
        onError: (errs) => { errorBodega.value = Object.values(errs)[0] || 'No se pudo cambiar la bodega.'; },
    });
};

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);
const modalRechazar = ref(false);
useEscClose(modalRechazar);
const modalConfirmar = ref(null);
useEscClose(modalConfirmar); // { tipo: 'aprobar'|'facturar', msg }
const motivo = ref('');
const motivoError = ref('');
const procesando = ref(false);
// LOG-J7
const modalDespachar = ref(false);
useEscClose(modalDespachar);
/*
 * Las cantidades arrancan en lo PENDIENTE de cada línea: no tocar nada
 * despacha completo, que es como se despachó siempre. Bajar un número es el
 * parcial.
 */
const cantidadesIniciales = () => Object.fromEntries(props.items.map((i) => [i.id, i.pendiente]));

const despachoForm = ref({
    guia_transportadora: '',
    transportadora: '',
    guias_extra: [],
    cantidades: cantidadesIniciales(),
    // Por defecto, lo que no sale SIGUE debiéndose: es lo menos destructivo.
    // Cerrar un pedido con mercancía sin entregar no se hace por omisión.
    resto_pendiente: true,
});
const despachoError = ref('');

/** ¿Va incompleto? Sólo entonces hay algo que decidir sobre el resto. */
const hayFaltante = computed(() => props.items.some(
    (i) => Number(despachoForm.value.cantidades[i.id] ?? i.pendiente) < i.pendiente,
));

/*
 * Cuánto sale en ESTE viaje, sumando todas las líneas.
 *
 * Existe para una sola cosa: no dejar mandar un despacho de cero. El
 * 21-nov-2026 se despachó un pedido con 0 unidades y quedó la guía escrita,
 * una caja creada y el pedido marcado como parcial, sin que saliera nada. Esa
 * guía fantasma salía después en Seguimiento de guías sobre un pedido que
 * decía «Pendiente de despacho».
 *
 * El `??` NO alcanza como red: una casilla que la persona deja en blanco manda
 * la cadena vacía, no `undefined`, así que el valor por defecto no aplica y
 * `Number('')` da 0. O sea que borrar la casilla ya despachaba cero.
 */
const totalSale = computed(() => props.items.reduce(
    (suma, i) => suma + (Number(despachoForm.value.cantidades[i.id] ?? i.pendiente) || 0), 0,
));

/*
 * Las cajas, agrupadas por el envío en que salieron.
 *
 * Se agrupa por la columna `viaje` y no por la hora de creación: dos despachos
 * del mismo pedido en el mismo minuto, o una corrección a mano, y agrupar por
 * marca de tiempo miente sin avisar.
 */
const cajasPorViaje = computed(() => (props.pedido.cajas || []).reduce((grupos, c) => {
    (grupos[c.viaje || 1] ??= []).push(c);
    return grupos;
}, {}));

// Lo que lleva el pedido en total, para el renglón «salieron X de Y».
const totalPedido = computed(() => props.items.reduce((s, i) => s + Number(i.cantidad || 0), 0));
const totalDespachado = computed(() => props.items.reduce((s, i) => s + Number(i.despachada || 0), 0));
const totalFaltante = computed(() => Math.max(0, totalPedido.value - totalDespachado.value));

/*
 * ¿Esta transportadora da una guía por caja?
 *
 * Hoy sólo Interrapidísimo, según lo que contó el área. No se usa para
 * obligar nada —a veces una caja va por otro lado— sino para avisar: si no se
 * dice, alguien despacha tres cajas con una sola guía y las otras dos quedan
 * sin rastro.
 */
const guiaPorCaja = computed(() => /interrapid/i.test(despachoForm.value.transportadora || ''));

/*
 * «Otra…» del selector de transportadora.
 *
 * El valor centinela `__otra__` nunca se manda al servidor: en cuanto se
 * elige, se cambia la lista por una casilla de texto y se limpia el campo. Si
 * se mandara, quedaría guardado como nombre de transportadora y después
 * aparecería en la lista de un pedido viejo.
 */
const otraTransportadora = ref(false);

watch(() => despachoForm.value.transportadora, (v) => {
    if (v === '__otra__') {
        otraTransportadora.value = true;
        despachoForm.value.transportadora = '';
    }
});

/*
 * EL ERROR SE BORRA SOLO AL CORREGIR, Y AL CERRAR EL MODAL.
 *
 * Sin esto el recuadro rojo se quedaba puesto: la persona corregía la guía
 * repetida, el mensaje seguía diciendo «hay guías repetidas», y quedaba leyendo
 * un error que ya no era cierto. Y el `onSuccess` reseteaba el formulario pero
 * no el error, así que al reabrir el modal reaparecía el del intento anterior.
 *
 * Va con un `watch` sobre el modal y no apagándolo en cada cierre porque hay
 * CUATRO formas de cerrarlo —el botón, el clic afuera, la tecla ESC y el
 * éxito—, y la quinta que alguien agregue mañana se olvidaría.
 */
watch(modalDespachar, () => { despachoError.value = ''; });
watch(despachoForm, () => { despachoError.value = ''; }, { deep: true });

const notifyError = (mensaje) => {
    if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('gb:error', { detail: { mensaje } }));
    }
};

const pedirAprobar = () => modalConfirmar.value = {
    tipo: 'aprobar',
    msg: props.pedido.estado === 'retenido'
        /*
         * Dice CONTABILIDAD, no Gerencia.
         *
         * `puedeAprobar()` es `esAracely() || esContable()`: quien libera un
         * retenido es Contabilidad, y así lo confirmó el área por escrito el
         * 20-nov-2026 —«la autorización para liberar los pedidos retenidos por
         * cartera está a cargo del área de Contabilidad»—.
         *
         * El texto decía que «Gerencia autoriza», así que la persona de
         * Contabilidad que pulsaba el botón estaba confirmando una autorización
         * que no era suya. O no lo pulsaba y el pedido se quedaba esperando a
         * alguien que nunca iba a venir.
         */
        ? '¿Liberar este pedido retenido? Confirmás que Contabilidad autoriza vender aunque el semáforo de cartera lo bloqueó. Quedará listo para facturar.'
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
    /*
     * Escrito como `! (… >= 1)` y no como `< 1` para que un NaN —casilla con
     * texto, o borrada— también caiga acá: cualquier comparación con NaN da
     * false, así que `< 1` lo dejaría pasar.
     */
    if (! (totalSale.value >= 1)) {
        despachoError.value = 'Poné al menos una unidad: así no sale nada y la guía queda en el aire.';
        return;
    }
    procesando.value = true;
    router.post(`/app/pedidos-b2b/${props.pedido.id}/despachar`, { ...despachoForm.value }, {
        preserveScroll: true,
        onSuccess: () => {
            modalDespachar.value = false;
            despachoForm.value = { guia_transportadora: '', transportadora: '', guias_extra: [], cantidades: cantidadesIniciales(), resto_pendiente: true };
            otraTransportadora.value = false;
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

                        <!-- De qué bodega sale. Sin esto, el aviso que sale al
                             aprobar —«asignale una bodega y volvé a aprobarlo»—
                             mandaba a hacer algo que la pantalla no permitía. -->
                        <div class="text-sm mt-2 flex items-center gap-2 flex-wrap">
                            <span class="text-xs text-surface-500 uppercase">Sale de</span>
                            <select v-if="puede_cambiar_bodega && bodegas_origen.length"
                                    :value="pedido.ubicacion_origen_id ?? ''" @change="cambiarBodega"
                                    class="input py-1 text-sm"
                                    aria-label="Bodega de origen del pedido">
                                <option value="" disabled>— sin bodega —</option>
                                <option v-for="b in bodegas_origen" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                            </select>
                            <b v-else>{{ pedido.bodega_origen || '— sin bodega —' }}</b>
                            <span v-if="!pedido.ubicacion_origen_id && !errorBodega"
                                  class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-1.5 py-0.5">
                                Sin bodega no se aparta mercancía y bodega no lo ve en su cola.
                            </span>
                            <span v-if="errorBodega"
                                  class="text-xs text-red-700 bg-red-50 border border-red-200 rounded px-1.5 py-0.5">
                                {{ errorBodega }}
                            </span>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-surface-500 uppercase">Total</div>
                        <div class="text-3xl font-bold text-brand-600">{{ money(pedido.total) }}</div>
                    </div>
                </div>

                <!-- Acciones -->
                <div class="flex items-center gap-2 flex-wrap border-t border-surface-200 dark:border-surface-800 pt-4">
                    <!--
                        LOG-J3 · el mismo botón aprueba y libera un retenido.
                        El rótulo dice CONTABILIDAD porque es quien puede:
                        `puedeAprobar()` = esAracely() || esContable(). Decía
                        «Gerencia» y mandaba a la persona equivocada.
                    -->
                    <button v-if="puedo.aprobar && ['enviado','retenido'].includes(pedido.estado)" @click="pedirAprobar" :disabled="procesando"
                            :class="pedido.estado === 'retenido' ? 'btn-primary bg-amber-600 hover:bg-amber-700' : 'btn-primary'">
                        <CheckCircle class="h-4 w-4"/>
                        {{ pedido.estado === 'retenido' ? 'Liberar (Contabilidad)' : 'Aprobar' }}
                    </button>
                    <button v-if="puedo.aprobar && ['enviado','retenido','aprobado'].includes(pedido.estado)" @click="modalRechazar = true" :disabled="procesando" class="btn-ghost text-red-600 border border-red-300">
                        <XCircle class="h-4 w-4"/> Rechazar
                    </button>
                    <button v-if="puedo.facturar && pedido.estado === 'aprobado'" @click="pedirFacturar" :disabled="procesando" class="btn-primary bg-emerald-600 hover:bg-emerald-700">
                        <FileText class="h-4 w-4"/> Facturar
                    </button>
                    <Link v-if="pedido.factura" :href="`/app/facturas/${pedido.factura.id}`" class="btn-ghost">
                        <FileText class="h-4 w-4"/> Ver factura {{ pedido.factura.numero }}
                    </Link>
                    <!-- LOG-J7 · Despachar · basta con factura emitida (la sync SIIGO va en segundo plano) -->
                    <button v-if="puedo.despachar && pedido.factura && !pedido.despachado_at && pedido.estado !== 'rechazado'"
                            @click="modalDespachar = true"
                            :disabled="procesando"
                            class="btn-primary bg-indigo-600 hover:bg-indigo-700">
                        <!--
                            El rótulo cambia en el segundo viaje. «Despachar» a
                            secas, sobre un pedido del que ya salió parte, se lee
                            como «todavía no ha salido nada».
                        -->
                        🚚 {{ pedido.resto_pendiente ? 'Despachar lo que falta' : 'Despachar' }}
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

                <!--
                    LOG-J7 · info del despacho.

                    Colgaba sólo de `despachado_at`, y en un parcial con resto
                    pendiente esa fecha se deja NULL a propósito —el candado de
                    idempotencia la mira—. Resultado: se despachaban 4 de 6 y el
                    pedido se veía idéntico a uno que no había salido. Ni la
                    guía, ni cuánto se llevó el transportador.

                    Ahora se pinta también con `despacho_parcial`, que es la
                    marca de «este pedido salió en partes».
                -->
                <div v-if="pedido.despachado_at || pedido.despacho_parcial"
                     class="mt-4 p-3 rounded-lg text-sm border-l-4"
                     :class="pedido.resto_pendiente
                         ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-500'
                         : 'bg-indigo-50 dark:bg-indigo-950/40 border-indigo-500'">
                    <div class="font-bold" :class="pedido.resto_pendiente ? 'text-amber-800 dark:text-amber-200' : 'text-indigo-800 dark:text-indigo-200'">
                        <template v-if="pedido.resto_pendiente">⏳ Salió a medias · falta por entregar</template>
                        <!--
                            ENVÍOS, no cajas. Decía `cajas.length` y un pedido
                            que salió en dos viajes de dos y una caja anunciaba
                            «3 envíos»: quien lee eso busca tres guías de camión
                            y encuentra dos.
                        -->
                        <!--
                            Y «completo» sólo si de verdad salió todo.
                            `resto_pendiente` en false significa que el pedido ya
                            no espera nada, pero eso pasa por dos caminos
                            distintos: porque salió entero, o porque quien
                            despachó lo CERRÓ sabiendo que faltaba. Decirle
                            «entregado completo» al segundo, con el renglón de
                            abajo diciendo «faltan 2» en el mismo recuadro, es
                            la clase de contradicción que termina en que alguien
                            le promete al cliente una caja que nadie va a enviar.
                        -->
                        <template v-else-if="pedido.despacho_parcial && totalFaltante > 0">
                            ✓ Cerrado con faltante · salió en {{ pedido.viajes || 1 }} envío{{ (pedido.viajes || 1) > 1 ? 's' : '' }}
                        </template>
                        <template v-else-if="pedido.despacho_parcial">✓ Entregado completo · salió en {{ pedido.viajes || 1 }} envío{{ (pedido.viajes || 1) > 1 ? 's' : '' }}</template>
                        <template v-else>✓ Despachado</template>
                        <span v-if="pedido.despachado_at" class="font-normal"> · {{ pedido.despachado_at }}</span>
                        <span v-if="pedido.transportadora" class="font-normal"> · {{ pedido.transportadora }}</span>
                    </div>

                    <!--
                        Cuánto va. Se suma de las líneas, que ya traen `cantidad`
                        y `despachada`: sin este renglón, saber qué falta obliga
                        a abrir el modal de despachar.
                    -->
                    <div v-if="pedido.despacho_parcial" class="mt-1 text-xs">
                        Salieron <b>{{ totalDespachado }}</b> de <b>{{ totalPedido }}</b> unidades<template v-if="totalFaltante > 0"> · faltan <b>{{ totalFaltante }}</b></template>
                    </div>

                    <!--
                        TODAS las guías, no sólo la última.

                        `guia_transportadora` guarda la del último viaje y la
                        pantalla mostraba ésa sola. Con dos viajes, la guía del
                        primero no aparecía en ningún lado: el cliente reclamaba
                        una caja y no había número que darle.
                    -->
                    <!--
                        Agrupadas por viaje cuando hubo más de uno. Saber cuáles
                        salieron juntas es lo primero que se pregunta cuando el
                        cliente dice que le llegaron dos de tres: las del mismo
                        viaje iban en el mismo camión.
                    -->
                    <div v-if="pedido.cajas?.length" class="mt-2 space-y-1.5">
                        <div v-for="(cajas, viaje) in cajasPorViaje" :key="viaje">
                            <div v-if="pedido.viajes > 1" class="text-[11px] text-surface-500 mb-0.5">
                                {{ viaje }}.º envío<template v-if="cajas[0].salio"> · {{ cajas[0].salio }}</template>
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <span v-for="c in cajas" :key="c.numero"
                                      class="inline-flex items-center gap-1 bg-white dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded px-1.5 py-0.5 text-xs">
                                    <span class="text-surface-500">caja {{ c.numero }}</span>
                                    <code v-if="c.guia" class="font-mono">{{ c.guia }}</code>
                                    <span v-else class="text-surface-400 italic">sin guía propia</span>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div v-else-if="pedido.guia_transportadora" class="mt-1 text-xs">
                        guía <code class="bg-white dark:bg-surface-900 px-1 rounded">{{ pedido.guia_transportadora }}</code>
                    </div>
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
                                // El hito de «retención liberada»: no es un paso
                                // más del trámite, es alguien pasando por encima
                                // del semáforo de cartera, y se ve distinto.
                                'bg-amber-100 text-amber-800': h.color === 'amber',
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
                        <!--
                            Se ELIGE de la lista del área. Era un campo de
                            texto, y la misma empresa entraba escrita de tres
                            formas: entonces no se encontraba su enlace de
                            rastreo ni se podía medir cuál se demora.
                            «Otra» queda para un caso suelto sin tener que
                            esperar a que alguien la agregue en las reglas.
                        -->
                        <select v-if="! otraTransportadora" v-model="despachoForm.transportadora" class="input w-full text-sm">
                            <option value="">— sin transportadora —</option>
                            <option v-for="t in transportadoras" :key="t" :value="t">{{ t }}</option>
                            <option value="__otra__">Otra…</option>
                        </select>
                        <input v-else v-model="despachoForm.transportadora" type="text"
                               placeholder="Nombre de la transportadora" class="input w-full text-sm" maxlength="80"/>
                        <button v-if="otraTransportadora" type="button" class="text-[11px] text-surface-500 underline mt-1"
                                @click="otraTransportadora = false; despachoForm.transportadora = ''">
                            volver a la lista
                        </button>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">
                            N° de guía <span class="text-red-600">*</span>
                            <span v-if="guiaPorCaja" class="font-normal text-surface-500">· caja 1</span>
                        </label>
                        <input v-model="despachoForm.guia_transportadora" type="text" placeholder="Número que da la transportadora" class="input w-full text-sm font-mono" maxlength="60" required/>
                    </div>

                    <!--
                        VARIAS CAJAS · Interrapidísimo da una guía POR CAJA; las
                        demás transportadoras, una sola para todo el pedido.
                        Antes sólo cabía una y las otras quedaban anotadas en un
                        papel — y la guía es con lo que se reclama cuando una
                        caja no llega.
                    -->
                    <div v-for="(g, i) in despachoForm.guias_extra" :key="i">
                        <label class="text-xs font-semibold">N° de guía · caja {{ i + 2 }}</label>
                        <div class="flex gap-1">
                            <input v-model="despachoForm.guias_extra[i]" type="text"
                                   placeholder="Guía de esta caja" class="input w-full text-sm font-mono" maxlength="60"/>
                            <button type="button" class="btn-ghost text-red-600 px-2 text-sm"
                                    @click="despachoForm.guias_extra.splice(i, 1)">✕</button>
                        </div>
                    </div>

                    <button type="button" class="text-xs text-indigo-600 font-semibold"
                            @click="despachoForm.guias_extra.push('')">
                        + Otra caja
                        <span v-if="guiaPorCaja" class="font-normal text-surface-500">
                            · {{ despachoForm.transportadora }} da una guía por caja
                        </span>
                    </button>

                    <!--
                        CUÁNTO SALE DE CADA LÍNEA.
                        Arranca en lo pendiente, así que no hacer nada despacha
                        completo, igual que siempre. Bajar un número es el
                        despacho parcial que pidió el área: sale lo que hay sin
                        esperar a completar el pedido.
                    -->
                    <div class="border-t border-surface-200 dark:border-surface-800 pt-3 space-y-2">
                        <div class="text-xs font-semibold">Qué sale</div>

                        <div v-for="it in items" :key="it.id" class="flex items-center gap-2 text-sm">
                            <div class="flex-1 min-w-0">
                                <div class="truncate">{{ it.desc }}</div>
                                <div class="text-[11px] text-surface-500">
                                    pide {{ it.cantidad }}<template v-if="it.despachada"> · ya salieron {{ it.despachada }}</template>
                                </div>
                            </div>
                            <input v-model.number="despachoForm.cantidades[it.id]" type="number"
                                   min="0" :max="it.pendiente"
                                   class="input w-20 text-sm text-right"/>
                            <span class="text-[11px] text-surface-500 w-10">de {{ it.pendiente }}</span>
                        </div>

                        <!--
                            Y qué pasa con lo que no salga. Lo decide quien
                            despacha: depende de si el cliente va a esperar el
                            resto, y eso el sistema no lo sabe.
                        -->
                        <div v-if="hayFaltante" class="rounded-lg bg-amber-50 dark:bg-amber-950/40 border-l-4 border-amber-500 p-2 space-y-1">
                            <div class="text-xs font-semibold">Va incompleto. ¿Qué pasa con lo que no sale?</div>
                            <label class="flex items-start gap-2 text-xs cursor-pointer">
                                <input type="radio" :value="true" v-model="despachoForm.resto_pendiente" class="mt-0.5"/>
                                <span><b>Sigue pendiente.</b> El pedido vuelve a la cola y sale en un segundo viaje.</span>
                            </label>
                            <label class="flex items-start gap-2 text-xs cursor-pointer">
                                <input type="radio" :value="false" v-model="despachoForm.resto_pendiente" class="mt-0.5"/>
                                <span><b>Ya no sale.</b> El pedido se cierra con lo que se entregó.</span>
                            </label>
                        </div>
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
