<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multiempresa · una razón social por canal: Great Baby, Arsutex y la del
 * e-commerce (Dropi). Cada una es otra empresa ante la DIAN —su NIT, su
 * resolución, su prefijo— y tiene su propia cuenta de SIIGO.
 *
 * `empresa_config` deja de ser «la» fila y pasa a ser una por razón social;
 * la que ya existe queda como Great Baby y principal, así que todo lo que
 * hoy lee `EmpresaConfig::current()` sigue viendo lo mismo. `siigo_config`
 * igual: la fila existente queda colgada de Great Baby.
 *
 * Pedidos y facturas guardan su empresa. NULL = la principal, para no tener
 * que reescribir el histórico ni los flujos que todavía no la eligen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_config', function (Blueprint $table) {
            $table->string('codigo', 30)->nullable()->unique()->after('id')
                ->comment('great_baby | arsutex | ecommerce');
            $table->boolean('es_principal')->default(false)->after('codigo');
            $table->boolean('activa')->default(true)->after('es_principal')
                ->comment('Sólo las activas se ofrecen en pedidos y facturan');
        });

        $principal = DB::table('empresa_config')->orderBy('id')->value('id');
        if ($principal) {
            DB::table('empresa_config')->where('id', $principal)
                ->update(['codigo' => 'great_baby', 'es_principal' => true, 'activa' => true]);
        }

        // Nacen apagadas: hasta que alguien escriba su NIT, su resolución y sus
        // credenciales de SIIGO no pueden facturar nada.
        foreach ([
            ['codigo' => 'arsutex', 'razon_social' => 'ARSUTEX', 'nombre_comercial' => 'Arsutex'],
            ['codigo' => 'ecommerce', 'razon_social' => 'E-COMMERCE (por definir)', 'nombre_comercial' => 'E-commerce · Dropi'],
        ] as $e) {
            if (! DB::table('empresa_config')->where('codigo', $e['codigo'])->exists()) {
                DB::table('empresa_config')->insert($e + [
                    'es_principal' => false, 'activa' => false, 'nit' => '',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        Schema::table('siigo_config', function (Blueprint $table) {
            $table->foreignId('empresa_id')->nullable()->unique()->after('id')->constrained('empresa_config');
            // IDs de impuestos en ESTA cuenta de SIIGO. La principal sigue con
            // `impuestos.siigo_id` y `siigo_tax_id` de las retenciones; las
            // demás cuentas numeran sus impuestos distinto.
            //   {"iva": {"19": 1234}, "retenciones": {"<retencion_config_id>": 5678}}
            $table->json('mapa_impuestos')->nullable()->after('payment_type_id');
        });

        $siigo = DB::table('siigo_config')->orderBy('id')->value('id');
        if ($siigo && $principal) {
            DB::table('siigo_config')->where('id', $siigo)->update(['empresa_id' => $principal]);
        }

        Schema::table('pedidos_cliente', function (Blueprint $table) {
            $table->foreignId('empresa_id')->nullable()->after('id')->constrained('empresa_config');
        });
        Schema::table('facturas_venta', function (Blueprint $table) {
            $table->foreignId('empresa_id')->nullable()->after('id')->constrained('empresa_config');
        });
    }

    public function down(): void
    {
        Schema::table('facturas_venta', fn (Blueprint $t) => $t->dropConstrainedForeignId('empresa_id'));
        Schema::table('pedidos_cliente', fn (Blueprint $t) => $t->dropConstrainedForeignId('empresa_id'));
        Schema::table('siigo_config', function (Blueprint $t) {
            $t->dropConstrainedForeignId('empresa_id');
            $t->dropColumn('mapa_impuestos');
        });
        DB::table('empresa_config')->whereIn('codigo', ['arsutex', 'ecommerce'])->delete();
        Schema::table('empresa_config', function (Blueprint $t) {
            $t->dropUnique(['codigo']);
            $t->dropColumn(['codigo', 'es_principal', 'activa']);
        });
    }
};
