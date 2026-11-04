<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Producto «de línea» (Word de Alejandro, sección 2): siempre debe haber, aunque su
 * rotación sea de más de tres meses. Los demás, con esa rotación, no se vuelven a pedir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $t) {
            $t->boolean('es_de_linea')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $t) {
            $t->dropColumn('es_de_linea');
        });
    }
};
