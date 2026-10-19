<script setup>
import { ref, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Users, Plus, GraduationCap, Download } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ empleados: { type: Object, required: true }, filtro: { type: String, default: 'activo' } });

const modal = ref(false);
const form = reactive({
    nombre: '', tipo_documento: 'CC', numero_documento: '', email: '', telefono: '',
    cargo: '', area: '', tipo_contrato: 'indefinido', salario: null, fecha_ingreso: new Date().toISOString().slice(0, 10),
});
const procesando = ref(false);
const crear = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post('/app/rrhh/empleados', form, {
        preserveScroll: true,
        onSuccess: () => { modal.value = false; Object.assign(form, { nombre: '', numero_documento: '', email: '', telefono: '', cargo: '', area: '' }); },
        onFinish: () => procesando.value = false,
    });
};
/*
 * El selector de inducción de esta tabla se fue en F4.
 *
 * Movía el estado a «completada» sin fecha, sin responsable y sin qué se vio,
 * que para una inducción en seguridad y salud en el trabajo es justo lo que
 * hay que poder demostrar. Ahora se registra desde la ficha de cada quien.
 */
const filtrar = (f) => router.get('/app/rrhh/empleados', { estado: f });
const money = (n) => n ? '$' + Number(n).toLocaleString('es-CO', { maximumFractionDigits: 0 }) : '—';
const badgeInd = (e) => ({ pendiente: 'bg-amber-100 text-amber-800', en_curso: 'bg-blue-100 text-blue-800', completada: 'bg-emerald-100 text-emerald-800' }[e] || 'bg-surface-100');
</script>

<template>
    <Head title="Empleados"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between">
                <h1 class="text-2xl font-bold flex items-center gap-2"><Users class="h-6 w-6 text-brand-600"/>Empleados</h1>
                <div class="flex gap-2">
                    <!-- El filtro de arriba manda: se exporta lo que se ve. -->
                    <a :href="`/app/rrhh/exportar/empleados?estado=${filtro}`" class="btn-secondary">
                        <Download class="h-4 w-4"/> Excel
                    </a>
                    <button @click="modal = true" class="btn-primary"><Plus class="h-4 w-4"/> Nuevo empleado</button>
                </div>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">{{ $page.props.flash.success }}</div>

            <div class="card p-3 flex items-center gap-2">
                <span class="text-xs uppercase font-bold text-surface-500">Filtro:</span>
                <button v-for="f in ['activo','retirado','suspendido']" :key="f" @click="filtrar(f)"
                    :class="['px-3 py-1 rounded text-sm capitalize', filtro === f ? 'bg-brand-600 text-white' : 'bg-surface-100 hover:bg-surface-200']">
                    {{ f }}
                </button>
            </div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-3">Empleado</th>
                            <th class="text-left p-3">Cargo · Área</th>
                            <th class="text-left p-3">Contrato</th>
                            <th class="text-right p-3">Salario</th>
                            <th class="text-left p-3">Ingreso</th>
                            <th class="text-center p-3">Inducción</th>
                            <th class="text-center p-3">Prueba</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="e in empleados.data" :key="e.id" class="hover:bg-surface-50">
                            <td class="p-3">
                                <Link :href="`/app/rrhh/empleados/${e.id}`" class="font-bold text-brand-600 hover:underline">
                                    {{ e.nombre }}
                                </Link>
                                <div class="text-xs text-surface-500">{{ e.documento }} · {{ e.email }}</div>
                            </td>
                            <td class="p-3">
                                <div class="font-medium">{{ e.cargo }}</div>
                                <div class="text-xs text-surface-500">{{ e.area }}</div>
                            </td>
                            <td class="p-3 text-xs">{{ e.tipo_contrato }}</td>
                            <td class="p-3 text-right">{{ money(e.salario) }}</td>
                            <td class="p-3 text-xs">{{ e.ingreso }}</td>
                            <td class="p-3 text-center">
                                <Link :href="`/app/rrhh/empleados/${e.id}`"
                                    :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badgeInd(e.induccion)]">
                                    {{ e.induccion }}
                                </Link>
                            </td>
                            <td class="p-3 text-center text-xs">
                                <span v-if="e.prueba_dias === null" class="text-surface-400">—</span>
                                <span v-else-if="e.prueba_dias < 0" class="text-rose-600 font-bold">Vencido</span>
                                <span v-else :class="e.prueba_dias <= 15 ? 'text-amber-600 font-bold' : 'text-surface-500'">
                                    {{ e.prueba_dias }} día(s)
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="modal" @click.self="modal = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-bold mb-3">Nuevo empleado</h3>
                <div class="space-y-3">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <div><label class="text-xs font-semibold">Nombre completo</label><input v-model="form.nombre" class="input w-full" autofocus/></div>
                        <div class="grid grid-cols-3 gap-2">
                            <div><label class="text-xs font-semibold">Doc</label>
                                <select v-model="form.tipo_documento" class="input w-full">
                                    <option>CC</option><option>CE</option><option>PA</option><option>NIT</option>
                                </select>
                            </div>
                            <div class="col-span-2"><label class="text-xs font-semibold">Número</label><input v-model="form.numero_documento" class="input w-full"/></div>
                        </div>
                        <div><label class="text-xs font-semibold">Email</label><input v-model="form.email" type="email" class="input w-full"/></div>
                        <div><label class="text-xs font-semibold">Teléfono</label><input v-model="form.telefono" class="input w-full"/></div>
                        <div><label class="text-xs font-semibold">Cargo</label><input v-model="form.cargo" class="input w-full"/></div>
                        <div><label class="text-xs font-semibold">Área</label><input v-model="form.area" class="input w-full"/></div>
                        <div><label class="text-xs font-semibold">Tipo contrato</label>
                            <select v-model="form.tipo_contrato" class="input w-full">
                                <option value="indefinido">Indefinido</option><option value="obra_labor">Obra/Labor</option>
                                <option value="prestacion_servicios">Prestación</option><option value="aprendizaje">Aprendizaje</option>
                                <option value="temporal">Temporal</option>
                            </select>
                        </div>
                        <div><label class="text-xs font-semibold">Salario</label><input type="number" step="1000" v-model.number="form.salario" class="input w-full"/></div>
                        <div><label class="text-xs font-semibold">Fecha ingreso</label><input type="date" v-model="form.fecha_ingreso" class="input w-full"/></div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modal = false" class="btn-ghost">Cancelar</button>
                    <button @click="crear" :disabled="procesando" class="btn-primary">{{ procesando ? '…' : 'Registrar' }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
