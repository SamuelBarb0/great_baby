<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retenciones automáticas al facturar.
 *
 * En una VENTA quien retiene es el cliente: si es agente de retención, paga
 * total − retenciones y lo retenido queda como saldo a favor de Great Baby
 * (1355). Las reglas de `retenciones_config` ya existían para compras; acá se
 * les agrega lo que falta para usarlas en ventas:
 *
 *   facturas_venta.retenciones     · total retenido, para que el saldo de
 *                                    cartera sea el neto que va a llegar
 *   retenciones_config.siigo_tax_id · el impuesto de retención en SIIGO, que
 *                                    va en `items[].taxes` de /v1/invoices
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('facturas_venta', 'retenciones')) {
            Schema::table('facturas_venta', function (Blueprint $t) {
                $t->decimal('retenciones', 14, 2)->default(0)->after('total');
            });
        }

        if (! Schema::hasColumn('retenciones_config', 'siigo_tax_id')) {
            Schema::table('retenciones_config', function (Blueprint $t) {
                $t->unsignedBigInteger('siigo_tax_id')->nullable()->after('cuenta_puc');
            });
        }
    }

    public function down(): void
    {
        Schema::table('facturas_venta', fn (Blueprint $t) => $t->dropColumn('retenciones'));
        Schema::table('retenciones_config', fn (Blueprint $t) => $t->dropColumn('siigo_tax_id'));
    }
};
