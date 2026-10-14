<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equivalencias Dropi ↔ catálogo GB. La llave de Dropi (`DV-<variación>` o el
 * SKU del producto) casi nunca coincide con `producto_variantes.codigo_barras`,
 * así que alguien la enlaza UNA vez con la variante y desde ahí los pedidos
 * entran solos. Tabla aparte y no columna en variantes: una variante del ERP
 * puede tener varias llaves en Dropi (variaciones, tiendas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dropi_vinculos_producto', function (Blueprint $table) {
            $table->id();
            $table->string('sku_dropi', 60)->unique();
            $table->foreignId('variante_id')->constrained('producto_variantes')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('producto_nombre', 150)->nullable()->comment('Nombre en Dropi al vincular (referencia)');
            $table->string('variacion', 80)->nullable();
            $table->foreignId('vinculado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dropi_vinculos_producto');
    }
};
