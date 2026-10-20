/**
 * Ubicación del equipo para la trazabilidad del empaque/despacho Dropi.
 *
 * Nunca rechaza: si el operario niega el permiso o el equipo no tiene GPS,
 * devuelve el motivo (`denegado` / `no_disponible`) para que el servidor lo
 * anote y decida si frena (regla `empaque.gps_obligatorio`).
 *
 * El navegador sólo pide el permiso la primera vez; después responde en
 * milisegundos. `maximumAge` reusa una lectura de hasta 1 min para no hacer
 * esperar a la pistola en cada pedido.
 */
export function obtenerUbicacion(timeoutMs = 8000) {
    return new Promise((resolve) => {
        if (typeof navigator === 'undefined' || !navigator.geolocation) {
            resolve({ estado: 'no_disponible' });
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (pos) => resolve({
                estado: 'ok',
                lat: pos.coords.latitude,
                lng: pos.coords.longitude,
                precision: Math.round(pos.coords.accuracy),
            }),
            (err) => resolve({ estado: err?.code === 1 ? 'denegado' : 'no_disponible' }),
            { enableHighAccuracy: true, timeout: timeoutMs, maximumAge: 60_000 },
        );
    });
}

export function useUbicacion() {
    return { obtenerUbicacion };
}
