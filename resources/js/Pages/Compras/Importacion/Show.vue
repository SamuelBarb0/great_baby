<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { useBogotaDate } from '@/composables/useBogotaDate';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Package, Plus, Zap, AlertTriangle, FileText, Paperclip, Upload, Trash2 } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import { fechaCorta } from '@/composables/useFecha';
import { useEscClose } from '@/composables/useEscClose';
import Seguimiento from './Seguimiento.vue';

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
    importacion: { type: Object, required: true },
    tipos_documento: { type: Object, default: () => ({}) },
    puede_quitar_documentos: Boolean,
    max_subida_mb: { type: Number, default: 40 },
    seguimiento: { type: Object, required: true },
    puede_editar_seguimiento: Boolean,
    selectividades: { type: Object, default: () => ({}) },
    tipos_inspeccion: { type: Object, default: () => ({}) },
});
// Re-audit M2 UX-C2 · useMoney en vez de fmtCOP inline (paridad con Cartera/Contabilidad).
const { money } = useMoney();

/*
 * El IVA de importacion NUNCA suma al costo del inventario: se paga a la DIAN
 * y se registra como credito fiscal (1355). Capitalizarlo lo mete dos veces en
 * el libro y lo descuadra por el monto completo del IVA.
 *
 * La casilla venia PREMARCADA para todo concepto, asi que ese era el camino
 * por defecto — y el texto («suma al costo del inventario») suena justamente a
 * lo que uno quiere. Ahora se apaga sola y queda bloqueada, con el motivo a la
 * vista en lugar de un tooltip.
 */
const modalGasto = ref(false);
const modalLiquidar = ref(false);
useEscClose(modalGasto);
useEscClose(modalLiquidar);

// Re-audit M2 PATRÓN L · alineado con backend `valor|cantidad|peso|volumen`.
const gastoForm = reactive({
    concepto: 'flete', descripcion: '', moneda: 'COP', monto: 0,
    // Alejandro reparte los gastos por los m³ de cada ítem (reunión 30-oct).
    capitalizable: true, metodo_prorrateo: 'volumen',
    factura_proveedor: '', fecha: hoyBogota(),
});
const esIvaDeImportacion = computed(() => gastoForm.concepto === 'iva_importacion');

watch(esIvaDeImportacion, (es) => {
    if (es) gastoForm.capitalizable = false;
});

const procesando = ref(false);
// Soporte del gasto (factura del flete, de la agencia…), opcional.
const comprobante = ref(null);

const agregarGasto = () => {
    if (procesando.value) return;
    procesando.value = true;
    router.post(`/app/compras/importacion/${props.importacion.id}/gasto`, { ...gastoForm, comprobante: comprobante.value }, {
        preserveScroll: true,
        forceFormData: !!comprobante.value,
        onSuccess: () => { modalGasto.value = false; comprobante.value = null; Object.assign(gastoForm, { monto: 0, descripcion: '', factura_proveedor: '' }); },
        onFinish: () => procesando.value = false,
    });
};

// Re-audit M2 UX-C4 · modal propio en lugar de confirm() nativo + loading state
// + preserveScroll para no perder la posición al liquidar.
const liquidando = ref(false);
const abrirLiquidar = () => { modalLiquidar.value = true; };
const confirmarLiquidar = () => {
    if (liquidando.value) return;
    liquidando.value = true;
    router.post(`/app/compras/importacion/${props.importacion.id}/liquidar`, {}, {
        preserveScroll: true,
        onSuccess: () => { modalLiquidar.value = false; },
        onFinish: () => liquidando.value = false,
    });
};

const puedeAgregarGasto = computed(() => props.importacion.estado !== 'liquidada' && props.importacion.estado !== 'cerrada');
const puedeLiquidar = computed(() => puedeAgregarGasto.value);
const costoTotalEstimado = computed(() => (props.importacion.total_fob || 0) + (props.importacion.total_gastos || 0));
const costoAlInventario = computed(() => (props.importacion.total_fob || 0) + (props.importacion.gastos_al_inventario || 0));

// Documentos del contenedor: por tipo, la versión más nueva arriba.
const docForm = reactive({ tipo: 'lista_empaque', archivo: null, notas: '' });
const subiendoDoc = ref(false);
// Por encima del límite del servidor el envío tarda y termina en un error de PHP.
const docMuyGrande = computed(() => !!docForm.archivo && docForm.archivo.size > props.max_subida_mb * 1048576);
const docMb = computed(() => (docForm.archivo ? Math.ceil(docForm.archivo.size / 1048576) : 0));
const subirDocumento = () => {
    if (!docForm.archivo || subiendoDoc.value || docMuyGrande.value) return;
    subiendoDoc.value = true;
    router.post(`/app/compras/importacion/${props.importacion.id}/documento`, { ...docForm }, {
        preserveScroll: true, forceFormData: true,
        onSuccess: () => { docForm.archivo = null; docForm.notas = ''; },
        onFinish: () => subiendoDoc.value = false,
    });
};
const quitarDocumento = (d) => router.delete(`/app/compras/importacion/documento/${d.id}`, { preserveScroll: true });
const documentosPorTipo = computed(() => {
    const grupos = {};
    for (const d of props.importacion.documentos || []) (grupos[d.tipo] ||= []).push(d);
    return Object.keys(props.tipos_documento).filter(t => grupos[t]).map(t => ({ tipo: t, label: props.tipos_documento[t], docs: grupos[t] }));
});
// La lista y la factura cambian por proveedor, pero todas traen referencia y cantidad.
const comparable = (d) => ['prelista_empaque', 'borrador_lista_empaque', 'lista_empaque', 'invoice', 'factura_final'].includes(d.tipo)
    && d.nombre.toLowerCase().endsWith('.xlsx');
const kb = (b) => b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`;

// Re-audit M2 UX-B10 · moneda ≠ COP se muestra "1,200 USD" en vez de "USD $1,200".
const fmtMoneda = (monto, mon) => mon === 'COP'
    ? money(monto)
    : `${Number(monto || 0).toLocaleString('es-CO', { maximumFractionDigits: 2 })} ${mon}`;
</script>

<template>
    <Head :title="`Importación ${importacion.numero}`"/>
    <AppLayout>
        <div class="max-w-5xl mx-auto space-y-4">
            <Link href="/app/compras" class="btn-ghost inline-flex items-center gap-1 text-sm">
                <ArrowLeft class="h-4 w-4"/> Volver
            </Link>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 dark:text-emerald-300 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-sm">
                {{ $page.props.flash.error }}
            </div>

            <div class="card p-5">
                <div class="flex items-start justify-between flex-wrap gap-3">
                    <div>
                        <div class="text-xs text-surface-500 uppercase">Importación</div>
                        <h1 class="text-2xl font-bold font-mono">{{ importacion.numero }}</h1>
                        <div class="text-xs text-surface-500 mt-1">
                            Contenedor: {{ importacion.contenedor || '—' }} · BL/AWB: {{ importacion.bl_awb || '—' }} · Incoterm: <span :title="'Incoterm 2020: cláusula de responsabilidad transporte + seguro'">{{ importacion.incoterm || '—' }}</span>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-surface-500 uppercase">Estado</div>
                        <span class="inline-block px-3 py-1 rounded font-bold text-sm uppercase"
                              :class="importacion.estado === 'liquidada' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' : 'bg-brand-100 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200'">{{ importacion.estado }}</span>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4 text-sm border-t border-surface-200 dark:border-surface-800 pt-3">
                    <div><b>Puerto origen:</b> {{ importacion.puerto_origen || '—' }}</div>
                    <div><b>Puerto destino:</b> {{ importacion.puerto_destino || '—' }}</div>
                    <div><b>Zarpe:</b> {{ fechaCorta(importacion.fecha_zarpe) }}</div>
                    <div><b>ETA:</b> {{ fechaCorta(importacion.eta) }}</div>
                    <div><b>Llegada:</b> {{ fechaCorta(importacion.fecha_llegada) }}</div>
                    <div><b>Liquidación:</b> {{ fechaCorta(importacion.fecha_liquidacion) }}</div>
                    <div><b>Moneda:</b> {{ importacion.moneda_origen }} @ {{ importacion.tasa_cambio_liquidacion }}</div>
                </div>
                <div class="flex items-center gap-2 border-t border-surface-200 dark:border-surface-800 pt-3 mt-3">
                    <button v-if="puedeLiquidar" @click="abrirLiquidar" class="btn-primary bg-emerald-600 hover:bg-emerald-700" :disabled="liquidando">
                        <Zap class="h-4 w-4"/> Liquidar contenedor
                    </button>
                    <a :href="`/compras/importacion/${importacion.id}/pdf`" target="_blank" rel="noopener" class="btn-ghost">PDF</a>
                </div>
            </div>

            <Seguimiento :importacion="importacion" :seguimiento="seguimiento" :puede-editar="puede_editar_seguimiento"
                         :selectividades="selectividades" :tipos-inspeccion="tipos_inspeccion"/>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="card p-4"><div class="text-xs text-surface-500">Total FOB<template v-if="importacion.fob_es_previsto"> (previsto)</template></div><div class="text-xl font-bold">{{ money(importacion.total_fob) }}</div></div>
                <div class="card p-4"><div class="text-xs text-surface-500" :title="'Suma de gastos capitalizables + no capitalizables'">Total gastos</div><div class="text-xl font-bold">{{ money(importacion.total_gastos) }}</div></div>
                <div class="card p-4"><div class="text-xs text-surface-500">Costo total</div><div class="text-xl font-bold text-brand-600">{{ money(costoTotalEstimado) }}</div></div>
            </div>

            <div class="card p-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-xs uppercase font-bold text-brand-600">Gastos ({{ importacion.gastos.length }})</div>
                    <button v-if="puedeAgregarGasto" @click="modalGasto = true" class="btn-ghost text-xs"><Plus class="h-3 w-3"/> Agregar gasto</button>
                    <span v-else class="text-xs text-surface-500">Ya liquidada — no admite gastos.</span>
                </div>
                <div class="overflow-x-auto">
                    <table v-tabla-movil v-if="importacion.gastos.length" class="w-full text-sm">
                        <thead class="text-[10px] text-surface-500 uppercase border-b border-surface-200 dark:border-surface-800">
                            <tr>
                                <th class="text-left p-2">Fecha</th>
                                <th class="text-left p-2">Concepto</th>
                                <th class="text-right p-2">Monto</th>
                                <th class="text-right p-2">Base COP</th>
                                <th class="text-center p-2" :title="'Capitalizable: suma al costo del inventario'">Cap.</th>
                                <th class="text-left p-2">Prorrateo</th>
                                <th class="text-left p-2">Soporte</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-for="g in importacion.gastos" :key="g.id">
                                <td class="p-2 text-xs">{{ fechaCorta(g.fecha) }}</td>
                                <td class="p-2">{{ g.concepto }}</td>
                                <td class="p-2 text-right">{{ fmtMoneda(g.monto, g.moneda) }}</td>
                                <td class="p-2 text-right font-bold">{{ money(g.monto_base) }}</td>
                                <td class="p-2 text-center">{{ g.capitalizable ? '✓' : '—' }}</td>
                                <td class="p-2 text-xs">{{ g.metodo_prorrateo }}</td>
                                <td class="p-2 text-xs">
                                    <a v-for="c in g.comprobantes" :key="c.id" :href="`/app/compras/importacion/documento/${c.id}`" target="_blank" rel="noopener"
                                       class="flex items-center gap-1 text-brand-600 hover:underline"><Paperclip class="h-3 w-3"/>{{ c.nombre }}</a>
                                    <span v-if="!g.comprobantes?.length" class="text-surface-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="text-center py-4 text-surface-500 text-sm">Sin gastos aún.</p>
                </div>
            </div>

            <!-- Documentos del contenedor -->
            <div class="card p-4 space-y-3">
                <div class="text-xs uppercase font-bold text-brand-600 flex items-center gap-1"><FileText class="h-3.5 w-3.5"/>Documentos ({{ importacion.documentos?.length || 0 }})</div>
                <div class="grid grid-cols-1 md:grid-cols-[1fr_1fr_1fr_auto] gap-2 items-end">
                    <div><label class="text-xs font-semibold">Tipo</label>
                        <select v-model="docForm.tipo" class="input w-full">
                            <option v-for="(label, clave) in tipos_documento" :key="clave" :value="clave">{{ label }}</option>
                        </select>
                    </div>
                    <div><label class="text-xs font-semibold">Archivo</label>
                        <input type="file" @change="docForm.archivo = $event.target.files[0] || null" class="input w-full text-xs"
                               accept=".pdf,.xlsx,.xls,.csv,.doc,.docx,.jpg,.jpeg,.png,.webp,.zip"/>
                    </div>
                    <div><label class="text-xs font-semibold">Nota (opcional)</label><input v-model="docForm.notas" class="input w-full" maxlength="500"/></div>
                    <button @click="subirDocumento" :disabled="!docForm.archivo || subiendoDoc || docMuyGrande" class="btn-primary text-sm disabled:opacity-50">
                        <Upload class="h-4 w-4"/>{{ subiendoDoc ? 'Subiendo…' : 'Subir' }}
                    </button>
                </div>
                <div v-if="docMuyGrande" class="p-2 rounded bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-xs">
                    Este archivo pesa {{ docMb }} MB y el máximo es {{ max_subida_mb }} MB. Suba una copia sin las fotos o guarde el original aparte.
                </div>
                <div v-if="$page.props.errors?.archivo" class="p-2 rounded bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-xs">{{ $page.props.errors.archivo }}</div>
                <div v-if="documentosPorTipo.length" class="divide-y divide-surface-100 dark:divide-surface-800">
                    <div v-for="g in documentosPorTipo" :key="g.tipo" class="py-2">
                        <div class="text-sm font-semibold">{{ g.label }}</div>
                        <div v-for="(d, i) in g.docs" :key="d.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm pl-2" :class="i > 0 ? 'text-surface-500' : ''">
                            <a :href="`/app/compras/importacion/documento/${d.id}`" target="_blank" rel="noopener" class="text-brand-600 hover:underline flex items-center gap-1">
                                <Paperclip class="h-3 w-3"/>{{ d.nombre }}
                            </a>
                            <span class="text-xs">v{{ d.version }}<span v-if="i === 0 && g.docs.length > 1"> · la más reciente</span> · {{ kb(d.tamano) }} · {{ d.subido_por }} · {{ d.subido_en }}</span>
                            <span v-if="d.notas" class="text-xs italic">{{ d.notas }}</span>
                            <Link v-if="comparable(d)" :href="`/app/compras/importacion/${importacion.id}/documento/${d.id}/comparar`" class="text-xs text-brand-600 hover:underline">Comparar con la orden</Link>
                            <button v-if="puede_quitar_documentos" @click="quitarDocumento(d)" class="text-red-600 hover:text-red-700" :aria-label="`Quitar ${d.nombre}`"><Trash2 class="h-3.5 w-3.5"/></button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-surface-500">Sin documentos aún. Suba la prelista, el BL, la lista de empaque, la DUTA… a medida que lleguen.</p>
            </div>

            <div v-if="importacion.lineas.length" class="card p-4">
                <div class="text-xs uppercase font-bold text-brand-600 mb-2">Líneas ({{ importacion.lineas.length }})</div>
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm min-w-[600px]">
                        <thead class="text-[10px] text-surface-500 uppercase border-b border-surface-200 dark:border-surface-800">
                            <tr>
                                <th class="text-right p-2">Cant</th>
                                <th class="text-right p-2">FOB unit</th>
                                <th class="text-right p-2">FOB total</th>
                                <th class="text-right p-2">Prorrateado</th>
                                <th class="text-right p-2">Costo final</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                            <tr v-for="l in importacion.lineas" :key="l.id">
                                <td class="p-2 text-right">{{ l.cantidad }}</td>
                                <td class="p-2 text-right">{{ money(l.costo_fob_unit) }}</td>
                                <td class="p-2 text-right">{{ money(l.costo_fob_total) }}</td>
                                <td class="p-2 text-right text-amber-600">{{ money(l.gasto_prorrateado) }}</td>
                                <td class="p-2 text-right font-bold text-brand-600">{{ money(l.costo_final_unit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Re-audit M2 UX-C4 · modal Liquidar con contexto financiero, no confirm() nativo. -->
        <div v-if="modalLiquidar" @click.self="modalLiquidar = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full">
                <div class="flex items-start gap-3">
                    <AlertTriangle class="h-6 w-6 text-amber-600 mt-1 flex-shrink-0"/>
                    <div>
                        <h3 class="text-lg font-bold mb-1">¿Liquidar importación?</h3>
                        <p class="text-sm text-surface-600 dark:text-surface-400 mb-3">
                            Se prorratean <b>{{ importacion.gastos.length }}</b> gasto(s) capitalizables al costo unitario de <b>{{ importacion.lineas.length || 'las' }}</b> líneas, se corrige el costo de lo que ya recibió bodega y se genera el asiento contable definitivo. La cantidad no cambia: el stock entra cuando bodega acepta la mercancía.
                        </p>
                        <div class="text-xs bg-surface-50 dark:bg-surface-900/50 rounded p-2 mb-3 space-y-1">
                            <div class="flex justify-between"><span>FOB total (previsto):</span> <b>{{ money(importacion.total_fob) }}</b></div>
                            <div class="flex justify-between"><span>Gastos:</span> <b>{{ money(importacion.total_gastos) }}</b></div>
                            <div class="flex justify-between text-brand-600 pt-1 border-t border-surface-200 dark:border-surface-700"><span>Costo total al inventario:</span> <b>{{ money(costoAlInventario) }}</b></div>
                        </div>
                        <p v-if="importacion.fob_aviso" class="text-[11px] text-red-600 dark:text-red-400 mb-2">{{ importacion.fob_aviso }}</p>
                        <p v-if="importacion.fob_sin_recibir > 0" class="text-[11px] text-surface-500 mb-2">{{ importacion.fob_sin_recibir }} línea(s) aún sin recibir van por lo pedido.</p>
                        <p v-if="importacion.gastos_al_inventario < importacion.total_gastos" class="text-[11px] text-surface-500 mb-2">Los gastos no capitalizables no entran al costo del inventario.</p>
                        <p class="text-[11px] text-amber-700 dark:text-amber-400"><b>Advertencia:</b> Después de liquidar no se pueden agregar gastos ni editar líneas sin reversar.</p>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalLiquidar = false" class="btn-ghost" :disabled="liquidando">Cancelar</button>
                    <button @click="confirmarLiquidar" :disabled="liquidando" class="btn-primary bg-emerald-600 hover:bg-emerald-700">
                        {{ liquidando ? 'Liquidando…' : 'Sí, liquidar' }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="modalGasto" @click.self="modalGasto = false" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full">
                <h3 class="text-lg font-bold mb-3">Agregar gasto de importación</h3>
                <div v-if="Object.keys($page.props.errors || {}).length" class="mb-3 p-2 rounded bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-xs">
                    <div v-for="(msg, k) in $page.props.errors" :key="k">{{ msg }}</div>
                </div>
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="text-xs font-semibold">Concepto</label>
                            <select v-model="gastoForm.concepto" class="input w-full">
                                <option value="flete">Flete</option>
                                <option value="seguro">Seguro</option>
                                <option value="agente_aduana">Agencia aduanera</option>
                                <option value="arancel">Arancel</option>
                                <option value="iva_importacion">IVA importación</option>
                                <option value="almacenaje">Bodegaje</option>
                                <option value="transporte_interno">Transporte interno</option>
                                <option value="otros">Otros</option>
                            </select>
                        </div>
                        <div><label class="text-xs font-semibold">Fecha</label><input type="date" v-model="gastoForm.fecha" class="input w-full"/></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div><label class="text-xs font-semibold">Moneda</label>
                            <select v-model="gastoForm.moneda" class="input w-full">
                                <option>COP</option><option>USD</option><option>EUR</option><option>CNY</option>
                            </select>
                        </div>
                        <div class="col-span-2"><label class="text-xs font-semibold">Monto</label><input type="number" step="0.01" v-model.number="gastoForm.monto" class="input w-full" autofocus/></div>
                    </div>
                    <div v-if="gastoForm.moneda !== 'COP'" class="text-[11px] text-surface-500 bg-surface-50 dark:bg-surface-900/50 rounded p-2">
                        Se convertirá a COP con tasa {{ importacion.tasa_cambio_liquidacion }} de esta importación.
                    </div>
                    <div><label class="text-xs font-semibold">Método prorrateo</label>
                        <select v-model="gastoForm.metodo_prorrateo" class="input w-full">
                            <option value="volumen">Por volumen (m³ de cada ítem)</option>
                            <option value="valor">Por valor FOB</option>
                            <option value="cantidad">Por cantidad</option>
                            <option value="peso">Por peso</option>
                        </select>
                    </div>
                    <div><label class="text-xs font-semibold">Factura</label><input v-model="gastoForm.factura_proveedor" class="input w-full"/></div>
                    <div><label class="text-xs font-semibold">Descripción</label><input v-model="gastoForm.descripcion" class="input w-full"/></div>
                    <div><label class="text-xs font-semibold">Comprobante (opcional)</label>
                        <input type="file" @change="comprobante = $event.target.files[0] || null" class="input w-full text-xs"
                               accept=".pdf,.xlsx,.xls,.csv,.doc,.docx,.jpg,.jpeg,.png,.webp,.zip"/>
                    </div>
                    <label class="flex items-center gap-2 text-sm cursor-pointer" :title="'Capitalizable: suma al costo del inventario. Los no capitalizables van a gastos operacionales.'">
                        <input type="checkbox" v-model="gastoForm.capitalizable" :disabled="esIvaDeImportacion"/>
                        Capitalizable (suma al costo)
                    </label>
                    <p v-if="esIvaDeImportacion" class="text-[11px] text-amber-600 mt-1">
                        El IVA de importación no capitaliza: se paga a la DIAN y queda como
                        crédito fiscal. Sumarlo al costo lo contaría dos veces.
                    </p>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="modalGasto = false" class="btn-ghost" :disabled="procesando">Cancelar</button>
                    <button @click="agregarGasto" :disabled="procesando || !gastoForm.monto" class="btn-primary">{{ procesando ? '…' : 'Agregar' }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
