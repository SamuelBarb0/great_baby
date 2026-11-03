<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { BookOpen, Plus, Search, Cloud, CloudOff, RefreshCw, X, Check, Trash2, Link2, Ban } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';
import { useDestacar } from '@/composables/useDestacar';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasLibro } from '@/composables/usePestanasContables';
import { useMoney } from '@/composables/useMoney';
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
    filtros: { type: Object, required: true },
    asientos: { type: Object, required: true },
    kpis: { type: Object, required: true },
    cuentas: { type: Array, required: true },
});

// La Torre SIIGO enlaza aca con `?destacar=<id>` cuando el documento no tiene
// pantalla de detalle propia. Senala la fila en vez de dar un 404.
const { clasesDe } = useDestacar();

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

const { money } = useMoney();
const q = ref(props.filtros.q);
const estado = ref(props.filtros.estado);
const sinSiigo = ref(!!props.filtros.sin_siigo);

let deb;
const filtrar = () => {
    clearTimeout(deb);
    deb = setTimeout(() => {
        router.get('/app/contabilidad/asientos-manuales', {
            q: q.value || null, estado: estado.value || null,
            sin_siigo: sinSiigo.value ? 1 : null,
        }, { preserveScroll: true, preserveState: true, replace: true });
    }, 300);
};

const modal = ref(false);
const procesando = ref(false);
const nuevo = reactive({
    fecha: hoyBogota(),
    glosa: '',
    lineas: [],
});

const nuevaLinea = () => ({ cuenta_puc: '', tercero_documento: '', debe: 0, haber: 0, descripcion: '' });

/*
 * Buscador de facturas pendientes para amarrar la línea a un documento.
 *
 * La contadora lo pidió así en la reunión del 21-oct: «la idea es que yo cuando
 * elija 3.05, elija el NIT del cliente y me despliegue las varias facturas que
 * él me debe y yo pueda escogerla». Hasta hoy el tercero era un campo de texto
 * que decía «opcional», o sea: había que acordarse del NIT, teclearlo bien, y
 * aun así el asiento no quedaba atado a ninguna factura.
 *
 * Se reusa el endpoint que ya alimenta la pantalla de Pagos — mismo permiso,
 * misma consulta, cero código nuevo del lado del servidor.
 */
const buscandoEn = ref(null);     // índice de la línea que tiene el buscador abierto
const textoFactura = ref('');
const facturas = ref([]);
const cargandoFacturas = ref(false);

const abrirBuscador = (i) => {
    buscandoEn.value = buscandoEn.value === i ? null : i;
    textoFactura.value = '';
    facturas.value = [];
    if (buscandoEn.value !== null) buscarFacturas();
};

const buscarFacturas = useDebounceFn(async () => {
    if (buscandoEn.value === null) return;
    cargandoFacturas.value = true;
    try {
        const r = await fetch(`/app/pagos/facturas-pendientes?q=${encodeURIComponent(textoFactura.value)}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        facturas.value = r.ok ? await r.json() : [];
    } catch {
        facturas.value = [];
    } finally {
        cargandoFacturas.value = false;
    }
}, 350);

watch(textoFactura, buscarFacturas);

/*
 * Al elegir la factura se llenan el tercero y la descripción, y el saldo se
 * propone en la columna que corresponde: una cuenta por COBRAR (13…) se abona
 * cuando el cliente paga, una por PAGAR (2…) se debita. El contador puede
 * cambiar el monto si el abono es parcial.
 */
const elegirFactura = (i, f) => {
    const l = nuevo.lineas[i];
    l.tercero_documento = f.nit || '';
    l.descripcion = `Factura ${f.numero} · ${f.cliente}`;

    const esPorCobrar = String(l.cuenta_puc || '').startsWith('13');
    if (esPorCobrar) { l.haber = f.saldo; l.debe = 0; }
    else { l.debe = f.saldo; l.haber = 0; }

    buscandoEn.value = null;
};

const abrir = () => {
    modal.value = true;
    Object.assign(nuevo, {
        fecha: hoyBogota(),
        glosa: '',
        lineas: [nuevaLinea(), nuevaLinea()],
    });
};
const agregarLinea = () => nuevo.lineas.push(nuevaLinea());
const quitarLinea = (i) => { if (nuevo.lineas.length > 2) nuevo.lineas.splice(i, 1); };

const totalDebe = computed(() => nuevo.lineas.reduce((s, l) => s + (parseFloat(l.debe) || 0), 0));
const totalHaber = computed(() => nuevo.lineas.reduce((s, l) => s + (parseFloat(l.haber) || 0), 0));
const cuadra = computed(() => totalDebe.value > 0 && Math.abs(totalDebe.value - totalHaber.value) < 0.01);

/*
 * Qué le falta al asiento para poder guardarse, dicho en voz alta.
 *
 * La descripción de cada línea es `required` en el HTML, así que el navegador
 * bloqueaba el submit y mostraba su globo nativo... anclado a un campo que
 * queda fuera del recuadro visible del modal. Desde afuera: clic en Guardar y
 * no pasa NADA — ni petición de red, ni mensaje, ni pista. Se midió en
 * producción el 2026-10-29.
 *
 * El cuadre ya se avisaba así; esto le da el mismo trato a lo demás.
 */
const faltaParaGuardar = computed(() => {
    const sinCuenta = nuevo.lineas.filter(l => ! String(l.cuenta_puc ?? '').trim()).length;
    if (sinCuenta) return `Falta la cuenta PUC en ${sinCuenta} línea(s).`;

    const sinDescripcion = nuevo.lineas.filter(l => ! String(l.descripcion ?? '').trim()).length;
    if (sinDescripcion) return `Falta la descripción en ${sinDescripcion} línea(s).`;

    if (! String(nuevo.glosa ?? '').trim()) return 'Falta la glosa del asiento.';
    if (String(nuevo.glosa).trim().length < 10) return 'La glosa necesita al menos 10 caracteres.';

    if (! cuadra.value) return null;   // ya lo dice el «NO CUADRA» de los totales
    return null;
});

const guardar = () => {
    if (procesando.value || !cuadra.value) return;
    procesando.value = true;
    router.post('/app/contabilidad/asientos-manuales', nuevo, {
        preserveScroll: true,
        onSuccess: () => { modal.value = false; },
        onFinish: () => { procesando.value = false; },
    });
};

const aprobar = (a) => {
    modalConfirm.value = {
        titulo: `¿Aprobar el asiento #${a.id}?`,
        mensaje: `Se aprueba por ${money(a.valor_total)} y se envía a SIIGO.`,
        color: 'emerald',
        textoConfirmar: 'Aprobar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.post(`/app/contabilidad/asientos-manuales/${a.id}/aprobar`, {}, { preserveScroll: true });
        },
    };
};

/*
 * Anular un asiento equivocado.
 *
 * El mínimo de 10 caracteres es el mismo que valida el servidor: si el botón
 * deja mandar «error», el 422 es la primera noticia de que faltaba explicar.
 */
const anular = (a) => {
    modalConfirm.value = {
        titulo: `¿Anular el asiento #${a.id}?`,
        mensaje: `Sus ${a.lineas_count} línea(s) por ${money(a.valor_total)} salen del balance.\n`
            + 'El asiento no se borra: queda en esta lista marcado «anulado», con tu nombre y el motivo.',
        color: 'rose',
        textoConfirmar: 'Anular',
        pedirMotivo: { etiqueta: '¿Por qué se anula?', minimo: 10 },
        onConfirmar: (motivo) => {
            modalConfirm.value = null;
            router.post(`/app/contabilidad/asientos-manuales/${a.id}/anular`, { motivo }, { preserveScroll: true });
        },
    };
};

const reintentando = ref(null);
const reenviar = (a) => {
    modalConfirm.value = {
        titulo: `¿Reenviar asiento #${a.id} a SIIGO?`,
        mensaje: 'Se encola un envío manual a SIIGO.',
        color: 'sky',
        textoConfirmar: 'Reenviar',
        onConfirmar: () => {
            modalConfirm.value = null;
            reintentando.value = a.id;
            router.post(`/app/contabilidad/asientos-manuales/${a.id}/reenviar-siigo`, {}, {
                preserveScroll: true,
                onFinish: () => { reintentando.value = null; },
            });
        },
    };
};

const badgeEstado = (e) => ({
    borrador: 'bg-surface-500/20 text-surface-700',
    cuadrado: 'bg-blue-500/20 text-blue-700',
    aprobado: 'bg-amber-500/20 text-amber-700',
    sincronizado: 'bg-emerald-500/20 text-emerald-700',
    anulado: 'bg-rose-500/20 text-rose-700 line-through',
}[e] || 'bg-surface-500/20');
</script>

<template>
    <Head title="Asientos manuales · Contabilidad + SIIGO"/>
    <AppLayout>
        <div class="space-y-4 max-w-7xl">
            <PestanasSeccion :pestanas="pestanasLibro"/>

            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <BookOpen class="h-6 w-6 text-brand-600"/>
                        Asientos manuales
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Traslados entre cuentas, ajustes de fin de mes, provisiones. Partida doble validada. Se envían a SIIGO como <code>POST /v1/journals</code>.
                    </p>
                </div>
                <button @click="abrir" class="btn-primary text-sm inline-flex items-center gap-1">
                    <Plus class="h-4 w-4"/> Nuevo asiento
                </button>
            </div>

            <div v-if="$page.props.flash?.message" class="p-3 rounded-lg text-sm"
                 :class="$page.props.flash.type === 'error' ? 'bg-red-500/15 border-l-4 border-red-500 text-red-700' : 'bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700'">
                {{ $page.props.flash.message }}
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Total</div><div class="text-2xl font-bold mt-1">{{ kpis.total }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Borradores</div><div class="text-2xl font-bold mt-1 text-surface-500">{{ kpis.borradores }}</div></div>
                <div class="card p-4" :class="kpis.sin_siigo > 0 ? 'ring-2 ring-amber-500' : ''"><div class="text-xs uppercase text-surface-500">Aprobados sin SIIGO</div><div class="text-2xl font-bold mt-1" :class="kpis.sin_siigo > 0 ? 'text-amber-600' : 'text-surface-400'">{{ kpis.sin_siigo }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-surface-500">Monto mes</div><div class="text-2xl font-bold mt-1 text-brand-600">{{ money(kpis.monto_mes) }}</div></div>
            </div>

            <div class="card p-3">
                <div class="flex gap-2 items-center flex-wrap">
                    <div class="relative flex-1 min-w-64">
                        <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-surface-400"/>
                        <input v-model="q" @input="filtrar" placeholder="Buscar por glosa…" class="input pl-9 w-full text-sm"/>
                    </div>
                    <select v-model="estado" @change="filtrar" class="input text-sm">
                        <option :value="null">Todos los estados</option>
                        <option value="borrador">Borrador</option>
                        <option value="cuadrado">Cuadrado</option>
                        <option value="aprobado">Aprobado</option>
                        <option value="sincronizado">Sincronizado</option>
                        <option value="anulado">Anulado</option>
                    </select>
                    <label class="flex items-center gap-1 text-xs whitespace-nowrap">
                        <input type="checkbox" v-model="sinSiigo" @change="filtrar" class="rounded"/> Solo sin SIIGO
                    </label>
                </div>
            </div>

            <div class="card overflow-hidden">
                <table v-tabla-movil data-vacia="No hay asientos manuales todavía." class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-surface-500 border-b bg-surface-50 dark:bg-surface-900">
                        <tr>
                            <th class="text-left p-2">#</th>
                            <th class="text-left p-2">Fecha</th>
                            <th class="text-left p-2">Glosa</th>
                            <th class="text-right p-2">Valor</th>
                            <th class="text-center p-2">Líneas</th>
                            <th class="text-left p-2">Estado</th>
                            <th class="text-center p-2">SIIGO</th>
                            <th class="text-left p-2">Autor</th>
                            <th class="text-right p-2 w-24">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="a in asientos.data" :key="a.id" :class="['hover:bg-surface-50', clasesDe(a.id)]">
                            <td class="p-2 font-mono">#{{ a.id }}</td>
                            <td class="p-2 text-xs">{{ a.fecha }}</td>
                            <td class="p-2 text-xs max-w-xs truncate" :title="a.glosa">{{ a.glosa }}</td>
                            <td class="p-2 text-right font-mono font-bold">{{ money(a.valor_total) }}</td>
                            <td class="p-2 text-center">{{ a.lineas_count }}</td>
                            <td class="p-2"><span class="text-[10px] px-2 py-0.5 rounded uppercase font-semibold" :class="badgeEstado(a.estado)">{{ a.estado }}</span></td>
                            <td class="p-2 text-center">
                                <Cloud v-if="a.siigo_journal_id" class="h-4 w-4 inline text-emerald-600" :title="`Journal ${a.siigo_journal_id}`"/>
                                <CloudOff v-else class="h-4 w-4 inline text-surface-400"/>
                            </td>
                            <td class="p-2 text-xs">{{ a.autor }}</td>
                            <td class="p-2 text-right whitespace-nowrap">
                                <button v-if="a.estado === 'cuadrado' && a.puedo_aprobar" @click="aprobar(a)" class="text-emerald-600 hover:text-emerald-700 p-1" title="Aprobar y enviar a SIIGO">
                                    <Check class="h-4 w-4"/>
                                </button>
                                <button v-if="a.estado === 'aprobado' && !a.siigo_journal_id" @click="reenviar(a)" :disabled="reintentando === a.id"
                                        class="text-brand-600 hover:text-brand-700 p-1"
                                        :class="reintentando === a.id ? 'opacity-50 cursor-wait' : ''"
                                        title="Reenviar a SIIGO">
                                    <RefreshCw :class="['h-4 w-4', reintentando === a.id ? 'animate-spin' : '']"/>
                                </button>
                                <button v-if="a.puedo_anular" @click="anular(a)" class="text-rose-600 hover:text-rose-700 p-1"
                                        title="Anular: sus líneas salen del balance y queda el motivo">
                                    <Ban class="h-4 w-4"/>
                                </button>
                                <span v-else-if="a.anulado_at" class="text-[10px] text-surface-500" :title="a.motivo_anulacion">
                                    anulado {{ a.anulado_at }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!asientos.data.length"><td colspan="9" class="p-6 text-center text-surface-500 text-sm">Sin asientos manuales.</td></tr>
                    </tbody>
                </table>
            </div>
            <Paginacion :pagina="asientos" etiqueta="asientos"/>

            <div v-if="modal" @click.self="modal = false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <div class="card p-5 w-full max-w-4xl space-y-3 max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-bold">Nuevo asiento manual</h3>
                        <button @click="modal = false" class="text-surface-500 hover:text-surface-700"><X class="h-5 w-5"/></button>
                    </div>
                    <form @submit.prevent="guardar" class="space-y-3">
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="text-xs font-semibold">Fecha *</label>
                                <input v-model="nuevo.fecha" type="date" required class="input w-full text-sm"/>
                            </div>
                            <div class="col-span-2">
                                <label class="text-xs font-semibold">Glosa *</label>
                                <input v-model="nuevo.glosa" required minlength="10" maxlength="500" placeholder="Ej: Traslado a caja general por cierre de semana" class="input w-full text-sm"/>
                            </div>
                        </div>

                        <div class="border rounded overflow-hidden">
                            <table v-tabla-movil class="w-full text-xs">
                                <thead class="bg-surface-50 dark:bg-surface-900 uppercase text-[10px]">
                                    <tr>
                                        <th class="text-left p-1.5 w-64">Cuenta PUC *</th>
                                        <th class="text-left p-1.5 w-32">Tercero (NIT)</th>
                                        <th class="text-right p-1.5 w-32">Débito</th>
                                        <th class="text-right p-1.5 w-32">Crédito</th>
                                        <th class="text-left p-1.5">Descripción *</th>
                                        <th class="w-8"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <!--
                                        template v-for porque cada línea son DOS filas: la de datos
                                        y, debajo, la del buscador de facturas cuando está abierto.
                                    -->
                                    <template v-for="(l, i) in nuevo.lineas" :key="i">
                                    <tr>
                                        <td class="p-1">
                                            <input v-model="l.cuenta_puc" list="cuentas-puc" required placeholder="1105" class="input w-full text-xs font-mono"/>
                                        </td>
                                        <td class="p-1">
                                            <div class="flex items-center gap-1">
                                                <input v-model="l.tercero_documento" placeholder="NIT"
                                                       class="input w-full text-xs font-mono"/>
                                                <!--
                                                    Amarrar la línea a una factura: en vez de acordarse
                                                    del NIT y teclearlo, se elige el documento y el
                                                    tercero, la descripción y el saldo se llenan solos.
                                                -->
                                                <button type="button" @click="abrirBuscador(i)"
                                                        :class="['p-1 rounded shrink-0',
                                                                 buscandoEn === i ? 'bg-brand-600 text-white' : 'text-brand-600 hover:bg-brand-50']"
                                                        title="Buscar factura pendiente">
                                                    <Link2 class="h-3.5 w-3.5"/>
                                                </button>
                                            </div>
                                        </td>
                                        <td class="p-1">
                                            <input v-model="l.debe" type="number" step="0.01" min="0" class="input w-full text-xs text-right font-mono"/>
                                        </td>
                                        <td class="p-1">
                                            <input v-model="l.haber" type="number" step="0.01" min="0" class="input w-full text-xs text-right font-mono"/>
                                        </td>
                                        <td class="p-1">
                                            <input v-model="l.descripcion" required maxlength="300" class="input w-full text-xs"/>
                                        </td>
                                        <td class="p-1">
                                            <button type="button" @click="quitarLinea(i)" :disabled="nuevo.lineas.length <= 2"
                                                    class="text-red-500 hover:text-red-700 disabled:opacity-30 p-1">
                                                <Trash2 class="h-3.5 w-3.5"/>
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Facturas pendientes del cliente, para amarrar esta línea -->
                                    <tr v-if="buscandoEn === i">
                                        <td colspan="6" class="p-2 bg-brand-50 dark:bg-brand-900/20">
                                            <div class="relative mb-2">
                                                <Search class="h-3.5 w-3.5 absolute left-2 top-1/2 -translate-y-1/2 text-surface-400"/>
                                                <input v-model="textoFactura" autofocus
                                                       placeholder="Buscar por número de factura, cliente o NIT…"
                                                       class="input w-full text-xs pl-7"/>
                                            </div>

                                            <div v-if="cargandoFacturas" class="text-xs text-surface-500 py-2">Buscando…</div>

                                            <div v-else-if="! facturas.length" class="text-xs text-surface-500 py-2">
                                                No hay facturas pendientes con ese criterio.
                                            </div>

                                            <div v-else class="max-h-52 overflow-y-auto divide-y divide-surface-200 dark:divide-surface-800">
                                                <button v-for="f in facturas" :key="f.id" type="button"
                                                        @click="elegirFactura(i, f)"
                                                        class="w-full text-left px-2 py-1.5 hover:bg-white dark:hover:bg-surface-800 flex items-center gap-3">
                                                    <span class="font-mono text-xs text-brand-600 font-semibold w-28 shrink-0">{{ f.numero }}</span>
                                                    <span class="text-xs flex-1 truncate">
                                                        {{ f.cliente }}
                                                        <span v-if="f.nit" class="text-surface-500 font-mono">· {{ f.nit }}</span>
                                                    </span>
                                                    <span class="text-[10px] text-surface-500 shrink-0">vence {{ f.vence || '—' }}</span>
                                                    <span class="font-mono text-xs font-bold text-red-600 w-28 text-right shrink-0">
                                                        {{ money(f.saldo) }}
                                                    </span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    </template>
                                </tbody>
                                <tfoot class="border-t bg-surface-50 dark:bg-surface-900 font-mono font-bold">
                                    <tr>
                                        <td colspan="2" class="p-1.5 text-right text-xs uppercase">Totales:</td>
                                        <td class="p-1.5 text-right">{{ money(totalDebe) }}</td>
                                        <td class="p-1.5 text-right">{{ money(totalHaber) }}</td>
                                        <td class="p-1.5">
                                            <span v-if="cuadra" class="text-emerald-600 text-xs">✓ CUADRA</span>
                                            <span v-else class="text-red-600 text-xs">✗ NO CUADRA (dif: {{ money(Math.abs(totalDebe - totalHaber)) }})</span>
                                        </td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <datalist id="cuentas-puc">
                            <option v-for="c in cuentas" :key="c.codigo" :value="c.codigo">{{ c.nombre }}</option>
                        </datalist>

                        <button type="button" @click="agregarLinea" class="btn-ghost text-xs inline-flex items-center gap-1">
                            <Plus class="h-3 w-3"/> Agregar línea
                        </button>

                        <div class="text-xs text-surface-500 p-2 bg-surface-50 dark:bg-surface-900 rounded">
                            💡 Guardar deja el asiento en estado <b>cuadrado</b>. Requiere aprobación separada (botón <Check class="h-3 w-3 inline"/>) para envío a SIIGO.
                        </div>

                        <div class="flex justify-end items-center gap-2 pt-2 border-t">
                            <p v-if="faltaParaGuardar" class="text-xs text-amber-600 mr-auto">{{ faltaParaGuardar }}</p>
                            <button type="button" @click="modal = false" class="btn-ghost text-sm">Cancelar</button>
                            <button type="submit" :disabled="procesando || !cuadra || !!faltaParaGuardar" class="btn-primary text-sm">
                                {{ procesando ? 'Guardando…' : `Guardar ${cuadra ? '('+money(totalDebe)+')' : ''}` }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
