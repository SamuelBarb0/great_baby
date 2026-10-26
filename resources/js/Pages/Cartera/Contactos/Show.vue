<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useMoney } from '@/composables/useMoney';
import { ArrowLeft, User, Phone, Mail, MapPin, FileText, TrendingUp, Wallet, FilePlus, CreditCard, Briefcase } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import KpiCard from '@/Components/KpiCard.vue';

const props = defineProps({
    contacto: { type: Object, required: true },
    facturas: { type: Array, required: true },
    metricas: { type: Object, required: true },
    // Cartera de clientes · sólo gerencia reasigna.
    puede_asignar_vendedor: { type: Boolean, default: false },
    vendedores: { type: Array, default: () => [] },
});

const vendedorSel = ref(props.contacto.vendedor_id ?? '');
const guardandoVendedor = ref(false);

const guardarVendedor = () => {
    if (guardandoVendedor.value) return;
    guardandoVendedor.value = true;
    router.post(`/app/contactos/${props.contacto.id}/vendedor`,
        { vendedor_id: vendedorSel.value === '' ? null : Number(vendedorSel.value) },
        { preserveScroll: true, onFinish: () => (guardandoVendedor.value = false) });
};

const { money: fmtCOP } = useMoney();

const rolLabel = {
    cliente: 'Cliente',
    b2b: 'B2B',
    proveedor: 'Proveedor',
    empleado: 'Empleado',
    vendedor: 'Vendedor Dropi',
};
const rolesActivos = Object.entries(props.contacto.roles).filter(([, v]) => v).map(([k]) => rolLabel[k]);

const badgeEstado = {
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    success: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    danger: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    gray: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
};

const abrirWA = () => {
    if (! props.contacto.telefono) return;
    const t = String(props.contacto.telefono).replace(/\D/g, '');
    const num = t.startsWith('57') ? t : '57' + t;
    window.open(`https://wa.me/${num}`, '_blank');
};
</script>

<template>
    <Head :title="contacto.nombre"/>
    <AppLayout>
        <div class="space-y-4 max-w-6xl">
            <!-- Header -->
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <Link href="/app/contactos" class="text-sm text-surface-500 hover:text-brand-600 flex items-center gap-1 mb-2">
                        <ArrowLeft class="h-4 w-4"/> Volver a contactos
                    </Link>
                    <h1 class="text-2xl font-bold flex items-center gap-2 text-surface-900 dark:text-surface-100">
                        <User class="h-6 w-6 text-brand-600"/>
                        {{ contacto.nombre }}
                        <span v-if="!contacto.activo" class="text-xs uppercase text-red-600 bg-red-50 dark:bg-red-950/30 px-2 py-0.5 rounded">Inactivo</span>
                    </h1>
                    <div class="text-sm text-surface-500 font-mono mt-1">{{ contacto.documento }}</div>
                    <div class="flex flex-wrap gap-1 mt-2">
                        <span v-for="r in rolesActivos" :key="r"
                              class="text-xs font-bold uppercase px-2 py-0.5 rounded bg-brand-50 text-brand-800 dark:bg-brand-900/30 dark:text-brand-300">
                            {{ r }}
                        </span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link :href="`/app/contactos/${contacto.id}/editar`" class="btn-ghost border border-surface-300 dark:border-surface-700 text-sm min-h-11">
                        <User class="h-4 w-4"/> Editar
                    </Link>
                    <!-- La factura sale del pedido aprobado, no a mano: así el
                         inventario y la contabilidad quedan cuadrados. El botón
                         lleva a la bandeja, que es el camino real. -->
                    <Link v-if="contacto.roles?.cliente || contacto.roles?.b2b"
                          href="/app/facturacion/bandeja" class="btn-primary text-sm min-h-11">
                        <FilePlus class="h-4 w-4"/> Facturar un pedido
                    </Link>
                    <Link v-if="contacto.roles?.cliente || contacto.roles?.b2b"
                          href="/app/pagos" class="btn-primary bg-brand-600 text-sm min-h-11">
                        <CreditCard class="h-4 w-4"/> Registrar pago
                    </Link>
                    <button v-if="contacto.telefono" @click="abrirWA" class="btn-primary bg-emerald-600 hover:bg-emerald-700 text-sm">
                        <Phone class="h-4 w-4"/> WhatsApp
                    </button>
                </div>
            </div>

            <!-- Datos + KPIs -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card p-4 lg:col-span-1">
                    <div class="text-xs uppercase tracking-wider text-surface-500 mb-3">Contacto</div>
                    <div class="space-y-2 text-sm">
                        <div v-if="contacto.telefono" class="flex items-center gap-2"><Phone class="h-4 w-4 text-surface-400"/> {{ contacto.telefono }}</div>
                        <div v-if="contacto.email" class="flex items-center gap-2"><Mail class="h-4 w-4 text-surface-400"/> <span class="truncate">{{ contacto.email }}</span></div>
                        <div v-if="contacto.fuente" class="text-xs text-surface-500">
                            Llegó por <span class="font-semibold text-surface-700 dark:text-surface-200">{{ contacto.fuente }}</span><span v-if="contacto.fuente_detalle"> · {{ contacto.fuente_detalle }}</span>
                        </div>
                        <div v-if="contacto.direccion || contacto.ciudad" class="flex items-start gap-2 text-surface-500">
                            <MapPin class="h-4 w-4 flex-shrink-0 mt-0.5"/>
                            <div>
                                <div v-if="contacto.direccion">{{ contacto.direccion }}</div>
                                <div v-if="contacto.ciudad">{{ contacto.ciudad }}<span v-if="contacto.departamento">, {{ contacto.departamento }}</span></div>
                            </div>
                        </div>
                        <div v-if="contacto.regimen_iva" class="pt-2 text-xs text-surface-500">
                            Régimen: <span class="font-medium">{{ contacto.regimen_iva }}</span>
                        </div>
                        <div v-if="contacto.siigo_id" class="text-xs text-surface-500">
                            SIIGO: <span class="font-mono">{{ contacto.siigo_id }}</span>
                        </div>
                    </div>

                    <!-- Cartera de clientes: quién atiende la cuenta. Decide
                         quién puede levantarle pedidos y de quién es la
                         comisión, por eso sólo lo cambia gerencia. -->
                    <div class="mt-4 pt-3 border-t border-surface-200 dark:border-surface-800">
                        <div class="text-xs uppercase tracking-wider text-surface-500 mb-2 flex items-center gap-1.5">
                            <Briefcase class="h-3.5 w-3.5"/> Vendedor de la cuenta
                        </div>

                        <template v-if="puede_asignar_vendedor">
                            <select v-model="vendedorSel" class="input w-full text-sm min-h-11">
                                <option value="">— Cliente libre —</option>
                                <option v-for="v in vendedores" :key="v.id" :value="v.id">{{ v.nombre }}</option>
                            </select>
                            <p class="text-xs text-surface-500 mt-1">
                                Sin vendedor, la cuenta queda libre y la toma el primero que le venda.
                            </p>
                            <button @click="guardarVendedor"
                                    :disabled="guardandoVendedor || String(vendedorSel) === String(contacto.vendedor_id ?? '')"
                                    class="btn-primary w-full mt-2 text-sm disabled:opacity-40">
                                {{ guardandoVendedor ? 'Guardando…' : 'Guardar vendedor' }}
                            </button>
                        </template>

                        <div v-else class="text-sm">
                            <span v-if="contacto.vendedor" class="font-semibold">{{ contacto.vendedor }}</span>
                            <span v-else class="text-surface-500 italic">Cliente libre</span>
                        </div>
                    </div>
                </div>
                <KpiCard label="Saldo pendiente" :value="metricas.saldo_pendiente" color="red" format="money" :icon="Wallet"/>
                <KpiCard label="Facturado histórico" :value="metricas.facturado_historico" color="emerald" format="money" :icon="TrendingUp"
                         :subtitle="metricas.facturas_count + ' facturas'"/>
            </div>

            <!-- Facturas -->
            <div class="card p-4">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3 flex items-center gap-2">
                    <FileText class="h-4 w-4"/> Últimas facturas ({{ facturas.length }})
                </div>
                <div v-if="! facturas.length" class="text-center py-6 text-surface-500 text-sm">Este contacto no tiene facturas registradas.</div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[500px] text-sm">
                        <thead>
                            <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                <th class="text-left py-2">Número</th>
                                <th class="text-right">Emisión</th>
                                <th class="text-right">Vence</th>
                                <th class="text-right">Total</th>
                                <th class="text-right">Saldo</th>
                                <th class="text-center">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="f in facturas" :key="f.id" class="border-b border-surface-100 dark:border-surface-900 hover:bg-surface-50 dark:hover:bg-surface-900/30">
                                <td class="py-2 font-mono text-brand-600">
                                    <Link :href="'/app/facturas/' + f.id" class="hover:underline font-semibold">{{ f.numero }}</Link>
                                </td>
                                <td class="text-right text-surface-500">{{ f.fecha }}</td>
                                <td class="text-right text-surface-500">{{ f.vence }}</td>
                                <td class="text-right font-mono font-bold">{{ fmtCOP(f.total) }}</td>
                                <td class="text-right font-mono font-bold" :class="f.saldo > 0 ? 'text-red-600' : 'text-emerald-600'">{{ fmtCOP(f.saldo) }}</td>
                                <td class="text-center">
                                    <span :class="['inline-block px-2 py-0.5 rounded text-xs font-bold', badgeEstado[f.estado_color] || badgeEstado.gray]">{{ f.estado_label }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
