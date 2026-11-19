<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * En qué casilla de la zona de alistamiento se está armando cada pedido.
 *
 * El procedimiento del área separa el picking del packing con un paso propio:
 * «Consolidación — trasladar al punto de consolidación y agrupar la totalidad
 * de ítems por cada orden», con entrada «artículos de distintas zonas» y
 * responsable «operarios por zona».
 *
 * O sea: varias personas recogen a la vez por zonas distintas, todo baja a un
 * mismo punto, y ahí se reparte por pedido. Para que eso funcione cada pedido
 * en curso necesita un sitio físico donde ir juntándose, y don Jorge mandó el
 * plano de ese sitio: una cuadrícula de seis columnas por tres filas, que él
 * numera `C1FA` … `C6FC`.
 *
 * Esta columna guarda cuál le tocó. Es texto y no una tabla aparte porque la
 * cuadrícula es del piso, no del sistema: si mañana el área la agranda o la
 * renumera, no hay que migrar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_cliente', function (Blueprint $t) {
            $t->string('casilla_consolidacion', 10)->nullable()->after('alistado_fin_at');

            // Se busca por casilla para saber qué hay en cada una y para no
            // dar la misma dos veces.
            $t->index('casilla_consolidacion');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_cliente', function (Blueprint $t) {
            $t->dropIndex(['casilla_consolidacion']);
            $t->dropColumn('casilla_consolidacion');
        });
    }
};
