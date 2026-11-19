<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Sunrise, Package, ShieldCheck, AlertCircle, UserX, Ghost, Boxes, Wallet, Stamp } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    generado_at: String,
    pedidosB2BLentos: Array,
    garantiasSinDecidir: Array,
    facturasVencidas: Array,
    clientesDormidos: Array,
    fantasma: Array,
    criticos: Array,
    wallet: Object,
    /*
     * Lo que espera una firma. Estaba repartido en cinco pantallas —Compras,
     * Contabilidad, Gastos, Cartera y CRM— y para saber que habia frenado
     * habia que abrir las cinco y acordarse de las cinco.
     */
    aprobaciones: { type: Object, default: () => ({ grupos: [], total: 0, monto: 0 }) },
});

// LOG · El AdminBodega entra a Cosas del día pero NO maneja Dropi · así que
//   ocultamos la card de wallet Dropi + la de mercancía fantasma, que son
//   problemas financieros de Aracely/Gerencia. Él se queda con los pendientes
//   que SÍ puede resolver (pedidos lentos, garantías, críticos).
const page = usePage();
const puedeVerDropi = computed(() => !!page.props.auth?.user?.es_aracely);

const money = (n) => '$' + Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });
const totalItems = computed(() =>
    props.pedidosB2BLentos.length
    + props.garantiasSinDecidir.length
    + props.facturasVencidas.length
    + props.clientesDormidos.length
    + props.criticos.length
    + (puedeVerDropi.value ? props.fantasma.length + (props.wallet.sin_cobro > 0 ? 1 : 0) : 0)
    + (props.aprobaciones?.grupos?.length || 0)
);
</script>

<template>
    <Head title="🌅 Cosas del día"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Sunrise class="h-7 w-7 text-amber-500"/>
                        Cosas del día
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">Todo lo que necesita tu atención HOY · actualizado {{ generado_at }}</p>
                </div>
                <div v-if="totalItems === 0" class="px-4 py-2 rounded-lg bg-emerald-500/15 text-emerald-800 dark:text-emerald-300 text-sm font-bold">
                    ✨ Todo al día — sin pendientes urgentes
                </div>
                <div v-else class="px-4 py-2 rounded-lg bg-amber-500/15 text-amber-800 dark:text-amber-300 text-sm font-bold">
                    {{ totalItems }} cosas para revisar
                </div>
            </div>

            <!--
                Lo que espera firma, antes que nada.

                Va en una fila propia y arriba de la grilla porque lo de aca no
                es una alerta sino un bloqueo: una orden de compra sin aprobar
                es mercancia que no se pidio, y una solicitud de credito sin
                responder es una venta detenida con un cliente esperando.
            -->
            <div v-if="aprobaciones.grupos.length" class="card p-4 border-l-4 border-brand-500">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <div class="flex items-center gap-2">
                        <Stamp class="h-5 w-5 text-brand-600"/>
                        <span class="font-bold">Esperando tu firma</span>
                        <span class="text-xs text-surface-500">
                            {{ aprobaciones.total }} documento(s) por {{ money(aprobaciones.monto) }}
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                    <Link v-for="g in aprobaciones.grupos" :key="g.clave" :href="g.url"
                          class="block p-3 rounded-lg border border-surface-200 dark:border-surface-800 hover:border-brand-500 transition-colors">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-sm font-medium">{{ g.titulo }}</span>
                            <span class="text-xl font-black tabular-nums text-brand-600">{{ g.cantidad }}</span>
                        </div>
                        <div class="text-sm tabular-nums font-semibold mt-0.5">{{ money(g.monto) }}</div>
                        <!-- «3 pendientes» no dice si son tres gastos de taxi o
                             tres ordenes de importacion. -->
                        <div class="text-[11px] text-surface-500 mt-1">{{ g.detalle }}</div>
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Pedidos B2B lentos -->
                <div v-if="pedidosB2BLentos.length" class="card p-4 border-l-4 border-blue-500">
                    <div class="flex items-center gap-2 mb-3">
                        <Package class="h-5 w-5 text-blue-600"/>
                        <div class="font-bold">Pedidos B2B esperando +24h ({{ pedidosB2BLentos.length }})</div>
                    </div>
                    <div class="space-y-1">
                        <Link v-for="p in pedidosB2BLentos" :key="p.id" :href="p.url" class="flex items-center justify-between text-sm hover:bg-surface-50 rounded p-2">
                            <div>
                                <span class="font-mono font-bold text-brand-600">{{ p.numero }}</span>
                                <span class="text-surface-500 ml-2">{{ p.cliente }}</span>
                            </div>
                            <div class="text-right">
                                <div class="font-bold">{{ money(p.total) }}</div>
                                <div class="text-xs text-red-600">hace {{ p.hace_horas }}h</div>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- Garantías sin decidir -->
                <div v-if="garantiasSinDecidir.length" class="card p-4 border-l-4 border-amber-500">
                    <div class="flex items-center gap-2 mb-3">
                        <ShieldCheck class="h-5 w-5 text-amber-600"/>
                        <div class="font-bold">Garantías sin decidir ({{ garantiasSinDecidir.length }})</div>
                    </div>
                    <div class="space-y-1">
                        <Link v-for="g in garantiasSinDecidir" :key="g.id" :href="g.url" class="flex items-center justify-between text-sm hover:bg-surface-50 rounded p-2">
                            <div>
                                <span class="font-mono font-bold text-brand-600">{{ g.numero }}</span>
                                <span class="text-surface-500 ml-2">{{ g.cliente }}</span>
                            </div>
                            <div class="text-right">
                                <div v-if="g.plazo_vencido" class="text-xs font-bold text-red-600">⏰ plazo vencido</div>
                                <div class="text-xs text-surface-500">hace {{ g.hace_dias }}d</div>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- Facturas vencidas -->
                <div v-if="facturasVencidas.length" class="card p-4 border-l-4 border-red-500">
                    <div class="flex items-center gap-2 mb-3">
                        <AlertCircle class="h-5 w-5 text-red-600"/>
                        <div class="font-bold">Facturas vencidas — top saldo ({{ facturasVencidas.length }})</div>
                    </div>
                    <div class="space-y-1">
                        <Link v-for="f in facturasVencidas" :key="f.id" :href="f.url" class="flex items-center justify-between text-sm hover:bg-surface-50 rounded p-2">
                            <div class="min-w-0 flex-1">
                                <span class="font-mono font-bold">{{ f.numero }}</span>
                                <div class="text-xs text-surface-500 truncate">{{ f.cliente }}</div>
                            </div>
                            <div class="text-right">
                                <div class="font-bold text-red-600">{{ money(f.saldo) }}</div>
                                <div class="text-xs text-surface-500">{{ Math.abs(f.dias_vencida) }}d vencida</div>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- Clientes dormidos VIP -->
                <div v-if="clientesDormidos.length" class="card p-4 border-l-4 border-purple-500">
                    <div class="flex items-center gap-2 mb-3">
                        <UserX class="h-5 w-5 text-purple-600"/>
                        <div class="font-bold">Clientes VIP/frecuente dormidos ({{ clientesDormidos.length }})</div>
                    </div>
                    <div class="space-y-1">
                        <Link v-for="c in clientesDormidos" :key="c.id" :href="c.url" class="flex items-center justify-between text-sm hover:bg-surface-50 rounded p-2">
                            <div>
                                <div class="font-medium">{{ c.nombre }}</div>
                                <div class="text-xs text-surface-500">{{ c.segmento }} · última: {{ c.ultima_compra }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-bold text-purple-600">{{ c.dias_sin_comprar }}d</div>
                                <a v-if="c.telefono" :href="`https://wa.me/57${c.telefono.replace(/\D/g,'')}`" target="_blank" @click.stop class="text-xs text-emerald-600 hover:underline">WhatsApp</a>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- Stock crítico -->
                <div v-if="criticos.length" class="card p-4 border-l-4 border-orange-500">
                    <div class="flex items-center gap-2 mb-3">
                        <Boxes class="h-5 w-5 text-orange-600"/>
                        <div class="font-bold">Stock crítico bajo mínimo ({{ criticos.length }})</div>
                    </div>
                    <div class="space-y-1">
                        <Link v-for="(k, i) in criticos" :key="i" :href="k.url" class="flex items-center justify-between text-sm hover:bg-surface-50 rounded p-2">
                            <div>
                                <span class="font-mono text-xs">{{ k.sku }}</span>
                                <div class="text-xs text-surface-500 truncate">{{ k.producto }}</div>
                            </div>
                            <div class="text-xs text-orange-600 font-bold">mín {{ k.minimo }}</div>
                        </Link>
                    </div>
                </div>

                <!-- Wallet Dropi · solo super admin/gerencia -->
                <div v-if="puedeVerDropi && wallet.sin_cobro > 0" class="card p-4 border-l-4 border-brand-500">
                    <div class="flex items-center gap-2 mb-3">
                        <Wallet class="h-5 w-5 text-brand-600"/>
                        <div class="font-bold">Dinero Dropi sin cobrar</div>
                    </div>
                    <Link href="/app/dropi/discrepancias" class="block p-3 hover:bg-surface-50 rounded">
                        <div class="text-3xl font-bold text-red-600">{{ money(wallet.monto_faltante) }}</div>
                        <div class="text-sm text-surface-500">{{ wallet.sin_cobro }} pedidos pagados sin movimiento en wallet</div>
                    </Link>
                </div>

                <!-- Mercancía fantasma Dropi · solo super admin/gerencia -->
                <div v-if="puedeVerDropi && fantasma.length" class="card p-4 border-l-4 border-pink-500">
                    <div class="flex items-center gap-2 mb-3">
                        <Ghost class="h-5 w-5 text-pink-600"/>
                        <div class="font-bold">Mercancía fantasma Dropi ({{ fantasma.length }})</div>
                    </div>
                    <p class="text-xs text-surface-500 mb-2">Pedidos marcados devueltos que nunca llegaron a bodega física.</p>
                    <div class="text-xs space-y-1">
                        <div v-for="(f, i) in fantasma" :key="i" class="p-2 bg-surface-50 rounded font-mono">
                            {{ JSON.stringify(f).slice(0, 100) }}...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
