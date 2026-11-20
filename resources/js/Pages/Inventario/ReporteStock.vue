<script setup>
import { Head } from '@inertiajs/vue3';
import { BarChart3, MapPin, Package, DollarSign, Cloud, CloudOff, Search } from 'lucide-vue-next';
import { ref, computed, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    ubicaciones: { type: Array, required: true },
    topStock: { type: Array, required: true },
    kpis: { type: Object, required: true },
});

const { money } = useMoney();

/*
 * Con el plano cargado, esta tabla pasó a 491 renglones y no tenía ni
 * buscador ni corte: para ver el saldo del rack 12 había que recorrerla
 * entera con la rueda del ratón. Un reporte que hay que recorrer para
 * encontrar el dato no es un reporte.
 *
 * Se agregan las dos cosas que faltaban: buscar por código o nombre, y
 * pintar de a tandas. Los totales de arriba NO cambian —siguen siendo de
 * toda la operación—, porque son lo que alguien viene a mirar primero.
 */
const buscar = ref('');

const sinTildes = (t) => (t || '').toString().toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '');

const filtradas = computed(() => {
    const q = sinTildes(buscar.value).trim();

    if (! q) return props.ubicaciones;

    const partes = q.split(/\s+/);

    return props.ubicaciones.filter((u) => {
        const heno = sinTildes([u.codigo, u.nombre, u.categoria].join(' '));

        return partes.every((x) => heno.includes(x));
    });
});

const POR_TANDA = 50;
const cuantasPintar = ref(POR_TANDA);

const visibles = computed(() => filtradas.value.slice(0, cuantasPintar.value));
const faltan = computed(() => filtradas.value.length - visibles.value.length);

watch(buscar, () => { cuantasPintar.value = POR_TANDA; });

/** Lo que suma lo que se está viendo, que no siempre es todo. */
const totalFiltrado = computed(() => ({
    unidades: filtradas.value.reduce((a, u) => a + Number(u.unidades || 0), 0),
    valor: filtradas.value.reduce((a, u) => a + Number(u.valor || 0), 0),
}));
</script>

<template>
    <Head title="Reporte stock por bodega · SIIGO"/>
    <AppLayout>
        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <BarChart3 class="h-6 w-6 text-brand-600"/>
                    Reporte stock por bodega · Formato SIIGO
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Valorización de inventario a costo promedio ponderado (base contable SIIGO).
                </p>
            </div>

            <!-- KPIs incluyen valorización -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="card p-4">
                    <div class="text-xs text-surface-500 flex items-center gap-2"><MapPin class="h-3 w-3"/> Bodegas</div>
                    <div class="text-3xl font-bold mt-1">{{ kpis.ubicaciones_activas }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs text-surface-500 flex items-center gap-2"><Package class="h-3 w-3"/> Unidades</div>
                    <div class="text-3xl font-bold mt-1 text-brand-600">{{ kpis.unidades_totales.toLocaleString('es-CO') }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs text-surface-500">SKUs con stock</div>
                    <div class="text-3xl font-bold mt-1">{{ kpis.skus_con_stock }}</div>
                </div>
                <div class="card p-4 bg-brand-50 dark:bg-brand-950/30 border-brand-200">
                    <div class="text-xs text-surface-500 flex items-center gap-2"><DollarSign class="h-3 w-3"/> Valor total inventario</div>
                    <div class="text-2xl font-bold mt-1 text-brand-700">{{ money(kpis.valor_total_inventario) }}</div>
                    <div class="text-[10px] text-surface-500 mt-1">a costo promedio · base SIIGO</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Stock por ubicación con valorización -->
                <div class="card p-4">
                    <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
                        <div class="text-xs uppercase font-bold text-brand-600">Stock por ubicación (valorizado)</div>
                        <div class="relative w-full sm:w-80">
                            <Search class="absolute left-2 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-400"/>
                            <input v-model="buscar" type="search"
                                   class="input w-full pl-8 text-sm"
                                   aria-label="Buscar una ubicación"
                                   placeholder="Buscar · ej: R12, rack 8, principal"/>
                        </div>
                    </div>

                    <p v-if="buscar" class="text-xs text-surface-500 mb-2">
                        <b>{{ filtradas.length }}</b> de {{ ubicaciones.length }} ubicaciones ·
                        {{ totalFiltrado.unidades.toLocaleString('es-CO') }} unidades ·
                        <span class="font-mono">{{ money(totalFiltrado.valor) }}</span>
                        <button class="ml-2 underline hover:text-brand-600" @click="buscar = ''">limpiar</button>
                    </p>
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-[10px] uppercase text-surface-500 border-b">
                            <tr>
                                <th class="text-left p-2">Ubicación</th>
                                <th class="text-left p-2">Categoría</th>
                                <th class="text-right p-2">SKUs</th>
                                <th class="text-right p-2">Unidades</th>
                                <th class="text-right p-2">Valor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="u in visibles" :key="u.codigo" class="hover:bg-surface-50">
                                <td class="p-2 font-mono">{{ u.codigo }}<div class="text-[10px] text-surface-500">{{ u.nombre }}</div></td>
                                <td class="p-2 text-xs">{{ u.categoria }}</td>
                                <td class="p-2 text-right">{{ u.skus }}</td>
                                <td class="p-2 text-right font-bold">{{ u.unidades.toLocaleString('es-CO') }}</td>
                                <td class="p-2 text-right font-bold font-mono text-brand-700">{{ money(u.valor) }}</td>
                            </tr>
                        </tbody>
                        <tfoot v-if="ubicaciones.length" class="border-t-2">
                            <tr class="font-bold bg-brand-50/50 dark:bg-brand-950/20">
                                <td class="p-2 text-xs uppercase">Total</td>
                                <td class="p-2"></td>
                                <td class="p-2"></td>
                                <td class="p-2 text-right">{{ kpis.unidades_totales.toLocaleString('es-CO') }}</td>
                                <td class="p-2 text-right font-mono text-brand-800">{{ money(kpis.valor_total_inventario) }}</td>
                            </tr>
                        </tfoot>
                    </table>

                    <!-- Una tabla que se corta sola y no avisa hace creer que
                         el dato no existe. Se dice cuántas faltan. -->
                    <div v-if="faltan > 0" class="pt-3 mt-2 border-t border-surface-200 dark:border-surface-800 text-center space-y-2">
                        <p class="text-xs text-surface-500">
                            Se muestran <b>{{ visibles.length }}</b> de {{ filtradas.length }} ubicaciones.
                            El total de abajo es de toda la operación, no de lo que se ve.
                        </p>
                        <div class="flex items-center justify-center gap-2 flex-wrap">
                            <button type="button" class="btn-ghost text-sm min-h-11" @click="cuantasPintar += POR_TANDA">
                                Ver {{ Math.min(POR_TANDA, faltan) }} más
                            </button>
                            <button type="button" class="btn-ghost text-sm min-h-11 text-surface-500"
                                    @click="cuantasPintar = filtradas.length">
                                Ver las {{ filtradas.length }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Top con costo promedio y SIIGO -->
                <div class="card p-4">
                    <div class="text-xs uppercase font-bold text-brand-600 mb-3">Top 30 con más stock</div>
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-[10px] uppercase text-surface-500 border-b">
                            <tr>
                                <th class="text-left p-2">SKU</th>
                                <th class="text-left p-2">Producto</th>
                                <th class="text-right p-2">Unid.</th>
                                <th class="text-right p-2">Costo prom.</th>
                                <th class="text-right p-2">Valor</th>
                                <th class="text-center p-2">SIIGO</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(t, i) in topStock" :key="i" class="hover:bg-surface-50">
                                <td class="p-2 font-mono text-xs">{{ t.codigo }}</td>
                                <td class="p-2 text-xs">{{ t.producto }}</td>
                                <td class="p-2 text-right font-bold text-brand-600">{{ t.total.toLocaleString('es-CO') }}</td>
                                <td class="p-2 text-right font-mono text-xs">{{ t.costo_prom > 0 ? money(t.costo_prom) : '—' }}</td>
                                <td class="p-2 text-right font-mono font-bold text-brand-700">{{ t.valor > 0 ? money(t.valor) : '—' }}</td>
                                <td class="p-2 text-center">
                                    <Cloud v-if="t.siigo_code" class="h-3 w-3 text-emerald-600 inline" :title="`SIIGO: ${t.siigo_code}`"/>
                                    <CloudOff v-else class="h-3 w-3 text-amber-600 inline" title="Pendiente sync SIIGO"/>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Nota SIIGO -->
            <div class="card p-3 text-xs text-surface-500">
                <p>
                    <strong>Costo promedio ponderado</strong> = Σ(cantidad × costo_unit de entradas) / Σ(cantidad entradas).
                    <strong>Valor</strong> = saldo actual × costo promedio.
                    Este es el valor que reporta SIIGO al cierre de inventario. Diferencias entre este total y el
                    saldo contable SIIGO en cuenta 1435 indican asientos no sincronizados
                    (ver <a href="/app/siigo" class="text-brand-600 underline">panel de sincronización</a>).
                </p>
            </div>
        </div>
    </AppLayout>
</template>
