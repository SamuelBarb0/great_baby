<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileText, CheckCircle, XCircle, Truck, Send, PackageCheck, ExternalLink } from 'lucide-vue-next';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    pedido: { type: Object, required: true },
    items: { type: Array, required: true },
});

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);

/*
 * El seguimiento terminaba en «Facturado».
 *
 * De ahí en adelante el cliente no tenía nada: ni fecha de salida, ni guía,
 * ni forma de saber si su mercancía ya iba en camino. La información existía
 * —logística la veía completa— pero el portal no la mostraba, así que la única
 * manera de averiguarlo era llamar.
 *
 * El paso «Enviado» de aquí arriba es el cliente enviándonos el pedido, no el
 * envío de la mercancía: por eso lleva el icono de mensaje y no el camión, y
 * el paso de la transportadora se llama «En camino».
 */
const timeline = computed(() => [
    { key: 'creado', label: 'Creado', at: props.pedido.creado, icon: FileText, done: true },
    { key: 'enviado', label: 'Enviado', at: props.pedido.enviado_at, icon: Send, done: !!props.pedido.enviado_at },
    { key: 'aprobado', label: 'Aprobado', at: props.pedido.aprobado_at, icon: CheckCircle, done: !!props.pedido.aprobado_at, hidden: props.pedido.estado === 'rechazado' },
    { key: 'rechazado', label: 'Rechazado', at: props.pedido.rechazado_at, icon: XCircle, done: !!props.pedido.rechazado_at, hidden: props.pedido.estado !== 'rechazado' },
    { key: 'facturado', label: 'Facturado', at: props.pedido.facturado_at, icon: FileText, done: !!props.pedido.facturado_at, hidden: props.pedido.estado === 'rechazado' },
    { key: 'camino', label: 'En camino', at: props.pedido.salio_at, icon: Truck, done: !!props.pedido.salio_at, hidden: props.pedido.estado === 'rechazado' },
    { key: 'entregado', label: 'Entregado', at: props.pedido.entregado_at, icon: PackageCheck, done: !!props.pedido.entregado_at, hidden: props.pedido.estado === 'rechazado' },
].filter(t => !t.hidden));

// Cuántas unidades se deben todavía. En unidades y no en líneas: es lo que el
// cliente cuenta cuando abre la caja.
const faltan = computed(() => Math.max(0,
    (props.pedido.unidades_pedidas || 0) - (props.pedido.unidades_despachadas || 0)));

/*
 * Las cajas, agrupadas por viaje.
 *
 * Con Interrapidísimo va una guía por bulto, así que un pedido que salió en
 * dos viajes puede tener cinco guías. Agrupadas por viaje el cliente ve qué
 * llegó junto y qué viene después; en una sola lista plana no se entiende.
 */
const cajasPorViaje = computed(() => {
    const grupos = new Map();
    for (const c of (props.pedido.cajas || [])) {
        const v = c.viaje || 1;
        if (!grupos.has(v)) grupos.set(v, []);
        grupos.get(v).push(c);
    }
    return [...grupos.entries()].sort((a, b) => a[0] - b[0]).map(([viaje, cajas]) => ({ viaje, cajas }));
});
</script>

<template>
    <Head :title="'Pedido ' + pedido.numero"/>
    <PortalLayout>
        <div class="max-w-4xl mx-auto space-y-4">
            <Link href="/portal/pedidos" class="btn-ghost inline-flex items-center gap-1 text-sm">
                <ArrowLeft class="h-4 w-4"/> Volver
            </Link>

            <div class="card p-6">
                <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
                    <div>
                        <div class="text-xs text-surface-500 uppercase">Pedido</div>
                        <h1 class="text-2xl font-bold">{{ pedido.numero }}</h1>
                        <div class="text-xs text-surface-500 mt-1">{{ pedido.creado }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-surface-500 uppercase">Total</div>
                        <div class="text-3xl font-bold text-brand-600">{{ money(pedido.total) }}</div>
                    </div>
                </div>

                <!-- Timeline -->
                <div class="border-t border-surface-200 dark:border-surface-800 pt-4">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">Seguimiento</div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <div v-for="(t, idx) in timeline" :key="t.key" class="flex items-center gap-2">
                            <div :class="['h-8 w-8 rounded-full flex items-center justify-center', t.done ? 'bg-emerald-500 text-white' : 'bg-surface-200 text-surface-500']">
                                <component :is="t.icon" class="h-4 w-4"/>
                            </div>
                            <div>
                                <div class="text-sm font-medium">{{ t.label }}</div>
                                <div class="text-[10px] text-surface-500">{{ t.at || '—' }}</div>
                            </div>
                            <div v-if="idx < timeline.length - 1" class="w-6 h-px bg-surface-300 dark:bg-surface-700"></div>
                        </div>
                    </div>
                </div>

                <div v-if="pedido.motivo_rechazo" class="mt-4 p-3 rounded-lg bg-red-50 border-l-4 border-red-500 text-red-700 text-sm">
                    <b>Motivo de rechazo:</b> {{ pedido.motivo_rechazo }}
                </div>

                <!--
                    SALIÓ EN PARTES, Y SE DICE SIN QUE HAYA QUE PREGUNTAR.

                    El estado sigue diciendo «facturado», y eso es a propósito
                    —el pedido vuelve a la cola por el resto—, así que sin esta
                    franja el cliente recibe media caja y no tiene dónde leer
                    por qué. `resto_pendiente` y no `despacho_parcial`: la
                    segunda es pegajosa y le diría «te debemos mercancía» a un
                    pedido que ya se completó en dos viajes.
                -->
                <div v-if="pedido.resto_pendiente" class="mt-4 p-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border-l-4 border-amber-500 text-amber-900 dark:text-amber-200 text-sm">
                    <b>Tu pedido salió en partes.</b>
                    Ya despachamos {{ pedido.unidades_despachadas }} de
                    {{ pedido.unidades_pedidas }} unidades; las
                    {{ faltan }} que faltan salen en un próximo envío.
                </div>

                <div v-if="pedido.factura" class="mt-4 p-3 rounded-lg bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 text-sm">
                    Facturado con <b>{{ pedido.factura.numero }}</b> · {{ money(pedido.factura.total) }}
                </div>
            </div>

            <!--
                LA GUÍA, PARA QUE EL CLIENTE RASTREE SOLO.

                Es el motivo número uno de llamada a logística, y el dato estaba
                guardado desde el primer día. Lo que NO se muestra acá es
                deliberado: nada de casilla de consolidación, alistador, notas
                internas ni zonas de bodega. Eso es de la bodega, no del cliente.
            -->
            <div v-if="pedido.salio_at" class="card p-4">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3 flex items-center gap-1">
                    <Truck class="h-4 w-4"/> Envío
                </div>
                <div class="grid sm:grid-cols-3 gap-3 text-sm">
                    <div>
                        <div class="text-xs text-surface-500">Salió de bodega</div>
                        <div class="font-semibold">{{ pedido.salio_at }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-surface-500">Transportadora</div>
                        <div class="font-semibold">{{ pedido.transportadora || '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-surface-500">{{ pedido.entregado_at ? 'Entregado' : 'Estado' }}</div>
                        <div class="font-semibold">
                            {{ pedido.entregado_at || (pedido.resto_pendiente ? 'Primera parte en camino' : 'En camino') }}
                        </div>
                    </div>
                </div>

                <div v-if="cajasPorViaje.length" class="mt-4 space-y-3">
                    <div v-for="g in cajasPorViaje" :key="g.viaje">
                        <!-- El encabezado del viaje sólo aparece si hubo más de uno:
                             en el caso normal sería ruido. -->
                        <div v-if="cajasPorViaje.length > 1" class="text-xs font-bold text-surface-500 uppercase mb-1">
                            Envío {{ g.viaje }} · {{ g.cajas.length }} caja{{ g.cajas.length > 1 ? 's' : '' }}
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span v-for="c in g.cajas" :key="c.numero"
                                  class="px-2 py-1 rounded-lg bg-surface-100 dark:bg-surface-800 text-xs font-mono">
                                Caja {{ c.numero }} · {{ c.guia }}
                            </span>
                        </div>
                    </div>
                </div>
                <div v-else-if="pedido.guia" class="mt-3 text-xs font-mono text-surface-600 dark:text-surface-300">
                    Guía {{ pedido.guia }}
                </div>

                <a v-if="pedido.rastreo" :href="pedido.rastreo" target="_blank" rel="noopener"
                   class="btn-primary mt-4 inline-flex items-center gap-1 text-sm">
                    <ExternalLink class="h-4 w-4"/> Rastrear en {{ pedido.transportadora }}
                </a>
            </div>

            <div class="card p-4">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">Ítems ({{ items.length }})</div>
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs text-surface-500 uppercase">
                            <tr>
                                <th class="text-left p-2">Producto</th>
                                <th class="text-right p-2">Cant.</th>
                                <th class="text-right p-2">Precio</th>
                                <th class="text-right p-2">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-for="(it, idx) in items" :key="idx">
                                <td class="p-2">
                                    <div class="font-medium">{{ it.desc }}</div>
                                    <div class="text-xs text-surface-500 font-mono">{{ it.sku }}</div>
                                </td>
                                <td class="p-2 text-right">
                                    {{ it.cantidad }}
                                    <!--
                                        El faltante va debajo de la cantidad y
                                        no en una columna nueva: así el pie de
                                        la tabla conserva su colspan, y en el
                                        caso normal —que es casi siempre— no se
                                        ve nada.

                                        Atado a `resto_pendiente` del pedido: si
                                        no ha salido nada, «pendiente» es igual a
                                        lo pedido en TODAS las líneas y escribir
                                        «falta 6» sobre un pedido recién hecho
                                        sonaría a problema donde no hay ninguno.
                                    -->
                                    <div v-if="pedido.resto_pendiente && it.pendiente > 0"
                                         class="text-[11px] font-semibold text-amber-700 dark:text-amber-300">
                                        falta{{ it.pendiente > 1 ? 'n' : '' }} {{ it.pendiente }}
                                    </div>
                                </td>
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

            <div v-if="pedido.notas_cliente" class="card p-4">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-2">Tus notas</div>
                <p class="text-sm">{{ pedido.notas_cliente }}</p>
            </div>
        </div>
    </PortalLayout>
</template>
