<script setup>
import { ref, computed } from 'vue';
import { Head, router, useForm, Link } from '@inertiajs/vue3';
import {
    ListTree, Plus, Upload, Download, Search, Pencil, Trash2, X, ArrowLeft,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasConfigContable } from '@/composables/usePestanasContables';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import { useEscClose } from '@/composables/useEscClose';

const props = defineProps({
    // Eliminar e importar son de gerencia. Antes los botones salian para
    // cualquier contable y respondian 403.
    puedeAdministrar: { type: Boolean, default: false },
    filtros: { type: Object, required: true },
    clases: { type: Object, required: true },
    kpis: { type: Object, required: true },
    cuentas: { type: Object, required: true },
});

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

// Filtros reactivos con debounce en el buscador.
const q = ref(props.filtros.q);
const clase = ref(props.filtros.clase);
const nivel = ref(props.filtros.nivel);
const movimiento = ref(props.filtros.movimiento);
const activa = ref(props.filtros.activa);

let debounce;
const filtrar = () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get('/app/contabilidad/plan-cuentas', {
            q: q.value || null,
            clase: clase.value || null,
            nivel: nivel.value || null,
            movimiento: movimiento.value === '' ? null : movimiento.value,
            activa: activa.value === '' ? null : activa.value,
        }, { preserveScroll: true, preserveState: true, replace: true });
    }, 300);
};

// Modal crear/editar
const editando = ref(null);
useEscClose(editando);
const form = useForm({
    codigo: '', nombre: '', naturaleza: 'debito',
    permite_movimiento: false, siigo_cuenta_id: '', activa: true,
});

const abrirNueva = () => {
    editando.value = 'nueva';
    form.reset();
    form.clearErrors();
};
const abrirEditar = (c) => {
    editando.value = c.id;
    form.codigo = c.codigo;
    form.nombre = c.nombre;
    form.naturaleza = c.naturaleza;
    form.permite_movimiento = c.permite_movimiento;
    form.siigo_cuenta_id = c.siigo_cuenta_id || '';
    form.activa = c.activa;
    form.clearErrors();
};
const cerrar = () => { editando.value = null; };
const guardar = () => {
    form.post('/app/contabilidad/plan-cuentas', {
        preserveScroll: true,
        onSuccess: cerrar,
    });
};

const eliminar = (c) => {
    modalConfirm.value = {
        titulo: `¿Eliminar la cuenta ${c.codigo} · ${c.nombre}?`,
        mensaje: `Solo se permite si NO tiene subcuentas.`,
        color: 'rose',
        textoConfirmar: 'Eliminar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.delete(`/app/contabilidad/plan-cuentas/${c.id}`, { preserveScroll: true });
        },
    };
};

// Importar Excel/CSV
const archivo = ref(null);
const importando = ref(false);
const importar = () => {
    if (! archivo.value) return;
    importando.value = true;
    const fd = new FormData();
    fd.append('archivo', archivo.value);
    router.post('/app/contabilidad/plan-cuentas/importar', fd, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            importando.value = false;
            archivo.value = null;
            const input = document.getElementById('archivoPuc');
            if (input) input.value = '';
        },
    });
};

const colorClase = (clase) => ({
    '1': 'bg-emerald-100 text-emerald-700',   // Activo
    '2': 'bg-red-100 text-red-700',           // Pasivo
    '3': 'bg-blue-100 text-blue-700',         // Patrimonio
    '4': 'bg-purple-100 text-purple-700',     // Ingresos
    '5': 'bg-amber-100 text-amber-700',       // Gastos
    '6': 'bg-orange-100 text-orange-700',     // Costos de venta
    '7': 'bg-pink-100 text-pink-700',         // Costos de producción
}[clase] || 'bg-surface-100 text-surface-700');
</script>

<template>
    <Head title="Plan de cuentas (PUC)"/>
    <AppLayout>
        <div class="space-y-4">
            <PestanasSeccion :pestanas="pestanasConfigContable"/>


            <Link href="/app/contabilidad" class="text-sm text-brand-600 hover:underline inline-flex items-center gap-1">
                <ArrowLeft class="h-4 w-4"/> Volver a Contabilidad
            </Link>

            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <ListTree class="h-6 w-6 text-brand-600"/>
                        Plan de cuentas (PUC)
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Catálogo contable de Great Baby · gestionado por Silvia (contadora).
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="/app/contabilidad/plan-cuentas/plantilla" download
                       class="px-3 py-2 text-sm font-semibold rounded border border-surface-300 hover:bg-surface-50 dark:hover:bg-surface-800 inline-flex items-center gap-1.5">
                        <Download class="h-4 w-4"/> Plantilla
                    </a>
                    <label v-if="puedeAdministrar" class="px-3 py-2 text-sm font-semibold rounded bg-blue-600 hover:bg-blue-700 text-white inline-flex items-center gap-1.5 cursor-pointer"
                           :class="importando ? 'opacity-50 cursor-wait' : ''">
                        <Upload class="h-4 w-4"/>
                        <span>{{ importando ? 'Importando…' : 'Importar' }}</span>
                        <input id="archivoPuc" type="file" accept=".xlsx,.csv,.txt" class="hidden"
                               @change="e => { archivo = e.target.files[0]; importar(); }"/>
                    </label>
                    <button @click="abrirNueva" class="px-3 py-2 text-sm font-semibold rounded bg-brand-600 hover:bg-brand-700 text-white inline-flex items-center gap-1.5">
                        <Plus class="h-4 w-4"/> Nueva cuenta
                    </button>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-3 gap-3">
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500">Total cuentas</div>
                    <div class="text-2xl font-bold mt-1">{{ kpis.total.toLocaleString('es-CO') }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500">De movimiento (hojas)</div>
                    <div class="text-2xl font-bold mt-1 text-emerald-600">{{ kpis.de_movimiento.toLocaleString('es-CO') }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500">Activas</div>
                    <div class="text-2xl font-bold mt-1">{{ kpis.activas.toLocaleString('es-CO') }}</div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="card p-3">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-2">
                    <div class="relative md:col-span-2">
                        <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-surface-400"/>
                        <input v-model="q" @input="filtrar" placeholder="Buscar por código o nombre…"
                               class="input pl-9 w-full text-sm"/>
                    </div>
                    <select v-model="clase" @change="filtrar" class="input text-sm">
                        <option value="">Todas las clases</option>
                        <option v-for="(nom, cod) in clases" :key="cod" :value="cod">{{ cod }} · {{ nom }}</option>
                    </select>
                    <select v-model="movimiento" @change="filtrar" class="input text-sm">
                        <option value="">Todos</option>
                        <option value="1">Solo de movimiento</option>
                        <option value="0">Solo padres</option>
                    </select>
                    <select v-model="activa" @change="filtrar" class="input text-sm">
                        <option value="">Todas</option>
                        <option value="1">Solo activas</option>
                        <option value="0">Solo inactivas</option>
                    </select>
                </div>
            </div>

            <!-- Tabla -->
            <div class="card overflow-hidden">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] text-surface-500 uppercase border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="text-left p-2 w-24">Código</th>
                            <th class="text-left p-2">Nombre</th>
                            <th class="text-left p-2 w-32">Clase</th>
                            <th class="text-center p-2 w-16">Nivel</th>
                            <th class="text-center p-2 w-24">Naturaleza</th>
                            <th class="text-center p-2 w-24">Movimiento</th>
                            <th class="text-center p-2 w-20">Activa</th>
                            <th class="text-left p-2 w-32">SIIGO ID</th>
                            <th class="text-right p-2 w-32">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="c in cuentas.data" :key="c.id" class="hover:bg-surface-50 dark:hover:bg-surface-800">
                            <td class="p-2 font-mono font-bold">{{ c.codigo }}</td>
                            <td class="p-2">{{ c.nombre }}</td>
                            <td class="p-2">
                                <span :class="['text-xs px-2 py-0.5 rounded font-semibold', colorClase(c.clase)]">
                                    {{ c.clase }} · {{ c.clase_nombre }}
                                </span>
                            </td>
                            <td class="p-2 text-center text-xs">{{ c.nivel }}</td>
                            <td class="p-2 text-center">
                                <span class="text-[10px] uppercase font-bold"
                                      :class="c.naturaleza === 'debito' ? 'text-blue-600' : 'text-purple-600'">
                                    {{ c.naturaleza }}
                                </span>
                            </td>
                            <td class="p-2 text-center">
                                <span v-if="c.permite_movimiento" class="text-emerald-600 text-lg">✓</span>
                                <span v-else class="text-surface-300">—</span>
                            </td>
                            <td class="p-2 text-center">
                                <span v-if="c.activa" class="text-emerald-600 text-lg">●</span>
                                <span v-else class="text-red-500 text-lg">●</span>
                            </td>
                            <td class="p-2 font-mono text-xs text-surface-500">{{ c.siigo_cuenta_id || '—' }}</td>
                            <td class="p-2 text-right">
                                <button @click="abrirEditar(c)" class="text-brand-600 hover:text-brand-700 p-1" title="Editar">
                                    <Pencil class="h-4 w-4"/>
                                </button>
                                <button v-if="puedeAdministrar" @click="eliminar(c)" class="text-red-500 hover:text-red-700 p-1 ml-1" title="Eliminar">
                                    <Trash2 class="h-4 w-4"/>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!cuentas.data.length">
                            <td colspan="9" class="p-8 text-center text-surface-500 text-sm">
                                Sin cuentas que coincidan con los filtros.
                            </td>
                        </tr>
                    </tbody>
                </table>
                <!-- Paginación -->
                <div v-if="cuentas.links && cuentas.links.length > 3" class="flex items-center justify-between p-3 border-t text-xs">
                    <div class="text-surface-500">
                        {{ cuentas.from || 0 }}–{{ cuentas.to || 0 }} de {{ cuentas.total }}
                    </div>
                    <div class="flex gap-1">
                        <template v-for="l in cuentas.links" :key="l.label">
                            <button v-if="l.url" @click="router.get(l.url, {}, { preserveScroll: true })"
                                    :class="['px-2 py-1 rounded', l.active ? 'bg-brand-600 text-white' : 'hover:bg-surface-100']"
                                    v-html="l.label"/>
                            <span v-else :class="['px-2 py-1 text-surface-400']" v-html="l.label"/>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Modal crear/editar -->
            <div v-if="editando" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="cerrar">
                <div class="card p-5 w-full max-w-lg space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-bold">{{ editando === 'nueva' ? 'Nueva cuenta' : 'Editar cuenta' }}</h2>
                        <button @click="cerrar" class="text-surface-500 hover:text-surface-700"><X class="h-5 w-5"/></button>
                    </div>
                    <form @submit.prevent="guardar" class="space-y-3">
                        <div>
                            <label class="text-xs text-surface-500">Código (solo dígitos)</label>
                            <input v-model="form.codigo" required pattern="\d+" maxlength="20"
                                   class="input w-full font-mono text-sm" :disabled="editando !== 'nueva'"/>
                            <div v-if="form.errors.codigo" class="text-xs text-red-600 mt-1">{{ form.errors.codigo }}</div>
                        </div>
                        <div>
                            <label class="text-xs text-surface-500">Nombre</label>
                            <input v-model="form.nombre" required maxlength="150" class="input w-full text-sm"/>
                            <div v-if="form.errors.nombre" class="text-xs text-red-600 mt-1">{{ form.errors.nombre }}</div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-xs text-surface-500">Naturaleza</label>
                                <select v-model="form.naturaleza" class="input w-full text-sm">
                                    <option value="debito">Débito</option>
                                    <option value="credito">Crédito</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs text-surface-500">ID SIIGO (opcional)</label>
                                <input v-model="form.siigo_cuenta_id" maxlength="50" class="input w-full font-mono text-sm"/>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" v-model="form.permite_movimiento" class="rounded"/>
                                Permite movimiento (hoja)
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" v-model="form.activa" class="rounded"/>
                                Activa
                            </label>
                        </div>
                        <div class="flex justify-end gap-2 pt-2 border-t">
                            <button type="button" @click="cerrar" class="px-3 py-2 text-sm rounded border border-surface-300 hover:bg-surface-50">Cancelar</button>
                            <button type="submit" :disabled="form.processing" class="px-4 py-2 text-sm font-semibold rounded bg-brand-600 hover:bg-brand-700 text-white">
                                {{ form.processing ? 'Guardando…' : 'Guardar' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
