<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarjeta Dropi · 5. Devoluciones.
 *
 *   · dropi_devoluciones: lo que la bodega escaneó al recibir (5.4). Antes se
 *     reingresaba el pedido entero aunque llegara una caja a medias.
 *   · dropi_pedidos.factura_venta_id: la factura en la que quedó el pedido al
 *     cerrar el corte (5.3). La nota crédito la buscaba por `ari_factura_id`,
 *     que nada llenaba, así que ninguna devolución generaba NC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_devoluciones', function (Blueprint $table) {
            if (! Schema::hasColumn('dropi_devoluciones', 'recibidos')) {
                // clave `v:{variante}` / `p:{producto}` => unidades escaneadas
                $table->json('recibidos')->nullable()->after('destino_inventario');
                $table->unsignedInteger('unidades_esperadas')->nullable()->after('recibidos');
                $table->unsignedInteger('unidades_recibidas')->nullable()->after('unidades_esperadas');
                $table->boolean('reescaneada')->default(false)->after('unidades_recibidas');
            }
        });

        Schema::table('dropi_pedidos', function (Blueprint $table) {
            if (! Schema::hasColumn('dropi_pedidos', 'factura_venta_id')) {
                $table->foreignId('factura_venta_id')->nullable()->after('ari_factura_id')
                    ->constrained('facturas_venta')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dropi_pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('factura_venta_id');
        });
        Schema::table('dropi_devoluciones', function (Blueprint $table) {
            $table->dropColumn(['recibidos', 'unidades_esperadas', 'unidades_recibidas', 'reescaneada']);
        });
    }
};
