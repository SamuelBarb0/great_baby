<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La carga DC- con que el stock de una recepción subió a Dropi: la constancia
 * que se ve en la orden de compra («Stock en Dropi», 24-nov-2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_cargas_productos', function (Blueprint $t) {
            $t->foreignId('recepcion_id')->nullable()->after('origen')->constrained('compras_recepciones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dropi_cargas_productos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('recepcion_id');
        });
    }
};
