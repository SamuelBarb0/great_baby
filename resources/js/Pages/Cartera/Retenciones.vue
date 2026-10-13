<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Receipt, Plus, Pencil, Trash2, X } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';

const props = defineProps({ reglas: { type: Array, required: true } });

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

const tab = ref('retefuente');
const modal = ref(false);
const form = reactive({ id: null, tipo: 'retefuente', concepto: '', ciudad: '', base_minima: 0, tarifa_pct: 0, cuenta_puc: '', siigo_tax_id: null, activa: true, notas: '' });
const procesando = ref(false);

const filtradas = computed(() => props.reglas.filter(r => r.tipo === tab.value));

const abrir = (regla = null) => {
    if (regla) Object.assign(form, regla);
    else Object.assign(form, { id: null, tipo: tab.value, concepto: '', ciudad: '', base_minima: 0, tarifa_pct: 0, cuenta_puc: '', siigo_tax_id: null, activa: true, notas: '' });
    modal.value = true;
};

const guardar = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post('/app/cartera/retenciones', form, {
        preserveScroll: true,
        onSuccess: () => { modal.value = false; },
        onFinish: () => { procesando.value = false; },
    });
};

const eliminar = (id) => {
    modalConfirm.value = {
        titulo: '¿Eliminar esta regla de retención?',
        mensaje: 'No se puede deshacer. Los documentos ya emitidos conservan la retención que se les aplicó.',
        color: 'rose',
        textoConfirmar: 'Eliminar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.delete(`/app/cartera/retenciones/${id}`, { preserveScroll: true });
        },
    };
};

const tabs = [
    { key: 'retefuente', label: `Retefuente (${props.reglas.filter(r => r.tipo === 'retefuente').length})` },
    { key: 'reteica', label: `Reteica (${props.reglas.filter(r => r.tipo === 'reteica').length})` },
    { key: 'reteiva', label: `Reteiva (${props.reglas.filter(r => r.tipo === 'reteiva').length})` },
];
</script>

<template>
    <Head title="Retenciones · configuración"/>
    <AppLayout>
        <div class="space-y-4 max-w-6xl">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Receipt class="h-6 w-6 text-brand-600"/>
                    Retenciones tributarias · Configuración
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Reglas para calcular automático Retefuente/Reteica/Reteiva al pagar facturas de compra.
                    Se aplican al confirmar recepción · quedan en <code>retenciones_aplicadas</code> y viajan a SIIGO como parte del voucher de egreso.
                </p>
                <p class="text-sm text-surface-500 mt-1">
                    En <strong>ventas</strong> se usan las mismas tarifas para lo que el cliente nos retiene (concepto
                    <code>compras_generales</code>, cuentas 1355 de Reglas → Contable), sólo para clientes marcados en su ficha.
                    Para facturas electrónicas la regla necesita su «ID impuesto SIIGO».
                </p>
            </div>

            <div v-if="$page.props.flash?.message" class="p-3 rounded-lg text-sm"
                 :class="$page.props.flash.type === 'error' ? 'bg-red-500/15 border-l-4 border-red-500 text-red-700' : 'bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700'">
                {{ $page.props.flash.message }}
            </div>

            <div class="card p-1 flex gap-1 flex-wrap">
                <button v-for="t in tabs" :key="t.key" @click="tab = t.key"
                        :class="['px-4 py-2 rounded text-sm font-semibold', tab === t.key ? 'bg-brand-600 text-white' : 'text-surface-600 hover:bg-surface-100']">
                    {{ t.label }}
                </button>
            </div>

            <div class="card overflow-hidden">
                <div class="p-3 border-b flex justify-between items-center">
                    <div class="text-xs uppercase font-bold text-brand-600">Reglas · {{ tab }}</div>
                    <button @click="abrir()" class="btn-primary text-xs inline-flex items-center gap-1">
                        <Plus class="h-3 w-3"/> Nueva regla
                    </button>
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="text-left p-2">Concepto</th>
                            <th v-if="tab === 'reteica'" class="text-left p-2">Ciudad</th>
                            <th class="text-right p-2">Base mín.</th>
                            <th class="text-right p-2">Tarifa %</th>
                            <th class="text-left p-2">Cta. PUC</th>
                            <th class="text-center p-2">Activa</th>
                            <th class="text-left p-2">Notas</th>
                            <th class="text-right p-2 w-24">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="r in filtradas" :key="r.id" class="hover:bg-surface-50">
                            <td class="p-2 font-semibold">{{ r.concepto }}</td>
                            <td v-if="tab === 'reteica'" class="p-2">{{ r.ciudad || '—' }}</td>
                            <td class="p-2 text-right font-mono">${{ Number(r.base_minima).toLocaleString('es-CO') }}</td>
                            <td class="p-2 text-right font-mono font-bold text-brand-600">{{ r.tarifa_pct }}%</td>
                            <td class="p-2 font-mono">{{ r.cuenta_puc }}</td>
                            <td class="p-2 text-center"><span :class="r.activa ? 'text-emerald-600' : 'text-red-500'" class="text-lg">●</span></td>
                            <td class="p-2 text-xs text-surface-500 max-w-xs truncate" :title="r.notas">{{ r.notas || '—' }}</td>
                            <td class="p-2 text-right whitespace-nowrap">
                                <button @click="abrir(r)" class="text-brand-600 hover:text-brand-700 p-1"><Pencil class="h-4 w-4"/></button>
                                <button @click="eliminar(r.id)" class="text-red-500 hover:text-red-700 p-1"><Trash2 class="h-4 w-4"/></button>
                            </td>
                        </tr>
                        <tr v-if="!filtradas.length"><td :colspan="tab === 'reteica' ? 8 : 7" class="p-6 text-center text-surface-500 text-sm">Sin reglas de {{ tab }} · empieza con "Nueva regla".</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="card p-3 text-xs text-surface-500">
                <div class="font-semibold text-surface-700 dark:text-surface-300 mb-1">💡 Ejemplos típicos Great Baby (Bogotá)</div>
                <ul class="list-disc list-inside space-y-1">
                    <li><strong>Retefuente compras generales:</strong> 2.5% desde $1.138.950 (27 UVT 2026) · Cuenta <code>236540</code></li>
                    <li><strong>Retefuente servicios:</strong> 4% o 6% desde $168.740 (4 UVT) · Cuenta <code>236525</code></li>
                    <li><strong>Reteica Bogotá compras:</strong> 0.414% (4.14x1000) desde $1.138.950 · Cuenta <code>236805</code></li>
                    <li><strong>Reteiva gran contribuyente:</strong> 15% del IVA · Cuenta <code>236701</code></li>
                </ul>
                <p class="mt-2">Silvia (contadora) confirma las cuentas específicas del PUC de Great Baby.</p>
            </div>

            <!-- Modal -->
            <div v-if="modal" @click.self="modal = false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <div class="card p-5 w-full max-w-md space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-bold">{{ form.id ? 'Editar regla' : 'Nueva regla' }} de {{ form.tipo }}</h3>
                        <button @click="modal = false" class="text-surface-500 hover:text-surface-700"><X class="h-5 w-5"/></button>
                    </div>
                    <form @submit.prevent="guardar" class="space-y-3">
                        <div>
                            <label class="text-xs font-semibold">Tipo *</label>
                            <select v-model="form.tipo" class="input w-full" :disabled="!!form.id">
                                <option value="retefuente">Retefuente</option>
                                <option value="reteica">Reteica</option>
                                <option value="reteiva">Reteiva</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Concepto *</label>
                            <input v-model="form.concepto" required maxlength="100" class="input w-full" placeholder="Ej: compras_generales, servicios, honorarios"/>
                        </div>
                        <div v-if="form.tipo === 'reteica'">
                            <label class="text-xs font-semibold">Ciudad *</label>
                            <input v-model="form.ciudad" required maxlength="60" class="input w-full" placeholder="Bogotá"/>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-xs font-semibold">Base mínima (COP)</label>
                                <input v-model="form.base_minima" type="number" min="0" step="0.01" class="input w-full font-mono"/>
                            </div>
                            <div>
                                <label class="text-xs font-semibold">Tarifa % *</label>
                                <input v-model="form.tarifa_pct" type="number" min="0" max="100" step="0.001" required class="input w-full font-mono"/>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Cuenta PUC *</label>
                            <input v-model="form.cuenta_puc" required maxlength="30" class="input w-full font-mono" placeholder="236540"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">ID impuesto SIIGO</label>
                            <input v-model.number="form.siigo_tax_id" type="number" min="1" class="input w-full font-mono" placeholder="Del catálogo /v1/taxes"/>
                            <p class="text-[11px] text-surface-500 mt-0.5">Necesario para mandar la retención en la factura electrónica.</p>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Notas</label>
                            <textarea v-model="form.notas" rows="2" class="input w-full"></textarea>
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" v-model="form.activa" class="rounded"/> Activa
                        </label>
                        <div class="flex justify-end gap-2 pt-2 border-t">
                            <button type="button" @click="modal = false" class="btn-ghost text-sm">Cancelar</button>
                            <button type="submit" :disabled="procesando" class="btn-primary text-sm">
                                {{ procesando ? 'Guardando…' : 'Guardar' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
