<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ShoppingBag, FileText, Truck, Undo2, Wallet, AlertTriangle, PackagePlus } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

/*
 * Inicio del espacio e-commerce: cuánto se facturó en el mes y qué pasó con
 * los pedidos de Dropi. Sólo lectura; para el detalle contable están los
 * enlaces del menú «Contabilidad e-commerce», ya filtrados a Dropi.
 */
const props = defineProps({
    periodo: { type: Object, required: true },
    empresa: { type: Object, default: null },
    facturado: { type: Object, required: true },
    por_dia: { type: Array, required: true },
    ultimas: { type: Array, required: true },
    pedidos: { type: Object, required: true },
    wallet: { type: Object, required: true },
});

const { money } = useMoney();

const mes = ref(props.periodo.mes);
const cambiarMes = () => router.get('/app/ecommerce/ventas', { mes: mes.value }, { preserveState: true });

// La barra más alta del mes es el 100 %: así se comparan los días entre sí.
const maxDia = computed(() => Math.max(1, ...props.por_dia.map((d) => d.total)));

const fechaCorta = (iso) => {
    const [y, m, d] = String(iso).split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('es-CO', { weekday: 'short', day: 'numeric' });
};
</script>

<template>
    <Head title="Ventas e-commerce"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <ShoppingBag class="h-6 w-6 text-brand-600"/>
                        Ventas e-commerce
                    </h1>
                    <p class="text-sm text-surface-500 dark:text-surface-400">
                        <template v-if="empresa">
                            Factura <b>{{ empresa.nombre }}</b><span v-if="empresa.nit"> · NIT {{ empresa.nit }}</span>
                        </template>
                        <template v-else>Sin razón social de e-commerce configurada</template>
                        · {{ periodo.inicio }} → {{ periodo.fin }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <input type="month" v-model="mes" class="input" @change="cambiarMes"/>
                    <Link href="/app/catalogo/productos/nuevo" class="btn-primary inline-flex items-center gap-1.5 whitespace-nowrap">
                        <PackagePlus class="h-4 w-4"/> Nuevo producto
                    </Link>
                </div>
            </div>

            <div v-if="empresa && !empresa.activa" class="card p-3 border border-amber-400 bg-amber-50 dark:bg-amber-950/30 text-sm flex items-start gap-2">
                <AlertTriangle class="h-4 w-4 text-amber-600 mt-0.5 flex-shrink-0"/>
                <span>
                    La empresa del e-commerce está <b>apagada</b>: los pedidos de Dropi se despachan pero no se facturan.
                    Se activa en <b>Configuración → Empresa</b> (pide NIT y prefijo).
                </span>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400 flex items-center gap-2"><FileText class="h-3 w-3"/> Facturado</div>
                    <div class="text-xl md:text-2xl font-bold mt-1 text-emerald-600">{{ money(facturado.total) }}</div>
                    <div class="text-xs text-surface-500 mt-1">{{ facturado.cantidad }} facturas · IVA {{ money(facturado.impuestos) }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400 flex items-center gap-2"><Wallet class="h-3 w-3"/> Pagado por Dropi</div>
                    <div class="text-xl md:text-2xl font-bold mt-1">{{ money(pedidos.pagado_monto) }}</div>
                    <div class="text-xs text-surface-500 mt-1">{{ pedidos.pagados }} pedidos pagados</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400 flex items-center gap-2"><Undo2 class="h-3 w-3"/> Devoluciones</div>
                    <div class="text-xl md:text-2xl font-bold mt-1 text-red-600">{{ money(pedidos.devuelto_monto) }}</div>
                    <div class="text-xs text-surface-500 mt-1">{{ pedidos.devueltos }} pedidos devueltos</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400 flex items-center gap-2"><Wallet class="h-3 w-3"/> Saldo wallet hoy</div>
                    <div class="text-xl md:text-2xl font-bold mt-1 break-words">{{ money(wallet.saldo) }}</div>
                    <div class="text-xs text-surface-500 mt-1">Retiros del mes {{ money(wallet.retiros_mes) }}</div>
                </div>
            </div>

            <div class="card p-4">
                <h2 class="font-semibold mb-3 flex items-center gap-2"><Truck class="h-4 w-4"/> Pedidos Dropi del mes</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center">
                    <div><div class="text-2xl font-bold">{{ pedidos.recibidos }}</div><div class="text-xs text-surface-500">Recibidos</div></div>
                    <div><div class="text-2xl font-bold">{{ pedidos.despachados }}</div><div class="text-xs text-surface-500">Despachados</div></div>
                    <div><div class="text-2xl font-bold">{{ pedidos.entregados }}</div><div class="text-xs text-surface-500">Entregados</div></div>
                    <div><div class="text-2xl font-bold">{{ pedidos.devueltos }}</div><div class="text-xs text-surface-500">Devueltos</div></div>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-4">
                <div class="card p-4">
                    <h2 class="font-semibold mb-3">Facturado por día</h2>
                    <p v-if="!por_dia.length" class="text-sm text-surface-500">No hay facturas del e-commerce en este mes.</p>
                    <div v-else class="space-y-1.5">
                        <div v-for="d in por_dia" :key="d.dia" class="flex items-center gap-2 text-sm">
                            <span class="w-16 text-surface-500 tabular-nums">{{ fechaCorta(d.dia) }}</span>
                            <div class="flex-1 h-3 rounded bg-surface-100 dark:bg-surface-800 overflow-hidden">
                                <div class="h-full bg-brand-500" :style="{ width: (d.total / maxDia * 100) + '%' }"></div>
                            </div>
                            <span class="w-28 text-right tabular-nums">{{ money(d.total) }}</span>
                            <span class="w-8 text-right text-xs text-surface-500 tabular-nums">{{ d.cantidad }}</span>
                        </div>
                    </div>
                </div>

                <div class="card p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="font-semibold">Últimas facturas</h2>
                        <Link href="/app/facturas?linea=dropi" class="text-sm text-brand-600 hover:underline">Ver todas →</Link>
                    </div>
                    <p v-if="!ultimas.length" class="text-sm text-surface-500">Todavía no hay facturas este mes.</p>
                    <table v-else class="w-full text-sm">
                        <tbody>
                            <tr v-for="f in ultimas" :key="f.id" class="border-t border-surface-100 dark:border-surface-800">
                                <td class="py-1.5">
                                    <Link :href="`/app/facturas/${f.id}`" class="font-medium text-brand-600 hover:underline">{{ f.numero }}</Link>
                                    <div class="text-xs text-surface-500">{{ f.cliente }}</div>
                                </td>
                                <td class="py-1.5 text-xs text-surface-500">{{ f.fecha }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ money(f.total) }}</td>
                                <td class="py-1.5 text-right text-xs text-surface-500">{{ f.estado_label }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
