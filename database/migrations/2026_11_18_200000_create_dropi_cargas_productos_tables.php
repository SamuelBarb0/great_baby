<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cargas de productos a Dropi hechas desde el ERP (crear productos y mover su
 * stock), en reemplazo de las plantillas de Dropi, que fallaban sin decir por
 * qué y no dejaban reintentar (18-nov-2026).
 *
 * Una carga = un lote (un Excel o un producto suelto) con su concepto. Cada
 * operación es un producto de Dropi: crear, o mover el stock de sus
 * variaciones. Se guarda el stock leído antes, el pedido y el releído
 * después, para auditar y para poder deshacer a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dropi_cargas_productos', function (Blueprint $t) {
            $t->id();
            $t->string('numero', 20)->unique();
            // excel = carga masiva · individual = un producto desde su ficha.
            $t->string('origen', 12)->default('excel');
            $t->string('archivo_nombre', 191)->nullable();
            // reemplazar = el Excel dice cuánto debe quedar · sumar = el Excel trae lo que entra.
            $t->string('modo', 12)->default('reemplazar');
            // Lo que queda escrito en el historial de stock de Dropi.
            $t->string('concepto', 120);
            $t->unsignedBigInteger('bodega_dropi_id');
            // Valores por defecto para los productos que se crean.
            $t->json('defaults_crear')->nullable();
            // borrador → procesando → terminado.
            $t->string('estado', 12)->default('borrador');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('confirmado_at')->nullable();
            $t->timestamp('terminado_at')->nullable();
            $t->timestamps();
        });

        Schema::create('dropi_carga_operaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('carga_id')->constrained('dropi_cargas_productos')->cascadeOnDelete();
            $t->unsignedInteger('fila')->nullable();
            $t->string('ref_excel', 100)->nullable();
            $t->string('nombre_excel', 255)->nullable();
            $t->string('variaciones_excel', 500)->nullable();
            $t->decimal('cantidad_excel', 12, 2)->nullable();
            // actualizar · crear
            $t->string('tipo', 12);
            // revisar · listo · omitido · ok · error
            $t->string('estado', 12);
            $t->string('motivo', 500)->nullable();
            $t->unsignedBigInteger('dropi_producto_id')->nullable()->index();
            $t->string('dropi_nombre', 255)->nullable();
            // actualizar: [{variation_id, etiqueta, stock_plan, cantidad, antes, movimiento, despues}]
            // crear: {nombre, sku, precio, sugerido, variaciones:[{valor, sku, precio, sugerido, cantidad}]}
            $t->json('detalle');
            $t->json('candidato')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('procesado_at')->nullable();
            $t->timestamps();

            $t->index(['carga_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dropi_carga_operaciones');
        Schema::dropIfExists('dropi_cargas_productos');
    }
};
