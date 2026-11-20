import { watch, onBeforeUnmount } from 'vue';

/**
 * Cierra un modal con la tecla Escape.
 *
 * Re-audit UX#5 · registra un listener global `keydown Escape` mientras el
 * modal está abierto, y se auto-limpia al cerrarlo o al desmontar.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * POR QUÉ IMPORTA MÁS DE LO QUE PARECE
 *
 * Un modal sin Escape no es una incomodidad: en una tablet de bodega el
 * teclado en pantalla recorta la mitad del alto, la tarjeta se desborda sin
 * scroll y los botones ✕ y Cancelar quedan fuera de cuadro. Si además el
 * fondo no cierra, la única salida es recargar la página y perder lo escrito.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * Uso normal, con un booleano:
 *
 *     const modal = ref(false);
 *     useEscClose(modal);
 *
 * Cuando el ref guarda el OBJETO que se está editando en vez de un booleano
 * —`const linea = ref(null)`, muy común en este repo— también sirve: al
 * cerrar se le pone `null` y no `false`, que dejaría la condición `v-if` en
 * un valor que el resto del código no espera.
 *
 * Y si cerrar requiere algo más que vaciar el ref —limpiar un formulario,
 * abortar una subida— se le pasa la función:
 *
 *     useEscClose(modal, () => cancelarTodo());
 *
 * @param {import('vue').Ref} openRef  el ref que abre y cierra el modal
 * @param {?Function} cerrar  qué hacer al pulsar Escape, si no basta vaciarlo
 */
export function useEscClose(openRef, cerrar = null) {
    const handler = (e) => {
        if (e.key !== 'Escape' || ! openRef.value) {
            return;
        }

        if (cerrar) {
            cerrar();

            return;
        }

        // Un ref que guarda un objeto se vacía con null: ponerle `false`
        // dejaría `v-if` apagado pero el resto del código leyendo un booleano
        // donde espera una ficha.
        openRef.value = typeof openRef.value === 'boolean' ? false : null;
    };

    watch(openRef, (isOpen) => {
        if (isOpen) {
            window.addEventListener('keydown', handler);
        } else {
            window.removeEventListener('keydown', handler);
        }
    }, { immediate: true });

    onBeforeUnmount(() => window.removeEventListener('keydown', handler));
}
