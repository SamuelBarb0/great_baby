<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 27-nov-2026: «que aparezcan todos simplemente con el número del pedido». La
 * guía provisional de los pedidos que Dropi aún no despacha deja de llevar el
 * prefijo SIN-GUIA-: pasa a ser el número de orden a secas. Si ese número ya
 * fuera la guía de otro pedido (la guía es única), ese pedido se deja como
 * estaba y queda en el log; el código reconoce los dos formatos.
 */
return new class extends Migration
{
    private const PREFIJO = 'SIN-GUIA-';

    public function up(): void
    {
        $saltados = [];

        DB::table('dropi_pedidos')
            ->where('guia', 'like', self::PREFIJO . '%')
            ->whereNotNull('dropi_orden_id')
            ->orderBy('id')
            ->select(['id', 'guia', 'dropi_orden_id'])
            ->chunkById(500, function ($pedidos) use (&$saltados) {
                foreach ($pedidos as $p) {
                    $nueva = (string) $p->dropi_orden_id;
                    if ($p->guia !== self::PREFIJO . $nueva || DB::table('dropi_pedidos')->where('guia', $nueva)->exists()) {
                        $saltados[] = $p->id;

                        continue;
                    }
                    DB::table('dropi_pedidos')->where('id', $p->id)->update(['guia' => $nueva]);
                }
            });

        if ($saltados) {
            Log::warning('Guía provisional sin prefijo: pedidos que se dejaron con SIN-GUIA-', ['pedido_ids' => $saltados]);
        }
    }

    public function down(): void
    {
        DB::table('dropi_pedidos')
            ->whereNotNull('dropi_orden_id')
            ->whereColumn('guia', 'dropi_orden_id')
            ->update(['guia' => DB::raw("CONCAT('" . self::PREFIJO . "', dropi_orden_id)")]);
    }
};
