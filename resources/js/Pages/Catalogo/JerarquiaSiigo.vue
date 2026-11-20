<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Layers, Plus, Pencil, Trash2, X } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import { useEscClose } from '@/composables/useEscClose';

const props = defineProps({
    lineas: { type: Array, required: true },
    grupos: { type: Array, required: true },
    subgrupos: { type: Array, required: true },
    clases: { type: Array, required: true },
});

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

const tab = ref('linea');
const modal = ref(null);
useEscClose(modal); // 'linea' | 'grupo' | 'subgrupo' | 'clase'
const form = reactive({});
const procesando = ref(false);

const gruposDeLineaModal = computed(() => props.grupos);
const subgruposDeGrupoModal = computed(() => props.subgrupos);

const abrir = (tipo, item = null) => {
    modal.value = tipo;
    // Reset
    Object.keys(form).forEach(k => delete form[k]);
    if (item) Object.assign(form, item);
    else {
        if (tipo === 'linea') Object.assign(form, { id: null, codigo: '', nombre: '', activa: true, siigo_id: '' });
        if (tipo === 'grupo') Object.assign(form, { id: null, linea_id: null, codigo: '', nombre: '', activa: true, siigo_id: '' });
        if (tipo === 'subgrupo') Object.assign(form, { id: null, grupo_id: null, codigo: '', nombre: '', activa: true });
        if (tipo === 'clase') Object.assign(form, { id: null, subgrupo_id: null, codigo: '', nombre: '', activa: true });
    }
};

const cerrar = () => { modal.value = null; };

const guardar = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post(`/app/catalogo/jerarquia-siigo/${modal.value}`, form, {
        preserveScroll: true,
        onSuccess: cerrar,
        onFinish: () => { procesando.value = false; },
    });
};

const eliminar = (tipo, id) => {
    modalConfirm.value = {
        titulo: `¿Eliminar ${tipo}?`,
        mensaje: `Solo se permite si no tiene descendientes.`,
        color: 'rose',
        textoConfirmar: 'Eliminar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.delete(`/app/catalogo/jerarquia-siigo/${tipo}/${id}`, { preserveScroll: true });
        },
    };
};

const tabs = [
    { key: 'linea', label: `Líneas (${props.lineas.length})` },
    { key: 'grupo', label: `Grupos (${props.grupos.length})` },
    { key: 'subgrupo', label: `Subgrupos (${props.subgrupos.length})` },
    { key: 'clase', label: `Clases (${props.clases.length})` },
];
</script>

<template>
    <Head title="Jerarquía SIIGO · Línea/Grupo/Subgrupo/Clase"/>
    <AppLayout>
        <div class="space-y-4 max-w-6xl">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Layers class="h-6 w-6 text-brand-600"/>
                    Jerarquía SIIGO · Clasificación de productos
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Estructura de 4 niveles obligatoria en SIIGO Kardex Referencias. Ejemplo: Línea "ROPA" → Grupo "BEBÉ" → Subgrupo "PIJAMAS" → Clase "TÉRMICOS".
                </p>
            </div>

            <div v-if="$page.props.flash?.message" class="p-3 rounded-lg text-sm"
                 :class="$page.props.flash.type === 'error' ? 'bg-red-500/15 border-l-4 border-red-500 text-red-700' : 'bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700'">
                {{ $page.props.flash.message }}
            </div>

            <!-- Tabs -->
            <div class="card p-1 flex gap-1 flex-wrap">
                <button v-for="t in tabs" :key="t.key" @click="tab = t.key"
                        :class="['px-4 py-2 rounded text-sm font-semibold', tab === t.key ? 'bg-brand-600 text-white' : 'text-surface-600 hover:bg-surface-100']">
                    {{ t.label }}
                </button>
            </div>

            <!-- TAB LÍNEAS -->
            <div v-if="tab === 'linea'" class="card overflow-hidden">
                <div class="p-3 border-b flex justify-between items-center">
                    <div class="text-xs uppercase font-bold text-brand-600">Líneas (nivel 1)</div>
                    <button @click="abrir('linea')" class="btn-primary text-xs inline-flex items-center gap-1">
                        <Plus class="h-3 w-3"/> Nueva línea
                    </button>
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="text-left p-2 w-24">Código</th>
                            <th class="text-left p-2">Nombre</th>
                            <th class="text-left p-2 w-32">ID SIIGO</th>
                            <th class="text-center p-2 w-20">Activa</th>
                            <th class="text-right p-2 w-24">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="l in lineas" :key="l.id" class="hover:bg-surface-50">
                            <td class="p-2 font-mono font-bold">{{ l.codigo }}</td>
                            <td class="p-2">{{ l.nombre }}</td>
                            <td class="p-2 font-mono text-xs text-surface-500">{{ l.siigo_id || '—' }}</td>
                            <td class="p-2 text-center"><span :class="l.activa ? 'text-emerald-600' : 'text-red-500'" class="text-lg">●</span></td>
                            <td class="p-2 text-right whitespace-nowrap">
                                <button @click="abrir('linea', l)" class="text-brand-600 hover:text-brand-700 p-1" title="Editar"><Pencil class="h-4 w-4"/></button>
                                <button @click="eliminar('linea', l.id)" class="text-red-500 hover:text-red-700 p-1" title="Eliminar"><Trash2 class="h-4 w-4"/></button>
                            </td>
                        </tr>
                        <tr v-if="!lineas.length"><td colspan="5" class="p-6 text-center text-surface-500 text-sm">Sin líneas creadas · empieza con "Nueva línea".</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- TAB GRUPOS -->
            <div v-if="tab === 'grupo'" class="card overflow-hidden">
                <div class="p-3 border-b flex justify-between items-center">
                    <div class="text-xs uppercase font-bold text-brand-600">Grupos (nivel 2 · dentro de una línea)</div>
                    <button @click="abrir('grupo')" class="btn-primary text-xs inline-flex items-center gap-1" :disabled="!lineas.length">
                        <Plus class="h-3 w-3"/> Nuevo grupo
                    </button>
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="text-left p-2">Línea</th>
                            <th class="text-left p-2 w-24">Código</th>
                            <th class="text-left p-2">Nombre</th>
                            <th class="text-left p-2 w-32">ID SIIGO</th>
                            <th class="text-center p-2 w-20">Activa</th>
                            <th class="text-right p-2 w-24">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="g in grupos" :key="g.id" class="hover:bg-surface-50">
                            <td class="p-2 text-xs">{{ g.linea?.codigo }} · {{ g.linea?.nombre }}</td>
                            <td class="p-2 font-mono font-bold">{{ g.codigo }}</td>
                            <td class="p-2">{{ g.nombre }}</td>
                            <td class="p-2 font-mono text-xs text-surface-500">{{ g.siigo_id || '—' }}</td>
                            <td class="p-2 text-center"><span :class="g.activa ? 'text-emerald-600' : 'text-red-500'" class="text-lg">●</span></td>
                            <td class="p-2 text-right whitespace-nowrap">
                                <button @click="abrir('grupo', g)" class="text-brand-600 hover:text-brand-700 p-1"><Pencil class="h-4 w-4"/></button>
                                <button @click="eliminar('grupo', g.id)" class="text-red-500 hover:text-red-700 p-1"><Trash2 class="h-4 w-4"/></button>
                            </td>
                        </tr>
                        <tr v-if="!grupos.length"><td colspan="6" class="p-6 text-center text-surface-500 text-sm">Sin grupos. Requiere al menos una línea.</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- TAB SUBGRUPOS -->
            <div v-if="tab === 'subgrupo'" class="card overflow-hidden">
                <div class="p-3 border-b flex justify-between items-center">
                    <div class="text-xs uppercase font-bold text-brand-600">Subgrupos (nivel 3)</div>
                    <button @click="abrir('subgrupo')" class="btn-primary text-xs inline-flex items-center gap-1" :disabled="!grupos.length">
                        <Plus class="h-3 w-3"/> Nuevo subgrupo
                    </button>
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="text-left p-2">Ruta (Línea/Grupo)</th>
                            <th class="text-left p-2 w-24">Código</th>
                            <th class="text-left p-2">Nombre</th>
                            <th class="text-center p-2 w-20">Activa</th>
                            <th class="text-right p-2 w-24">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="s in subgrupos" :key="s.id" class="hover:bg-surface-50">
                            <td class="p-2 font-mono text-xs text-surface-500">{{ s.grupo_label }}</td>
                            <td class="p-2 font-mono font-bold">{{ s.codigo }}</td>
                            <td class="p-2">{{ s.nombre }}</td>
                            <td class="p-2 text-center"><span :class="s.activa ? 'text-emerald-600' : 'text-red-500'" class="text-lg">●</span></td>
                            <td class="p-2 text-right whitespace-nowrap">
                                <button @click="abrir('subgrupo', s)" class="text-brand-600 hover:text-brand-700 p-1"><Pencil class="h-4 w-4"/></button>
                                <button @click="eliminar('subgrupo', s.id)" class="text-red-500 hover:text-red-700 p-1"><Trash2 class="h-4 w-4"/></button>
                            </td>
                        </tr>
                        <tr v-if="!subgrupos.length"><td colspan="5" class="p-6 text-center text-surface-500 text-sm">Sin subgrupos. Requiere al menos un grupo.</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- TAB CLASES -->
            <div v-if="tab === 'clase'" class="card overflow-hidden">
                <div class="p-3 border-b flex justify-between items-center">
                    <div class="text-xs uppercase font-bold text-brand-600">Clases (nivel 4 · máximo detalle)</div>
                    <button @click="abrir('clase')" class="btn-primary text-xs inline-flex items-center gap-1" :disabled="!subgrupos.length">
                        <Plus class="h-3 w-3"/> Nueva clase
                    </button>
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="text-left p-2">Ruta (Línea/Grupo/Subgrupo)</th>
                            <th class="text-left p-2 w-24">Código</th>
                            <th class="text-left p-2">Nombre</th>
                            <th class="text-center p-2 w-20">Activa</th>
                            <th class="text-right p-2 w-24">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="c in clases" :key="c.id" class="hover:bg-surface-50">
                            <td class="p-2 font-mono text-xs text-surface-500">{{ c.subgrupo_label }}</td>
                            <td class="p-2 font-mono font-bold">{{ c.codigo }}</td>
                            <td class="p-2">{{ c.nombre }}</td>
                            <td class="p-2 text-center"><span :class="c.activa ? 'text-emerald-600' : 'text-red-500'" class="text-lg">●</span></td>
                            <td class="p-2 text-right whitespace-nowrap">
                                <button @click="abrir('clase', c)" class="text-brand-600 hover:text-brand-700 p-1"><Pencil class="h-4 w-4"/></button>
                                <button @click="eliminar('clase', c.id)" class="text-red-500 hover:text-red-700 p-1"><Trash2 class="h-4 w-4"/></button>
                            </td>
                        </tr>
                        <tr v-if="!clases.length"><td colspan="5" class="p-6 text-center text-surface-500 text-sm">Sin clases. Requiere al menos un subgrupo.</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Modal -->
            <div v-if="modal" @click.self="cerrar" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <div class="card p-5 w-full max-w-md space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-bold capitalize">
                            {{ form.id ? 'Editar' : 'Nueva' }} {{ modal }}
                        </h3>
                        <button @click="cerrar" class="text-surface-500 hover:text-surface-700"><X class="h-5 w-5"/></button>
                    </div>
                    <form @submit.prevent="guardar" class="space-y-3">
                        <!-- Padre (para grupo/subgrupo/clase) -->
                        <div v-if="modal === 'grupo'">
                            <label class="text-xs font-semibold">Línea *</label>
                            <select v-model="form.linea_id" required class="input w-full">
                                <option :value="null">— Selecciona —</option>
                                <option v-for="l in lineas" :key="l.id" :value="l.id">{{ l.codigo }} · {{ l.nombre }}</option>
                            </select>
                        </div>
                        <div v-if="modal === 'subgrupo'">
                            <label class="text-xs font-semibold">Grupo *</label>
                            <select v-model="form.grupo_id" required class="input w-full">
                                <option :value="null">— Selecciona —</option>
                                <option v-for="g in gruposDeLineaModal" :key="g.id" :value="g.id">{{ g.linea?.codigo }} / {{ g.codigo }} · {{ g.nombre }}</option>
                            </select>
                        </div>
                        <div v-if="modal === 'clase'">
                            <label class="text-xs font-semibold">Subgrupo *</label>
                            <select v-model="form.subgrupo_id" required class="input w-full">
                                <option :value="null">— Selecciona —</option>
                                <option v-for="s in subgruposDeGrupoModal" :key="s.id" :value="s.id">{{ s.grupo_label }} / {{ s.codigo }} · {{ s.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Código *</label>
                            <input v-model="form.codigo" required maxlength="20" class="input w-full font-mono"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Nombre *</label>
                            <input v-model="form.nombre" required maxlength="100" class="input w-full"/>
                        </div>
                        <div v-if="modal === 'linea' || modal === 'grupo'">
                            <label class="text-xs font-semibold">ID SIIGO (opcional)</label>
                            <input v-model="form.siigo_id" maxlength="60" class="input w-full font-mono"/>
                            <p class="text-[10px] text-surface-500 mt-1">Se autocompleta al sincronizar con SIIGO.</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" v-model="form.activa" class="rounded"/> Activa
                        </label>
                        <div class="flex justify-end gap-2 pt-2 border-t">
                            <button type="button" @click="cerrar" class="btn-ghost text-sm">Cancelar</button>
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
