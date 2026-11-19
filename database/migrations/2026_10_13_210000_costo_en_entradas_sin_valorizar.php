<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pone costo a las entradas de inventario que quedaron sin valorizar.
 *
 * El stock inicial que escribe el formulario de productos entraba al kardex
 * sin `costo_unit` (corregido en `Producto::booted`). Como el kardex se
 * valoriza con el promedio ponderado de las ENTRADAS, todo lo que entró así
 * vale cero, y eso arrastra dos cosas que se ven en pantalla:
 *
 *   · el «Reporte de stock por bodega» muestra bodegas llenas valorizadas
 *     en $0, y el balance subvalúa el activo;
 *   · el despacho sale a costo cero, así que NO causa costo de ventas y el
 *     margen bruto del Estado de resultados da 100%.
 *
 * El costo está en el producto (`precio_proveedor`), que es de donde debió
 * salir desde el principio. No se tocan las entradas que ya tienen costo —ese
 * es el dato real de la compra— ni las salidas, que salen al promedio.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventario_movimientos as m')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->whereNull('m.costo_unit')
            ->where('m.cantidad', '>', 0)
            ->where('p.precio_proveedor', '>', 0)
            ->update(['m.costo_unit' => DB::raw('p.precio_proveedor')]);
    }

    public function down(): void
    {
        // No se revierte: devolver estas entradas a NULL sería volver a dejar
        // el inventario sin valorizar, que es justamente el defecto.
    }
};
