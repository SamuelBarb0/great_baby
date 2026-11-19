<?php

/*
 * Una garantía le cuesta a la empresa, aunque el cliente no pague nada.
 *
 * El ticket ya guardaba `valor_reposicion`, y el flujo lo pone en CERO con el
 * comentario «contrato: reposición a valor $0». Eso es correcto —en una
 * garantía el cliente no paga—, pero es lo que cobra la empresa, no lo que le
 * cuesta. Resultado: el módulo de garantías no sabía cuánto cuesta la
 * posventa, y por lo tanto nadie podía responder cuál referencia la genera.
 *
 * Una garantía cuesta por tres lados, y los tres se pagan por separado:
 *
 *   · el producto que se repone, que sale del inventario al costo;
 *   · los fletes, que normalmente son DOS —recoger lo fallado y mandar el
 *     reemplazo— y se cobran aunque después se rechace la garantía;
 *   · la nota crédito, cuando en vez de reponer se le devuelve la plata.
 *
 * Se guardan separados y no como un total, porque la pregunta que importa no
 * es «cuánto gastamos» sino «en qué»: si el 70% es flete, el problema es la
 * logística inversa; si es producto, el problema es el producto.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNAS = [
        'costo_producto' => 'Costo del producto repuesto, al costo de inventario',
        'costo_flete_recoger' => 'Flete de recoger lo fallado',
        'costo_flete_enviar' => 'Flete de mandar el reemplazo',
        'costo_nota_credito' => 'Plata devuelta al cliente en vez de reponer',
        'costo_otros' => 'Reparación, peritaje, lo que no entra en los otros',
    ];

    public function up(): void
    {
        Schema::table('garantia_tickets', function (Blueprint $t) {
            foreach (self::COLUMNAS as $col => $comentario) {
                if (! Schema::hasColumn('garantia_tickets', $col)) {
                    $t->decimal($col, 14, 2)->default(0)->comment($comentario);
                }
            }

            if (! Schema::hasColumn('garantia_tickets', 'costo_nota')) {
                $t->string('costo_nota', 255)->nullable()
                    ->comment('En qué se fue la plata, cuando el desglose no alcanza');
            }

            /*
             * Cuándo se cargó el costo, que no es lo mismo que cuándo se cerró
             * el ticket: el flete llega facturado semanas después. Sin esta
             * fecha, un informe mensual de costo de garantías cambiaría solo
             * cada vez que alguien actualiza un ticket viejo.
             */
            if (! Schema::hasColumn('garantia_tickets', 'costo_cargado_at')) {
                $t->timestamp('costo_cargado_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('garantia_tickets', function (Blueprint $t) {
            foreach (array_merge(array_keys(self::COLUMNAS), ['costo_nota', 'costo_cargado_at']) as $col) {
                if (Schema::hasColumn('garantia_tickets', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
