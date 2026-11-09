<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los contenedores llevan su línea de negocio (8-nov).
 *
 * Sin columna, el asiento de la liquidación se deducía del módulo del
 * documento (Compras) y caía siempre en Great Baby: un contenedor de Dropi
 * movía el 1465 y el 1435 de Great Baby. `MovimientoContable` ya lee la línea
 * del documento cuando éste la trae. Los contenedores existentes quedan en
 * Great Baby, que es donde ya estaban sus asientos y sus órdenes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('compras_importaciones', 'linea_negocio')) {
            Schema::table('compras_importaciones', function (Blueprint $t) {
                $t->string('linea_negocio', 20)->default('great_baby')->index()->after('numero');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('compras_importaciones', 'linea_negocio')) {
            Schema::table('compras_importaciones', function (Blueprint $t) {
                $t->dropIndex(['linea_negocio']);
                $t->dropColumn('linea_negocio');
            });
        }
    }
};
