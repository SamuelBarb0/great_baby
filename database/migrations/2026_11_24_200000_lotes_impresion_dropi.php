<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada «Imprimir guías» es un lote (24-nov-2026: son 300 a 800 paquetes): el
 * PDF se arma en segundo plano, en partes, y la Vista del Alistador muestra la
 * recolección de ESE lote, la misma hoja que la encargada tiene en la mano.
 *
 *  - `dropi_lotes_impresion`: los pedidos en el orden de la pila, el avance y las partes.
 *  - `dropi_pedidos.lote_impresion_id`: de qué lote salió su sticker.
 *  - `dropi_recoleccion_marcas.lote_impresion_id`: «recogido» por lote; las marcas
 *    viejas por corte se quedan como estaban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dropi_lotes_impresion', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->json('pedidos');
            $t->unsignedInteger('total');
            $t->unsignedInteger('descargados')->default(0);
            $t->json('sin_sticker')->nullable();
            $t->json('partes')->nullable();
            $t->string('estado', 20)->default('descargando');
            $t->text('error')->nullable();
            $t->timestamps();
        });

        Schema::table('dropi_pedidos', function (Blueprint $t) {
            $t->foreignId('lote_impresion_id')->nullable()->after('orden_empaque')
                ->constrained('dropi_lotes_impresion')->nullOnDelete();
        });

        Schema::table('dropi_recoleccion_marcas', function (Blueprint $t) {
            $t->foreignId('corte_id')->nullable()->change();
            $t->foreignId('lote_impresion_id')->nullable()->after('corte_id')
                ->constrained('dropi_lotes_impresion')->cascadeOnDelete();
            $t->unique(['lote_impresion_id', 'sku_dropi']);
        });
    }

    public function down(): void
    {
        Schema::table('dropi_recoleccion_marcas', function (Blueprint $t) {
            $t->dropUnique(['lote_impresion_id', 'sku_dropi']);
            $t->dropConstrainedForeignId('lote_impresion_id');
        });
        Schema::table('dropi_pedidos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('lote_impresion_id');
        });
        Schema::dropIfExists('dropi_lotes_impresion');
    }
};
