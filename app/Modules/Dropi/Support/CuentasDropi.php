<?php

namespace App\Modules\Dropi\Support;

use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Models\Producto;

/**
 * Las cuentas del PUC con las que asienta Dropi, y SÓLO Dropi (19-nov-2026).
 *
 * La regla de la casa: lo de Dropi no mueve nada del ERP. La factura, el
 * cobro y la nota crédito de Dropi pasan por el mismo código que los de Great
 * Baby (RegistrarAsientoContable, RegistrarAsientoNota); ahí la única pieza
 * nueva es preguntarle a esta clase la cuenta, y esta clase devuelve la regla
 * del ERP intacta cuando el documento NO es de Dropi. Para Great Baby el
 * resultado es exactamente el de antes.
 *
 * Las reglas son `contable.cta_dropi_*` (pantalla de Reglas, título Dropi) y
 * todas apuntan a cuentas hoja del PUC.
 */
class CuentasDropi
{
    /** ¿El documento (factura, pago, nota) es de la línea Dropi? */
    public static function esDropi(?object $documento): bool
    {
        $linea = $documento?->linea_negocio ?? null;

        return ($linea instanceof LineaNegocio ? $linea->value : (string) $linea) === LineaNegocio::Dropi->value;
    }

    /** IVA generado: el de Dropi para sus documentos, el del ERP para el resto. */
    public static function ivaVenta(?object $factura): string
    {
        return (string) (self::esDropi($factura)
            ? setting('contable.cta_dropi_iva_venta')
            : setting('contable.cta_iva_venta_default'));
    }

    /** Devolución en ventas: la de Dropi para sus notas crédito, la del ERP para el resto. */
    public static function devolucion(?object $factura): string
    {
        return (string) (self::esDropi($factura)
            ? setting('contable.cta_dropi_devolucion')
            : setting('contable.cta_devolucion_default'));
    }

    /** Proveedores: la de Dropi (nacional o exterior) para sus compras, la del ERP para el resto. */
    public static function proveedores(?object $documento, bool $exterior = false): string
    {
        if (self::esDropi($documento)) {
            return (string) setting($exterior ? 'contable.cta_dropi_proveedores_exterior' : 'contable.cta_dropi_proveedores');
        }

        return (string) setting($exterior ? 'contable.cta_proveedores_exterior' : 'contable.cta_proveedores_default');
    }

    /** IVA descontable: el de Dropi para sus compras, el del ERP para el resto. */
    public static function ivaCompra(?object $documento): string
    {
        return (string) (self::esDropi($documento)
            ? setting('contable.cta_dropi_iva_compra')
            : setting('contable.cta_iva_compra_default'));
    }

    /**
     * Contrapartida de una carga de mercancía de Dropi, según el motivo.
     * La compra se le debe al proveedor en la misma cuenta que una compra por
     * orden; el resto, a su regla `contable.cta_dropi_carga_*`.
     */
    public static function contrapartidaCarga(\App\Modules\Inventario\Enums\MotivoCargaInventario $motivo): string
    {
        return match ($motivo) {
            \App\Modules\Inventario\Enums\MotivoCargaInventario::Compra => (string) setting('contable.cta_dropi_proveedores'),
            // De Great Baby a Dropi: la misma empresa, libros aparte.
            \App\Modules\Inventario\Enums\MotivoCargaInventario::TrasladoGreatBaby => (string) setting('contable.cta_dropi_cuenta_interna'),
            default => (string) setting('contable.cta_dropi_carga_'.$motivo->value),
        };
    }

    /** El producto de una línea que trae producto y/o variante. */
    public static function productoDe(?int $productoId, ?int $varianteId): ?Producto
    {
        if ($varianteId) {
            $p = \App\Modules\Dropi\Models\ProductoVariante::find($varianteId)?->producto;
            if ($p) {
                return $p;
            }
        }

        return $productoId ? Producto::find($productoId) : null;
    }

    /**
     * Costo de ventas de un producto vendido por Dropi.
     *
     * El PUC abre el costo por familia de producto igual que el ingreso
     * (41350104 JUGUETERÍA → 61350504 JUGUETERÍA), y la categoría del producto
     * ya trae la cuenta de ingreso. Se busca la hoja de 613505 con el MISMO
     * NOMBRE: por nombre y no por número, porque la 41350102 es «Descuentos»
     * y no baño. Sin categoría con cuenta, queda lo que diga el producto o la
     * regla del ERP, como antes.
     */
    public static function costo(?Producto $producto): ?string
    {
        return self::hojaODropi(
            self::hermanaDelIngreso($producto, '613505') ?? $producto?->cta('costo') ?? setting('contable.cta_costo_default'),
            'contable.cta_dropi_costo_default',
        );
    }

    /**
     * QA 23-nov-2026: 361 de 364 productos no tienen categoría, así que la
     * familia no se encontraba y caía a la regla del ERP (6135 / 1435), que son
     * GRUPOS: no admiten movimiento y SIIGO los rechaza. Para Dropi, si lo que
     * sale no es una cuenta hoja, se usa la regla propia de Dropi.
     */
    private static function hojaODropi(?string $cuenta, string $reglaDropi): ?string
    {
        if (filled($cuenta) && \App\Modules\Contabilidad\Models\PlanCuenta::where('codigo', $cuenta)->where('permite_movimiento', true)->exists()) {
            return (string) $cuenta;
        }
        $dropi = (string) setting($reglaDropi);

        return $dropi !== '' ? $dropi : $cuenta;
    }

    /** Inventario de un producto de Dropi: la hoja de 1435 de su familia. Ver costo(). */
    public static function inventario(?Producto $producto): ?string
    {
        return self::hojaODropi(
            self::hermanaDelIngreso($producto, '1435') ?? $producto?->cta('inventario') ?? setting('contable.cta_inventario_default'),
            'contable.cta_dropi_inventario_default',
        );
    }

    private static function hermanaDelIngreso(?Producto $producto, string $prefijo): ?string
    {
        if (! $producto) {
            return null;
        }
        // Lo que la ficha del producto diga explícitamente manda.
        $propia = $prefijo === '1435' ? $producto->cta_inventario : $producto->cta_costo;
        if (filled($propia)) {
            return (string) $propia;
        }

        $ingreso = filled($producto->cta_ingreso) ? (string) $producto->cta_ingreso : null;
        $categoria = $producto->categoriaMaestra;
        $saltos = 0;
        while (! $ingreso && $categoria && $saltos++ < 10) {
            $ingreso = filled($categoria->cuenta_puc_ingreso) ? (string) $categoria->cuenta_puc_ingreso : null;
            $categoria = $categoria->padre;
        }
        if (! $ingreso) {
            return null;
        }

        $nombre = \App\Modules\Contabilidad\Models\PlanCuenta::where('codigo', $ingreso)->value('nombre');
        if (! $nombre) {
            return null;
        }

        return \App\Modules\Contabilidad\Models\PlanCuenta::where('codigo', 'like', $prefijo.'%')
            ->where('permite_movimiento', true)
            ->where('nombre', $nombre)
            ->orderBy('codigo')
            ->value('codigo');
    }
}
