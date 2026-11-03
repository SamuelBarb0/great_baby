<script setup>
import { Ship } from 'lucide-vue-next';

defineProps({ reporte: { type: Object, required: true } });

const money = (n) => (n === null || n === undefined ? '—' : '$' + Number(n).toLocaleString('es-CO', { maximumFractionDigits: 0 }));
const num = (n) => Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 1 });
</script>

<template>
    <div class="card p-4 space-y-3">
        <div class="text-xs uppercase font-bold text-brand-600 flex items-center gap-1"><Ship class="h-3.5 w-3.5"/>Contenedores</div>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
            <div><div class="text-xs text-surface-500">En el agua</div><b class="text-xl">{{ reporte.conteos.en_agua }}</b></div>
            <div><div class="text-xs text-surface-500">Entre puerto y zona franca</div><b class="text-xl">{{ reporte.conteos.en_puerto }}</b></div>
            <div><div class="text-xs text-surface-500">En bodega</div><b class="text-xl">{{ reporte.conteos.en_bodega }}</b></div>
        </div>
        <div v-if="reporte.filas.length" class="overflow-x-auto">
            <table v-tabla-movil class="w-full text-xs">
                <thead class="text-[10px] uppercase text-surface-500 border-b border-surface-200 dark:border-surface-800">
                    <tr>
                        <th class="text-left p-2">Contenedor</th>
                        <th class="text-left p-2">Etapa</th>
                        <th class="text-right p-2">Días en el mar</th>
                        <th class="text-right p-2">m³</th>
                        <th class="text-right p-2">Mercancía</th>
                        <th class="text-right p-2">Flete</th>
                        <th class="text-right p-2">Flete por m³</th>
                        <th class="text-right p-2">Arancel</th>
                        <th class="text-right p-2">IVA</th>
                        <th class="text-right p-2">Otros</th>
                        <th class="text-right p-2">Total gastos</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                    <tr v-for="f in reporte.filas" :key="f.id">
                        <td class="p-2"><a :href="`/app/compras/importacion/${f.id}`" class="font-mono font-bold text-brand-600 hover:underline">{{ f.contenedor || f.numero }}</a></td>
                        <td class="p-2">
                            {{ f.etapa || '—' }}
                            <span v-if="f.dias_atraso > 0" class="block font-semibold text-red-600">{{ f.dias_atraso }} día(s) de atraso</span>
                        </td>
                        <td class="p-2 text-right">{{ f.dias_transito ?? '—' }}</td>
                        <td class="p-2 text-right">{{ num(f.m3) }}</td>
                        <td class="p-2 text-right">{{ money(f.fob) }}</td>
                        <td class="p-2 text-right">{{ money(f.flete) }}</td>
                        <td class="p-2 text-right font-semibold">{{ money(f.flete_m3) }}</td>
                        <td class="p-2 text-right">{{ money(f.arancel) }}</td>
                        <td class="p-2 text-right">{{ money(f.iva) }}</td>
                        <td class="p-2 text-right">{{ money(f.otros) }}</td>
                        <td class="p-2 text-right font-bold">{{ money(f.gastos) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="text-sm text-surface-500">Sin contenedores con órdenes todavía.</p>
    </div>
</template>
