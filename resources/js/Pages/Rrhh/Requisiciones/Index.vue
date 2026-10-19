<script setup>
/*
 * Solicitudes de personal.
 *
 * Esta pantalla la abren dos personas distintas y tiene que servirle a las dos:
 * el líder de área que pide y quiere saber en qué va lo suyo, y Gestión Humana
 * que recibe y tiene que decidir. Por eso el encabezado y la tabla cambian
 * según `esGestionHumana`, en vez de hacer dos pantallas que se desincronicen.
 */
import { ref, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { UserPlus, Plus, Clock, FileText, CheckCircle2, Download } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    requisiciones: { type: Object, required: true },
    esGestionHumana: { type: Boolean, default: false },
    kpis: { type: Object, default: () => ({}) },
    estados: { type: Array, default: () => [] },
});

const modal = ref(false);
const procesando = ref(false);
const errores = ref({});

const vacio = () => ({
    cargo: '', area: '', numero_vacantes: 1, motivo: '',
    funciones: '', competencias: '', estudios: '', experiencia: '',
    salario_min: null, salario_max: null, tipo_contrato: 'indefinido',
    fecha_requerida: null, observaciones: '',
});
const form = reactive(vacio());

const crear = () => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post('/app/rrhh/requisiciones', form, {
        onError: (e) => { errores.value = e; },
        onFinish: () => (procesando.value = false),
    });
};

const money = (n) => (n ? '$' + Number(n).toLocaleString('es-CO', { maximumFractionDigits: 0 }) : '—');

const badge = (color) => ({
    gray: 'bg-surface-200 text-surface-700',
    warning: 'bg-amber-100 text-amber-800',
    success: 'bg-emerald-100 text-emerald-800',
    danger: 'bg-rose-100 text-rose-800',
}[color] || 'bg-surface-100 text-surface-700');

const filtro = ref(new URLSearchParams(window.location.search).get('estado') || '');
const filtrar = () => router.get('/app/rrhh/requisiciones',
    filtro.value ? { estado: filtro.value } : {},
    { preserveState: true, replace: true });

const hayFilas = computed(() => props.requisiciones.data.length > 0);
</script>

<template>
    <Head title="Solicitudes de personal" />
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <UserPlus class="h-6 w-6 text-brand-600" />Solicitudes de personal
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        {{ esGestionHumana
                            ? 'Lo que piden los líderes de área. Acá se aprueba y se abre la vacante.'
                            : 'Pedí el personal que necesita tu área y seguí en qué va, sin preguntarle a nadie.' }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <!-- El líder saca las suyas; Gestión Humana, todas. -->
                    <a href="/app/rrhh/exportar/requisiciones" class="btn-secondary">
                        <Download class="h-4 w-4" /> Excel
                    </a>
                    <button @click="modal = true" class="btn-primary"><Plus class="h-4 w-4" /> Nueva solicitud</button>
                </div>
            </div>

            <div v-if="$page.props.flash?.success"
                class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>

            <!-- Tarjetas de arriba: lo que cada quien necesita saber de un vistazo -->
            <div class="grid gap-3" :class="esGestionHumana ? 'sm:grid-cols-4' : 'sm:grid-cols-2'">
                <div v-if="esGestionHumana" class="card p-4">
                    <div class="text-xs text-surface-500 uppercase font-semibold flex items-center gap-1">
                        <Clock class="h-3.5 w-3.5" /> Esperan decisión
                    </div>
                    <div class="text-3xl font-bold mt-1" :class="kpis.por_aprobar ? 'text-amber-600' : 'text-surface-400'">
                        {{ kpis.por_aprobar ?? 0 }}
                    </div>
                </div>
                <div v-if="esGestionHumana" class="card p-4">
                    <div class="text-xs text-surface-500 uppercase font-semibold">La más vieja lleva</div>
                    <div class="text-3xl font-bold mt-1" :class="kpis.espera_dias > 3 ? 'text-rose-600' : 'text-surface-700'">
                        {{ kpis.espera_dias ?? 0 }} <span class="text-base font-normal">día(s)</span>
                    </div>
                </div>
                <div class="card p-4">
                    <div class="text-xs text-surface-500 uppercase font-semibold flex items-center gap-1">
                        <FileText class="h-3.5 w-3.5" /> Mis borradores
                    </div>
                    <div class="text-3xl font-bold mt-1 text-surface-700">{{ kpis.mias_borrador ?? 0 }}</div>
                    <div class="text-xs text-surface-400">Sin enviar · no las ve nadie todavía</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs text-surface-500 uppercase font-semibold flex items-center gap-1">
                        <CheckCircle2 class="h-3.5 w-3.5" /> Mías en trámite
                    </div>
                    <div class="text-3xl font-bold mt-1 text-brand-600">{{ kpis.mias_en_tramite ?? 0 }}</div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <label class="text-xs font-semibold text-surface-500 uppercase">Estado</label>
                <select v-model="filtro" @change="filtrar" class="input text-sm">
                    <option value="">Todos</option>
                    <option v-for="e in estados" :key="e.valor" :value="e.valor">{{ e.label }}</option>
                </select>
            </div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-3">Número</th>
                            <th class="text-left p-3">Cargo</th>
                            <th class="text-left p-3">Área</th>
                            <th v-if="esGestionHumana" class="text-left p-3">Solicita</th>
                            <th class="text-right p-3">Vacantes</th>
                            <th class="text-right p-3">Salario</th>
                            <th class="text-left p-3">Se necesita</th>
                            <th class="text-center p-3">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="x in requisiciones.data" :key="x.id" class="hover:bg-surface-50">
                            <td class="p-3 font-bold">
                                <Link :href="`/app/rrhh/requisiciones/${x.id}`" class="text-brand-600 hover:underline">
                                    {{ x.numero }}
                                </Link>
                                <div class="text-xs text-surface-400">{{ x.creada }}</div>
                            </td>
                            <td class="p-3 font-semibold">{{ x.cargo }}</td>
                            <td class="p-3">{{ x.area || '—' }}</td>
                            <td v-if="esGestionHumana" class="p-3 text-xs">{{ x.solicitante || '—' }}</td>
                            <td class="p-3 text-right font-bold">
                                {{ x.vacantes_abiertas }}/{{ x.numero_vacantes }}
                            </td>
                            <td class="p-3 text-right text-xs">
                                {{ money(x.salario_min) }}<span v-if="x.salario_max"> - {{ money(x.salario_max) }}</span>
                            </td>
                            <td class="p-3 text-xs">{{ x.fecha_requerida || '—' }}</td>
                            <td class="p-3 text-center">
                                <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(x.estado_color)]">
                                    {{ x.estado_label }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!hayFilas">
                            <td :colspan="esGestionHumana ? 8 : 7" class="p-8 text-center text-surface-400 text-sm">
                                Todavía no hay solicitudes.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="modal" @click.self="modal = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-3xl w-full max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-bold">Nueva solicitud de personal</h3>
                <p class="text-xs text-surface-500 mb-4">
                    Queda en borrador. Nadie la ve hasta que la envíes desde su detalle.
                </p>

                <div class="space-y-3">
                    <div class="grid sm:grid-cols-3 gap-2">
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold">Cargo que se necesita *</label>
                            <input v-model="form.cargo" class="input w-full" autofocus placeholder="Auxiliar de bodega" />
                            <p v-if="errores.cargo" class="text-xs text-rose-600 mt-1">{{ errores.cargo }}</p>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">¿Cuántas personas? *</label>
                            <input type="number" min="1" v-model.number="form.numero_vacantes" class="input w-full" />
                            <p v-if="errores.numero_vacantes" class="text-xs text-rose-600 mt-1">{{ errores.numero_vacantes }}</p>
                        </div>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs font-semibold">Área</label>
                            <input v-model="form.area" class="input w-full" placeholder="Bodega, Ventas, Contabilidad..." />
                        </div>
                        <div>
                            <label class="text-xs font-semibold">¿Para cuándo se necesita?</label>
                            <input type="date" v-model="form.fecha_requerida" class="input w-full" />
                        </div>
                    </div>

                    <div>
                        <label class="text-xs font-semibold">¿Por qué se necesita?</label>
                        <textarea v-model="form.motivo" rows="2" class="input w-full"
                            placeholder="Reemplazo de quien renunció, cargo nuevo, aumento de operación..."></textarea>
                    </div>

                    <div>
                        <label class="text-xs font-semibold">Funciones del cargo</label>
                        <textarea v-model="form.funciones" rows="3" class="input w-full"
                            placeholder="Qué va a hacer esta persona en el día a día"></textarea>
                    </div>

                    <div>
                        <label class="text-xs font-semibold">Competencias y habilidades</label>
                        <textarea v-model="form.competencias" rows="2" class="input w-full"></textarea>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs font-semibold">Estudios</label>
                            <input v-model="form.estudios" class="input w-full" placeholder="Bachiller, técnico, profesional..." />
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Experiencia</label>
                            <input v-model="form.experiencia" class="input w-full" placeholder="1 año en cargos similares" />
                        </div>
                    </div>

                    <div class="grid sm:grid-cols-3 gap-2">
                        <div>
                            <label class="text-xs font-semibold">Salario desde</label>
                            <input type="number" step="1000" v-model.number="form.salario_min" class="input w-full" />
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Salario hasta</label>
                            <input type="number" step="1000" v-model.number="form.salario_max" class="input w-full" />
                            <p v-if="errores.salario_max" class="text-xs text-rose-600 mt-1">{{ errores.salario_max }}</p>
                        </div>
                        <div>
                            <label class="text-xs font-semibold">Tipo de contrato</label>
                            <select v-model="form.tipo_contrato" class="input w-full">
                                <option value="indefinido">Indefinido</option>
                                <option value="fijo">Término fijo</option>
                                <option value="obra_labor">Obra o labor</option>
                                <option value="aprendiz">Aprendiz SENA</option>
                                <option value="prestacion">Prestación de servicios</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="text-xs font-semibold">Observaciones</label>
                        <textarea v-model="form.observaciones" rows="2" class="input w-full"></textarea>
                    </div>
                </div>

                <div class="flex justify-end gap-2 mt-5">
                    <button @click="modal = false" class="btn-secondary">Cancelar</button>
                    <button @click="crear" :disabled="procesando || !form.cargo" class="btn-primary">
                        {{ procesando ? 'Guardando...' : 'Crear borrador' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
