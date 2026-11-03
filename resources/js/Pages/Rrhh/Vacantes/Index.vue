<script setup>
import { ref, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Briefcase, Plus, Lock, Unlock } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';

const props = defineProps({ vacantes: { type: Object, required: true } });
const modal = ref(false);
const form = reactive({
    titulo: '', area: '', descripcion: '', requisitos: '',
    salario_min: null, salario_max: null, modalidad: 'presencial', tipo_contrato: 'indefinido',
});
const procesando = ref(false);
const crear = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post('/app/rrhh/vacantes', form, {
        preserveScroll: true,
        onSuccess: () => { modal.value = false; Object.assign(form, { titulo: '', descripcion: '', requisitos: '' }); },
        onFinish: () => procesando.value = false,
    });
};
const money = (n) => n ? '$' + Number(n).toLocaleString('es-CO', { maximumFractionDigits: 0 }) : '—';
const badge = (e) => ({ abierta: 'bg-emerald-100 text-emerald-800', en_seleccion: 'bg-blue-100 text-blue-800', cerrada: 'bg-surface-200 text-surface-600' }[e] || 'bg-surface-100');

// Cerrar y reabrir. El motivo del cierre es obligatorio: «cerrada» a secas no
// distingue entre se contrató a alguien y se canceló el cargo.
const modalConfirm = ref(null);
const cerrar = (v) => {
    modalConfirm.value = {
        titulo: `¿Cerrar la vacante «${v.titulo}»?`,
        mensaje: v.candidatos_count > 0
            ? `Tiene ${v.candidatos_count} candidato(s) cargado(s). Se deja de recibir hojas de vida; los que ya están siguen visibles en el tablero.`
            : 'Se deja de recibir hojas de vida. La podés reabrir cuando quieras.',
        color: 'amber',
        textoConfirmar: 'Cerrar vacante',
        pedirMotivo: { etiqueta: '¿Por qué se cierra?', placeholder: 'Se contrató a alguien, se canceló el cargo…', minimo: 5 },
        onConfirmar: (motivo) => {
            modalConfirm.value = null;
            router.post(`/app/rrhh/vacantes/${v.id}/cerrar`, { motivo }, { preserveScroll: true });
        },
    };
};
const reabrir = (v) => router.post(`/app/rrhh/vacantes/${v.id}/reabrir`, {}, { preserveScroll: true });
</script>

<template>
    <Head title="Vacantes"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between">
                <h1 class="text-2xl font-bold flex items-center gap-2"><Briefcase class="h-6 w-6 text-brand-600"/>Vacantes</h1>
                <button @click="modal = true" class="btn-primary"><Plus class="h-4 w-4"/> Nueva vacante</button>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">{{ $page.props.flash.success }}</div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-3">Título</th>
                            <th class="text-left p-3">Área</th>
                            <th class="text-left p-3">Modalidad</th>
                            <th class="text-left p-3">Contrato</th>
                            <th class="text-right p-3">Salario</th>
                            <th class="text-right p-3">Candidatos</th>
                            <th class="text-center p-3">Estado</th>
                            <th class="text-right p-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="v in vacantes.data" :key="v.id" class="hover:bg-surface-50">
                            <td class="p-3 font-bold">
                                <Link :href="`/app/rrhh/vacantes/${v.id}`" class="text-brand-600 hover:underline">{{ v.titulo }}</Link>
                                <div class="text-xs text-surface-500">
                                    Abierta: {{ v.apertura }}
                                    <span v-if="v.cierre"> · Cerrada: {{ v.cierre }}</span>
                                </div>
                            </td>
                            <td class="p-3">{{ v.area }}</td>
                            <td class="p-3 capitalize text-xs">{{ v.modalidad }}</td>
                            <td class="p-3 text-xs">{{ v.tipo_contrato }}</td>
                            <td class="p-3 text-right text-xs">{{ money(v.salario_min) }} - {{ money(v.salario_max) }}</td>
                            <td class="p-3 text-right font-bold">{{ v.candidatos_count }}</td>
                            <td class="p-3 text-center">
                                <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(v.estado)]">{{ v.estado }}</span>
                            </td>
                            <td class="p-3 text-right whitespace-nowrap">
                                <button v-if="v.estado !== 'cerrada'" @click="cerrar(v)"
                                        class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded border border-surface-300 hover:bg-surface-50">
                                    <Lock class="h-3.5 w-3.5"/> Cerrar
                                </button>
                                <button v-else @click="reabrir(v)"
                                        class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded border border-surface-300 hover:bg-surface-50">
                                    <Unlock class="h-3.5 w-3.5"/> Reabrir
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>

        <div v-if="modal" @click.self="modal = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-bold mb-3">Nueva vacante</h3>
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="text-xs font-semibold">Título</label><input v-model="form.titulo" class="input w-full" autofocus/></div>
                        <div><label class="text-xs font-semibold">Área</label><input v-model="form.area" class="input w-full" placeholder="Ventas, Bodega, Diseño..."/></div>
                    </div>
                    <div><label class="text-xs font-semibold">Descripción</label><textarea v-model="form.descripcion" rows="3" class="input w-full"></textarea></div>
                    <div><label class="text-xs font-semibold">Requisitos</label><textarea v-model="form.requisitos" rows="3" class="input w-full"></textarea></div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="text-xs font-semibold">Salario mín</label><input type="number" step="1000" v-model.number="form.salario_min" class="input w-full"/></div>
                        <div><label class="text-xs font-semibold">Salario máx</label><input type="number" step="1000" v-model.number="form.salario_max" class="input w-full"/></div>
                        <div><label class="text-xs font-semibold">Modalidad</label>
                            <select v-model="form.modalidad" class="input w-full">
                                <option value="presencial">Presencial</option><option value="remoto">Remoto</option><option value="hibrido">Híbrido</option>
                            </select>
                        </div>
                        <div><label class="text-xs font-semibold">Contrato</label>
                            <select v-model="form.tipo_contrato" class="input w-full">
                                <option value="indefinido">Indefinido</option><option value="obra_labor">Obra/Labor</option>
                                <option value="prestacion_servicios">Prestación</option><option value="aprendizaje">Aprendizaje</option>
                                <option value="temporal">Temporal</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modal = false" class="btn-ghost">Cancelar</button>
                    <button @click="crear" :disabled="procesando" class="btn-primary">{{ procesando ? '…' : 'Crear' }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
