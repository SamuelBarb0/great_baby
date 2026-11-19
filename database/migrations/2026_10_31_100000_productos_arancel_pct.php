<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El arancel de cada producto, junto a su posición arancelaria (pedido de
 * Liz, Comercio Exterior, 30-oct-2026): con el histórico en el maestro no hay
 * que consultarlo en cada pedido, y la pre-liquidación usa el del producto en
 * vez del promedio de la orden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $t) {
            $t->decimal('arancel_pct', 5, 2)->nullable()->after('posicion_arancelaria');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $t) {
            $t->dropColumn('arancel_pct');
        });
    }
};
