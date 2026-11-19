<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F1 · Los dos ejes de la contabilidad.
 *
 * Hasta acá `movimientos_contables` no tenía NINGUNA columna que dijera a qué
 * libro pertenece un asiento. Great Baby y Dropi caían en la misma pila, y la
 * única marca relacionada con SIIGO (`siigo_journal_id`) guarda el RESULTADO
 * —si ya se envió— no la DECISIÓN de si debe enviarse. Por eso «pendiente de
 * SIIGO» estaba definido como `siigo_id IS NULL` y lo que alguien decidiera no
 * mandar quedaba apareciendo como pendiente para siempre.
 *
 * Son dos ejes independientes, no uno:
 *   · linea_negocio   great_baby | dropi
 *   · destino_fiscal  siigo | solo_erp | bolsita
 *
 * Así se puede sacar el balance de Great Baby solo, el de Dropi solo o el
 * consolidado, y en cada uno decidir si entra o no la bolsita.
 *
 * El backfill deja todo como estaba funcionando hoy: la línea se deduce del
 * `origen_type` (que es de dónde salía la verdad hasta ahora) y el destino
 * queda en `siigo`, que es lo que el sistema asumía de todo.
 */
return new class extends Migration
{
    /** Documentos que originan asientos y donde alguien decide el destino. */
    private const TABLAS = [
        'movimientos_contables',
        'facturas_venta',
        'gastos_operativos',
        'compras_ordenes',
    ];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla)) continue;

            Schema::table($tabla, function (Blueprint $t) use ($tabla) {
                if (! Schema::hasColumn($tabla, 'linea_negocio')) {
                    $t->string('linea_negocio', 20)->default('great_baby')->index()
                        ->comment('Eje 1 · de quién es: great_baby | dropi');
                }
                if (! Schema::hasColumn($tabla, 'destino_fiscal')) {
                    $t->string('destino_fiscal', 20)->default('siigo')->index()
                        ->comment('Eje 2 · a dónde va: siigo | solo_erp | bolsita');
                }
            });
        }

        // Backfill de la línea en lo que ya existe. El destino no se toca:
        // el default `siigo` ya reproduce el comportamiento de hoy.
        if (Schema::hasTable('movimientos_contables')) {
            DB::table('movimientos_contables')
                ->where('origen_type', 'like', 'App\\\\Modules\\\\Dropi\\\\%')
                ->update(['linea_negocio' => 'dropi']);
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla)) continue;

            Schema::table($tabla, function (Blueprint $t) use ($tabla) {
                foreach (['linea_negocio', 'destino_fiscal'] as $col) {
                    if (Schema::hasColumn($tabla, $col)) {
                        $t->dropIndex([$col]);
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};
