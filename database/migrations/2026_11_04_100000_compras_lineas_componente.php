<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subproductos (Word de Alejandro, sección 7): en una orden pueden venir filas con
 * la misma referencia que son partes o empaque del producto, no unidades para
 * vender. Ej. AND2505-889: 12.000 pares + 12.000 + 24.000 + 12.000 piezas de empaque.
 *
 * Un componente se cuenta en la recepción pero no sube al inventario, y su costo se
 * suma al del producto al que pertenece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compra_orden_items', function (Blueprint $t) {
            $t->string('tipo_linea', 20)->default('producto')->after('empresa_destino_id');
            $t->unsignedBigInteger('componente_de_item_id')->nullable()->after('tipo_linea')->index();
        });
    }

    public function down(): void
    {
        Schema::table('compra_orden_items', function (Blueprint $t) {
            $t->dropIndex(['componente_de_item_id']);
            $t->dropColumn(['tipo_linea', 'componente_de_item_id']);
        });
    }
};
