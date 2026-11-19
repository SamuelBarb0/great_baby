<script setup>
import { ref, reactive } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, router } from '@inertiajs/vue3';
import { Scissors, Plus, Lock, FileText, AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useEscClose } from '@/composables/useEscClose';
import { useMoney } from '@/composables/useMoney';

/*
 * La fecha de HOY en Colombia, no en UTC.
 *
 * `toISOString()` convierte a UTC antes de recortar, asi que entre las
 * 19:00 y la medianoche hora Bogota devuelve el dia siguiente. Las fechas
 * de esta pantalla quedan escritas en registros, no son un filtro: un dia
 * de corrimiento cambia lo que dice el documento.
 */
const { hoy: hoyBogota } = useBogotaDate();

const { money } = useMoney();

const props = defineProps({
    cortes: { type: Object, required: true },
});

const modalNuevo = ref(false);
useEscClose(modalNuevo);
const form = reactive({ numero: '', fecha: hoyBogota() });
const procesando = ref(false);

const crear = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post('/app/dropi/cortes', form, {
        preserveScroll: true,
        onSuccess: () => { modalNuevo.value = false; form.numero = ''; },
        onFinish: () => procesando.value = false,
    });
};

// U5/U23 · modal explicativo en vez de window.confirm nativo.
const modalCerrar = ref(false);
useEscClose(modalCerrar);
const corteACerrar = ref(null);
const cerrandoCorte = ref(false);

const solicitarCerrar = (corte) => {
    corteACerrar.value = corte;
    modalCerrar.value = true;
};
const confirmarCerrar = () => {
    if (cerrandoCorte.value || !corteACerrar.value) return;
    cerrandoCorte.value = true;
    router.post(`/app/dropi/cortes/${corteACerrar.value.id}/cerrar`, {}, {
        preserveScroll: true,
        onSuccess: () => { modalCerrar.value = false; corteACerrar.value = null; },
        onFinish: () => { cerrandoCorte.value = false; },
    });
};

const badge = (e) => ({
    abierto: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    cerrado: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
}[e] || 'bg-surface-100 text-surface-700 dark:bg-surface-800 dark:text-surface-300');
</script>

<template>
    <Head title="Cortes Dropi"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between">
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Scissors class="h-6 w-6 text-brand-600"/>
                    Cortes Dropi
                </h1>
                <button @click="modalNuevo = true" class="btn-primary"><Plus class="h-4 w-4"/> Nuevo corte</button>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 dark:text-emerald-300 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-sm">
                {{ $page.props.flash.error }}
            </div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 dark:text-surface-400 uppercase border-b border-surface-200 dark:border-surface-800">
                        <tr>
                            <th class="text-left p-3">Número</th>
                            <th class="text-left p-3">Fecha</th>
                            <th class="text-center p-3">Estado</th>
                            <th class="text-right p-3">Pedidos</th>
                            <th class="text-left p-3">Cerrado</th>
                            <th class="text-right p-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                        <tr v-for="c in cortes.data" :key="c.id" class="hover:bg-surface-50 dark:hover:bg-surface-800/50">
                            <td class="p-3 font-bold">{{ c.numero }}</td>
                            <td class="p-3">{{ c.fecha }}</td>
                            <td class="p-3 text-center">
                                <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(c.estado)]">{{ c.estado }}</span>
                            </td>
                            <td class="p-3 text-right">{{ c.pedidos_count }}</td>
                            <td class="p-3 text-xs">{{ c.cerrado_at ?? '—' }}</td>
                            <td class="p-3 text-right">
                                <button v-if="c.estado === 'abierto'" @click="solicitarCerrar(c)"
                                    class="btn-ghost text-xs text-red-600">
                                    <Lock class="h-3 w-3"/> Cerrar
                                </button>
                                <a v-if="c.estado !== 'abierto'" :href="`/dropi/manifiesto/${c.id}`"
                                    class="btn-ghost text-xs" target="_blank">
                                    <FileText class="h-3 w-3"/> Manifiesto
                                </a>
                            </td>
                        </tr>
                        <tr v-if="!cortes.data.length"><td colspan="6" class="p-6 text-center text-surface-500 text-sm">Sin cortes.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal Nuevo corte -->
        <div v-if="modalNuevo" @click.self="modalNuevo = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full">
                <h3 class="text-lg font-bold mb-3">Nuevo corte</h3>
                <div class="space-y-3">
                    <div>
                        <label class="text-xs font-semibold">Número</label>
                        <input v-model.number="form.numero" type="number" min="1" class="input w-full" autofocus/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Fecha</label>
                        <input v-model="form.fecha" type="date" class="input w-full"/>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 mt-4">
                    <button @click="modalNuevo = false" class="btn-ghost">Cancelar</button>
                    <button @click="crear" :disabled="procesando" class="btn-primary disabled:opacity-50">{{ procesando ? 'Creando…' : 'Crear' }}</button>
                </div>
            </div>
        </div>

        <!-- U5/U23 · Modal cerrar corte con explicación completa -->
        <div v-if="modalCerrar" @click.self="modalCerrar = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full border-l-4 border-red-500">
                <div class="flex items-start gap-3 mb-3">
                    <AlertTriangle class="h-8 w-8 text-red-500 flex-shrink-0"/>
                    <div>
                        <h3 class="text-lg font-bold">Cerrar corte {{ corteACerrar?.numero }}</h3>
                        <p class="text-sm text-surface-500 dark:text-surface-400 mt-1">Esta acción es irreversible.</p>
                    </div>
                </div>
                <!-- Re-audit DR-γ UX (UX-M3) · contexto financiero antes de cerrar. -->
                <div class="rounded-lg bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-800 p-3 mb-3 text-sm">
                    <div class="grid grid-cols-2 gap-2">
                        <div><span class="text-surface-500">Fecha:</span> <b>{{ corteACerrar?.fecha ?? '—' }}</b></div>
                        <div><span class="text-surface-500">Turno:</span> <b>{{ (corteACerrar?.numero || '').includes('T') || Number(corteACerrar?.hora?.split(':')?.[0] ?? 0) >= 14 ? 'Tarde (14-24)' : 'Mañana (00-14)' }}</b></div>
                        <div><span class="text-surface-500">Pedidos:</span> <b>{{ corteACerrar?.pedidos_totales ?? '—' }}</b></div>
                        <div><span class="text-surface-500">Monto esperado:</span> <b>{{ money(corteACerrar?.monto_total ?? 0) }}</b></div>
                    </div>
                </div>
                <p class="text-sm mb-2">Al cerrar el corte:</p>
                <ul class="text-sm space-y-1 list-disc list-inside text-surface-700 dark:text-surface-300 mb-4">
                    <li>Se congelan los pedidos con hash SHA-256 (auditoría).</li>
                    <li>Se genera el manifiesto DIAN en PDF.</li>
                    <li>Se crean las remisiones ARI para cada pedido despachado.</li>
                    <li>Se emiten facturas B2B a vendedores que las requieren.</li>
                    <li><b>NO se pueden agregar ni editar pedidos</b> luego.</li>
                </ul>
                <div class="flex items-center justify-end gap-2">
                    <button @click="modalCerrar = false" class="btn-ghost">Cancelar</button>
                    <button @click="confirmarCerrar" :disabled="cerrandoCorte" class="btn-primary bg-red-600 hover:bg-red-700 disabled:opacity-50">
                        {{ cerrandoCorte ? 'Cerrando…' : 'Sí, cerrar corte' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
