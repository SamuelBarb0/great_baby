/*
 * Las pestañas de las dos secciones que se unificaron.
 *
 * Viven acá y no copiadas en cada página para que agregar o renombrar una
 * pantalla sea un solo cambio. Si se declaran en nueve archivos distintos, al
 * mes están desincronizadas.
 */

export const pestanasSiigo = [
    { label: '⚠ Pendientes', href: '/app/contabilidad/pendientes-siigo' },
    { label: '🔎 Discrepancias', href: '/app/contabilidad/discrepancias-siigo' },
    { label: '✓ Validación del PUC', href: '/app/contabilidad/validacion-puc-siigo' },
    { label: '↔ Conciliación', href: '/app/contabilidad/siigo/conciliacion' },
    { label: 'Cuentas por pagar', href: '/app/contabilidad/siigo/cuentas-por-pagar' },
];

export const pestanasConfigContable = [
    { label: 'Plan de cuentas (PUC)', href: '/app/contabilidad/plan-cuentas' },
    { label: 'Retenciones e impuestos', href: '/app/cartera/retenciones' },
    { label: 'Métodos de pago', href: '/app/cartera/metodos-pago' },
    { label: 'Comisiones de vendedores', href: '/app/cartera/comisiones' },
];

/* El libro: lo que ya está asentado y lo que se asienta a mano. */
export const pestanasLibro = [
    { label: 'Movimientos', href: '/app/cartera/movimientos' },
    { label: 'Asientos manuales', href: '/app/contabilidad/asientos-manuales' },
];

/* Los informes contables, los cuatro juntos. */
export const pestanasReportes = [
    { label: '📊 Balance general', href: '/app/contabilidad/balance-general' },
    { label: '📊 Estado de resultados', href: '/app/contabilidad/estado-resultados' },
    { label: 'Reportes contables', href: '/app/contabilidad/reportes' },
    { label: 'Detalle de movimientos', href: '/app/contabilidad/reporte-detalle' },
];

/* Notas que corrigen una factura. */
export const pestanasNotas = [
    { label: 'Notas crédito', href: '/app/cartera/notas-credito' },
    { label: 'Notas débito', href: '/app/cartera/notas-debito' },
];

/* El panel contable: la foto del mes y la del día. */
export const pestanasPanel = [
    { label: 'Resumen del mes', href: '/app/contabilidad' },
    { label: 'Panel del día', href: '/app/contabilidad/panel' },
];

/* Todo lo de cobrarle al cliente: condiciones, gestiones y solicitudes. */
export const pestanasCobranza = [
    { label: 'Crédito y condiciones', href: '/app/credito' },
    { label: 'Cobranzas enviadas', href: '/app/cartera/cobranzas' },
    { label: 'Solicitudes de crédito', href: '/app/cartera/solicitudes' },
];
