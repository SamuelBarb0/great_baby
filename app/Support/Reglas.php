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
        'empaque.gps_obligatorio' => ['grupo' => 'empaque', 'tipo' => 'bool', 'valor' => '0', 'etiqueta' => 'Ubicación (GPS) obligatoria para empacar y despachar Dropi', 'descripcion' => 'Si el navegador no entrega la ubicación, no deja confirmar. Apagada: se registra si la hay y se anota por qué no'],
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
        'dropi.facturacion_automatica' => ['grupo' => 'dropi', 'tipo' => 'bool', 'valor' => '0', 'etiqueta' => 'Facturar cada pedido Dropi al despacharlo', 'descripcion' => 'Remisión 1:1 + factura a consumidor final por el valor de proveedor, y a SIIGO si está activo. Apagada: no se factura nada'],
        'dropi.facturar_desde' => ['grupo' => 'dropi', 'tipo' => 'string', 'valor' => '', 'etiqueta' => 'Facturar pedidos Dropi despachados desde (AAAA-MM-DD)', 'descripcion' => 'Corte para no facturar la historia al prender la facturación automática'],
        'dropi.nit_tercero' => ['grupo' => 'dropi', 'tipo' => 'string', 'valor' => '', 'etiqueta' => 'NIT de Dropi (tercero de comisiones y sanciones)', 'descripcion' => 'Los gastos que cobra Dropi (fletes de garantía, tarjeta, sanciones) se asientan con este tercero'],
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
        'contable.cta_cxc_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '1305', 'etiqueta' => 'Cuenta CxC por defecto (Clientes)', 'descripcion' => 'PUC Great Baby · 1305 Clientes (cuenta real en uso por Dropi/ventas). Confirmar subcuenta movible con Silvia si el plan la exige.'],
        'dropi.empresa' => ['grupo' => 'dropi', 'tipo' => 'string', 'valor' => 'ecommerce', 'etiqueta' => 'Razón social que factura lo de Dropi', 'descripcion' => 'Código de EmpresaConfig (canal e-commerce) con el que se factura lo de Dropi.'],
        'contable.cta_ingreso_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '4135', 'etiqueta' => 'Cuenta ingreso por defecto', 'descripcion' => '⚠ Confirmar subcuenta específica con Silvia (4135xx según categoría)'],
        'contable.cta_iva_venta_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '240805', 'etiqueta' => 'Cuenta IVA venta por defecto', 'descripcion' => 'PUC Great Baby · 240805 IVA generado en ventas'],
        'contable.cta_iva_compra_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '240810', 'etiqueta' => 'Cuenta IVA compra descontable', 'descripcion' => 'PUC Great Baby · 240810 IVA descontable por compras'],
        'contable.cta_costo_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '6135', 'etiqueta' => 'Cuenta costo por defecto', 'descripcion' => '⚠ Confirmar subcuenta específica con Silvia (6135xx según categoría)'],
        'contable.cta_inventario_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '1435', 'etiqueta' => 'Cuenta inventario por defecto', 'descripcion' => '⚠ Confirmar subcuenta específica con Silvia (1435xx según categoría)'],
        'contable.cta_devolucion_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '4175', 'etiqueta' => 'Cuenta devolución en ventas por defecto', 'descripcion' => '⚠ Confirmar subcuenta específica con Silvia'],
        'contable.cta_descuento_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '5305', 'etiqueta' => 'Cuenta descuento comercial por defecto', 'descripcion' => '⚠ Confirmar subcuenta específica con Silvia'],
        /*
         * SEGREGACION DE FUNCIONES · quien crea no aprueba.
         *
         * Las dos guardas existian en el codigo con su mensaje de 403 y su
         * comentario diciendo que el default era `false`... pero el codigo
         * ponia `true`, asi que `! $permitirAuto` era SIEMPRE falso y el
         * control nunca se disparaba. Y como las claves no estaban registradas
         * aca, `Reglas::set()` las rechazaba con «Regla desconocida»: **no habia
         * forma de arreglarlo desde la interfaz.**
         *
         * Ahora el default es `false` (control ACTIVO, como decia la intencion)
         * y estan registradas, asi que si manana le estorba a alguien se apaga
         * desde Reglas de negocio sin tocar codigo.
         *
         * Aracely, Gerencia y Gerente quedan exentos siempre: el control es
         * para que un Contador o un AdminBodega no se apruebe lo suyo.
         */
        // Lo habitual con China: el plazo corre desde que zarpa el buque.
        'compras.credito_dias_zarpe' => ['grupo' => 'contable', 'tipo' => 'int', 'valor' => 90, 'etiqueta' => 'Días de crédito con China desde el zarpe', 'descripcion' => 'Plazo con que nace el plan de pagos de una orden de importación.'],
        'compras.interes_tramos' => ['grupo' => 'contable', 'tipo' => 'json', 'valor' => '[]', 'etiqueta' => 'Tramos de interés por mora con China', 'descripcion' => 'Ejemplo: [{"desde":1,"hasta":30,"pct":1.5}]. Vacío = no se estima interés.'],
        // Sin ella Araceli recibe el aviso, pero el traslado no se puede crear.
        'compras.ubicacion_ecommerce' => ['grupo' => 'contable', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'Ubicación de e-commerce para las importaciones', 'descripcion' => 'Id de la ubicación a la que se traslada lo que llega marcado para e-commerce. 0 = sin configurar.'],
        'compras.permitir_auto_aprobar' => ['grupo' => 'contable', 'tipo' => 'bool', 'valor' => false, 'etiqueta' => 'Permitir que quien crea una OC la apruebe', 'descripcion' => 'NO (recomendado) exige que la apruebe otra persona. Gerencia siempre puede.'],
        'contabilidad.permitir_auto_aprobar_asiento' => ['grupo' => 'contable', 'tipo' => 'bool', 'valor' => false, 'etiqueta' => 'Permitir que quien crea un asiento manual lo apruebe', 'descripcion' => 'NO (recomendado). El asiento aprobado viaja a SIIGO, que es la contabilidad oficial.'],

        // Contra que cuenta se debita un gasto operativo cuando el contador no
        // le puso una. 5195 «Diversos» es el cajon de sastre del PUC: sirve
        // para que el gasto ENTRE al libro, y despues se reclasifica. Antes de
        // esto el gasto no entraba a ningun lado.
        'contable.cta_gasto_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '5195', 'etiqueta' => 'Cuenta de gasto por defecto', 'descripcion' => 'PUC Great Baby · 5195 Diversos. Se usa cuando el gasto no trae cuenta propia.'],
        // La contrapartida de un gasto causado y todavia no pagado.
        /*
         * Las cuentas de la nómina.
         *
         * La nómina y la liquidación definitiva NO generaban ni un movimiento
         * contable: se liquidaba, gerencia aprobaba, tesorería pagaba, y el
         * libro no se enteraba nunca. El gasto de personal —que en esta
         * empresa es de los más grandes— no existía en el balance ni en el
         * estado de resultados.
         *
         * Los códigos son de OCHO dígitos a propósito. El plan de cuentas
         * marca las de seis como agrupadoras (`permite_movimiento = 0`), y
         * asentar contra ellas es el defecto que ya tiene el resto del libro:
         * 104 de 104 movimientos contra cuentas que no admiten movimiento.
         * Acá se arranca bien.
         *
         * Son configurables porque el plan lo maneja la contadora, no el
         * código: si mañana reclasifica una subcuenta, se cambia en Reglas y
         * la siguiente nómina ya sale con la nueva.
         */
        'contable.cta_nomina_sueldos' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51050601', 'etiqueta' => 'Nómina · sueldos', 'descripcion' => 'PUC 51050601 Sueldos. Se debita el salario del período, el adicional y, en crédito, lo que no se causó por ausentismo.'],
        'contable.cta_nomina_extras' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51051501', 'etiqueta' => 'Nómina · horas extras', 'descripcion' => 'PUC 51051501 Horas extras y recargos.'],
        'contable.cta_nomina_auxilio' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51052701', 'etiqueta' => 'Nómina · auxilio de transporte', 'descripcion' => 'PUC 51052701 Auxilio de transporte. Va aparte de los sueldos porque no es salario y no constituye base prestacional.'],
        'contable.cta_nomina_gasto_salud' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51056901', 'etiqueta' => 'Nómina · gasto aporte EPS', 'descripcion' => 'PUC 51056901 Aportes a EPS. Es lo que PAGA LA EMPRESA, no lo que se le descuenta al empleado.'],
        'contable.cta_nomina_gasto_pension' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51057001', 'etiqueta' => 'Nómina · gasto aporte pensión', 'descripcion' => 'PUC 51057001 Aporte a fondos de pensión. Es la parte patronal.'],
        'contable.cta_nomina_gasto_arl' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51056801', 'etiqueta' => 'Nómina · gasto ARL', 'descripcion' => 'PUC 51056801 Aportes a ARL. La ARL la paga entera la empresa: al empleado no se le descuenta nada.'],
        'contable.cta_nomina_gasto_caja' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51057201', 'etiqueta' => 'Nómina · gasto caja de compensación', 'descripcion' => 'PUC 51057201 Aportes cajas de compensación familiar.'],
        'contable.cta_nomina_gasto_icbf' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51057501', 'etiqueta' => 'Nómina · gasto ICBF', 'descripcion' => 'PUC 51057501 Aportes ICBF.'],
        'contable.cta_nomina_gasto_sena' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51057801', 'etiqueta' => 'Nómina · gasto SENA', 'descripcion' => 'PUC 51057801 Aportes SENA.'],
        'contable.cta_nomina_gasto_cesantias' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51053001', 'etiqueta' => 'Nómina · gasto cesantías', 'descripcion' => 'PUC 51053001 Cesantías. Es la provisión del mes, no un pago.'],
        'contable.cta_nomina_gasto_intereses' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51053301', 'etiqueta' => 'Nómina · gasto intereses de cesantías', 'descripcion' => 'PUC 51053301 Intereses sobre cesantías.'],
        'contable.cta_nomina_gasto_prima' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51053601', 'etiqueta' => 'Nómina · gasto prima', 'descripcion' => 'PUC 51053601 Prima de servicios.'],
        'contable.cta_nomina_gasto_vacaciones' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51053901', 'etiqueta' => 'Nómina · gasto vacaciones', 'descripcion' => 'PUC 51053901 Vacaciones.'],
        'contable.cta_nomina_salarios_por_pagar' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '25050501', 'etiqueta' => 'Nómina · salarios por pagar', 'descripcion' => 'PUC 25050501 Salarios por pagar. Acá queda el NETO mientras no se consigne; al pagar se cancela contra bancos.'],
        'contable.cta_nomina_por_pagar_salud' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '23700501', 'etiqueta' => 'Nómina · EPS por pagar', 'descripcion' => 'PUC 23700501 Aportes a EPS. Junta lo descontado al empleado y lo que pone la empresa: a la EPS se le gira todo junto.'],
        'contable.cta_nomina_por_pagar_pension' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '23803001', 'etiqueta' => 'Nómina · pensión por pagar', 'descripcion' => 'PUC 23803001 Fondos de cesantías y/o pensiones. Igual que salud: lo del empleado y lo de la empresa van juntos.'],
        'contable.cta_nomina_por_pagar_arl' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '23701501', 'etiqueta' => 'Nómina · ARL por pagar', 'descripcion' => 'PUC 23701501 Aportes ARL.'],
        'contable.cta_nomina_por_pagar_parafiscales' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '23701001', 'etiqueta' => 'Nómina · parafiscales por pagar', 'descripcion' => 'PUC 23701001 Aportes al ICBF, SENA y cajas de compensación. Los tres van a la misma cuenta porque se giran en la misma planilla.'],
        'contable.cta_nomina_provision_cesantias' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '25101001', 'etiqueta' => 'Nómina · cesantías consolidadas', 'descripcion' => 'PUC 25101001 Cesantías. Es el pasivo que se acumula mes a mes.'],
        'contable.cta_nomina_provision_intereses' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '25150101', 'etiqueta' => 'Nómina · intereses de cesantías consolidados', 'descripcion' => 'PUC 25150101 Intereses sobre cesantías.'],
        'contable.cta_nomina_provision_prima' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '25200101', 'etiqueta' => 'Nómina · prima consolidada', 'descripcion' => 'PUC 25200101 Prima de servicios.'],
        'contable.cta_nomina_provision_vacaciones' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '25250101', 'etiqueta' => 'Nómina · vacaciones consolidadas', 'descripcion' => 'PUC 25250101 Vacaciones.'],
        'contable.cta_liquidacion_indemnizacion' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '51056001', 'etiqueta' => 'Liquidación · gasto indemnización', 'descripcion' => 'PUC 51056001 Indemnizaciones laborales. Es gasto del período en que se despide: no sale de una provisión, porque nadie provisiona despidos.'],
        'contable.cta_liquidacion_descuentos' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '13651501', 'etiqueta' => 'Liquidación · otros descuentos', 'descripcion' => '⚠ CONFIRMAR CON LA CONTADORA. Es la contrapartida de lo que se le descuenta a alguien en su liquidación (un préstamo pendiente, una dotación no devuelta). El plan de esta empresa sólo trae 1365 con dos hijas —Educación y Calamidad doméstica— y ninguna describe el caso general, así que el valor de acá es provisional. Afecta sólo a las liquidaciones que traen descuentos.'],
        'contable.cta_liquidacion_cesantias_consignadas' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '23803001', 'etiqueta' => 'Liquidación · cesantías ya consignadas al fondo', 'descripcion' => 'PUC 23803001 Fondos de cesantías y/o pensiones. Es la parte de las cesantías que la empresa ya giró al fondo: el trabajador las cobra allá, no acá. Por eso en la liquidación se descuentan del neto y en el libro se acreditan a esta cuenta en vez de salir del banco.'],
        'contable.cta_gastos_por_pagar' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '2335', 'etiqueta' => 'Cuenta de gastos por pagar', 'descripcion' => 'PUC Great Baby · 2335 Costos y gastos por pagar'],
        'contable.cta_bancos_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '111010', 'etiqueta' => 'Cuenta bancos por defecto', 'descripcion' => 'PUC 111010 Moneda nacional · Davivienda. Era 112005, que el plan marca como AGRUPADORA y no admite movimiento: asentar ahí es lo que tiene hoy al libro entero contra cuentas que no se pueden mover. Si se abre otro banco, acá se cambia.'],
        'contable.cta_caja_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '11050501', 'etiqueta' => 'Cuenta caja general', 'descripcion' => 'PUC 11050501 Caja general. Era 110505, que es la agrupadora del mismo nombre y no admite movimiento.'],
        'contable.cta_proveedores_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '220505', 'etiqueta' => 'Cuenta proveedores nacionales', 'descripcion' => 'PUC Great Baby · 220505 Proveedores nacionales'],
        // Faltaba registrarla: el código la pedía con `setting()` pero la clave
        // no existía, así que devolvía siempre el literal del código y no había
        // forma de cambiarla desde la pantalla de Reglas.
        'contable.cta_proveedores_exterior' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '2210', 'etiqueta' => 'Cuenta proveedores del exterior', 'descripcion' => 'Se usa al pagar una IMPORTACIÓN, en vez de 220505. ⚠ Confirmar la subcuenta con Silvia: en el plan de cuentas 2210 es cuenta de nivel 3 y no admite movimiento.'],
        'contable.cta_retefuente_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '236575', 'etiqueta' => 'Cuenta autorretenciones por defecto', 'descripcion' => '⚠ Confirmar concepto específico con Silvia (2365xx según naturaleza)'],
        // Retenciones que el CLIENTE nos practica al pagar una venta: son saldo a
        // favor (1355), no por pagar (2365, que es lo de compras).
        'contable.cta_retefuente_venta' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '135515', 'etiqueta' => 'Retefuente que nos practican (ventas)', 'descripcion' => 'PUC · 135515 Retención en la fuente. ⚠ Confirmar con Silvia'],
        'contable.cta_reteiva_venta' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '135517', 'etiqueta' => 'ReteIVA que nos practican (ventas)', 'descripcion' => 'PUC · 135517 Impuesto a las ventas retenido. ⚠ Confirmar con Silvia'],
        'contable.cta_reteica_venta' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => '135518', 'etiqueta' => 'ReteICA que nos practican (ventas)', 'descripcion' => 'PUC · 135518 Impuesto de industria y comercio retenido. ⚠ Confirmar con Silvia'],
        'contable.concepto_retencion_venta' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => 'compras_generales', 'etiqueta' => 'Concepto de retención en ventas', 'descripcion' => 'Regla de Cartera → Retenciones que se usa al facturar: el cliente nos compra mercancía, así que para él es «compras generales».'],
        'contable.centro_costo_default' => ['grupo' => 'contable', 'tipo' => 'string', 'valor' => 'GB-01', 'etiqueta' => 'Centro de costo por defecto', 'descripcion' => 'Cuenta / centro de imputación por defecto para asientos'],

        /*
         * ============ Gestión Humana · cuadro de novedades ============
         *
         * Los dos valores con los que se calcula la columna «DTOS» del cuadro
         * que se manda al contador. Son cifras que cambian cada enero por
         * decreto, así que viven acá y no en el código: el día que suban, se
         * corrigen en la pantalla de reglas sin tocar nada más.
         *
         * Importante: lo que ya se descontó NO se recalcula al cambiarlas. Un
         * corte cerrado se queda como se cerró, porque la nómina de ese mes ya
         * salió con ese número.
         */
        'rrhh.salario_minimo' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '1750905', 'etiqueta' => 'Salario mínimo mensual (SMMLV)', 'descripcion' => 'Vigente 2026: $1.750.905 (Decreto 1469 de 2025, ratificado por el Decreto 159 del 19-feb-2026). Cambia cada enero — actualizarlo acá. De este valor sale el tope de 2 SMMLV para el auxilio de transporte ($3.501.810).'],
        'rrhh.auxilio_transporte' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '249095', 'etiqueta' => 'Auxilio de transporte mensual', 'descripcion' => 'Vigente 2026: $249.095 (Decreto 1470 de 2025). Cambia cada enero. Lo devenga quien gana hasta 2 SMMLV Y se transporta al trabajo: a quien vive en el sitio o tiene ruta de la empresa se le excluye desde su ficha, no desde acá.'],
        'rrhh.dias_mes' => ['grupo' => 'rrhh', 'tipo' => 'int', 'valor' => 30, 'etiqueta' => 'Días del mes para liquidar', 'descripcion' => 'El mes comercial de 30 días con el que se prorratea salario y auxilio. Cambiarlo altera TODO descuento que se calcule de aquí en adelante.'],

        /*
         * ==================== Gestión Humana · nómina =======================
         *
         * Los porcentajes y recargos con los que se liquida. Van acá y no en
         * el código por una razón concreta: cambian por ley, normalmente en
         * enero, y una nómina que haya que esperar a un programador para
         * salir con el porcentaje nuevo es una nómina que sale tarde.
         *
         * Los valores por defecto son los vigentes y la fuente está en la
         * descripción de cada uno, para que quien los cambie sepa contra qué
         * está comparando.
         */
        'rrhh.aporte_salud_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '4', 'etiqueta' => 'Aporte a salud del trabajador (%)', 'descripcion' => 'Lo que se le descuenta al colaborador. Ley 100 de 1993: 4% del ingreso base de cotización. El 8,5% restante lo paga la empresa y no sale en este descuento.'],
        'rrhh.aporte_pension_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '4', 'etiqueta' => 'Aporte a pensión del trabajador (%)', 'descripcion' => 'Lo que se le descuenta al colaborador. Ley 100 de 1993: 4% del ingreso base de cotización. El 12% restante lo paga la empresa.'],

        /*
         * Los cuatro recargos de hora extra. El ERP no tiene marcación: las
         * horas entran digitadas en el cuadro de novedades, con su clase
         * (ordinaria o dominical) y si fue nocturna.
         *
         * El valor de la hora ordinaria sale de `dias_mes` × 8 — con el mes
         * comercial de 30 son las 240 horas de siempre.
         */
        'rrhh.recargo_extra_diurna' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '1.25', 'etiqueta' => 'Factor hora extra diurna', 'descripcion' => 'Código Sustantivo del Trabajo art. 168: la hora extra diurna se paga con un recargo del 25%, o sea 1,25 veces la hora ordinaria.'],
        'rrhh.recargo_extra_nocturna' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '1.75', 'etiqueta' => 'Factor hora extra nocturna', 'descripcion' => 'Recargo del 75%: 1,75 veces la hora ordinaria.'],
        'rrhh.recargo_extra_dominical' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '2', 'etiqueta' => 'Factor hora extra dominical o festiva', 'descripcion' => 'Recargo dominical del 75% más el 25% de la extra: 2 veces la hora ordinaria.'],
        'rrhh.recargo_extra_dominical_nocturna' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '2.5', 'etiqueta' => 'Factor hora extra dominical nocturna', 'descripcion' => 'Dominical y nocturna a la vez: 2,5 veces la hora ordinaria.'],

        /*
         * ============ Gestión Humana · lo que paga la empresa ===============
         *
         * Encima del sueldo, la empresa pone seguridad social, parafiscales y
         * las provisiones de prestaciones. Es lo que convierte «lo que se
         * consigna» en «lo que cuesta la nómina», y suele ser ~1,5 veces el
         * salario.
         *
         * La EXONERACIÓN es la regla que más plata mueve: una empresa que
         * declara renta no paga salud 8,5%, SENA 2% ni ICBF 3% por los
         * trabajadores que ganan menos de 10 salarios mínimos (Ley 1607/2012
         * art. 25, modificada por la Ley 1819/2016 art. 65). Darla por
         * sentada en un sentido o en el otro infla o desinfla el costo en
         * 13,5 puntos, así que se decide acá y no en el código.
         */
        'rrhh.empresa_exonerada_parafiscales' => ['grupo' => 'rrhh', 'tipo' => 'bool', 'valor' => '1', 'etiqueta' => '¿La empresa está exonerada de salud, SENA e ICBF?', 'descripcion' => 'Sí para la empresa que declara renta, por los trabajadores que ganan menos de 10 SMMLV (Ley 1607/2012 art. 25). Confirmar con el contador: cambia el costo de la nómina en 13,5 puntos.'],
        'rrhh.patronal_salud_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '8.5', 'etiqueta' => 'Salud a cargo de la empresa (%)', 'descripcion' => 'El 8,5% que completa el 12,5% total. No se paga si la empresa está exonerada y el trabajador gana menos de 10 SMMLV.'],
        'rrhh.patronal_pension_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '12', 'etiqueta' => 'Pensión a cargo de la empresa (%)', 'descripcion' => 'El 12% que completa el 16% total. NO tiene exoneración: se paga siempre.'],
        'rrhh.patronal_caja_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '4', 'etiqueta' => 'Caja de compensación (%)', 'descripcion' => 'Se paga SIEMPRE, con exoneración o sin ella. Es el parafiscal que no se exonera.'],
        'rrhh.patronal_sena_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '2', 'etiqueta' => 'SENA (%)', 'descripcion' => 'Exonerado bajo las mismas condiciones que la salud patronal.'],
        'rrhh.patronal_icbf_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '3', 'etiqueta' => 'ICBF (%)', 'descripcion' => 'Exonerado bajo las mismas condiciones que la salud patronal.'],

        /*
         * ARL por nivel de riesgo (Decreto 1072 de 2015). Lo paga la empresa
         * entero y depende del CARGO, no del sueldo: el nivel vive en la
         * ficha de cada persona.
         */
        'rrhh.arl_i_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '0.522', 'etiqueta' => 'ARL riesgo I (%)', 'descripcion' => 'Riesgo mínimo: oficina, labores administrativas.'],
        'rrhh.arl_ii_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '1.044', 'etiqueta' => 'ARL riesgo II (%)', 'descripcion' => 'Riesgo bajo.'],
        'rrhh.arl_iii_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '2.436', 'etiqueta' => 'ARL riesgo III (%)', 'descripcion' => 'Riesgo medio: bodega, manipulación de carga.'],
        'rrhh.arl_iv_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '4.35', 'etiqueta' => 'ARL riesgo IV (%)', 'descripcion' => 'Riesgo alto.'],
        'rrhh.arl_v_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '6.96', 'etiqueta' => 'ARL riesgo V (%)', 'descripcion' => 'Riesgo máximo.'],

        /*
         * Provisiones de prestaciones. No se pagan este mes: se guardan,
         * porque la prima llega en junio y las cesantías en febrero. Una
         * empresa que no las provisiona se encuentra el hueco de golpe.
         *
         * Las vacaciones se provisionan sobre el salario SIN auxilio, igual
         * que se liquidan.
         */
        'rrhh.provision_cesantias_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '8.33', 'etiqueta' => 'Provisión de cesantías (%)', 'descripcion' => 'Un mes de salario por año: 1 ÷ 12 = 8,33%. Sobre salario más auxilio.'],
        'rrhh.provision_intereses_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '1', 'etiqueta' => 'Provisión de intereses sobre cesantías (%)', 'descripcion' => '12% anual sobre las cesantías: 1% mensual del salario base.'],
        'rrhh.provision_prima_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '8.33', 'etiqueta' => 'Provisión de prima (%)', 'descripcion' => 'Un mes de salario por año. Sobre salario más auxilio.'],
        'rrhh.provision_vacaciones_pct' => ['grupo' => 'rrhh', 'tipo' => 'float', 'valor' => '4.17', 'etiqueta' => 'Provisión de vacaciones (%)', 'descripcion' => '15 días hábiles por año = medio mes: 4,17%. Sobre el salario SIN auxilio, igual que se liquidan.'],

        /*
         * ============ Gestión Humana · examen médico ocupacional ============
         *
         * La IPS a la que se manda a la gente, tal como va impresa en la carta
         * SST-F-001. Vive acá y no en el código porque es un proveedor: se
         * cambia de IPS y la carta tiene que salir con la nueva sin esperar a
         * un programador.
         */
        /*
         * ============== Gestión Humana · temario de inducción ===============
         *
         * La lista de temas que se le explican a quien entra. Vive acá y no
         * en el código porque la edita el área: agregar «manejo del escáner»
         * no debería esperar a un programador.
         *
         * ⚠ PROVISIONAL. El área todavía no nos pasó su formato de inducción
         * —está pedido en la solicitud de insumos—. Estos temas son un punto
         * de partida razonable, no los suyos. Cuando llegue el formato se
         * reemplaza esta lista; las inducciones ya dadas no cambian, porque
         * cada una guarda el temario con el que se dio.
         */
        'rrhh.temario_induccion' => ['grupo' => 'rrhh', 'tipo' => 'string', 'valor' => 'Quiénes somos: historia, misión y líneas de negocio|Reglamento interno de trabajo|Horario, marcación y manejo de permisos|Política de seguridad y salud en el trabajo (SST)|Riesgos del puesto y elementos de protección|Ruta de evacuación y qué hacer en una emergencia|Conocimiento del producto y del catálogo|Sistemas: acceso al ERP y a su espacio de colaborador|Manejo de la información y datos personales (Ley 1581)|Canal para reportar novedades y a quién acudir', 'etiqueta' => 'Temario de la inducción', 'descripcion' => '⚠ Provisional: faltan los temas del formato del área. Separados por barra vertical ( | ). Cada inducción guarda el temario con el que se dio, así que cambiar esta lista no altera las que ya se hicieron.'],

        'rrhh.ips_emo_nombre' => ['grupo' => 'rrhh', 'tipo' => 'string', 'valor' => 'IPS ELOGIOS', 'etiqueta' => 'IPS del examen médico ocupacional', 'descripcion' => 'A quién va dirigida la carta SST-F-001.'],
        'rrhh.ips_emo_direccion' => ['grupo' => 'rrhh', 'tipo' => 'string', 'valor' => 'Carrera 36 # 49-45, Barrio Cabecera del Llano', 'etiqueta' => 'Dirección de la IPS', 'descripcion' => 'Dónde debe presentarse la persona.'],
        'rrhh.ips_emo_horario' => ['grupo' => 'rrhh', 'tipo' => 'string', 'valor' => 'de 7:00 a.m. a 12:00 m. y de 2:00 p.m. a 5:00 p.m.', 'etiqueta' => 'Horario de atención de la IPS', 'descripcion' => 'Se imprime tal cual en la carta.'],
        'rrhh.ips_emo_instrucciones' => ['grupo' => 'rrhh', 'tipo' => 'string', 'valor' => 'Contar con dos (2) horas de disponibilidad y presentarse con documento de identidad.', 'etiqueta' => 'Instrucciones para el colaborador', 'descripcion' => 'Lo que la persona tiene que saber antes de ir: tiempo, ayuno, qué llevar.'],

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
        /*
         * Como se compone la porcion declarada de una factura de REPARTO.
         *
         *   · «cantidad» — menos unidades al PRECIO REAL. El precio unitario
         *     queda igual al de cualquier otra factura del producto, que es lo
         *     que se compara si alguien cruza precios entre clientes. La
         *     cantidad sale fraccionada.
         *   · «precio» — las MISMAS unidades a precio menor. El conteo cuadra
         *     con lo despachado, pero el precio unitario queda falseado.
         *
         * Esta por defecto en «cantidad» porque un precio inventado es mas
         * dificil de sostener ante la DIAN que un conteo que no cuadra, y
         * porque el inventario de verdad vive en el ERP, no en SIIGO. Es una
         * decision contable: la cambia Silvia desde la pantalla de reglas.
         */
        'siigo.reparto_modo' => ['grupo' => 'siigo', 'tipo' => 'string', 'valor' => 'cantidad', 'etiqueta' => 'SIIGO · Como se declara un reparto', 'descripcion' => '«cantidad» = menos unidades al precio real (recomendado) · «precio» = mismas unidades a precio menor'],
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
        'siigo.doc_type_recibo' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 2371, 'etiqueta' => 'SIIGO · Tipo documento Recibo de caja (RC)', 'descripcion' => 'ID del comprobante con que se registran los COBROS a clientes. Se consulta con GET /v1/document-types?type=RC ⚠ El valor por defecto es el del SANDBOX. Los IDs de comprobante son POR CUENTA, así que al apuntar a la cuenta real de Great Baby hay que correr `php artisan siigo:sync-document-types` y volver a elegir en el desplegable de acá, que muestra los comprobantes reales y marca «no figura en SIIGO» si el guardado ya no existe.'],
        'siigo.payment_type_recibo' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Medio de pago del recibo de caja', 'descripcion' => 'Medio de pago para los cobros. Se consulta con GET /v1/payment-types?document_type=RC'],
        'siigo.doc_type_egreso' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 2376, 'etiqueta' => 'SIIGO · Tipo documento Egreso (RP)', 'descripcion' => 'ID del comprobante con que se registran los PAGOS a proveedores. Se consulta con GET /v1/document-types?type=RP ⚠ El valor por defecto es el del SANDBOX. Los IDs de comprobante son POR CUENTA, así que al apuntar a la cuenta real de Great Baby hay que correr `php artisan siigo:sync-document-types` y volver a elegir en el desplegable de acá, que muestra los comprobantes reales y marca «no figura en SIIGO» si el guardado ya no existe.'],
        'siigo.payment_type_egreso' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 0, 'etiqueta' => 'SIIGO · Medio de pago del egreso', 'descripcion' => 'Medio de pago para los pagos a proveedores. Si queda en 0 se usa el del recibo de caja'],
        'siigo.doc_type_asiento' => ['grupo' => 'siigo', 'tipo' => 'int', 'valor' => 2393, 'etiqueta' => 'SIIGO · Tipo documento Asiento contable (CC)', 'descripcion' => 'ID del comprobante contable para asientos manuales y de inventario. Se consulta con GET /v1/document-types?type=CC ⚠ El valor por defecto es el del SANDBOX. Los IDs de comprobante son POR CUENTA, así que al apuntar a la cuenta real de Great Baby hay que correr `php artisan siigo:sync-document-types` y volver a elegir en el desplegable de acá, que muestra los comprobantes reales y marca «no figura en SIIGO» si el guardado ya no existe.'],
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
