<?php

/*
 * La reserva de una variante no sabía de qué producto era.
 *
 * `ReservarStock` grababa `producto_id = NULL` en toda reserva de variante,
 * mientras el kardex sí lo denormaliza desde siempre. La disponibilidad masiva
 * del portal suma las dos tablas con el MISMO filtro —`whereIn('producto_id',
 * ...)` más `whereNotNull('variante_id')`—, así que encontraba el físico y no
 * encontraba lo reservado: el portal ofrecía mercancía que ya estaba apartada
 * para otro pedido, y recién al aprobar aparecía que no alcanzaba.
 *
 * Esto rellena lo que ya está guardado. Sin el relleno, el arreglo del código
 * sólo sirve para las reservas nuevas y las que están vivas hoy —que son las
 * que de verdad descuentan— seguirían invisibles.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reservas_inventario') || ! Schema::hasTable('producto_variantes')) {
            return;
        }

        DB::table('reservas_inventario')
            ->whereNotNull('variante_id')
            ->whereNull('producto_id')
            ->orderBy('id')
            ->chunkById(500, function ($reservas) {
                $productos = DB::table('producto_variantes')
                    ->whereIn('id', $reservas->pluck('variante_id'))
                    ->pluck('producto_id', 'id');

                foreach ($reservas as $r) {
                    $productoId = $productos[$r->variante_id] ?? null;

                    // La variante borrada no se adivina: se deja como está y
                    // la reserva sigue funcionando por `variante_id`.
                    if ($productoId) {
                        DB::table('reservas_inventario')->where('id', $r->id)
                            ->update(['producto_id' => $productoId]);
                    }
                }
            });
    }

    /*
     * No se vacía de vuelta.
     *
     * Dejar el producto puesto no rompe nada —es el mismo dato que ya está en
     * la variante— y vaciarlo volvería a esconder las reservas del portal.
     */
    public function down(): void {}
};
