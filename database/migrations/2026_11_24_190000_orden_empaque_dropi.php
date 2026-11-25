<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que Aracely imprime es lo que se empaca, en el orden de la pila de
 * stickers (24-nov-2026): `orden_empaque` es la posición del pedido en la pila,
 * corrida entre lotes (el lote de las 9 va antes que el de las 11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_pedidos', function (Blueprint $t) {
            $t->unsignedBigInteger('orden_empaque')->nullable()->after('guia_impresa_por')->index();
        });
    }

    public function down(): void
    {
        Schema::table('dropi_pedidos', function (Blueprint $t) {
            $t->dropIndex(['orden_empaque']);
            $t->dropColumn('orden_empaque');
        });
    }
};
