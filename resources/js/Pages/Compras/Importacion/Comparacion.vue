<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, FileSearch } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

/*
 * Lo pedido contra lo que dice el proveedor. No cambia la orden ni el inventario:
 * deja escrito qué cambió y por qué. Lo que manda al final es el conteo de bodega.
 */
const props = defineProps({
    importacion: { type: Object, required: true },
    documento: { type: Object, required: true },
    hoja: { type: String, default: '' },
    avisos: { type: Array, default: () => [] },
    resumen: { type: Object, required: true },
    filas: { type: Array, required: true },
});

const num = (n) => Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 2 });
const estados = {
    quitada: ['No viene en la lista', 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200'],
    nueva: ['No estaba en la orden', 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'],
    cambia: ['Cambió la cantidad', 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-200'],
    igual: ['Igual', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'],
};

const motivos = reactive(Object.fromEntries(props.filas.map((f) => [f.referencia, f.motivo || ''])));
const vinculos = reactive({});
const enviando = ref(false);
const base = computed(() => `/app/compras/importacion/${props.importacion.id}`);
const lineasDeOrdenSinLista = computed(() => props.filas.filter((f) => f.estado === 'quitada'));

const enviar = (url, datos) => {
    if (enviando.value) return;
    enviando.value = true;
    router.post(url, datos, { preserveScroll: true, onFinish: () => { enviando.value = false; } });
};
const guardarMotivo = (f) => enviar(`${base.value}/empaque/motivo`, { referencia: f.referencia, motivo: motivos[f.referencia] });
// Lo escrito y todavía sin guardar: lo que cambió y ya es un motivo válido.
const porGuardar = computed(() => props.filas.filter((f) => {
    const m = (motivos[f.referencia] || '').trim();
    return f.estado !== 'igual' && m.length >= 3 && m !== (f.motivo || '');
}));
const guardarTodos = () => enviar(`${base.value}/empaque/motivos`, {
    motivos: porGuardar.value.map((f) => ({ referencia: f.referencia, motivo: motivos[f.referencia].trim() })),
});
const vincular = (f) => enviar(`${base.value}/empaque/vincular`, { referencia: f.referencia, orden_item_id: vinculos[f.referencia] });
</script>

<template>
    <Head :title="`Comparar lista · ${importacion.numero}`"/>
    <AppLayout>
        <div class="max-w-6xl mx-auto space-y-4">
            <Link :href="base" class="btn-ghost inline-flex items-center gap-1 text-sm"><ArrowLeft class="h-4 w-4"/> Volver al contenedor</Link>
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2"><FileSearch class="h-6 w-6 text-brand-600"/> Lo pedido contra la lista</h1>
                <p class="text-sm text-surface-500 mt-1">{{ documento.tipo }} v{{ documento.version }}: {{ documento.nombre }}<template v-if="hoja"> · hoja «{{ hoja }}»</template>. No cambia la orden ni el inventario.</p>
            </div>

            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-sm">{{ $page.props.flash.error }}</div>
            <div v-for="(a, i) in avisos" :key="i" class="p-2 rounded bg-amber-50 dark:bg-amber-950/40 border-l-4 border-amber-500 text-xs text-amber-800 dark:text-amber-200">{{ a }}</div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Cajas pedidas</div><div class="text-xl font-bold mt-1">{{ num(resumen.cajas_pedidas) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Cajas en la lista</div><div class="text-xl font-bold mt-1">{{ num(resumen.cajas_lista) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Volumen y peso</div><div class="text-sm font-bold mt-1">{{ num(resumen.cbm_lista) }} m³ · {{ num(resumen.peso_lista) }} kg</div></div>
                <div class="card p-4" :class="resumen.sin_motivo > 0 ? 'ring-2 ring-amber-500' : ''"><div class="text-xs uppercase text-surface-500">Diferencias sin motivo</div><div class="text-xl font-bold mt-1">{{ resumen.sin_motivo }} de {{ resumen.diferencias }}</div></div>
            </div>

            <div class="flex justify-end">
                <button @click="guardarTodos" :disabled="!porGuardar.length || enviando" class="btn-primary text-sm disabled:opacity-50">
                    {{ enviando ? 'Guardando…' : `Guardar todos (${porGuardar.length})` }}
                </button>
            </div>

            <div class="card overflow-hidden overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="text-left p-2">Referencia</th>
                            <th class="text-left p-2">Estado</th>
                            <th class="text-right p-2">Pedido</th>
                            <th class="text-right p-2">Lista</th>
                            <th class="text-left p-2">Por qué</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                        <tr v-for="f in filas" :key="f.referencia">
                            <td class="p-2">
                                <span class="font-mono text-xs font-bold">{{ f.referencia }}</span>
                                <span v-if="f.como_llego && f.como_llego !== f.referencia" class="block text-xs text-surface-500">Llegó como {{ f.como_llego }}</span>
                                <span class="block text-xs text-surface-500">{{ f.descripcion }}</span>
                            </td>
                            <td class="p-2 text-xs"><span class="inline-block px-2 py-0.5 rounded font-semibold whitespace-nowrap" :class="estados[f.estado][1]">{{ estados[f.estado][0] }}</span></td>
                            <td class="p-2 text-right whitespace-nowrap">{{ num(f.cantidad_pedida) }}<span class="block text-xs text-surface-500">{{ num(f.cajas_pedidas) }} cajas</span></td>
                            <td class="p-2 text-right whitespace-nowrap">{{ num(f.cantidad_lista) }}<span class="block text-xs text-surface-500">{{ num(f.cajas_lista) }} cajas</span></td>
                            <td class="p-2 min-w-[16rem]">
                                <div v-if="f.estado !== 'igual'" class="space-y-1">
                                    <div class="flex gap-1">
                                        <input v-model="motivos[f.referencia]" maxlength="500" placeholder="¿Qué pasó?" class="input w-full text-xs" :aria-label="`Motivo de ${f.referencia}`"/>
                                        <button @click="guardarMotivo(f)" :disabled="(motivos[f.referencia] || '').trim().length < 3 || enviando" class="btn-ghost text-xs disabled:opacity-50">Guardar</button>
                                    </div>
                                    <div v-if="f.estado === 'nueva' && lineasDeOrdenSinLista.length" class="flex gap-1">
                                        <select v-model="vinculos[f.referencia]" class="input w-full text-xs" :aria-label="`Línea de la orden para ${f.referencia}`">
                                            <option :value="undefined">Es la misma referencia que…</option>
                                            <option v-for="l in lineasDeOrdenSinLista" :key="l.orden_item_id" :value="l.orden_item_id">{{ l.referencia }}</option>
                                        </select>
                                        <button @click="vincular(f)" :disabled="!vinculos[f.referencia] || enviando" class="btn-ghost text-xs disabled:opacity-50">Unir</button>
                                    </div>
                                </div>
                                <span v-else class="text-xs text-surface-400">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
