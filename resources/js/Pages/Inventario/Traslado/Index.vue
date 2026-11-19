<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeftRight, Plus, ExternalLink, Ban, Truck, CheckCircle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useEscClose } from '@/composables/useEscClose';
import { useFecha } from '@/composables/useFecha';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppPromptModal from '@/Components/AppPromptModal.vue';

const props = defineProps({
    traslados: { type: Object, required: true },
    ubicaciones: { type: Array, default: () => [] },
    filtros: { type: Object, default: () => ({}) },
});

/*
 * Las ubicaciones agrupadas para el desplegable.
 *
 * Con 491 opciones seguidas no se encuentra «rack 12, sección 4, nivel 3
 * derecha»: agrupadas por bodega y por rack, el navegador deja saltar de grupo
 * en grupo y la lista se vuelve recorrible. Las bodegas van primero porque es
 * a donde se mueve más seguido.
 */
const ubicacionesPorGrupo = computed(() => {
    const salida = {};

    for (const u of props.ubicaciones) {
        const g = u.grupo || 'Otras';
        (salida[g] ??= []).push(u);
    }

    // «Bodegas» primero; el resto, por nombre de rack.
    return Object.fromEntries(
        Object.entries(salida).sort(([a], [b]) =>
            a === 'Bodegas' ? -1 : b === 'Bodegas' ? 1 : a.localeCompare(b, 'es', { numeric: true })),
    );
});

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);
// Pedido de motivo (anulaciones), en vez del prompt() nativo.
const modalMotivo = ref(null);

const { fechaCorta } = useFecha();

const modal = ref(false);
useEscClose(modal);

const form = reactive({ origen_id: '', destino_id: '', motivo: '', observaciones: '' });
const errores = ref({});
const procesando = ref(false);

const crear = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post('/app/inventario/traslados', form, {
        preserveScroll: true,
        onSuccess: () => {
            modal.value = false;
            Object.assign(form, { origen_id: '', destino_id: '', motivo: '', observaciones: '' });
        },
        onError: (e) => { errores.value = e; },
        onFinish: () => (procesando.value = false),
    });
};

const badge = (e) => ({
    borrador: 'bg-surface-200 text-surface-800',
    en_transito: 'bg-amber-100 text-amber-800',
    recibido: 'bg-emerald-100 text-emerald-800',
    anulado: 'bg-red-100 text-red-800',
}[e] || 'bg-surface-100');

const filtroEstado = ref(props.filtros.estado || '');
const aplicarFiltro = () => router.get('/app/inventario/traslados', { estado: filtroEstado.value || undefined }, { preserveState: true, preserveScroll: true });

// Sprint 3 · A.5 · acciones por fila (enviar, recibir, anular).
const enviando = ref(null);
const recibiendo = ref(null);
const anulando = ref(null);

const enviar = (t) => {
    modalConfirm.value = {
        titulo: `¿Enviar traslado ${t.numero}?`,
        mensaje: `Sale de ${t.origen} · queda EN TRÁNSITO.`,
        color: 'sky',
        textoConfirmar: 'Enviar',
        onConfirmar: () => {
            modalConfirm.value = null;
            enviando.value = t.id;
            router.post(`/app/inventario/traslados/${t.id}/enviar`, {}, {
                preserveScroll: true,
                onFinish: () => { enviando.value = null; },
            });
        },
    };
};

const recibir = (t) => {
    modalConfirm.value = {
        titulo: `¿Confirmar recepción del traslado ${t.numero}?`,
        mensaje: `Entra a bodega ${t.destino}. Genera asiento SIIGO automático.`,
        color: 'emerald',
        textoConfirmar: 'Confirmar recepción',
        onConfirmar: () => {
            modalConfirm.value = null;
            recibiendo.value = t.id;
            router.post(`/app/inventario/traslados/${t.id}/recibir`, {}, {
                preserveScroll: true,
                onFinish: () => { recibiendo.value = null; },
            });
        },
    };
};

const anular = (t) => {
    // Antes era un prompt() nativo: dentro del iframe de la app de escritorio
    // devuelve null al instante, así que anular no hacía nada y sin aviso.
    modalMotivo.value = {
        titulo: `Anular el traslado ${t.numero}`,
        mensaje: 'Queda registrado quién lo anuló y cuándo. No se puede deshacer.',
        etiqueta: 'Motivo de la anulación',
        minimo: 10,
        color: 'rose',
        textoConfirmar: 'Anular',
        onConfirmar: (motivo) => {
            modalMotivo.value = null;
            anulando.value = t.id;
            router.post(`/app/inventario/traslados/${t.id}/anular`, { motivo }, {
                preserveScroll: true,
                onFinish: () => { anulando.value = null; },
            });
        },
    };
};

const puedeEnviar = (t) => t.estado === 'borrador';
const puedeRecibir = (t) => t.estado === 'en_transito';
const puedeAnular = (t) => ['borrador', 'en_transito'].includes(t.estado);
</script>

<template>
    <Head title="Traslados de inventario" />
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <ArrowLeftRight class="h-6 w-6 text-brand-600" />
                    Traslados entre ubicaciones
                </h1>
                <div class="flex gap-2">
                    <select v-model="filtroEstado" @change="aplicarFiltro" class="input">
                        <option value="">Todos los estados</option>
                        <option value="borrador">Borrador</option>
                        <option value="en_transito">En tránsito</option>
                        <option value="recibido">Recibido</option>
                        <option value="anulado">Anulado</option>
                    </select>
                    <button @click="modal = true" class="btn-primary min-h-11">
                        <Plus class="h-4 w-4" /> Nuevo traslado
                    </button>
                </div>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 text-sm">
                {{ $page.props.flash.error }}
            </div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-3">Número</th>
                            <th class="text-left p-3">Origen → Destino</th>
                            <th class="text-left p-3">Motivo</th>
                            <th class="text-left p-3">Solicitante</th>
                            <th class="text-left p-3">Fecha</th>
                            <th class="text-center p-3">Estado</th>
                            <th class="text-right p-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-if="!traslados.data.length"><td colspan="7" class="p-6 text-center text-surface-500">No hay traslados. Crea uno con el botón de arriba.</td></tr>
                        <tr v-for="t in traslados.data" :key="t.id" class="hover:bg-surface-50">
                            <td class="p-3 font-mono font-bold">{{ t.numero }}</td>
                            <td class="p-3 text-xs">{{ t.origen }} → {{ t.destino }}</td>
                            <td class="p-3">{{ t.motivo }}</td>
                            <td class="p-3">{{ t.solicitante }}</td>
                            <td class="p-3 text-xs">{{ fechaCorta(t.fecha) }}</td>
                            <td class="p-3 text-center">
                                <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(t.estado)]">{{ t.estado }}</span>
                            </td>
                            <td class="p-3 text-right whitespace-nowrap">
                                <Link :href="`/app/inventario/traslados/${t.id}`" class="text-brand-600 hover:text-brand-700 p-1 inline-block" title="Abrir">
                                    <ExternalLink class="h-4 w-4"/>
                                </Link>
                                <button v-if="puedeEnviar(t)" @click="enviar(t)" :disabled="enviando === t.id"
                                        class="text-blue-600 hover:text-blue-700 p-1"
                                        :class="enviando === t.id ? 'opacity-50 cursor-wait' : ''"
                                        title="Enviar (poner en tránsito)">
                                    <Truck class="h-4 w-4"/>
                                </button>
                                <button v-if="puedeRecibir(t)" @click="recibir(t)" :disabled="recibiendo === t.id"
                                        class="text-emerald-600 hover:text-emerald-700 p-1"
                                        :class="recibiendo === t.id ? 'opacity-50 cursor-wait' : ''"
                                        title="Recibir (confirmar entrada)">
                                    <CheckCircle class="h-4 w-4"/>
                                </button>
                                <button v-if="puedeAnular(t)" @click="anular(t)" :disabled="anulando === t.id"
                                        class="text-red-500 hover:text-red-700 p-1"
                                        :class="anulando === t.id ? 'opacity-50 cursor-wait' : ''"
                                        title="Anular traslado">
                                    <Ban class="h-4 w-4"/>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="traslados.links && traslados.links.length > 3" class="flex justify-center gap-1 flex-wrap">
                <Link v-for="l in traslados.links" :key="l.label" :href="l.url || '#'" preserve-scroll
                    v-html="l.label"
                    :class="['px-3 py-1 text-xs rounded', l.active ? 'bg-brand-600 text-white' : 'bg-surface-100 hover:bg-surface-200', !l.url && 'opacity-40 pointer-events-none']"/>
            </div>
        </div>

        <div v-if="modal" @click.self="modal = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full">
                <h3 class="text-lg font-bold mb-3">Nuevo traslado</h3>
                <div class="space-y-3">
                    <div>
                        <label class="text-xs font-semibold">Origen</label>
                        <select v-model.number="form.origen_id" class="input w-full" autofocus>
                            <option value="">— Selecciona una ubicación —</option>
                            <optgroup v-for="(lista, grupo) in ubicacionesPorGrupo" :key="grupo" :label="grupo">
                                <option v-for="u in lista" :key="u.id" :value="u.id">{{ u.codigo }} · {{ u.nombre }}</option>
                            </optgroup>
                        </select>
                        <p v-if="errores.origen_id" class="text-xs text-red-600 mt-1">{{ errores.origen_id }}</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Destino</label>
                        <select v-model.number="form.destino_id" class="input w-full">
                            <option value="">— Selecciona una ubicación —</option>
                            <optgroup v-for="(lista, grupo) in ubicacionesPorGrupo" :key="grupo" :label="grupo">
                                <option v-for="u in lista" :key="u.id" :value="u.id" :disabled="u.id === form.origen_id">{{ u.codigo }} · {{ u.nombre }}</option>
                            </optgroup>
                        </select>
                        <p v-if="errores.destino_id" class="text-xs text-red-600 mt-1">{{ errores.destino_id }}</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Motivo</label>
                        <select v-model="form.motivo" class="input w-full">
                            <option value="">— Selecciona —</option>
                            <option value="reposicion">Reposición stock venta</option>
                            <option value="averia">Envío por avería</option>
                            <option value="correccion">Corrección de inventario</option>
                            <option value="prestamo">Préstamo entre bodegas</option>
                            <option value="otro">Otro</option>
                        </select>
                        <p v-if="errores.motivo" class="text-xs text-red-600 mt-1">{{ errores.motivo }}</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Observaciones</label>
                        <textarea v-model="form.observaciones" rows="2" class="input w-full" placeholder="Opcional"></textarea>
                    </div>
                    <p class="text-xs text-surface-500">Después de crear, agrega los productos en la pantalla del traslado.</p>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modal = false" class="btn-ghost min-h-11">Cancelar</button>
                    <button @click="crear" :disabled="procesando" class="btn-primary min-h-11">{{ procesando ? 'Creando…' : 'Crear' }}</button>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
        <AppPromptModal :cfg="modalMotivo" @cerrar="modalMotivo = null"/>
    </AppLayout>
</template>
