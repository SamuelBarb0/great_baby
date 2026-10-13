<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Última versión vista de cada pedido en la lista de Dropi (estado + guía +
 * transportadora). El sync web solo pide el detalle —la llamada cara— de los
 * pedidos cuya huella cambió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dropi_web_huellas', function (Blueprint $table) {
            $table->unsignedBigInteger('dropi_orden_id')->primary();
            $table->string('huella', 64);
            $table->string('estatus_dropi', 60)->nullable();
            $table->timestamp('visto_at')->nullable();
            $table->timestamp('sincronizado_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dropi_web_huellas');
    }
};
