<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, Save, Trash2, Eye, Plus, CheckCircle2, Copy, Upload } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import { mensajeDeError } from '@/composables/useMensajeError';

const props = defineProps({
    tipo: { type: String, required: true },
    plantillas: { type: Array, required: true },
    plantilla: { type: Object, required: true },
});

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

const form = reactive({
    id: props.plantilla.id,
    tipo: props.tipo,
    nombre: props.plantilla.nombre,
    predeterminada: props.plantilla.predeterminada,
    activa: props.plantilla.activa,
    config: JSON.parse(JSON.stringify(props.plantilla.config)),
});
const procesando = ref(false);
const previewUrl = ref('');
const previewLoading = ref(false);
const errorMsg = ref('');

const tipos = [
    { key: 'factura', label: 'Factura de venta' },
    { key: 'oc', label: 'Orden de compra' },
    { key: 'recepcion', label: 'Recepción' },
    { key: 'nc', label: 'Nota crédito' },
    { key: 'cotizacion', label: 'Cotización' },
    { key: 'egreso', label: 'Comprobante egreso' },
];

const cambiarTipo = (t) => router.get('/app/plantillas', { tipo: t });
const abrirPlantilla = (id) => router.get('/app/plantillas', { tipo: form.tipo, id });

const nuevaPlantilla = () => {
    router.get('/app/plantillas', { tipo: form.tipo, id: 0 });
    // El controller creará una vacía al pasar id inexistente; forzamos reset del form:
    form.id = null;
    form.nombre = 'Nueva plantilla';
};

// Otra versión a partir de la que está abierta: se guarda como plantilla
// nueva y no le quita la predeterminada a nadie hasta que se marque.
const duplicar = () => {
    form.id = null;
    form.nombre = `${form.nombre} (copia)`;
    form.predeterminada = false;
    guardar();
};

// El logo y la firma se suben como archivo y viajan embebidos (data URI):
// así no dependen de que una URL externa siga viva.
const MAX_IMAGEN = 1_000_000;
const subirImagen = (evento, campo) => {
    const archivo = evento.target.files?.[0];
    evento.target.value = '';
    if (!archivo) return;
    if (!/^image\/(png|jpe?g|gif|webp)$/.test(archivo.type)) {
        errorMsg.value = 'La imagen debe ser PNG, JPG, GIF o WEBP.';
        return;
    }
    if (archivo.size > MAX_IMAGEN) {
        errorMsg.value = 'La imagen pesa más de 1 MB. Redúcela e inténtalo de nuevo.';
        return;
    }
    const lector = new FileReader();
    lector.onload = () => { form.config[campo] = lector.result; errorMsg.value = ''; };
    lector.readAsDataURL(archivo);
};
const esEmbebida = (v) => typeof v === 'string' && v.startsWith('data:');

const guardar = () => {
    if (procesando.value) return;
    procesando.value = true;
    errorMsg.value = '';
    router.post('/app/plantillas', form, {
        preserveScroll: true,
        onError: (e) => { errorMsg.value = Object.values(e).flat().find(v => typeof v === 'string') || 'Error al guardar'; },
        onFinish: () => { procesando.value = false; },
    });
};

const eliminar = () => {
    if (!form.id) return;
    modalConfirm.value = {
        titulo: '¿Eliminar esta plantilla?',
        mensaje: 'No se puede deshacer.',
        color: 'rose',
        textoConfirmar: 'Eliminar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.delete(`/app/plantillas/${form.id}`, { preserveScroll: true });
        },
    };
};

// Preview: POST devuelve PDF, lo cargamos en iframe con blob URL.
const generarPreview = async () => {
    previewLoading.value = true;
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const res = await fetch('/app/plantillas/preview', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/pdf',
            },
            body: JSON.stringify({ tipo: form.tipo, config: form.config }),
        });
        if (!res.ok) {
            const err = await res.text();
            throw new Error(err.slice(0, 200));
        }
        const blob = await res.blob();
        if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = URL.createObjectURL(blob);
    } catch (e) {
        errorMsg.value = mensajeDeError(e, 'No pude generar la vista previa');
    } finally {
        previewLoading.value = false;
    }
};

onMounted(generarPreview);

// Debounce automático del preview cuando cambia config
let deb;
watch(() => form.config, () => {
    clearTimeout(deb);
    deb = setTimeout(generarPreview, 800);
}, { deep: true });
</script>

<template>
    <Head title="Plantillas de documento"/>
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <FileText class="h-6 w-6 text-brand-600"/>
                        Plantillas de documento
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">Personaliza logo, colores, encabezado, pie y bloques de tus PDFs.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="nuevaPlantilla" class="btn-ghost"><Plus class="h-4 w-4"/> Nueva</button>
                    <button @click="guardar" :disabled="procesando" class="btn-primary">
                        <Save class="h-4 w-4"/> {{ procesando ? 'Guardando…' : 'Guardar' }}
                    </button>
                </div>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="errorMsg" class="p-3 rounded-lg bg-red-50 border-l-4 border-red-500 text-red-700 text-sm">
                {{ errorMsg }}
            </div>

            <!-- Selector tipo -->
            <div class="card p-3 flex items-center gap-2 flex-wrap">
                <span class="text-xs uppercase tracking-widest font-bold text-surface-500">Tipo de documento:</span>
                <button v-for="t in tipos" :key="t.key" @click="cambiarTipo(t.key)"
                    :class="['px-3 py-1.5 text-sm rounded-lg',
                        tipo === t.key ? 'bg-brand-600 text-white' : 'bg-surface-100 dark:bg-surface-800 hover:bg-surface-200']">
                    {{ t.label }}
                </button>
            </div>

            <!-- Lista de plantillas del tipo -->
            <div v-if="plantillas.length > 0" class="card p-3 flex items-center gap-2 flex-wrap">
                <span class="text-xs uppercase tracking-widest font-bold text-surface-500">Plantillas ({{ plantillas.length }}):</span>
                <button v-for="p in plantillas" :key="p.id" @click="abrirPlantilla(p.id)"
                    :class="['px-3 py-1 text-sm rounded-lg flex items-center gap-1.5 border',
                        form.id === p.id ? 'border-brand-600 bg-brand-50 text-brand-800' : 'border-surface-200 hover:bg-surface-100']">
                    <CheckCircle2 v-if="p.predeterminada" class="h-3 w-3 text-emerald-600"/>
                    {{ p.nombre }}
                </button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
                <!-- Panel edición -->
                <div class="lg:col-span-2 space-y-4">
                    <div class="card p-4 space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-surface-600 mb-1">Nombre</label>
                            <input v-model="form.nombre" class="input w-full" placeholder="Ej: Estilo GB corporativo"/>
                        </div>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="checkbox" v-model="form.predeterminada" class="rounded"/>
                                Predeterminada
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="checkbox" v-model="form.activa" class="rounded"/>
                                Activa
                            </label>
                        </div>
                    </div>

                    <div class="card p-4 space-y-3">
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Diseño</div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-xs">Color primario</label>
                                <input type="color" v-model="form.config.colores.primario" class="w-full h-9 rounded"/>
                            </div>
                            <div>
                                <label class="text-xs">Color secundario</label>
                                <input type="color" v-model="form.config.colores.secundario" class="w-full h-9 rounded"/>
                            </div>
                            <div>
                                <label class="text-xs">Texto</label>
                                <input type="color" v-model="form.config.colores.texto" class="w-full h-9 rounded"/>
                            </div>
                            <div>
                                <label class="text-xs">Fondo acento (tablas)</label>
                                <input type="color" v-model="form.config.colores.acento" class="w-full h-9 rounded"/>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-xs">Tipografía</label>
                                <select v-model="form.config.tipografia" class="input w-full">
                                    <option value="sans">Sans (default)</option>
                                    <option value="serif">Serif</option>
                                    <option value="mono">Mono</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs">Espaciado</label>
                                <select v-model="form.config.layout" class="input w-full">
                                    <option value="espacioso">Espacioso</option>
                                    <option value="compacto">Compacto</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="card p-4 space-y-3">
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Encabezado</div>
                        <div>
                            <label class="text-xs">Logo</label>
                            <div class="flex items-center gap-2">
                                <input v-if="!esEmbebida(form.config.logo_url)" v-model="form.config.logo_url" class="input w-full font-mono text-xs" placeholder="https://… o sube un archivo" />
                                <img v-else :src="form.config.logo_url" alt="Logo" class="h-9 max-w-[60%] object-contain border rounded bg-white p-1"/>
                                <label class="btn-ghost text-xs cursor-pointer whitespace-nowrap">
                                    <Upload class="h-3 w-3"/> Subir
                                    <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" class="hidden" @change="subirImagen($event, 'logo_url')"/>
                                </label>
                                <button v-if="form.config.logo_url" type="button" @click="form.config.logo_url = null" class="btn-ghost text-xs text-red-600">Quitar</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-xs">Alto logo (px)</label>
                                <input type="number" min="20" max="150" v-model.number="form.config.logo_alto_px" class="input w-full"/>
                            </div>
                            <div>
                                <label class="text-xs">Alineación encabezado extra</label>
                                <select v-model="form.config.encabezado_alineacion" class="input w-full">
                                    <option value="izquierda">Izquierda</option>
                                    <option value="centrado">Centrado</option>
                                    <option value="derecha">Derecha</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs">Texto de encabezado extra (opcional)</label>
                            <textarea v-model="form.config.encabezado_extra" rows="2" class="input w-full text-sm" placeholder="Ej: Especialistas en productos para bebé desde 2010"></textarea>
                        </div>
                    </div>

                    <div class="card p-4 space-y-3">
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Bloques</div>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" v-model="form.config.mostrar_qr" class="rounded"/>
                            Mostrar QR DIAN
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" v-model="form.config.mostrar_bloque_banco" class="rounded"/>
                            Mostrar datos bancarios
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" v-model="form.config.mostrar_bloque_retenciones" class="rounded"/>
                            Mostrar retenciones
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" v-model="form.config.mostrar_bloque_notas" class="rounded"/>
                            Mostrar observaciones
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" v-model="form.config.mostrar_totales_en_letras" class="rounded"/>
                            Total en letras
                        </label>
                    </div>

                    <div class="card p-4 space-y-3">
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Términos y pie</div>
                        <div>
                            <label class="text-xs">Términos y condiciones (aparece al final)</label>
                            <textarea v-model="form.config.terminos_condiciones" rows="3" class="input w-full text-sm"
                                placeholder="Pagos a 30 días. Devoluciones dentro de los primeros 15 días..."></textarea>
                        </div>
                        <div>
                            <label class="text-xs">Pie de página (una línea)</label>
                            <input v-model="form.config.pie_html" class="input w-full"
                                placeholder="Gracias por confiar en GREAT BABY S.A.S. · WhatsApp 300 123 4567"/>
                        </div>
                    </div>

                    <div class="card p-4 space-y-3">
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Firma</div>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" v-model="form.config.mostrar_firma" class="rounded"/>
                            Mostrar firma al final del documento
                        </label>
                        <template v-if="form.config.mostrar_firma">
                            <div>
                                <label class="text-xs">Imagen de la firma (opcional)</label>
                                <div class="flex items-center gap-2">
                                <input v-if="!esEmbebida(form.config.firma_url)" v-model="form.config.firma_url" class="input w-full font-mono text-xs" placeholder="https://… o sube un archivo" />
                                <img v-else :src="form.config.firma_url" alt="Firma" class="h-9 max-w-[60%] object-contain border rounded bg-white p-1"/>
                                <label class="btn-ghost text-xs cursor-pointer whitespace-nowrap">
                                    <Upload class="h-3 w-3"/> Subir
                                    <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" class="hidden" @change="subirImagen($event, 'firma_url')"/>
                                </label>
                                <button v-if="form.config.firma_url" type="button" @click="form.config.firma_url = null" class="btn-ghost text-xs text-red-600">Quitar</button>
                            </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-xs">Nombre</label>
                                    <input v-model="form.config.firma_nombre" class="input w-full" placeholder="Quien firma"/>
                                </div>
                                <div>
                                    <label class="text-xs">Cargo</label>
                                    <input v-model="form.config.firma_cargo" class="input w-full" placeholder="Ej: Gerente administrativa"/>
                                </div>
                            </div>
                            <div class="w-1/2">
                                <label class="text-xs">Alto de la firma (px)</label>
                                <input type="number" min="20" max="150" v-model.number="form.config.firma_alto_px" class="input w-full"/>
                            </div>
                        </template>
                    </div>

                    <div class="card p-4 space-y-3">
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Marca de agua</div>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" v-model="form.config.watermark_activo" class="rounded"/>
                            Activar marca de agua
                        </label>
                        <div>
                            <label class="text-xs">Texto marca de agua</label>
                            <input v-model="form.config.sello_texto" class="input w-full" placeholder="Ej: BORRADOR / PAGADA"/>
                        </div>
                    </div>

                    <div v-if="form.id" class="pt-2 flex items-center gap-2 flex-wrap">
                        <button @click="duplicar" :disabled="procesando" class="btn-ghost text-sm">
                            <Copy class="h-4 w-4"/> Duplicar como otra versión
                        </button>
                        <button @click="eliminar" class="btn-ghost text-red-600 text-sm">
                            <Trash2 class="h-4 w-4"/> Eliminar esta plantilla
                        </button>
                    </div>
                </div>

                <!-- Preview PDF -->
                <div class="lg:col-span-3">
                    <div class="card p-3 sticky top-20">
                        <div class="flex items-center justify-between mb-2">
                            <div class="text-xs uppercase tracking-widest font-bold text-brand-600 flex items-center gap-2">
                                <Eye class="h-3 w-3"/> Preview PDF
                            </div>
                            <button @click="generarPreview" :disabled="previewLoading" class="btn-ghost text-xs">
                                {{ previewLoading ? 'Generando…' : 'Actualizar' }}
                            </button>
                        </div>
                        <div class="bg-surface-100 dark:bg-surface-800 rounded overflow-hidden" style="height: 80vh;">
                            <iframe v-if="previewUrl" :src="previewUrl" class="w-full h-full border-0" title="Preview PDF"></iframe>
                            <div v-else class="flex items-center justify-center h-full text-surface-500 text-sm">
                                {{ previewLoading ? 'Generando preview…' : 'Sin preview aún. Guarda una factura primero.' }}
                            </div>
                        </div>
                        <p class="text-xs text-surface-500 mt-2">
                            El preview usa la última factura como muestra. Los cambios en el editor se aplican automáticamente al PDF.
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
