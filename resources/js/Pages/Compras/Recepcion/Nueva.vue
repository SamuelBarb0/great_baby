<script setup>
import { reactive, ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Truck, Save, ArrowLeft, AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    oc: { type: Object, default: null },
    // Órdenes por recibir, para elegir una sin salir de esta pantalla.
    ocs_pendientes: { type: Array, default: () => [] },
});

const ocsPendientes = computed(() => props.ocs_pendientes ?? []);

const { money } = useMoney();

const form = reactive({
    orden_id: props.oc?.id ?? '',
    remision_proveedor: '',
    factura_proveedor: '',
    transportista: '',
    observaciones: '',
    items: props.oc?.items.map(i => ({
        orden_item_id: i.id,
        descripcion: i.descripcion,
        cantidad_pendiente: i.cantidad_pendiente,
        cantidad_recibida: i.cantidad_pendiente,
        precio_unit: i.precio_unit,
        cajas: i.cajas,
        es_componente: i.es_componente,
        unidades_por_caja: i.unidades_por_caja,
        destino: i.destino,
        para_otra_empresa: i.para_otra_empresa,
        lote: '',
        // LOG-J8 · clasificación por línea · default apto para no romper el flujo
        //   actual, pero el logístico puede cambiarlo al descargar.
        clasificacion: 'apto',
        motivo_clasificacion: '',
    })) ?? [],
});
const procesando = ref(false);

// Descargar un contenedor toma horas: si se cierra la página, no se pierde lo contado.
const claveConteo = props.oc ? `conteo-oc-${props.oc.id}` : null;
const hayConteoGuardado = ref(false);
if (claveConteo) {
    try {
        const guardado = JSON.parse(localStorage.getItem(claveConteo) || 'null');
        if (guardado) {
            for (const it of form.items) {
                const g = guardado[it.orden_item_id];
                if (g && Number(g.cantidad_recibida) <= it.cantidad_pendiente) Object.assign(it, g);
            }
            hayConteoGuardado.value = true;
        }
    } catch { /* sin almacenamiento local: el conteo sigue funcionando, sólo no se recuerda */ }
    watch(() => form.items, (items) => {
        try {
            localStorage.setItem(claveConteo, JSON.stringify(Object.fromEntries(items.map(i => [i.orden_item_id, {
                cantidad_recibida: i.cantidad_recibida, lote: i.lote, clasificacion: i.clasificacion, motivo_clasificacion: i.motivo_clasificacion,
            }]))));
        } catch { /* ídem */ }
    }, { deep: true });
}

const cajasOtraEmpresa = computed(() => form.items.filter(i => i.para_otra_empresa));
const sinReferencia = computed(() => props.oc?.items.filter(i => i.no_sube) ?? []);

// Re-audit M2 UX-A7 · clamp reactivo — antes `:max` era solo hint HTML, el
// usuario podía escribir 30 sobre pendiente 20 y romper el saldo.
const clampCantidad = (it) => {
    const n = Number(it.cantidad_recibida);
    if (isNaN(n) || n < 0) it.cantidad_recibida = 0;
    else if (n > it.cantidad_pendiente) it.cantidad_recibida = it.cantidad_pendiente;
};

// Re-audit M2 UX-M12 · deshabilitar submit si ningún ítem tiene cantidad > 0.
const itemsAConfirmar = computed(() => form.items.filter(i => Number(i.cantidad_recibida) > 0));
// Lo marcado como faltante no llegó: no suma, igual que al guardar.
const valorLinea = (i) => (i.clasificacion === 'faltante' ? 0 : Number(i.cantidad_recibida || 0) * Number(i.precio_unit || 0));
const totalRecepcion = computed(() => itemsAConfirmar.value.reduce((s, i) => s + valorLinea(i), 0));

const guardar = () => {
    if (procesando.value || itemsAConfirmar.value.length === 0) return;
    procesando.value = true;
    router.post('/app/compras/recepcion', {
        orden_id: form.orden_id,
        remision_proveedor: form.remision_proveedor,
        factura_proveedor: form.factura_proveedor,
        transportista: form.transportista,
        observaciones: form.observaciones,
        items: itemsAConfirmar.value.map(i => ({
            orden_item_id: i.orden_item_id,
            cantidad_recibida: i.cantidad_recibida,
            lote: i.lote || null,
            clasificacion: i.clasificacion || 'apto',
            motivo_clasificacion: i.motivo_clasificacion || null,
        })),
    }, {
        preserveScroll: true,
        onSuccess: () => { try { localStorage.removeItem(claveConteo); } catch { /* ídem */ } },
        onFinish: () => procesando.value = false,
    });
};
</script>

<template>
    <Head title="Recibir mercancía"/>
    <AppLayout>
        <div class="max-w-4xl mx-auto space-y-4">
            <Link :href="oc?.importacion_id ? `/app/compras/importacion/${oc.importacion_id}` : (oc ? `/app/compras/oc/${oc.id}` : '/app/compras')" class="btn-ghost inline-flex items-center gap-1 text-sm">
                <ArrowLeft class="h-4 w-4"/> Volver
            </Link>

            <h1 class="text-2xl font-bold flex items-center gap-2">
                <Truck class="h-6 w-6 text-brand-600"/>
                Recibir mercancía
            </h1>

            <div v-if="$page.props.errors && Object.keys($page.props.errors).length" class="p-3 rounded-lg bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-sm space-y-1">
                <div v-for="(msg, k) in $page.props.errors" :key="k">{{ msg }}</div>
            </div>

            <!-- Sin OC elegida: se listan acá las que están por llegar. Antes
                 esto mandaba a /app/compras, que para quien recibe la mercancía
                 responde 403. -->
            <div v-if="!oc" class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-800">
                    <h3 class="font-semibold">Elegí la orden que estás recibiendo</h3>
                    <p class="text-xs text-surface-500 mt-0.5">
                        Órdenes aprobadas o recibidas a medias, de la más reciente a la más vieja.
                    </p>
                </div>

                <div v-if="!ocsPendientes.length" class="p-8 text-center text-surface-500">
                    No hay órdenes de compra pendientes por recibir.
                </div>

                <table v-tabla-movil v-else class="w-full text-sm">
                    <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900 border-b">
                        <tr>
                            <th class="p-3 text-left">Orden</th>
                            <th class="p-3 text-left">Proveedor</th>
                            <th class="p-3 text-left">Contenedor</th>
                            <th class="p-3 text-left">Estado</th>
                            <th class="p-3 text-left">Se esperaba</th>
                            <th class="p-3 text-right">Total</th>
                            <th class="p-3 text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                        <tr v-for="o in ocsPendientes" :key="o.id" class="hover:bg-brand-50/40 dark:hover:bg-brand-950/20">
                            <td class="p-3 font-mono text-xs font-bold">{{ o.numero }}</td>
                            <td class="p-3">{{ o.proveedor || '—' }}</td>
                            <td class="p-3 font-mono text-xs">{{ o.contenedor || '—' }}</td>
                            <td class="p-3 text-xs">{{ o.estado }}</td>
                            <td class="p-3 text-xs">{{ o.fecha_esperada || '—' }}</td>
                            <td class="p-3 text-right">{{ money(o.total) }}</td>
                            <td class="p-3 text-right">
                                <Link :href="`/app/compras/recepcion/nueva?oc=${o.id}`"
                                      class="inline-flex items-center px-3 py-2 min-h-11 bg-brand-600 hover:bg-brand-700 text-white rounded text-xs font-bold">
                                    Recibir esta →
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="space-y-4">
                <div class="card p-4">
                    <div class="text-xs uppercase font-bold text-brand-600 mb-1">OC de origen</div>
                    <div class="font-mono font-bold">{{ oc.numero }}</div>
                    <div class="text-sm text-surface-500">{{ oc.proveedor }}</div>
                    <div v-if="oc.contenedor" class="text-sm text-surface-500">Contenedor <span class="font-mono">{{ oc.contenedor }}</span></div>
                    <p v-if="oc.es_importacion" class="text-sm mt-2">Cuente producto por producto y corrija lo que llegó distinto. <b>Nada sube al inventario hasta que confirme.</b></p>
                    <p v-if="hayConteoGuardado" class="text-xs text-surface-500 mt-1">Se recuperó su conteo anterior.</p>
                </div>

                <div v-if="oc.falta_tasa" class="p-3 rounded-lg bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-sm">{{ oc.falta_tasa }}</div>

                <div v-if="sinReferencia.length" class="p-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200 text-sm">
                    <b>{{ sinReferencia.length }} línea(s) sin referencia no subirán al inventario.</b>
                    Asígnesela en la <a :href="`/app/compras/oc/${oc.id}`" class="underline">orden de compra</a> antes de confirmar.
                </div>

                <div v-if="cajasOtraEmpresa.length" class="p-3 rounded-lg bg-sky-50 dark:bg-sky-950/40 border-l-4 border-sky-500 text-sky-800 dark:text-sky-200 text-sm">
                    <b>Para separar:</b>
                    <div v-for="i in cajasOtraEmpresa" :key="i.orden_item_id">{{ i.cajas }} cajas de {{ i.descripcion }} son para {{ i.destino }}.</div>
                </div>

                <div class="card p-4 grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div><label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Remisión proveedor</label><input v-model="form.remision_proveedor" class="input w-full" maxlength="100"/></div>
                    <div><label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Factura proveedor</label><input v-model="form.factura_proveedor" class="input w-full" maxlength="100"/></div>
                    <div><label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Transportista</label><input v-model="form.transportista" class="input w-full" maxlength="100"/></div>
                    <div class="md:col-span-3"><label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Observaciones</label><textarea v-model="form.observaciones" rows="2" class="input w-full" maxlength="1000"></textarea></div>
                </div>

                <div class="card p-4">
                    <div class="text-xs uppercase font-bold text-brand-600 mb-2">Ítems a recibir ({{ form.items.length }})</div>
                    <div class="overflow-x-auto">
                        <table v-tabla-movil class="w-full text-sm">
                            <thead class="text-[10px] text-surface-500 uppercase border-b border-surface-200 dark:border-surface-800">
                                <tr>
                                    <th class="text-left p-2">Descripción</th>
                                    <th class="text-right p-2">Pendiente</th>
                                    <th class="text-right p-2">A recibir</th>
                                    <th class="text-right p-2">Subtotal línea</th>
                                    <th class="text-left p-2">Lote</th>
                                    <!-- LOG-J8 · destino por línea · cierra el descuadre que mencionó Don Jorge -->
                                    <th class="text-left p-2">Destino</th>
                                    <th class="text-left p-2">Motivo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                                <tr v-for="(it, i) in form.items" :key="i"
                                    :class="it.clasificacion !== 'apto' ? 'bg-amber-50/40 dark:bg-amber-950/20' : ''">
                                    <td class="p-2" :title="it.descripcion">
                                        {{ it.descripcion }}
                                        <span v-if="it.es_componente" class="block text-xs text-sky-700 dark:text-sky-400 font-semibold">Componente · se cuenta, pero no sube al inventario</span>
                                        <span v-if="it.cajas" class="block text-xs text-surface-500">{{ it.cajas }} cajas de {{ it.unidades_por_caja }}<template v-if="it.destino"> · {{ it.destino }}</template></span>
                                    </td>
                                    <td class="p-2 text-right font-mono">{{ it.cantidad_pendiente }}</td>
                                    <td class="p-2">
                                        <input type="number" step="0.001" min="0" :max="it.cantidad_pendiente"
                                               v-model.number="it.cantidad_recibida"
                                               @change="clampCantidad(it)"
                                               @blur="clampCantidad(it)"
                                               class="input w-24 text-right text-sm"/>
                                    </td>
                                    <td class="p-2 text-right font-mono text-xs">{{ money(valorLinea(it)) }}</td>
                                    <td class="p-2"><input v-model="it.lote" class="input w-full text-sm" placeholder="Opcional" maxlength="80"/></td>
                                    <td class="p-2">
                                        <select v-model="it.clasificacion" class="input text-xs w-full">
                                            <option value="apto">✓ Apto venta</option>
                                            <option value="averia">⚠ Avería</option>
                                            <option value="cuarentena">⏳ Cuarentena</option>
                                            <option value="revision">🔍 Revisión</option>
                                            <option value="faltante">✕ Faltante</option>
                                        </select>
                                    </td>
                                    <td class="p-2">
                                        <input v-model="it.motivo_clasificacion"
                                               :placeholder="it.clasificacion === 'apto' ? '—' : 'Describí la novedad'"
                                               :required="it.clasificacion !== 'apto'"
                                               class="input w-full text-xs" maxlength="180"/>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t-2 border-surface-300 dark:border-surface-700 font-bold">
                                <tr>
                                    <td colspan="3" class="p-2 text-right">Total recepción</td>
                                    <td class="p-2 text-right font-mono">{{ money(totalRecepcion) }}</td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div v-if="itemsAConfirmar.length === 0" class="mt-3 p-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200 text-xs flex items-start gap-2">
                        <AlertTriangle class="h-4 w-4 flex-shrink-0 mt-0.5"/>
                        <div>Marca al menos un ítem con cantidad mayor a 0 antes de confirmar.</div>
                    </div>
                </div>

                <div class="h-16" aria-hidden="true"></div>
                <div class="card p-3 fixed bottom-4 right-4 left-4 md:left-auto md:right-6 md:max-w-2xl z-30 flex items-center justify-between gap-3 flex-wrap shadow-lg">
                    <p class="text-sm">Total a recibir: <b>{{ money(totalRecepcion) }}</b></p>
                    <button @click="guardar" :disabled="procesando || itemsAConfirmar.length === 0 || !!oc.falta_tasa" class="btn-primary disabled:opacity-50">
                        <Save class="h-4 w-4"/> {{ procesando ? 'Guardando…' : (oc.es_importacion ? 'Aceptar y subir al inventario' : 'Confirmar recepción') }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
