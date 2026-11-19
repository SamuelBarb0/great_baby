import '../css/app.css';
import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp, Link, Head, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import tablaMovil from './directivas/tablaMovil';

/*
 * Interceptor GLOBAL: cualquier respuesta 4xx/5xx o caída de red dispara
 * toast+beep y una alerta visual persistente. Elimina el «silencio en
 * errores» (UX-13, UX-2).
 *
 * ────────────────────────────────────────────────────────────────────────
 * LOS NOMBRES DE LOS EVENTOS IMPORTAN, Y NO FALLAN RUIDOSAMENTE
 *
 * Esto escuchaba `invalid` y `exception`, que son de Inertia 1. El proyecto
 * corre @inertiajs/vue3 3.7, donde se llaman `httpException` y
 * `networkError`. Comprobado contra la librería instalada el 19-nov-2026:
 * los eventos reales son before, beforeUpdate, clientVisit, error, finish,
 * flash, httpException, initial, internal, location, navigate, prefetched,
 * prefetching, progress, start, success. `invalid` y `exception` no existen.
 *
 * Y `router.on()` no valida el nombre: hace un `addEventListener` sobre
 * `inertia:<loquesea>` sin quejarse. O sea que los dos oyentes quedaban
 * colgados de eventos que nadie dispara nunca, en silencio, durante meses.
 *
 * Lo que eso significaba en la práctica:
 *   · Un `abort(403/422)`, un 419 de sesión vencida o un 500 mostraban el
 *     diálogo crudo de Inertia con la página de error de Laravel — en
 *     producción, sin el motivo.
 *   · Una caída de red no mostraba ABSOLUTAMENTE NADA: el botón se
 *     desbloqueaba y la pantalla quedaba igual, así que la persona
 *     reintentaba sin saber qué pasó.
 * ────────────────────────────────────────────────────────────────────────
 */
router.on('httpException', (event) => {
    const status = event.detail?.response?.status;

    console.error('[Inertia] respuesta', status, event.detail);

    // Cancelar el evento evita el diálogo con el HTML crudo de Laravel y deja
    // el toast, que es lo que la persona puede entender.
    event.preventDefault();

    const mensaje = {
        401: 'Tu sesión se cerró. Volvé a entrar.',
        403: 'No tenés permiso para hacer eso.',
        404: 'Eso ya no está.',
        419: 'La página estuvo abierta demasiado tiempo. Recargá y volvé a intentar.',
        429: 'Demasiados intentos seguidos. Esperá un momento.',
    }[status] ?? (status >= 500
        ? 'El servidor falló. Si vuelve a pasar, avisá a sistemas.'
        : 'El servidor rechazó la operación.');

    window.dispatchEvent(new CustomEvent('gb:error', { detail: { mensaje } }));
});
router.on('networkError', (event) => {
    console.error('[Inertia] sin red', event.detail?.error);
    window.dispatchEvent(new CustomEvent('gb:error', { detail: { mensaje: 'Sin conexión con el servidor — reintentá' } }));
});
router.on('error', (event) => {
    const errs = event.detail?.errors || {};
    const first = Object.values(errs)[0];
    const msg = Array.isArray(first) ? first[0] : (typeof first === 'string' ? first : 'Hubo un error en la operación');
    window.dispatchEvent(new CustomEvent('gb:error', { detail: { mensaje: msg } }));
});

const appName = import.meta.env.VITE_APP_NAME || 'GREAT BABY · ERP';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => resolvePageComponent(
        `./Pages/${name}.vue`,
        import.meta.glob('./Pages/**/*.vue')
    ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .component('Link', Link)
            .component('Head', Head)
            // Apila las tablas marcadas cuando la pantalla es angosta.
            .directive('tabla-movil', tablaMovil)
            .mount(el);
    },
    progress: {
        color: '#f59e0b',
        showSpinner: true,
    },
});
