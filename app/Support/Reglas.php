<?php

namespace App\Support;

use App\Models\ReglaNegocio;

class Reglas
{
    /** Diccionario canónico de reglas (defaults + metadatos para la UI). */
    public const DEFAULTS = [
        // ============ EMPAQUE ============
        'empaque.min_foto_bytes' => ['grupo' => 'empaque', 'tipo' => 'int', 'valor' => 30000, 'etiqueta' => 'Tamaño mínimo de foto de empaque (bytes)', 'descripcion' => 'Evita fotos vacías/negras; 30 KB por defecto'],
        'empaque.max_foto_bytes' => ['grupo' => 'empaque', 'tipo' => 'int', 'valor' => 3_000_000, 'etiqueta' => 'Tamaño máximo de foto (bytes)', 'descripcion' => '3 MB por defecto'],
        'empaque.foto_obligatoria' => ['grupo' => 'empaque', 'tipo' => 'bool', 'valor' => '1', 'etiqueta' => 'Foto obligatoria para confirmar empaque', 'descripcion' => 'Bloquea el confirmar si no hay foto'],
        'empaque.tiempo_objetivo_seg' => ['grupo' => 'empaque', 'tipo' => 'int', 'valor' => 180, 'etiqueta' => 'Tiempo objetivo por empaque (segundos)', 'descripcion' => 'Base para semáforos y ranking'],
        'empaque.rate_limit_min' => ['grupo' => 'empaque', 'tipo' => 'int', 'valor' => 120, 'etiqueta' => 'Escaneos máximos por minuto', 'descripcion' => 'Throttle en la estación'],
        'empaque.retention_foto_dias' => ['grupo' => 'empaque', 'tipo' => 'int', 'valor' => 180, 'etiqueta' => 'Días retención foto empaque', 'descripcion' => 'Purga automática de fotos más antiguas que N días post-empaque (Habeas Data)'],

        // ============ DASHBOARD (alertas) ============
        'dashboard.alerta_pendientes' => ['grupo' => 'dashboard', 'tipo' => 'int', 'valor' => 50, 'etiqueta' => 'Alerta si pendientes por empacar >', 'descripcion' => 'Dispara alerta en Torre de Control'],
        'dashboard.alerta_tasa_devolucion' => ['grupo' => 'dashboard', 'tipo' => 'float', 'valor' => '15', 'etiqueta' => 'Alerta si tasa devolución % >', 'descripcion' => 'Umbral crítico de devolución del día'],
        'dashboard.refresh_seg' => ['grupo' => 'dashboard', 'tipo' => 'int', 'valor' => 30, 'etiqueta' => 'Auto-refresh dashboard (segundos)', 'descripcion' => ''],

        // ============ CARTERA · semáforo ============
        'cartera.bucket_1_dias' => ['grupo' => 'cartera', 'tipo' => 'int', 'valor' => 30, 'etiqueta' => 'Semáforo verde hasta N días', 'descripcion' => ''],
        'cartera.bucket_2_dias' => ['grupo' => 'cartera', 'tipo' => 'int', 'valor' => 60, 'etiqueta' => 'Semáforo amarillo hasta N días', 'descripcion' => ''],
        'cartera.bucket_3_dias' => ['grupo' => 'cartera', 'tipo' => 'int', 'valor' => 90, 'etiqueta' => 'Semáforo rojo hasta N días', 'descripcion' => 'Después de esto: crítico'],
        'cartera.match_dias_tolerancia' => ['grupo' => 'cartera', 'tipo' => 'int', 'valor' => 3, 'etiqueta' => 'Tolerancia días para match bancario', 'descripcion' => 'Fecha del pago ± N días'],
        'cartera.match_monto_tolerancia' => ['grupo' => 'cartera', 'tipo' => 'float', 'valor' => '0', 'etiqueta' => 'Tolerancia monto para match bancario (COP)', 'descripcion' => '0 = exacto'],

        // ============ DROPI ============
        'dropi.dias_retorno_mercancia' => ['grupo' => 'dropi', 'tipo' => 'int', 'valor' => 20, 'etiqueta' => 'Días máx. mercancía en tránsito antes de sospechosa', 'descripcion' => 'Al superar: alertar Aracely y marcar sospechoso'],
        'dropi.dias_tolerancia_devolucion' => ['grupo' => 'dropi', 'tipo' => 'int', 'valor' => 7, 'etiqueta' => 'Días tolerancia devolución física post-marca', 'descripcion' => 'Si Dropi marca devuelto pero no llega en N días → mercancía fantasma'],
        'dropi.dias_espera_cobro' => ['grupo' => 'dropi', 'tipo' => 'int', 'valor' => 40, 'etiqueta' => 'Días máx. entregado sin cobro antes de discrepancia', 'descripcion' => 'Un pedido entregado y sin movimiento wallet tras N días aparece en Discrepancias'],
        'dropi.auto_nota_credito' => ['grupo' => 'dropi', 'tipo' => 'bool', 'valor' => '1', 'etiqueta' => 'Auto-generar Nota Crédito al devolver', 'descripcion' => 'Al marcar DropiPedido como Devuelto emite NC'],
        'dropi.auto_nc_envia_siigo' => ['grupo' => 'dropi', 'tipo' => 'bool', 'valor' => '1', 'etiqueta' => 'Auto-enviar NC a SIIGO', 'descripcion' => ''],

        // ============ CATÁLOGO ============
        'catalogo.preservar_codigo_china' => ['grupo' => 'catalogo', 'tipo' => 'bool', 'valor' => '1', 'etiqueta' => 'Preservar consecutivo China en re-import', 'descripcion' => 'Si el SKU existe reusa el código de barras; no lo pisa'],
        'catalogo.bloquear_cambio_codigo' => ['grupo' => 'catalogo', 'tipo' => 'bool', 'valor' => '1', 'etiqueta' => 'Bloquear cambio manual de código de barras', 'descripcion' => 'Evita duplicados accidentales'],

        // ============ CONTABILIDAD · cuentas por defecto ============
        // Actualizados 2026-09-28 con PUC oficial Great Baby SAS (NIT 901738354).
        // ⚠ Las cuentas de INGRESO/COSTO/INVENTARIO específicas de producto deben ser
        // confirmadas con Silvia (contadora) porque dependen de la línea (accesorios/ropa/etc).
        // Dejamos GRUPO de 4 dígitos como default; en la ficha contable de cada producto
        // se puede sobrescribir con la subcuenta específica (6 dígitos).
        'contable.cta_cxc_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '130505', 'etiqueta' => 'Cuenta CxC por defecto (Clientes nacionales)', 'descripcion' => 'PUC Great Baby · 130505 Clientes nacionales'],
        'contable.cta_ingreso_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '4135', 'etiqueta' => 'Cuenta ingreso por defecto', 'descripcion' => '⚠ Confirmar subcuenta específica con Silvia (4135xx según categoría)'],
        'contable.cta_iva_venta_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '240805', 'etiqueta' => 'Cuenta IVA venta por defecto', 'descripcion' => 'PUC Great Baby · 240805 IVA generado en ventas'],
        'contable.cta_iva_compra_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '240810', 'etiqueta' => 'Cuenta IVA compra descontable', 'descripcion' => 'PUC Great Baby · 240810 IVA descontable por compras'],
        'contable.cta_costo_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '6135', 'etiqueta' => 'Cuenta costo por defecto', 'descripcion' => '⚠ Confirmar subcuenta específica con Silvia (6135xx según categoría)'],
        'contable.cta_inventario_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '1435', 'etiqueta' => 'Cuenta inventario por defecto', 'descripcion' => '⚠ Confirmar subcuenta específica con Silvia (1435xx según categoría)'],
        'contable.cta_devolucion_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '4175', 'etiqueta' => 'Cuenta devolución en ventas por defecto', 'descripcion' => '⚠ Confirmar subcuenta específica con Silvia'],
        'contable.cta_descuento_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '5305', 'etiqueta' => 'Cuenta descuento comercial por defecto', 'descripcion' => '⚠ Confirmar subcuenta específica con Silvia'],
        'contable.cta_bancos_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '112005', 'etiqueta' => 'Cuenta bancos por defecto', 'descripcion' => 'PUC Great Baby · 112005 Bancos moneda nacional'],
        'contable.cta_caja_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '110505', 'etiqueta' => 'Cuenta caja general', 'descripcion' => 'PUC Great Baby · 110505 Caja general'],
        'contable.cta_proveedores_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '220505', 'etiqueta' => 'Cuenta proveedores nacionales', 'descripcion' => 'PUC Great Baby · 220505 Proveedores nacionales'],
        'contable.cta_retefuente_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '236575', 'etiqueta' => 'Cuenta autorretenciones por defecto', 'descripcion' => '⚠ Confirmar concepto específico con Silvia (2365xx según naturaleza)'],
        // Retenciones que el CLIENTE nos practica al pagar una venta: son saldo a
        // favor (1355), no por pagar (2365, que es lo de compras).
        'contable.cta_retefuente_venta' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '135515', 'etiqueta' => 'Retefuente que nos practican (ventas)', 'descripcion' => 'PUC · 135515 Retención en la fuente. ⚠ Confirmar con Silvia'],
        'contable.cta_reteiva_venta' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '135517', 'etiqueta' => 'ReteIVA que nos practican (ventas)', 'descripcion' => 'PUC · 135517 Impuesto a las ventas retenido. ⚠ Confirmar con Silvia'],
        'contable.cta_reteica_venta' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '135518', 'etiqueta' => 'ReteICA que nos practican (ventas)', 'descripcion' => 'PUC · 135518 Impuesto de industria y comercio retenido. ⚠ Confirmar con Silvia'],
        'contable.concepto_retencion_venta' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => 'compras_generales', 'etiqueta' => 'Concepto de retención en ventas', 'descripcion' => 'Regla de Cartera → Retenciones que se usa al facturar: el cliente nos compra mercancía, así que para él es «compras generales».'],
        'contable.centro_costo_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => 'GB-01', 'etiqueta' => 'Centro de costo por defecto', 'descripcion' => 'Cuenta / centro de imputación por defecto para asientos'],

        // ============ CRM · segmentación ============
        'crm.vip_min_ventas' => ['grupo' => 'crm', 'tipo' => 'float', 'valor' => '5000000', 'etiqueta' => 'Ventas anuales mínimas para VIP (COP)', 'descripcion' => ''],
        'crm.dormido_dias' => ['grupo' => 'crm', 'tipo' => 'int', 'valor' => 90, 'etiqueta' => 'Días sin compra para marcar Dormido', 'descripcion' => ''],
        'crm.riesgo_min_moras' => ['grupo' => 'crm', 'tipo' => 'int', 'valor' => 2, 'etiqueta' => 'Moras mínimas para marcar Riesgo', 'descripcion' => ''],
        'crm.comision_porcentaje' => ['grupo' => 'crm', 'tipo' => 'float', 'valor' => '3', 'etiqueta' => 'Comisión vendedor por defecto (%)', 'descripcion' => 'Se puede sobrescribir por vendedor'],

        // --- Exportación fiscal a SIIGO (IDs de tipo de documento + cuentas por defecto) ---
        'siigo.doc_type_compra' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Tipo de documento Compra', 'descripcion' => 'ID del comprobante de compra en SIIGO'],
        'siigo.doc_type_gasto' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Tipo de documento Gasto (asiento)', 'descripcion' => 'ID del comprobante contable para gastos'],
        'siigo.doc_type_importacion' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Tipo de documento Importación', 'descripcion' => 'ID del comprobante de compra para importaciones'],
        'siigo.doc_type_conciliacion' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Tipo de documento Conciliación (asiento)', 'descripcion' => 'ID del comprobante contable para conciliaciones'],
        'siigo.cta_gasto_default' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => '5195', 'etiqueta' => 'SIIGO · Cuenta de gasto por defecto', 'descripcion' => 'PUC débito para gastos sin asiento previo'],
        'siigo.cta_banco_default' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => '1110', 'etiqueta' => 'SIIGO · Cuenta de banco por defecto', 'descripcion' => 'PUC de bancos'],
        'siigo.cta_conciliacion_default' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => '139535', 'etiqueta' => 'SIIGO · Partida conciliatoria por defecto', 'descripcion' => 'PUC para la diferencia de conciliación'],
        'siigo.proveedor_importacion_doc' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => '', 'etiqueta' => 'SIIGO · NIT proveedor de importaciones', 'descripcion' => 'Identificación por defecto para comprobantes de importación'],
        // UBIC-6 · resolución DIAN FV auto-asignada a nuevas ubicaciones de venta.
        'siigo.resolucion_fv_default_id' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Resolución DIAN default (FV)', 'descripcion' => 'ID del document-type FV que se auto-asigna a toda ubicación de venta nueva sin resolución elegida'],
        // INV-A1 · cuentas PUC alineadas con CerrarTomaFisica para que SIIGO
        // y el ERP escriban en la misma cuenta (antes SIIGO usaba 5195 y
        // CerrarTomaFisica usaba 5299 → libros divergentes).
        'siigo.cta_perdida_inventario_default' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => '5299', 'etiqueta' => 'SIIGO · Cuenta pérdida por baja inventario', 'descripcion' => 'PUC débito para faltantes/mermas (default 5299 pérdida inventario)'],
        'siigo.cta_sobrante_inventario_default' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => '4295', 'etiqueta' => 'SIIGO · Cuenta sobrante inventario', 'descripcion' => 'PUC crédito para sobrantes de toma física (default 4295 diversos)'],
        // COMP-B1 · documento SIIGO para NC de compra (devolución a proveedor).
        'siigo.doc_type_nc_compra' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Comprobante de devolución a proveedor', 'descripcion' => 'Comprobante contable (CC) con el que se asienta la devolución a proveedor. Se manda por /v1/journals, no es una nota crédito pese al nombre de la regla'],

        // ── Claves que el código leía y nadie podía configurar ──────────────
        //
        // `SiigoEmisionService` pedía estas tres con `setting(..., 0)`. Como no
        // estaban declaradas acá, nunca se sembraban en la base, siempre valían
        // 0, el bloque `document` se descartaba del payload y SIIGO respondía
        // «The field document is required». Resultado medido el 2026-10-08: 0 de
        // 79 asientos, 0 notas crédito y 0 notas débito habían llegado a SIIGO.
        // No era un fallo intermitente: era imposible que funcionara.
        // SIIGO pide un tercero en cada linea del journal, tambien en los
        // ajustes de inventario, que no tienen contraparte externa. Vacio =
        // se usa el NIT de la empresa (Configuracion -> Empresa).
        'siigo.tercero_ajustes_inventario' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => '', 'etiqueta' => 'SIIGO · Tercero para ajustes de inventario', 'descripcion' => 'Identificación con la que se asientan traslados, mermas y sobrantes. Vacío = el NIT de la empresa, sin puntos ni dígito de verificación'],
        'siigo.doc_type_nota_credito' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Tipo documento Nota crédito (venta)', 'descripcion' => 'ID del document-type NC. Sin esto SIIGO rechaza toda nota crédito'],
        'siigo.doc_type_nota_debito' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Tipo documento Nota débito (venta)', 'descripcion' => 'ID del document-type ND. Sin esto SIIGO rechaza toda nota débito'],
        'siigo.doc_type_asiento_manual' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Tipo documento Asiento manual', 'descripcion' => 'ID del comprobante contable (CC) para asientos manuales. Si queda en 0 se usa el de Gasto'],

        // Centro de costo de las compras. SIIGO lo exige en algunas cuentas.
        'siigo.cost_center_compras' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Centro de costo de compras', 'descripcion' => 'ID del centro de costo para facturas de compra. 0 = no se manda'],

        // Ciudad de respaldo cuando un contacto trae una que no está en el DANE.
        'siigo.ciudad_default' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => '11001', 'etiqueta' => 'SIIGO · Código DANE de ciudad por defecto', 'descripcion' => 'Se usa cuando la ciudad del contacto no figura en la tabla DANE (11001 = Bogotá)'],

        // Destinos del comparador diario ERP ↔ SIIGO (TEST-S7). Sin estos el
        // comando corre, escribe el reporte y no avisa a nadie.
        'siigo.alerta_whatsapp' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => '', 'etiqueta' => 'SIIGO · WhatsApp para alertas de descuadre', 'descripcion' => 'Número que recibe la alerta diaria si el ERP y SIIGO no cuadran. Vacío = no se manda'],
        'siigo.alerta_email' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => '', 'etiqueta' => 'SIIGO · Correo para alertas de descuadre', 'descripcion' => 'Correo que recibe el reporte diario de comparación. Vacío = no se manda'],

        // Campos personalizados del producto en SIIGO, por nombre → id.
        'siigo.custom_fields_map' => ['grupo' => 'siigo', 'tipo' => 'json', 'valor' => '{}', 'etiqueta' => 'SIIGO · Mapa de campos personalizados', 'descripcion' => 'JSON {"linea": 12345, "grupo": 12346} con los ids de los custom fields del producto en SIIGO'],
        'siigo.payment_type_compra_default' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Payment type compra default', 'descripcion' => 'Payment type por defecto para NC/factura de compra'],
        // COMP-B1 · cuentas AUXILIARES transaccionales SIIGO para journals de devolución
        // a proveedor. SIIGO rechaza cuentas padre (2205, 1435, 2408) con error
        // `account_not_allowed · transactional characteristic not allowed`.
        // La contadora las edita en el panel de Reglas sin tocar código.
        'siigo.cta_cxp_proveedor_siigo' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => '22050501', 'etiqueta' => 'SIIGO · Cuenta auxiliar CxP proveedor', 'descripcion' => 'PUC auxiliar transaccional de proveedores (SIIGO estándar: 22050501). Débito al devolver mercancía al proveedor'],
        'siigo.cta_inventario_siigo' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => '1435010101', 'etiqueta' => 'SIIGO · Cuenta auxiliar inventario', 'descripcion' => 'PUC auxiliar transaccional de inventario de mercancías (SIIGO estándar: 1435010101). Crédito al bajar inventario por devolución'],
        'siigo.cta_iva_dev_compra_siigo' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => '24082001', 'etiqueta' => 'SIIGO · Cuenta IVA devolución compra', 'descripcion' => 'PUC auxiliar transaccional de IVA Devolución en compras (estándar: 24082001 para 19%). Crédito al reversar el IVA de la compra devuelta'],
        'siigo.centro_costo_siigo' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Centro de costo default', 'descripcion' => 'ID numérico del centro de costo SIIGO · Opcional; se envía solo si el tipo de comprobante lo exige'],
        'siigo.tax_id_iva_19' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 1270, 'etiqueta' => 'SIIGO · ID impuesto IVA 19%', 'descripcion' => 'ID numérico del impuesto IVA 19% en SIIGO (default sandbox: 1270). Se usa en líneas de IVA de los journals'],
        'siigo.iva_porcentaje' => ['grupo' => 'siigo', 'tipo' => 'float', 'valor' => '19', 'etiqueta' => 'SIIGO · Porcentaje IVA default', 'descripcion' => 'Porcentaje del IVA default (19 Colombia). Se usa para calcular base_value en journals a partir del monto del IVA'],
        // Comprobantes de tesorería y contabilidad. Antes el código los leía con
        // `setting('siigo.doc_type_egreso')` pero NUNCA estuvieron declarados acá:
        // devolvían null, los jobs morían con "Falta configurar …" y no había
        // forma de arreglarlo desde el panel. Los tipos son los de SIIGO:
        // RC = recibo de caja (cobro a cliente) · RP = recibo de pago / egreso
        // (pago a proveedor) · CC = comprobante contable (asientos).
        'siigo.doc_type_recibo' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 2371, 'etiqueta' => 'SIIGO · Tipo documento Recibo de caja (RC)', 'descripcion' => 'ID del comprobante con que se registran los COBROS a clientes. Se consulta con GET /v1/document-types?type=RC'],
        'siigo.payment_type_recibo' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Medio de pago del recibo de caja', 'descripcion' => 'Medio de pago para los cobros. Se consulta con GET /v1/payment-types?document_type=RC'],
        'siigo.doc_type_egreso' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 2376, 'etiqueta' => 'SIIGO · Tipo documento Egreso (RP)', 'descripcion' => 'ID del comprobante con que se registran los PAGOS a proveedores. Se consulta con GET /v1/document-types?type=RP'],
        'siigo.payment_type_egreso' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Medio de pago del egreso', 'descripcion' => 'Medio de pago para los pagos a proveedores. Si queda en 0 se usa el del recibo de caja'],
        'siigo.doc_type_asiento' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 2393, 'etiqueta' => 'SIIGO · Tipo documento Asiento contable (CC)', 'descripcion' => 'ID del comprobante contable para asientos manuales y de inventario. Se consulta con GET /v1/document-types?type=CC'],
    ];

    /** Memoization por-request para evitar N lecturas de cache en un mismo handler. */
    private static ?array $requestCache = null;

    public static function get(string $clave, mixed $default = null): mixed
    {
        if (self::$requestCache === null) {
            // `setting()` se usa en todo el código como si no pudiera fallar, así
            // que si la tabla todavía no existe (instalación nueva, test unitario
            // que no migra) devolvemos los valores por defecto en vez de reventar.
            try {
                self::$requestCache = ReglaNegocio::todas();
            } catch (\Throwable) {
                self::$requestCache = [];
            }
        }
        if (array_key_exists($clave, self::$requestCache)) return self::$requestCache[$clave];

        // Fallback: retornar el default con el TIPO NATIVO PHP que espera el consumer
        // (paridad con ReglaNegocio::valorCasteado — Sec F8).
        $meta = self::DEFAULTS[$clave] ?? null;
        $raw = $default ?? ($meta['valor'] ?? null);
        if ($meta === null || $raw === null) return $raw;
        return match ($meta['tipo']) {
            'int' => (int) $raw,
            'float' => (float) $raw,
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'json' => is_string($raw) ? json_decode($raw, true) : $raw,
            default => (string) $raw,
        };
    }

    public static function limpiarRequestCache(): void
    {
        self::$requestCache = null;
    }

    /**
     * Casteo estricto según DEFAULTS. Retorna [ok:bool, valor:mixed, error?:string].
     * Rechaza inputs inválidos (evita min_foto_bytes="false" → 0).
     */
    public static function castear(string $clave, mixed $input): array
    {
        $meta = self::DEFAULTS[$clave] ?? null;
        if (! $meta) return [false, null, "Clave desconocida"];

        switch ($meta['tipo']) {
            case 'int':
                if (is_int($input)) return [true, $input, null];
                if (is_string($input) && preg_match('/^-?\d+$/', trim($input))) return [true, (int) trim($input), null];
                if (is_numeric($input)) return [true, (int) $input, null];
                return [false, null, "Debe ser un número entero"];
            case 'float':
                if (is_float($input) || is_int($input)) return [true, (float) $input, null];
                if (is_string($input)) {
                    $s = trim(str_replace(',', '.', $input));
                    if (is_numeric($s)) return [true, (float) $s, null];
                }
                return [false, null, "Debe ser un número"];
            case 'bool':
                if (is_bool($input)) return [true, $input ? '1' : '0', null];
                if (in_array($input, [1, '1', 'true', 'on', 'yes'], true)) return [true, '1', null];
                if (in_array($input, [0, '0', 'false', 'off', 'no', '', null], true)) return [true, '0', null];
                return [false, null, "Debe ser sí/no"];
            case 'json':
                if (is_array($input) || is_object($input)) return [true, json_encode($input, JSON_UNESCAPED_UNICODE), null];
                if (is_string($input) && json_decode($input) !== null) return [true, $input, null];
                return [false, null, "Debe ser JSON válido"];
            case 'string':
            default:
                if (is_scalar($input) || $input === null) return [true, (string) ($input ?? ''), null];
                return [false, null, "Debe ser texto"];
        }
    }

    /**
     * Guarda una regla con validación estricta. Lanza InvalidArgumentException si:
     * - la clave no está en DEFAULTS
     * - el valor no pasa el cast del tipo
     */
    public static function set(string $clave, mixed $valor): void
    {
        $meta = self::DEFAULTS[$clave] ?? null;
        if (! $meta) throw new \InvalidArgumentException("Regla desconocida: {$clave}");

        [$ok, $val, $err] = self::castear($clave, $valor);
        if (! $ok) throw new \InvalidArgumentException("Regla {$clave}: {$err}");

        ReglaNegocio::updateOrCreate(
            ['clave' => $clave],
            [
                'grupo' => $meta['grupo'],
                'tipo' => $meta['tipo'],
                'etiqueta' => $meta['etiqueta'],
                'descripcion' => $meta['descripcion'] ?? '',
                'valor' => $val,
            ]
        );
        self::limpiarRequestCache();
    }

    /** Siembra en BD los defaults que aún no existen. Idempotente + concurrent-safe (firstOrCreate). */
    public static function seedDefaults(): int
    {
        // Los textos de la UI se refrescan desde el diccionario en cada pasada.
        //
        // Antes, una clave que ya existía se saltaba entera: si acá se corregía
        // una etiqueta equivocada, la pantalla seguía mostrando la vieja para
        // siempre. Pasó con «Tipo documento NC compra», que en realidad es el
        // comprobante contable de la devolución a proveedor. Se sincronizan
        // sólo grupo, etiqueta y descripción —lo que describe la regla—; el
        // `valor` y el `tipo` no se tocan nunca, porque ese dato es del usuario
        // y cambiarle el tipo invalidaría lo que tenga guardado.
        foreach (ReglaNegocio::all() as $fila) {
            $meta = self::DEFAULTS[$fila->clave] ?? null;
            if (! $meta) continue;
            $fila->fill([
                'grupo' => $meta['grupo'],
                'etiqueta' => $meta['etiqueta'],
                'descripcion' => $meta['descripcion'] ?? '',
            ]);
            if ($fila->isDirty()) $fila->save();
        }

        $existentes = ReglaNegocio::pluck('clave')->flip(); // 1 query
        $creadas = 0;
        foreach (self::DEFAULTS as $clave => $meta) {
            if ($existentes->has($clave)) continue;
            // firstOrCreate para blindar race entre requests concurrentes.
            $r = ReglaNegocio::firstOrCreate(
                ['clave' => $clave],
                [
                    'grupo' => $meta['grupo'],
                    'tipo' => $meta['tipo'],
                    'etiqueta' => $meta['etiqueta'],
                    'descripcion' => $meta['descripcion'] ?? '',
                    'valor' => (string) $meta['valor'],
                ]
            );
            if ($r->wasRecentlyCreated) $creadas++;
        }
        return $creadas;
    }

    /** Devuelve todas las reglas agrupadas para la UI de admin. */
    public static function porGrupo(): array
    {
        $rows = ReglaNegocio::orderBy('grupo')->orderBy('clave')->get();
        return $rows->groupBy('grupo')->map(fn ($g) => $g->map(fn ($r) => [
            'id' => $r->id,
            'clave' => $r->clave,
            'tipo' => $r->tipo,
            'valor' => $r->valorCasteado(),
            'etiqueta' => $r->etiqueta,
            'descripcion' => $r->descripcion,
        ])->values()->all())->all();
    }
}
