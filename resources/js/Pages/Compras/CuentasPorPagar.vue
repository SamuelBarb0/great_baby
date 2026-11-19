<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Receipt, Search } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    filas: { type: Array, required: true },
    resumen: { type: Object, required: true },
    porProveedor: { type: Array, required: true },
    filtros: { type: Object, required: true },
});

const money = (n) => '$' + Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });

const filtrar = (campo, val) =>
    router.get('/app/compras/cuentas-por-pagar', { ...props.filtros, [campo]: val || null },
        { preserveState: true, replace: true });

/*
 * El color sale de los días, no de un estado guardado: una factura no «es»
 * vencida, se vuelve vencida sola el día que pasa la fecha.
 */
const tono = (dias) => {
    if (dias > 60) return 'bg-red-500/20 text-red-700';
    if (dias > 0) return 'bg-amber-500/20 text-amber-700';
    if (dias > -8) return 'bg-blue-500/20 text-blue-700';
    return 'bg-surface-500/15 text-surface-600';
};

const diasTexto = (d) => {
    if (d > 0) return `${d} día${d === 1 ? '' : 's'} de mora`;
    if (d === 0) return 'vence hoy';
    return `faltan ${Math.abs(d)} día${Math.abs(d) === 1 ? '' : 's'}`;
};

const hayFiltro = computed(() => props.filtros.estado !== 'todas' || !! props.filtros.q);
</script>

<template>
    <Head title="Cuentas por pagar"/>
    <AppLayout>
        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Receipt class="h-6 w-6 text-brand-600"/> Cuentas por pagar
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Recepciones confirmadas con factura de proveedor, menos lo abonado. El vencimiento sale del plazo
                    pactado en su condición de crédito; sin condición, de contado.
                </p>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="card p-3">
                    <div class="text-[11px] uppercase text-surface-500">Saldo total</div>
                    <div class="text-xl font-bold font-mono">{{ money(resumen.total) }}</div>
                    <div class="text-[11px] text-surface-500">
                        {{ resumen.facturas }} factura(s) · {{ resumen.proveedores }} proveedor(es)
                    </div>
                </div>
                <div class="card p-3 border-l-4 border-red-500">
                    <div class="text-[11px] uppercase text-surface-500">Vencido</div>
                    <div class="text-xl font-bold font-mono text-red-600">{{ money(resumen.vencido) }}</div>
                </div>
                <div class="card p-3 border-l-4 border-blue-500">
                    <div class="text-[11px] uppercase text-surface-500">Por vencer</div>
                    <div class="text-xl font-bold font-mono text-blue-600">{{ money(resumen.por_vencer) }}</div>
                </div>
                <div class="card p-3">
                    <div class="text-[11px] uppercase text-surface-500">Mayor acreedor</div>
                    <div class="text-sm font-bold truncate" :title="porProveedor[0]?.proveedor">
                        {{ porProveedor[0]?.proveedor ?? '—' }}
                    </div>
                    <div class="font-mono text-sm">{{ money(porProveedor[0]?.saldo) }}</div>
                </div>
            </div>

            <div class="card p-2 flex gap-2 flex-wrap items-center">
                <button v-for="f in [['todas','Todas'],['vencidas','Vencidas'],['por_vencer','Por vencer']]" :key="f[0]"
                        @click="filtrar('estado', f[0])"
                        :class="['px-3 py-1.5 rounded text-xs font-semibold',
                                 filtros.estado === f[0] ? 'bg-brand-600 text-white' : 'text-surface-600 hover:bg-surface-100']">
                    {{ f[1] }}
                </button>
                <div class="relative ml-auto">
                    <Search class="h-4 w-4 absolute left-2 top-2.5 text-surface-400"/>
                    <input :value="filtros.q" @input="filtrar('q', $event.target.value)"
                           placeholder="Proveedor o número de factura…" class="input pl-8 text-sm w-64"/>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <!-- El detalle, que es la hoja que hoy imprimen -->
                <div class="card overflow-hidden lg:col-span-2">
                    <table class="w-full text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900 text-[11px] uppercase text-surface-500">
                            <tr>
                                <th class="p-2 text-left">Proveedor</th>
                                <th class="p-2 text-left">Factura</th>
                                <th class="p-2 text-left">Vence</th>
                                <th class="p-2 text-right">Saldo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="f in filas" :key="f.recepcion_id">
                                <td class="p-2">
                                    {{ f.proveedor }}
                                    <span class="block text-[11px] text-surface-500">recepción {{ f.numero }}</span>
                                </td>
                                <td class="p-2 font-mono text-xs">{{ f.factura ?? '—' }}</td>
                                <td class="p-2">
                                    <span class="text-[10px] px-2 py-0.5 rounded font-semibold" :class="tono(f.dias_mora)">
                                        {{ f.vencimiento }}
                                    </span>
                                    <span class="block text-[11px] text-surface-500">{{ diasTexto(f.dias_mora) }}</span>
                                </td>
                                <td class="p-2 text-right font-mono font-bold">
                                    {{ money(f.saldo) }}
                                    <span v-if="f.abonado" class="block text-[11px] font-normal text-surface-500">
                                        abonado {{ money(f.abonado) }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!filas.length">
                                <td colspan="4" class="p-6 text-center text-surface-500 text-sm">
                                    {{ hayFiltro
                                        ? 'Nada con ese filtro.'
                                        : 'No hay facturas de proveedor pendientes. Aparecen acá cuando se confirma una recepción con su número de factura.' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Concentración por proveedor: con quién está la deuda -->
                <div class="card overflow-hidden">
                    <div class="p-2 border-b text-xs uppercase font-bold text-brand-600">Por proveedor</div>
                    <table class="w-full text-sm">
                        <tbody class="divide-y">
                            <tr v-for="p in porProveedor" :key="p.proveedor">
                                <td class="p-2">
                                    {{ p.proveedor }}
                                    <span class="block text-[11px] text-surface-500">{{ p.facturas }} factura(s)</span>
                                </td>
                                <td class="p-2 text-right font-mono">
                                    {{ money(p.saldo) }}
                                    <span v-if="p.vencido" class="block text-[11px] text-red-600">
                                        {{ money(p.vencido) }} vencido
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!porProveedor.length">
                                <td colspan="2" class="p-4 text-center text-surface-500 text-xs">Sin deuda.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
