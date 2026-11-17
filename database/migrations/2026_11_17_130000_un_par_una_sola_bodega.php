<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un par (oficina, almacén) = una sola bodega.
 *
 * El daño que evita es el más caro de todo este cambio: dos filas de
 * `inventario_ubicaciones` que significan lo mismo, con la mitad del stock
 * cada una. No se ve en ninguna pantalla —las dos se llaman parecido y las dos
 * tienen movimientos— y aparece el día que el alistador no encuentra
 * mercancía que el sistema dice tener.
 *
 * Había dos caminos para llegar ahí: `buscar()` ignoraba las bodegas apagadas
 * (ya corregido) y `mapear` sólo revisaba el choque en el momento de guardar,
 * que no sirve si dos personas guardan a la vez. El índice lo cierra en la
 * base, que es donde no se puede esquivar.
 *
 * En MySQL un índice único NO choca entre NULLs, así que esto deja convivir
 * sin problema todas las ubicaciones sin par: los racks (que lo heredan de su
 * bodega), los 43 espejos de SIIGO y cualquier bodega todavía sin mapear.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Si ya hubiera duplicados, el índice no entra y tumbaría el
        // despliegue. Se avisa y se sigue: hay que unirlos a mano, porque
        // decidir cuál sobrevive y a dónde se mueve el stock no lo puede
        // resolver una migración.
        $duplicados = \DB::table('inventario_ubicaciones')
            ->whereNotNull('oficina_id')
            ->whereNotNull('almacen_id')
            ->selectRaw('oficina_id, almacen_id, COUNT(*) as c')
            ->groupBy('oficina_id', 'almacen_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicados->isNotEmpty()) {
            \Illuminate\Support\Facades\Log::warning(
                'No se pudo crear el índice único del par oficina/almacén: hay bodegas duplicadas.',
                ['pares' => $duplicados->toArray()]
            );

            return;
        }

        Schema::table('inventario_ubicaciones', function (Blueprint $t) {
            $t->unique(['oficina_id', 'almacen_id'], 'ubic_par_unico');
        });
    }

    public function down(): void
    {
        Schema::table('inventario_ubicaciones', function (Blueprint $t) {
            $t->dropUnique('ubic_par_unico');
        });
    }
};
