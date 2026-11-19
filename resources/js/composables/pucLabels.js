/**
 * Re-audit M5 UX-A4 · mapa PUC colombiano → nombre legible.
 *
 * Aracely no memoriza PUC. Los códigos crudos (1105, 4135, 2408) hacen los
 * reportes contables una tabla de números. Este helper devuelve el nombre
 * corto de la cuenta; para prefijos (1305xx, 4135xx) devuelve el nombre del
 * grupo. Compatible tanto con tooltip como con render inline.
 *
 * ⚠ ESTO ES EL RESPALDO, NO LA FUENTE.
 *
 * Se escribió cuando el ERP todavía no tenía cargado el PUC del cliente. Hoy
 * sí lo tiene —1.079 cuentas con su nombre— y el backend manda `cuenta_nombre`
 * en cada fila: las pantallas usan ese y caen acá sólo si la cuenta no está en
 * el catálogo.
 *
 * Importa que sea así porque esta tabla resuelve POR PREFIJO, y desde que el
 * asiento de venta parte el ingreso por categoría eso dejó de ser una
 * aproximación y pasó a ser un error: 41350110 «Vestuario bebé» y 41350104
 * «Juguetería» aparecían con el mismo nombre, una encima de la otra, como si
 * fueran la misma cuenta. No agregues códigos nuevos acá; van en el plan de
 * cuentas.
 */

// Cuentas exactas (más específicas) primero.
const EXACT = {
    '1105': 'Caja general',
    '1110': 'Bancos',
    '1305': 'Clientes B2B (CxC)',
    '1355': 'Impuestos anticipados / saldos a favor',
    '1360': 'Anticipo de impuestos',
    '1435': 'Inventario',
    '2205': 'Proveedores nacionales',
    '2365': 'Retención en la fuente (RETEFTE)',
    '2367': 'Retención IVA (RETEIVA)',
    '2368': 'Retención de ICA (RETEICA)',
    '2380': 'Acreedores varios',
    // R4 FUNC-M2 · en PUC oficial 2404 no es cuenta estándar; GB la usa como
    // "IVA descontable" (activo, saldo naturalmente débito) aunque el prefijo
    // 24 es "Impuestos por pagar" (pasivo). Etiqueta lo aclara.
    '2404': 'IVA descontable (uso interno GB)',
    '2408': 'IVA por pagar',
    '2505': 'Salarios por pagar',
    '2805': 'Anticipos y avances recibidos',
    '3115': 'Capital suscrito y pagado',
    '3705': 'Resultados acumulados',
    '4135': 'Ingresos comercio al por mayor',
    '4175': 'Devoluciones en ventas',
    '4210': 'Ingresos financieros',
    '5195': 'Transportes, fletes y acarreos',
    '5205': 'Gastos de personal',
    '5305': 'Gastos financieros',
    '530510': 'Descuentos comerciales condicionados',
    '6135': 'Costo de mercancía vendida',
};

// Grupos por prefijo de 2 dígitos (fallback).
const PREFIX2 = {
    '11': 'Disponible',
    '13': 'Cuentas por cobrar',
    '14': 'Inventarios',
    '15': 'Propiedad, planta y equipo',
    '22': 'Proveedores',
    '23': 'Cuentas por pagar',
    '24': 'Impuestos por pagar',
    '25': 'Obligaciones laborales',
    '28': 'Otros pasivos',
    '31': 'Capital',
    '36': 'Resultados del ejercicio',
    '41': 'Ingresos operacionales',
    '42': 'Ingresos no operacionales',
    '51': 'Gastos de administración',
    '52': 'Gastos de ventas',
    // Re-audit R2 FUNC-M6 · 53 en PUC colombiano es "No operacionales"; los
    // FINANCIEROS son la subcuenta 5305. Etiqueta corregida.
    '53': 'Gastos no operacionales',
    '61': 'Costo de ventas',
    '73': 'Producción',
    '74': 'Contratos de servicio',
};

// Familia (primer dígito) para agrupar activo/pasivo/etc.
const FAMILIA = {
    '1': 'Activo', '2': 'Pasivo', '3': 'Patrimonio',
    '4': 'Ingreso', '5': 'Gasto', '6': 'Costo', '7': 'Producción',
};

export function pucLabel(code) {
    if (!code) return '';
    const c = String(code);
    if (EXACT[c]) return EXACT[c];
    // Prefix largo (530510 → 5305) antes que corto.
    for (let len = c.length - 1; len >= 4; len--) {
        const p = c.slice(0, len);
        if (EXACT[p]) return EXACT[p];
    }
    const p2 = c.slice(0, 2);
    if (PREFIX2[p2]) return PREFIX2[p2];
    return FAMILIA[c[0]] || 'Cuenta sin catálogo';
}

export function pucFamilia(code) {
    return code ? (FAMILIA[String(code)[0]] || 'Otro') : 'Otro';
}
