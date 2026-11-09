<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repara `dropi_pedido_items.producto_id` donde falta.
 *
 * En producción `2026_09_21_100010_add_producto_id_a_dropi_pedido_items`
 * figura como corrida (batch 29) pero la columna NO está (visto el 7-nov-2026
 * en la copia de prod). Como Laravel no vuelve a correr una migración
 * registrada, ésta repite lo mismo con la misma guarda: donde la columna ya
 * existe no hace nada, y donde falta la crea y la llena desde la variante.
 *
 * Sin la columna, todo vínculo de Dropi a un producto AGREGADO (casi todo el
 * catálogo) revienta al guardar el ítem del pedido.
 */
return new class extends Migration {

    public function up(): void
    {
        if (! Schema::hasColumn('dropi_pedido_items', 'producto_id')) {
            Schema::table('dropi_pedido_items', function (Blueprint $t) {
                $t->foreignId('producto_id')->nullable()->after('variante_id')
                  ->constrained('productos')->cascadeOnUpdate()->restrictOnDelete();
            });
        }

        // Los ítems con variante heredan el producto de su variante. Idempotente:
        // sólo toca los que no lo tienen.
        DB::statement('
            UPDATE dropi_pedido_items i
              JOIN producto_variantes v ON i.variante_id = v.id
              SET i.producto_id = v.producto_id
              WHERE i.producto_id IS NULL AND i.variante_id IS NOT NULL
        ');
    }

    /**
     * Sin reversa: la columna es de `2026_09_21_100010`, y deshacer esta
     * reparación no debe quitársela a una base donde ya existía.
     */
    public function down(): void
    {
    }
};
