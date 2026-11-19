<script setup>
/**
 * Modal de confirmación reutilizable. Reemplaza window.confirm() nativo
 * que está bloqueado dentro del iframe de la app desktop Claude Code y
 * en algunos modales embebidos de pantalla completa.
 *
 * Uso:
 *   const modal = ref(null);
 *   function pedir() {
 *     modal.value = {
 *       titulo: '¿Eliminar producto?',
 *       mensaje: 'Esta acción se puede deshacer dentro de 30 días.',
 *       color: 'rose',
 *       textoConfirmar: 'Eliminar',
 *       onConfirmar: () => { router.delete(...); modal.value = null; },
 *     };
 *   }
 *   <AppConfirmModal :cfg="modal" @cerrar="modal = null"/>
 *
 * cfg esperado:
 *   { titulo, mensaje, color?, textoConfirmar?, cargando?, onConfirmar }
 *
 * color admite: emerald | rose | amber | sky | brand (default). El mapa
 * es estático para que Tailwind JIT genere las clases en el build.
 *
 * ── Pedir un motivo ──────────────────────────────────────────────────────
 *
 * Hay acciones que el servidor no acepta sin explicación: anular un pago
 * —que además borra su asiento del libro— o cerrar una vacante. Para esas,
 * `pedirMotivo` muestra un campo de texto y el motivo llega como argumento:
 *
 *   modal.value = {
 *     titulo: '¿Anular el pago?',
 *     pedirMotivo: { etiqueta: '¿Por qué se anula?', minimo: 5 },
 *     onConfirmar: (motivo) => { ... },
 *   };
 *
 * El botón queda deshabilitado hasta que el texto llegue al mínimo, así el
 * 422 del servidor no es la primera noticia de que faltaba algo. Los
 * llamadores que no piden motivo no cambian en nada.
 */
import { computed, ref, watch } from 'vue';
import { useEscClose } from '@/composables/useEscClose';

const props = defineProps({
    cfg: { type: Object, default: null },
});
const emit = defineEmits(['cerrar']);

/*
 * Escape cierra, y el teclado no se escapa al fondo.
 *
 * El composable ya existía y lo usaban las siete modales de Compras, pero no
 * ésta —que es la compartida— ni las de logística. Acá se arregla para todas
 * las que la usan de una vez: quien está contando con una mano y el teclado
 * con la otra no debería tener que buscar la X con el mouse.
 *
 * `useEscClose` espera un ref booleano y lo pone en false; esta modal se abre
 * con un objeto `cfg` y se cierra emitiendo, así que se traduce.
 */
const abierta = computed({
    get: () => !!props.cfg,
    set: (v) => { if (! v) emit('cerrar'); },
});

useEscClose(abierta);

const motivo = ref('');
// Cada vez que el modal se abre arranca en blanco: si no, el motivo de la
// anulación anterior aparecería escrito en la siguiente.
watch(() => props.cfg, () => { motivo.value = ''; });

const minimoMotivo = computed(() => props.cfg?.pedirMotivo?.minimo ?? 5);
const faltaMotivo = computed(() =>
    !! props.cfg?.pedirMotivo && motivo.value.trim().length < minimoMotivo.value);

const confirmar = () => {
    if (faltaMotivo.value) return;
    props.cfg?.onConfirmar?.(motivo.value.trim());
};

const COLORES = {
    emerald: { title: 'text-emerald-700', btn: 'bg-emerald-600 hover:bg-emerald-700' },
    rose:    { title: 'text-rose-700',    btn: 'bg-rose-600 hover:bg-rose-700' },
    amber:   { title: 'text-amber-700',   btn: 'bg-amber-600 hover:bg-amber-700' },
    sky:     { title: 'text-sky-700',     btn: 'bg-sky-600 hover:bg-sky-700' },
    brand:   { title: 'text-brand-700',   btn: 'bg-brand-600 hover:bg-brand-700' },
};
const paleta = computed(() => {
    const pedido = props.cfg?.color;
    // Un color que no está en el mapa caía al de marca sin decir nada, así que
    // un borrado podía verse igual que cualquier otra acción. Ahora se avisa
    // en desarrollo; en producción sigue mostrando algo antes que romperse.
    if (pedido && ! COLORES[pedido] && import.meta.env.DEV) {
        console.warn(`[AppConfirmModal] color «${pedido}» no existe. Usá: ${Object.keys(COLORES).join(', ')}.`);
    }
    return COLORES[pedido] || COLORES.brand;
});
const textoConfirmar = computed(() => props.cfg?.textoConfirmar || 'Confirmar');
const cargando = computed(() => !!props.cfg?.cargando);
</script>

<template>
    <div v-if="cfg"
         class="fixed inset-0 bg-black/40 flex items-center justify-center z-50"
         @click.self="emit('cerrar')">
        <!--
            `role="dialog"` + `aria-modal` para que un lector de pantalla lo
            anuncie como diálogo y no como un trozo más de la página, y
            `aria-labelledby` para que lea el título al abrirse.
        -->
        <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl max-w-md w-full m-4 p-5 space-y-4"
             role="dialog"
             aria-modal="true"
             aria-labelledby="confirm-modal-titulo">
            <h2 id="confirm-modal-titulo" class="font-bold text-lg" :class="paleta.title">{{ cfg.titulo }}</h2>
            <p class="text-sm text-surface-600 whitespace-pre-line">{{ cfg.mensaje }}</p>

            <div v-if="cfg.pedirMotivo" class="space-y-1">
                <label class="block text-xs font-bold text-surface-600">
                    {{ cfg.pedirMotivo.etiqueta || 'Motivo' }}
                </label>
                <textarea v-model="motivo" rows="3"
                          :placeholder="cfg.pedirMotivo.placeholder || 'Queda guardado con tu nombre y la hora.'"
                          class="w-full text-sm rounded border border-surface-300 p-2 focus:border-brand-500 focus:ring-brand-500"></textarea>
                <p v-if="faltaMotivo" class="text-xs text-surface-500">
                    Escribí al menos {{ minimoMotivo }} caracteres.
                </p>
            </div>

            <!--
                En celular los dos botones ocupan la mitad cada uno y miden 44px
                de alto, que es el minimo para tocar con el dedo sin fallar;
                antes quedaban en 32px pegados a la esquina. De sm para arriba
                vuelven a ser compactos y alineados a la derecha.
            -->
            <div class="flex items-center gap-2 justify-end pt-3 border-t">
                <button @click="emit('cerrar')"
                        class="flex-1 sm:flex-none text-sm px-4 min-h-[44px] sm:min-h-0 sm:py-1.5 rounded border border-surface-300 hover:bg-surface-50">
                    Cancelar
                </button>
                <button @click="confirmar"
                        :disabled="cargando || faltaMotivo"
                        :class="['flex-1 sm:flex-none text-sm px-4 min-h-[44px] sm:min-h-0 sm:py-1.5 rounded text-white disabled:opacity-50', paleta.btn]">
                    {{ cargando ? 'Procesando…' : textoConfirmar }}
                </button>
            </div>
        </div>
    </div>
</template>
