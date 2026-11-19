<script setup>
import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { ExternalLink, Copy, Check, X } from 'lucide-vue-next';
import { avisar } from '@/composables/useAviso';

/*
 * «Crear en Dropi» · abre el formulario de producto de Dropi en una ventana al
 * lado del ERP, con la sesión de Dropi que la persona ya tiene en su navegador.
 *
 * Por qué ventana y no modal: Dropi responde `X-Frame-Options: SAMEORIGIN`, así
 * que el navegador se niega a mostrarlo dentro de un iframe de otro dominio.
 * Por qué no crear el producto desde el servidor: la API oficial de Dropi no
 * crea productos, e imitar su web es frágil y exige el código 2FA. Aquí una
 * persona usa Dropi normal; el ERP sólo le ahorra volver a escribir los datos.
 */
const props = defineProps({
    // Lo que el formulario del ERP ya tiene, para copiarlo en Dropi.
    datos: { type: Object, required: true },
});

const URL_DROPI = 'https://app.dropi.co/dashboard/products/add';

const page = usePage();
const puede = computed(() => (page.props.auth?.menu || []).includes('dropi'));
const panel = ref(false);
const copiado = ref(null);

const abrir = () => {
    // Ventana a la derecha de la pantalla, para ver el ERP y Dropi a la vez.
    const ancho = Math.min(1200, Math.round(window.screen.availWidth * 0.6));
    const alto = Math.round(window.screen.availHeight * 0.9);
    const izq = window.screen.availWidth - ancho;
    const w = window.open(URL_DROPI, 'gb_dropi_crear', `popup,width=${ancho},height=${alto},left=${izq},top=0`);
    if (! w) {
        avisar('El navegador bloqueó la ventana de Dropi. Permite las ventanas emergentes para este sitio.', 'warning');
        return;
    }
    w.focus();
    panel.value = true;
};

const campos = computed(() => [
    { clave: 'nombre', label: 'Product Name', valor: props.datos.nombre },
    { clave: 'sku', label: 'SKU', valor: props.datos.referencia },
    { clave: 'peso', label: 'Weight (g en el ERP)', valor: props.datos.peso_gr },
    { clave: 'largo', label: 'Length (cm)', valor: props.datos.largo_cm },
    { clave: 'ancho', label: 'Width (cm)', valor: props.datos.ancho_cm },
    { clave: 'alto', label: 'Height (cm)', valor: props.datos.alto_cm },
    { clave: 'desc', label: 'Description', valor: props.datos.descripcion },
].filter((c) => c.valor !== null && c.valor !== undefined && String(c.valor).trim() !== ''));

const copiar = async (c) => {
    try {
        await navigator.clipboard.writeText(String(c.valor));
        copiado.value = c.clave;
        setTimeout(() => { if (copiado.value === c.clave) copiado.value = null; }, 1500);
    } catch {
        avisar('No se pudo copiar: selecciona el texto y cópialo a mano.', 'warning');
    }
};
</script>

<template>
    <template v-if="puede">
        <button type="button" @click="abrir"
                class="text-sm inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-orange-500 text-orange-700 hover:bg-orange-50 dark:text-orange-300 dark:hover:bg-orange-950/30"
                title="Abre el formulario de producto de Dropi en una ventana aparte">
            <ExternalLink class="h-4 w-4"/> Crear en Dropi
        </button>

        <!-- Datos para copiar mientras la ventana de Dropi está abierta -->
        <div v-if="panel"
             class="fixed bottom-4 left-4 z-40 w-80 max-w-[calc(100vw-2rem)] card p-3 shadow-xl border border-orange-300 dark:border-orange-800">
            <div class="flex items-center justify-between mb-2">
                <div class="font-semibold text-sm">Copiar a Dropi</div>
                <button type="button" @click="panel = false" class="btn-ghost p-1" aria-label="Cerrar">
                    <X class="h-4 w-4"/>
                </button>
            </div>
            <p v-if="!campos.length" class="text-xs text-surface-500">
                Llena nombre, referencia y medidas aquí y aparecen para copiarlos.
            </p>
            <ul class="space-y-1">
                <li v-for="c in campos" :key="c.clave" class="flex items-center gap-2 text-sm">
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] uppercase text-surface-500">{{ c.label }}</div>
                        <div class="truncate" :title="String(c.valor)">{{ c.valor }}</div>
                    </div>
                    <button type="button" @click="copiar(c)" class="btn-ghost p-1.5" :aria-label="`Copiar ${c.label}`">
                        <Check v-if="copiado === c.clave" class="h-4 w-4 text-emerald-600"/>
                        <Copy v-else class="h-4 w-4"/>
                    </button>
                </li>
            </ul>
            <button type="button" @click="abrir" class="mt-2 text-xs text-brand-600 hover:underline">
                Volver a la ventana de Dropi ↗
            </button>
        </div>
    </template>
</template>
