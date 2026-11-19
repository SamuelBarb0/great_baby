<script setup>
/*
 * Maestro de métodos de pago.
 *
 * Sólo existía en el panel Filament. De acá salen las opciones del formulario
 * de pagos y la cuenta PUC con que se contabiliza cada cobro.
 */
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { CreditCard, Plus, X, Trash2, Pencil, AlertTriangle, ExternalLink } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasConfigContable } from '@/composables/usePestanasContables';
const props = defineProps({
    metodos: { type: Array, required: true },
    tipos: { type: Array, required: true },
});

const vacio = () => ({
    id: null, codigo: '', nombre: '', tipo: 'transferencia',
    requiere_referencia: false, requiere_banco: false, requiere_comprobante: false,
    cuenta_puc: '', siigo_payment_type_id: '', siigo_payment_type_nombre: '',
    activo: true, orden: 0,
});

/*
 * Mapeo a SIIGO.
 *
 * El recibo de caja salía siempre con el mismo tipo de pago, así que en SIIGO
 * una transferencia y un cobro en efectivo entraban igual y la conciliación de
 * bancos contra caja no cuadraba. Acá se elige a qué tipo de SIIGO entra cada
 * método, con la lista traída en vivo de SIIGO.
 */
const tiposSiigo = ref([]);
const cargandoTipos = ref(false);
const errorTipos = ref('');
const filtroTipo = ref('');

const cargarTiposSiigo = async (forzar = false) => {
    if ((tiposSiigo.value.length && ! forzar) || cargandoTipos.value) return;
    cargandoTipos.value = true;
    errorTipos.value = '';
    try {
        const r = await fetch('/app/cartera/metodos-pago/tipos-siigo', { headers: { Accept: 'application/json' } });
        const j = await r.json();
        tiposSiigo.value = j.tipos || [];
        if (! j.ok) errorTipos.value = j.error || 'SIIGO no respondió.';
    } catch (e) {
        errorTipos.value = 'No se pudo consultar SIIGO.';
    } finally {
        cargandoTipos.value = false;
    }
};

/*
 * El ambiente de pruebas de SIIGO es COMPARTIDO: la lista trae decenas de
 * formas de pago de otras empresas (Samesu, sHUB, Lizit…). Por eso el buscador:
 * encontrar la nuestra entre 60 ajenas a ojo es incómodo. En el SIIGO real de
 * Aracely la lista va a ser sólo la suya.
 *
 * La API de SIIGO no deja CREAR formas de pago (POST /v1/payment-types
 * responde 404, sólo se pueden listar), así que la nuestra se crea en el
 * portal de SIIGO y acá se refresca la lista para elegirla.
 */
const tiposFiltrados = computed(() => {
    const q = filtroTipo.value.trim().toLowerCase();
    if (! q) return tiposSiigo.value;
    return tiposSiigo.value.filter(t =>
        t.nombre.toLowerCase().includes(q) || String(t.id).includes(q));
});

// Al elegir se guarda también el nombre, para mostrarlo sin volver a SIIGO.
const alElegirTipoSiigo = () => {
    const t = tiposSiigo.value.find(x => x.id === Number(form.value.siigo_payment_type_id));
    form.value.siigo_payment_type_nombre = t?.nombre || '';
};

const form = ref(vacio());
const abierto = ref(false);
const guardando = ref(false);
const editando = computed(() => !! form.value.id);

// El código es la llave con que los pagos ya registrados apuntan al método.
const codigoBloqueado = computed(() => {
    if (! editando.value) return false;
    const m = props.metodos.find(x => x.id === form.value.id);
    return !! m && m.usos > 0;
});

const abrirNuevo = () => { form.value = vacio(); abierto.value = true; cargarTiposSiigo(); };
const abrirEditar = (m) => { form.value = { ...m }; abierto.value = true; cargarTiposSiigo(); };

// Sugerir el código a partir del nombre, sin tildes ni espacios.
const sugerirCodigo = () => {
    if (editando.value || form.value.codigo) return;
    form.value.codigo = form.value.nombre
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
};

const puedeGuardar = computed(() =>
    form.value.codigo.trim() !== '' && form.value.nombre.trim() !== '' && ! guardando.value);

const guardar = () => {
    if (! puedeGuardar.value) return;
    guardando.value = true;
    const url = editando.value ? `/app/cartera/metodos-pago/${form.value.id}` : '/app/cartera/metodos-pago';
    router.post(url, { ...form.value }, {
        preserveScroll: true,
        onSuccess: () => { abierto.value = false; form.value = vacio(); },
        onFinish: () => { guardando.value = false; },
    });
};

const eliminar = (m) => {
    router.delete(`/app/cartera/metodos-pago/${m.id}`, { preserveScroll: true });
};

const tipoLabel = (v) => props.tipos.find(t => t.valor === v)?.label || v;
</script>

<template>
    <Head title="Métodos de pago"/>
    <AppLayout>
        <div class="space-y-4 max-w-5xl">
            <PestanasSeccion :pestanas="pestanasConfigContable"/>

            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <CreditCard class="h-6 w-6 text-brand-600"/> Métodos de pago
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Las formas en que entra la plata. Son las opciones que aparecen al registrar un pago.
                    </p>
                </div>
                <button @click="abrirNuevo" class="btn-primary text-sm min-h-11">
                    <Plus class="h-4 w-4"/> Nuevo método
                </button>
            </div>

            <!-- Alta / edición -->
            <div v-if="abierto" class="card p-4 border-2 border-brand-300 dark:border-brand-800 space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-bold">{{ editando ? 'Editar método' : 'Nuevo método' }}</h2>
                    <button @click="abierto = false" class="btn-ghost p-2 min-h-11" aria-label="Cerrar">
                        <X class="h-4 w-4"/>
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Nombre *</label>
                        <input v-model="form.nombre" @blur="sugerirCodigo" class="input w-full min-h-11" maxlength="80"
                               placeholder="Transferencia Bancolombia"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Código *</label>
                        <input v-model="form.codigo" class="input w-full min-h-11 font-mono text-sm"
                               :disabled="codigoBloqueado" maxlength="40"/>
                        <p v-if="codigoBloqueado" class="text-xs text-amber-600 mt-0.5">
                            Ya tiene pagos registrados: el código no se puede cambiar.
                        </p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Tipo</label>
                        <select v-model="form.tipo" class="input w-full min-h-11">
                            <option v-for="t in tipos" :key="t.valor" :value="t.valor">{{ t.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Cuenta PUC</label>
                        <input v-model="form.cuenta_puc" class="input w-full min-h-11 font-mono text-sm" maxlength="20"
                               placeholder="11100501"/>
                        <p class="text-xs text-surface-500 mt-0.5">Con esta cuenta se contabiliza el cobro.</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Orden en la lista</label>
                        <input v-model.number="form.orden" type="number" min="0" max="999" class="input w-full min-h-11"/>
                    </div>
                    <div class="md:col-span-3">
                        <div class="flex items-end justify-between gap-2 flex-wrap">
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Tipo de pago en SIIGO</label>
                            <button type="button" @click="cargarTiposSiigo(true)" :disabled="cargandoTipos"
                                    class="text-xs text-brand-600 hover:underline disabled:opacity-40">
                                {{ cargandoTipos ? 'Consultando…' : '↻ Actualizar lista desde SIIGO' }}
                            </button>
                        </div>

                        <input v-model="filtroTipo" class="input w-full min-h-11 mt-1"
                               placeholder="Buscá la tuya: escribí parte del nombre o el número…"/>

                        <select v-model="form.siigo_payment_type_id" @change="alElegirTipoSiigo"
                                class="input w-full min-h-11 mt-2" :disabled="cargandoTipos" size="1">
                            <option value="">{{ cargandoTipos ? 'Consultando SIIGO…' : '— Sin mapear (usa el global) —' }}</option>
                            <option v-for="t in tiposFiltrados" :key="t.id" :value="t.id">{{ t.nombre }} · {{ t.id }}</option>
                        </select>

                        <p v-if="errorTipos" class="text-xs text-red-600 mt-1">{{ errorTipos }}</p>
                        <div v-else class="text-xs text-surface-500 mt-1 space-y-0.5">
                            <p>
                                Define a qué tipo entra el recibo en SIIGO. Sin esto, todos los cobros caen en el mismo
                                y la conciliación de bancos contra caja no cuadra.
                            </p>
                            <p v-if="tiposSiigo.length">
                                {{ tiposFiltrados.length }} de {{ tiposSiigo.length }} formas de pago.
                                <strong>El ambiente de pruebas de SIIGO es compartido</strong>, por eso aparecen las de
                                otras empresas. En el SIIGO real de la empresa sólo van a estar las propias.
                            </p>
                        </div>

                        <!-- La API de SIIGO no deja crear formas de pago (POST
                             /v1/payment-types responde 404), así que hay que
                             crearla a mano allá. Se deja la ruta exacta para no
                             tener que buscarla. -->
                        <div class="mt-3 p-3 rounded-lg bg-brand-50 dark:bg-brand-950/30 border-l-4 border-brand-500 text-xs space-y-1">
                            <div class="font-bold text-brand-800 dark:text-brand-200">
                                ¿No encontrás la tuya? Hay que crearla en SIIGO
                            </div>
                            <p class="text-surface-600 dark:text-surface-300">
                                La forma de pago se crea en los dos lados: <strong>primero en SIIGO</strong> y después
                                acá, eligiéndola en esta lista. La API de SIIGO no permite crearlas desde el ERP.
                            </p>
                            <p class="text-surface-600 dark:text-surface-300">
                                En SIIGO: <strong>Configuración → Contabilidad → Generales → Formas de pago en
                                documentos</strong>. Poné el mismo nombre que usás acá y, en «Cuenta contable»,
                                la misma cuenta PUC de arriba (<span class="font-mono">{{ form.cuenta_puc || '—' }}</span>)
                                para que el asiento quede igual de los dos lados.
                            </p>
                            <p>
                                <a href="https://siigonube.portaldeclientes.siigo.com/crear-formas-de-pago/"
                                   target="_blank" rel="noopener"
                                   class="text-brand-600 font-semibold hover:underline inline-flex items-center gap-1">
                                    Guía de SIIGO para crear formas de pago
                                    <ExternalLink class="h-3 w-3"/>
                                </a>
                            </p>
                            <p class="text-surface-500">
                                Cuando ya esté creada allá, tocá «Actualizar lista desde SIIGO» y aparecerá acá.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                    <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800">
                        <input type="checkbox" v-model="form.requiere_referencia" class="h-4 w-4"/>
                        <span class="text-sm">Pide referencia</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800">
                        <input type="checkbox" v-model="form.requiere_banco" class="h-4 w-4"/>
                        <span class="text-sm">Pide banco</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800">
                        <input type="checkbox" v-model="form.requiere_comprobante" class="h-4 w-4"/>
                        <span class="text-sm">Pide comprobante</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800">
                        <input type="checkbox" v-model="form.activo" class="h-4 w-4"/>
                        <span class="text-sm">Activo</span>
                    </label>
                </div>

                <div class="flex justify-end gap-2">
                    <button @click="abierto = false" class="btn-ghost min-h-11">Cancelar</button>
                    <button @click="guardar" :disabled="! puedeGuardar" class="btn-primary min-h-11 disabled:opacity-40">
                        {{ guardando ? 'Guardando…' : 'Guardar' }}
                    </button>
                </div>
            </div>

            <!-- Listado -->
            <div class="card overflow-hidden">
                <div v-if="! metodos.length" class="p-10 text-center text-surface-500">
                    Todavía no hay métodos de pago cargados.
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900 border-b border-surface-200 dark:border-surface-800">
                            <tr>
                                <th class="p-3 text-left">Método</th>
                                <th class="p-3 text-left">Tipo</th>
                                <th class="p-3 text-left">Pide</th>
                                <th class="p-3 text-left">Cuenta PUC</th>
                                <th class="p-3 text-left">Tipo en SIIGO</th>
                                <th class="p-3 text-right">Pagos</th>
                                <th class="p-3 text-center">Estado</th>
                                <th class="p-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-for="m in metodos" :key="m.id" class="hover:bg-brand-50/40 dark:hover:bg-brand-950/20">
                                <td class="p-3">
                                    <div class="font-semibold">{{ m.nombre }}</div>
                                    <div class="text-xs font-mono text-surface-500">{{ m.codigo }}</div>
                                </td>
                                <td class="p-3 text-xs">{{ tipoLabel(m.tipo) }}</td>
                                <td class="p-3 text-xs text-surface-500">
                                    <span v-if="m.requiere_referencia">referencia</span>
                                    <span v-if="m.requiere_banco"> · banco</span>
                                    <span v-if="m.requiere_comprobante"> · comprobante</span>
                                    <span v-if="!m.requiere_referencia && !m.requiere_banco && !m.requiere_comprobante">—</span>
                                </td>
                                <td class="p-3 font-mono text-xs">{{ m.cuenta_puc || '—' }}</td>
                                <td class="p-3 text-xs">
                                    <span v-if="m.siigo_payment_type_id">
                                        {{ m.siigo_payment_type_nombre || m.siigo_payment_type_id }}
                                    </span>
                                    <span v-else class="text-amber-600 font-semibold">sin mapear</span>
                                </td>
                                <td class="p-3 text-right text-xs">{{ m.usos }}</td>
                                <td class="p-3 text-center">
                                    <span :class="['text-xs font-bold px-2 py-0.5 rounded',
                                                   m.activo ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                                            : 'bg-surface-200 text-surface-600 dark:bg-surface-800 dark:text-surface-300']">
                                        {{ m.activo ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="p-3 text-right whitespace-nowrap">
                                    <button @click="abrirEditar(m)" class="btn-ghost p-2 min-h-11" title="Editar">
                                        <Pencil class="h-4 w-4"/>
                                    </button>
                                    <button v-if="m.usos === 0" @click="eliminar(m)" class="btn-ghost p-2 min-h-11 text-red-600" title="Eliminar">
                                        <Trash2 class="h-4 w-4"/>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="text-xs text-surface-500 flex items-start gap-2">
                <AlertTriangle class="h-4 w-4 flex-shrink-0 mt-0.5"/>
                <div>Un método con pagos registrados no se borra: desactivalo y deja de aparecer al registrar pagos nuevos, sin tocar el histórico.</div>
            </div>
        </div>
    </AppLayout>
</template>
