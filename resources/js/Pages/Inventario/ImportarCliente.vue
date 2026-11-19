<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Upload, FileSpreadsheet, CheckCircle2, AlertTriangle, Loader2, MapPin, Scale, Search, X } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { avisar } from '@/composables/useAviso';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    bodegas: { type: Array, required: true },
    grupos: { type: Array, default: () => [] },
    motivos: { type: Array, default: () => [] },
});
const { money } = useMoney();

const form = useForm({
    // Por qué entra la mercancía. Todo ingreso tiene que tener contrapartida:
    // se le debe a un proveedor, lo aportaron los socios, es el saldo inicial…
    motivo: '',
    observacion: '',
    soporte: '',
    tercero_id: null,
    archivo: null,
    bodega_id: props.bodegas[0]?.id ?? null,
    hoja: 'Hoja1',
    dry_run: false,
    // Posición por defecto · se aplica a las filas que no traigan la suya en el
    // Excel. Vacío = la mercancía entra a la bodega sin posición, como antes.
    pasillo: '',
    estante: '',
    nivel: '',
});

// Solo una bodega puede tener posiciones adentro. Si se eligió una posición
// concreta como destino, pedir pasillo/estante/nivel sería mentir: el modelo no
// anida un nivel dentro de otro nivel y el importador lo ignoraría.
const destino = computed(() => props.bodegas.find((b) => b.id === form.bodega_id) || null);
const destinoEsBodega = computed(() => destino.value?.es_bodega !== false);

watch(destinoEsBodega, (esBodega) => {
    if (! esBodega) { form.pasillo = ''; form.estante = ''; form.nivel = ''; }
});

const motivoSel = computed(() => props.motivos.find((m) => m.value === form.motivo) || null);

// Buscador de tercero (proveedor o socio) · mismo endpoint que Cartera.
const terceroTexto = ref('');
const terceroSel = ref(null);
const terceroResultados = ref([]);
let terceroDeb;
watch(terceroTexto, (q) => {
    clearTimeout(terceroDeb);
    if (terceroSel.value || !q || q.trim().length < 2) { terceroResultados.value = []; return; }
    terceroDeb = setTimeout(async () => {
        try {
            const res = await fetch('/app/api/contactos/buscar?q=' + encodeURIComponent(q.trim()), {
                credentials: 'same-origin', headers: { Accept: 'application/json' },
            });
            terceroResultados.value = res.ok ? await res.json() : [];
        } catch { terceroResultados.value = []; }
    }, 300);
});
const elegirTercero = (t) => {
    terceroSel.value = t;
    form.tercero_id = t.id;
    terceroTexto.value = `${t.nombre}${t.documento ? ' · ' + t.documento : ''}`;
    terceroResultados.value = [];
};
const quitarTercero = () => {
    terceroSel.value = null;
    form.tercero_id = null;
    terceroTexto.value = '';
};

const faltaPorque = computed(() => !form.motivo
    || form.observacion.trim().length < 5
    || (motivoSel.value?.requiere_tercero && !form.tercero_id));

const dragActive = ref(false);
const nombreArchivo = computed(() => form.archivo?.name || null);
const tamanoArchivo = computed(() => form.archivo
    ? (form.archivo.size / 1024).toFixed(1) + ' KB'
    : null);

const resultadoRef = ref(null);
const mensajeOverlay = ref('');

const seleccionar = (file) => {
    if (!file) return;
    if (!/\.(xlsx|xls)$/i.test(file.name)) {
        avisar('El archivo debe ser .xlsx o .xls', 'warning');
        return;
    }
    form.archivo = file;
};

const onFileInput = (e) => seleccionar(e.target.files[0]);
const onDrop = (e) => {
    e.preventDefault();
    dragActive.value = false;
    seleccionar(e.dataTransfer.files[0]);
};

const enviar = (dryRun = false) => {
    if (!form.archivo || !form.bodega_id) {
        avisar('Elegí un archivo y una bodega antes de enviar.', 'warning');
        return;
    }
    if (faltaPorque.value) {
        avisar('Falta el porqué: elige el motivo, escribe la observación y, si es compra o aporte, el tercero.', 'warning');
        return;
    }
    form.dry_run = dryRun;
    mensajeOverlay.value = dryRun
        ? 'Simulando carga sin guardar en BD…'
        : 'Cargando inventario en la base de datos…';
    form.post('/app/inventario/importar-cliente', {
        forceFormData: true,
        preserveScroll: false,
        onSuccess: () => {
            if (!dryRun) form.reset('archivo');
            // Scroll automático al banner de resultado.
            nextTick(() => {
                resultadoRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        },
    });
};

</script>

<template>
    <Head title="Importar inventario del cliente"/>
    <AppLayout>
        <!-- OVERLAY FULLSCREEN mientras procesa (bloquea todo el UI) -->
        <div v-if="form.processing"
             class="fixed inset-0 z-[9999] bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-surface-900 rounded-2xl shadow-2xl p-8 max-w-md w-full text-center">
                <Loader2 class="h-16 w-16 mx-auto text-brand-500 animate-spin"/>
                <h2 class="mt-4 text-xl font-bold text-surface-900 dark:text-surface-100">Procesando…</h2>
                <p class="mt-2 text-sm text-surface-600 dark:text-surface-400">{{ mensajeOverlay }}</p>
                <p class="mt-4 text-xs text-surface-500">Esto puede tardar entre 5 y 30 segundos. <strong>No cierres esta pestaña.</strong></p>
            </div>
        </div>

        <div class="max-w-3xl mx-auto space-y-4 p-4">

            <div class="card p-6">
                <div class="flex items-start gap-3">
                    <div class="p-2 rounded-lg bg-brand-500/10 text-brand-600">
                        <Upload class="h-6 w-6"/>
                    </div>
                    <div class="flex-1">
                        <h1 class="text-2xl font-bold">Importar inventario del cliente</h1>
                        <p class="text-sm text-surface-500 mt-1">
                            Sube el Excel formato <strong>INVENTARIO DR REPORTE</strong> (filas por categoría + productos).
                            Los productos se crean como <strong>agregados</strong> (colores surtidos, sin desglose por variante)
                            con el stock inicial en la ubicación que elijas.
                        </p>
                    </div>
                </div>

                <!-- INV-B3 · hint explícito de la auto-creación de productos. -->
                <div class="mt-4 p-3 rounded-lg bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 text-sm">
                    <div class="font-semibold mb-1 flex items-center gap-1.5">
                        <CheckCircle2 class="h-4 w-4"/>
                        Creación automática de productos
                    </div>
                    <div class="text-xs leading-relaxed">
                        Si una <strong>referencia</strong> del Excel no existe aún en el catálogo, se crea
                        automáticamente con los defaults del Excel (nombre, categoría, stock). No necesitás
                        crearlos manualmente antes.
                        <strong>Tip:</strong> probá primero con el modo "Simulación (dry-run)" para ver cuántos
                        se van a crear vs actualizar antes de aplicar.
                    </div>
                </div>
            </div>

            <!-- RESULTADO — banner GRANDE con emoji, colores fuertes y auto-scroll -->
            <div v-if="$page.props.flash?.importResumen" ref="resultadoRef"
                 class="rounded-2xl p-6 border-2 shadow-lg animate-pulse-slow"
                 :class="$page.props.flash.importResumen.dry_run
                    ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30'
                    : 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/30'">
                <div class="flex items-start gap-4">
                    <CheckCircle2 class="h-10 w-10 text-emerald-600 flex-shrink-0"/>
                    <div class="flex-1">
                        <div class="text-2xl font-bold text-emerald-900 dark:text-emerald-100">
                            {{ $page.props.flash.importResumen.accion }}
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
                            <div class="bg-white dark:bg-surface-900 rounded-lg p-3 text-center">
                                <div class="text-3xl font-bold text-emerald-600">{{ $page.props.flash.importResumen.creados }}</div>
                                <div class="text-xs text-surface-500 mt-1">Productos creados</div>
                            </div>
                            <div class="bg-white dark:bg-surface-900 rounded-lg p-3 text-center">
                                <div class="text-3xl font-bold text-blue-600">{{ $page.props.flash.importResumen.actualizados }}</div>
                                <div class="text-xs text-surface-500 mt-1">Actualizados</div>
                            </div>
                            <div class="bg-white dark:bg-surface-900 rounded-lg p-3 text-center">
                                <div class="text-3xl font-bold text-brand-600">{{ $page.props.flash.importResumen.movs }}</div>
                                <div class="text-xs text-surface-500 mt-1">Movs de kardex</div>
                            </div>
                            <div class="bg-white dark:bg-surface-900 rounded-lg p-3 text-center">
                                <div class="text-3xl font-bold text-surface-500">{{ $page.props.flash.importResumen.ignoradas }}</div>
                                <div class="text-xs text-surface-500 mt-1">Ignoradas</div>
                            </div>
                        </div>
                        <div v-if="$page.props.flash.importResumen.ubicaciones_creadas > 0"
                             class="mt-3 p-3 rounded bg-brand-50 border border-brand-200 text-surface-800 text-sm">
                            <div class="font-semibold flex items-center gap-1.5">
                                <MapPin class="h-4 w-4 text-brand-600"/>
                                {{ $page.props.flash.importResumen.ubicaciones_creadas }}
                                {{ $page.props.flash.importResumen.dry_run ? 'posiciones se crearían' : 'posiciones creadas' }}
                                dentro de la bodega
                            </div>
                            <ul class="mt-1 text-xs font-mono text-surface-600 list-disc list-inside">
                                <li v-for="(u, i) in ($page.props.flash.importResumen.ubicaciones_detalle || []).slice(0, 12)" :key="i">{{ u }}</li>
                            </ul>
                            <div v-if="($page.props.flash.importResumen.ubicaciones_detalle || []).length > 12"
                                 class="text-xs text-surface-500 mt-1">
                                … y {{ $page.props.flash.importResumen.ubicaciones_detalle.length - 12 }} más ·
                                la lista completa está en <a href="/app/inventario/ubicaciones" class="underline">Ubicaciones</a>.
                            </div>
                        </div>
                        <div v-if="($page.props.flash.importResumen.columnas_posicion || []).length"
                             class="mt-3 p-3 rounded bg-sky-50 border border-sky-200 text-sky-900 text-xs">
                            Columnas de posición detectadas en el Excel:
                            <strong>{{ ($page.props.flash.importResumen.columnas_posicion || []).join(', ') }}</strong>
                            · esas mandan sobre la posición del formulario.
                        </div>
                        <div v-if="$page.props.flash.importResumen.pos_ignoradas > 0" class="mt-3 p-3 rounded bg-amber-100 text-amber-900 text-sm">
                            <strong>⚠️ {{ $page.props.flash.importResumen.pos_ignoradas }}</strong> filas traían
                            pasillo/estante/nivel pero el destino elegido ya era una posición, no una bodega:
                            esa mercancía entró en el destino tal cual. Para repartirla, volvé a cargar eligiendo la bodega.
                        </div>
                        <div v-if="$page.props.flash.importResumen.omitidos_granular > 0" class="mt-3 p-3 rounded bg-amber-100 text-amber-900 text-sm">
                            <strong>⚠️ {{ $page.props.flash.importResumen.omitidos_granular }}</strong> productos saltados porque ya existen como granular. Renombra su referencia si son distintos.
                        </div>
                        <div v-if="$page.props.flash.importResumen.sin_cat > 0" class="mt-3 p-3 rounded bg-red-100 text-red-900 text-sm">
                            <strong>❌ {{ $page.props.flash.importResumen.sin_cat }}</strong> filas ignoradas por falta de categoría en el Excel.
                        </div>
                        <!-- Lo contable: dónde queda este ingreso en el libro. -->
                        <div class="mt-3 p-3 rounded bg-white dark:bg-surface-900 border border-surface-200 dark:border-surface-700 text-sm">
                            <div class="font-semibold flex items-center gap-1.5 text-surface-800 dark:text-surface-100">
                                <Scale class="h-4 w-4 text-brand-600"/>
                                Contabilidad · {{ $page.props.flash.importResumen.motivo }}
                            </div>
                            <div class="mt-2 grid sm:grid-cols-2 gap-2 text-xs">
                                <div class="p-2 rounded bg-blue-50 dark:bg-blue-950/30">
                                    <div class="text-surface-500">Débito · Inventario (1435)</div>
                                    <div class="font-mono font-bold text-blue-700 text-base">{{ money($page.props.flash.importResumen.valor) }}</div>
                                </div>
                                <div class="p-2 rounded bg-purple-50 dark:bg-purple-950/30">
                                    <div class="text-surface-500">Crédito · cuenta {{ $page.props.flash.importResumen.cuenta_contrapartida }}</div>
                                    <div class="font-mono font-bold text-purple-700 text-base">{{ money($page.props.flash.importResumen.valor) }}</div>
                                </div>
                            </div>
                            <div v-if="$page.props.flash.importResumen.columna_costo" class="mt-2 text-xs text-surface-600">
                                Costo leído de la columna <strong>«{{ $page.props.flash.importResumen.columna_costo }}»</strong>
                                en {{ $page.props.flash.importResumen.con_costo_excel }} referencias · las demás usan el costo del catálogo.
                            </div>
                            <div v-if="($page.props.flash.importResumen.costo_ilegible || []).length" class="mt-2 p-2 rounded bg-red-100 text-red-900 text-xs">
                                <strong>❌ No entendí el costo</strong> de estas filas (quedaron con el del catálogo):
                                <div class="mt-1 font-mono text-[11px]">{{ $page.props.flash.importResumen.costo_ilegible.slice(0, 10).join(' · ') }}</div>
                            </div>
                            <div v-if="$page.props.flash.importResumen.sin_costo > 0" class="mt-2 p-2 rounded bg-amber-100 text-amber-900 text-xs">
                                <strong>⚠️ {{ $page.props.flash.importResumen.sin_costo }}</strong> referencias no tienen costo:
                                su stock {{ $page.props.flash.importResumen.dry_run ? 'entraría' : 'entró' }},
                                pero <strong>no suman al asiento</strong>. Agrega una columna <strong>COSTO</strong> al Excel
                                (o ponles costo en el catálogo) antes de cargar si deben valer en el libro.
                                <div class="mt-1 font-mono text-[11px] text-amber-800">
                                    {{ ($page.props.flash.importResumen.sin_costo_detalle || []).slice(0, 15).join(' · ') }}
                                    <span v-if="$page.props.flash.importResumen.sin_costo > 15">…</span>
                                </div>
                            </div>
                            <div v-if="$page.props.flash.importResumen.ya_cargados > 0" class="mt-2 text-xs text-surface-600">
                                {{ $page.props.flash.importResumen.ya_cargados }} referencias ya se habían cargado en esta bodega y no se repiten.
                            </div>
                            <div v-if="$page.props.flash.importResumen.carga" class="mt-2 text-xs">
                                Documento <a :href="$page.props.flash.importResumen.carga.url" class="font-mono font-semibold text-brand-600 underline">{{ $page.props.flash.importResumen.carga.numero }}</a>
                                · ahí ves cada referencia y su asiento.
                            </div>
                        </div>
                        <div v-if="$page.props.flash.importResumen.dry_run" class="mt-4 text-sm text-blue-800 dark:text-blue-200 font-semibold">
                            ✅ La simulación completó. Ahora presiona <strong>"Cargar inventario definitivo"</strong> para guardar de verdad.
                        </div>
                        <div v-else class="mt-4 flex flex-wrap gap-2">
                            <a href="/app/catalogo" class="btn-primary">Ver productos en catálogo →</a>
                            <a href="/app/inventario/reporte-stock" class="btn-ghost">Ver stock por bodega →</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Errores de validación -->
            <div v-if="Object.keys($page.props.errors || {}).length" class="card p-4 border-l-4 border-l-red-500 bg-red-50 dark:bg-red-900/20">
                <div class="flex items-start gap-3">
                    <AlertTriangle class="h-5 w-5 text-red-600 flex-shrink-0 mt-0.5"/>
                    <div class="text-sm">
                        <div class="font-semibold text-red-900 dark:text-red-200">No pude procesar el archivo</div>
                        <ul class="mt-1 list-disc list-inside text-red-800 dark:text-red-300">
                            <li v-for="(err, k) in $page.props.errors" :key="k">{{ Array.isArray(err) ? err[0] : err }}</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="card p-6 space-y-4">
                <!-- El porqué del ingreso. Sin esto el inventario entra «porque se
                     subió» y el débito al 1435 queda sin contrapartida. -->
                <div>
                    <label class="text-sm font-semibold block mb-2 flex items-center gap-1.5">
                        <Scale class="h-4 w-4 text-brand-600"/>
                        1. ¿Por qué entra esta mercancía?
                    </label>
                    <div class="grid sm:grid-cols-2 gap-2">
                        <label v-for="m in motivos" :key="m.value"
                               class="flex items-start gap-2 p-3 rounded-lg border cursor-pointer transition-colors"
                               :class="form.motivo === m.value
                                   ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20'
                                   : 'border-surface-200 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-800'">
                            <input type="radio" v-model="form.motivo" :value="m.value" class="mt-1"/>
                            <span class="text-sm">
                                <span class="font-semibold block">{{ m.label }}</span>
                                <span class="text-xs text-surface-500 block">{{ m.ayuda }}</span>
                                <span class="text-[11px] text-surface-400 block mt-0.5">Débito 1435 · Crédito <span class="font-mono">{{ m.cuenta }}</span></span>
                            </span>
                        </label>
                    </div>

                    <div v-if="motivoSel?.requiere_tercero" class="mt-3 relative">
                        <label class="text-xs font-semibold block mb-1">
                            {{ form.motivo === 'compra' ? '¿A qué proveedor se le compró?' : '¿Qué socio la aporta?' }}
                        </label>
                        <div class="relative">
                            <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-400"/>
                            <input v-model="terceroTexto" :readonly="!!terceroSel" class="input w-full pl-10 pr-10"
                                   placeholder="Nombre o NIT / cédula" autocomplete="off"/>
                            <button v-if="terceroSel" type="button" @click="quitarTercero"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-surface-400 hover:text-red-600" title="Quitar">
                                <X class="h-4 w-4"/>
                            </button>
                        </div>
                        <div v-if="terceroResultados.length"
                             class="absolute z-10 left-0 right-0 mt-1 max-h-56 overflow-y-auto rounded-lg border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900 shadow-lg">
                            <button v-for="t in terceroResultados" :key="t.id" type="button" @click="elegirTercero(t)"
                                    class="w-full text-left px-3 py-2 text-sm hover:bg-surface-50 dark:hover:bg-surface-800 border-b border-surface-100 dark:border-surface-800 last:border-b-0">
                                <span class="font-semibold">{{ t.nombre }}</span>
                                <span v-if="t.documento" class="text-xs text-surface-500 ml-1">{{ t.documento }}</span>
                            </button>
                        </div>
                        <p class="text-[11px] text-surface-500 mt-1">Si no aparece, créalo primero en Contactos.</p>
                    </div>

                    <div class="mt-3 grid sm:grid-cols-3 gap-3">
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold block mb-1">Observación <span class="text-red-600">*</span></label>
                            <textarea v-model="form.observacion" rows="2" maxlength="1000" class="input w-full"
                                      placeholder="Ej: Inventario físico de la bodega Dropi al 17-nov, contado por Aracely"></textarea>
                        </div>
                        <div>
                            <label class="text-xs font-semibold block mb-1">Soporte <span class="font-normal text-surface-400">(opcional)</span></label>
                            <input v-model="form.soporte" type="text" maxlength="60" class="input w-full" placeholder="Nº factura o acta"/>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="text-sm font-semibold block mb-2">2. Bodega donde cargar el inventario</label>
                    <select v-model="form.bodega_id" class="input w-full">
                        <optgroup v-for="g in grupos" :key="g" :label="g">
                            <option v-for="b in bodegas.filter((x) => x.grupo === g)" :key="b.id" :value="b.id">
                                {{ b.label }}
                            </option>
                        </optgroup>
                    </select>
                    <p class="text-xs text-surface-500 mt-1">
                        Todos los productos entrarán con su stock inicial aquí.
                    </p>
                </div>

                <!-- Dónde queda parada la mercancía dentro de la bodega. Sin
                     esto la carga dejaba las 134 referencias como un bulto
                     único y después el conteo había que hacerlo caminando. -->
                <div>
                    <label class="text-sm font-semibold block mb-2 flex items-center gap-1.5">
                        <MapPin class="h-4 w-4 text-brand-600"/>
                        3. ¿En qué posición de la bodega? <span class="text-xs font-normal text-surface-400">(opcional)</span>
                    </label>
                    <div v-if="destinoEsBodega" class="grid grid-cols-3 gap-3">
                        <div>
                            <input v-model="form.pasillo" type="text" class="input w-full min-h-11" placeholder="4" maxlength="30"/>
                            <div class="text-xs text-surface-500 mt-1">Pasillo</div>
                        </div>
                        <div>
                            <input v-model="form.estante" type="text" class="input w-full min-h-11" placeholder="6" maxlength="30"/>
                            <div class="text-xs text-surface-500 mt-1">Estante</div>
                        </div>
                        <div>
                            <input v-model="form.nivel" type="text" class="input w-full min-h-11" placeholder="3" maxlength="30"/>
                            <div class="text-xs text-surface-500 mt-1">Nivel</div>
                        </div>
                    </div>
                    <p v-if="destinoEsBodega" class="text-xs text-surface-500 mt-2">
                        Esto es el respaldo: <strong>si el Excel trae columnas llamadas «Pasillo», «Estante» o
                        «Nivel», manda el Excel</strong> y cada producto queda en su propio sitio. Lo que escribas acá
                        se usa para las filas que no las traigan. La posición se crea sola si no existía
                        (ej. pañales de recién nacido en pasillo 4, estante 6, nivel 3 → se crea
                        <code>Pasillo 4 · Estante 6 · Nivel 3</code> dentro de la bodega).
                        Si lo dejás vacío, todo entra a la bodega sin posición.
                    </p>
                    <p v-else class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2 mt-1">
                        Elegiste una posición concreta, no una bodega: la mercancía entra ahí tal cual.
                        Para repartirla por pasillo/estante/nivel, elegí arriba la bodega que los contiene.
                    </p>
                </div>

                <div>
                    <label class="text-sm font-semibold block mb-2">4. Nombre de la hoja del Excel</label>
                    <input v-model="form.hoja" type="text" class="input w-full" placeholder="Hoja1"/>
                    <p class="text-xs text-surface-500 mt-1">
                        Por defecto <code>Hoja1</code>. Si tu Excel tiene otro nombre (ej. <code>REPORTE</code>), cámbialo aquí.
                    </p>
                </div>

                <div>
                    <label class="text-sm font-semibold block mb-2">5. Archivo Excel</label>
                    <div
                        @dragenter.prevent="dragActive = true"
                        @dragover.prevent="dragActive = true"
                        @dragleave.prevent="dragActive = false"
                        @drop="onDrop"
                        :class="[
                            'border-2 border-dashed rounded-lg p-8 text-center transition-colors',
                            dragActive ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20' : 'border-surface-300 dark:border-surface-700',
                            nombreArchivo ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-500' : ''
                        ]"
                    >
                        <FileSpreadsheet v-if="nombreArchivo" class="h-12 w-12 mx-auto text-emerald-600"/>
                        <Upload v-else class="h-12 w-12 mx-auto text-surface-400"/>
                        <div v-if="nombreArchivo" class="mt-3 font-semibold text-emerald-700 dark:text-emerald-300">
                            {{ nombreArchivo }} · {{ tamanoArchivo }}
                        </div>
                        <div v-else class="mt-3 text-sm text-surface-500">
                            Arrastra el archivo aquí o
                            <label class="text-brand-600 font-semibold cursor-pointer hover:underline">
                                selecciónalo
                                <input type="file" @change="onFileInput" accept=".xlsx,.xls" class="hidden"/>
                            </label>
                        </div>
                        <div class="text-xs text-surface-400 mt-1">Formatos: .xlsx, .xls · Max 10 MB</div>
                    </div>
                </div>

                <div class="pt-4 border-t border-surface-200 dark:border-surface-800 flex flex-col sm:flex-row gap-2">
                    <button
                        @click="enviar(true)"
                        :disabled="!form.archivo || faltaPorque || form.processing"
                        class="btn-ghost flex-1 disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        <Loader2 v-if="form.processing && form.dry_run" class="h-4 w-4 animate-spin"/>
                        Probar sin guardar (dry-run)
                    </button>
                    <button
                        @click="enviar(false)"
                        :disabled="!form.archivo || faltaPorque || form.processing"
                        class="btn-primary flex-1 disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        <Loader2 v-if="form.processing && !form.dry_run" class="h-4 w-4 animate-spin"/>
                        <Upload v-else class="h-4 w-4"/>
                        Cargar inventario definitivo
                    </button>
                </div>
                <p class="text-xs text-surface-500 text-center">
                    Recomendado: primero <strong>Probar sin guardar</strong> para revisar el conteo, luego cargar.
                </p>
            </div>

            <div class="card p-4 text-sm text-surface-500 space-y-2">
                <div class="font-semibold text-surface-700 dark:text-surface-200">¿Qué hace este importador?</div>
                <ul class="list-disc list-inside space-y-1">
                    <li>Lee filas de <strong>categoría</strong> (solo columna A con nombre) y <strong>producto</strong> (referencia + descripción + existencia + variación, y opcional costo).</li>
                    <li>Crea/actualiza cada producto como <strong>agregado</strong> (colores surtidos).</li>
                    <li>Inserta el stock inicial en el kardex de la bodega elegida (idempotente: puedes re-correr sin duplicar).</li>
                    <li>
                        Si el Excel trae una columna <strong>Costo</strong> o <strong>Costo unitario</strong> (de la E en adelante),
                        ese es el costo de cada referencia en esta carga; acepta <code>45000</code>, <code>$ 45.000</code> o
                        <code>12.500,50</code>. Si la fila no lo trae, se usa el costo del catálogo. Una columna
                        «Costo total» se ignora (es cantidad × costo). El costo del Excel llena el catálogo sólo
                        en productos nuevos o que estaban en 0.
                    </li>
                    <li>
                        Deja un documento <strong>CI-…</strong> con el motivo y la observación, y escribe su
                        <strong>asiento contable</strong>: débito al inventario (1435) contra la cuenta del motivo.
                        Es contabilidad interna: no se envía a SIIGO.
                    </li>
                    <li>
                        Si el Excel trae columnas <strong>Pasillo</strong>, <strong>Estante</strong> o
                        <strong>Nivel</strong> (en cualquier posición, las reconoce por el nombre del encabezado),
                        cada producto entra en esa posición dentro de la bodega y la posición se crea sola si no existía.
                    </li>
                    <li>Salta productos que ya existen como <strong>granulares</strong> (para no romper el toggle).</li>
                    <li>Ignora filas de totales, fechas y fila de header.</li>
                </ul>
            </div>

        </div>
    </AppLayout>
</template>

<style scoped>
@keyframes pulse-slow {
    0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
    50% { box-shadow: 0 0 0 12px rgba(16, 185, 129, 0); }
}
.animate-pulse-slow {
    animation: pulse-slow 2s ease-in-out 3;
}
</style>
