<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que le faltaba a `cajas` para parecerse a cómo trabajan.
 *
 * La tabla nació el 2026-09-05 como «TO-BE Contabilidad P1» con lo mínimo
 * —código, nombre, responsable, cuenta PUC— y nunca se construyó encima. Al
 * levantar el requisito con Aracely quedó claro que faltan dos cosas, y que
 * no son adorno:
 *
 *  · `tipo` · «hay cajas que las dejamos así, o varios bancos que siempre
 *    tienen ahí hasta que se retira el dinero, pero hay una caja que se
 *    mueve, que es la caja menor, esa es la caja del día». Una caja menor no
 *    es una caja general con otro nombre: se arquea a diario, se cierra y se
 *    vacía. El banco ni siquiera se cuenta a mano.
 *
 *  · `caja_destino_cierre_id` · «siempre que se cierra se va a una caja
 *    cualquiera, normalmente la general, pero puede ser que se vaya a otra».
 *    Si el destino estuviera quemado en el código, abrir una caja en Bogotá
 *    obligaría a tocar el programa. Acá se elige y ya.
 *
 * `cuenta_puc` se queda como está: su default es '1105', que es agrupadora y
 * no admite movimiento. No se cambia en silencio porque la cuenta correcta la
 * define Silvia; la pantalla sólo ofrece cuentas que admiten movimiento, que
 * es donde se corrige sin adivinar por ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->string('tipo', 20)->default('general')->after('nombre')
                ->comment('menor|general|banco');

            // Autorreferencia: la caja menor apunta a dónde deja su saldo al
            // cerrar. Nula en las que no se cierran contra nadie.
            $table->foreignId('caja_destino_cierre_id')->nullable()->after('tipo')
                ->constrained('cajas')->nullOnDelete();

            $table->index(['tipo', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropIndex(['tipo', 'activa']);
            $table->dropConstrainedForeignId('caja_destino_cierre_id');
            $table->dropColumn('tipo');
        });
    }
};
