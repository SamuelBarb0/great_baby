<script setup>
/*
 * Comisiones de vendedores: el cierre de cada mes y, para gerencia, cuánto
 * cobra cada uno. Vivía sólo en el panel Filament.
 */
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useMoney } from '@/composables/useMoney';
import { Calculator, Plus, X, Trash2, Pencil, CheckCircle, Wallet } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasConfigContable } from '@/composables/usePestanasContables';
const props = defineProps({
    periodo: { type: Object, required: true },
    anios: { type: Array, required: true },
    meses: { type: Array, required: true },
    calculadas: { type: Array, required: true },
    total_periodo: { type: Number, default: 0 },
    puede_configurar: { type: Boolean, default: false },
    configs: { type: Array, default: () => [] },
    vendedores: { type: Array, default: () => [] },
});

const { money: fmtCOP } = useMoney();

const anio = ref(props.periodo.anio);
const mes = ref(props.periodo.mes);
const calculando = ref(false);

const cambiarPeriodo = () => {
    router.get('/app/cartera/comisiones', { anio: anio.value, mes: mes.value },
        { preserveState: true, preserveScroll: true, replace: true });
};

const calcular = () => {
    if (calculando.value) return;
    calculando.value = true;
    router.post('/app/cartera/comisiones/calcular', { anio: anio.value, mes: mes.value },
        { preserveScroll: true, onFinish: () => (calculando.value = false) });
};

const cambiarEstado = (c, estado) => {
    router.post(`/app/cartera/comisiones/${c.id}/estado`, { estado }, { preserveScroll: true });
};

const tonoEstado = (e) => ({
    borrador: 'bg-surface-200 text-surface-700 dark:bg-surface-800 dark:text-surface-300',
    aprobado: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    pagado: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
}[e] || 'bg-surface-200 text-surface-700');

/* ---- Configuración (sólo gerencia) ---- */
const vacio = () => ({
    id: null, vendedor_id: '', porcentaje_base: 0, cobra_solo_cobrado: true,
    meta_mensual: 0, bono_por_meta_pct: 0, activo: true, notas: '',
});
const cfg = ref(vacio());
const cfgAbierto = ref(false);
const guardandoCfg = ref(false);
const editandoCfg = computed(() => !! cfg.value.id);

const abrirCfg = (c = null) => { cfg.value = c ? { ...c } : vacio(); cfgAbierto.value = true; };

const guardarCfg = () => {
    if (guardandoCfg.value || ! cfg.value.vendedor_id) return;
    guardandoCfg.value = true;
    const url = editandoCfg.value
        ? `/app/cartera/comisiones/config/${cfg.value.id}`
        : '/app/cartera/comisiones/config';
    router.post(url, { ...cfg.value }, {
        preserveScroll: true,
        onSuccess: () => { cfgAbierto.value = false; cfg.value = vacio(); },
        onFinish: () => { guardandoCfg.value = false; },
    });
};

const eliminarCfg = (c) => router.delete(`/app/cartera/comisiones/config/${c.id}`, { preserveScroll: true });
</script>

<template>
    <Head title="Comisiones"/>
    <AppLayout>
        <div class="space-y-4 max-w-6xl">
            <PestanasSeccion :pestanas="pestanasConfigContable"/>

            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Calculator class="h-6 w-6 text-brand-600"/> Comisiones de vendedores
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Liquidación de cada mes. Lo que ya está aprobado o pagado no se vuelve a calcular.
                </p>
            </div>

            <!-- Periodo + calcular -->
            <div class="card p-4 flex items-end gap-3 flex-wrap">
                <div>
                    <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Mes</label>
                    <select v-model.number="mes" @change="cambiarPeriodo" class="input min-h-11">
                        <option v-for="m in meses" :key="m.valor" :value="m.valor">{{ m.label }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Año</label>
                    <select v-model.number="anio" @change="cambiarPeriodo" class="input min-h-11">
                        <option v-for="a in anios" :key="a" :value="a">{{ a }}</option>
                    </select>
                </div>
                <button @click="calcular" :disabled="calculando" class="btn-primary min-h-11 disabled:opacity-40">
                    <Calculator class="h-4 w-4"/> {{ calculando ? 'Calculando…' : 'Calcular comisiones del mes' }}
                </button>
                <div class="ml-auto text-right">
                    <div class="text-xs uppercase text-surface-500">Total a pagar · {{ periodo.label }}</div>
                    <div class="text-2xl font-bold text-emerald-600">{{ fmtCOP(total_periodo) }}</div>
                </div>
            </div>

            <!-- Liquidaciones del mes -->
            <div class="card overflow-hidden">
                <div v-if="! calculadas.length" class="p-10 text-center text-surface-500">
                    No hay comisiones calculadas para {{ periodo.label }}.
                    <div class="text-xs mt-1">Usá «Calcular comisiones del mes» para generarlas.</div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900 border-b border-surface-200 dark:border-surface-800">
                            <tr>
                                <th class="p-3 text-left">Vendedor</th>
                                <th class="p-3 text-right">Facturado</th>
                                <th class="p-3 text-right">Cobrado</th>
                                <th class="p-3 text-right">Base</th>
                                <th class="p-3 text-right">%</th>
                                <th class="p-3 text-right">Comisión</th>
                                <th class="p-3 text-right">Bono</th>
                                <th class="p-3 text-right">Total</th>
                                <th class="p-3 text-center">Estado</th>
                                <th class="p-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-for="c in calculadas" :key="c.id" class="hover:bg-brand-50/40 dark:hover:bg-brand-950/20">
                                <td class="p-3">
                                    <div class="font-semibold">{{ c.vendedor }}</div>
                                    <div class="text-xs text-surface-500">{{ c.facturas }} factura(s)</div>
                                </td>
                                <td class="p-3 text-right">{{ fmtCOP(c.total_facturado) }}</td>
                                <td class="p-3 text-right">{{ fmtCOP(c.total_cobrado) }}</td>
                                <td class="p-3 text-right">{{ fmtCOP(c.base) }}</td>
                                <td class="p-3 text-right">{{ c.porcentaje }}%</td>
                                <td class="p-3 text-right">{{ fmtCOP(c.comision) }}</td>
                                <td class="p-3 text-right">{{ fmtCOP(c.bono) }}</td>
                                <td class="p-3 text-right font-bold text-emerald-600">{{ fmtCOP(c.total) }}</td>
                                <td class="p-3 text-center">
                                    <span :class="['text-xs font-bold px-2 py-0.5 rounded', tonoEstado(c.estado)]">
                                        {{ c.estado }}
                                    </span>
                                </td>
                                <td class="p-3 text-right whitespace-nowrap">
                                    <button v-if="c.estado === 'borrador'" @click="cambiarEstado(c, 'aprobado')"
                                            class="btn-ghost p-2 min-h-11 text-blue-600" title="Aprobar">
                                        <CheckCircle class="h-4 w-4"/>
                                    </button>
                                    <button v-if="c.estado === 'aprobado'" @click="cambiarEstado(c, 'pagado')"
                                            class="btn-ghost p-2 min-h-11 text-emerald-600" title="Marcar pagada">
                                        <Wallet class="h-4 w-4"/>
                                    </button>
                                    <span v-if="c.estado === 'pagado'" class="text-xs text-surface-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Configuración · sólo gerencia -->
            <template v-if="puede_configurar">
                <div class="flex items-center justify-between gap-2 pt-2">
                    <h2 class="font-bold">Cuánto cobra cada vendedor</h2>
                    <button @click="abrirCfg()" class="btn-primary text-sm min-h-11">
                        <Plus class="h-4 w-4"/> Configurar vendedor
                    </button>
                </div>

                <div v-if="cfgAbierto" class="card p-4 border-2 border-brand-300 dark:border-brand-800 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="font-semibold">{{ editandoCfg ? 'Editar configuración' : 'Nueva configuración' }}</h3>
                        <button @click="cfgAbierto = false" class="btn-ghost p-2 min-h-11" aria-label="Cerrar">
                            <X class="h-4 w-4"/>
                        </button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Vendedor *</label>
                            <select v-model="cfg.vendedor_id" class="input w-full min-h-11" :disabled="editandoCfg">
                                <option value="">— Elegí —</option>
                                <option v-for="v in vendedores" :key="v.id" :value="v.id">{{ v.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">% de comisión</label>
                            <input v-model.number="cfg.porcentaje_base" type="number" step="0.01" min="0" max="100"
                                   class="input w-full min-h-11 text-right"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Meta mensual</label>
                            <input v-model.number="cfg.meta_mensual" type="number" step="1000" min="0"
                                   class="input w-full min-h-11 text-right"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Bono si cumple la meta (%)</label>
                            <input v-model.number="cfg.bono_por_meta_pct" type="number" step="0.01" min="0" max="100"
                                   class="input w-full min-h-11 text-right"/>
                        </div>
                        <div class="md:col-span-2">
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Notas</label>
                            <input v-model="cfg.notas" class="input w-full min-h-11" maxlength="500"/>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer">
                            <input type="checkbox" v-model="cfg.cobra_solo_cobrado" class="h-4 w-4"/>
                            <span class="text-sm">Comisiona sólo sobre lo efectivamente cobrado</span>
                        </label>
                        <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer">
                            <input type="checkbox" v-model="cfg.activo" class="h-4 w-4"/>
                            <span class="text-sm">Activo</span>
                        </label>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button @click="cfgAbierto = false" class="btn-ghost min-h-11">Cancelar</button>
                        <button @click="guardarCfg" :disabled="guardandoCfg || ! cfg.vendedor_id"
                                class="btn-primary min-h-11 disabled:opacity-40">
                            {{ guardandoCfg ? 'Guardando…' : 'Guardar' }}
                        </button>
                    </div>
                </div>

                <div class="card overflow-hidden">
                    <div v-if="! configs.length" class="p-8 text-center text-surface-500">
                        Ningún vendedor tiene comisión configurada todavía: sin esto el cálculo del mes no los incluye.
                    </div>
                    <table v-tabla-movil v-else class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900 border-b border-surface-200 dark:border-surface-800">
                            <tr>
                                <th class="p-3 text-left">Vendedor</th>
                                <th class="p-3 text-right">%</th>
                                <th class="p-3 text-left">Base</th>
                                <th class="p-3 text-right">Meta</th>
                                <th class="p-3 text-right">Bono</th>
                                <th class="p-3 text-center">Estado</th>
                                <th class="p-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-for="c in configs" :key="c.id">
                                <td class="p-3 font-semibold">{{ c.vendedor }}</td>
                                <td class="p-3 text-right">{{ c.porcentaje_base }}%</td>
                                <td class="p-3 text-xs">{{ c.cobra_solo_cobrado ? 'Sólo lo cobrado' : 'Todo lo facturado' }}</td>
                                <td class="p-3 text-right">{{ fmtCOP(c.meta_mensual) }}</td>
                                <td class="p-3 text-right">{{ c.bono_por_meta_pct }}%</td>
                                <td class="p-3 text-center">
                                    <span :class="['text-xs font-bold px-2 py-0.5 rounded',
                                                   c.activo ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                                            : 'bg-surface-200 text-surface-600 dark:bg-surface-800 dark:text-surface-300']">
                                        {{ c.activo ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="p-3 text-right whitespace-nowrap">
                                    <button @click="abrirCfg(c)" class="btn-ghost p-2 min-h-11" title="Editar">
                                        <Pencil class="h-4 w-4"/>
                                    </button>
                                    <button @click="eliminarCfg(c)" class="btn-ghost p-2 min-h-11 text-red-600" title="Eliminar">
                                        <Trash2 class="h-4 w-4"/>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
