<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de todo lo que el ERP le escribe a Dropi (driver web): quién, qué
 * pedido, qué cambio pidió, y qué respondió Dropi. Se guarda también lo fallido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dropi_web_acciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->nullable()->constrained('dropi_pedidos')->nullOnDelete();
            $table->string('dropi_orden_id', 60);
            $table->string('accion', 40);             // actualizar_estatus
            $table->string('estatus_antes', 60)->nullable();
            $table->string('estatus_pedido', 60)->nullable();
            $table->text('motivo')->nullable();
            $table->boolean('exito');
            $table->text('respuesta')->nullable();    // resultado o mensaje de error de Dropi
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['dropi_orden_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dropi_web_acciones');
    }
};
