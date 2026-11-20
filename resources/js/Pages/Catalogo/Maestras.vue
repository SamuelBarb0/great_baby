<script setup>
import { ref, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Tag, Plus, Edit2, Trash2 } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import { useEscClose } from '@/composables/useEscClose';

const props = defineProps({ marcas: Array, categorias: Array, colores: Array });
const tab = ref('marcas');
const modal = ref(null);
useEscClose(modal);
const form = reactive({});
const procesando = ref(false);
// PROD-11 · reemplazo de confirm() nativo (bloqueado en iframes y con UX pobre).
const modalConfirm = ref(null);

const abrir = (tipo, item = null) => {
    modal.value = tipo;
    if (item) Object.assign(form, item);
    else {
        if (tipo === 'marca') Object.assign(form, { id: null, codigo: '', nombre: '', activa: true });
        if (tipo === 'categoria') Object.assign(form, { id: null, padre_id: null, codigo: '', nombre: '', cuenta_puc_ingreso: '', cuenta_puc_costo: '', activa: true });
        if (tipo === 'color') Object.assign(form, { id: null, codigo: '', nombre: '', hex: '#cccccc', activo: true });
    }
};
const guardar = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post(`/app/catalogo/${modal.value}`, form, {
        preserveScroll: true,
        onSuccess: () => modal.value = null,
        onFinish: () => procesando.value = false,
    });
};
const eliminar = (tipo, id) => {
    modalConfirm.value = {
        titulo: '¿Eliminar registro?',
        mensaje: 'La acción no se puede deshacer.',
        color: 'rose',
        textoConfirmar: 'Eliminar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.delete(`/app/catalogo/${tipo}/${id}`, { preserveScroll: true });
        },
    };
};
</script>

<template>
    <Head title="Maestras catálogo"/>
    <AppLayout>
        <div class="space-y-4">
            <h1 class="text-2xl font-bold flex items-center gap-2"><Tag class="h-6 w-6 text-brand-600"/>Maestras del catálogo</h1>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">{{ $page.props.flash.success }}</div>

            <div class="card p-1 flex">
                <button v-for="t in [{k:'marcas',l:'Marcas ('+marcas.length+')'},{k:'categorias',l:'Categorías ('+categorias.length+')'},{k:'colores',l:'Colores ('+colores.length+')'}]" :key="t.k"
                    @click="tab = t.k" :class="['px-4 py-2 rounded text-sm font-semibold', tab === t.k ? 'bg-brand-600 text-white' : 'text-surface-600']">
                    {{ t.l }}
                </button>
            </div>

            <!-- MARCAS -->
            <div v-if="tab === 'marcas'" class="card p-4">
                <div class="flex justify-between mb-3">
                    <div class="text-xs uppercase font-bold text-brand-600">Marcas</div>
                    <button @click="abrir('marca')" class="btn-primary text-sm"><Plus class="h-4 w-4"/> Nueva</button>
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b"><tr><th class="text-left p-2">Código</th><th class="text-left p-2">Nombre</th><th class="text-center p-2">Activa</th><th class="text-right p-2"></th></tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="m in marcas" :key="m.id" class="hover:bg-surface-50">
                            <td class="p-2 font-mono">{{ m.codigo }}</td>
                            <td class="p-2 font-medium">{{ m.nombre }}</td>
                            <td class="p-2 text-center">{{ m.activa ? '✓' : '—' }}</td>
                            <td class="p-2 text-right"><button @click="abrir('marca', m)" class="btn-ghost p-1"><Edit2 class="h-4 w-4"/></button><button @click="eliminar('marca', m.id)" class="btn-ghost p-1 text-red-600"><Trash2 class="h-4 w-4"/></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- CATEGORÍAS -->
            <div v-if="tab === 'categorias'" class="card p-4">
                <div class="flex justify-between mb-3">
                    <div class="text-xs uppercase font-bold text-brand-600">Categorías</div>
                    <button @click="abrir('categoria')" class="btn-primary text-sm"><Plus class="h-4 w-4"/> Nueva</button>
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b"><tr><th class="text-left p-2">Código</th><th class="text-left p-2">Nombre</th><th class="text-left p-2">PUC Ingreso</th><th class="text-left p-2">PUC Costo</th><th class="text-right p-2"></th></tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="c in categorias" :key="c.id" class="hover:bg-surface-50">
                            <td class="p-2 font-mono">{{ c.codigo }}</td>
                            <td class="p-2 font-medium">{{ c.nombre }}</td>
                            <td class="p-2 font-mono text-xs">{{ c.cuenta_puc_ingreso || '—' }}</td>
                            <td class="p-2 font-mono text-xs">{{ c.cuenta_puc_costo || '—' }}</td>
                            <td class="p-2 text-right"><button @click="abrir('categoria', c)" class="btn-ghost p-1"><Edit2 class="h-4 w-4"/></button><button @click="eliminar('categoria', c.id)" class="btn-ghost p-1 text-red-600"><Trash2 class="h-4 w-4"/></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- COLORES -->
            <div v-if="tab === 'colores'" class="card p-4">
                <div class="flex justify-between mb-3">
                    <div class="text-xs uppercase font-bold text-brand-600">Colores</div>
                    <button @click="abrir('color')" class="btn-primary text-sm"><Plus class="h-4 w-4"/> Nuevo</button>
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b"><tr><th class="text-left p-2">Muestra</th><th class="text-left p-2">Código</th><th class="text-left p-2">Nombre</th><th class="text-left p-2">Hex</th><th class="text-right p-2"></th></tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="c in colores" :key="c.id" class="hover:bg-surface-50">
                            <td class="p-2"><div class="w-6 h-6 rounded" :style="`background:${c.hex || '#ccc'}`"></div></td>
                            <td class="p-2 font-mono">{{ c.codigo }}</td>
                            <td class="p-2">{{ c.nombre }}</td>
                            <td class="p-2 font-mono text-xs">{{ c.hex }}</td>
                            <td class="p-2 text-right"><button @click="abrir('color', c)" class="btn-ghost p-1"><Edit2 class="h-4 w-4"/></button><button @click="eliminar('color', c.id)" class="btn-ghost p-1 text-red-600"><Trash2 class="h-4 w-4"/></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="modal" @click.self="modal = null" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full">
                <h3 class="text-lg font-bold mb-3 capitalize">{{ form.id ? 'Editar' : 'Nueva' }} {{ modal }}</h3>
                <div class="space-y-3">
                    <div><label class="text-xs font-semibold">Código</label><input v-model="form.codigo" class="input w-full" autofocus/></div>
                    <div><label class="text-xs font-semibold">Nombre</label><input v-model="form.nombre" class="input w-full"/></div>
                    <template v-if="modal === 'categoria'">
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="text-xs font-semibold">PUC Ingreso</label><input v-model="form.cuenta_puc_ingreso" class="input w-full font-mono"/></div>
                            <div><label class="text-xs font-semibold">PUC Costo</label><input v-model="form.cuenta_puc_costo" class="input w-full font-mono"/></div>
                        </div>
                    </template>
                    <template v-if="modal === 'color'">
                        <div><label class="text-xs font-semibold">Hex</label><input type="color" v-model="form.hex" class="w-full h-10 rounded"/></div>
                    </template>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="checkbox"
                            :checked="modal === 'color' ? form.activo : form.activa"
                            @change="e => { if (modal === 'color') form.activo = e.target.checked; else form.activa = e.target.checked; }"/>
                        Activa/o
                    </label>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modal = null" class="btn-ghost">Cancelar</button>
                    <button @click="guardar" :disabled="procesando" class="btn-primary">{{ procesando ? '…' : 'Guardar' }}</button>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
