<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que le faltaba al gasto para poder asentarse.
 *
 * `gastos_operativos` ya tenía los dos ejes desde la migración de octubre, pero
 * el modelo no los casteaba y nadie los leía. Le faltaban dos cosas para que el
 * gasto pueda generar un asiento de verdad:
 *
 *   · `cuenta_puc` — contra qué cuenta de gasto se debita. La categoría es
 *     texto libre («papelería», «arriendo»), así que no sirve para elegir una
 *     cuenta: la pone el contador, y si la deja vacía se usa la cuenta por
 *     defecto de la regla `contable.cta_gasto_default`.
 *
 *   · `motivo_destino` — por qué un gasto no se declara, igual que en facturas
 *     y compras. Sin esto, dentro de tres meses nadie sabe si fue una decisión
 *     o un error de dedo.
 *
 * No se toca `estado`: el flujo pendiente → aprobado → pagado ya existe y es el
 * que marca cuándo se causa y cuándo se paga.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gastos_operativos')) {
            return;
        }

        Schema::table('gastos_operativos', function (Blueprint $t) {
            if (! Schema::hasColumn('gastos_operativos', 'cuenta_puc')) {
                $t->string('cuenta_puc', 20)->nullable()->index()
                    ->comment('Cuenta de gasto a debitar. Vacía = la de la regla contable.cta_gasto_default');
            }
            if (! Schema::hasColumn('gastos_operativos', 'motivo_destino')) {
                $t->string('motivo_destino', 300)->nullable()
                    ->comment('Por qué el gasto no se declara. Obligatorio al sacarlo de lo fiscal.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('gastos_operativos')) {
            return;
        }

        Schema::table('gastos_operativos', function (Blueprint $t) {
            foreach (['cuenta_puc', 'motivo_destino'] as $col) {
                if (Schema::hasColumn('gastos_operativos', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
