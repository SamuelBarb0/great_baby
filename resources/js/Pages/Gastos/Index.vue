<script setup>
import { ref, reactive } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, router } from '@inertiajs/vue3';
import { Receipt, Plus, CheckCircle, XCircle, CreditCard, BookOpen } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';

/*
 * La fecha de HOY en Colombia, no en UTC.
 *
 * `new Date().toISOString().slice(0,10)` convierte a UTC antes de
 * recortar: entre las 19:00 y la medianoche hora Bogotá devuelve el día
 * siguiente. Acá eso no era un filtro incómodo sino la fecha que queda
 * escrita en el documento.
 */
const { hoy: hoyBogota } = useBogotaDate();

const props = defineProps({
    gastos: Object, conteos: Object, esAdmin: Boolean, filtros: Object,
    // Quien puede APROBAR no es el mismo que puede pagar: el Contador paga
    // pero no aprueba. Antes los dos botones salian con `esAdmin` y el
    // Contador recibia un 403 al apretar el verde.
    puedeAprobar: { type: Boolean, default: false },
    cuentaPorDefecto: { type: String, default: '5195' },
    destinos: { type: Array, default: () => [] },
    lineas: { type: Array, default: () => [] },
    cajas: { type: Array, default: () => [] },
});

/*
 * Lo contable del gasto: contra que cuenta va y si se declara.
 *
 * Antes la pantalla no mostraba nada de esto porque el gasto no llegaba al
 * libro: se aprobaba, se pagaba, y el balance no se enteraba. Ahora el gasto
 * genera asiento y el contador tiene que poder ver —y corregir— contra que.
 */
const modalContable = ref(null);
const formContable = ref({ cuenta_puc: '', destino: 'siigo', linea: 'great_baby', motivo: '' });
const guardandoContable = ref(false);

const abrirContable = (g) => {
    formContable.value = {
        cuenta_puc: g.cuenta_puc || '',
        destino: g.destino || 'siigo',
        linea: g.linea || 'great_baby',
        motivo: g.motivo_destino || '',
    };
    modalContable.value = g;
};

const guardarContable = () => {
    if (guardandoContable.value) return;
    guardandoContable.value = true;
    router.post(`/app/gastos/${modalContable.value.id}/contabilizar`, formContable.value, {
        preserveScroll: true,
        onFinish: () => { guardandoContable.value = false; },
        onSuccess: () => { modalContable.value = null; },
    });
};

const badgeDestino = (color) => ({
    success: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
}[color] || 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200');

const modal = ref(false);
const form = reactive({
    fecha: hoyBogota(),
    categoria: 'papeleria', descripcion: '', monto: 0, proveedor: '', factura_ref: '',
    metodo_pago: 'transferencia', tipo: 'gasto', notas: '',
});
const modalRech = ref(null);
const motivoRech = ref('');
const procesando = ref(false);

const crear = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post('/app/gastos', form, {
        preserveScroll: true,
        onSuccess: () => { modal.value = false; Object.assign(form, { descripcion: '', monto: 0, proveedor: '', factura_ref: '', notas: '' }); },
        onFinish: () => procesando.value = false,
    });
};
// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseño.
const modalConfirm = ref(null);

const aprobar = (id) => {
    modalConfirm.value = {
        titulo: '¿Aprobar este gasto?',
        mensaje: 'Queda aprobado y listo para pagarse.',
        color: 'emerald',
        textoConfirmar: 'Aprobar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.post(`/app/gastos/${id}/aprobar`, {}, { preserveScroll: true });
        },
    };
};
const rechazar = () => {
    if (motivoRech.value.trim().length < 10) return;
    router.post(`/app/gastos/${modalRech.value.id}/rechazar`, { motivo: motivoRech.value }, {
        preserveScroll: true, onSuccess: () => { modalRech.value = null; motivoRech.value = ''; },
    });
};
/*
 * Marcar pagado · y si sale en efectivo, de qué caja.
 *
 * Sin elegir caja el gasto golpeaba la cuenta contable por defecto y no
 * aterrizaba en ningún arqueo: al final del día faltaban los billetes y la
 * pantalla de la caja no tenía con qué explicarlo. Para los que se pagan por
 * transferencia no se pregunta nada, que es el caso más común.
 */
const modalPagar = ref(null);
const cajaElegida = ref(null);

const marcarPagado = (g) => {
    if (g.metodo_pago !== 'efectivo') {
        modalConfirm.value = {
            titulo: '¿Marcar el gasto como pagado?',
            mensaje: 'Se registra como pagado y sale de los pendientes.',
            color: 'emerald',
            textoConfirmar: 'Marcar pagado',
            onConfirmar: () => {
                modalConfirm.value = null;
                router.post(`/app/gastos/${g.id}/pagado`, {}, { preserveScroll: true });
            },
        };
        return;
    }

    cajaElegida.value = props.cajas.length === 1 ? props.cajas[0].id : null;
    modalPagar.value = g;
};

const confirmarPagoEnEfectivo = () => {
    if (! cajaElegida.value) return;
    router.post(`/app/gastos/${modalPagar.value.id}/pagado`, { caja_id: cajaElegida.value }, {
        preserveScroll: true,
        onSuccess: () => { modalPagar.value = null; cajaElegida.value = null; },
    });
};
const filtrar = (campo, val) => router.get('/app/gastos', { ...props.filtros, [campo]: val || null });

const money = (n) => '$' + Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });
const badge = (e) => ({ pendiente: 'bg-amber-100 text-amber-800', aprobado: 'bg-emerald-100 text-emerald-800', rechazado: 'bg-red-100 text-red-800', pagado: 'bg-brand-100 text-brand-800' }[e] || 'bg-surface-100');
</script>

<template>
    <Head title="Gastos y reembolsos"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between">
                <h1 class="text-2xl font-bold flex items-center gap-2"><Receipt class="h-6 w-6 text-brand-600"/>Gastos operativos y reembolsos</h1>
                <button @click="modal = true" class="btn-primary"><Plus class="h-4 w-4"/> Nueva solicitud</button>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">{{ $page.props.flash.success }}</div>

            <div class="grid grid-cols-4 gap-3">
                <div v-for="e in ['pendiente','aprobado','pagado','rechazado']" :key="e" class="card p-3">
                    <div class="text-xs uppercase capitalize text-surface-500">{{ e }}</div>
                    <div class="text-2xl font-bold mt-1">{{ conteos[e]?.total || 0 }}</div>
                    <div class="text-xs text-surface-500">{{ money(conteos[e]?.suma || 0) }}</div>
                </div>
            </div>

            <div class="card p-3 flex items-center gap-2 flex-wrap">
                <button v-for="t in ['','gasto','reembolso']" :key="t" @click="filtrar('tipo', t)"
                    :class="['px-3 py-1 rounded text-sm capitalize', (filtros.tipo || '') === t ? 'bg-brand-600 text-white' : 'bg-surface-100']">
                    {{ t || 'Todos' }}
                </button>
                <span class="mx-2 text-surface-300">|</span>
                <button v-for="e in ['','pendiente','aprobado','pagado','rechazado']" :key="e" @click="filtrar('estado', e)"
                    :class="['px-3 py-1 rounded text-sm capitalize', (filtros.estado || '') === e ? 'bg-brand-600 text-white' : 'bg-surface-100']">
                    {{ e || 'Todos' }}
                </button>
            </div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil data-vacia="No hay gastos registrados con esos filtros." class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-2">Número</th>
                            <th class="text-left p-2">Fecha</th>
                            <th class="text-left p-2">Tipo</th>
                            <th class="text-left p-2">Categoría</th>
                            <th class="text-left p-2">Descripción</th>
                            <th class="text-right p-2">Monto</th>
                            <th class="text-left p-2">Solicita</th>
                            <th class="text-center p-2">Estado</th>
                            <th class="text-center p-2">Contable</th>
                            <th class="text-right p-2">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="g in gastos.data" :key="g.id" class="hover:bg-surface-50">
                            <td class="p-2 font-mono">{{ g.numero }}</td>
                            <td class="p-2 text-xs">{{ g.fecha }}</td>
                            <td class="p-2 text-xs capitalize">{{ g.tipo }}</td>
                            <td class="p-2 text-xs">{{ g.categoria }}</td>
                            <td class="p-2">{{ g.descripcion }}</td>
                            <td class="p-2 text-right font-bold">{{ money(g.monto) }}</td>
                            <td class="p-2 text-xs">{{ g.solicita }}</td>
                            <td class="p-2 text-center"><span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase', badge(g.estado)]">{{ g.estado }}</span></td>
                            <!-- Contra que cuenta va y si se declara. Si todavia
                                 no esta en el libro se dice, en vez de dejarlo
                                 en blanco como si no existiera. -->
                            <td class="p-2 text-center whitespace-nowrap">
                                <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-bold', badgeDestino(g.destino_color)]">
                                    {{ g.destino_label }}
                                </span>
                                <div class="text-[10px] text-surface-400 font-mono">
                                    {{ g.cuenta_puc || cuentaPorDefecto + ' (por defecto)' }}
                                </div>
                                <div v-if="!g.en_el_libro" class="text-[10px] text-amber-600 font-semibold">
                                    sin asiento
                                </div>
                            </td>
                            <td class="p-2 text-right space-x-1">
                                <button v-if="esAdmin" @click="abrirContable(g)" class="btn-ghost p-1 text-surface-500" title="Cuenta y destino contable"><BookOpen class="h-4 w-4"/></button>
                                <button v-if="puedeAprobar && g.estado === 'pendiente'" @click="aprobar(g.id)" class="btn-ghost p-1 text-emerald-600" title="Aprobar"><CheckCircle class="h-4 w-4"/></button>
                                <button v-if="puedeAprobar && g.estado === 'pendiente'" @click="modalRech = g" class="btn-ghost p-1 text-red-600" title="Rechazar"><XCircle class="h-4 w-4"/></button>
                                <button v-if="esAdmin && g.estado === 'aprobado'" @click="marcarPagado(g)" class="btn-ghost p-1 text-brand-600" title="Marcar pagado"><CreditCard class="h-4 w-4"/></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Paginacion :pagina="gastos" etiqueta="gastos"/>
        </div>

        <div v-if="modal" @click.self="modal = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-lg w-full max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-bold mb-3">Nueva solicitud</h3>
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="text-xs font-semibold">Fecha</label><input type="date" v-model="form.fecha" class="input w-full"/></div>
                        <div><label class="text-xs font-semibold">Tipo</label>
                            <select v-model="form.tipo" class="input w-full">
                                <option value="gasto">Gasto operativo</option><option value="reembolso">Reembolso</option>
                            </select>
                        </div>
                        <div><label class="text-xs font-semibold">Categoría</label>
                            <select v-model="form.categoria" class="input w-full">
                                <option value="servicios_publicos">Servicios públicos</option>
                                <option value="arriendo">Arriendo</option>
                                <option value="transporte">Transporte</option>
                                <option value="viaticos">Viáticos</option>
                                <option value="papeleria">Papelería</option>
                                <option value="publicidad">Publicidad</option>
                                <option value="mantenimiento">Mantenimiento</option>
                                <option value="otros">Otros</option>
                            </select>
                        </div>
                        <div><label class="text-xs font-semibold">Método pago</label>
                            <select v-model="form.metodo_pago" class="input w-full">
                                <option value="efectivo">Efectivo</option><option value="transferencia">Transferencia</option>
                                <option value="tarjeta">Tarjeta</option><option value="nequi">Nequi</option><option value="daviplata">Daviplata</option>
                            </select>
                        </div>
                        <div><label class="text-xs font-semibold">Monto</label><input type="number" step="0.01" v-model.number="form.monto" class="input w-full" autofocus/></div>
                        <div><label class="text-xs font-semibold">Proveedor</label><input v-model="form.proveedor" class="input w-full"/></div>
                    </div>
                    <div><label class="text-xs font-semibold">Descripción</label><input v-model="form.descripcion" class="input w-full"/></div>
                    <div><label class="text-xs font-semibold">Referencia factura</label><input v-model="form.factura_ref" class="input w-full"/></div>
                    <div><label class="text-xs font-semibold">Notas</label><textarea v-model="form.notas" rows="2" class="input w-full"></textarea></div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modal = false" class="btn-ghost">Cancelar</button>
                    <button @click="crear" :disabled="procesando" class="btn-primary">{{ procesando ? '…' : 'Solicitar' }}</button>
                </div>
            </div>
        </div>

        <div v-if="modalRech" @click.self="modalRech = null" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full">
                <h3 class="text-lg font-bold mb-3">Rechazar {{ modalRech.numero }}</h3>
                <textarea v-model="motivoRech" rows="3" class="input w-full" placeholder="Motivo mínimo 10 caracteres" autofocus/>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalRech = null" class="btn-ghost">Cancelar</button>
                    <button @click="rechazar" :disabled="motivoRech.trim().length < 10" class="btn-primary bg-red-600">Rechazar</button>
                </div>
            </div>
        </div>
        <!-- Cuenta y destino contable del gasto -->
        <div v-if="modalContable" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
             @click.self="modalContable = null">
            <div class="card p-5 w-full max-w-md space-y-3">
                <h3 class="font-bold text-lg">Contabilidad del gasto {{ modalContable.numero }}</h3>
                <p class="text-xs text-surface-500">
                    {{ modalContable.descripcion }} · {{ money(modalContable.monto) }}
                </p>

                <div>
                    <label class="text-xs font-semibold text-surface-500">Cuenta PUC del gasto</label>
                    <input v-model="formContable.cuenta_puc" class="input w-full mt-1 font-mono"
                           :placeholder="cuentaPorDefecto + ' (por defecto)'" maxlength="10"/>
                    <p class="text-[11px] text-surface-500 mt-1">
                        Vacía usa {{ cuentaPorDefecto }}. Tiene que existir en el plan de cuentas.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-surface-500">Línea de negocio</label>
                        <select v-model="formContable.linea" class="input w-full mt-1">
                            <option v-for="l in lineas" :key="l.valor" :value="l.valor">{{ l.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-500">Destino</label>
                        <select v-model="formContable.destino" class="input w-full mt-1">
                            <option v-for="d in destinos" :key="d.valor" :value="d.valor">{{ d.label }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="formContable.destino !== 'siigo'"
                     class="text-sm bg-amber-50 dark:bg-amber-900/20 border-l-4 border-amber-500 p-3">
                    Este gasto no se declara: su IVA no se descuenta y no viaja a SIIGO.
                    Escribí por qué.
                </div>

                <div>
                    <label class="text-xs font-semibold text-surface-500">
                        Motivo {{ formContable.destino === 'siigo' ? '(opcional)' : '*' }}
                    </label>
                    <textarea v-model="formContable.motivo" rows="2" maxlength="300" class="input w-full mt-1"
                              placeholder="Ej.: el proveedor no factura"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button @click="modalContable = null" class="btn-secondary text-sm">Cancelar</button>
                    <button @click="guardarContable"
                            :disabled="guardandoContable || (formContable.destino !== 'siigo' && ! formContable.motivo.trim())"
                            class="btn-primary text-sm disabled:opacity-40">
                        {{ guardandoContable ? 'Guardando…' : 'Guardar y rehacer asiento' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Pagar en efectivo: de qué caja sale la plata -->
        <div v-if="modalPagar" @click.self="modalPagar = null"
             class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl max-w-md w-full p-5 space-y-3">
                <h3 class="text-lg font-bold">Pagar {{ modalPagar.numero }} en efectivo</h3>
                <p class="text-sm text-surface-600">
                    {{ modalPagar.descripcion }} · <strong>{{ money(modalPagar.monto) }}</strong>
                </p>

                <div v-if="cajas.length">
                    <label class="block text-xs font-bold text-surface-600 mb-1">¿De qué caja sale?</label>
                    <select v-model="cajaElegida" class="input w-full">
                        <option :value="null">Elegí la caja…</option>
                        <option v-for="c in cajas" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                    </select>
                    <p class="text-xs text-surface-500 mt-1">
                        Queda como gasto del día en esa caja, así el arqueo cuadra sin tener que acordarse.
                    </p>
                </div>
                <p v-else class="text-sm text-amber-600">
                    Todavía no hay cajas creadas. Creá una en Contabilidad → Cajas y bancos.
                </p>

                <div class="flex justify-end gap-2 pt-1">
                    <button @click="modalPagar = null" class="btn-ghost text-sm">Cancelar</button>
                    <button @click="confirmarPagoEnEfectivo" :disabled="!cajaElegida"
                            class="btn-primary text-sm disabled:opacity-40">
                        Marcar pagado
                    </button>
                </div>
            </div>
        </div>

        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
