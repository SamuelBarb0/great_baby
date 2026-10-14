<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etiquetas de Dropi vistas por el sync, para que la ventana de etiquetas del
 * ERP abra al instante (buscar el pedido en Dropi tarda ~9 s).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_web_huellas', function (Blueprint $table) {
            $table->json('etiquetas')->nullable()->after('estatus_dropi');
        });
    }

    public function down(): void
    {
        Schema::table('dropi_web_huellas', function (Blueprint $table) {
            $table->dropColumn('etiquetas');
        });
    }
};
