<script setup>
import { ref, reactive, watch } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, router } from '@inertiajs/vue3';
import { Wallet, Plus, TrendingUp, TrendingDown } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import { useEscClose } from '@/composables/useEscClose';

/*
 * La fecha de HOY en Colombia, no en UTC.
 *
 * `toISOString()` convierte a UTC antes de recortar, asi que entre las
 * 19:00 y la medianoche hora Bogota devuelve el dia siguiente. Las fechas
 * de esta pantalla quedan escritas en registros, no son un filtro: un dia
 * de corrimiento cambia lo que dice el documento.
 */
const { hoy: hoyBogota } = useBogotaDate();

const props = defineProps({
    movimientos: { type: Object, required: true },
    saldo: { type: Number, default: 0 },
    saldo_bruto: { type: Number, default: 0 },
    sanciones_total: { type: Number, default: 0 },
});

const { money } = useMoney();

// U4 · tipo como select cerrado (evita typos que rompen agrupaciones de reportes).
const TIPOS = [
    { value: 'pago_guia', label: 'Pago de guía' },
    { value: 'retiro_banco', label: 'Retiro a banco' },
    { value: 'indemnizacion', label: 'Indemnización' },
    { value: 'flete_garantia', label: 'Flete de garantía' },
    { value: 'tarjeta', label: 'Comisión tarjeta' },
    { value: 'otro', label: 'Otro' },
];

const modalNuevo = ref(false);
useEscClose(modalNuevo);
const form = reactive({
    fecha: hoyBogota(),
    tipo: 'otro',
    monto: 0,
    guia: '',           // U20 · autocomplete por guía (backend resuelve pedido_id)
    pedido_id: null,
    categoria: 'ajuste_manual',
});
const procesando = ref(false);

// U20 · resolver pedido_id vía búsqueda por guía (debounced 400ms).
const buscando = ref(false);
const pedidoLabel = ref('');
let debounceHandle;
watch(() => form.guia, (val) => {
    pedidoLabel.value = '';
    form.pedido_id = null;
    clearTimeout(debounceHandle);
    if (!val || val.trim().length < 4) return;
    debounceHandle = setTimeout(async () => {
        buscando.value = true;
        try {
            const res = await fetch('/app/dropi/escaner/buscar', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ codigo: val.trim() }),
            });
            const data = await res.json();
            if (data.encontrado) {
                form.pedido_id = data.pedido.id;
                pedidoLabel.value = `${data.pedido.cliente} · ${data.pedido.ciudad}`;
            } else {
                pedidoLabel.value = '⚠ Guía no encontrada';
            }
        } catch { pedidoLabel.value = '⚠ Error de búsqueda'; }
        finally { buscando.value = false; }
    }, 400);
});

const crear = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post('/app/dropi/wallet', {
        fecha: form.fecha, tipo: form.tipo, monto: form.monto,
        pedido_id: form.pedido_id, categoria: form.categoria,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            modalNuevo.value = false;
            form.monto = 0; form.guia = ''; form.pedido_id = null; pedidoLabel.value = '';
        },
        onFinish: () => procesando.value = false,
    });
};
</script>

<template>
    <Head title="Wallet Dropi"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Wallet class="h-6 w-6 text-brand-600"/>
                    Wallet · movimientos
                </h1>
                <button @click="modalNuevo = true" class="btn-primary"><Plus class="h-4 w-4"/> Ajuste manual</button>
            </div>

            <!-- U15 · saldo visible en la página dedicada -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400 flex items-center gap-2"><TrendingUp class="h-3 w-3"/> Saldo neto</div>
                    <div class="text-3xl font-bold mt-1" :class="saldo >= 0 ? 'text-emerald-600' : 'text-red-600'">{{ money(saldo) }}</div>
                    <div class="text-[10px] text-surface-500 mt-1">Bruto {{ money(saldo_bruto) }} − sanciones {{ money(sanciones_total) }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400">Movimientos (últimos 30)</div>
                    <div class="text-3xl font-bold mt-1">{{ movimientos.data.length }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 dark:text-surface-400 flex items-center gap-2"><TrendingDown class="h-3 w-3 text-red-500"/> Sanciones acum.</div>
                    <div class="text-2xl font-bold mt-1 text-red-600">{{ money(sanciones_total) }}</div>
                </div>
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
                            <th class="text-left p-3">Fecha</th>
                            <th class="text-left p-3">Tipo</th>
                            <th class="text-left p-3">Pedido</th>
                            <th class="text-left p-3">Categoría</th>
                            <th class="text-left p-3">Referencia</th>
                            <th class="text-right p-3">Monto</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                        <tr v-for="m in movimientos.data" :key="m.id" class="hover:bg-surface-50 dark:hover:bg-surface-800/50">
                            <td class="p-3">{{ m.fecha }}</td>
                            <td class="p-3 text-xs">{{ m.tipo }}</td>
                            <td class="p-3 text-xs">{{ m.pedido_id ?? '—' }}</td>
                            <td class="p-3 text-xs">{{ m.categoria }}</td>
                            <td class="p-3 font-mono text-xs">{{ m.referencia }}</td>
                            <td class="p-3 text-right font-bold" :class="m.monto >= 0 ? 'text-emerald-600' : 'text-red-600'">
                                {{ money(m.monto) }}
                            </td>
                        </tr>
                        <tr v-if="!movimientos.data.length"><td colspan="6" class="p-6 text-center text-surface-500 text-sm">Sin movimientos.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="modalNuevo" @click.self="modalNuevo = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full">
                <h3 class="text-lg font-bold mb-3">Ajuste manual wallet</h3>
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="text-xs font-semibold">Fecha</label><input v-model="form.fecha" type="date" class="input w-full"/></div>
                        <div><label class="text-xs font-semibold">Tipo</label>
                            <select v-model="form.tipo" class="input w-full">
                                <option v-for="t in TIPOS" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>
                    </div>
                    <div><label class="text-xs font-semibold">Monto (positivo entra, negativo sale)</label>
                        <input type="number" step="0.01" v-model.number="form.monto" class="input w-full" autofocus/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Guía Dropi (opcional)</label>
                        <input v-model="form.guia" class="input w-full font-mono" placeholder="Ej: 4200000123456"/>
                        <div v-if="buscando" class="text-[11px] text-surface-500 mt-1">Buscando…</div>
                        <div v-else-if="pedidoLabel" class="text-[11px] mt-1" :class="form.pedido_id ? 'text-emerald-600' : 'text-red-600'">
                            {{ pedidoLabel }}
                        </div>
                    </div>
                    <div><label class="text-xs font-semibold">Categoría</label><input v-model="form.categoria" class="input w-full"/></div>
                </div>
                <div class="flex items-center justify-end gap-2 mt-4">
                    <button @click="modalNuevo = false" class="btn-ghost">Cancelar</button>
                    <button @click="crear" :disabled="procesando" class="btn-primary disabled:opacity-50">{{ procesando ? '…' : 'Registrar' }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
