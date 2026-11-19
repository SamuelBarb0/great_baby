<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Tags, AlertTriangle, Check } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    listas: { type: Array, required: true },
    // Cuántas cosas se pueden tarifar en total: variantes de los granulares
    // más los productos agregados.
    vendibles: { type: Number, default: 0 },
});

const pct = (l) => (props.vendibles > 0 ? Math.round((l.tarifados / props.vendibles) * 100) : 0);
</script>

<template>
    <Head title="Listas de precios · Catálogo"/>
    <AppLayout>
        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Tags class="h-6 w-6 text-brand-600"/> Listas de precios
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    A cada cliente se le asigna una lista, y el vendedor sólo puede ofrecerle lo que
                    tenga precio en ella. Una lista <strong>vacía</strong> es un cliente que no le puede
                    comprar nada: no aparece en el panel del vendedor ni en el portal.
                </p>
            </div>

            <!--
                El aviso va arriba y no al pie: si ninguna lista tiene precios,
                todo lo demás de la pantalla es decoración.
            -->
            <div v-if="listas.every(l => l.tarifados === 0)"
                 class="p-3 rounded-lg text-sm bg-amber-500/15 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200 flex gap-2">
                <AlertTriangle class="h-5 w-5 shrink-0"/>
                <div>
                    <strong>Ninguna lista tiene precios cargados.</strong>
                    Mientras siga así, ningún vendedor va a ver clientes en su panel y ningún
                    cliente del portal va a ver productos. Entrá a una lista y cargá precios.
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                <th class="text-left py-2 px-3">Lista</th>
                                <th class="text-left">Canal</th>
                                <th class="text-left">Origen</th>
                                <th class="text-right">Clientes</th>
                                <th class="text-right">Con precio</th>
                                <th class="text-left px-3">Cobertura</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in listas" :key="l.id"
                                class="border-b border-surface-100 dark:border-surface-800/60 hover:bg-surface-50 dark:hover:bg-surface-900/30">
                                <td class="py-2 px-3">
                                    <Link :href="`/app/catalogo/listas-precios/${l.id}`"
                                          class="font-medium hover:text-brand-600">{{ l.nombre }}</Link>
                                    <span v-if="! l.activa"
                                          class="ml-2 text-[10px] uppercase px-1.5 py-0.5 rounded bg-surface-200 dark:bg-surface-800 text-surface-600">inactiva</span>
                                </td>
                                <td class="text-surface-500">{{ l.canal }}</td>
                                <td class="text-surface-500 text-xs">{{ l.origen }}</td>
                                <td class="text-right tabular-nums"
                                    :class="l.clientes > 0 && l.tarifados === 0 ? 'text-red-600 font-bold' : 'text-surface-500'">
                                    {{ l.clientes }}
                                </td>
                                <td class="text-right tabular-nums font-semibold">{{ l.tarifados }}</td>
                                <td class="px-3 w-56">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 h-2 rounded bg-surface-200 dark:bg-surface-800 overflow-hidden">
                                            <div class="h-full rounded"
                                                 :class="pct(l) === 0 ? 'bg-red-500' : pct(l) < 100 ? 'bg-amber-500' : 'bg-emerald-500'"
                                                 :style="{ width: Math.max(pct(l), l.tarifados > 0 ? 4 : 0) + '%' }"></div>
                                        </div>
                                        <span class="text-xs tabular-nums w-10 text-right"
                                              :class="pct(l) === 0 ? 'text-red-600' : 'text-surface-500'">{{ pct(l) }}%</span>
                                        <Check v-if="pct(l) >= 100" class="h-4 w-4 text-emerald-600"/>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="p-3 text-xs text-surface-500 border-t border-surface-100 dark:border-surface-800">
                    La cobertura se mide contra <strong>{{ vendibles }}</strong> cosas tarifables:
                    las variantes de los productos que se venden por talla o color, más los productos
                    de colores surtidos, que llevan un solo precio.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
