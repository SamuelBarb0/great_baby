<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una carga a Dropi puede registrar primero la misma mercancía en el
 * inventario del ERP (carga CI- con su asiento) y después mandarla a Dropi.
 * Sin esto Dropi subía de stock pero el kardex y el libro no se enteraban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_cargas_productos', function (Blueprint $t) {
            // {activo, motivo, observacion, soporte, tercero_id, ubicacion_id,
            //  resultado: {creados, actualizados, movimientos, omitidos, sin_costo, filas: {ref: estado}}}
            $t->json('ingreso_erp')->nullable()->after('defaults_crear');
            $t->foreignId('carga_inventario_id')->nullable()->after('ingreso_erp')
                ->constrained('cargas_inventario')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dropi_cargas_productos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('carga_inventario_id');
            $t->dropColumn('ingreso_erp');
        });
    }
};
