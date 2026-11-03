<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las dos puntas de un traslado se conocen entre sí.
 *
 * Pasar plata de la caja menor a la general son dos movimientos: una salida
 * acá y una entrada allá. Si no quedan amarrados, en el arqueo de cada caja
 * aparecen como un gasto y un ingreso sueltos, y a los dos meses nadie sabe
 * que eran el mismo hecho — ni puede detectar que una punta se registró y la
 * otra no, que es exactamente como se pierde plata en papel.
 *
 * Autorreferencia simple en vez de una tabla de traslados: un traslado ES sus
 * dos movimientos, y una tabla aparte obligaría a mantener tres cosas en
 * sincronía para no decir nada nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->foreignId('contraparte_id')->nullable()->after('arqueo_id')
                ->constrained('movimientos_caja')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contraparte_id');
        });
    }
};
