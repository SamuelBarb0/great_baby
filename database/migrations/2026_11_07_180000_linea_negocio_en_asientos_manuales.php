<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los asientos manuales llevan su línea de negocio (7-nov).
 *
 * Sin columna, sus líneas en el libro se deducían del módulo del documento
 * (Contabilidad) y caían siempre en Great Baby: un ajuste de Dropi ensuciaba
 * el balance de Great Baby. `MovimientoContable` ya lee la línea del documento
 * cuando éste la trae. Los asientos existentes quedan en Great Baby, que es
 * donde ya estaban sus líneas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asientos_manuales', 'linea_negocio')) {
            Schema::table('asientos_manuales', function (Blueprint $t) {
                $t->string('linea_negocio', 20)->default('great_baby')->index()->after('glosa');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('asientos_manuales', 'linea_negocio')) {
            Schema::table('asientos_manuales', function (Blueprint $t) {
                $t->dropIndex(['linea_negocio']);
                $t->dropColumn('linea_negocio');
            });
        }
    }
};
