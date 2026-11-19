<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El color pedido en la orden y el color contado en la recepción. Se guarda como
 * texto porque puede no existir todavía como variante, o porque el producto ya tiene
 * stock sin colores y no puede tenerlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compra_orden_items', function (Blueprint $t) {
            $t->string('color_nombre', 60)->nullable()->after('descripcion');
        });
        Schema::table('compras_recepcion_items', function (Blueprint $t) {
            $t->string('color_nombre', 60)->nullable()->after('variante_id');
        });
    }

    public function down(): void
    {
        Schema::table('compra_orden_items', fn (Blueprint $t) => $t->dropColumn('color_nombre'));
        Schema::table('compras_recepcion_items', fn (Blueprint $t) => $t->dropColumn('color_nombre'));
    }
};
