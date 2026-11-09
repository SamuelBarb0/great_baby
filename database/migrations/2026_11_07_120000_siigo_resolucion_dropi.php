<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dropi factura con Great Baby y su propia resolución DIAN.
 *
 * Decidido el 7-nov con el jefe de Samuel: Great Baby, los puntos de venta y
 * el e-commerce son LA MISMA empresa (un NIT, una cuenta de SIIGO); lo que
 * cambia es la resolución, y SIIGO separa la contabilidad por ella. El
 * multiempresa del 23-oct había puesto lo de Dropi en otra razón social
 * («ecommerce») con su propia cuenta de SIIGO.
 *
 * - `tipo_documento_dropi_id`: el Document Type ID de la resolución de Dropi
 *   en la cuenta de Great Baby. Vacío = las facturas de Dropi no van a SIIGO.
 * - La regla `dropi.empresa` pasa de «ecommerce» a la empresa principal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('siigo_config', function (Blueprint $t) {
            if (! Schema::hasColumn('siigo_config', 'tipo_documento_dropi_id')) {
                $t->unsignedInteger('tipo_documento_dropi_id')->nullable()->after('tipo_documento_id');
            }
        });

        if (Schema::hasTable('reglas_negocio')) {
            DB::table('reglas_negocio')
                ->where('clave', 'dropi.empresa')
                ->where('valor', 'ecommerce')
                ->update(['valor' => 'great_baby', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('reglas_negocio')) {
            DB::table('reglas_negocio')
                ->where('clave', 'dropi.empresa')
                ->where('valor', 'great_baby')
                ->update(['valor' => 'ecommerce', 'updated_at' => now()]);
        }

        Schema::table('siigo_config', function (Blueprint $t) {
            if (Schema::hasColumn('siigo_config', 'tipo_documento_dropi_id')) {
                $t->dropColumn('tipo_documento_dropi_id');
            }
        });
    }
};
