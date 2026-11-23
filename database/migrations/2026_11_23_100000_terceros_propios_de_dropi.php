<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes y proveedores propios de Dropi (23-nov-2026).
 *
 * «Legalmente es la misma empresa pero internamente es otra»: Dropi tiene sus
 * propios proveedores y clientes, y no deben revolverse con los del ERP.
 *
 * Un tercero sigue siendo UNA ficha por número de documento (la columna es
 * única y así lo exige la DIAN), pero ahora dice en qué lado se usa:
 *
 *   en_erp    lo ve el ERP (/app). Todo lo que ya existía, por defecto.
 *   en_dropi  lo ve Dropi (/admin).
 *
 * Uno que crea Dropi nace sólo en Dropi; si Dropi «crea» un NIT que el ERP ya
 * tenía, se comparte la ficha (queda en los dos) y no se duplica.
 *
 * Backfill: quedan en Dropi los proveedores de las compras de Dropi y el
 * tercero «Dropi» de la wallet. Todos siguen en el ERP: nada desaparece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contactos', function (Blueprint $t) {
            if (! Schema::hasColumn('contactos', 'en_erp')) {
                $t->boolean('en_erp')->default(true)->index();
            }
            if (! Schema::hasColumn('contactos', 'en_dropi')) {
                $t->boolean('en_dropi')->default(false)->index();
            }
        });

        if (Schema::hasTable('compras_ordenes') && Schema::hasColumn('compras_ordenes', 'linea_negocio')) {
            DB::table('contactos')->whereIn('id',
                DB::table('compras_ordenes')->where('linea_negocio', 'dropi')->whereNotNull('proveedor_id')->select('proveedor_id')
            )->update(['en_dropi' => true]);
        }

        $nit = preg_replace('/\D/', '', (string) DB::table('reglas_negocio')->where('clave', 'dropi.nit_tercero')->value('valor'));
        if ($nit !== '') {
            DB::table('contactos')->where('numero_documento', $nit)->update(['en_dropi' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('contactos', function (Blueprint $t) {
            $t->dropColumn(['en_erp', 'en_dropi']);
        });
    }
};
