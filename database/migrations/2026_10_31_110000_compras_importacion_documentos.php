<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los documentos de cada contenedor (reunión con Alejandro y Liz, 30-oct-2026):
 * prelista y lista de empaque, BL, invoice, factura final, DUTA, declaraciones,
 * formulario de ingreso a zona franca… y el comprobante de cada gasto, que es
 * un documento más ligado a su gasto. Cada tipo guarda sus versiones: la
 * orden nunca coincide con la lista final y hay que poder ver ambas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras_importacion_documentos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('importacion_id')->constrained('compras_importaciones')->cascadeOnDelete();
            $t->foreignId('gasto_id')->nullable()->constrained('compras_importacion_gastos')->cascadeOnDelete();
            $t->string('tipo', 40);
            $t->string('nombre', 255)->comment('Nombre original del archivo');
            $t->string('ruta', 500);
            $t->string('mime', 120)->nullable();
            $t->unsignedBigInteger('tamano')->default(0);
            $t->unsignedSmallInteger('version')->default(1);
            $t->text('notas')->nullable();
            $t->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['importacion_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras_importacion_documentos');
    }
};
