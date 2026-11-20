# Merge `qa/cableado-permisos-y-asientos-nomina` → `main` · pendientes para los devs

**Fecha:** 2026-10-28
**Estado:** merge resuelto en rama local `integra/qa-nomina` (`266027c`). **NO se hizo push.** `main` sigue en `0c8106f`.
**Respaldo:** tag `pre-merge-qa-nomina` = `0c8106f`.

## Qué se mergeó
- **Rama QA:** `origin/qa/cableado-permisos-y-asientos-nomina` (1 commit: `a7eee99` — "las casillas de roles gobiernan, y la nómina entra al libro").
- **Punto de corte:** `95cb299` → la rama salió **antes** del PR #3 (`0c8106f`, Dropi/Marketing/multiempresa).
- Trae: cableado de permisos por rol, **nómina al libro contable**, sistema central de **Reglas contables** (`app/Support/Reglas.php`), y refactor de **Cartera/Contabilidad de Filament → Vue/Inertia**.

## Resultado de la suite completa sobre el merge
**1072 pasan / 8 fallan.**

- **4 × `SincronizarPedidosDropiTest`** → fixtures locales faltantes (`storage/app/dropi-fixtures/pedidos.json`). **Preexistente/entorno, NO es del merge.**
- **4 reales del choque PR #3 ↔ QA** (detalle abajo).

---

## Pendientes a reconciliar (los 4 fallos reales)

### A) `ReglasSinDefaultContradictorioTest` — 2 fallos · **arreglo trivial y seguro**

**A.1 — "toda clave que el código consulta está registrada en Reglas::DEFAULTS"**
- Clave sin registrar: **`dropi.empresa`**
- Dónde: `app/Modules/Dropi/Actions/FacturarPedidoDropi.php:109`
  `setting('dropi.empresa', 'ecommerce')`
- **Fix:** registrar la clave en `app/Support/Reglas.php` (array `DEFAULTS`), p.ej.:
  ```php
  'dropi.empresa' => ['grupo' => 'dropi', 'tipo' => 'string', 'valor' => 'ecommerce',
      'etiqueta' => 'Razón social que factura lo de Dropi', 'descripcion' => 'Código de EmpresaConfig (canal e-commerce).'],
  ```

**A.2 — "ninguna regla CONTABLE lleva default escrito en la llamada"**
- La regla de QA prohíbe pasar un default literal a `setting('contable.*')` (si la contadora cambia la cuenta en pantalla, el literal gana en cualquier base sin fila sembrada → el asiento va a la cuenta vieja).
- Infractores (código Dropi del PR #3): `app/Modules/Dropi/Services/SalidaInventarioDropi.php:343-344`
  ```php
  $ctaCosto = $producto?->cta('costo')       ?? setting('contable.cta_costo_default', '6135');   // ← quitar '6135'
  $ctaInv   = $producto?->cta('inventario')  ?? setting('contable.cta_inventario_default', '1435'); // ← quitar '1435'
  ```
- **Fix:** quitar el 2º argumento (las claves ya están en `Reglas::DEFAULTS` con esos mismos valores 6135/1435, así que funcionalmente no cambia):
  `setting('contable.cta_costo_default')` y `setting('contable.cta_inventario_default')`.

### B) `FacturacionDropiTest` — 2 fallos · **requiere decisión contable (Silvia)**

- Tests: "al despachar: remisión 1:1 y factura a consumidor final…" y "el pago de Dropi cierra la factura con un solo crédito al 1305…".
- Síntoma: el test espera crédito **1305** = 119.000 y débito **111015** = 119.000; tras el merge da **0** en esas cuentas.
- Causa: el recibo de caja del pago Dropi ahora se asienta con la lógica de QA en `RegistrarAsientoContable` (cuentas `contable.cta_caja_default=11050501`, `contable.cta_bancos_default=111010`), que **no coincide** con las cuentas que esperaba el flujo del PR #3 (**111015**). No es un bug de código suelto: es **qué cuenta PUC debe debitar el recibo de Dropi**.
- **A decidir:** ¿el recibo de Dropi usa la cuenta de bancos general de QA, una cuenta propia (111015 del PR #3), o la wallet? Alinear `FacturacionDropiTest` / el asiento con lo que confirme Silvia, entre Samuel (Dropi) y el dev de QA.

---

## Conflictos ya resueltos en el merge (9 fuentes + assets)

Resueltos combinando **ambas** intenciones (no se descartó nada):

| Archivo | Resolución |
|---|---|
| `app/Auth/Permisos.php` | Unión de permisos: `marketing_material` (PR#3) + `rrhh_novedades`/`rrhh_nomina`/`rrhh_nomina_aprobar` (QA) |
| `app/Modules/Cartera/Actions/RegistrarAsientoContable.php` | Cuentas configurables de QA (`setting(...)`) **+** caso `wallet_dropi` del PR#3 en el `match` |
| `app/Http/Controllers/App/PedidosB2BController.php` | Método `cambiarEmpresa()` (PR#3) **+** firma `aprobar(Request $r, int $pedido)` (QA) |
| `app/Http/Controllers/App/CrmController.php` | Claves `fuentes` (PR#3) **+** `puede_segmentar` (QA) |
| `app/Http/Controllers/App/InventarioGestionController.php` | 2 comentarios `referencia_tipo` de QA |
| `app/Modules/Compras/Actions/RegistrarDevolucionProveedor.php` | Comentario `referencia_tipo` de QA |
| `resources/js/Layouts/AppLayout.vue` | Iconos: `LayoutGrid, Images` (PR#3) + `ClipboardList` (QA) |
| `resources/js/Pages/PedidosB2B/Show.vue` | Props `empresas` (PR#3) + `puedo` (QA) |
| `tests/Feature/MenuCoincideConAccesoTest.php` | Rutas marketing (PR#3) + rutas rrhh (QA) |
| `public/build/**` | Regenerado con `npm run build` (vite) |

> ⚠️ La resolución de `RegistrarAsientoContable.php` es la más sensible y está relacionada con el pendiente **B**: validar con Silvia que el `match` de `$cuentaCaja` + el caso `wallet_dropi` quedan correctos para el recibo de Dropi.

## Cómo retomar
- El merge resuelto está en `integra/qa-nomina` (local, `266027c`). Se puede empujar a un remoto para que los devs continúen desde ahí, o descartar y rehacerlo tras arreglar A y B en sus ramas.
- Rollback total: `main` ya está en `0c8106f` (= `pre-merge-qa-nomina`).
