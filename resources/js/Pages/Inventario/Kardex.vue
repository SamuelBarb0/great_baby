<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { History, Search, ExternalLink, Cloud, CloudOff } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import BuscadorInventario from '@/Components/BuscadorInventario.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    codigo: { type: String, default: '' },
    variante: { type: Object, default: null },
    movimientos: { type: Array, required: true },
    saldoTotal: { type: Number, default: 0 },
    costoPromedio: { type: Number, default: 0 },
    valorStock: { type: Number, default: 0 },
    sin_repartir: { type: Object, default: null },
});

const { money } = useMoney();
const q = ref(props.codigo);
const buscar = () => router.get('/app/inventario/kardex', { codigo: q.value }, { preserveState: false });

// Sprint 3 · Kardex SIIGO · leyenda del sync.
const iconoSiigo = (c) => ({ emerald: '🟢', amber: '🟡', red: '🔴', gray: '⚪' }[c] || '⚪');
const tipoLabel = (t) => ({
    entrada_compra: 'Entrada compra',
    traslado_salida: 'Traslado salida',
    traslado_entrada: 'Traslado entrada',
    stock_inicial_form: 'Stock inicial',
    carga_inicial_cliente: 'Carga inicial',
    ingreso: 'Ingreso',
    merma: 'Merma',
    faltante: 'Faltante',
    sobrante: 'Sobrante',
    ajuste_toma_fisica: 'Ajuste toma',
    salida_venta: 'Salida venta',
}[t] || t);

const tipoColor = (t, cantidad) => {
    if (cantidad > 0) return 'text-emerald-600';
    if (cantidad < 0) return 'text-red-600';
    return 'text-surface-500';
};
</script>

<template>
    <Head title="Kardex por variante · SIIGO"/>
    <AppLayout>
        <div class="max-w-7xl mx-auto space-y-4">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <History class="h-6 w-6 text-brand-600"/>
                        Kardex por variante · Formato SIIGO
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Costo promedio ponderado · valorización de stock · documento origen · sync SIIGO por asiento.
                    </p>
                </div>
                <!-- INV-B1 · buscador global · atajo "/" -->
                <div class="w-full md:w-96">
                    <BuscadorInventario placeholder="Saltar a otro SKU / producto / bodega… ( / )"/>
                </div>
            </div>

            <div class="card p-4">
                <label class="text-xs font-semibold">Código de barras o referencia</label>
                <div class="flex gap-2">
                    <input v-model="q" @keydown.enter="buscar" autofocus class="input flex-1 font-mono"/>
                    <button @click="buscar" class="btn-primary"><Search class="h-4 w-4"/> Buscar</button>
                </div>
            </div>

            <div v-if="codigo && !variante" class="card p-8 text-center text-red-600">
                No hay nada con ese código de barras ni esa referencia.
            </div>

            <!--
                LOG-H3 · la variante existe, pero lo que entró quedó asentado
                a nivel producto. Un kardex vacío se lee como «nunca se movió»,
                y acá hay unidades en bodega.
            -->
            <div v-if="sin_repartir"
                class="card p-4 border-l-4 border-amber-500 bg-amber-500/10 text-sm text-amber-900 dark:text-amber-200">
                Esta variante no tiene movimientos propios, pero el producto
                <strong>{{ sin_repartir.referencia }}</strong> tiene
                <strong>{{ sin_repartir.cuantos }}</strong>
                {{ sin_repartir.cuantos === 1 ? 'movimiento' : 'movimientos' }}
                a nivel producto, sin repartir por variante —así los asienta la recepción, porque
                la orden de compra enlaza al producto, no a la variante—.
                Buscá por la referencia <strong>{{ sin_repartir.referencia }}</strong> para verlos.
            </div>

            <!-- Header variante + KPIs valorización -->
            <div v-if="variante" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="card p-4 md:col-span-2">
                    <div class="font-mono text-xs text-surface-500">{{ variante.codigo }}</div>
                    <div class="font-bold text-lg">{{ variante.producto }}</div>
                    <div class="text-sm text-surface-500">{{ variante.referencia }} · {{ variante.detalle }}</div>
                    <div class="text-xs mt-2 flex items-center gap-1">
                        <Cloud v-if="variante.siigo_id" class="h-3 w-3 text-emerald-600"/>
                        <CloudOff v-else class="h-3 w-3 text-amber-600"/>
                        <span :class="variante.siigo_id ? 'text-emerald-700' : 'text-amber-700'">
                            {{ variante.siigo_id ? `SIIGO: ${variante.siigo_code}` : 'Pendiente de sync SIIGO' }}
                        </span>
                    </div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500">Saldo actual</div>
                    <div class="text-2xl font-bold" :class="saldoTotal > 0 ? 'text-emerald-600' : 'text-surface-400'">{{ saldoTotal }} un.</div>
                    <div class="text-xs text-surface-500 mt-1">Costo prom: {{ money(costoPromedio) }}</div>
                </div>
                <div class="card p-4 bg-brand-50 dark:bg-brand-950/30 border-brand-200">
                    <div class="text-xs uppercase text-surface-500">Valor stock (SIIGO)</div>
                    <div class="text-2xl font-bold text-brand-700">{{ money(valorStock) }}</div>
                    <div class="text-xs text-surface-500 mt-1">saldo × costo promedio</div>
                </div>
            </div>

            <!-- Tabla FORMATO SIIGO · 10 columnas contables -->
            <div v-if="variante" class="card overflow-x-auto">
                <table v-tabla-movil class="w-full text-xs min-w-[1100px]">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="text-left p-2">Fecha</th>
                            <th class="text-left p-2">Tipo mov.</th>
                            <th class="text-left p-2">Ubicación</th>
                            <th class="text-right p-2">Cant.</th>
                            <th class="text-right p-2">Costo unit</th>
                            <th class="text-right p-2">Valor mov.</th>
                            <th class="text-right p-2">Saldo</th>
                            <th class="text-right p-2">Costo prom.</th>
                            <th class="text-right p-2">Valor stock</th>
                            <th class="text-left p-2">Documento</th>
                            <th class="text-center p-2">SIIGO</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="m in movimientos" :key="m.id" class="hover:bg-surface-50">
                            <td class="p-2 whitespace-nowrap">{{ m.fecha }}</td>
                            <td class="p-2"><span class="font-semibold" :class="tipoColor(m.tipo, m.cantidad)">{{ tipoLabel(m.tipo) }}</span></td>
                            <td class="p-2 text-surface-500">{{ m.ubicacion }}</td>
                            <td class="p-2 text-right font-bold" :class="tipoColor(m.tipo, m.cantidad)">
                                {{ m.cantidad > 0 ? '+' : '' }}{{ m.cantidad }}
                            </td>
                            <td class="p-2 text-right font-mono">{{ m.costo_unit > 0 ? money(m.costo_unit) : '—' }}</td>
                            <td class="p-2 text-right font-mono">{{ m.valor_mov > 0 ? money(m.valor_mov) : '—' }}</td>
                            <td class="p-2 text-right font-bold">{{ m.saldo }}</td>
                            <td class="p-2 text-right font-mono text-surface-500">{{ m.costo_prom > 0 ? money(m.costo_prom) : '—' }}</td>
                            <td class="p-2 text-right font-mono font-bold text-brand-700">{{ m.valor_stock > 0 ? money(m.valor_stock) : '—' }}</td>
                            <td class="p-2 text-xs">
                                <Link v-if="m.referencia_link" :href="m.referencia_link" class="text-brand-600 hover:underline inline-flex items-center gap-1">
                                    {{ m.referencia_label }} <ExternalLink class="h-3 w-3"/>
                                </Link>
                                <span v-else class="text-surface-400">{{ m.referencia_label }}</span>
                            </td>
                            <td class="p-2 text-center" :title="`Asiento SIIGO · ${m.siigo_sync_hace || 'pendiente'}`">
                                {{ iconoSiigo(m.siigo_color) }}
                                <span v-if="m.siigo_journal_id" class="font-mono text-[10px] text-brand-600 ml-1">{{ m.siigo_journal_id }}</span>
                            </td>
                        </tr>
                        <tr v-if="!movimientos.length">
                            <td colspan="11" class="p-6 text-center text-surface-500">
                                Sin movimientos para esta variante.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Leyenda SIIGO -->
            <div v-if="variante" class="card p-3 text-xs text-surface-500">
                <div class="font-semibold mb-1 text-surface-700 dark:text-surface-300">Leyenda SIIGO</div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                    <div>🟢 Asiento SIIGO generado</div>
                    <div>🟡 Contable · pendiente de asiento</div>
                    <div>⚪ No requiere asiento (ej: stock inicial)</div>
                    <div>🔴 Error en el sync</div>
                </div>
                <p class="mt-2 text-[11px]">
                    <strong>Costo promedio ponderado:</strong> se recalcula solo en entradas con costo (compras, ingresos).
                    Las salidas usan el costo promedio vigente al momento del movimiento.
                    <strong>Valor stock</strong> = saldo actual × costo promedio (base de la valorización SIIGO).
                </p>
            </div>
        </div>
    </AppLayout>
</template>
