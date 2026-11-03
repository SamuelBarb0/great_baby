<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Wallet, Plus, Pencil, X, CalendarCheck } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasConfigContable } from '@/composables/usePestanasContables';

const props = defineProps({
    cajas: { type: Array, required: true },
    tipos: { type: Array, required: true },
    cuentas: { type: Array, required: true },
    personas: { type: Array, required: true },
    destinos: { type: Array, required: true },
    // Qué puede hacer QUIEN está mirando. Un botón que da 403 es peor que un
    // botón que no está.
    puedo: { type: Object, default: () => ({ crear: false, editar: false }) },
});

const modal = ref(false);
const procesando = ref(false);
const alternando = ref(null);

const enBlanco = () => ({
    id: null,
    codigo: '',
    nombre: '',
    tipo: 'general',
    responsable_id: null,
    cuenta_puc: '',
    saldo_inicial: 0,
    caja_destino_cierre_id: null,
    activa: true,
});

const form = reactive(enBlanco());

const abrir = (c = null) => {
    // Siempre desde cero: un formulario que hereda lo del anterior registra
    // la caja siguiente con el responsable y la cuenta de la pasada.
    Object.assign(form, enBlanco());
    if (c) {
        Object.assign(form, {
            id: c.id, codigo: c.codigo, nombre: c.nombre, tipo: c.tipo,
            responsable_id: c.responsable_id, cuenta_puc: c.cuenta_puc,
            saldo_inicial: c.saldo_inicial,
            caja_destino_cierre_id: c.destino_cierre_id, activa: c.activa,
        });
    }
    modal.value = true;
};

const guardar = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post('/app/contabilidad/cajas', form, {
        preserveScroll: true,
        onSuccess: () => { modal.value = false; },
        onFinish: () => { procesando.value = false; },
    });
};

const alternar = (c) => {
    if (alternando.value) return;
    alternando.value = c.id;
    router.post(`/app/contabilidad/cajas/${c.id}/activa`, {}, {
        preserveScroll: true,
        onFinish: () => { alternando.value = null; },
    });
};

/*
 * Una caja menor necesita contra qué cerrar, y el aviso sale mientras se
 * escribe el formulario, no después de guardar: el servidor valida lo mismo,
 * pero enterarse al final obliga a volver a llenar todo.
 */
const faltaDestino = computed(() => form.tipo === 'menor' && ! form.caja_destino_cierre_id);

const puedeGuardar = computed(() =>
    !! form.codigo.trim() && !! form.nombre.trim() && !! form.cuenta_puc && ! faltaDestino.value);

const money = (n) => '$' + Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });

const colorTipo = (t) => ({
    menor: 'bg-amber-500/20 text-amber-700',
    general: 'bg-blue-500/20 text-blue-700',
    banco: 'bg-emerald-500/20 text-emerald-700',
}[t] ?? 'bg-surface-500/20');

const activas = computed(() => props.cajas.filter(c => c.activa).length);
</script>

<template>
    <Head title="Cajas y bancos · Contabilidad"/>
    <AppLayout>
        <PestanasSeccion :items="pestanasConfigContable"/>

        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Wallet class="h-6 w-6 text-brand-600"/> Cajas y bancos
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Dónde está la plata y quién responde por ella. La <strong>caja menor</strong> es la del día: se
                    arquea contando billetes y al cerrar su saldo se traslada a la caja que elijas. La
                    <strong>general</strong> recibe esos cierres. El <strong>banco</strong> se concilia contra el
                    extracto, no se cuenta a mano.
                </p>
            </div>

            <div v-if="$page.props.flash?.message" class="p-3 rounded-lg text-sm"
                 :class="$page.props.flash.type === 'error'
                    ? 'bg-red-500/15 border-l-4 border-red-500 text-red-700'
                    : 'bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700'">
                {{ $page.props.flash.message }}
            </div>

            <div class="card overflow-hidden">
                <div class="p-3 border-b flex justify-between items-center">
                    <div class="text-xs uppercase font-bold text-brand-600">
                        Cajas
                        <span class="normal-case font-normal text-surface-500">
                            · {{ activas }} de {{ cajas.length }} activa{{ activas === 1 ? '' : 's' }}
                        </span>
                    </div>
                    <button v-if="puedo.crear" @click="abrir()" class="btn-primary text-xs inline-flex items-center gap-1">
                        <Plus class="h-3 w-3"/> Nueva caja
                    </button>
                    <span v-else class="text-[11px] text-surface-500">Sólo lectura</span>
                </div>

                <table class="w-full text-sm">
                    <thead class="bg-surface-50 dark:bg-surface-900 text-[11px] uppercase text-surface-500">
                        <tr>
                            <th class="p-2 text-left">Código</th>
                            <th class="p-2 text-left">Nombre</th>
                            <th class="p-2 text-left">Tipo</th>
                            <th class="p-2 text-left">Responsable</th>
                            <th class="p-2 text-left">Cuenta PUC</th>
                            <th class="p-2 text-right">Saldo inicial</th>
                            <th class="p-2 text-left">Cierra contra</th>
                            <th class="p-2 text-center">Activa</th>
                            <th class="p-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="c in cajas" :key="c.id" :class="c.activa ? '' : 'opacity-50'">
                            <td class="p-2 font-mono font-semibold">{{ c.codigo }}</td>
                            <td class="p-2">{{ c.nombre }}</td>
                            <td class="p-2">
                                <span class="text-[10px] px-2 py-0.5 rounded uppercase font-semibold" :class="colorTipo(c.tipo)">
                                    {{ c.tipo }}
                                </span>
                                <span v-if="c.se_arquea" class="ml-1 text-[10px] text-surface-500">se arquea</span>
                            </td>
                            <td class="p-2 text-xs">{{ c.responsable ?? '—' }}</td>
                            <td class="p-2 font-mono text-xs">{{ c.cuenta_puc }}</td>
                            <td class="p-2 text-right font-mono">{{ money(c.saldo_inicial) }}</td>
                            <td class="p-2 text-xs">{{ c.destino_cierre ?? '—' }}</td>
                            <td class="p-2 text-center">
                                <button type="button" @click="alternar(c)" :disabled="alternando === c.id || !puedo.editar"
                                        :aria-pressed="c.activa"
                                        :title="c.activa ? 'Está activa · clic para desactivarla' : 'Está inactiva · clic para activarla'"
                                        class="relative inline-flex h-5 w-9 items-center rounded-full transition disabled:opacity-50"
                                        :class="c.activa ? 'bg-emerald-500' : 'bg-surface-300'">
                                    <span class="inline-block h-4 w-4 transform rounded-full bg-white transition"
                                          :class="c.activa ? 'translate-x-4' : 'translate-x-1'"/>
                                </button>
                            </td>
                            <td class="p-2 text-right whitespace-nowrap">
                                <!-- Sólo la que se arquea tiene día que abrir: un banco se
                                     concilia contra el extracto, no se cuenta a mano. -->
                                <a v-if="c.se_arquea && c.activa" :href="`/app/contabilidad/cajas/${c.id}/dia`"
                                   class="text-emerald-600 hover:text-emerald-700 p-1 inline-flex" title="Abrir el día: ingresos, gastos y arqueo">
                                    <CalendarCheck class="h-4 w-4"/>
                                </a>
                                <button v-if="puedo.editar" @click="abrir(c)" class="text-brand-600 hover:text-brand-700 p-1" title="Editar">
                                    <Pencil class="h-4 w-4"/>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!cajas.length">
                            <td colspan="9" class="p-6 text-center text-surface-500 text-sm">
                                Todavía no hay cajas. Empezá por la <strong>caja general</strong> —que es donde llegan
                                los cierres— y después creá la <strong>caja menor</strong> apuntándola contra ella.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Alta y edición -->
        <div v-if="modal" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4"
             @click.self="modal = false">
            <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl max-w-xl w-full p-5 space-y-4">
                <div class="flex justify-between items-center">
                    <h2 class="font-bold text-lg">{{ form.id ? 'Editar caja' : 'Nueva caja' }}</h2>
                    <button @click="modal = false" class="text-surface-400 hover:text-surface-600"><X class="h-5 w-5"/></button>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-surface-600 mb-1">Código *</label>
                        <input v-model="form.codigo" maxlength="20" class="input w-full font-mono" placeholder="CM-01"/>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-surface-600 mb-1">Nombre *</label>
                        <input v-model="form.nombre" maxlength="100" class="input w-full" placeholder="Caja menor Bogotá"/>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-surface-600 mb-1">Tipo *</label>
                    <select v-model="form.tipo" class="input w-full">
                        <option v-for="t in tipos" :key="t.valor" :value="t.valor">{{ t.texto }}</option>
                    </select>
                </div>

                <div v-if="form.tipo === 'menor'">
                    <label class="block text-xs font-bold text-surface-600 mb-1">Al cerrar, su saldo se va a *</label>
                    <select v-model="form.caja_destino_cierre_id" class="input w-full">
                        <option :value="null">Elegí la caja destino…</option>
                        <option v-for="d in destinos" :key="d.id" :value="d.id">{{ d.nombre }}</option>
                    </select>
                    <p v-if="faltaDestino" class="text-xs text-amber-600 mt-1">
                        Una caja menor se cierra contra otra caja. Si todavía no existe, creá primero la general.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-surface-600 mb-1">Responsable</label>
                        <select v-model="form.responsable_id" class="input w-full">
                            <option :value="null">Sin asignar</option>
                            <option v-for="p in personas" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-surface-600 mb-1">Saldo inicial</label>
                        <input v-model="form.saldo_inicial" type="number" min="0" step="1" class="input w-full font-mono"/>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-surface-600 mb-1">Cuenta PUC *</label>
                    <input v-model="form.cuenta_puc" list="cuentas-caja" class="input w-full font-mono" placeholder="11050501"/>
                    <datalist id="cuentas-caja">
                        <option v-for="c in cuentas" :key="c.codigo" :value="c.codigo">{{ c.nombre }}</option>
                    </datalist>
                    <p class="text-xs text-surface-500 mt-1">
                        Sólo cuentas que admiten movimiento: una agrupadora arma asientos que SIIGO devuelve rechazados.
                    </p>
                </div>

                <div class="flex justify-end items-center gap-2 pt-2 border-t">
                    <p v-if="faltaDestino" class="text-xs text-amber-600 mr-auto">Falta la caja destino del cierre.</p>
                    <button @click="modal = false" class="btn-ghost text-sm">Cancelar</button>
                    <button @click="guardar" :disabled="procesando || !puedeGuardar" class="btn-primary text-sm">
                        {{ procesando ? 'Guardando…' : 'Guardar' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
