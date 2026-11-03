<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jorge recibe un solo aviso por contenedor con el día, las cajas y el peso, para
 * preparar personal y espacio. Esta fecha es la que evita repetírselo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras_importaciones', function (Blueprint $t) {
            $t->date('aviso_llegada_en')->nullable()->after('fecha_liquidacion');
        });
    }

    public function down(): void
    {
        Schema::table('compras_importaciones', function (Blueprint $t) {
            $t->dropColumn('aviso_llegada_en');
        });
    }
};
