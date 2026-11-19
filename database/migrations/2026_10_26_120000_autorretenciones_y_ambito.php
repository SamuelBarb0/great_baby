<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Autorretención de renta y de ICA, y el ámbito compras / ventas.
 *
 * La contadora lo pidió en la reunión del 21-oct: «nosotros hacemos una
 * autorrenta por cada facturación que se emita; ese movimiento debe ir amarrado
 * ahí también, así como el IVA». Hasta hoy la pantalla sólo aceptaba
 * `retefuente|reteica|reteiva`, y las tres eran de COMPRAS: no había forma de
 * configurar nada que se dispare al emitir una factura de venta.
 *
 * Tres cambios:
 *
 * 1 · `tipo` admite `autorrenta` y `autorreteica`.
 *
 * 2 · `ambito` (compras|ventas) separa lo que se practica al PAGAR una compra
 *     de lo que se practica al EMITIR una venta. Las que ya existen quedan en
 *     «compras», que es lo que eran.
 *
 * 3 · `cuenta_puc_contrapartida`, porque una autorretención mueve DOS cuentas:
 *     la 13 como activo (el anticipo que queda a favor) y la 23 como pasivo (lo
 *     que se le paga a la DIAN mes a mes). Con una sola columna sólo se podía
 *     guardar la mitad del asiento.
 *     Las dos ya existen en el PUC de Great Baby:
 *       13551816 / 13551817  AUTORRETENCIONES ICA BUCARAMANGA   (activo)
 *       23657501             AUTORRETENCIONES DE RENTA          (pasivo)
 *       23657601..04         AUTORRETENCIONES ICA BUCARAMANGA   (pasivo)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE retenciones_config MODIFY COLUMN tipo
             ENUM('retefuente','reteica','reteiva','autorrenta','autorreteica') NOT NULL"
        );

        Schema::table('retenciones_config', function (Blueprint $t) {
            if (! Schema::hasColumn('retenciones_config', 'ambito')) {
                $t->enum('ambito', ['compras', 'ventas'])->default('compras')->after('tipo')
                    ->comment('compras = al pagar una compra · ventas = al emitir una factura');
            }
            if (! Schema::hasColumn('retenciones_config', 'cuenta_puc_contrapartida')) {
                $t->string('cuenta_puc_contrapartida', 30)->nullable()->after('cuenta_puc')
                    ->comment('La otra pata del asiento · en autorretención, la 13 de activo');
            }
        });

        // Lo que ya estaba cargado es de compras, que es lo único que existía.
        DB::table('retenciones_config')->whereNull('ambito')->update(['ambito' => 'compras']);
    }

    public function down(): void
    {
        Schema::table('retenciones_config', function (Blueprint $t) {
            foreach (['ambito', 'cuenta_puc_contrapartida'] as $col) {
                if (Schema::hasColumn('retenciones_config', $col)) {
                    $t->dropColumn($col);
                }
            }
        });

        DB::table('retenciones_config')->whereIn('tipo', ['autorrenta', 'autorreteica'])->delete();

        DB::statement(
            "ALTER TABLE retenciones_config MODIFY COLUMN tipo
             ENUM('retefuente','reteica','reteiva') NOT NULL"
        );
    }
};
