<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { Building2, Save, PlugZap } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    empresa: { type: Object, required: true },
    // Multiempresa · una pestaña por razón social (Great Baby, Arsutex, e-commerce).
    empresas: { type: Array, default: () => [] },
    // Cuenta de SIIGO de esta razón social; null en la principal (va por Integración SIIGO).
    siigo: { type: Object, default: null },
});

// QA-D Bloque2: useForm en vez de reactive+router.post. Así:
// - form.errors se pinta automático por campo
// - form.processing bloquea el botón (evita doble-click)
// - preserveScroll y onSuccess resetean solo si guardó bien
const form = useForm({ ...props.empresa });

const guardar = () => {
    if (form.processing) return;
    form.post('/app/empresa', {
        preserveScroll: true,
        onError: () => {
            if (typeof window !== 'undefined') {
                window.dispatchEvent(new CustomEvent('gb:error', { detail: { mensaje: 'Revisa los campos marcados en rojo.' } }));
            }
        },
    });
};

// Cuenta de SIIGO de la empresa. La llave nunca viaja al navegador: se
// escribe sólo para cambiarla.
const siigoForm = useForm(props.siigo ? {
    username: props.siigo.username ?? '',
    access_key: '',
    partner_id: props.siigo.partner_id ?? '',
    ambiente: props.siigo.ambiente ?? 'sandbox',
    activo: !!props.siigo.activo,
    tipo_documento_id: props.siigo.tipo_documento_id ?? '',
    seller_id: props.siigo.seller_id ?? '',
    payment_type_id: props.siigo.payment_type_id ?? '',
    iva: Object.fromEntries(props.siigo.ivas.map((i) => [i.clave, i.siigo_id ?? ''])),
    retenciones: Object.fromEntries(props.siigo.retenciones.map((x) => [x.clave, x.siigo_id ?? ''])),
} : {});

const guardarSiigo = () => {
    if (siigoForm.processing) return;
    siigoForm.post(`/app/empresa/${props.empresa.id}/siigo`, {
        preserveScroll: true,
        onSuccess: () => { siigoForm.access_key = ''; },
    });
};
const probarSiigo = () => router.post(`/app/empresa/${props.empresa.id}/siigo/probar`, {}, { preserveScroll: true });

// Grupos de campos
const grupos = [
    {
        titulo: 'Datos legales', campos: [
            { k: 'razon_social', l: 'Razón social', req: true },
            { k: 'nombre_comercial', l: 'Nombre comercial' },
            { k: 'nit', l: 'NIT', req: true },
            { k: 'regimen', l: 'Régimen IVA' },
            { k: 'actividad_economica', l: 'CIIU / Actividad económica' },
        ]
    },
    {
        titulo: 'Ubicación y contacto', campos: [
            { k: 'direccion', l: 'Dirección' },
            { k: 'ciudad', l: 'Ciudad' },
            { k: 'departamento', l: 'Departamento' },
            { k: 'pais', l: 'País' },
            { k: 'telefono', l: 'Teléfono' },
            { k: 'email', l: 'Email', type: 'email' },
            { k: 'web', l: 'Sitio web' },
        ]
    },
    {
        titulo: 'Resolución DIAN (facturación electrónica)', campos: [
            { k: 'resolucion_dian', l: 'Número de resolución' },
            { k: 'prefijo_dian', l: 'Prefijo' },
            { k: 'resolucion_desde', l: 'Vigencia desde', type: 'date' },
            { k: 'resolucion_hasta', l: 'Vigencia hasta', type: 'date' },
            { k: 'rango_desde', l: 'Rango desde', type: 'number' },
            { k: 'rango_hasta', l: 'Rango hasta', type: 'number' },
        ]
    },
    {
        titulo: 'Datos bancarios', campos: [
            { k: 'banco_nombre', l: 'Banco' },
            { k: 'banco_cuenta', l: 'Número de cuenta' },
            { k: 'banco_moneda', l: 'Moneda' },
            { k: 'banco_swift', l: 'SWIFT' },
            { k: 'banco_iban', l: 'IBAN' },
        ]
    },
    {
        titulo: 'Contacto financiero', campos: [
            { k: 'financiero_nombre', l: 'Nombre' },
            { k: 'financiero_email', l: 'Email', type: 'email' },
            { k: 'financiero_telefono', l: 'Teléfono' },
        ]
    },
];

const camposSiigo = [
    { k: 'tipo_documento_id', l: 'Tipo de documento (Document Type ID)' },
    { k: 'seller_id', l: 'Vendedor SIIGO (Seller ID)' },
    { k: 'payment_type_id', l: 'Método de pago (Payment Type ID)' },
];
</script>

<template>
    <Head title="Empresas"/>
    <AppLayout>
        <div class="space-y-4 max-w-5xl">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Building2 class="h-6 w-6 text-brand-600"/>
                        Configuración de empresas
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Cada razón social factura con su NIT, su prefijo y su cuenta de SIIGO. Estos datos salen en sus facturas y PDF.
                    </p>
                </div>
                <button @click="guardar" :disabled="form.processing" class="btn-primary disabled:opacity-50">
                    <Save class="h-4 w-4"/> {{ form.processing ? 'Guardando…' : 'Guardar cambios' }}
                </button>
            </div>

            <!-- Una pestaña por razón social -->
            <nav v-if="empresas.length > 1" class="flex gap-2 flex-wrap border-b border-surface-200 dark:border-surface-800">
                <Link v-for="e in empresas" :key="e.id" :href="`/app/empresa?empresa=${e.id}`" preserve-scroll
                      :class="['px-3 py-2 text-sm -mb-px border-b-2',
                               e.id === empresa.id ? 'border-brand-600 font-semibold text-brand-700' : 'border-transparent text-surface-500 hover:text-surface-800']">
                    {{ e.nombre }}
                    <span v-if="e.es_principal" class="text-[10px] uppercase text-surface-400 ml-1">principal</span>
                    <span v-else-if="!e.activa" class="text-[10px] uppercase text-amber-600 ml-1">apagada</span>
                </Link>
            </nav>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 dark:text-emerald-300 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 text-sm">
                {{ $page.props.flash.error }}
            </div>

            <!-- Encender/apagar (la principal siempre factura) -->
            <div v-if="!empresa.es_principal" class="card p-4 flex items-start gap-3">
                <input id="f-activa" type="checkbox" v-model="form.activa" class="mt-1"/>
                <label for="f-activa" class="text-sm">
                    <b>Activa</b>: se puede elegir en los pedidos y factura.
                    <span class="block text-xs text-surface-500">Para activarla hacen falta el NIT y el prefijo de su resolución DIAN.</span>
                </label>
            </div>

            <div v-for="g in grupos" :key="g.titulo" class="card p-5">
                <h2 class="text-sm font-bold text-brand-600 uppercase tracking-wider mb-4">{{ g.titulo }}</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div v-for="c in g.campos" :key="c.k">
                        <label :for="'f-' + c.k" class="block text-xs font-semibold text-surface-600 dark:text-surface-400 mb-1">
                            {{ c.l }}<span v-if="c.req" class="text-red-500">*</span>
                        </label>
                        <input :id="'f-' + c.k" v-model="form[c.k]" :type="c.type || 'text'"
                               :class="['input w-full', form.errors[c.k] ? 'border-red-500 ring-red-500' : '']"
                               :placeholder="c.l"/>
                        <p v-if="form.errors[c.k]" class="text-xs text-red-600 mt-1">{{ form.errors[c.k] }}</p>
                    </div>
                </div>
            </div>

            <!-- Cuenta de SIIGO de esta razón social -->
            <div v-if="empresa.es_principal" class="card p-4 text-sm text-surface-600">
                La cuenta de SIIGO de {{ empresa.nombre_comercial || empresa.razon_social }} se configura en
                <Link href="/app/siigo" class="text-brand-600 underline">Integración SIIGO</Link>.
            </div>
            <div v-else-if="siigo" class="card p-5 space-y-4">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div>
                        <h2 class="text-sm font-bold text-brand-600 uppercase tracking-wider">Cuenta de SIIGO</h2>
                        <p class="text-xs text-surface-500 mt-1">
                            Es otra empresa en SIIGO: su usuario, su llave y sus IDs.
                            <template v-if="siigo.ultimo_auth_at">
                                Última prueba {{ siigo.ultimo_auth_at }}:
                                <b :class="siigo.ultimo_auth_ok ? 'text-emerald-700' : 'text-red-700'">
                                    {{ siigo.ultimo_auth_ok ? 'conectó' : (siigo.ultimo_auth_error || 'falló') }}
                                </b>
                            </template>
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button @click="probarSiigo" class="btn-ghost border border-surface-300 text-sm"><PlugZap class="h-4 w-4"/> Probar conexión</button>
                        <button @click="guardarSiigo" :disabled="siigoForm.processing" class="btn-primary text-sm disabled:opacity-50">
                            <Save class="h-4 w-4"/> {{ siigoForm.processing ? 'Guardando…' : 'Guardar SIIGO' }}
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold mb-1">Usuario (correo de SIIGO)</label>
                        <input v-model="siigoForm.username" class="input w-full"/>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">Access key</label>
                        <input v-model="siigoForm.access_key" type="password" autocomplete="new-password" class="input w-full"
                               :placeholder="siigo.tiene_clave ? '•••••• guardada (escribí otra para cambiarla)' : 'Pegá la llave de la API'"/>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">Partner ID</label>
                        <input v-model="siigoForm.partner_id" class="input w-full"/>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">Ambiente</label>
                        <select v-model="siigoForm.ambiente" class="input w-full">
                            <option value="sandbox">Sandbox (pruebas)</option>
                            <option value="produccion">Producción</option>
                        </select>
                    </div>
                    <div v-for="c in camposSiigo" :key="c.k">
                        <label class="block text-xs font-semibold mb-1">{{ c.l }}</label>
                        <input v-model="siigoForm[c.k]" type="number" min="1" class="input w-full"/>
                        <p v-if="siigoForm.errors[c.k]" class="text-xs text-red-600 mt-1">{{ siigoForm.errors[c.k] }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <h3 class="text-xs font-bold uppercase text-surface-500 mb-2">IVA · ID en esta cuenta</h3>
                        <div v-for="i in siigo.ivas" :key="i.clave" class="flex items-center gap-2 mb-2">
                            <span class="text-sm w-40 truncate">{{ i.nombre }}</span>
                            <input v-model="siigoForm.iva[i.clave]" type="number" min="1" class="input w-32" placeholder="ID SIIGO"/>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xs font-bold uppercase text-surface-500 mb-2">Retenciones · ID en esta cuenta</h3>
                        <p v-if="!siigo.retenciones.length" class="text-xs text-surface-400 italic">No hay reglas de retención activas.</p>
                        <div v-for="x in siigo.retenciones" :key="x.clave" class="flex items-center gap-2 mb-2">
                            <span class="text-sm w-40 truncate" :title="x.nombre">{{ x.nombre }}</span>
                            <input v-model="siigoForm.retenciones[x.clave]" type="number" min="1" class="input w-32" placeholder="ID SIIGO"/>
                        </div>
                    </div>
                </div>

                <label class="flex items-start gap-2 text-sm border-t border-surface-200 dark:border-surface-800 pt-3">
                    <input type="checkbox" v-model="siigoForm.activo" class="mt-1"/>
                    <span>
                        <b>Emitir a SIIGO con esta cuenta</b>
                        <span class="block text-xs text-surface-500">
                            Apagado, sus facturas nacen internas (sin DIAN). Recibos y notas de esta empresa todavía no se envían (fase 2).
                        </span>
                    </span>
                </label>
            </div>
        </div>
    </AppLayout>
</template>
