<script setup>
import { ref, reactive, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { MessageSquare, Plus } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasCobranza } from '@/composables/usePestanasContables';
import { useEscClose } from '@/composables/useEscClose';

const props = defineProps({ cobros: { type: Object, required: true } });

const modal = ref(false);
useEscClose(modal);

const form = reactive({ factura_id: '', canal: 'whatsapp', tramo: '30d', mensaje: '' });
const procesando = ref(false);

// H1 · autocomplete real de facturas — el backend ya devuelve varios matches
// vía /app/api/contactos/buscar?scope=facturas. Muestra dropdown con nombre,
// número, saldo, vencimiento. Aracely elige del listado; nunca escribe IDs.
const buscar = ref('');
const buscando = ref(false);
const resultados = ref([]);
const facturaSeleccionada = ref(null);
let debHandle;
watch(buscar, (v) => {
    clearTimeout(debHandle);
    if (facturaSeleccionada.value && v !== labelFactura(facturaSeleccionada.value)) {
        facturaSeleccionada.value = null;
        form.factura_id = '';
    }
    if (!v || v.trim().length < 2) { resultados.value = []; return; }
    debHandle = setTimeout(async () => {
        buscando.value = true;
        try {
            const res = await fetch('/app/api/contactos/buscar?q=' + encodeURIComponent(v.trim()) + '&scope=facturas', {
                headers: { Accept: 'application/json' }, credentials: 'same-origin',
            });
            if (res.ok) {
                const data = await res.json();
                resultados.value = Array.isArray(data) ? data : [];
            }
        } catch { resultados.value = []; }
        finally { buscando.value = false; }
    }, 300);
});
const labelFactura = (f) => `${f.numero} · ${f.contacto}`;
const seleccionar = (f) => {
    facturaSeleccionada.value = f;
    form.factura_id = f.factura_id;
    buscar.value = labelFactura(f);
    resultados.value = [];
};
const fmtDinero = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);

const crear = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post('/app/cartera/cobranzas', form, {
        preserveScroll: true,
        onSuccess: () => { modal.value = false; Object.assign(form, { factura_id: '', mensaje: '' }); buscar.value = ''; facturaSeleccionada.value = null; resultados.value = []; },
        onError: () => { window.dispatchEvent(new CustomEvent('gb:error', { detail: { msg: 'Verifica los datos del registro.' } })); },
        onFinish: () => procesando.value = false,
    });
};

// Re-audit UX #14 · dark mode para badges canal.
const badgeCanal = (c) => ({
    whatsapp: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    email: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    llamada: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    visita: 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200',
    sms: 'bg-brand-100 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200',
}[c] || 'bg-surface-100 dark:bg-surface-800 dark:text-surface-300');
</script>

<template>
    <Head title="Registros de cobranza"/>
    <AppLayout>
        <div class="space-y-4">
            <PestanasSeccion :pestanas="pestanasCobranza"/>

            <div class="flex items-start justify-between">
                <h1 class="text-2xl font-bold flex items-center gap-2"><MessageSquare class="h-6 w-6 text-brand-600"/>Registros de cobranza</h1>
                <button @click="modal = true" class="btn-primary"><Plus class="h-4 w-4"/> Nuevo</button>
            </div>

            <!-- Re-audit UX #16 · flash success + error -->
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
                            <th class="text-left p-3">Factura</th>
                            <th class="text-center p-3">Canal</th>
                            <th class="text-left p-3">Tramo</th>
                            <th class="text-left p-3">Estado</th>
                            <th class="text-left p-3">Mensaje</th>
                            <th class="text-left p-3">Gestor</th>
                            <th class="text-left p-3">Enviado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                        <tr v-for="c in cobros.data" :key="c.id" class="hover:bg-surface-50 dark:hover:bg-surface-800/50">
                            <td class="p-3 font-mono text-xs">{{ c.factura }}</td>
                            <td class="p-3 text-center"><span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badgeCanal(c.canal)]">{{ c.canal }}</span></td>
                            <td class="p-3 text-xs">{{ c.tramo }}</td>
                            <td class="p-3 text-xs">{{ c.estado }}</td>
                            <td class="p-3 text-xs max-w-md truncate" :title="c.mensaje">{{ c.mensaje }}</td>
                            <td class="p-3 text-xs">{{ c.gestor }}</td>
                            <td class="p-3 text-xs">{{ c.enviado_at }}</td>
                        </tr>
                        <tr v-if="!cobros.data.length"><td colspan="7" class="p-6 text-center text-surface-500 text-sm">Sin registros de cobranza.</td></tr>
                    </tbody>
                </table>
            </div>
            <Paginacion :pagina="cobros" etiqueta="gestiones"/>
        </div>

        <div v-if="modal" @click.self="modal = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full">
                <h3 class="text-lg font-bold mb-3">Registrar cobranza</h3>
                <div class="space-y-3">
                    <div class="relative">
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Buscar factura (número o cliente)</label>
                        <input v-model="buscar" class="input w-full" placeholder="Ej: FV-2609-0001 o Distribuidora El Sol" autocomplete="off"/>
                        <div v-if="buscando" class="text-[11px] text-surface-500 mt-1">Buscando…</div>
                        <div v-if="resultados.length" class="absolute z-10 left-0 right-0 mt-1 max-h-64 overflow-y-auto rounded-lg border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900 shadow-lg">
                            <button v-for="f in resultados" :key="f.factura_id" type="button" @click="seleccionar(f)"
                                    class="w-full text-left px-3 py-2 hover:bg-surface-50 dark:hover:bg-surface-800 border-b border-surface-100 dark:border-surface-800 last:border-b-0">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="font-mono font-semibold">{{ f.numero }}</span>
                                    <span class="text-[11px] text-surface-500">vence {{ f.vence || '—' }}</span>
                                </div>
                                <div class="flex items-center justify-between text-xs mt-0.5">
                                    <span class="truncate text-surface-700 dark:text-surface-300">{{ f.contacto }}</span>
                                    <span class="text-amber-600 dark:text-amber-400 font-semibold whitespace-nowrap ml-2">saldo {{ fmtDinero(f.saldo) }}</span>
                                </div>
                            </button>
                        </div>
                        <div v-if="facturaSeleccionada" class="text-[11px] mt-1 text-emerald-600 dark:text-emerald-400">
                            ✓ {{ labelFactura(facturaSeleccionada) }} · saldo {{ fmtDinero(facturaSeleccionada.saldo) }}
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Canal</label>
                            <select v-model="form.canal" class="input w-full">
                                <option value="whatsapp">WhatsApp</option><option value="email">Email</option>
                                <option value="llamada">Llamada</option><option value="visita">Visita</option><option value="sms">SMS</option>
                            </select>
                        </div>
                        <div><label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Tramo</label>
                            <select v-model="form.tramo" class="input w-full">
                                <option value="0d">Recordatorio</option><option value="30d">30 días</option>
                                <option value="60d">60 días</option><option value="90d">90 días</option><option value="120+">120+ días</option>
                            </select>
                        </div>
                    </div>
                    <div><label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Mensaje</label><textarea v-model="form.mensaje" rows="4" class="input w-full"></textarea></div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modal = false" class="btn-ghost">Cancelar</button>
                    <button @click="crear" :disabled="procesando || !form.factura_id" class="btn-primary disabled:opacity-50">{{ procesando ? '…' : 'Registrar' }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
