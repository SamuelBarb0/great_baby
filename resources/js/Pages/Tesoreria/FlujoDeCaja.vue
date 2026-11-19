<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { TrendingUp, Lock } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    tipo: { type: String, required: true },
    desde: { type: String, required: true },
    periodos: { type: Array, required: true },
    grupos: { type: Array, required: true },
    resumen: { type: Object, required: true },
    misPendientes: { type: Array, default: () => [] },
    puedoTodo: { type: Boolean, default: false },
    misConceptos: { type: Array, default: () => [] },
});

const money = (n) => {
    const v = Number(n || 0);
    if (! v) return '—';
    // En una grilla de ocho columnas el número completo no cabe y la hoja se
    // vuelve ilegible. Los miles son la unidad con la que hablan de esto.
    return '$' + Math.round(v / 1000).toLocaleString('es-CO') + 'k';
};

const puedoEditar = (c) => props.puedoTodo || props.misConceptos.includes(c.id);

const cambiarVista = (tipo) =>
    router.get('/app/tesoreria/flujo-de-caja', { tipo, desde: props.desde }, { preserveState: false });

/* ──────────── Capturar una celda ──────────── */

const editando = ref(null);   // { conceptoId, periodo }
const valor = ref(null);
const guardando = ref(false);

const abrir = (c, celda) => {
    if (! puedoEditar(c)) return;
    editando.value = { conceptoId: c.id, periodo: celda.periodo, nombre: c.nombre };
    valor.value = celda.proyectado || null;
};

const guardar = () => {
    if (guardando.value) return;
    guardando.value = true;
    router.post('/app/tesoreria/flujo-de-caja/proyeccion', {
        concepto_id: editando.value.conceptoId,
        periodo_tipo: props.tipo,
        periodo: editando.value.periodo,
        monto: valor.value ?? 0,
    }, {
        preserveScroll: true,
        onSuccess: () => { editando.value = null; valor.value = null; },
        onFinish: () => { guardando.value = false; },
    });
};

/*
 * El color de la desviación.
 *
 * Verde no es «cumplió la meta»: en los egresos gastar menos de lo
 * proyectado es bueno y en los ingresos es malo. El signo lo decide el grupo.
 */
const tonoCelda = (grupo, celda) => {
    if (! celda.real && ! celda.proyectado) return '';
    const dif = celda.real - celda.proyectado;
    if (Math.abs(dif) < 1) return '';
    const esEgreso = grupo.startsWith('egreso') || grupo.startsWith('otro_egreso');
    const bueno = esEgreso ? dif < 0 : dif > 0;
    return bueno ? 'text-emerald-600' : 'text-rose-600';
};

const totalGrupo = (g, i, campo) =>
    g.conceptos.reduce((s, c) => s + (c.celdas[i]?.[campo] ?? 0), 0);
</script>

<template>
    <Head title="Flujo de caja"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between flex-wrap gap-2">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <TrendingUp class="h-6 w-6 text-brand-600"/> Flujo de caja
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Cada área proyecta sus renglones. La columna <strong>Real</strong> la calcula el ERP: recaudo,
                        pagos a proveedor, nómina y gastos salen solos.
                    </p>
                </div>
                <div class="card p-1 flex gap-1">
                    <button v-for="t in [['semana','Semanal'],['mes','Mensual']]" :key="t[0]"
                            @click="cambiarVista(t[0])"
                            :class="['px-4 py-2 rounded text-sm font-semibold',
                                     tipo === t[0] ? 'bg-brand-600 text-white' : 'text-surface-600 hover:bg-surface-100']">
                        {{ t[1] }}
                    </button>
                </div>
            </div>

            <div v-if="$page.props.flash?.message" class="p-3 rounded-lg text-sm bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700">
                {{ $page.props.flash.message }}
            </div>

            <!-- Lo que ESTA persona debe informar -->
            <div v-if="misPendientes.length" class="p-3 rounded-lg bg-amber-500/15 border-l-4 border-amber-500 text-sm">
                <strong>Te faltan {{ misPendientes.length }} renglón(es) por informar.</strong>
                <span class="text-surface-600">
                    {{ misPendientes.slice(0, 4).map(p => `${p.concepto} (${p.periodo})`).join(' · ') }}
                    <template v-if="misPendientes.length > 4">…</template>
                </span>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div class="card p-3">
                    <div class="text-[11px] uppercase text-surface-500">Proyectado</div>
                    <div class="text-xl font-bold font-mono">{{ money(resumen.proyectado) }}</div>
                </div>
                <div class="card p-3">
                    <div class="text-[11px] uppercase text-surface-500">Real</div>
                    <div class="text-xl font-bold font-mono">{{ money(resumen.real) }}</div>
                </div>
                <div class="card p-3">
                    <div class="text-[11px] uppercase text-surface-500">Desviación</div>
                    <div class="text-xl font-bold font-mono"
                         :class="resumen.desviacion >= 0 ? 'text-emerald-600' : 'text-rose-600'">
                        {{ money(resumen.desviacion) }}
                    </div>
                </div>
            </div>

            <!-- La grilla -->
            <div class="card overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-surface-50 dark:bg-surface-900">
                        <tr class="text-[11px] uppercase text-surface-500">
                            <th class="p-2 text-left sticky left-0 bg-surface-50 dark:bg-surface-900 min-w-56">Concepto</th>
                            <th v-for="p in periodos" :key="p.inicio" colspan="2" class="p-2 text-center border-l">
                                {{ p.etiqueta }}
                            </th>
                        </tr>
                        <tr class="text-[10px] uppercase text-surface-400">
                            <th class="p-1 sticky left-0 bg-surface-50 dark:bg-surface-900"></th>
                            <template v-for="p in periodos" :key="`h${p.inicio}`">
                                <th class="p-1 text-right border-l">Proy.</th>
                                <th class="p-1 text-right">Real</th>
                            </template>
                        </tr>
                    </thead>

                    <tbody v-for="g in grupos" :key="g.clave" class="divide-y">
                        <tr class="bg-surface-100/60 dark:bg-surface-800">
                            <td class="p-2 font-bold uppercase text-[11px] text-brand-700 sticky left-0 bg-surface-100/60 dark:bg-surface-800">
                                {{ g.etiqueta }}
                            </td>
                            <template v-for="(p, i) in periodos" :key="`t${g.clave}${p.inicio}`">
                                <td class="p-1 text-right font-mono font-bold border-l">{{ money(totalGrupo(g, i, 'proyectado')) }}</td>
                                <td class="p-1 text-right font-mono font-bold">{{ money(totalGrupo(g, i, 'real')) }}</td>
                            </template>
                        </tr>

                        <tr v-for="c in g.conceptos" :key="c.id" class="hover:bg-surface-50 dark:hover:bg-surface-800/50">
                            <td class="p-2 sticky left-0 bg-white dark:bg-surface-900">
                                {{ c.nombre }}
                                <span class="text-[10px] px-1 rounded ml-1"
                                      :class="c.tipo === 'F' ? 'bg-blue-500/20 text-blue-700' : 'bg-surface-500/20 text-surface-600'">
                                    {{ c.tipo }}
                                </span>
                                <span v-if="c.calculado" class="text-[10px] text-emerald-600 ml-1" title="El real lo calcula el ERP">
                                    auto
                                </span>
                                <span class="block text-[10px] text-surface-500">
                                    {{ c.responsable ?? 'sin responsable' }}
                                </span>
                            </td>

                            <template v-for="celda in c.celdas" :key="`${c.id}${celda.periodo}`">
                                <td class="p-1 text-right font-mono border-l"
                                    :class="puedoEditar(c) ? 'cursor-pointer hover:bg-brand-500/10' : ''"
                                    @click="abrir(c, celda)">
                                    {{ money(celda.proyectado) }}
                                </td>
                                <td class="p-1 text-right font-mono" :class="tonoCelda(g.clave, celda)">
                                    {{ money(celda.real) }}
                                    <Lock v-if="c.calculado" class="h-2.5 w-2.5 inline text-surface-300"/>
                                </td>
                            </template>
                        </tr>

                        <tr v-if="!g.conceptos.length">
                            <td :colspan="1 + periodos.length * 2" class="p-3 text-center text-surface-400 text-[11px]">
                                Sin renglones en este grupo.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-surface-500">
                Las cifras van en miles. Hacé clic en una celda de <strong>Proy.</strong> para cargar tu proyección;
                las de <strong>Real</strong> marcadas con candado las calcula el ERP y no se teclean.
            </p>
        </div>

        <!-- Capturar -->
        <div v-if="editando" @click.self="editando = null"
             class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl max-w-sm w-full p-5 space-y-3">
                <h3 class="font-bold text-lg">{{ editando.nombre }}</h3>
                <p class="text-xs text-surface-500">Período que arranca el {{ editando.periodo }}</p>
                <div>
                    <label class="block text-xs font-bold text-surface-600 mb-1">Proyección</label>
                    <input v-model="valor" type="number" min="0" class="input w-full font-mono" autofocus/>
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <button @click="editando = null" class="btn-ghost text-sm">Cancelar</button>
                    <button @click="guardar" :disabled="guardando" class="btn-primary text-sm">
                        {{ guardando ? 'Guardando…' : 'Guardar' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
