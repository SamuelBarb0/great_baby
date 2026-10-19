<script setup>
/*
 * Mi equipo · la gente que entró por lo que este jefe pidió.
 *
 * Es deliberadamente poco: nombre, cargo, cuándo entró y cómo va el período de
 * prueba. NO el salario, que es de Gestión Humana. Lo único que él hace acá es
 * dar su concepto al final de la prueba, que es la mitad que faltaba de
 * «validado por el jefe del área y por gestión humana».
 */
import { ref, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Users, Clock, AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    equipo: { type: Array, default: () => [] },
    pendientes: { type: Number, default: 0 },
});

const procesando = ref(false);
const errores = ref({});

const modal = ref(null);
const concepto = reactive({ resultado: 'continua', observacion: '' });

const guardar = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post(`/app/rrhh/mi-equipo/${modal.value.id}/concepto`, { ...concepto }, {
        preserveScroll: true,
        onSuccess: () => { modal.value = null; Object.assign(concepto, { resultado: 'continua', observacion: '' }); },
        onError: (e) => (errores.value = e),
        onFinish: () => (procesando.value = false),
    });
};

const tono = (e) => {
    if (e.cerrado) return 'text-surface-400';
    if (e.prueba_dias === null) return 'text-surface-400';
    if (e.prueba_dias < 0) return 'text-rose-600 font-bold';
    return e.prueba_dias <= 15 ? 'text-amber-600 font-bold' : 'text-surface-600';
};
</script>

<template>
    <Head title="Mi equipo" />
    <AppLayout>
        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Users class="h-6 w-6 text-brand-600" />Mi equipo
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    La gente que entró por las solicitudes de personal que hiciste.
                </p>
            </div>

            <div v-if="$page.props.flash?.success"
                class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>

            <div v-if="pendientes" class="p-3 rounded-lg bg-amber-500/10 border-l-4 border-amber-500 text-amber-800 text-sm">
                Tenés <strong>{{ pendientes }}</strong> concepto(s) de período de prueba sin dar.
                Gestión Humana no puede cerrar el período hasta que opines.
            </div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-3">Persona</th>
                            <th class="text-left p-3">Cargo</th>
                            <th class="text-left p-3">Entró</th>
                            <th class="text-left p-3">Período de prueba</th>
                            <th class="text-left p-3">Mi concepto</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="e in equipo" :key="e.id" class="hover:bg-surface-50">
                            <td class="p-3 font-bold">{{ e.nombre }}</td>
                            <td class="p-3">{{ e.cargo }} <span class="text-xs text-surface-400">· {{ e.area }}</span></td>
                            <td class="p-3 text-xs">{{ e.ingreso }}</td>
                            <td class="p-3 text-xs" :class="tono(e)">
                                <span v-if="e.cerrado">
                                    Cerrado · {{ e.resultado_final === 'continua' ? 'continúa' : 'no continuó' }}
                                </span>
                                <span v-else-if="!e.prueba_hasta">Sin fijar</span>
                                <span v-else-if="e.prueba_dias < 0" class="inline-flex items-center gap-1">
                                    <AlertTriangle class="h-3.5 w-3.5" /> Vencido hace {{ Math.abs(e.prueba_dias) }} día(s)
                                </span>
                                <span v-else class="inline-flex items-center gap-1">
                                    <Clock class="h-3.5 w-3.5" /> {{ e.prueba_dias }} día(s) · hasta {{ e.prueba_hasta }}
                                </span>
                            </td>
                            <td class="p-3">
                                <button v-if="e.debo_conceptuar" @click="modal = e" :disabled="procesando"
                                    class="btn-primary text-xs py-1 px-3">Dar mi concepto</button>
                                <span v-else-if="e.mi_concepto" class="text-xs"
                                    :class="e.mi_concepto === 'continua' ? 'text-emerald-700' : 'text-rose-600'">
                                    {{ e.mi_concepto === 'continua' ? 'Continúa' : 'No continúa' }}
                                    <span class="text-surface-400">· {{ e.mi_concepto_at }}</span>
                                </span>
                                <span v-else class="text-xs text-surface-400">—</span>
                            </td>
                        </tr>
                        <tr v-if="!equipo.length">
                            <td colspan="5" class="p-8 text-center text-surface-400 text-sm">
                                Todavía no ha entrado nadie por tus solicitudes.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="modal" @click.self="modal = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full">
                <h3 class="text-lg font-bold">Período de prueba de {{ modal.nombre }}</h3>
                <p class="text-xs text-surface-500 mb-3">
                    Tu concepto. Gestión Humana decide después, viendo lo que opinaste.
                </p>
                <select v-model="concepto.resultado" class="input w-full">
                    <option value="continua">Sí, que continúe</option>
                    <option value="no_continua">No continúa</option>
                </select>
                <div class="mt-3">
                    <label class="text-xs font-semibold">
                        Observación
                        <span v-if="concepto.resultado === 'no_continua'" class="text-rose-600">· obligatoria</span>
                    </label>
                    <textarea v-model="concepto.observacion" rows="3" class="input w-full"
                        placeholder="Cómo le fue en el puesto, qué hace bien, qué le falta..."></textarea>
                    <p v-if="errores.observacion" class="text-xs text-rose-600 mt-1">{{ errores.observacion }}</p>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modal = null" class="btn-secondary">Cancelar</button>
                    <button @click="guardar"
                        :disabled="procesando || (concepto.resultado === 'no_continua' && !concepto.observacion)"
                        class="btn-primary">Guardar concepto</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
