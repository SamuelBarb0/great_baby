import { computed } from 'vue';

/*
 * Señala una fila concreta de un listado, pedida por la URL.
 *
 * La Torre SIIGO enlazaba al detalle de cada documento pendiente, pero cuatro
 * de los seis tipos NO TIENEN pantalla de detalle: pagos a proveedor, notas
 * crédito, notas débito y asientos manuales sólo existen como listado. Esos
 * cuatro «Ver detalle» daban 404 — justo en la pantalla que existe para
 * destrabar lo que no llegó a SIIGO.
 *
 * En vez de inventar cuatro pantallas de detalle, el enlace lleva al listado
 * con `?destacar=<id>` y la fila queda marcada. Es lo que la persona necesita:
 * ver el documento y apretar «Enviar a SIIGO».
 *
 * Lee la URL directamente y no una prop, para que el backend no tenga que
 * pasarla en cada controlador.
 */
export function useDestacar(parametro = 'destacar') {
    const idDestacado = computed(() => {
        if (typeof window === 'undefined') return null;

        const v = new URLSearchParams(window.location.search).get(parametro);
        const n = Number(v);

        return Number.isInteger(n) && n > 0 ? n : null;
    });

    /** Las clases de la fila señalada. Devuelve '' para las demás. */
    const clasesDe = (id) => id === idDestacado.value
        ? 'ring-2 ring-brand-500 ring-inset bg-brand-50 dark:bg-brand-900/20'
        : '';

    return { idDestacado, clasesDe };
}
