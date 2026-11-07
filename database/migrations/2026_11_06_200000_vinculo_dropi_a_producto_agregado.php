<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un SKU de Dropi puede apuntar a un producto AGREGADO, no sólo a una variante.
 *
 * El vínculo exigía `variante_id`, pero casi todo el catálogo de Great Baby está
 * en modo agregado (`desglose_stock = false`: el stock es un solo total, sin
 * variantes) y ese modo prohíbe crear variantes si el producto ya tiene kardex.
 * Resultado: ningún pedido de Dropi podía enlazarse a esos productos —en la
 * copia local, 2.204 de 2.552 líneas sin producto—. Lo de abajo (empaque,
 * salida de inventario, factura, devolución) ya sabía tratar líneas agregadas
 * por `producto_id`; faltaba que el vínculo pudiera decirlo.
 *
 * Exactamente uno de los dos: variante (producto con desglose) o producto
 * (agregado). Lo cuida VincularProductoDropi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_vinculos_producto', function (Blueprint $table) {
            $table->dropForeign(['variante_id']);
        });

        Schema::table('dropi_vinculos_producto', function (Blueprint $table) {
            $table->unsignedBigInteger('variante_id')->nullable()->change();
            $table->foreign('variante_id')->references('id')->on('producto_variantes')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('producto_id')->nullable()->after('variante_id')
                ->constrained('productos')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Volver atrás sólo es posible si no quedaron vínculos a productos agregados.
        if (\Illuminate\Support\Facades\DB::table('dropi_vinculos_producto')->whereNull('variante_id')->exists()) {
            throw new RuntimeException('Hay vínculos de Dropi a productos agregados: bórrelos antes de revertir.');
        }

        Schema::table('dropi_vinculos_producto', function (Blueprint $table) {
            $table->dropForeign(['producto_id']);
            $table->dropColumn('producto_id');
            $table->dropForeign(['variante_id']);
        });

        Schema::table('dropi_vinculos_producto', function (Blueprint $table) {
            $table->unsignedBigInteger('variante_id')->nullable(false)->change();
            $table->foreign('variante_id')->references('id')->on('producto_variantes')->cascadeOnUpdate()->restrictOnDelete();
        });
    }
};
