<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los manifiestos de Dropi («Logistic Management → Salidas»): cada entrega de
 * paquetes a una transportadora, con su PDF y su Excel guardados en el ERP
 * (reunión con la señora Lahida, 24-nov-2026: es la constancia para reclamar
 * la garantía de un paquete perdido).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dropi_manifiestos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dropi_id')->unique();
            $t->string('transportadora', 80)->nullable();
            $t->unsignedBigInteger('bodega_dropi_id')->nullable();
            $t->string('usuario_dropi', 120)->nullable();
            $t->timestamp('dropi_creado_at')->nullable()->index();
            $t->unsignedInteger('pedidos')->default(0);
            // [{orden, guia}] tal cual vienen en la salida.
            $t->json('guias')->nullable();
            $t->string('pdf_path')->nullable();
            $t->string('excel_path')->nullable();
            $t->text('error_archivos')->nullable();
            $t->timestamp('revisado_at')->nullable();
            $t->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dropi_manifiestos');
    }
};
