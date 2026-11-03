<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo hay que pagarle a China (reunión con Alejandro, 21-oct-2026).
 *
 * La regla no es una sola: con el intermediario son 90 días desde que zarpa el
 * buque; con una fábrica nueva, 30 % al mandar a fabricar y 70 % al terminar. Por
 * eso cada orden lleva un plan de una o varias cuotas, y no un plazo.
 *
 * El plazo de `condiciones_credito` cuenta desde la recepción, y la deuda con China
 * corre mucho antes de que la mercancía llegue a bodega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras_compromisos_pago', function (Blueprint $t) {
            $t->id();
            $t->foreignId('orden_id')->constrained('compras_ordenes')->cascadeOnDelete();
            $t->string('concepto', 80);
            $t->decimal('porcentaje', 5, 2);
            $t->string('base', 20)->comment('emision | fabricacion | zarpe');
            $t->unsignedSmallInteger('dias')->default(0);
            $t->date('pagado_en')->nullable();
            $t->string('nota_pago', 500)->nullable();
            $t->foreignId('pagado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->date('ultimo_seguimiento_en')->nullable();
            $t->string('ultimo_seguimiento_nota', 500)->nullable();
            $t->foreignId('seguimiento_por')->nullable()->constrained('users')->nullOnDelete();
            $t->date('aviso_naranja_en')->nullable();
            $t->date('aviso_rojo_en')->nullable();
            $t->date('aviso_vencida_en')->nullable();
            $t->timestamps();
            $t->index(['orden_id', 'pagado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras_compromisos_pago');
    }
};
