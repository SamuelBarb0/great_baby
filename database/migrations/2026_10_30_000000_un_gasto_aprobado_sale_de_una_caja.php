<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un gasto que se paga en efectivo sale de UNA caja, no de «la caja».
 *
 * El ciclo de `gastos_operativos` ya estaba completo —cualquiera solicita,
 * Gerencia aprueba y se causa, Contabilidad marca pagado y se cancela el
 * pasivo— pero el pago en efectivo golpeaba la cuenta contable de caja por
 * defecto, sin decir de cuál cajón salió la plata.
 *
 * Eso rompe el arqueo: la persona cuenta los billetes al final del día, le
 * faltan los $395.000 que se le entregaron a Olinto, y en la pantalla del día
 * no hay nada que lo explique porque el gasto vivía en otro módulo. Con
 * `caja_id` el gasto aprobado aterriza en el día de la caja que lo pagó y el
 * conteo cuadra solo.
 *
 * Es nullable: un gasto pagado por transferencia no sale de ninguna caja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gastos_operativos', function (Blueprint $table) {
            $table->foreignId('caja_id')->nullable()->after('metodo_pago')
                ->constrained('cajas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gastos_operativos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caja_id');
        });
    }
};
