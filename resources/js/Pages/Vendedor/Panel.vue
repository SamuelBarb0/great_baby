<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Search, TrendingUp, TrendingDown, DollarSign, ShoppingCart, Target, Users } from 'lucide-vue-next';

const props = defineProps({
    periodo: String,
    rango: Object,
    resumen: Object,
    anterior: Object,
    variacion: Object,
    funnel: Object,
    ranking: Array,
    tendencia: Array,
    mis_pedidos: Array,
    clientes: Array,
    q: { type: String, default: null },
    es_super: Boolean,
    // Contra qué se mide este vendedor este mes. Nulo para quien ve el panel
    // de todo el equipo: una meta individual ahí no significa nada.
    meta: { type: Object, default: null },
});

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);
const pct = (v) => (v >= 0 ? '+' : '') + v + '%';
const query = ref('');
const buscar = () => router.get('/app/vendedor', { q: query.value || null, periodo: props.periodo }, { preserveState: true });
const cambiarPeriodo = (p) => router.get('/app/vendedor', { periodo: p }, { preserveState: true });

const periodosDisponibles = [
    { key: 'mes-actual', label: 'Mes actual' },
    { key: 'mes-anterior', label: 'Mes anterior' },
    { key: 'trimestre-actual', label: 'Trimestre' },
    { key: 'anio-actual', label: 'Año' },
];

// Chip color por estado de pedido.
const estadoChip = (e) => ({
    borrador: 'bg-surface-100 text-surface-700',
    enviado: 'bg-blue-100 text-blue-800',
    retenido: 'bg-amber-100 text-amber-900 ring-1 ring-amber-400',
    aprobado: 'bg-emerald-100 text-emerald-800',
    facturado: 'bg-brand-100 text-brand-800',
    despachado: 'bg-indigo-100 text-indigo-800',
    rechazado: 'bg-red-100 text-red-800',
}[e] || 'bg-surface-100');

// Mini-sparkline SVG de la tendencia 30d.
const sparklinePath = computed(() => {
    const pts = props.tendencia || [];
    if (!pts.length) return '';
    const max = Math.max(1, ...pts.map(p => p.monto));
    const w = 280, h = 60;
    return pts.map((p, i) => {
        const x = (i / (pts.length - 1)) * w;
        const y = h - (p.monto / max) * h;
        return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
});
</script>

<template>
    <Head title="Panel del vendedor"/>
    <AppLayout>
        <div class="space-y-5 max-w-7xl mx-auto">
            <!-- Encabezado + selector de periodo -->
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-bold">
                        {{ es_super ? 'Panel comercial · Todos los vendedores' : 'Mi panel de vendedor' }}
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        {{ rango.inicio }} → {{ rango.fin }} · facturado + despachado = venta efectiva
                    </p>
                </div>
                <div class="flex gap-2 flex-wrap items-center">
                    <button v-for="p in periodosDisponibles" :key="p.key" @click="cambiarPeriodo(p.key)"
                            :class="['px-3 py-1.5 text-xs rounded-lg',
                                     periodo === p.key ? 'bg-brand-600 text-white' : 'bg-surface-100 dark:bg-surface-800 hover:bg-surface-200']">
                        {{ p.label }}
                    </button>
                </div>
            </div>

            <!--
                La accion principal del vendedor, arriba del todo.

                Estaba al FINAL de la pagina, debajo de siete tarjetas de KPIs y
                con pinta de filtro: en una revision del front no se encontro, y
                el panel parecia de pura consulta. Lo que el vendedor hace en la
                calle es tomar pedidos; eso va primero.
            -->
            <div class="card p-4">
                <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
                    <div class="text-sm font-semibold">🛒 Levantar un pedido a nombre de un cliente</div>
                </div>
                <form @submit.prevent="buscar" class="flex gap-2 mb-3">
                    <div class="flex-1 relative">
                        <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-400"/>
                        <input v-model="query" type="text" placeholder="Razón social, NIT, correo o nombre…"
                               class="w-full pl-10 pr-4 py-2 border border-surface-300 rounded-lg dark:bg-surface-900 focus:border-brand-500 focus:outline-none">
                    </div>
                    <button type="submit" class="btn-primary">Buscar cliente</button>
                </form>
                <div v-if="!clientes.length" class="text-sm text-surface-400 italic text-center py-4">
                    <span v-if="q">Sin coincidencias con «{{ q }}».</span>
                    <span v-else>Escribí para buscar un cliente activo con lista de precios.</span>
                </div>
                <div v-else class="divide-y divide-surface-200 dark:divide-surface-800">
                    <Link v-for="c in clientes" :key="c.id" :href="`/app/vendedor/pedido-nuevo/${c.id}`"
                          class="block p-2 hover:bg-brand-50 dark:hover:bg-surface-800 rounded transition">
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <div>
                                <div class="font-bold text-sm flex items-center gap-2 flex-wrap">
                                    {{ c.razon_social || c.nombre_completo }}
                                    <!-- Cartera de clientes: el vendedor ve lo suyo y las
                                         cuentas libres; las de un compañero no le aparecen. -->
                                    <span v-if="c.libre"
                                          class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300">
                                        libre
                                    </span>
                                    <span v-else-if="c.es_mio"
                                          class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-brand-100 text-brand-700 dark:bg-brand-900/50 dark:text-brand-300">
                                        mi cuenta
                                    </span>
                                    <span v-else-if="c.vendedor"
                                          class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-surface-200 text-surface-600 dark:bg-surface-800 dark:text-surface-300">
                                        {{ c.vendedor }}
                                    </span>
                                </div>
                                <div class="text-xs text-surface-500">
                                    NIT {{ c.numero_documento }} · {{ c.ciudad || 'sin ciudad' }}
                                </div>
                            </div>
                            <div class="btn-primary text-xs">Armar pedido →</div>
                        </div>
                    </Link>
                </div>
            </div>

            <!--
                La meta del mes.

                Va ARRIBA de los KPI porque es lo que les da sentido: sin ella,
                «$4.120.000 este mes» no dice si se va bien o mal, y el vendedor
                se entera en la reunión de fin de mes, cuando ya no hay margen.

                El número que importa no es el porcentaje sino el RITMO: ir al
                60% el día 12 y el día 28 son dos situaciones distintas.
            -->
            <div v-if="meta" class="card p-4">
                <div v-if="! meta.tiene_meta" class="text-sm text-surface-500 flex items-center gap-2">
                    <Target class="h-4 w-4"/>
                    Este mes no tenés meta definida. Las pone Gerencia.
                </div>
                <template v-else>
                    <div class="flex flex-wrap items-end justify-between gap-4 mb-3">
                        <div>
                            <div class="text-xs uppercase font-semibold text-surface-500 flex items-center gap-1">
                                <Target class="h-3.5 w-3.5"/> Meta del mes
                            </div>
                            <div class="text-2xl font-black tabular-nums">
                                {{ money(meta.vendido) }}
                                <span class="text-base font-normal text-surface-500">de {{ money(meta.meta) }}</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-3xl font-black tabular-nums"
                                 :class="meta.va_en_ritmo ? 'text-emerald-600' : 'text-amber-600'">
                                {{ meta.cumplimiento }}%
                            </div>
                            <div class="text-[11px]" :class="meta.va_en_ritmo ? 'text-emerald-600' : 'text-amber-600'">
                                {{ meta.va_en_ritmo ? 'vas en ritmo' : 'vas por debajo del ritmo' }}
                            </div>
                        </div>
                    </div>

                    <!-- La barra, con la marca de por dónde debería ir el mes. -->
                    <div class="relative h-3 rounded bg-surface-200 dark:bg-surface-800 overflow-hidden">
                        <div class="h-full rounded transition-all"
                             :class="meta.va_en_ritmo ? 'bg-emerald-500' : 'bg-amber-500'"
                             :style="{ width: Math.min(meta.cumplimiento, 100) + '%' }"></div>
                    </div>
                    <div class="relative h-4">
                        <div class="absolute top-0 -translate-x-1/2 text-[10px] text-surface-500 whitespace-nowrap"
                             :style="{ left: Math.min(meta.avance_del_mes, 100) + '%' }">
                            ▲ {{ meta.avance_del_mes }}% del mes
                        </div>
                    </div>

                    <div v-if="meta.falta > 0" class="text-sm mt-2">
                        Faltan <strong>{{ money(meta.falta) }}</strong>
                        <template v-if="meta.ritmo_necesario">
                            · quedan {{ meta.dias_restantes }} días hábiles, o sea
                            <strong>{{ money(meta.ritmo_necesario) }} por día</strong>.
                        </template>
                        <template v-else>
                            y el mes ya terminó.
                        </template>
                    </div>
                    <div v-else class="text-sm mt-2 text-emerald-600 font-medium">
                        Meta cumplida. 🎉
                    </div>
                </template>
            </div>

            <!-- KPIs grandes con comparativa -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="card p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs uppercase font-semibold text-surface-500">Ventas facturadas</div>
                        <DollarSign class="h-4 w-4 text-emerald-600"/>
                    </div>
                    <div class="text-2xl font-black text-brand-700">{{ money(resumen.total_ventas) }}</div>
                    <div class="flex items-center gap-1 text-xs mt-1"
                         :class="variacion.ventas.tendencia === 'up' ? 'text-emerald-700' : 'text-red-700'">
                        <TrendingUp v-if="variacion.ventas.tendencia === 'up'" class="h-3 w-3"/>
                        <TrendingDown v-else class="h-3 w-3"/>
                        <span>{{ pct(variacion.ventas.valor) }} vs periodo anterior ({{ money(anterior.total_ventas) }})</span>
                    </div>
                </div>
                <div class="card p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs uppercase font-semibold text-surface-500">Pedidos cerrados</div>
                        <ShoppingCart class="h-4 w-4 text-blue-600"/>
                    </div>
                    <div class="text-2xl font-black">{{ resumen.total_pedidos }}</div>
                    <div class="flex items-center gap-1 text-xs mt-1"
                         :class="variacion.pedidos.tendencia === 'up' ? 'text-emerald-700' : 'text-red-700'">
                        <TrendingUp v-if="variacion.pedidos.tendencia === 'up'" class="h-3 w-3"/>
                        <TrendingDown v-else class="h-3 w-3"/>
                        <span>{{ pct(variacion.pedidos.valor) }} vs {{ anterior.total_pedidos }} antes</span>
                    </div>
                </div>
                <div class="card p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs uppercase font-semibold text-surface-500">Ticket promedio</div>
                        <Target class="h-4 w-4 text-amber-600"/>
                    </div>
                    <div class="text-2xl font-black">{{ money(resumen.ticket_promedio) }}</div>
                    <div class="flex items-center gap-1 text-xs mt-1"
                         :class="variacion.ticket.tendencia === 'up' ? 'text-emerald-700' : 'text-red-700'">
                        <TrendingUp v-if="variacion.ticket.tendencia === 'up'" class="h-3 w-3"/>
                        <TrendingDown v-else class="h-3 w-3"/>
                        <span>{{ pct(variacion.ticket.valor) }} vs {{ money(anterior.ticket_promedio) }}</span>
                    </div>
                </div>
            </div>

            <!-- Sparkline tendencia 30d + funnel + conversión -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card p-4 lg:col-span-2">
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-sm font-semibold">Tendencia · últimos 30 días</div>
                        <div class="text-xs text-surface-500">monto facturado por día</div>
                    </div>
                    <svg viewBox="0 0 280 60" class="w-full h-24">
                        <path :d="sparklinePath" fill="none" stroke="currentColor" stroke-width="1.5" class="text-brand-500"/>
                    </svg>
                </div>
                <div class="card p-4">
                    <div class="text-sm font-semibold mb-3">Funnel del periodo</div>
                    <div class="text-xs text-surface-500 mb-2">
                        {{ funnel.creados }} pedidos creados · <b>{{ funnel.tasa_conversion }}% convertidos</b>
                    </div>
                    <div class="space-y-1.5">
                        <div v-for="(c, estado) in funnel.conteos" :key="estado" class="flex items-center justify-between text-xs">
                            <span class="px-2 py-0.5 rounded font-semibold" :class="estadoChip(estado)">{{ estado }}</span>
                            <span><b>{{ c.cantidad }}</b> · {{ money(c.monto) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ranking clientes + Mis últimos pedidos -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="card p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <Users class="h-4 w-4 text-brand-600"/>
                        <div class="text-sm font-semibold">Top 10 clientes · {{ periodo }}</div>
                    </div>
                    <div v-if="!ranking.length" class="text-sm text-surface-400 italic text-center py-6">
                        Sin ventas facturadas en el periodo.
                    </div>
                    <div v-else class="divide-y divide-surface-200 dark:divide-surface-800">
                        <div v-for="(c, i) in ranking" :key="c.contacto_id" class="flex items-center justify-between py-2">
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold truncate text-sm">
                                    <span class="text-brand-600 mr-1">{{ i + 1 }}.</span>
                                    {{ c.cliente }}
                                </div>
                                <div class="text-xs text-surface-500">
                                    {{ c.ciudad || '—' }} · {{ c.pedidos }} pedidos
                                </div>
                            </div>
                            <div class="font-bold text-sm whitespace-nowrap">{{ money(c.monto) }}</div>
                        </div>
                    </div>
                </div>

                <div class="card p-4">
                    <div class="text-sm font-semibold mb-3">Mis últimos 10 pedidos</div>
                    <div v-if="!mis_pedidos.length" class="text-sm text-surface-400 italic text-center py-6">
                        Todavía no levantaste pedidos.
                    </div>
                    <div v-else class="space-y-1.5 text-sm">
                        <Link v-for="p in mis_pedidos" :key="p.id" :href="`/app/pedidos-b2b/${p.id}`"
                              class="flex items-center justify-between gap-2 py-1 px-2 rounded hover:bg-surface-100 dark:hover:bg-surface-800 transition">
                            <div class="flex-1 min-w-0">
                                <div class="font-mono text-xs text-surface-600">{{ p.numero }}</div>
                                <div class="truncate">{{ p.cliente }}</div>
                                <!-- El chip de estado dice «facturado» tanto para el
                                     que salió completo como para el que salió a medias.
                                     Es el pedido por el que el cliente no paga. -->
                                <div v-if="p.resto_pendiente"
                                     class="text-[10px] font-bold text-amber-700 dark:text-amber-300">
                                    ⏳ le falta mercancía
                                </div>
                            </div>
                            <span class="px-2 py-0.5 rounded text-xs font-semibold" :class="estadoChip(p.estado)">{{ p.estado }}</span>
                            <span class="font-bold text-xs whitespace-nowrap">{{ money(p.total) }}</span>
                        </Link>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
