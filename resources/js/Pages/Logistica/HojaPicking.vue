<script setup>
import { onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Printer, ArrowLeft } from 'lucide-vue-next';

defineProps({
    pedido: { type: Object, required: true },
    items: { type: Array, required: true },
});

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);
const imprimir = () => window.print();

// LOG-J6 · si Jorge llega acá presionando "Imprimir", disparamos el print
//   automáticamente a los 300ms para que no tenga que buscar el botón.
onMounted(() => {
    if (new URL(window.location.href).searchParams.get('auto') === '1') {
        setTimeout(() => window.print(), 300);
    }
});

const totalUnidades = (items) => items.reduce((acc, it) => acc + (it.cantidad || 0), 0);
</script>

<template>
    <Head :title="`Hoja de picking · ${pedido.numero}`"/>
    <AppLayout>
        <!-- Barra de acciones (NO imprime) -->
        <div class="no-print mb-4 flex items-center justify-between flex-wrap gap-2">
            <Link href="/app/logistica/cola-jorge" class="btn-ghost text-sm">
                <ArrowLeft class="h-4 w-4"/> Volver a la cola
            </Link>
            <button @click="imprimir" class="btn-primary">
                <Printer class="h-4 w-4"/> Imprimir hoja de picking
            </button>
        </div>

        <!-- Hoja imprimible -->
        <div class="hoja bg-white text-black p-6 rounded-lg shadow-sm max-w-4xl mx-auto">
            <!-- Encabezado -->
            <div class="border-b-2 border-black pb-3 mb-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h1 class="text-xl font-bold uppercase">Hoja de picking</h1>
                        <div class="text-sm">GREAT BABY · {{ pedido.generado_at }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-3xl font-black">{{ pedido.numero }}</div>
                        <div class="text-sm">Factura {{ pedido.factura || '—' }}</div>
                        <div v-if="pedido.numero_picking" class="text-xs font-mono text-surface-600">
                            {{ pedido.numero_picking }}
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mt-3 text-sm">
                    <div>
                        <div class="font-bold">Cliente</div>
                        <div>{{ pedido.cliente }}</div>
                        <div v-if="pedido.direccion">{{ pedido.direccion }}</div>
                        <div v-if="pedido.ciudad || pedido.telefono">
                            {{ pedido.ciudad }}{{ pedido.ciudad && pedido.telefono ? ' · ' : '' }}{{ pedido.telefono }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="font-bold">Alistador asignado</div>
                        <div>{{ pedido.alistador || '— sin asignar —' }}</div>
                        <!--
                            Quién vendió, para que la bodega pueda preguntarle sin
                            tener que averiguar de quién era el pedido. El teléfono
                            sale de su ficha de Gestión Humana; si no tiene ficha,
                            sale el nombre solo.
                        -->
                        <div v-if="pedido.asesor" class="mt-2 text-xs">
                            <span class="font-bold">Asesor:</span> {{ pedido.asesor }}<!--
                            --><span v-if="pedido.asesor_telefono"> · {{ pedido.asesor_telefono }}</span>
                        </div>
                        <div class="mt-2 text-xs">Total pedido: <b>{{ money(pedido.total) }}</b></div>
                    </div>
                </div>
            </div>

            <!--
                POR DóNDE SE CAMINA

                El área reparte la bodega en zonas con un responsable cada una, y
                su lista de picking pide tres casillas: la zona, quién responde
                por ella y «compartido con zona». Esa última es la que importa:
                un pedido que toca racks de dos zonas necesita dos personas o un
                recorrido el doble de largo, y eso hay que verlo ANTES de
                arrancar, no a mitad de camino.

                El reparto lo manda la regla `logistica.zonas_bodega`, así que
                esta banda cambia sola cuando el área mueve un rack de zona.
            -->
            <div v-if="pedido.zona" class="mb-4 flex items-stretch gap-3 border-2 border-black">
                <div class="bg-black text-white px-5 py-3 text-center">
                    <div class="text-[10px] uppercase tracking-widest opacity-80">Zona</div>
                    <div class="text-4xl font-black leading-none">{{ pedido.zona }}</div>
                </div>
                <div class="flex-1 py-2 pr-3 text-sm">
                    <div>
                        <span class="font-bold uppercase text-xs">Responsable de la zona:</span>
                        {{ pedido.responsable_zona }}
                    </div>
                    <div v-if="pedido.compartido_con && pedido.compartido_con.length"
                         class="mt-1 font-bold text-amber-800">
                        ⚠ Compartido con zona {{ pedido.compartido_con.join(' y ') }} ·
                        este pedido no se alista de una sola zona
                    </div>
                    <div v-else class="mt-1 text-xs text-surface-600">
                        Todo el pedido sale de esta zona.
                    </div>
                </div>
            </div>

            <!--
                Sin zona no se inventó una: o el pedido no tiene stock ubicado, o
                sus racks no están en el reparto. Decirlo es más útil que
                imprimir una letra que manda a caminar a donde no es.
            -->
            <div v-else class="mb-4 border-2 border-dashed border-surface-400 px-4 py-2 text-xs text-surface-700">
                Sin zona: los racks de este pedido no están repartidos en ninguna zona
                (se configura en Reglas · Logística), o todavía no hay stock ubicado.
            </div>

            <!-- Tabla de items -->
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="bg-black text-white">
                        <th class="p-2 text-left w-10">#</th>
                        <th class="p-2 text-left">SKU</th>
                        <th class="p-2 text-left">Producto</th>
                        <th class="p-2 text-center w-20">Cant.</th>
                        <th class="p-2 text-left">Ubicación (Rack · Sección · Nivel)</th>
                        <th class="p-2 text-center w-16">✓</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(it, idx) in items" :key="idx" class="border-b border-surface-300">
                        <td class="p-2 font-mono">{{ idx + 1 }}</td>
                        <td class="p-2 font-mono text-xs">{{ it.sku }}</td>
                        <td class="p-2">
                            <div class="font-semibold">{{ it.descripcion }}</div>
                            <div v-if="it.variante && (it.variante.color || it.variante.talla)" class="text-xs text-surface-600">
                                {{ it.variante.color }} {{ it.variante.talla }}
                            </div>
                            <div v-if="it.es_agregado" class="text-xs italic text-amber-700">
                                ⚠ Producto agregado · colores surtidos
                            </div>
                        </td>
                        <td class="p-2 text-center">
                            <span class="text-xl font-black">{{ it.cantidad }}</span>
                        </td>
                        <td class="p-2 text-xs">
                            <div v-if="it.ubicaciones.length === 0" class="text-red-600 font-bold">
                                ⚠ SIN STOCK — reportar a Jorge
                            </div>
                            <div v-else class="space-y-0.5">
                                <div v-for="(u, i) in it.ubicaciones" :key="i"
                                     :class="[
                                         'px-1.5 py-0.5 inline-block rounded mr-1 font-mono',
                                         i === 0 ? 'bg-black text-white font-bold text-sm' : 'bg-surface-200 text-xs',
                                     ]">
                                    <span v-if="u.zona" class="mr-0.5">{{ u.zona }}·</span>{{ u.codigo }}
                                    <span class="ml-1 opacity-75">({{ u.stock }} disp.)</span>
                                </div>
                                <div v-if="it.ubicaciones.length > 1" class="text-[10px] text-surface-500 mt-0.5">
                                    1ª opción en negro · alternativas si está vacía
                                </div>
                            </div>
                        </td>
                        <td class="p-2 text-center">
                            <!-- Casilla físicas para marcar al tomar la mercancía -->
                            <div class="inline-block w-6 h-6 border-2 border-black rounded-sm"></div>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="font-bold border-t-2 border-black">
                        <td colspan="3" class="p-2 text-right">TOTAL UNIDADES A ALISTAR</td>
                        <td class="p-2 text-center text-xl">{{ totalUnidades(items) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>

            <!-- Trazabilidad automática + observaciones -->
            <div class="mt-6 grid grid-cols-2 gap-6 text-sm">
                <div>
                    <div class="font-semibold mb-2 uppercase text-xs">Trazabilidad (automática)</div>
                    <table class="w-full text-xs border border-black">
                        <tbody>
                            <tr class="border-b border-black">
                                <td class="p-1.5 font-semibold bg-surface-100 w-32">Alistador</td>
                                <td class="p-1.5">{{ pedido.alistador || '— sin asignar —' }}</td>
                            </tr>
                            <tr class="border-b border-black">
                                <td class="p-1.5 font-semibold bg-surface-100">Asignado</td>
                                <td class="p-1.5 font-mono">{{ pedido.alistador_asignado_at || '—' }}</td>
                            </tr>
                            <tr class="border-b border-black">
                                <td class="p-1.5 font-semibold bg-surface-100">Hora inicio</td>
                                <td class="p-1.5 font-mono">{{ pedido.alistado_inicio_at || '— en curso al imprimir —' }}</td>
                            </tr>
                            <tr class="border-b border-black">
                                <td class="p-1.5 font-semibold bg-surface-100">Hora fin</td>
                                <td class="p-1.5 font-mono">{{ pedido.alistado_fin_at || '— pendiente —' }}</td>
                            </tr>
                            <tr>
                                <td class="p-1.5 font-semibold bg-surface-100">Impresa por</td>
                                <td class="p-1.5">{{ pedido.impreso_por }} · {{ pedido.generado_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="text-[10px] italic mt-1 text-surface-500">
                        Horas registradas por el ERP al tomar/terminar en la Cola. No se escriben a mano.
                    </div>
                </div>
                <div>
                    <div class="font-semibold mb-1">Observaciones / novedades</div>
                    <div class="border border-black h-32 p-1"></div>
                </div>
            </div>

            <div class="mt-4 text-[10px] text-center text-surface-500">
                Generado por GREAT BABY ERP · {{ pedido.generado_at }} · hoja {{ pedido.numero }}-picking
            </div>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    /* Esconder TODO lo que no es la hoja. */
    aside, header, nav, .no-print,
    [class*="sidebar"], [class*="topbar"], [class*="bell"] { display: none !important; }
    body, html { background: white !important; }
    .hoja { box-shadow: none !important; padding: 0 !important; max-width: 100% !important; }
    main { padding: 0 !important; }
    @page { size: letter; margin: 10mm; }
}
</style>
