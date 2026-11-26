<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué pasó con la guía de cada pedido de un reporte (26-nov-2026): generada,
 * ya la tenía, cancelada o reemplazada en Dropi, sin cobertura, error… Para
 * que Aracely vea el motivo de cada una y qué hacer, no sólo «9 con error».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_lotes_impresion', function (Blueprint $t) {
            $t->json('resultados')->nullable()->after('sin_sticker');
        });
    }

    public function down(): void
    {
        Schema::table('dropi_lotes_impresion', function (Blueprint $t) {
            $t->dropColumn('resultados');
        });
    }
};
