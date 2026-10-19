<script setup>
/* Las contrataciones en curso: lo que hay que cerrar. */
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ClipboardCheck, AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    contrataciones: { type: Object, required: true },
    filtro: { type: String, default: 'en_proceso' },
    en_proceso: { type: Number, default: 0 },
});

const estado = ref(props.filtro);
const filtrar = () => router.get('/app/rrhh/contrataciones', { estado: estado.value },
    { preserveState: true, replace: true });

const badge = (e) => ({
    en_proceso: 'bg-amber-100 text-amber-800',
    completada: 'bg-emerald-100 text-emerald-800',
    cancelada: 'bg-rose-100 text-rose-800',
}[e] || 'bg-surface-100');
</script>

<template>
    <Head title="Contrataciones" />
    <AppLayout>
        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <ClipboardCheck class="h-6 w-6 text-brand-600" />Contrataciones
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Entre «lo elegimos» y «ya trabaja acá»: examen médico, papeles, contrato y las cuatro afiliaciones.
                </p>
            </div>

            <div v-if="$page.props.flash?.success"
                class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>

            <div class="flex items-center gap-3 flex-wrap">
                <div class="card p-4 flex-1 min-w-[12rem]">
                    <div class="text-xs text-surface-500 uppercase font-semibold">En proceso</div>
                    <div class="text-3xl font-bold mt-1" :class="en_proceso ? 'text-amber-600' : 'text-surface-400'">
                        {{ en_proceso }}
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs font-semibold text-surface-500 uppercase">Estado</label>
                    <select v-model="estado" @change="filtrar" class="input text-sm">
                        <option value="en_proceso">En proceso</option>
                        <option value="completada">Completadas</option>
                        <option value="cancelada">Canceladas</option>
                        <option value="todas">Todas</option>
                    </select>
                </div>
            </div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-3">Persona</th>
                            <th class="text-left p-3">Cargo</th>
                            <th class="text-left p-3">Entra</th>
                            <th class="text-right p-3">Pendientes</th>
                            <th class="text-center p-3">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="c in contrataciones.data" :key="c.id" class="hover:bg-surface-50">
                            <td class="p-3 font-bold">
                                <Link :href="`/app/rrhh/contrataciones/${c.id}`" class="text-brand-600 hover:underline">
                                    {{ c.nombre }}
                                </Link>
                                <div class="text-xs text-surface-400">{{ c.documento || 'sin cédula' }}</div>
                            </td>
                            <td class="p-3">{{ c.cargo }} <span class="text-xs text-surface-400">· {{ c.area }}</span></td>
                            <td class="p-3 text-xs">{{ c.fecha_ingreso || '—' }}</td>
                            <td class="p-3 text-right">
                                <span v-if="c.frenada" class="inline-flex items-center gap-1 text-rose-600 font-bold text-xs">
                                    <AlertTriangle class="h-3.5 w-3.5" /> No apto
                                </span>
                                <span v-else-if="c.falta" class="font-bold text-amber-600">{{ c.falta }}</span>
                                <span v-else class="text-emerald-600 font-bold">Lista</span>
                            </td>
                            <td class="p-3 text-center">
                                <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(c.estado)]">
                                    {{ c.estado.replace('_', ' ') }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!contrataciones.data.length">
                            <td colspan="5" class="p-8 text-center text-surface-400 text-sm">
                                No hay contrataciones acá. Arrancan cuando el jefe elige de la terna.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
