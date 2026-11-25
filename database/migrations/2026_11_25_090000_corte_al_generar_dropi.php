<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El corte nace cuando Aracely genera (25-nov-2026): filtra, revisa, selecciona
 * y le da «Generar corte»; la hora de ese momento dice si es el Corte 1 o el 2.
 * Hasta entonces el pedido está «sin corte».
 *
 *  - `dropi_pedidos.corte_id` pasa a ser opcional (los que ya tienen corte lo conservan).
 *  - `dropi_lotes_impresion.corte_id`: el reporte (hoja + stickers) es de un corte;
 *    y `guias_pendientes`: el reporte se arma cuando terminan sus guías.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_pedidos', function (Blueprint $t) {
            $t->foreignId('corte_id')->nullable()->change();
        });

        Schema::table('dropi_lotes_impresion', function (Blueprint $t) {
            $t->foreignId('corte_id')->nullable()->after('id')->constrained('dropi_cortes')->nullOnDelete();
            $t->unsignedInteger('guias_pendientes')->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('dropi_lotes_impresion', function (Blueprint $t) {
            $t->dropConstrainedForeignId('corte_id');
            $t->dropColumn('guias_pendientes');
        });
        // corte_id de dropi_pedidos se deja opcional: volverlo obligatorio
        //   fallaría con los pedidos que ya nacieron sin corte.
    }
};
