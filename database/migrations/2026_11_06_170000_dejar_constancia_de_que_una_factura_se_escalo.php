<?php

/*
 * Una factura de 120+ días no escalaba a ningún lado.
 *
 * El contrato pide «semáforo de cartera por tramos y por vendedor, con
 * escalamiento a Gerencia en 120+». Los tramos existían desde hacía meses
 * —`TramoAntiguedad` con los cinco del contrato— y el escalamiento no existía
 * en ninguna parte: una factura podía cruzar los 120 días sin que nadie se
 * enterara, salvo que alguien abriera el reporte.
 *
 * Una columna basta. Se guarda CUÁNDO se escaló, no un sí/no, por dos
 * razones: para no volver a avisar todos los días de la misma factura, y para
 * poder responder después hace cuánto se sabía.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas_venta', function (Blueprint $t) {
            if (! Schema::hasColumn('facturas_venta', 'escalada_gerencia_at')) {
                $t->timestamp('escalada_gerencia_at')->nullable()->index()
                    ->comment('Cuándo se le avisó a Gerencia de esta cartera vencida');
            }
        });
    }

    public function down(): void
    {
        Schema::table('facturas_venta', function (Blueprint $t) {
            if (Schema::hasColumn('facturas_venta', 'escalada_gerencia_at')) {
                $t->dropColumn('escalada_gerencia_at');
            }
        });
    }
};
