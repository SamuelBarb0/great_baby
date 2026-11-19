<script setup>
/**
 * La hoja de una OLEADA.
 *
 * El procedimiento del área separa el picking del packing con un paso propio:
 * «Consolidación — trasladar al punto de consolidación y agrupar la totalidad
 * de ítems por cada orden», con entrada «artículos de distintas zonas».
 *
 * Por eso la hoja tiene dos caras y se imprimen juntas:
 *
 *   1. RECOGER — una fila por ubicación, en el orden en que se camina la
 *      bodega, con el total de todos los pedidos juntos. Un viaje por
 *      referencia, no uno por pedido.
 *   2. REPARTIR — qué le toca a cada pedido y en qué casilla de la zona de
 *      alistamiento se arma.
 *
 * Se imprime, así que todo tiene que leerse en blanco y negro: nada de color
 * como único portador de información.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Printer, ArrowLeft, AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    oleada: { type: Object, required: true },
    generado_at: { type: String, default: '' },
    impreso_por: { type: String, default: '' },
});

const resumen = computed(() => props.oleada.resumen || {});
const recorrido = computed(() => props.oleada.recorrido || []);
const casillas = computed(() => props.oleada.casillas || []);

/** Cuántas paradas hay que caminar de verdad (sin las excepciones). */
/** `window` no existe en el scope del template: se expone desde el script. */
const imprimir = () => window.print();

const paradasDelRecorrido = computed(
    () => recorrido.value.filter(p => !p.es_respaldo && p.codigo !== 'SIN UBICAR').length,
);
</script>

<template>
    <Head title="Hoja de oleada"/>

    <AppLayout>
        <div class="no-print flex items-center justify-between mb-4">
            <Link href="/app/logistica/cola-jorge" class="btn-ghost inline-flex items-center gap-1.5 text-sm">
                <ArrowLeft class="h-4 w-4"/> Volver a la cola
            </Link>
            <button @click="imprimir" class="btn-primary inline-flex items-center gap-1.5">
                <Printer class="h-4 w-4"/> Imprimir hoja de oleada
            </button>
        </div>

        <div class="hoja bg-white text-black p-6 rounded-lg shadow-sm max-w-5xl mx-auto">
            <!-- Encabezado -->
            <div class="border-b-2 border-black pb-3 mb-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h1 class="text-xl font-bold uppercase">Hoja de oleada · recoger y repartir</h1>
                        <div class="text-sm">GREAT BABY · {{ generado_at }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-3xl font-black">{{ resumen.pedidos }}</div>
                        <div class="text-sm">pedidos en esta oleada</div>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-4 mt-3 text-sm">
                    <div>
                        <div class="font-bold uppercase text-xs">Paradas del recorrido</div>
                        <div class="text-2xl font-black">{{ paradasDelRecorrido }}</div>
                    </div>
                    <div>
                        <div class="font-bold uppercase text-xs">Unidades a recoger</div>
                        <div class="text-2xl font-black">{{ resumen.unidades }}</div>
                    </div>
                    <div>
                        <div class="font-bold uppercase text-xs">Zonas que se caminan</div>
                        <div class="text-2xl font-black">{{ (resumen.zonas || []).join(' · ') || '—' }}</div>
                    </div>
                </div>
            </div>

            <!--
                Las dos excepciones se avisan ARRIBA, antes de salir a caminar.
                Enterarse a mitad de recorrido de que falta mercancía es lo que
                el área quiere evitar.
            -->
            <div v-if="resumen.hay_faltantes"
                 class="mb-3 border-2 border-black p-2 text-sm flex gap-2">
                <AlertTriangle class="h-5 w-5 shrink-0"/>
                <div>
                    <b>Hay unidades que el sistema no encuentra dónde están.</b>
                    Salen al final de la tabla, marcadas «SIN UBICAR». Repórtelas antes de
                    cerrar la oleada: un pedido incompleto no sale.
                </div>
            </div>

            <div v-if="resumen.hay_respaldo"
                 class="mb-3 border-2 border-dashed border-black p-2 text-sm">
                <b>Esta oleada toca EXHIBICIÓN.</b>
                El área lo pide por escrito: si los racks no completan la cantidad, se toma
                de allá para no dejar picos de referencia — y <b>hay que registrar el traslado</b>.
                Esas paradas van al final, con el borde punteado.
            </div>

            <!-- ───────────── CARA 1 · RECOGER ───────────── -->
            <h2 class="text-lg font-black uppercase border-b-2 border-black mb-2">
                1 · Recoger — en este orden, sin devolverse
            </h2>

            <table class="w-full text-sm border-collapse mb-6">
                <thead>
                    <tr class="bg-black text-white">
                        <th class="p-2 text-left w-10">#</th>
                        <th class="p-2 text-left">Dónde</th>
                        <th class="p-2 text-left">Qué</th>
                        <th class="p-2 text-center w-20">Total</th>
                        <th class="p-2 text-left">Para qué casillas</th>
                        <th class="p-2 text-center w-14">✓</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="(parada, idx) in recorrido" :key="parada.codigo">
                        <tr v-for="(linea, j) in parada.lineas" :key="parada.codigo + j"
                            class="border-b border-surface-300"
                            :class="parada.es_respaldo || parada.codigo === 'SIN UBICAR' ? 'border-l-4 border-l-black' : ''">
                            <td class="p-2 font-mono align-top">
                                <span v-if="j === 0">{{ idx + 1 }}</span>
                            </td>
                            <td class="p-2 align-top">
                                <template v-if="j === 0">
                                    <div class="font-mono font-bold">
                                        <span v-if="parada.zona">{{ parada.zona }}·</span>{{ parada.codigo }}
                                    </div>
                                    <div v-if="parada.es_respaldo" class="text-[10px] font-bold uppercase">
                                        Exhibición · registre el traslado
                                    </div>
                                    <div v-else-if="parada.codigo === 'SIN UBICAR'" class="text-[10px] font-bold uppercase">
                                        Repórtelo
                                    </div>
                                </template>
                            </td>
                            <td class="p-2 align-top">
                                <div class="font-mono text-xs">{{ linea.sku }}</div>
                                <div class="text-xs">{{ linea.descripcion }}</div>
                            </td>
                            <td class="p-2 text-center align-top">
                                <span class="text-xl font-black">{{ linea.total }}</span>
                            </td>
                            <td class="p-2 align-top text-xs">
                                <span v-for="(d, k) in linea.reparto" :key="k"
                                      class="inline-block mr-2 whitespace-nowrap">
                                    <b class="font-mono">{{ d.casilla || '—' }}</b>: {{ d.cantidad }}
                                </span>
                            </td>
                            <td class="p-2 text-center align-top">
                                <div class="inline-block w-6 h-6 border-2 border-black rounded-sm"></div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <!-- ───────────── CARA 2 · REPARTIR ───────────── -->
            <h2 class="text-lg font-black uppercase border-b-2 border-black mb-2">
                2 · Repartir en la zona de alistamiento
            </h2>

            <div class="grid grid-cols-2 gap-3">
                <div v-for="p in casillas" :key="p.id" class="border-2 border-black p-2">
                    <div class="flex items-baseline justify-between border-b border-black pb-1 mb-1">
                        <div class="text-2xl font-black font-mono">{{ p.casilla || '—' }}</div>
                        <div class="text-right">
                            <div class="font-bold">{{ p.numero }}</div>
                            <div class="text-xs">{{ p.unidades }} unidades</div>
                        </div>
                    </div>
                    <div class="text-xs font-semibold">{{ p.cliente }}</div>
                    <div v-if="p.ciudad" class="text-xs text-surface-600 mb-1">{{ p.ciudad }}</div>
                    <table class="w-full text-xs">
                        <tr v-for="(it, j) in p.items" :key="j" class="border-t border-surface-200">
                            <td class="py-0.5 font-mono">{{ it.sku }}</td>
                            <td class="py-0.5 text-right font-bold">{{ it.cantidad }}</td>
                            <td class="py-0.5 w-6 text-center">
                                <span class="inline-block w-3.5 h-3.5 border border-black"></span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="mt-6 pt-3 border-t-2 border-black text-xs flex justify-between">
                <span>Impresa por {{ impreso_por }} · {{ generado_at }}</span>
                <span>El pedido sale completo o no sale.</span>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
@media print {
    .no-print { display: none !important; }
    .hoja { box-shadow: none; padding: 0; max-width: none; }
    /* Repartir empieza en página nueva: es otro momento y otra persona. */
    h2:nth-of-type(2) { break-before: page; }
    tr { break-inside: avoid; }
}
</style>
