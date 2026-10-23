<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Camera, Warehouse, ArrowRightLeft, ArrowLeftRight } from 'lucide-vue-next';

/**
 * El servidor ya manda los préstamos separados en abiertos y cerrados.
 *
 * Acá se declaraba `prestamos` como arreglo y se volvía a filtrar, pero
 * `MarketingPrestamosController` envía un objeto `{abiertos, cerrados,
 * total_abiertos}`. El `.filter()` sobre un objeto lanzaba
 * «prestamos.filter is not a function» en pleno render y **toda la pantalla
 * quedaba en blanco**: sin menú, sin contenido y sin forma de salir. Era el
 * único rol que no podía usar el sistema.
 *
 * Se consume lo que el servidor manda, que además ya recorta el histórico a
 * los últimos 20.
 */
const props = defineProps({
    mis_bodegas: { type: Array, required: true },
    prestamos: { type: Object, required: true },
    bodegas_comerciales: { type: Array, required: true },
    // Saldo real del kardex en los almacenes MKT: lo único que dice de verdad
    // qué sigue prestado, porque la devolución es un traslado aparte y el
    // modelo no guarda a qué préstamo corresponde.
    en_mi_almacen: { type: Array, default: () => [] },
    es_super: { type: Boolean, default: false },
    // MKT-5 · préstamos ya entregados: la devolución dice cuál cierra.
    para_devolver: { type: Array, default: () => [] },
});

const abiertos = computed(() => props.prestamos?.abiertos ?? []);
const cerrados = computed(() => props.prestamos?.cerrados ?? []);

/**
 * Acciones rápidas que de verdad hacen algo.
 *
 * Los dos botones apuntaban a `/app/inventario/traslados` —la misma URL los
 * dos— y esa pantalla le responde **403** al rol Marketing. Debajo había un
 * texto pidiéndole a la persona que fuera allá y eligiera a mano origen,
 * destino y motivo. La única acción del panel era un callejón sin salida.
 *
 * Ahora la solicitud se crea desde acá, contra el endpoint del propio módulo.
 * Cuando hay una sola bodega de venta y un solo almacén MKT —el caso de hoy—
 * no queda nada que elegir: se escribe qué se necesita y listo.
 */
const modal = ref(null);          // 'pedir' | 'devolver' | null
const comercialId = ref(props.bodegas_comerciales[0]?.id ?? '');
const mktId = ref(props.mis_bodegas[0]?.id ?? '');
const observaciones = ref('');
const prestamoId = ref('');
const enviando = ref(false);
const errores = ref({});

const puedePedir = computed(() => props.bodegas_comerciales.length > 0 && props.mis_bodegas.length > 0);

const abrir = (tipo) => {
    modal.value = tipo;
    observaciones.value = '';
    prestamoId.value = props.para_devolver.length === 1 ? props.para_devolver[0].id : '';
    errores.value = {};
};

const enviar = () => {
    if (enviando.value) return;
    enviando.value = true;
    errores.value = {};
    const pedir = modal.value === 'pedir';
    router.post('/app/marketing/prestamo', {
        origen_id: pedir ? comercialId.value : mktId.value,
        destino_id: pedir ? mktId.value : comercialId.value,
        observaciones: observaciones.value || null,
        prestamo_id: pedir ? null : (prestamoId.value || null),
    }, {
        preserveScroll: true,
        onSuccess: () => { modal.value = null; },
        onError: (e) => { errores.value = e; },
        onFinish: () => (enviando.value = false),
    });
};

const estadoChip = (e) => ({
    borrador: 'bg-surface-100 text-surface-700',
    en_transito: 'bg-amber-100 text-amber-900 ring-1 ring-amber-400',
    recibido: 'bg-emerald-100 text-emerald-800',
    anulado: 'bg-red-100 text-red-800',
}[e] || 'bg-surface-100');

/** Quién firmó cada paso, en una línea: «Aprobó Aracely · Entregó Jorge · Recogió Laura». */
const firmas = (p) => [
    p.aprobado_por && `Aprobó ${p.aprobado_por}`,
    p.entregado_por && (p.es_prestamo ? `Alistó ${p.entregado_por}` : `Despachó ${p.entregado_por}`),
    p.recogido_por && (p.es_prestamo ? `Recogió ${p.recogido_por}` : `La trajo ${p.recogido_por}`),
].filter(Boolean).join(' · ');
</script>

<template>
    <Head title="Marketing · préstamos"/>
    <AppLayout>
        <div class="space-y-5 max-w-6xl mx-auto">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Camera class="h-6 w-6 text-brand-600"/>
                    Marketing · préstamos de bodega
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Pedí productos prestados a bodega para fotos y videos, y devolvelos cuando termines.
                    Todo queda registrado en el kardex, sin papelitos.
                </p>
            </div>

            <!-- Nuestros almacenes + accesos rápidos -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <Warehouse class="h-4 w-4 text-brand-600"/>
                        <div class="text-sm font-semibold">Mis almacenes</div>
                    </div>
                    <div v-if="!mis_bodegas.length" class="text-xs text-surface-400 italic">
                        Pedile a Aracely que te cree una ubicación con prefijo MKT-.
                    </div>
                    <div v-else class="space-y-1 text-sm">
                        <div v-for="b in mis_bodegas" :key="b.id" class="flex items-center justify-between p-2 bg-surface-50 dark:bg-surface-900 rounded">
                            <div>
                                <div class="font-mono font-bold">{{ b.codigo }}</div>
                                <div class="text-xs text-surface-500">{{ b.nombre }}{{ b.ciudad ? ' · ' + b.ciudad : '' }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card p-4 lg:col-span-2 space-y-3">
                    <div class="text-sm font-semibold mb-1">Acciones rápidas</div>
                    <button type="button" @click="abrir('pedir')" :disabled="!puedePedir"
                            class="btn-primary w-full justify-start text-sm disabled:opacity-50">
                        <ArrowRightLeft class="h-4 w-4"/>
                        📸 Pedir prestado a bodega
                    </button>
                    <button type="button" @click="abrir('devolver')" :disabled="!puedePedir"
                            class="btn-ghost border border-brand-300 w-full justify-start text-sm disabled:opacity-50">
                        <ArrowLeftRight class="h-4 w-4"/>
                        ↩ Devolver a bodega
                    </button>
                    <div v-if="!puedePedir" class="text-[11px] text-amber-700 pt-1">
                        Falta configurar tu almacén (código MKT-) o una bodega de venta.
                        Pedíselo a Aracely y estos botones se activan.
                    </div>
                    <div v-else class="text-[11px] text-surface-500 pt-1 italic">
                        Pedís → la aprueban → bodega la alista y te avisa → la recogés y queda firmado quién y en qué estado.
                    </div>
                </div>
            </div>

            <!-- Solicitudes en curso -->
            <div class="card p-4">
                <div class="text-sm font-semibold mb-3 flex items-center gap-2">
                    🔴 Solicitudes en curso · {{ abiertos.length }}
                </div>
                <div v-if="!abiertos.length" class="text-sm text-surface-400 italic text-center py-6">
                    No tenés solicitudes pendientes.
                </div>
                <table v-tabla-movil v-else class="w-full text-sm">
                    <thead class="text-xs uppercase text-surface-500 border-b border-surface-200 dark:border-surface-800">
                        <tr>
                            <th class="p-2 text-left">N°</th>
                            <th class="p-2 text-left">De → A</th>
                            <th class="p-2 text-left">Qué se pidió</th>
                            <th class="p-2 text-center">Items</th>
                            <th class="p-2 text-left">Responsables</th>
                            <th class="p-2 text-left">Fecha</th>
                            <th class="p-2 text-center">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                        <tr v-for="p in abiertos" :key="p.id" class="hover:bg-surface-50 dark:hover:bg-surface-900">
                            <td class="p-2 font-mono text-xs">{{ p.numero }}</td>
                            <td class="p-2">
                                <span class="font-mono text-xs">{{ p.origen }}</span>
                                <span class="text-surface-400 mx-1">→</span>
                                <span class="font-mono text-xs">{{ p.destino }}</span>
                            </td>
                            <td class="p-2 text-xs max-w-xs">
                                <div class="line-clamp-2">{{ p.observaciones || '—' }}</div>
                                <div v-if="p.estado_mercancia_label" class="mt-0.5"
                                     :class="p.estado_mercancia === 'completo' ? 'text-emerald-700' : 'text-amber-700 font-semibold'">
                                    {{ p.estado_mercancia_label }}<span v-if="p.observaciones_entrega"> · {{ p.observaciones_entrega }}</span>
                                </div>
                            </td>
                            <td class="p-2 text-center">{{ p.items_count }}</td>
                            <td class="p-2 text-xs">
                                <div>Pidió {{ p.solicitado_por || '—' }}</div>
                                <div v-if="firmas(p)" class="text-surface-500">{{ firmas(p) }}</div>
                            </td>
                            <td class="p-2 text-xs">{{ p.fecha }}</td>
                            <td class="p-2 text-center">
                                <span class="px-2 py-0.5 rounded text-xs font-semibold whitespace-nowrap" :class="estadoChip(p.estado)">{{ p.paso || p.estado_label }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Lo que sigue en el almacén, según el kardex -->
            <div class="card p-4">
                <div class="text-sm font-semibold mb-3">📦 En mi almacén ahora · {{ en_mi_almacen.length }} referencias</div>
                <div v-if="!en_mi_almacen.length" class="text-sm text-surface-400 italic text-center py-6">
                    No tenés nada de bodega en tu poder.
                </div>
                <div v-else class="divide-y divide-surface-200 dark:divide-surface-800 text-sm">
                    <div v-for="p in en_mi_almacen" :key="p.sku" class="flex items-center justify-between py-2">
                        <div class="min-w-0">
                            <div class="truncate">{{ p.nombre }}</div>
                            <div class="text-xs text-surface-500 font-mono">
                                {{ p.sku }}<span v-if="p.detalle"> · {{ p.detalle }}</span>
                            </div>
                        </div>
                        <span class="font-bold tabular-nums">{{ p.cantidad }}</span>
                    </div>
                </div>
                <p class="text-[11px] text-surface-500 italic pt-2">
                    Sale del kardex: es lo que entró a tu almacén menos lo que ya devolviste.
                </p>
            </div>

            <!-- Devoluciones y anulados -->
            <div v-if="cerrados.length" class="card p-4">
                <div class="text-sm font-semibold mb-3">↩ Devoluciones y cancelados ({{ cerrados.length }})</div>
                <div class="divide-y divide-surface-200 dark:divide-surface-800 text-sm">
                    <div v-for="p in cerrados" :key="p.id" class="flex items-center justify-between py-2">
                        <div>
                            <div class="font-mono text-xs">{{ p.numero }} · {{ p.origen }} → {{ p.destino }}</div>
                            <div class="text-xs text-surface-500">
                                {{ p.items_count }} ítems · {{ p.ejecutado_at || p.fecha }}
                                <span v-if="p.prestamo"> · cierra {{ p.prestamo }}</span>
                            </div>
                            <div v-if="firmas(p)" class="text-xs text-surface-500">{{ firmas(p) }}</div>
                            <div v-if="p.estado_mercancia_label" class="text-xs"
                                 :class="p.estado_mercancia === 'completo' ? 'text-emerald-700' : 'text-amber-700 font-semibold'">
                                {{ p.estado_mercancia_label }}<span v-if="p.observaciones_entrega"> · {{ p.observaciones_entrega }}</span>
                            </div>
                        </div>
                        <!-- El chip decía "OK" en verde para todo. Un préstamo
                             anulado se veía igual que una devolución completa. -->
                        <span class="px-2 py-0.5 rounded text-xs font-semibold whitespace-nowrap" :class="estadoChip(p.estado)">
                            {{ p.paso || p.estado_label }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Solicitud de préstamo / devolución -->
        <div v-if="modal" @click.self="modal = null"
             class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full space-y-3">
                <h3 class="text-lg font-bold">
                    {{ modal === 'pedir' ? '📸 Pedir prestado a bodega' : '↩ Devolver a bodega' }}
                </h3>

                <!-- Con una sola opción no se pregunta: se muestra. -->
                <div v-if="bodegas_comerciales.length > 1">
                    <label class="text-xs font-semibold">Bodega de venta</label>
                    <select v-model.number="comercialId" class="input w-full">
                        <option v-for="b in bodegas_comerciales" :key="b.id" :value="b.id">{{ b.codigo }} · {{ b.nombre }}</option>
                    </select>
                </div>
                <div v-if="mis_bodegas.length > 1">
                    <label class="text-xs font-semibold">Tu almacén</label>
                    <select v-model.number="mktId" class="input w-full">
                        <option v-for="b in mis_bodegas" :key="b.id" :value="b.id">{{ b.codigo }} · {{ b.nombre }}</option>
                    </select>
                </div>

                <div v-if="modal === 'devolver' && para_devolver.length">
                    <label class="text-xs font-semibold">¿De qué préstamo es?</label>
                    <select v-model.number="prestamoId" class="input w-full">
                        <option value="">No sé / varios</option>
                        <option v-for="p in para_devolver" :key="p.id" :value="p.id">
                            {{ p.numero }}{{ p.observaciones ? ' · ' + p.observaciones.slice(0, 50) : '' }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold">
                        {{ modal === 'pedir' ? '¿Qué necesitás y para cuándo?' : '¿Qué estás devolviendo?' }}
                    </label>
                    <textarea v-model="observaciones" rows="3" class="input w-full"
                              :placeholder="modal === 'pedir'
                                  ? 'Ej: los bodies de verano talla 0-3 para las fotos del viernes'
                                  : 'Ej: todo lo del préstamo TRA-2026-000012, sin averías'"></textarea>
                    <p v-if="errores.observaciones" class="text-xs text-red-600 mt-1">{{ errores.observaciones }}</p>
                </div>

                <p v-if="errores.origen_id || errores.destino_id" class="text-xs text-red-600">
                    {{ errores.origen_id || errores.destino_id }}
                </p>

                <p class="text-xs text-surface-500">
                    {{ modal === 'pedir'
                        ? 'Se aprueba, bodega elige las referencias y te llega un aviso cuando esté lista para recoger.'
                        : 'Bodega la recibe y anota en qué estado llegó.' }}
                </p>

                <div class="flex justify-end gap-2 pt-1">
                    <button @click="modal = null" class="btn-ghost min-h-11">Cancelar</button>
                    <button @click="enviar" :disabled="enviando" class="btn-primary min-h-11">
                        {{ enviando ? 'Enviando…' : 'Enviar solicitud' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
