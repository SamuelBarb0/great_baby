<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «IMPRESAS / NO IMPRESAS» como en Dropi (reunión con la señora Lahida,
 * 24-nov-2026): Aracely separa un corte del otro filtrando las guías generadas
 * que aún no imprimió.
 *
 *  - `dropi_web_huellas.guia_descargada`: lo que dice Dropi (`guide_was_downloaded`).
 *  - `dropi_pedidos.guia_impresa_at/por`: cuando se imprimen desde el ERP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_web_huellas', function (Blueprint $t) {
            $t->boolean('guia_descargada')->nullable()->after('estatus_dropi');
        });
        Schema::table('dropi_pedidos', function (Blueprint $t) {
            $t->timestamp('guia_impresa_at')->nullable()->after('sticker_path');
            $t->foreignId('guia_impresa_por')->nullable()->after('guia_impresa_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dropi_pedidos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('guia_impresa_por');
            $t->dropColumn('guia_impresa_at');
        });
        Schema::table('dropi_web_huellas', function (Blueprint $t) {
            $t->dropColumn('guia_descargada');
        });
    }
};
