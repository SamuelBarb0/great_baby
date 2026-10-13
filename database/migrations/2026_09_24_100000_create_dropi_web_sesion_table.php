<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesión del driver `web` de Dropi (el que habla con la API interna de app.dropi.co).
 * Una sola fila: la cuenta de Dropi con la que el ERP lee y escribe.
 * El token va cifrado (cast `encrypted` en el modelo), nunca en claro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dropi_web_sesion', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->text('token')->nullable();
            $table->timestamp('expira_at')->nullable();
            // desconectado | esperando_codigo | conectado | expirado
            $table->string('estado', 20)->default('desconectado');
            $table->text('ultimo_error')->nullable();
            $table->foreignId('conectado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('conectado_at')->nullable();
            $table->timestamp('ultimo_uso_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dropi_web_sesion');
    }
};
