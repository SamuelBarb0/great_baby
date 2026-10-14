<script setup>
import { ref, reactive, watch, computed, onBeforeUnmount, onMounted } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import {
    Package, ArrowLeft, Save, Info, DollarSign, Ruler, FileText,
    Cloud, CloudOff, Trash2, Plus, X, Search, Copy, AlertTriangle,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import { useMoney } from '@/composables/useMoney';
import { avisar, avisarError } from '@/composables/useAviso';
import { mensajeDeError } from '@/composables/useMensajeError';
const { money } = useMoney();

const props = defineProps({
    historial: { type: Array, default: () => [] },
    producto: { type: Object, default: null },
    catalogos: { type: Object, required: true },
    // Precio por lista del producto agregado; vacío en los granulares,
    // que llevan el precio en cada variante.
    precios_lista: { type: Array, default: () => [] },
    // Qué le falta al producto para poder venderse y costearse.
    listo_para_vender: { type: Object, default: () => ({}) },
});

const esNuevo = !props.producto;
const tab = ref('general');

// Estructura completa del form.
const form = useForm({
    referencia: props.producto?.referencia || '',
    nombre: props.producto?.nombre || '',
    descripcion: props.producto?.descripcion || '',
    descripcion_ampliada: props.producto?.descripcion_ampliada || '',
    ficha_tecnica: props.producto?.ficha_tecnica || '',
    activo: props.producto?.activo ?? true,
    // Pestaña 1: General
    marca_id: props.producto?.marca_id || null,
    categoria_id: props.producto?.categoria_id || null,
    coleccion_id: props.producto?.coleccion_id || null,
    // Si el catálogo tiene una sola línea, un producto nuevo ya nace con ella:
    // un desplegable obligatorio con una única respuesta posible sólo cobra un
    // clic. En un producto que ya existe no se toca, para no ensuciar el
    // guardia de cambios sin pedir.
    linea_id: props.producto?.linea_id
        || (! props.producto && props.catalogos?.lineas?.length === 1 ? props.catalogos.lineas[0].id : null),
    grupo_id: props.producto?.grupo_id || null,
    subgrupo_id: props.producto?.subgrupo_id || null,
    clase_id: props.producto?.clase_id || null,
    unidad_medida_id: props.producto?.unidad_medida_id || null,
    unidad_compra_id: props.producto?.unidad_compra_id || null,
    factor_conversion: props.producto?.factor_conversion || null,
    posicion_arancelaria: props.producto?.posicion_arancelaria || '',
    reposicion_max_dias: props.producto?.reposicion_max_dias || null,
    proteger_precio: props.producto?.proteger_precio ?? false,
    maneja_lotes: props.producto?.maneja_lotes ?? false,
    maneja_seriales: props.producto?.maneja_seriales ?? false,
    es_estadistico: props.producto?.es_estadistico ?? false,
    requiere_talla: props.producto?.requiere_talla ?? false,
    es_set: props.producto?.es_set ?? false,
    neto: props.producto?.neto ?? true,
    // Default FALSE para nuevos · modo "agregado" = 1 producto SIIGO (sin
    // variantes). Si el usuario necesita variantes, lo activa manualmente.
    // Antes era true por default y causaba "0 productos creados en SIIGO"
    // cuando el user guardaba sin agregar variantes.
    desglose_stock: props.producto?.desglose_stock ?? false,
    stock_directo: props.producto?.stock_directo || 0,
    // Dónde entra ese stock inicial. Null = la primera activa, que es como se
    // comportaba antes y es lo que esperan los importadores.
    stock_ubicacion_id: null,
    // Pestaña 2: Comercial
    impuesto_id: props.producto?.impuesto_id || null,
    precio_proveedor: props.producto?.precio_proveedor || 0,
    rentabilidad_pct: props.producto?.rentabilidad_pct || null,
    descuento_default_pct: props.producto?.descuento_default_pct || null,
    valor_gasto_venta_niif: props.producto?.valor_gasto_venta_niif || null,
    valor_neto_realizable_niif: props.producto?.valor_neto_realizable_niif || null,
    cta_ingreso: props.producto?.cta_ingreso || '',
    cta_iva_venta: props.producto?.cta_iva_venta || '',
    cta_costo: props.producto?.cta_costo || '',
    cta_inventario: props.producto?.cta_inventario || '',
    cta_devolucion: props.producto?.cta_devolucion || '',
    cta_descuento: props.producto?.cta_descuento || '',
    centro_costo: props.producto?.centro_costo || '',
    notas_contables: props.producto?.notas_contables || '',
    // Pestaña 3: Características
    peso_gr: props.producto?.peso_gr || null,
    alto_cm: props.producto?.alto_cm || null,
    ancho_cm: props.producto?.ancho_cm || null,
    largo_cm: props.producto?.largo_cm || null,
    // M:M
    accesorios: props.producto?.accesorios || [],
    sustitutos: props.producto?.sustitutos || [],
    // Sprint Variantes · colección editable inline (color/diseño/talla/EAN/stock_min).
    variantes: props.producto?.variantes || [],
    // ─── SIIGO Paridad · campos nuevos ────────────────────────
    tipo_siigo: props.producto?.tipo_siigo || 'Product',
    stock_control: props.producto?.stock_control ?? true,
    tax_classification: props.producto?.tax_classification || 'Taxed',
    tax_included: props.producto?.tax_included ?? false,
    tax_consumption_value: props.producto?.tax_consumption_value || null,
    modelo_siigo: props.producto?.modelo_siigo || '',
    barcode_padre: props.producto?.barcode_padre || '',
    unit_label: props.producto?.unit_label || 'Unidad',
    impuestos_ids: props.producto?.impuestos_ids || [],
    // FASE H · Paridad 1:1 form SIIGO
    visible_en_facturas: props.producto?.visible_en_facturas ?? true,
    retencion_siigo_id: props.producto?.retencion_siigo_id || null,
    impuesto_cargo_dos_id: props.producto?.impuesto_cargo_dos_id || null,
    reference_fabrica: props.producto?.reference_fabrica || '',
    stock_minimo: props.producto?.stock_minimo || null,
    // FASE F2.A4 · override por producto del grupo SIIGO
    siigo_account_group_override: props.producto?.siigo_account_group_override || null,
});

// QA-FIX #14 · Cascada Línea → Grupo → Subgrupo → Clase con filtros LOCALES
// (todo el árbol viene precargado desde el controller — ver catalogosParaForm).
// Antes: 3 fetch secuenciales por selección (~2s cumulativo). Ahora: 0 round-trips.
const grupos = computed(() =>
    form.linea_id ? (props.catalogos.grupos || []).filter(g => g.linea_id === form.linea_id) : []
);
const subgrupos = computed(() =>
    form.grupo_id ? (props.catalogos.subgrupos || []).filter(s => s.grupo_id === form.grupo_id) : []
);
const clases = computed(() =>
    form.subgrupo_id ? (props.catalogos.clases || []).filter(c => c.subgrupo_id === form.subgrupo_id) : []
);

// Solo reset de hijos si el usuario cambia el padre (no en hidratación).
let hidratando = ! esNuevo;
watch(() => form.linea_id, () => { if (!hidratando) { form.grupo_id = null; form.subgrupo_id = null; form.clase_id = null; } });
watch(() => form.grupo_id, () => { if (!hidratando) { form.subgrupo_id = null; form.clase_id = null; } });
watch(() => form.subgrupo_id, () => { if (!hidratando) { form.clase_id = null; } });
// Después del primer tick, aceptar cambios como acciones del usuario.
if (hidratando) setTimeout(() => { hidratando = false; }, 100);

// SIIGO account_group · local al form porque no está en productos sino en categorias.
// Si el user cambia este selector, actualizamos la categoría local linkeada
// (si tiene una) para que persista a BD. Alternativa: enviar un campo extra al
// controller y resolverlo ahí — más limpio, pero cambia la API. Por ahora
// readonly visualizador que muestra lo que SÍ tiene la categoría local hoy.
const categoriaActual = computed(() =>
    form.categoria_id
        ? (props.catalogos.categorias || []).find(c => c.id === form.categoria_id)
        : null
);
const siigo_account_group_id_local = ref(categoriaActual.value?.siigo_account_group_id || null);
const aplicarAccountGroupACategoria = () => {
    // Si editan este selector sin elegir categoría local, no hacemos nada.
    // En Fase 5 se puede abrir un modal "Editar categoría → asignar account_group".
    // Por ahora el valor queda local al form (futuro: PATCH /categorias/:id).
};

// Verificación en vivo de la referencia + autogenerar.
// Fix de raíz · antes el user podía poner un código ya existente y solo se
// enteraba al Guardar con un 500 de MySQL. Ahora se avisa al blur del campo.
import axios from 'axios';
const refConflicto = ref(null);  // {id, nombre, eliminado} si existe otro
const refOk = ref(false);         // true si pasó la verificación OK
const refVerificando = ref(false);

const verificarReferencia = async () => {
    const r = (form.referencia || '').trim();
    if (!r) { refConflicto.value = null; refOk.value = false; return; }
    refVerificando.value = true;
    try {
        const { data } = await axios.get('/app/catalogo/productos/verificar-referencia', {
            params: { ref: r, except: props.producto?.id || 0 },
        });
        if (data.existe) {
            refConflicto.value = { id: data.id, nombre: data.nombre, eliminado: data.eliminado };
            refOk.value = false;
        } else {
            refConflicto.value = null;
            refOk.value = true;
        }
    } catch (e) {
        // no bloqueamos al usuario · la validación del server hará su parte
        refConflicto.value = null; refOk.value = false;
    } finally {
        refVerificando.value = false;
    }
};

const autogenerar = async () => {
    try {
        const { data } = await axios.get('/app/catalogo/productos/sugerir-referencia');
        form.referencia = data.referencia;
        refConflicto.value = null;
        refOk.value = true;
    } catch (e) { /* ignore */ }
};

// Buscador de productos para accesorios/sustitutos
const buscar = ref('');
const resultados = ref([]);
const buscando = ref(false);
let debSearch;
// FASE F4.M7 · cancelar timers pendientes al desmontar · si Aracely
// navega a otro producto mientras un debSearch está pendiente, la
// respuesta llegaba a un componente ya destruido y Vue warning.
onBeforeUnmount(() => {
    if (debSearch) clearTimeout(debSearch);
});

// PROD-10 · Dirty-state guard. Si Aracely edita cualquier input y navega
// fuera (link Inertia, reload, cerrar pestaña), avisa antes de perder el
// trabajo. Se arma onMounted y se desarma onBeforeUnmount para evitar
// que la advertencia aparezca en otras páginas.
const beforeUnloadHandler = (e) => {
    if (form.isDirty && !form.processing) {
        e.preventDefault();
        e.returnValue = '';
    }
};
let removeInertiaGuard = null;
// Bandera para dejar pasar el viaje que se repite después de confirmar.
let saliendoSinGuardar = false;

/**
 * El guardado también es una navegación, y no hay que advertir sobre ella.
 *
 * Inertia emite `before` ANTES de marcar el formulario como `processing`, así
 * que al apretar Guardar la guarda veía «hay cambios y no está procesando» y
 * cancelaba el viaje. Con el confirm() nativo de antes esto no se veía: dentro
 * del iframe devolvía falso y el guardado se cancelaba en silencio — apretabas
 * Guardar y no pasaba nada. Esta bandera marca los viajes que dispara la propia
 * pantalla.
 */
let guardandoAhora = false;
onMounted(() => {
    window.addEventListener('beforeunload', beforeUnloadHandler);
    // Inertia router.on('before') intercepta clicks en <Link> y router.visit()
    //
    // Antes acá iba un confirm() nativo porque Inertia necesita la respuesta
    // en el acto, y además queda bloqueado dentro del iframe de la app de
    // escritorio: la navegación se escapaba sin preguntar nada y Aracely
    // perdía la edición. Ahora se cancela siempre el viaje, se pregunta con
    // el modal y, si dice que sí, se repite el mismo destino con la guarda
    // desactivada.
    removeInertiaGuard = router.on('before', (event) => {
        if (saliendoSinGuardar) {
            saliendoSinGuardar = false;
            return;
        }
        if (guardandoAhora) return;
        if (!form.isDirty || form.processing) return;

        const visita = event.detail.visit;
        event.preventDefault();
        modalConfirm.value = {
            titulo: 'Tenés cambios sin guardar',
            mensaje: 'Si salís ahora se pierde lo que editaste en este producto.',
            color: 'amber',
            textoConfirmar: 'Salir sin guardar',
            onConfirmar: () => {
                modalConfirm.value = null;
                saliendoSinGuardar = true;
                router.visit(visita.url, {
                    method: visita.method,
                    data: visita.data,
                    replace: visita.replace,
                    preserveState: visita.preserveState,
                    preserveScroll: visita.preserveScroll,
                });
            },
        };
    });
});
onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', beforeUnloadHandler);
    if (removeInertiaGuard) removeInertiaGuard();
});
watch(buscar, () => {
    clearTimeout(debSearch);
    if (buscar.value.length < 2) { resultados.value = []; return; }
    debSearch = setTimeout(async () => {
        buscando.value = true;
        try {
            // FASE F4.M5 · axios en vez de fetch · ya trae XSRF-TOKEN cookie
            // y header X-CSRF-TOKEN de meta; fetch nativo omitía el header
            // CSRF y rompía bajo Sanctum stateful.
            const { data } = await axios.get(`/app/catalogo/productos-buscar`, {
                params: { q: buscar.value },
            });
            resultados.value = data;
        } finally { buscando.value = false; }
    }, 300);
});

const agregarAccesorio = (p) => {
    if (form.accesorios.find(a => a.id === p.id)) return;
    form.accesorios.push({ id: p.id, referencia: p.referencia, nombre: p.nombre, cantidad: 1 });
    buscar.value = '';
};
const quitarAccesorio = (id) => { form.accesorios = form.accesorios.filter(a => a.id !== id); };

const agregarSustituto = (p) => {
    if (form.sustitutos.find(s => s.id === p.id)) return;
    form.sustitutos.push({ id: p.id, referencia: p.referencia, nombre: p.nombre });
    buscar.value = '';
};
const quitarSustituto = (id) => { form.sustitutos = form.sustitutos.filter(s => s.id !== id); };

// Guardar
/**
 * Precios por lista del producto agregado.
 *
 * Se guardan aparte del formulario principal: son filas de otra tabla y
 * mezclarlas en el `put` del producto obligaría a tocar la validación de todo
 * lo demás. Dejar una casilla vacía quita el precio, que es como se deja de
 * ofrecer el producto a los clientes de esa lista.
 */
const precios = ref(JSON.parse(JSON.stringify(props.precios_lista || [])));
const guardandoPrecios = ref(false);
const listasConPrecio = computed(() => precios.value.filter(l => Number(l.precio) > 0).length);

const guardarPrecios = () => {
    if (guardandoPrecios.value || ! props.producto) return;
    guardandoPrecios.value = true;
    router.put(`/app/catalogo/productos/${props.producto.id}/precios`, {
        precios: precios.value.map(l => ({ lista_id: l.lista_id, precio: l.precio === '' ? null : l.precio })),
    }, {
        preserveScroll: true,
        onFinish: () => { guardandoPrecios.value = false; },
    });
};

const guardar = () => {
    // Aplanar accesorios y sustitutos para el backend
    const payload = { ...form.data(), accesorios: form.accesorios.map(a => ({ id: a.id, cantidad: a.cantidad })), sustitutos: form.sustitutos.map(s => s.id) };
    guardandoAhora = true;
    const liberar = { onFinish: () => { guardandoAhora = false; } };

    if (esNuevo) {
        form.transform(() => payload).post('/app/catalogo/productos', liberar);
    } else {
        form.transform(() => payload).put(`/app/catalogo/productos/${props.producto.id}`, liberar);
    }
};

// Sprint confirm → modal · reemplazamos window.confirm() nativo (bloqueado
// en iframe). Un solo ref `modalConfirm` sirve para todos los pedidos.
const modalConfirm = ref(null);
const eliminar = () => {
    modalConfirm.value = {
        titulo: `¿Eliminar producto ${props.producto.referencia}?`,
        mensaje: 'Pasa a la papelera · puedes restaurarlo dentro de 30 días.',
        color: 'rose',
        textoConfirmar: 'Eliminar',
        onConfirmar: () => {
            modalConfirm.value = null;
            router.delete(`/app/catalogo/productos/${props.producto.id}`);
        },
    };
};

// FASE C1 · Duplica el producto con sufijo -COPY-N.
// PROD-7 · Modal propio con checkboxes para que Aracely decida alcance:
//   - Variantes (default ON) · si está en modo granular, clona la estructura.
//   - Precios por lista (default OFF) · las listas suelen revisarse manualmente
//     en el clon para no arrastrar un precio obsoleto.
const modalClonar = ref(null);
const abrirClonar = () => {
    modalClonar.value = {
        incluirVariantes: true,
        incluirPrecios: false,
    };
};
const confirmarClonar = () => {
    const payload = {
        incluir_variantes: modalClonar.value.incluirVariantes,
        incluir_precios: modalClonar.value.incluirPrecios,
    };
    modalClonar.value = null;
    router.post(`/app/catalogo/productos/${props.producto.id}/clonar`, payload);
};

// FASE D1-D2 · Visor en vivo contra SIIGO + diff.
const siigoVivo = ref(null);
const cargandoSiigo = ref(false);
const verEnSiigo = async () => {
    cargandoSiigo.value = true;
    try {
        const { data } = await axios.get(`/app/siigo/verificar/producto/${props.producto.id}`);
        siigoVivo.value = data;
    } catch (e) {
        avisarError(mensajeDeError(e, 'No pude consultar el estado en SIIGO'));
    } finally {
        cargandoSiigo.value = false;
    }
};
// D2 · Compara campos clave ERP vs SIIGO · array de {campo, local, siigo, igual}
const diffCampos = computed(() => {
    const r = siigoVivo.value?.respuesta_siigo;
    if (! r || siigoVivo.value?.ok !== true) return [];
    const p = props.producto;
    const pares = [
        ['Código', p.referencia, r.code],
        ['Nombre', p.nombre, r.name],
        ['Referencia fábrica', p.reference_fabrica, r.reference],
        ['Activo', p.activo ? 'Sí' : 'No', r.active ? 'Sí' : 'No'],
        ['Tipo', p.tipo_siigo || 'Product', r.type],
        ['Lleva stock', p.stock_control ? 'Sí' : 'No', r.stock_control ? 'Sí' : 'No'],
        ['Marca', p.marca_nombre || '—', r.brand || '—'],
        ['Modelo', p.modelo_siigo || '—', r.model || '—'],
        // Fix bug · antes la columna ERP comparaba r.prices contra sí mismo
        // (siempre verde). Ahora ERP usa p.precio_proveedor; SIIGO usa r.prices.
        ['Precio', p.precio_proveedor ?? '—', (r.prices?.[0]?.price_list?.[0]?.value ?? '—').toString()],
    ];
    return pares.map(([k, local, siigo]) => ({
        campo: k,
        local: local ?? '—',
        siigo: siigo ?? '—',
        igual: String(local ?? '').trim() === String(siigo ?? '').trim(),
    }));
});

// FASE D3 · Fuerza push manual
const forzandoSync = ref(false);
const forzarSync = () => {
    modalConfirm.value = {
        titulo: '¿Reenviar este producto a SIIGO ahora?',
        mensaje: 'Lo pondremos de primero en la cola para que SIIGO lo reciba cuanto antes.',
        color: 'amber',
        textoConfirmar: 'Reenviar',
        onConfirmar: async () => {
            modalConfirm.value = null;
            forzandoSync.value = true;
            try {
                const { data } = await axios.post(`/app/catalogo/productos/${props.producto.id}/forzar-sync`);
                // Refresca el panel SIIGO para ver el nuevo estado tras encolar.
                await verEnSiigo();
                avisar(data.mensaje);
            } catch (e) {
                avisarError(mensajeDeError(e, 'No pude encolar el sync a SIIGO'));
            } finally {
                forzandoSync.value = false;
            }
        },
    };
};

// FASE F2.A13 · estados globales que combinan el form.processing con otras
// acciones asíncronas (imagen, sync, verificación) · el botón Guardar queda
// disabled si cualquier operación está en curso.
const subiendoImagen = ref(false);
const estaCargando = computed(() =>
    form.processing || subiendoImagen.value || cargandoSiigo.value || forzandoSync.value
);

// FASE H · Imágenes del producto
const subirImagen = async (event, orden) => {
    const file = event.target.files?.[0];
    if (!file) return;
    if (file.size > 1024 * 1024) {
        avisar('La imagen supera 1 MB · reducí el tamaño antes de subir.', 'warning');
        event.target.value = '';
        return;
    }
    subiendoImagen.value = true;
    const data = new FormData();
    data.append('imagen', file);
    data.append('orden', orden);
    try {
        await axios.post(`/app/catalogo/productos/${props.producto.id}/imagenes`, data, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        router.reload({ only: ['producto'] });
    } catch (e) {
        avisarError(mensajeDeError(e, 'No pude subir la imagen'));
    } finally {
        subiendoImagen.value = false;
        event.target.value = '';
    }
};
const eliminarImagen = (id) => {
    modalConfirm.value = {
        titulo: '¿Eliminar esta imagen?',
        mensaje: 'La imagen se borra del producto y del almacenamiento.',
        color: 'rose',
        textoConfirmar: 'Eliminar',
        onConfirmar: async () => {
            modalConfirm.value = null;
            try {
                await axios.delete(`/app/catalogo/productos/${props.producto.id}/imagenes/${id}`);
                router.reload({ only: ['producto'] });
            } catch (e) {
                avisarError(mensajeDeError(e, 'No pude borrar la imagen'));
            }
        },
    };
};

// Sprint Variantes · pestaña nueva, visible siempre pero utilizable solo
// con desglose_stock=true (banner interno lo explica).
const tabs = [
    { key: 'general', label: '1. General', icon: Info },
    { key: 'variantes', label: '2. Variantes', icon: Package },
    { key: 'comercial', label: '3. Comercial', icon: DollarSign },
    { key: 'caracteristicas', label: '4. Características', icon: Ruler },
    { key: 'ficha', label: '5. Ficha técnica', icon: FileText },
    { key: 'siigo', label: '6. SIIGO', icon: Cloud },
];

// Sprint Variantes · helpers de edición inline.
const agregarVariante = () => {
    form.variantes.push({
        id: null,
        color_id: null,
        diseno_id: null,
        talla_id: null,
        codigo_barras: '',
        // Default 0 (coherente con backend NOT NULL). Antes era null y
        // reventaba al guardar hasta que el controller lo normalizaba.
        stock_minimo: 0,
    });
};
const quitarVariante = (idx) => {
    const v = form.variantes[idx];
    // Confirmación solo si la variante ya fue guardada (tiene id en BD).
    // Para filas nuevas que apenas agregaste no tiene sentido molestar.
    if (v?.id) {
        modalConfirm.value = {
            titulo: '¿Eliminar esta variante guardada?',
            mensaje: `Se borrará la variante #${v.id} del producto. Si estaba en SIIGO también se desactivará allá.`,
            color: 'rose',
            textoConfirmar: 'Eliminar variante',
            onConfirmar: () => {
                modalConfirm.value = null;
                form.variantes.splice(idx, 1);
            },
        };
        return;
    }
    form.variantes.splice(idx, 1);
};
const generarMatriz = () => {
    // Toma los colores, tallas y diseños marcados en los <select multiple>
    // internos y crea el producto cartesiano, respetando lo que ya existe.
    // Incluir diseño en el eje evita duplicados color/talla con diseño
    // distinto (ej. "Rojo-Estrellas-M" y "Rojo-Flores-M" son variantes
    // legítimas, no una la copia de la otra).
    const cs = matrizColores.value.map(Number),
          ts = matrizTallas.value.map(Number),
          ds = matrizDisenos.value.length ? matrizDisenos.value.map(Number) : [null];
    if (!cs.length || !ts.length) return;
    const yaExiste = (c, t, d) => form.variantes.some(v =>
        Number(v.color_id) === c && Number(v.talla_id) === t && (v.diseno_id ?? null) === d
    );
    for (const c of cs) {
        for (const t of ts) {
            for (const d of ds) {
                if (!yaExiste(c, t, d)) {
                    form.variantes.push({
                        id: null, color_id: c, diseno_id: d, talla_id: t,
                        codigo_barras: '', stock_minimo: 0,
                    });
                }
            }
        }
    }
    matrizColores.value = [];
    matrizTallas.value = [];
    matrizDisenos.value = [];
};
const matrizColores = ref([]);
const matrizTallas = ref([]);
const matrizDisenos = ref([]);
</script>

<template>
    <Head :title="esNuevo ? 'Nuevo producto' : `Producto ${producto.referencia}`"/>
    <AppLayout>
        <div class="max-w-6xl mx-auto space-y-4">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <Link href="/app/catalogo/productos" class="text-sm text-brand-600 hover:underline inline-flex items-center gap-1">
                        <ArrowLeft class="h-4 w-4"/> Volver a Productos
                    </Link>
                    <h1 class="text-2xl font-bold flex items-center gap-2 mt-1">
                        <Package class="h-6 w-6 text-brand-600"/>
                        {{ esNuevo ? 'Nuevo producto' : `Producto ${producto.referencia}` }}
                    </h1>
                    <!-- FASE F2.A5 · Toggle Producto/Servicio en header como el form oficial de SIIGO -->
                    <div class="mt-2 inline-flex items-center gap-1 p-1 bg-surface-100 dark:bg-surface-800 rounded-lg">
                        <button type="button" @click="form.tipo_siigo = 'Product'"
                                :class="['text-xs px-3 py-1 rounded-md font-semibold transition',
                                         form.tipo_siigo === 'Product' ? 'bg-brand-600 text-white shadow' : 'text-surface-600 hover:text-surface-900']">
                            Producto
                        </button>
                        <button type="button" @click="form.tipo_siigo = 'Service'"
                                :class="['text-xs px-3 py-1 rounded-md font-semibold transition',
                                         form.tipo_siigo === 'Service' ? 'bg-brand-600 text-white shadow' : 'text-surface-600 hover:text-surface-900']">
                            Servicio
                        </button>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <div v-if="!esNuevo" class="flex items-center gap-1 text-xs">
                        <Cloud v-if="producto.siigo_id" class="h-4 w-4 text-emerald-600"/>
                        <CloudOff v-else class="h-4 w-4 text-amber-600"/>
                        <span :class="producto.siigo_id ? 'text-emerald-700' : 'text-amber-700'">
                            {{ producto.siigo_id ? `SIIGO: ${producto.siigo_code}` : 'Pendiente SIIGO' }}
                        </span>
                    </div>
                    <button v-if="!esNuevo" @click="abrirClonar"
                            class="text-sm inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-sky-500 text-sky-700 hover:bg-sky-50"
                            title="Duplicar · crea un producto nuevo con los mismos datos">
                        <Copy class="h-4 w-4"/> Duplicar
                    </button>
                    <button v-if="!esNuevo" @click="eliminar" class="btn-ghost text-red-500 hover:text-red-700 text-sm">
                        <Trash2 class="h-4 w-4"/>
                    </button>
                    <button @click="guardar" :disabled="estaCargando"
                            class="btn-primary text-sm inline-flex items-center gap-1"
                            :class="estaCargando ? 'opacity-50 cursor-wait' : ''">
                        <Save class="h-4 w-4"/>
                        {{ form.processing ? 'Guardando…' : (subiendoImagen ? 'Subiendo imagen…' : 'Guardar') }}
                    </button>
                </div>
            </div>

            <!--
                Qué le falta a este producto para servir de algo.

                Se puede guardar con referencia, nombre y línea, y queda
                perfectamente inútil: sin precio de venta no entra en ningún
                pedido, y sin costo sus movimientos de kardex no se pueden
                asentar, así que la venta no registra el costo y el margen
                bruto sale 100%. Las dos cosas fallaban DESPUÉS, lejos de acá
                y sin explicación. El formulario de contactos ya avisa así;
                este no decía nada.

                Avisa, no bloquea: crear el producto y ponerle precio después
                es una forma legítima de trabajar.
            -->
            <div v-if="producto && (listo_para_vender.sin_costo || listo_para_vender.sin_precio || listo_para_vender.variantes_sin_precio)"
                 class="rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-900/20 px-4 py-3">
                <div class="flex items-start gap-2">
                    <AlertTriangle class="h-4 w-4 text-amber-600 shrink-0 mt-0.5"/>
                    <div class="text-sm text-amber-900 dark:text-amber-100">
                        <p class="font-semibold">Este producto todavía no está listo para operar.</p>
                        <ul class="mt-1 space-y-0.5 text-amber-800 dark:text-amber-200">
                            <li v-if="listo_para_vender.sin_precio">
                                <strong>No tiene precio de venta</strong>, así que no se puede pedir.
                                <template v-if="form.desglose_stock">Se carga en cada variante.</template>
                                <template v-else>Se carga abajo, en la pestaña Comercial.</template>
                            </li>
                            <li v-else-if="listo_para_vender.variantes_sin_precio">
                                <strong>{{ listo_para_vender.variantes_sin_precio }} variante(s) sin precio</strong>:
                                esas no se pueden pedir.
                            </li>
                            <li v-if="listo_para_vender.sin_costo">
                                <strong>No tiene precio de proveedor</strong>, que es el costo. Sin él la venta no
                                registra cuánto costó la mercancía y el margen sale inflado.
                            </li>
                        </ul>
                    </div>
                </div>
            </div>


            <!-- Tabs -->
            <div class="flex items-center gap-1 border-b border-surface-200 dark:border-surface-800 overflow-x-auto">
                <button v-for="t in tabs" :key="t.key" @click="tab = t.key"
                        :class="['px-4 py-2 text-sm font-semibold border-b-2 flex items-center gap-1 whitespace-nowrap',
                                 tab === t.key ? 'border-brand-500 text-brand-600' : 'border-transparent text-surface-500 hover:text-surface-700']">
                    <component :is="t.icon" class="h-4 w-4"/> {{ t.label }}
                </button>
            </div>

            <!-- PESTAÑA 1: GENERAL -->
            <div v-if="tab === 'general'" class="card p-5 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-surface-600 flex items-center justify-between">
                            <span>Referencia / SKU *</span>
                            <button type="button" @click="autogenerar" class="text-[11px] text-sky-600 hover:underline">
                                Generar automáticamente
                            </button>
                        </label>
                        <input v-model="form.referencia"
                               @blur="verificarReferencia"
                               class="input w-full font-mono"
                               :class="refConflicto ? 'border-red-500 bg-red-50' : (refOk ? 'border-emerald-500 bg-emerald-50' : '')"
                               required maxlength="100" placeholder="Ej: GB-0001"/>
                        <div v-if="form.errors.referencia" class="text-xs text-red-600 mt-1">{{ form.errors.referencia }}</div>
                        <div v-else-if="refConflicto" class="text-xs text-red-600 mt-1">
                            ⚠ Ya existe un producto con esta referencia:
                            <Link :href="`/app/catalogo/productos/${refConflicto.id}`" class="underline font-semibold">
                                {{ refConflicto.nombre }}
                            </Link>
                            <span v-if="refConflicto.eliminado">(eliminado · usa otro código)</span>
                        </div>
                        <div v-else-if="refOk" class="text-xs text-emerald-700 mt-1">✓ Referencia disponible</div>
                        <div v-else-if="refVerificando" class="text-xs text-surface-500 mt-1">Verificando…</div>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600">Nombre / Descripción *</label>
                        <input v-model="form.nombre" class="input w-full" required maxlength="200"/>
                        <div v-if="form.errors.nombre" class="text-xs text-red-600">{{ form.errors.nombre }}</div>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-semibold text-surface-600">Descripción</label>
                    <textarea v-model="form.descripcion" rows="2" class="input w-full"></textarea>
                </div>
                <div>
                    <label class="text-xs font-semibold text-surface-600">Descripción ampliada</label>
                    <textarea v-model="form.descripcion_ampliada" rows="2" class="input w-full" placeholder="Descripción adicional (opcional · útil para servicios y productos con specs largas)"></textarea>
                </div>

                <!-- Jerarquía SIIGO -->
                <div class="border-t pt-4">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-2">Clasificación SIIGO</div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div>
                            <!-- Sin asterisco: el servidor la acepta nula. Decía
                                 obligatoria y no lo era. -->
                            <label class="text-xs font-semibold text-surface-600">Línea</label>
                            <select v-model="form.linea_id" class="input w-full">
                                <option :value="null">— Selecciona —</option>
                                <option v-for="l in catalogos.lineas" :key="l.id" :value="l.id">{{ l.codigo }} · {{ l.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Grupo</label>
                            <select v-model="form.grupo_id" class="input w-full" :disabled="!form.linea_id">
                                <option :value="null">— Selecciona —</option>
                                <option v-for="g in grupos" :key="g.id" :value="g.id">{{ g.codigo }} · {{ g.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Subgrupo</label>
                            <select v-model="form.subgrupo_id" class="input w-full" :disabled="!form.grupo_id">
                                <option :value="null">— Selecciona —</option>
                                <option v-for="s in subgrupos" :key="s.id" :value="s.id">{{ s.codigo }} · {{ s.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Clase</label>
                            <select v-model="form.clase_id" class="input w-full" :disabled="!form.subgrupo_id">
                                <option :value="null">— Selecciona —</option>
                                <option v-for="c in clases" :key="c.id" :value="c.id">{{ c.codigo }} · {{ c.nombre }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Marca/Categoría/Colección (ERP interno) -->
                <div class="border-t pt-4">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-2">Clasificación ERP interna</div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Marca</label>
                            <select v-model="form.marca_id" class="input w-full">
                                <option :value="null">— Sin marca —</option>
                                <option v-for="m in catalogos.marcas" :key="m.id" :value="m.id">{{ m.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Categoría</label>
                            <select v-model="form.categoria_id" class="input w-full">
                                <option :value="null">—</option>
                                <option v-for="c in catalogos.categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Colección</label>
                            <select v-model="form.coleccion_id" class="input w-full">
                                <option :value="null">—</option>
                                <option v-for="c in catalogos.colecciones" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Unidades + Factor conversión -->
                <div class="border-t pt-4">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-2">Unidades</div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Unidad de venta</label>
                            <select v-model="form.unidad_medida_id" class="input w-full">
                                <option :value="null">— Selecciona —</option>
                                <option v-for="u in catalogos.unidades" :key="u.id" :value="u.id">{{ u.codigo }} · {{ u.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Unidad de compra</label>
                            <select v-model="form.unidad_compra_id" class="input w-full">
                                <option :value="null">— Igual que venta —</option>
                                <option v-for="u in catalogos.unidades" :key="u.id" :value="u.id">{{ u.codigo }} · {{ u.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Factor conversión (compra→venta)</label>
                            <input v-model="form.factor_conversion" type="number" step="0.0001" class="input w-full" placeholder="Ej: 12 (1 caja = 12 uds)"/>
                        </div>
                    </div>
                </div>

                <!-- Aduana + Reposición -->
                <div class="border-t pt-4 grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-surface-600">Posición arancelaria</label>
                        <input v-model="form.posicion_arancelaria" class="input w-full font-mono" maxlength="20" placeholder="Ej: 6111.20.00.00"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600">Tiempo reposición máx (días)</label>
                        <input v-model="form.reposicion_max_dias" type="number" min="0" class="input w-full"/>
                    </div>
                </div>

                <!-- Flags SIIGO -->
                <div class="border-t pt-4">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-2">Comportamiento</div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.activo" class="rounded"/> Activo</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.proteger_precio" class="rounded"/> Precio protegido</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.maneja_lotes" class="rounded"/> Maneja lotes</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.maneja_seriales" class="rounded"/> Maneja seriales</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.es_estadistico" class="rounded"/> Estadístico</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.requiere_talla" class="rounded"/> Requiere talla</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.es_set" class="rounded"/> Es set/kit</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.neto" class="rounded"/> Neto (aplica desc.)</label>
                    </div>
                </div>

                <!-- Toggle con copy humanizado (Fix UX Aracely) -->
                <div class="border-t pt-4">
                    <label class="flex items-center gap-2 text-sm font-semibold">
                        <input type="checkbox" v-model="form.desglose_stock" class="rounded"/>
                        ¿Este producto se vende en varios colores o tallas?
                    </label>
                    <p class="text-[11px] text-surface-500 mt-1">
                        <strong>Enciende</strong> si vendes el mismo producto en distintas versiones (Rojo‑M, Rojo‑L, Azul‑M…). Vas a poder capturar cada variante en la pestaña 2 y cada una va a SIIGO como su propio producto para facturar bien.<br>
                        <strong>Déjalo apagado</strong> si es un producto único sin variaciones (ej. un biberón, un peluche). SIIGO lo verá como un solo renglón.
                    </p>
                    <!-- El stock inicial necesita saber DÓNDE entra. Antes sólo
                         se pedía la cantidad y el kardex la dejaba en la primera
                         ubicación activa: se montaba una bodega nueva, se
                         escribían 60 unidades y aparecían en otra bodega sin que
                         nadie se enterara hasta ir a despachar. -->
                    <div v-if="!form.desglose_stock" class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Stock inicial</label>
                            <input v-model="form.stock_directo" type="number" min="0" class="input w-40"/>
                            <p class="text-[10px] text-surface-500 mt-1">Cantidad de unidades que tienes hoy en bodega.</p>
                        </div>
                        <div v-if="Number(form.stock_directo) > 0">
                            <label class="text-xs font-semibold text-surface-600">¿En cuál bodega está?</label>
                            <select v-model="form.stock_ubicacion_id" class="input w-full">
                                <option :value="null">— la primera disponible —</option>
                                <option v-for="u in (catalogos.ubicaciones || [])" :key="u.id" :value="u.id">
                                    {{ u.codigo }} · {{ u.nombre }}
                                </option>
                            </select>
                            <p class="text-[10px] mt-1"
                               :class="form.stock_ubicacion_id ? 'text-surface-500' : 'text-amber-700'">
                                {{ form.stock_ubicacion_id
                                    ? 'Ahí van a quedar las unidades.'
                                    : 'Sin elegir, las unidades caen en la primera bodega de la lista.' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PESTAÑA 2 · VARIANTES (Sprint Variantes) -->
            <div v-if="tab === 'variantes'" class="card p-5 space-y-4">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div>
                        <h3 class="font-semibold text-base">Variantes del producto</h3>
                        <p class="text-xs text-surface-600 mt-1">
                            Cada variante viaja a SIIGO como <strong>producto independiente</strong>
                            (code = referencia padre + sufijo de color/talla).
                        </p>
                    </div>
                    <button type="button" @click="agregarVariante"
                            class="btn btn-primary text-sm flex items-center gap-1">
                        <Plus class="w-4 h-4"/> Variante
                    </button>
                </div>

                <!-- Banner si desglose_stock está apagado -->
                <div v-if="!form.desglose_stock" class="bg-amber-50 border border-amber-300 text-amber-900 rounded px-3 py-2 text-xs">
                    El toggle <strong>"Desglosar stock por variante"</strong> está apagado
                    (pestaña General). Puedes capturar variantes aquí, pero el kardex local
                    seguirá en modo agregado. Actívalo si manejas stock por color/talla.
                </div>

                <!-- Generador por matriz -->
                <div class="border rounded p-3 bg-surface-50/50">
                    <p class="text-xs font-semibold text-surface-700 mb-2">Generar matriz (color × talla)</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2 items-end">
                        <div>
                            <label class="text-[11px] text-surface-600">Colores</label>
                            <select v-model="matrizColores" multiple size="4" class="input w-full text-xs">
                                <option v-for="c in (catalogos.colores || [])" :key="c.id" :value="c.id">
                                    {{ c.codigo }} · {{ c.nombre }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[11px] text-surface-600">Tallas</label>
                            <select v-model="matrizTallas" multiple size="4" class="input w-full text-xs">
                                <option v-for="t in (catalogos.tallas || [])" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                            </select>
                        </div>
                        <button type="button" @click="generarMatriz"
                                :disabled="!matrizColores.length || !matrizTallas.length"
                                class="btn btn-secondary text-xs h-9 disabled:opacity-40">
                            Crear {{ (matrizColores.length || 0) * (matrizTallas.length || 0) }} variantes
                        </button>
                    </div>
                    <p class="text-[10px] text-surface-500 mt-2">Mantén Ctrl/Cmd para seleccionar varios. Las combinaciones que ya existen no se duplican.</p>
                </div>

                <!-- Tabla editable -->
                <div v-if="form.variantes.length === 0" class="text-sm text-surface-500 italic text-center py-6">
                    Sin variantes. Agrega una fila manualmente o genera la matriz arriba.
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-xs border-collapse">
                        <thead class="bg-surface-100 text-surface-700">
                            <tr>
                                <th class="text-left p-2 border">Color</th>
                                <th class="text-left p-2 border">Diseño</th>
                                <th class="text-left p-2 border">Talla</th>
                                <th class="text-left p-2 border">Código de barras (EAN)</th>
                                <th class="text-left p-2 border w-24">Stock mín.</th>
                                <th class="p-2 border w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(v, idx) in form.variantes" :key="idx" class="hover:bg-surface-50">
                                <td class="p-1 border">
                                    <!-- Fix v-model.number sobre select con :value=null · el modifier
                                         convierte null → NaN y rompe el validator exists:colores,id.
                                         Vue infiere el tipo correcto desde el :value del option. -->
                                    <select v-model="v.color_id" class="input w-full text-xs">
                                        <option :value="null">—</option>
                                        <option v-for="c in (catalogos.colores || [])" :key="c.id" :value="c.id">
                                            {{ c.codigo }} · {{ c.nombre }}
                                        </option>
                                    </select>
                                </td>
                                <td class="p-1 border">
                                    <select v-model="v.diseno_id" class="input w-full text-xs">
                                        <option :value="null">—</option>
                                        <option v-for="d in (catalogos.disenos || [])" :key="d.id" :value="d.id">
                                            {{ d.codigo }} · {{ d.nombre }}
                                        </option>
                                    </select>
                                </td>
                                <td class="p-1 border">
                                    <select v-model="v.talla_id" class="input w-full text-xs">
                                        <option :value="null">—</option>
                                        <option v-for="t in (catalogos.tallas || [])" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                                    </select>
                                </td>
                                <td class="p-1 border">
                                    <input v-model="v.codigo_barras" type="text" maxlength="100"
                                           placeholder="Opcional · se genera si vacío"
                                           class="input w-full text-xs"/>
                                </td>
                                <td class="p-1 border">
                                    <input v-model.number="v.stock_minimo" type="number" min="0"
                                           class="input w-full text-xs"/>
                                </td>
                                <td class="p-1 border text-center">
                                    <button type="button" @click="quitarVariante(idx)"
                                            class="text-rose-600 hover:text-rose-800" title="Eliminar">
                                        <X class="w-4 h-4"/>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-[11px] text-surface-500">
                    {{ form.variantes.length }} variante(s) · se guardan al pulsar
                    <strong>Guardar</strong> arriba, en la misma transacción que el producto.
                </p>
            </div>

            <!-- PESTAÑA 3: COMERCIAL -->
            <div v-if="tab === 'comercial'" class="card p-5 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-surface-600">Impuesto</label>
                        <select v-model="form.impuesto_id" class="input w-full">
                            <option :value="null">— Selecciona —</option>
                            <option v-for="i in catalogos.impuestos" :key="i.id" :value="i.id">{{ i.nombre }} ({{ i.porcentaje }}%)</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600">Precio proveedor</label>
                        <input :value="form.precio_proveedor" @input="form.precio_proveedor = Number(($event.target.value || '').replace(/[^\d.]/g,'')) || 0"
                               type="text" inputmode="decimal" class="input w-full font-mono"/>
                        <p v-if="form.precio_proveedor > 0" class="text-[11px] text-surface-500 mt-1">
                            = <strong>{{ money(form.precio_proveedor) }}</strong>
                        </p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600">Rentabilidad % (control)</label>
                        <input v-model="form.rentabilidad_pct" type="number" step="0.01" min="0" max="100" class="input w-full"/>
                        <p class="text-[10px] text-surface-500 mt-1">Bloquea facturar bajo esta rentabilidad</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600">% Descuento default</label>
                        <input v-model="form.descuento_default_pct" type="number" step="0.01" min="0" max="100" class="input w-full"/>
                    </div>
                </div>

                <!-- Cuentas contables -->
                <div class="border-t pt-4">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-2">Cuentas contables (PUC Great Baby)</div>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Cta. ingreso</label>
                            <input v-model="form.cta_ingreso" class="input w-full font-mono" maxlength="30" placeholder="4135"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Cta. IVA venta</label>
                            <input v-model="form.cta_iva_venta" class="input w-full font-mono" maxlength="30" placeholder="240805"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Cta. costo</label>
                            <input v-model="form.cta_costo" class="input w-full font-mono" maxlength="30" placeholder="6135"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Cta. inventario</label>
                            <input v-model="form.cta_inventario" class="input w-full font-mono" maxlength="30" placeholder="1435"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Cta. devolución</label>
                            <input v-model="form.cta_devolucion" class="input w-full font-mono" maxlength="30" placeholder="4175"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Cta. descuento</label>
                            <input v-model="form.cta_descuento" class="input w-full font-mono" maxlength="30" placeholder="5305"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Centro de costo</label>
                            <input v-model="form.centro_costo" class="input w-full font-mono" maxlength="30"/>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="text-xs font-semibold text-surface-600">Notas contables</label>
                        <textarea v-model="form.notas_contables" rows="2" class="input w-full"></textarea>
                    </div>
                </div>

                <!-- NIIF -->
                <div class="border-t pt-4">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-2">NIIF</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Valor gasto venta</label>
                            <input v-model="form.valor_gasto_venta_niif" type="number" step="0.01" min="0" class="input w-full font-mono"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Valor neto realizable</label>
                            <input v-model="form.valor_neto_realizable_niif" type="number" step="0.01" min="0" class="input w-full font-mono"/>
                        </div>
                    </div>
                </div>

                <!-- Accesorios y Sustitutos -->
                <div class="border-t pt-4">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-2">Accesorios (facturados junto)</div>
                    <div class="space-y-1 mb-2">
                        <div v-for="a in form.accesorios" :key="a.id" class="flex items-center gap-2 text-sm p-2 rounded bg-surface-50 dark:bg-surface-900">
                            <span class="font-mono font-bold">{{ a.referencia }}</span>
                            <span class="flex-1">{{ a.nombre }}</span>
                            <input v-model="a.cantidad" type="number" min="1" class="input w-20 text-sm"/>
                            <button type="button" @click="quitarAccesorio(a.id)" class="text-red-500 hover:text-red-700"><X class="h-4 w-4"/></button>
                        </div>
                    </div>
                    <div class="relative">
                        <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-surface-400"/>
                        <input v-model="buscar" class="input pl-9 w-full text-sm" placeholder="Buscar producto para agregar accesorio/sustituto…"/>
                        <div v-if="resultados.length" class="absolute z-10 left-0 right-0 mt-1 max-h-48 overflow-y-auto rounded border bg-white dark:bg-surface-900 shadow-lg">
                            <div v-for="p in resultados" :key="p.id" class="flex items-center gap-2 p-2 hover:bg-surface-50 text-sm">
                                <span class="font-mono font-bold flex-1">{{ p.referencia }} · {{ p.nombre }}</span>
                                <button type="button" @click="agregarAccesorio(p)" class="btn-ghost text-xs">+Accesorio</button>
                                <button type="button" @click="agregarSustituto(p)" class="btn-ghost text-xs">+Sustituto</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-t pt-4">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-2">Sustitutos (alternativas si sin stock)</div>
                    <div class="space-y-1">
                        <div v-for="s in form.sustitutos" :key="s.id" class="flex items-center gap-2 text-sm p-2 rounded bg-surface-50 dark:bg-surface-900">
                            <span class="font-mono font-bold">{{ s.referencia }}</span>
                            <span class="flex-1">{{ s.nombre }}</span>
                            <button type="button" @click="quitarSustituto(s.id)" class="text-red-500 hover:text-red-700"><X class="h-4 w-4"/></button>
                        </div>
                        <div v-if="!form.sustitutos.length" class="text-xs text-surface-500">Sin sustitutos definidos.</div>
                    </div>
                </div>

                <!--
                    Precio de venta por lista · sólo para productos agregados.

                    En un producto granular el precio vive en cada variante. Un
                    agregado no tenía dónde guardarlo: el pedido le buscaba precio,
                    no lo encontraba y descartaba la línea en silencio. Resultado
                    medido el 2026-10-08: 15 de los 31 productos activos no se
                    podían vender y nadie sabía por qué.

                    Se muestran TODAS las listas, también las que están en blanco,
                    porque una lista sin precio es un grupo de clientes que no le
                    puede comprar este producto.
                -->
                <div v-if="! form.desglose_stock && producto" class="pt-4 border-t border-surface-200 dark:border-surface-800">
                    <div class="flex items-center justify-between gap-3 flex-wrap mb-1">
                        <div>
                            <div class="font-semibold text-sm">Precio de venta por lista</div>
                            <div class="text-xs text-surface-500">
                                Sin precio en una lista, los clientes que la tengan asignada no pueden pedir este producto.
                            </div>
                        </div>
                        <button type="button" @click="guardarPrecios" :disabled="guardandoPrecios"
                                class="btn-primary min-h-[44px] sm:min-h-0 disabled:opacity-50">
                            {{ guardandoPrecios ? 'Guardando…' : 'Guardar precios' }}
                        </button>
                    </div>

                    <p v-if="listasConPrecio === 0" class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                        Todavía no tiene precio en ninguna lista, así que hoy no se puede pedir.
                    </p>
                    <p v-else class="mt-2 text-xs text-surface-500">
                        Con precio en {{ listasConPrecio }} de {{ precios.length }} listas.
                    </p>

                    <table v-tabla-movil class="w-full text-sm mt-3">
                        <thead class="bg-surface-50 dark:bg-surface-900/60 text-xs uppercase text-surface-500">
                            <tr>
                                <th class="text-left px-3 py-2">Lista</th>
                                <th class="text-left px-3 py-2">Origen</th>
                                <th class="text-right px-3 py-2">Precio de venta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in precios" :key="l.lista_id"
                                class="border-t border-surface-100 dark:border-surface-800">
                                <td class="px-3 py-2 font-medium">{{ l.nombre }}</td>
                                <td class="px-3 py-2 text-xs text-surface-500">{{ l.grupo }}</td>
                                <td class="px-3 py-2 text-right">
                                    <input v-model="l.precio" type="number" min="0" step="1"
                                           placeholder="sin precio"
                                           class="input w-36 text-right font-mono tabular-nums"/>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

            <!-- PESTAÑA 3: CARACTERÍSTICAS -->
            <div v-if="tab === 'caracteristicas'" class="card p-5 space-y-4">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Dimensiones (para facturación electrónica y logística)</div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-surface-600">Peso (g)</label>
                        <input v-model="form.peso_gr" type="number" step="0.01" min="0" class="input w-full font-mono"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600">Alto (cm)</label>
                        <input v-model="form.alto_cm" type="number" step="0.01" min="0" class="input w-full font-mono"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600">Ancho (cm)</label>
                        <input v-model="form.ancho_cm" type="number" step="0.01" min="0" class="input w-full font-mono"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600">Largo (cm)</label>
                        <input v-model="form.largo_cm" type="number" step="0.01" min="0" class="input w-full font-mono"/>
                    </div>
                </div>
                <div class="text-xs text-surface-500 pt-2">
                    Campos de impoconsumo, estampillas, CUM (farmacia) e hortofruticultura NO aplican al negocio Great Baby (ropa y accesorios bebé). Se pueden habilitar si algún día se venden productos de esos sectores.
                </div>
            </div>

            <!-- PESTAÑA 4: FICHA TÉCNICA -->
            <div v-if="tab === 'ficha'" class="card p-5 space-y-4">
                <div>
                    <label class="text-xs font-semibold text-surface-600">Ficha técnica / Cuidados / Conservación</label>
                    <textarea v-model="form.ficha_tecnica" rows="10" class="input w-full font-mono text-xs"
                              placeholder="Especial para ropa bebé: instrucciones de lavado, temperatura máxima, planchado, secado, ingredientes textiles, certificaciones OEKO-TEX, etc.

Este texto aparece al momento de facturar y puede modificarse por línea."></textarea>
                    <p class="text-xs text-surface-500 mt-1">Se muestra en el detalle del producto en factura y catálogo B2B.</p>
                </div>
            </div>

            <!-- PESTAÑA 5: SIIGO · configuración 1:1 con el producto en SIIGO Nube -->
            <div v-if="tab === 'siigo'" class="space-y-4">
                <!-- Alerta si no hay catálogos sincronizados -->
                <div v-if="!catalogos.siigo || (catalogos.siigo.account_groups || []).length === 0"
                     class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    ⚠ Los catálogos SIIGO aún no están sincronizados.
                    Ve al panel <Link href="/app/siigo" class="underline font-semibold">SIIGO</Link> y haz clic en
                    <strong>"Sincronizar catálogos"</strong> para que los selectores de abajo tengan opciones reales.
                </div>

                <div v-else class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-sky-500"></span>
                    <span>
                        Opciones cargadas desde tu tenant SIIGO:
                        <strong>{{ (catalogos.siigo.account_groups || []).length }}</strong> grupos de inventario,
                        <strong>{{ (catalogos.siigo.taxes || []).length }}</strong> impuestos,
                        <strong>{{ (catalogos.siigo.warehouses || []).length }}</strong> bodegas,
                        <strong>{{ (catalogos.siigo.price_lists || []).length }}</strong> listas de precios.
                    </span>
                    <span v-if="catalogos.siigo.sync_at" class="ml-auto text-sky-600">
                        Última sync: {{ new Date(catalogos.siigo.sync_at).toLocaleString('es-CO') }}
                    </span>
                </div>

                <!-- Fix UX Aracely · si el producto aún no existe, en vez de
                     dejar la sección completamente invisible mostramos un cartel
                     que explica qué va a aparecer ahí tras guardar. -->
                <div v-if="esNuevo" class="card p-5 bg-sky-50 border-sky-200 text-sky-900">
                    <h3 class="font-bold text-sm uppercase mb-1">Verificación en vivo con SIIGO</h3>
                    <p class="text-xs">
                        Primero guarda el producto. Después aquí vas a ver en tiempo real qué datos tiene SIIGO y
                        podrás reenviarlo si algo no coincide con el ERP.
                    </p>
                </div>

                <!-- FASE D · Verificación en vivo contra SIIGO -->
                <div v-if="!esNuevo" class="card p-5 space-y-3">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <h3 class="font-bold text-sm uppercase text-surface-600">Verificación en vivo con SIIGO</h3>
                        <div class="flex items-center gap-2">
                            <button @click="verEnSiigo" :disabled="cargandoSiigo"
                                    class="text-xs inline-flex items-center gap-1 px-2 py-1.5 rounded border border-sky-500 text-sky-700 hover:bg-sky-50 disabled:opacity-50">
                                <Cloud class="h-3.5 w-3.5"/>
                                {{ cargandoSiigo ? 'Consultando…' : 'Ver en SIIGO ahora' }}
                            </button>
                            <button @click="forzarSync" :disabled="forzandoSync"
                                    class="text-xs inline-flex items-center gap-1 px-2 py-1.5 rounded border border-amber-500 text-amber-700 hover:bg-amber-50 disabled:opacity-50">
                                {{ forzandoSync ? 'Encolando…' : '⟲ Forzar re-sync' }}
                            </button>
                        </div>
                    </div>

                    <!-- Mensaje si no hay siigo_id -->
                    <div v-if="siigoVivo && !siigoVivo.ok" class="text-sm rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900">
                        ⚠ {{ siigoVivo.motivo || `SIIGO respondió HTTP ${siigoVivo.http}` }}
                    </div>

                    <!-- Modo AGREGADO · diff 1:1 lado a lado (producto único en SIIGO). -->
                    <div v-if="siigoVivo && siigoVivo.ok && !form.desglose_stock" class="space-y-2">
                        <table v-tabla-movil class="w-full text-sm border rounded-lg overflow-hidden">
                            <thead class="bg-surface-50 text-xs uppercase text-surface-500">
                                <tr>
                                    <th class="p-2 text-left w-1/3">Campo</th>
                                    <th class="p-2 text-left">ERP</th>
                                    <th class="p-2 text-left">SIIGO</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="d in diffCampos" :key="d.campo"
                                    :class="d.igual ? '' : 'bg-red-50'">
                                    <td class="p-2 font-semibold">{{ d.campo }}</td>
                                    <td class="p-2 font-mono text-xs">{{ d.local }}</td>
                                    <td class="p-2 font-mono text-xs" :class="! d.igual && 'text-red-700'">
                                        {{ d.siigo }}
                                        <span v-if="! d.igual" class="ml-1 text-red-500">⚠</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <details class="text-xs">
                            <summary class="cursor-pointer text-surface-500 hover:text-surface-700">
                                Ver JSON crudo de SIIGO
                            </summary>
                            <pre class="mt-2 p-3 bg-surface-900 text-emerald-300 rounded-lg overflow-x-auto max-h-96 text-[10px]">{{ JSON.stringify(siigoVivo.respuesta_siigo, null, 2) }}</pre>
                        </details>
                    </div>

                    <!-- Modo VARIANTES · el diff 1:1 no aplica; cada variante es un
                         producto SIIGO independiente. Mostramos el estado real de la
                         bandada: cuántas quedaron en SIIGO, con su siigo_id. -->
                    <div v-if="form.desglose_stock" class="space-y-2">
                        <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-900">
                            Este producto está en modo <strong>desglose por variante</strong>: cada variante
                            viaja a SIIGO como producto independiente. Por eso aquí no hay un único
                            registro padre que comparar · abajo verás el estado real de cada variante.
                        </div>
                        <table v-tabla-movil class="w-full text-sm border rounded-lg overflow-hidden">
                            <thead class="bg-surface-50 text-xs uppercase text-surface-500">
                                <tr>
                                    <th class="p-2 text-left">Variante</th>
                                    <th class="p-2 text-left">Código de barras</th>
                                    <th class="p-2 text-left">Estado en SIIGO</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="v in (producto?.variantes || [])" :key="v.id">
                                    <td class="p-2 text-xs">#{{ v.id }}
                                        <span v-if="v.color_id || v.talla_id" class="text-surface-500">
                                            · color {{ v.color_id ?? '—' }} · talla {{ v.talla_id ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="p-2 font-mono text-xs">{{ v.codigo_barras || '—' }}</td>
                                    <td class="p-2 text-xs">
                                        <span v-if="v.siigo_id" class="inline-flex items-center gap-1 text-emerald-700">
                                            ✅ sincronizada
                                            <span class="font-mono text-[10px] text-emerald-600" :title="v.siigo_id">
                                                ({{ String(v.siigo_id).slice(0, 8) }}…)
                                            </span>
                                        </span>
                                        <span v-else class="inline-flex items-center gap-1 text-amber-700">
                                            ⌛ pendiente
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="!producto?.variantes?.length">
                                    <td colspan="3" class="p-3 text-center text-xs text-surface-500 italic">
                                        No hay variantes capturadas aún. Agrega filas en la pestaña 2.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="(producto?.variantes || []).length" class="text-[11px] text-surface-500">
                            {{ (producto?.variantes || []).filter(v => v.siigo_id).length }}
                            de {{ (producto?.variantes || []).length }} variantes sincronizadas con SIIGO.
                        </p>
                        <!-- Fix UX · antes decía "0 de 0" cuando el producto aún no tiene
                             variantes capturadas · Aracely lo leía como "nada sincronizó". -->
                        <p v-else class="text-[11px] text-surface-500 italic">
                            Guarda primero el producto con sus variantes para iniciar la sincronización con SIIGO.
                        </p>
                    </div>
                </div>

                <!-- Clasificación SIIGO -->
                <div class="card p-5 space-y-4">
                    <h3 class="font-bold text-sm uppercase text-surface-600">Clasificación SIIGO</h3>

                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Tipo de producto en SIIGO</label>
                            <select v-model="form.tipo_siigo" class="input w-full">
                                <option v-for="t in (catalogos.siigo?.types || [])" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                            </select>
                            <p class="text-[10px] text-surface-500 mt-1">Controla si lleva stock, si se factura como servicio, etc.</p>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-surface-600">Clasificación tributaria</label>
                            <select v-model="form.tax_classification" class="input w-full">
                                <option v-for="t in (catalogos.siigo?.tax_classifications || [])" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- FASE H · Toggle "Visible en facturas de venta" (header del form SIIGO) -->
                    <label class="flex items-start gap-2 text-sm border-t pt-3">
                        <input type="checkbox" v-model="form.visible_en_facturas" class="mt-1 rounded"/>
                        <span>
                            <strong>Visible en facturas de venta</strong>
                            <div class="text-xs text-surface-500">Desactiva si es un producto interno que no debe aparecer en el combo del facturador.</div>
                        </span>
                    </label>

                    <div>
                        <label class="text-xs font-semibold text-surface-600">Grupo de inventario SIIGO (account_group)</label>
                        <!-- FASE F2.A4 · ahora persiste a form.siigo_account_group_override -->
                        <select v-model.number="form.siigo_account_group_override" class="input w-full">
                            <option :value="null">— Hereda de categoría local ({{ categoriaActual?.siigo_account_group_id || '—' }}) —</option>
                            <option v-for="g in (catalogos.siigo?.account_groups || [])" :key="g.id" :value="g.id">
                                {{ g.id }} · {{ g.nombre }}
                            </option>
                        </select>
                        <p class="text-[10px] text-surface-500 mt-1">
                            Si lo dejas en blanco, usa el grupo asignado a la categoría local.
                            Si lo defines aquí, SOBRESCRIBE para este producto y viaja a SIIGO como <code class="bg-surface-100 px-1">account_group</code>.
                        </p>
                    </div>
                </div>

                <!-- Impuestos múltiples -->
                <div class="card p-5 space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold text-sm uppercase text-surface-600">Impuestos (SIIGO acepta hasta 3)</h3>
                        <span class="text-xs text-surface-500">{{ form.impuestos_ids.length }} seleccionado(s)</span>
                    </div>

                    <div class="grid md:grid-cols-2 gap-2 max-h-60 overflow-y-auto border rounded-lg p-3">
                        <label v-for="imp in (catalogos.impuestos || [])" :key="imp.id"
                               class="flex items-center gap-2 text-sm hover:bg-surface-50 p-1 rounded cursor-pointer">
                            <input type="checkbox" :value="imp.id" v-model="form.impuestos_ids" class="rounded"/>
                            <span>{{ imp.nombre }} <span class="text-xs text-surface-500">({{ imp.porcentaje }}%)</span></span>
                            <span v-if="imp.siigo_id" class="ml-auto text-[10px] text-emerald-600" title="Linkeado a SIIGO">✓ SIIGO</span>
                            <span v-else class="ml-auto text-[10px] text-amber-600" title="No linkeado — se buscará por %">⚠</span>
                        </label>
                    </div>

                    <div class="grid md:grid-cols-3 gap-4 pt-2 border-t">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" v-model="form.tax_included" class="rounded"/>
                            <span>Precio incluye IVA</span>
                        </label>
                        <div class="md:col-span-2">
                            <label class="text-xs font-semibold text-surface-600">Valor impoconsumo (saludables)</label>
                            <input v-model.number="form.tax_consumption_value" type="number" step="0.01" class="input w-full"
                                   placeholder="Solo para bebidas azucaradas · dejar vacío si no aplica"/>
                        </div>
                    </div>

                    <!-- FASE H · Retención + Impuesto cargo dos (del form SIIGO) -->
                    <div class="grid md:grid-cols-2 gap-4 pt-2 border-t">
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Retención</label>
                            <select v-model="form.retencion_siigo_id" class="input w-full">
                                <option :value="null">No aplica</option>
                                <option v-for="imp in (catalogos.impuestos || []).filter(i => (i.tipo || '').toLowerCase().includes('reten'))"
                                        :key="imp.id" :value="imp.id">
                                    {{ imp.nombre }} ({{ imp.porcentaje }}%)
                                </option>
                            </select>
                            <p class="text-[10px] text-surface-500 mt-1">Retención en la fuente aplicable al producto · SIIGO: `withholding_taxes[]`.</p>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Impuesto cargo dos (opcional)</label>
                            <select v-model="form.impuesto_cargo_dos_id" class="input w-full">
                                <option :value="null">No aplica</option>
                                <option v-for="imp in (catalogos.impuestos || []).filter(i => ! (i.tipo || '').toLowerCase().includes('reten'))"
                                        :key="imp.id" :value="imp.id">
                                    {{ imp.nombre }} ({{ imp.porcentaje }}%)
                                </option>
                            </select>
                            <p class="text-[10px] text-surface-500 mt-1">Segundo impuesto cargo cuando aplica combinación (ej: IVA + ICA).</p>
                        </div>
                    </div>
                </div>

                <!-- FASE H · Descripción y stock + Imágenes (replica tabs SIIGO 2 y 3) -->
                <div class="card p-5 space-y-4">
                    <h3 class="font-bold text-sm uppercase text-surface-600">Descripción y stock (SIIGO)</h3>
                    <div class="grid md:grid-cols-3 gap-4">
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Referencia de fábrica</label>
                            <input v-model="form.reference_fabrica" type="text" maxlength="60" class="input w-full"
                                   placeholder="Ej: REF-TEST-003"/>
                            <p class="text-[10px] text-surface-500 mt-1">Distinta del SKU · SIIGO: campo `reference`.</p>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Stock mínimo</label>
                            <input v-model.number="form.stock_minimo" type="number" step="0.0001" min="0" class="input w-full"
                                   placeholder="0"/>
                            <p class="text-[10px] text-surface-500 mt-1">Alerta cuando el stock baja de este valor.</p>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Etiqueta de unidad en factura</label>
                            <input v-model="form.unit_label" type="text" maxlength="50" class="input w-full"
                                   placeholder="Unidad / Caja / Paquete"/>
                        </div>
                    </div>
                </div>

                <!-- Imágenes (tab "Subir imágenes" del form SIIGO · hasta 5) -->
                <div class="card p-5 space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold text-sm uppercase text-surface-600">Imágenes del producto (hasta 5 · PNG/JPG · 1MB c/u)</h3>
                        <span class="text-xs text-surface-500">{{ (producto?.imagenes || []).length }}/5</span>
                    </div>

                    <div v-if="esNuevo" class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800">
                        💡 Guarda primero el producto · después podrás subir imágenes desde el detalle.
                    </div>

                    <div v-else>
                        <div class="grid grid-cols-5 gap-2">
                            <div v-for="slot in 5" :key="slot" class="relative aspect-square border-2 border-dashed border-surface-200 rounded-lg overflow-hidden">
                                <img v-if="producto?.imagenes?.[slot-1]"
                                     :src="producto.imagenes[slot-1].url"
                                     :alt="`Imagen ${slot}`"
                                     class="w-full h-full object-cover"/>
                                <label v-else
                                       class="flex flex-col items-center justify-center h-full text-xs text-surface-400 cursor-pointer hover:bg-surface-50">
                                    <input type="file" accept="image/png,image/jpeg" class="hidden"
                                           @change="(e) => subirImagen(e, slot - 1)"/>
                                    <span class="text-2xl">+</span>
                                    <span>Slot {{ slot }}</span>
                                </label>
                                <button v-if="producto?.imagenes?.[slot-1]" type="button"
                                        @click="eliminarImagen(producto.imagenes[slot-1].id)"
                                        class="absolute top-1 right-1 bg-red-500 text-white rounded-full w-5 h-5 text-xs flex items-center justify-center">
                                    ✕
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Flags adicionales SIIGO -->
                <div class="card p-5 space-y-3">
                    <h3 class="font-bold text-sm uppercase text-surface-600">Comportamiento en SIIGO</h3>
                    <div class="grid md:grid-cols-2 gap-4">
                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" v-model="form.stock_control" class="mt-1 rounded"/>
                            <span>
                                <strong>Lleva control de stock (kardex)</strong>
                                <div class="text-xs text-surface-500">Desactiva si es un servicio o un gasto sin inventario</div>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- FASE C4 · Historial de sync SIIGO del producto -->
                <div v-if="!esNuevo && historial.length" class="card p-5 space-y-3">
                    <h3 class="font-bold text-sm uppercase text-surface-600">Historial de sync SIIGO · últimos {{ historial.length }}</h3>
                    <div class="max-h-60 overflow-y-auto">
                        <table v-tabla-movil class="w-full text-xs">
                            <thead class="text-left text-surface-500 uppercase">
                                <tr>
                                    <th class="py-1">Cuándo</th>
                                    <th class="py-1">Acción</th>
                                    <th class="py-1">Estado</th>
                                    <th class="py-1">Mensaje</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="h in historial" :key="h.id" class="border-t border-surface-100">
                                    <td class="py-1 text-surface-600">{{ h.hace }}</td>
                                    <td class="py-1 font-mono">{{ h.accion }}</td>
                                    <td class="py-1">
                                        <span :class="h.estado === 'exitoso' ? 'text-emerald-700' : (h.estado === 'fallido' ? 'text-red-700' : 'text-amber-700')">
                                            {{ h.estado }}
                                        </span>
                                    </td>
                                    <td class="py-1 text-surface-500 truncate max-w-xs" :title="h.mensaje">{{ h.mensaje }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Campos adicionales -->
                <div class="card p-5 space-y-4">
                    <h3 class="font-bold text-sm uppercase text-surface-600">Campos adicionales SIIGO</h3>
                    <div class="grid md:grid-cols-3 gap-4">
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Modelo</label>
                            <input v-model="form.modelo_siigo" type="text" maxlength="100" class="input w-full"
                                   placeholder="Ej: Loiry, M-2024"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600">Código de barras padre</label>
                            <input v-model="form.barcode_padre" type="text" maxlength="100" class="input w-full"
                                   placeholder="Si el producto no tiene variantes"/>
                        </div>
                        <!-- FASE F4.M6 · "Etiqueta de unidad" eliminada aquí · ya existe en la pestaña anterior (línea ~922). Dos inputs al mismo v-model confundían a Aracely ("¿cuál se guarda?"). -->
                    </div>
                </div>
            </div>
        </div>
        <!-- Modal de confirmación global · reemplaza confirm() nativo bloqueado en iframe. -->
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>

        <!-- PROD-7 · Modal Duplicar con alcance -->
        <div v-if="modalClonar"
             class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
             @click.self="modalClonar = null">
            <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                <div class="flex items-start gap-3 mb-4">
                    <div class="p-2 bg-sky-50 rounded-lg">
                        <Copy class="h-5 w-5 text-sky-600"/>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-semibold text-surface-800">Duplicar {{ producto.referencia }}</h3>
                        <p class="text-sm text-surface-500 mt-0.5">Elige qué incluir en el clon. La ficha, impuestos, accesorios y sustitutos siempre se copian.</p>
                    </div>
                </div>

                <div class="space-y-2.5 mb-5">
                    <label class="flex items-start gap-2.5 p-3 rounded-lg border border-surface-200 hover:bg-surface-50 cursor-pointer">
                        <input type="checkbox" v-model="modalClonar.incluirVariantes"
                               class="mt-0.5 h-4 w-4 text-sky-600 rounded border-surface-300"/>
                        <div class="flex-1">
                            <div class="font-medium text-sm text-surface-800">Variantes</div>
                            <div class="text-xs text-surface-500">Color, diseño, talla y stock mínimo (solo si el producto está en modo granular).</div>
                        </div>
                    </label>
                    <label class="flex items-start gap-2.5 p-3 rounded-lg border border-surface-200 hover:bg-surface-50 cursor-pointer"
                           :class="! modalClonar.incluirVariantes && 'opacity-50 pointer-events-none'">
                        <input type="checkbox" v-model="modalClonar.incluirPrecios"
                               :disabled="! modalClonar.incluirVariantes"
                               class="mt-0.5 h-4 w-4 text-sky-600 rounded border-surface-300"/>
                        <div class="flex-1">
                            <div class="font-medium text-sm text-surface-800">Precios por lista</div>
                            <div class="text-xs text-surface-500">Copia los precios de cada lista. Si vas a revisarlos manualmente, déjalo en blanco.</div>
                        </div>
                    </label>
                </div>

                <div class="flex gap-2 justify-end">
                    <button @click="modalClonar = null"
                            class="text-sm px-4 py-2 rounded-lg text-surface-600 hover:bg-surface-100">Cancelar</button>
                    <button @click="confirmarClonar"
                            class="text-sm px-4 py-2 rounded-lg bg-sky-600 text-white hover:bg-sky-700 inline-flex items-center gap-1.5">
                        <Copy class="h-4 w-4"/> Duplicar
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
