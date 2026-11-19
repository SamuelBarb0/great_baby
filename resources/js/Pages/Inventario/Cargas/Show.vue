<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, PackagePlus, Scale, AlertTriangle, ExternalLink } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import { pucLabel } from '@/composables/pucLabels';

const props = defineProps({
    carga: { type: Object, required: true },
    kardex: { type: Array, default: () => [] },
    asiento: { type: Array, default: () => [] },
    asiento_url: { type: String, default: null },
});
const { money } = useMoney();

const totalDebe = computed(() => props.asiento.reduce((s, l) => s + l.debe, 0));
const totalHaber = computed(() => props.asiento.reduce((s, l) => s + l.haber, 0));
</script>

<template>
    <Head :title="`Carga ${carga.numero}`"/>
    <AppLayout>
        <div class="max-w-5xl mx-auto space-y-4 p-4">
            <Link href="/app/inventario/importar-cliente" class="text-sm text-brand-600 hover:underline inline-flex items-center gap-1">
                <ArrowLeft class="h-4 w-4"/> Volver a Importar inventario
            </Link>

            <div class="card p-5">
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <PackagePlus class="h-6 w-6 text-brand-600"/>
                    Carga de inventario <span class="font-mono">{{ carga.numero }}</span>
                </h1>
                <dl class="mt-4 grid sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <div><dt class="text-surface-500 inline">Motivo:</dt> <dd class="inline font-semibold">{{ carga.motivo }}</dd></div>
                    <div><dt class="text-surface-500 inline">Fecha:</dt> <dd class="inline">{{ carga.fecha }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-surface-500 inline">Observación:</dt> <dd class="inline">{{ carga.observacion }}</dd></div>
                    <div v-if="carga.tercero"><dt class="text-surface-500 inline">Tercero:</dt> <dd class="inline">{{ carga.tercero }}</dd></div>
                    <div v-if="carga.soporte"><dt class="text-surface-500 inline">Soporte:</dt> <dd class="inline font-mono">{{ carga.soporte }}</dd></div>
                    <div><dt class="text-surface-500 inline">Bodega:</dt> <dd class="inline">{{ carga.ubicacion }}</dd></div>
                    <div><dt class="text-surface-500 inline">Cargado por:</dt> <dd class="inline">{{ carga.usuario || (carga.canal === 'cli' ? 'Consola (artisan)' : '—') }}</dd></div>
                    <div v-if="carga.archivo"><dt class="text-surface-500 inline">Archivo:</dt> <dd class="inline font-mono text-xs">{{ carga.archivo }}</dd></div>
                </dl>
                <div class="mt-4 grid grid-cols-3 gap-3">
                    <div class="rounded-lg bg-surface-50 dark:bg-surface-800 p-3 text-center">
                        <div class="text-2xl font-bold">{{ carga.referencias }}</div>
                        <div class="text-xs text-surface-500">Referencias</div>
                    </div>
                    <div class="rounded-lg bg-surface-50 dark:bg-surface-800 p-3 text-center">
                        <div class="text-2xl font-bold">{{ carga.total_unidades }}</div>
                        <div class="text-xs text-surface-500">Unidades</div>
                    </div>
                    <div class="rounded-lg bg-surface-50 dark:bg-surface-800 p-3 text-center">
                        <div class="text-2xl font-bold text-brand-700">{{ money(carga.total_valor) }}</div>
                        <div class="text-xs text-surface-500">Valor asentado</div>
                    </div>
                </div>
                <div v-if="carga.referencias_sin_costo > 0" class="mt-3 p-3 rounded bg-amber-100 text-amber-900 text-sm flex gap-2">
                    <AlertTriangle class="h-4 w-4 mt-0.5 flex-shrink-0"/>
                    <span><strong>{{ carga.referencias_sin_costo }}</strong> referencias entraron sin costo: movieron stock pero no suman al asiento.</span>
                </div>
            </div>

            <!-- Cómo quedó en el libro -->
            <div class="card p-5">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h2 class="font-bold flex items-center gap-2"><Scale class="h-5 w-5 text-brand-600"/>Asiento contable</h2>
                    <a v-if="asiento_url && asiento.length" :href="asiento_url" class="text-sm text-brand-600 hover:underline inline-flex items-center gap-1">
                        Ver en Contabilidad <ExternalLink class="h-3 w-3"/>
                    </a>
                </div>
                <div v-if="!asiento.length" class="text-sm text-surface-500">
                    Esta carga no generó asiento: ninguna referencia tenía costo, así que no hay valor que llevar al libro.
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 border-b">
                            <tr><th class="text-left p-2">Cuenta</th><th class="text-left p-2">Descripción</th><th class="text-right p-2">Débito</th><th class="text-right p-2">Crédito</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="l in asiento" :key="l.id">
                                <td class="p-2">
                                    <div class="font-mono font-bold text-brand-600">{{ l.cuenta }}</div>
                                    <div class="text-[10px] text-surface-500">{{ pucLabel(l.cuenta) }}</div>
                                </td>
                                <td class="p-2 text-xs">{{ l.descripcion }}</td>
                                <td class="p-2 text-right text-blue-600">{{ l.debe > 0 ? money(l.debe) : '—' }}</td>
                                <td class="p-2 text-right text-purple-600">{{ l.haber > 0 ? money(l.haber) : '—' }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t-2 font-bold">
                            <tr>
                                <td colspan="2" class="p-2 text-right">Totales</td>
                                <td class="p-2 text-right text-blue-600">{{ money(totalDebe) }}</td>
                                <td class="p-2 text-right text-purple-600">{{ money(totalHaber) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                    <p class="text-xs text-surface-500 mt-2">Contabilidad interna del ERP · no se envía a SIIGO.</p>
                </div>
            </div>

            <!-- Qué entró -->
            <div class="card p-5">
                <h2 class="font-bold mb-3">Referencias de la carga</h2>
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 border-b">
                            <tr>
                                <th class="text-left p-2">Referencia</th><th class="text-left p-2">Producto</th><th class="text-left p-2">Ubicación</th>
                                <th class="text-right p-2">Cant.</th><th class="text-right p-2">Costo unit</th><th class="text-right p-2">Valor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="k in kardex" :key="k.id" :class="k.valor <= 0 ? 'bg-amber-50/60 dark:bg-amber-950/20' : ''">
                                <td class="p-2 font-mono text-xs">{{ k.referencia }}</td>
                                <td class="p-2">{{ k.producto }}</td>
                                <td class="p-2 text-xs text-surface-500">{{ k.ubicacion }}</td>
                                <td class="p-2 text-right font-bold">{{ k.cantidad }}</td>
                                <td class="p-2 text-right font-mono">{{ k.costo_unit > 0 ? money(k.costo_unit) : 'sin costo' }}</td>
                                <td class="p-2 text-right font-mono">{{ k.valor > 0 ? money(k.valor) : '—' }}</td>
                            </tr>
                            <tr v-if="!kardex.length"><td colspan="6" class="p-6 text-center text-surface-500">Sin movimientos.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
