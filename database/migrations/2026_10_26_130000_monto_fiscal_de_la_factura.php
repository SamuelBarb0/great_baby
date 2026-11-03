<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto de la factura se declara, cuando no se declara toda.
 *
 * El caso que planteó Yeniffer: una venta real de $100 M de la que el cliente
 * sólo acepta factura electrónica por $20 M. El ERP conserva los $100 M —es lo
 * que mueve inventario, lo que el cliente debe y sobre lo que comisiona el
 * vendedor— y a SIIGO se le manda la porción que sí se declara.
 *
 * `monto_fiscal` sólo tiene sentido cuando `destino_fiscal = reparto`:
 *   · fiscal   → se declara todo, monto_fiscal queda null
 *   · interno  → no se declara nada, monto_fiscal queda null
 *   · reparto  → monto_fiscal dice cuánto, y el resto queda interno
 *
 * `motivo_destino` guarda POR QUÉ. Sin eso, dentro de tres meses nadie puede
 * explicar por qué esa factura salió por menos — y es justo la que van a
 * preguntar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas_venta', function (Blueprint $t) {
            if (! Schema::hasColumn('facturas_venta', 'monto_fiscal')) {
                $t->decimal('monto_fiscal', 14, 2)->nullable()->after('destino_fiscal')
                    ->comment('Sólo en reparto · cuánto del total se declara en SIIGO');
            }
            if (! Schema::hasColumn('facturas_venta', 'motivo_destino')) {
                $t->string('motivo_destino', 300)->nullable()->after('monto_fiscal')
                    ->comment('Por qué esta factura no se declara completa');
            }
        });
    }

    public function down(): void
    {
        Schema::table('facturas_venta', function (Blueprint $t) {
            foreach (['monto_fiscal', 'motivo_destino'] as $col) {
                if (Schema::hasColumn('facturas_venta', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
