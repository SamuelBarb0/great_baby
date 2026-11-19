<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trading o fábrica directa (Word de Alejandro, secciones 3 y 4): de eso dependen la
 * comisión de la pre-liquidación y el valor mínimo del contenedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras_ordenes', function (Blueprint $t) {
            $t->string('modelo_compra', 20)->nullable()->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('compras_ordenes', function (Blueprint $t) {
            $t->dropColumn('modelo_compra');
        });
    }
};
