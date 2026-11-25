<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Los terceros de Dropi también en el ERP (señora Lahida, 25-nov-2026: «la idea
 * también es crearlo en el ERP… por eso es que no nos aparecía»).
 *
 * Del 23 al 25-nov un cliente o proveedor creado en Dropi nacía sólo allá y el
 * ERP no lo veía (contactos, compras, devoluciones, buscador, CRM). Los que
 * quedaron así pasan también al ERP. Los que se mueven quedan en el log: después
 * ya no se distinguen, por eso `down()` no los devuelve.
 */
return new class extends Migration
{
    public function up(): void
    {
        $soloDropi = DB::table('contactos')->where('en_dropi', true)->where('en_erp', false)
            ->get(['id', 'numero_documento', 'nombre_completo']);

        if ($soloDropi->isEmpty()) {
            return;
        }

        DB::table('contactos')->whereIn('id', $soloDropi->pluck('id'))->update(['en_erp' => true, 'updated_at' => now()]);

        Log::info('terceros_dropi.pasan_al_erp', ['contactos' => $soloDropi->map(fn ($c) => (array) $c)->all()]);
    }

    public function down(): void
    {
        // Sin vuelta atrás automática: la lista de los que se movieron está en el log.
    }
};
