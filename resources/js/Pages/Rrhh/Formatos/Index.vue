<script setup>
/*
 * Fase G · Listado maestro de formatos.
 *
 * Es la ficha de control documental de cada formato que el ERP imprime. Lo
 * que se escriba acá sale en el recuadro de arriba de todos los impresos
 * desde el minuto siguiente: antes esos tres datos estaban dentro de las
 * plantillas y subir una versión obligaba a tocar código.
 *
 * No se pueden crear ni borrar renglones a propósito: la lista es la de los
 * formatos que el sistema sabe imprimir. Un renglón sin plantilla sería un
 * formato que no existe.
 */
import { ref, reactive, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { FileCheck2, Pencil, AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    formatos: { type: Array, default: () => [] },
    procesos: { type: Array, default: () => [] },
    sin_codigo: { type: Number, default: 0 },
});

const procesando = ref(false);
const errores = ref({});
const editando = ref(null);

const form = reactive({ codigo: '', nombre: '', proceso: '', version: '', fecha_version: '' });

const abrir = (f) => {
    editando.value = f;
    errores.value = {};
    Object.assign(form, {
        codigo: f.codigo ?? '',
        nombre: f.nombre,
        proceso: f.proceso,
        version: f.version,
        fecha_version: f.fecha_version ?? '',
    });
};

const cerrar = () => { editando.value = null; };

const guardar = () => {
    procesando.value = true;
    router.put(`/app/rrhh/formatos/${editando.value.id}`, { ...form }, {
        preserveScroll: true,
        onSuccess: cerrar,
        onError: (e) => { errores.value = e; },
        onFinish: () => { procesando.value = false; },
    });
};

/* Agrupados por proceso, que es como se lee un listado maestro. */
const porProceso = computed(() => {
    const mapa = {};
    props.formatos.forEach((f) => {
        (mapa[f.proceso] ??= []).push(f);
    });
    return mapa;
});
</script>

<template>
    <Head title="Listado maestro de formatos" />
    <AppLayout>
        <div class="space-y-5 max-w-5xl">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <FileCheck2 class="h-6 w-6 text-brand-600" />Listado maestro de formatos
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    El código, la versión y la fecha que llevan arriba los formatos que imprime el ERP.
                    Lo que se cambie acá sale en el siguiente impreso.
                </p>
            </div>

            <div v-if="sin_codigo"
                class="p-3 rounded bg-amber-500/10 border-l-4 border-amber-500 text-sm text-amber-800 flex gap-2">
                <AlertTriangle class="h-5 w-5 shrink-0" />
                <span>
                    <strong>{{ sin_codigo }}</strong>
                    {{ sin_codigo === 1 ? 'formato sigue' : 'formatos siguen' }} sin código asignado.
                    Se imprimen diciendo «sin asignar»: el sistema no inventa uno porque parecería válido.
                </span>
            </div>

            <div v-for="(lista, proceso) in porProceso" :key="proceso" class="card">
                <h2 class="text-xs uppercase tracking-wide text-surface-500 font-semibold mb-2">
                    Proceso de {{ proceso }}
                </h2>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-surface-400 border-b">
                            <th class="py-2 w-28">Código</th>
                            <th class="py-2">Formato</th>
                            <th class="py-2 w-20">Versión</th>
                            <th class="py-2 w-28">Fecha</th>
                            <th class="py-2 w-44">Última actualización</th>
                            <th class="py-2 w-10"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="f in lista" :key="f.id" class="border-b last:border-0">
                            <td class="py-2 font-mono">
                                <span v-if="f.codigo">{{ f.codigo }}</span>
                                <span v-else class="text-rose-600 text-xs">sin asignar</span>
                            </td>
                            <td class="py-2">{{ f.nombre }}</td>
                            <td class="py-2">{{ f.version }}</td>
                            <td class="py-2">{{ f.fecha_version ?? '—' }}</td>
                            <td class="py-2 text-xs text-surface-500">
                                <span v-if="f.actualizado">{{ f.actualizado }} · {{ f.actualizado_por ?? '—' }}</span>
                                <span v-else>como vino del área</span>
                            </td>
                            <td class="py-2 text-right">
                                <button @click="abrir(f)" class="btn-secondary text-xs py-1 px-2">
                                    <Pencil class="h-3.5 w-3.5" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Editar la ficha de control de un formato -->
        <div v-if="editando" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4"
            @click.self="cerrar">
            <div class="bg-white dark:bg-surface-900 rounded-lg shadow-xl w-full max-w-lg p-5 space-y-3">
                <h3 class="font-semibold">{{ editando.nombre }}</h3>
                <p class="text-xs text-surface-500">
                    Esto es lo que va en el recuadro de control del impreso.
                </p>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs uppercase text-surface-500">Código</label>
                        <input v-model="form.codigo" class="input w-full mt-1" placeholder="GH-F-0XX" />
                        <p v-if="errores.codigo" class="text-xs text-rose-600 mt-1">{{ errores.codigo }}</p>
                    </div>
                    <div>
                        <label class="text-xs uppercase text-surface-500">Versión</label>
                        <input v-model="form.version" class="input w-full mt-1" placeholder="001" />
                        <p v-if="errores.version" class="text-xs text-rose-600 mt-1">{{ errores.version }}</p>
                    </div>
                </div>

                <div>
                    <label class="text-xs uppercase text-surface-500">Nombre del formato</label>
                    <input v-model="form.nombre" class="input w-full mt-1" />
                    <p v-if="errores.nombre" class="text-xs text-rose-600 mt-1">{{ errores.nombre }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs uppercase text-surface-500">Proceso</label>
                        <select v-model="form.proceso" class="input w-full mt-1">
                            <option v-for="p in procesos" :key="p" :value="p">{{ p }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs uppercase text-surface-500">Fecha de la versión</label>
                        <input v-model="form.fecha_version" type="date" class="input w-full mt-1" />
                        <p v-if="errores.fecha_version" class="text-xs text-rose-600 mt-1">
                            {{ errores.fecha_version }}
                        </p>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button @click="cerrar" class="btn-secondary">Cancelar</button>
                    <button @click="guardar" :disabled="procesando" class="btn-primary">
                        {{ procesando ? 'Guardando…' : 'Guardar' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
