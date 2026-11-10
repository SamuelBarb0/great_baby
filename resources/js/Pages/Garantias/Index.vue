<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ShieldCheck, Plus, Search } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ref, computed } from 'vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    tickets: { type: Object, required: true },
    conteos: { type: Object, required: true },
    // Cumplimiento del plazo para dar concepto. El ticket guardaba
    // `plazo_concepto_at` desde el principio y nadie lo miraba: se podía
    // incumplir todos los días sin que apareciera en ninguna pantalla.
    sla: { type: Object, default: () => ({}) },
    // Lo que cuesta la posventa. El área medía tiempos y no plata.
    costos: { type: Object, default: () => ({ desglose: {}, por_producto: [] }) },
    filtros: { type: Object, required: true },
});

const { money: fmtCOP } = useMoney();

const ETIQUETAS_COSTO = {
    producto: 'Producto',
    flete_recoger: 'Flete recoger',
    flete_enviar: 'Flete enviar',
    nota_credito: 'Nota crédito',
    otros: 'Otros',
};

/*
 * Sólo los renglones que tienen plata.
 *
 * Mostrar los cinco siempre, tres de ellos en cero, hace que el ojo tenga que
 * buscar cuál importa. El porcentaje es lo que de verdad se lee: «el 70% es
 * flete» es un problema de logística inversa, no de producto.
 */
const desgloseVisible = computed(() => {
    const d = props.costos.desglose ?? {};
    const total = props.costos.total || 0;

    return Object.entries(d)
        .filter(([, v]) => v > 0)
        .map(([k, v]) => ({
            k,
            label: ETIQUETAS_COSTO[k] ?? k,
            valor: v,
            pct: total > 0 ? Math.round((v / total) * 100) : 0,
        }))
        .sort((a, b) => b.valor - a.valor);
});

const q = ref(props.filtros.q || '');
let deb;
const buscar = () => {
    clearTimeout(deb);
    deb = setTimeout(() => router.get('/app/garantias', { q: q.value, estado: props.filtros.estado }, { preserveState: true, replace: true }), 300);
};

const filtrarEstado = (e) => router.get('/app/garantias', { estado: e || null, q: props.filtros.q }, { preserveState: true });

const tabs = [
    { key: '', label: 'Todos' },
    { key: 'abierto', label: 'Abiertos' },
    { key: 'aprobada', label: 'Aprobadas' },
    { key: 'en_reposicion', label: 'En reposición' },
    { key: 'rechazada', label: 'Rechazadas' },
    { key: 'cerrada', label: 'Cerradas' },
];

const badge = (e) => ({
    abierto: 'bg-blue-100 text-blue-800',
    en_revision: 'bg-amber-100 text-amber-800',
    aprobada: 'bg-emerald-100 text-emerald-800',
    en_reposicion: 'bg-brand-100 text-brand-800',
    rechazada: 'bg-red-100 text-red-800',
    cerrada: 'bg-surface-200 text-surface-600',
}[e] || 'bg-surface-100');
</script>

<template>
    <Head title="Garantías"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <ShieldCheck class="h-6 w-6 text-brand-600"/>
                        Servicio al Cliente · Garantías
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">Tickets, decisiones y reposiciones a valor $0.</p>
                </div>
                <Link href="/app/garantias/nueva" class="btn-primary"><Plus class="h-4 w-4"/> Nueva garantía</Link>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">{{ $page.props.flash.success }}</div>

            <!-- Cómo va el área con su propio plazo -->
            <div class="card p-4 flex flex-wrap items-center gap-6">
                <div>
                    <div class="text-[10px] uppercase text-surface-500">Se contesta en</div>
                    <div class="font-black text-lg">{{ sla.plazo_dias }} días</div>
                </div>
                <div class="h-9 border-l border-surface-200 dark:border-surface-800"></div>
                <div>
                    <div class="text-[10px] uppercase text-surface-500">Cumplimiento</div>
                    <div v-if="sla.cumplimiento !== null" class="font-black text-lg"
                        :class="sla.cumplimiento >= 90 ? 'text-emerald-600'
                            : sla.cumplimiento >= 70 ? 'text-amber-600' : 'text-red-600'">
                        {{ sla.cumplimiento }} %
                    </div>
                    <div v-else class="text-sm text-surface-500">sin datos todavía</div>
                    <div class="text-[11px] text-surface-500">{{ sla.a_tiempo }} de {{ sla.decididos }} a tiempo</div>
                </div>
                <div>
                    <div class="text-[10px] uppercase text-surface-500">Demora promedio</div>
                    <div class="font-black text-lg">
                        {{ sla.horas_promedio !== null ? sla.horas_promedio + ' h' : '—' }}
                    </div>
                </div>
                <div>
                    <div class="text-[10px] uppercase text-surface-500">Abiertos y vencidos</div>
                    <div class="font-black text-lg" :class="sla.vencidos_abiertos ? 'text-red-600' : ''">
                        {{ sla.vencidos_abiertos }}
                    </div>
                    <div v-if="sla.vencidos_abiertos" class="text-[11px] text-red-600">
                        esperan concepto hace más de {{ sla.plazo_dias }} días
                    </div>
                </div>
            </div>

            <!--
                Qué cuesta la posventa.

                El área medía tiempos y no plata: el ticket guardaba
                `valor_reposicion`, que es lo que paga el cliente —cero, por
                contrato— y nada de lo que le cuesta a la empresa.
            -->
            <div class="card p-4">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600">
                        💸 Costo de la posventa · {{ costos.desde }} a {{ costos.hasta }}
                    </div>
                    <div v-if="costos.sin_costo > 0" class="text-xs text-amber-700 dark:text-amber-300">
                        ⚠ {{ costos.sin_costo }} garantía(s) sin costo cargado — este total cuenta de menos
                    </div>
                </div>

                <div class="flex flex-wrap items-start gap-6">
                    <div>
                        <div class="text-[10px] uppercase text-surface-500">Total del período</div>
                        <div class="text-2xl font-black tabular-nums text-red-600">{{ fmtCOP(costos.total) }}</div>
                        <div class="text-[11px] text-surface-500">{{ costos.tickets }} garantía(s) con costo</div>
                    </div>
                    <div class="h-9 border-l border-surface-200 dark:border-surface-800"></div>
                    <div>
                        <div class="text-[10px] uppercase text-surface-500">Promedio por garantía</div>
                        <div class="text-lg font-black tabular-nums">{{ fmtCOP(costos.promedio) }}</div>
                    </div>
                    <div class="h-9 border-l border-surface-200 dark:border-surface-800"></div>
                    <!-- En qué se fue: si el grueso es flete, el problema es la
                         logística inversa, no el producto. -->
                    <div class="flex flex-wrap gap-4">
                        <div v-for="d in desgloseVisible" :key="d.k">
                            <div class="text-[10px] uppercase text-surface-500">{{ d.label }}</div>
                            <div class="text-sm font-bold tabular-nums">{{ fmtCOP(d.valor) }}</div>
                            <div class="text-[10px] text-surface-500">{{ d.pct }}%</div>
                        </div>
                    </div>
                </div>

                <div v-if="costos.por_producto?.length" class="mt-4">
                    <div class="text-[10px] uppercase text-surface-500 mb-1">
                        Las referencias que más cuestan
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[420px] text-sm">
                            <thead>
                                <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                    <th class="text-left py-1">Producto</th>
                                    <th class="text-right">Garantías</th>
                                    <th class="text-right">Costo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in costos.por_producto" :key="p.referencia"
                                    class="border-b border-surface-100 dark:border-surface-800/60">
                                    <td class="py-1.5">
                                        {{ p.producto }}
                                        <span class="text-[10px] text-surface-500 font-mono block">{{ p.referencia }}</span>
                                    </td>
                                    <td class="text-right tabular-nums text-surface-500">{{ p.tickets }}</td>
                                    <td class="text-right tabular-nums font-bold text-red-600">{{ fmtCOP(p.costo) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div v-else class="mt-3 text-xs text-surface-500">
                    Todavía no hay garantías con costo cargado en este período. El costo se carga
                    dentro de cada ticket, y se puede volver cuando llegue la factura del flete.
                </div>
            </div>

            <div class="card p-3 flex items-center gap-2 flex-wrap">
                <div class="relative flex-1 min-w-[200px]">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-500"/>
                    <input v-model="q" @input="buscar" placeholder="Buscar por número, cliente o teléfono…" class="input w-full pl-10"/>
                </div>
                <button v-for="t in tabs" :key="t.key" @click="filtrarEstado(t.key)"
                    :class="['px-3 py-1.5 text-sm rounded-lg',
                        (filtros.estado || '') === t.key ? 'bg-brand-600 text-white' : 'bg-surface-100 hover:bg-surface-200']">
                    {{ t.label }}<span v-if="conteos[t.key]" class="ml-1 text-xs opacity-80">({{ conteos[t.key] }})</span>
                </button>
            </div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil data-vacia="No hay tickets de garantía abiertos." class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-3">Número</th>
                            <th class="text-left p-3">Cliente</th>
                            <th class="text-left p-3">Descripción</th>
                            <th class="text-right p-3">Cant</th>
                            <th class="text-left p-3">Creado</th>
                            <th class="text-center p-3">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="t in tickets.data" :key="t.id" class="hover:bg-surface-50">
                            <td class="p-3 font-mono font-bold">
                                <Link :href="`/app/garantias/${t.id}`" class="text-brand-600 hover:underline">{{ t.numero }}</Link>
                            </td>
                            <td class="p-3">
                                <div class="font-medium">{{ t.cliente }}</div>
                                <div class="text-xs text-surface-500">{{ t.telefono }}</div>
                            </td>
                            <td class="p-3 text-xs max-w-md truncate" :title="t.descripcion">{{ t.descripcion }}</td>
                            <td class="p-3 text-right font-bold">{{ t.cantidad }}</td>
                            <td class="p-3 text-xs">{{ t.creado }}</td>
                            <td class="p-3 text-center">
                                <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(t.estado)]">{{ t.estado }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
