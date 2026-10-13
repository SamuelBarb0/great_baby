<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuenta de Dropi con la que entra el driver `web`, editable desde el CRM
 * (Dropi → Conexión Dropi). Una sola fila; si no hay, se usa la del .env
 * (DROPI_WEB_EMAIL / DROPI_WEB_PASSWORD). La clave va cifrada (cast `encrypted`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dropi_web_cuenta', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->text('password');
            $table->foreignId('configurada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dropi_web_cuenta');
    }
};
