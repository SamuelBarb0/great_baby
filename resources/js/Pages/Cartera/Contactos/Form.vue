<script setup>
/*
 * Alta y edición de contactos.
 *
 * Esta pantalla sólo existía en el panel Filament. Al dejar `/admin` para
 * Dropi, el ERP se quedaba sin forma de dar de alta un cliente o un proveedor.
 */
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Save, User, AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    contacto: { type: Object, default: null },
    listas: { type: Array, default: () => [] },
    ciudades_dane: { type: Array, default: () => [] },
    fuentes: { type: Object, default: () => ({}) },
});

const editando = computed(() => !! props.contacto?.id);

const form = ref({
    tipo_documento: props.contacto?.tipo_documento ?? 'CC',
    numero_documento: props.contacto?.numero_documento ?? '',
    nombre_completo: props.contacto?.nombre_completo ?? '',
    razon_social: props.contacto?.razon_social ?? '',
    email: props.contacto?.email ?? '',
    telefono: props.contacto?.telefono ?? '',
    direccion: props.contacto?.direccion ?? '',
    ciudad: props.contacto?.ciudad ?? '',
    departamento: props.contacto?.departamento ?? '',
    es_cliente: props.contacto?.es_cliente ?? true,
    es_cliente_b2b: props.contacto?.es_cliente_b2b ?? false,
    es_proveedor: props.contacto?.es_proveedor ?? false,
    es_empleado: props.contacto?.es_empleado ?? false,
    regimen_iva: props.contacto?.regimen_iva ?? '',
    retenciones_default: {
        fuente: !! props.contacto?.retenciones_default?.fuente,
        ica: !! props.contacto?.retenciones_default?.ica,
        iva: !! props.contacto?.retenciones_default?.iva,
    },
    lista_precios_id: props.contacto?.lista_precios_id ?? '',
    activo: props.contacto?.activo ?? true,
    fuente: props.contacto?.fuente ?? '',
    fuente_detalle: props.contacto?.fuente_detalle ?? '',
});

const guardando = ref(false);

// Un B2B sin lista no se puede facturar ni aparece en el armador del vendedor.
const faltaLista = computed(() => form.value.es_cliente_b2b && ! form.value.lista_precios_id);

/**
 * Listas de precios por origen, conservando el orden que manda el servidor
 * (las de Great Baby primero). Un `reduce` sobre el arreglo mantiene ese orden;
 * agrupar en el template lo perdería.
 */
const listasAgrupadas = computed(() => props.listas.reduce((acc, l) => {
    const g = l.grupo || 'Listas de precios';
    (acc[g] ??= []).push(l);
    return acc;
}, {}));

/**
 * Ciudad ↔ código DANE.
 *
 * SIIGO no factura con el nombre de la ciudad, factura con el código DANE. El
 * ERP traduce con una tabla propia y, si no reconoce lo escrito, emite con la
 * ciudad por defecto (Bogotá) y lo deja sólo en el log. Un dedazo —«Mosquera,
 * Cund.»— mandaba la factura a otra ciudad sin que nadie lo viera.
 *
 * Acá se ofrece la lista que el ERP sí sabe traducir y se avisa cuando la
 * escrita no está. No se bloquea: puede haber un cliente en un municipio que
 * todavía no figura en la tabla.
 */
const normalizarCiudad = (v) => (v || '')
    .toLowerCase()
    .split(',')[0]
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z ]/g, '')
    .trim().replace(/\s+/g, ' ');

const ciudadDane = computed(() => {
    const k = normalizarCiudad(form.value.ciudad);
    if (! k) return null;
    return props.ciudades_dane.find((c) => normalizarCiudad(c.ciudad) === k) || null;
});

const ciudadDesconocida = computed(() =>
    form.value.ciudad.trim() !== '' && ! ciudadDane.value);

// Al reconocer la ciudad se completa el departamento, que es dato derivado:
// nadie debería teclear "Antioquia" después de escribir "Medellín".
watch(ciudadDane, (c) => {
    if (c && form.value.departamento.trim() === '') form.value.departamento = c.departamento;
});

const puedeGuardar = computed(() =>
    form.value.numero_documento.trim() !== ''
    && form.value.nombre_completo.trim() !== ''
    && ! faltaLista.value
    && ! guardando.value);

const guardar = () => {
    if (! puedeGuardar.value) return;
    guardando.value = true;
    const url = editando.value ? `/app/contactos/${props.contacto.id}` : '/app/contactos';
    router.post(url, {
        ...form.value,
        lista_precios_id: form.value.lista_precios_id === '' ? null : Number(form.value.lista_precios_id),
        regimen_iva: form.value.regimen_iva === '' ? null : form.value.regimen_iva,
    }, { onFinish: () => (guardando.value = false) });
};
</script>

<template>
    <Head :title="editando ? 'Editar contacto' : 'Nuevo contacto'"/>
    <AppLayout>
        <div class="max-w-3xl mx-auto space-y-4">
            <Link :href="editando ? `/app/contactos/${contacto.id}` : '/app/contactos'"
                  class="btn-ghost inline-flex items-center gap-1 text-sm min-h-11">
                <ArrowLeft class="h-4 w-4"/> Volver
            </Link>

            <h1 class="text-2xl font-bold flex items-center gap-2">
                <User class="h-6 w-6 text-brand-600"/>
                {{ editando ? 'Editar contacto' : 'Nuevo contacto' }}
            </h1>

            <div v-if="$page.props.errors && Object.keys($page.props.errors).length"
                 class="p-3 rounded-lg bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-sm space-y-1">
                <div v-for="(msg, k) in $page.props.errors" :key="k">{{ msg }}</div>
            </div>

            <div class="card p-4 space-y-3">
                <div class="text-xs uppercase font-bold text-brand-600">Identificación</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Tipo de documento</label>
                        <select v-model="form.tipo_documento" class="input w-full min-h-11">
                            <option value="CC">Cédula</option>
                            <option value="CE">Cédula extranjera</option>
                            <option value="NIT">NIT empresa</option>
                            <option value="PP">Pasaporte</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Número *</label>
                        <input v-model="form.numero_documento" class="input w-full min-h-11" maxlength="30"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Nombre completo *</label>
                        <input v-model="form.nombre_completo" class="input w-full min-h-11" maxlength="180"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Razón social</label>
                        <input v-model="form.razon_social" class="input w-full min-h-11" maxlength="180"
                               placeholder="Si es empresa"/>
                    </div>
                </div>
            </div>

            <div class="card p-4 space-y-3">
                <div class="text-xs uppercase font-bold text-brand-600">Contacto</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Correo</label>
                        <input v-model="form.email" type="email" class="input w-full min-h-11" maxlength="120"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Teléfono</label>
                        <input v-model="form.telefono" type="tel" class="input w-full min-h-11" maxlength="30"/>
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Dirección</label>
                        <input v-model="form.direccion" class="input w-full min-h-11" maxlength="200"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Ciudad</label>
                        <!-- Lista sugerida, no cerrada: se puede escribir una
                             que no esté y el aviso de abajo explica qué pasa. -->
                        <input v-model="form.ciudad" list="ciudades-dane" class="input w-full min-h-11" maxlength="80"
                               :class="ciudadDesconocida ? 'border-amber-400' : ''"
                               placeholder="Empezá a escribir y elegí de la lista"/>
                        <datalist id="ciudades-dane">
                            <option v-for="c in ciudades_dane" :key="c.city_code" :value="c.ciudad">{{ c.departamento }}</option>
                        </datalist>
                        <p v-if="ciudadDane" class="text-xs text-emerald-700 mt-1">
                            ✓ Código DANE {{ ciudadDane.city_code }} · se factura a esta ciudad.
                        </p>
                        <p v-else-if="ciudadDesconocida" class="text-xs text-amber-700 mt-1">
                            ⚠ Esta ciudad no está en la tabla DANE, así que la factura electrónica
                            va a salir con la ciudad por defecto (Bogotá). Elegí una de la lista
                            o avisá para agregarla.
                        </p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Departamento</label>
                        <input v-model="form.departamento" class="input w-full min-h-11" maxlength="80"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">¿Cómo llegó?</label>
                        <select v-model="form.fuente" class="input w-full min-h-11">
                            <option value="">Sin registrar</option>
                            <option v-for="(label, clave) in fuentes" :key="clave" :value="clave">{{ label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Detalle de la fuente</label>
                        <input v-model="form.fuente_detalle" class="input w-full min-h-11" maxlength="120"
                               :placeholder="form.fuente === 'referido' ? '¿Quién lo refirió?' : 'Campaña, feria, página… (opcional)'"/>
                    </div>
                </div>
            </div>

            <div class="card p-4 space-y-3">
                <div class="text-xs uppercase font-bold text-brand-600">¿Qué es para la empresa?</div>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                    <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800">
                        <input type="checkbox" v-model="form.es_cliente" class="h-4 w-4"/> <span class="text-sm">Cliente</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800">
                        <input type="checkbox" v-model="form.es_cliente_b2b" class="h-4 w-4"/> <span class="text-sm">Cliente B2B (con crédito)</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800">
                        <input type="checkbox" v-model="form.es_proveedor" class="h-4 w-4"/> <span class="text-sm">Proveedor</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800">
                        <input type="checkbox" v-model="form.es_empleado" class="h-4 w-4"/> <span class="text-sm">Empleado</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800">
                        <input type="checkbox" v-model="form.activo" class="h-4 w-4"/> <span class="text-sm">Activo</span>
                    </label>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Lista de precios</label>
                        <select v-model="form.lista_precios_id" class="input w-full min-h-11"
                                :class="faltaLista && 'border-amber-500'">
                            <option value="">— Sin lista —</option>
                            <!--
                                Agrupadas: las de Great Baby arriba. El sandbox de
                                SIIGO es compartido, así que el catálogo trae listas
                                de otras empresas («14.999», «Asistente Gerente»)
                                que acá no significan nada y antes salían primero.
                            -->
                            <optgroup v-for="(grupo, nombre) in listasAgrupadas" :key="nombre" :label="nombre">
                                <option v-for="l in grupo" :key="l.id" :value="l.id" :disabled="l.productos === 0">
                                    {{ l.nombre }} · {{ l.productos ? l.productos + ' productos' : 'sin precios cargados' }}
                                </option>
                            </optgroup>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Régimen de IVA</label>
                        <select v-model="form.regimen_iva" class="input w-full min-h-11">
                            <option value="">— Sin definir —</option>
                            <option value="responsable">Responsable de IVA</option>
                            <option value="no_responsable">No responsable</option>
                        </select>
                    </div>
                </div>

                <div v-if="form.es_cliente || form.es_cliente_b2b">
                    <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Retenciones que nos practica al pagar</label>
                    <p class="text-xs text-surface-500 mb-1">
                        Sólo si es agente de retención. Se calculan solas al facturar con las reglas de Cartera → Retenciones
                        y la factura muestra el neto a pagar.
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-1">
                        <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800">
                            <input type="checkbox" v-model="form.retenciones_default.fuente" class="h-4 w-4"/> <span class="text-sm">Retefuente</span>
                        </label>
                        <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800">
                            <input type="checkbox" v-model="form.retenciones_default.iva" class="h-4 w-4"/> <span class="text-sm">ReteIVA</span>
                        </label>
                        <label class="flex items-center gap-2 p-2 rounded min-h-11 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800">
                            <input type="checkbox" v-model="form.retenciones_default.ica" class="h-4 w-4"/> <span class="text-sm">ReteICA (según su ciudad)</span>
                        </label>
                    </div>
                </div>

                <div v-if="faltaLista"
                     class="p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200 text-xs flex items-start gap-2">
                    <AlertTriangle class="h-4 w-4 flex-shrink-0 mt-0.5"/>
                    <div>Un cliente B2B necesita lista de precios: sin ella no aparece cuando el vendedor va a armarle un pedido.</div>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <Link :href="editando ? `/app/contactos/${contacto.id}` : '/app/contactos'" class="btn-ghost min-h-11">Cancelar</Link>
                <button @click="guardar" :disabled="! puedeGuardar" class="btn-primary min-h-11 disabled:opacity-40">
                    <Save class="h-4 w-4"/> {{ guardando ? 'Guardando…' : (editando ? 'Guardar cambios' : 'Crear contacto') }}
                </button>
            </div>
        </div>
    </AppLayout>
</template>
