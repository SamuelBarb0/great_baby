<script setup>
import { ref, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { FilePlus, Plus, Search, Cloud, CloudOff, RefreshCw, X } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';
import { useDestacar } from '@/composables/useDestacar';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasNotas } from '@/composables/usePestanasContables';
import { useMoney } from '@/composables/useMoney';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import { useEscClose } from '@/composables/useEscClose';

const props = defineProps({
    filtros: { type: Object, required: true },
    notas: { type: Object, required: true },
    kpis: { type: Object, required: true },
});

// La Torre SIIGO enlaza aca con `?destacar=<id>` cuando el documento no tiene
// pantalla de detalle propia. Senala la fila en vez de dar un 404.
const { clasesDe } = useDestacar();

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

const { money } = useMoney();
const q = ref(props.filtros.q);
const sinSiigo = ref(!!props.filtros.sin_siigo);

let debounce;
const filtrar = () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get('/app/cartera/notas-debito', {
            q: q.value || null,
            sin_siigo: sinSiigo.value ? 1 : null,
        }, { preserveScroll: true, preserveState: true, replace: true });
    }, 300);
};

const modal = ref(false);
useEscClose(modal);
const form = reactive({ factura_id: null, valor: 0, motivo: '' });
const buscar = ref('');
const facturasEncontradas = ref([]);
const procesando = ref(false);

let debSearch;
const buscarFactura = () => {
    clearTimeout(debSearch);
    if (buscar.value.length < 2) { facturasEncontradas.value = []; return; }
    debSearch = setTimeout(async () => {
        const r = await fetch(`/app/api/contactos/buscar?q=${encodeURIComponent(buscar.value)}&scope=facturas`, { credentials: 'same-origin' });
        if (r.ok) facturasEncontradas.value = await r.json();
    }, 300);
};

const seleccionarFactura = (f) => {
    form.factura_id = f.factura_id;
    buscar.value = `${f.numero} · ${f.contacto} · saldo ${money(f.saldo)}`;
    facturasEncontradas.value = [];
};

const abrir = () => {
    modal.value = true;
    Object.assign(form, { factura_id: null, valor: 0, motivo: '' });
    buscar.value = '';
};

const guardar = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post('/app/cartera/notas-debito', form, {
        preserveScroll: true,
        onSuccess: () => { modal.value = false; },
        onFinish: () => { procesando.value = false; },
    });
};

const reintentando = ref(null);
const reenviar = (nd) => {
    modalConfirm.value = {
        titulo: `¿Reenviar ND ${nd.numero} a SIIGO?`,
        mensaje: 'Se encola un envío manual a SIIGO con esta nota débito.',
        color: 'sky',
        textoConfirmar: 'Reenviar',
        onConfirmar: () => {
            modalConfirm.value = null;
            reintentando.value = nd.id;
            router.post(`/app/cartera/notas-debito/${nd.id}/reenviar-siigo`, {}, {
                preserveScroll: true,
                onFinish: () => { reintentando.value = null; },
            });
        },
    };
};
</script>

<template>
    <Head title="Notas débito · manuales + SIIGO"/>
    <AppLayout>
        <div class="space-y-4 max-w-7xl">
            <PestanasSeccion :pestanas="pestanasNotas"/>

            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <FilePlus class="h-6 w-6 text-brand-600"/>
                        Notas débito
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Recargos por mora / ajuste al alza post-factura. Se envían a SIIGO como <code>POST /v1/debit-notes</code>.
                    </p>
                </div>
                <button @click="abrir" class="btn-primary text-sm inline-flex items-center gap-1">
                    <Plus class="h-4 w-4"/> Nueva ND
                </button>
            </div>

            <div v-if="$page.props.flash?.message" class="p-3 rounded-lg text-sm"
                 :class="$page.props.flash.type === 'error' ? 'bg-red-500/15 border-l-4 border-red-500 text-red-700' : 'bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700'">
                {{ $page.props.flash.message }}
            </div>

            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Total ND</div><div class="text-2xl font-bold mt-1">{{ kpis.total }}</div></div>
                <div class="card p-4" :class="kpis.sin_siigo > 0 ? 'ring-2 ring-amber-500' : ''"><div class="text-xs uppercase text-surface-500">Sin sync SIIGO</div><div class="text-2xl font-bold mt-1" :class="kpis.sin_siigo > 0 ? 'text-amber-600' : 'text-surface-400'">{{ kpis.sin_siigo }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Monto mes</div><div class="text-2xl font-bold mt-1 text-brand-600">{{ money(kpis.monto_mes) }}</div></div>
            </div>

            <div class="card p-3">
                <div class="flex gap-2 items-center flex-wrap">
                    <div class="relative flex-1 min-w-64">
                        <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-surface-400"/>
                        <input v-model="q" @input="filtrar" placeholder="Buscar por número ND o factura…" class="input pl-9 w-full text-sm"/>
                    </div>
                    <label class="flex items-center gap-1 text-xs whitespace-nowrap">
                        <input type="checkbox" v-model="sinSiigo" @change="filtrar" class="rounded"/> Solo sin SIIGO
                    </label>
                </div>
            </div>

            <div class="card overflow-hidden">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="text-left p-2">ND</th>
                            <th class="text-left p-2">Factura</th>
                            <th class="text-left p-2">Cliente</th>
                            <th class="text-right p-2">Valor</th>
                            <th class="text-left p-2">Motivo</th>
                            <th class="text-center p-2">SIIGO</th>
                            <th class="text-left p-2">Emitida</th>
                            <th class="text-right p-2 w-20">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="n in notas.data" :key="n.id" :class="['hover:bg-surface-50', clasesDe(n.id)]">
                            <td class="p-2 font-mono font-bold text-brand-600">{{ n.numero }}</td>
                            <td class="p-2 font-mono text-xs">{{ n.factura_numero }}</td>
                            <td class="p-2 text-xs">{{ n.cliente }}</td>
                            <td class="p-2 text-right font-mono font-bold">{{ money(n.valor) }}</td>
                            <td class="p-2 text-xs text-surface-500 max-w-xs truncate" :title="n.motivo">{{ n.motivo }}</td>
                            <td class="p-2 text-center">
                                <span :title="n.siigo_id ? `CUFE: ${n.cufe || ''}` : 'Pendiente'">
                                    <Cloud v-if="n.siigo_id" class="h-4 w-4 inline text-emerald-600"/>
                                    <CloudOff v-else class="h-4 w-4 inline text-amber-600"/>
                                </span>
                            </td>
                            <td class="p-2 text-xs text-surface-500">{{ n.emitida_at || n.creada_at }}</td>
                            <td class="p-2 text-right">
                                <button v-if="!n.siigo_id" @click="reenviar(n)" :disabled="reintentando === n.id"
                                        class="text-brand-600 hover:text-brand-700 p-1"
                                        :class="reintentando === n.id ? 'opacity-50 cursor-wait' : ''"
                                        title="Reenviar a SIIGO">
                                    <RefreshCw :class="['h-4 w-4', reintentando === n.id ? 'animate-spin' : '']"/>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!notas.data.length"><td colspan="8" class="p-6 text-center text-surface-500 text-sm">Sin notas débito.</td></tr>
                    </tbody>
                </table>
            </div>
            <Paginacion :pagina="notas" etiqueta="notas debito"/>

            <div v-if="modal" @click.self="modal = false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <div class="card p-5 w-full max-w-lg space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-bold">Nueva Nota Débito</h3>
                        <button @click="modal = false" class="text-surface-500 hover:text-surface-700"><X class="h-5 w-5"/></button>
                    </div>
                    <form @submit.prevent="guardar" class="space-y-3">
                        <div class="relative">
                            <label class="text-xs font-semibold">Factura de venta *</label>
                            <input v-model="buscar" @input="buscarFactura" required placeholder="Buscar por número o cliente…" class="input w-full text-sm"/>
                            <div v-if="facturasEncontradas.length" class="absolute z-10 left-0 right-0 mt-1 max-h-56 overflow-y-auto rounded border bg-white dark:bg-surface-900 shadow-lg">
                                <button v-for="f in facturasEncontradas" :key="f.factura_id" type="button" @click="seleccionarFactura(f)"
                                        class="w-full text-left px-3 py-2 hover:bg-surface-50 border-b text-sm">
                                    <div class="font-mono font-semibold">{{ f.numero }}</div>
                                    <div class="text-xs text-surface-500">{{ f.contacto }} · saldo {{ money(f.saldo) }}</div>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Valor ND *</label>
                            <input v-model="form.valor" type="number" step="0.01" min="0.01" required class="input w-full font-mono"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Motivo * (mínimo 10 caracteres)</label>
                            <textarea v-model="form.motivo" rows="3" required minlength="10" maxlength="500" class="input w-full" placeholder="Ej: Intereses por mora sobre saldo vencido a 60 días"></textarea>
                        </div>
                        <div class="text-xs text-surface-500 p-2 bg-surface-50 dark:bg-surface-900 rounded">
                            💡 Al guardar, se genera ND-#### y se encola a SIIGO como <code>debit-note</code> causa 1 (intereses/recargo).
                        </div>
                        <div class="flex justify-end gap-2 pt-2 border-t">
                            <button type="button" @click="modal = false" class="btn-ghost text-sm">Cancelar</button>
                            <button type="submit" :disabled="procesando || !form.factura_id" class="btn-primary text-sm">
                                {{ procesando ? 'Creando…' : 'Crear ND' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
