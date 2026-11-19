<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos cosas que pidieron Alejandro y Liz (reuniones del 21 y 30-oct-2026):
 *
 * «Este producto cambió a esta referencia»: el sistema debe leer las dos como el
 * mismo producto, en vez de crear otro.
 *
 * La orden nunca coincide con la lista de empaque final: hay que poder ver qué se
 * quitó y por qué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_referencias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $t->string('referencia', 100)->unique();
            $t->string('motivo', 200)->nullable();
            $t->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('compras_diferencias_empaque', function (Blueprint $t) {
            $t->id();
            $t->foreignId('importacion_id')->constrained('compras_importaciones')->cascadeOnDelete();
            $t->string('referencia', 100);
            $t->string('motivo', 500);
            $t->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['importacion_id', 'referencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras_diferencias_empaque');
        Schema::dropIfExists('producto_referencias');
    }
};
