<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MKT-6 · De dónde llegó cada cliente (WhatsApp, página, referido…).
 *
 * El cliente preguntó «¿se puede mirar la fuente del lead?» y el CRM no la
 * guardaba en ninguna parte. Va en el contacto y además se copia al pedido
 * al crearlo: así el reporte por fuente no cambia si alguien corrige después
 * la ficha del contacto, y cada pedido queda ligado al canal que lo trajo.
 *
 * Los contactos que ya existen quedan sin fuente («Sin registrar»): no hay
 * de dónde deducirla y adivinar ensuciaría el reporte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contactos', function (Blueprint $t) {
            $t->string('fuente', 30)->nullable()->after('activo')->index();
            $t->string('fuente_detalle', 120)->nullable()->after('fuente');
        });

        Schema::table('pedidos_cliente', function (Blueprint $t) {
            $t->string('fuente', 30)->nullable()->after('contacto_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_cliente', function (Blueprint $t) {
            $t->dropIndex(['fuente']);
            $t->dropColumn('fuente');
        });

        Schema::table('contactos', function (Blueprint $t) {
            $t->dropIndex(['fuente']);
            $t->dropColumn(['fuente', 'fuente_detalle']);
        });
    }
};
