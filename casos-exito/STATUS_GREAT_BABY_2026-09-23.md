# 📊 STATUS — ERP GREAT BABY

**Fecha de corte:** 23-septiembre-2026
**Proyecto:** ERP a la medida v1.0 — GREAT BABY S.A.S.
**Proveedor:** MyTech Solutions (Michael Cárdenas)
**Repo:** `github.com/michcardenas/great_baby` · **Último commit:** `fb74952`

---

## 1. Resumen ejecutivo

El ERP está **en ejecución activa** y con módulos ya funcionando en producción. El foco actual de las últimas dos semanas fue **cerrar los tres frentes críticos del cliente**: Contabilidad (Silvia), la integración con SIIGO y el mini-ecosistema Dropi (Aracely). El desarrollo propio (lo que no depende del cliente) va al día; los avances que faltan están **bloqueados esperando insumos del cliente** (credenciales SIIGO y validación de la cuenta Dropi), no por desarrollo.

**Semáforo general:**

| Frente | Estado |
|---|---|
| Contabilidad (núcleo) | 🟢 Operativo, faltan complementos |
| Cartera / Crédito bodega | 🟢 Operativo (con gaps menores) |
| Dropi (operativo) | 🟢 Importador + Dashboard listos |
| Dropi (financiero/wallet) | 🔴 Bloqueado (cuenta Dropi sin validar) |
| Integración SIIGO | 🟡 Motor listo, espera credenciales |
| Inventario | 🟢 Importador + desglose dual |
| Separación canal Empresa/Dropi | 🟢 Listo |

---

## 2. Datos del proyecto

- **Cliente:** GREAT BABY S.A.S. — importadora/comercializadora mayorista de productos de bebé (Bogotá; ~400–500 pedidos/día; venta tienda a tienda + e-commerce Dropi + mayoristas; mucha venta a crédito).
- **Contrato:** v4 **FIRMADO** + anticipo Hito 1 pagado ($4.569.600). Opción B (100% MyTech).
- **Valor desarrollo:** $12.800.000 + IVA = $15.232.000 · **Hitos 30/30/40** (S1 anticipo ✓ / S3 = 60% / S5 = final).
- **Infraestructura:** administrada por MyTech, $535.500/mes IVA incl. (nube incluida).
- **Soporte:** bolsa de 10 h post-implementación.
- **Plan:** 5 semanas / 3 hitos, basado en el TO-BE integral (7 procesos + 10 áreas).

---

## 3. Arquitectura técnica

- **Backend:** Laravel 12 · **Admin:** Filament v5 · **Portal/páginas:** Vue 3 + Inertia.
- **BD:** MySQL/MariaDB.
- **Deploy sin Node:** `public/build` compilado viaja en el repo → en el servidor solo `git pull` + `composer install` + `migrate` + `optimize:clear`.
- **Local:** `http://127.0.0.1:8070/admin` (XAMPP MySQL, BD `great_baby`).
- **Producción:** `https://maroon-goldfinch-224564.hostingersite.com/app` (Hostinger, symlink `public_html → great_baby/public`).

---

## 4. Estado por módulo

### 4.1 Contabilidad (dominio de Silvia) — 🟢
- ✅ **Estado de Resultados (P&L)** y **Balance General** (calculan desde `movimientos_contables` por clase PUC, cuadran).
- ✅ **Libro Diario** con filtros inteligentes visibles + filtro por **Canal** (Empresa/Dropi).
- ✅ **Plan de cuentas (PUC) gestionable + importación** por Excel (plantilla incluida, ~49 cuentas base Decreto 2650, mapeo a SIIGO).
- ✅ **Gastos** y **Conciliación bancaria** en modal, con **subida de archivos** adjuntos.
- 🟠 Pendiente: retenciones automáticas al facturar; cierre contable por periodo; comisiones por vendedor + préstamos a empleados; exógena (posterior).
- ⚠️ Decisión pendiente: consolidar contabilidad que hoy vive en dos lados (Filament `/admin` de Silvia vs Vue `/app/contabilidad`).

### 4.2 Cartera / Crédito en bodega — 🟢
- ✅ Maestro de **Métodos de pago** gestionable (efectivo/transferencia/consignación/cruce de cuentas/NEC/tarjeta/Nequi/Daviplata) con comprobante requerido según método.
- ✅ **Gate de crédito conectado** al facturar B2B: valida cupo/mora, retiene y escala a Gerencia si hay factura vencida, marca factura contado/crédito.
- 🟠 Gaps menores: validar que el aprobador coincida con el nivel; el gate solo aplica en portal B2B (no en factura manual Filament).

### 4.3 Dropi (mini-ecosistema) — 🟢 operativo / 🔴 financiero
- ✅ **Importador de pedidos por Excel** (export "Órdenes una orden por fila", 63 cols → mapea estados, valor proveedor, datos DIAN).
- ✅ **Ítems por pedido** (export "Órdenes con Productos"): el mismo botón autodetecta el tipo de archivo.
- ✅ Import idempotente: distingue "con cambios" vs "sin cambios" y recalcula contadores de corte.
- ✅ **Dashboard Dropi con tablero de ventas integrado**: desempeño por transportadora, destinos por departamento, Top 10 productos, tabla de tiendas por ingreso a bodega + buscador general (guía/producto/tienda/cliente).
- ✅ Cortes, Torre de Control, Devoluciones y Estación de Empaque (pantallas base).
- ✅ Botones "Crear pedido" y "Sincronizar con Dropi" deshabilitados (todo entra por Excel hasta tener API/MCP).
- 🔴 **Bloqueado:** import de Wallet/recaudado/liquidaciones (la cuenta Dropi del usuario `contabilidad` sigue sin validar → "Recaudado" e "Integraciones/token" no accesibles).

### 4.4 Integración SIIGO — 🟡
- ✅ **Motor `SiigoExportService`** + matriz por tipo (compra→purchases; gasto→journals; importación→purchases borrador; conciliación→journals borrador) + **tercero (NIT) por línea** + settings configurables.
- ✅ Botón **"Enviar a SIIGO"** cableado en Órdenes de compra, Importaciones, Gastos y Conciliación.
- ✅ Separación por canal/razón social lista para conectar a cuentas y prefijos distintos (Great Baby / Arsutex / Dropi).
- 🔴 **Bloqueado:** credenciales SIIGO (ya adquirido; falta pago/credenciales). Requiere contacto técnico de SIIGO (Cristina/Creiss) para API keys.

### 4.5 Inventario — 🟢
- ✅ **Importador del Excel del cliente** (Aracely) sin SSH, con feedback UX.
- ✅ **Desglose dual de stock** (granular O agregado) + `producto_id` propagado a movimientos, reservas, alertas, traslados, tomas físicas, pedidos, facturas.

### 4.6 Separación de canal Empresa/Dropi — 🟢
- ✅ Interruptor global "Modo: Empresa/Dropi" en la barra superior; en modo Dropi la barra cambia a cian + badge, y los reportes filtran por canal.

---

## 5. Integraciones — estado y bloqueantes

| Integración | Estado | Bloqueante |
|---|---|---|
| **SIIGO** | Motor listo | Credenciales (Username/Access Key/Partner ID) + IDs de tipo de documento (los tiene Silvia). Contacto técnico SIIGO. |
| **Dropi** | Puente Excel operativo | Validación de la cuenta Dropi para abrir Wallet/Recaudado; token API/MCP no es self-service (solicitud por correo a TI Dropi). |
| **WhatsApp** | Mock en local | Credenciales Cloud API para producción. |

---

## 6. Pendientes del CLIENTE (desbloqueantes)

1. **SIIGO:** completar pago + entregar credenciales; definir prefijos por razón social con Silvia; IDs de tipo de documento.
2. **Plan de cuentas real** de Silvia (Excel) para importar sobre el base.
3. **Dropi:** validar la cuenta (abre Wallet/Recaudado) o compartir export de liquidaciones/wallet.
4. **Reglas de negocio a confirmar con Silvia/operación:** cómo factura Dropi (consumidor final / comisión + sanciones), conciliación bancaria, categorías de gasto, métodos de pago usados, flujo de pago/crédito en bodega, reglas de aseguradora para clientes nuevos.

---

## 7. Próximos pasos (MyTech)

- **Contactar al técnico de SIIGO** (Cristina / Creiss) para credenciales API y probar exportación real (`SiigoExportService` ya listo).
- Importar el plan de cuentas real de Silvia apenas lo envíe.
- Extender el filtro de canal a más pantallas de Cartera/Contabilidad.
- **Tintero Dropi** (etapa posterior, requiere reglas + datos): recibir devolución en bodega → reintegrar stock → Nota Crédito; empaque/despacho; conciliar wallet↔guía; facturar por valor de proveedor.
- Complementos de contabilidad: retenciones automáticas, cierre por periodo, comisiones/préstamos.

---

## 8. Riesgos

- 🔴 **Dependencia de terceros:** SIIGO (credenciales) y Dropi (validación de cuenta / API) condicionan los frentes financieros. Por contrato, el tiempo de terceros no cuenta en el plazo.
- 🟠 **Doble hogar de contabilidad** (Filament vs Vue) — decidir consolidación para evitar divergencia.
- 🟠 **Estabilidad de MySQL en local** (XAMPP) — no afecta producción.

---

## 9. Contactos

| Rol | Persona |
|---|---|
| Contacto cliente | **Danna García** |
| Aux. contable (Contabilidad + SIIGO doc types + plan de cuentas) | **Silvia** |
| Líder Dropi | **Aracely Ramírez** |
| Encargada SIIGO (cliente) | **Cristina** |
| Técnico integración SIIGO (API keys) | **Creiss** |
| Contadora | **Lahida** |
| Proveedor / Dev | **Michael Cárdenas** — MyTech Solutions |

---

## 10. Accesos

- **Producción:** `https://maroon-goldfinch-224564.hostingersite.com/app` — usuarios `@greatbaby.com`, clave `greatbaby2026` (minúsculas).
- **Local:** `http://127.0.0.1:8070/admin` — `admin@greatbaby.co`, clave `GreatBaby2026*`.

---

> _Documento generado como corte de estado para reporte. Ver bitácora completa en `casos-exito/ESTADO_PROYECTO_GREAT_BABY.md` y la memoria del proyecto._
