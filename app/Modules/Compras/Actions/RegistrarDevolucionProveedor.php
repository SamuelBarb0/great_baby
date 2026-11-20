<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Models\DevolucionProveedor;
use App\Modules\Compras\Models\DevolucionProveedorItem;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Siigo\Jobs\PushDevolucionProveedorASiigo;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * COMP-B1 · Confirma una devolución a proveedor:
 *   1. Lock y validaciones (estado=borrador, items no vacíos).
 *   2. Totales recalculados (subtotal + IVA = total).
 *   3. Kardex: 1 InventarioMovimiento 'salida_devolucion_proveedor' por item
 *      con cantidad NEGATIVA (baja stock de la ubicación).
 *   4. MovimientoContable local inverso al asiento de compra:
 *      DB 2205 CxP · CR 1435 Inventario · CR 2408 IVA retenido (si aplica)
 *   5. afterCommit → encola PushDevolucionProveedorASiigo que llama a
 *      POST /v1/credit-notes de SIIGO (NC de compra · supplier.identification).
 */
class RegistrarDevolucionProveedor
{
    public function confirmar(DevolucionProveedor $devolucion): DevolucionProveedor
    {
        return DB::transaction(function () use ($devolucion) {
            $d = DevolucionProveedor::lockForUpdate()->findOrFail($devolucion->id);
            if ($d->estado !== 'borrador') {
                throw new DomainException("Solo devoluciones en Borrador se pueden confirmar.");
            }
            $items = $d->items()->get();
            if ($items->isEmpty()) {
                throw new DomainException("La devolución no tiene ítems.");
            }

            // 2. Recalcular totales por consistencia (nunca confiar en el front).
            $subtotal = 0; $iva = 0;
            foreach ($items as $it) {
                $base = round((float) $it->cantidad * (float) $it->costo_unit, 2);
                $subtotal += $base;
                $iva += round($base * ((float) $it->iva_pct / 100), 2);
            }
            $d->subtotal = round($subtotal, 2);
            $d->iva = round($iva, 2);
            $d->total = round($subtotal + $iva, 2);

            $ts = now();
            $userId = auth()->id();

            // 3. Kardex · baja stock por cada item en la ubicación origen.
            foreach ($items as $it) {
                InventarioMovimiento::create([
                    'variante_id' => $it->variante_id,
                    'producto_id' => $it->producto_id,
                    'ubicacion_id' => $d->ubicacion_id,
                    'tipo' => 'salida_devolucion_proveedor',
                    'cantidad' => -1 * abs((float) $it->cantidad),
                    'costo_unit' => (float) $it->costo_unit,
                    /*
                     * `referencia_tipo`, no `referencia_type`.
                     *
                     * Decía `_type`, y como `InventarioMovimiento` usa
                     * `$guarded = ['id']`, el atributo entraba tal cual al
                     * INSERT: «Unknown column 'referencia_type'». El kardex se
                     * escribe antes del asiento, así que la transacción entera
                     * hacía rollback y NINGUNA devolución a proveedor se podía
                     * confirmar — el botón existía, la pantalla existía, el
                     * asiento estaba bien diseñado, y la tabla tenía 0 filas.
                     */
                    'referencia_tipo' => DevolucionProveedor::class,
                    'referencia_id' => $d->id,
                    'user_id' => $userId,
                    'notas' => "Devolución {$d->numero} · {$d->motivo}",
                    'created_at' => $ts,
                ]);
            }

            // 4. Asiento local inverso de compra.
            //    DB 2205 (CxP al proveedor baja) · CR 1435 (Inventario baja).
            //    IVA: si lo había, también se reversa 2408 crédito contra 2205.
            $terceroType = \App\Models\Contacto::class;
            MovimientoContable::create([
                // Si la compra es de Dropi, sus cuentas; si no, las de siempre.
                'fecha' => $ts, 'cuenta_puc' => \App\Modules\Dropi\Support\CuentasDropi::proveedores($d->recepcion),
                'tercero_type' => $terceroType, 'tercero_id' => $d->proveedor_id,
                'debe' => $d->total, 'haber' => 0,
                'origen_type' => DevolucionProveedor::class, 'origen_id' => $d->id,
                'descripcion' => "Devolución {$d->numero} · reversa CxP",
                'user_id' => $userId,
            ]);
            $ctaInv = $d->ubicacion?->ctaInventarioEfectiva() ?? (string) setting('contable.cta_inventario_default');
            // Dropi: sale de la cuenta de inventario de la familia de cada
            // producto, la misma a la que entró en la recepción.
            $porCuenta = [$ctaInv => (float) $d->subtotal];
            if (\App\Modules\Dropi\Support\CuentasDropi::esDropi($d->recepcion)) {
                $porCuenta = [];
                foreach ($items as $it) {
                    $prod = \App\Modules\Dropi\Support\CuentasDropi::productoDe($it->producto_id, $it->variante_id);
                    $cta = (string) (\App\Modules\Dropi\Support\CuentasDropi::inventario($prod) ?: $ctaInv);
                    $porCuenta[$cta] = round(($porCuenta[$cta] ?? 0) + round((float) $it->cantidad * (float) $it->costo_unit, 2), 2);
                }
            }
            foreach ($porCuenta as $cta => $valor) {
                MovimientoContable::create([
                    'fecha' => $ts, 'cuenta_puc' => $cta,
                    'debe' => 0, 'haber' => $valor,
                    'origen_type' => DevolucionProveedor::class, 'origen_id' => $d->id,
                    'descripcion' => "Devolución {$d->numero} · baja inventario",
                    'user_id' => $userId,
                ]);
            }
            if ($d->iva > 0) {
                MovimientoContable::create([
                    'fecha' => $ts, 'cuenta_puc' => \App\Modules\Dropi\Support\CuentasDropi::ivaCompra($d->recepcion),
                    'debe' => 0, 'haber' => $d->iva,
                    'origen_type' => DevolucionProveedor::class, 'origen_id' => $d->id,
                    'descripcion' => "Devolución {$d->numero} · reversa IVA",
                    'user_id' => $userId,
                ]);
            }

            $d->estado = 'confirmada';
            $d->confirmada_at = $ts;
            $d->save();

            DB::afterCommit(function () use ($d) {
                PushDevolucionProveedorASiigo::dispatch($d->id);
            });

            return $d->fresh(['items', 'proveedor', 'ubicacion']);
        });
    }
}
