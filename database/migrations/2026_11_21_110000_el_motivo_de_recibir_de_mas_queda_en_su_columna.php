<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por qué se recibió más de lo pedido, en su propia columna.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * EL PROBLEMA
 *
 * Cuando el proveedor manda de más, el área lo recibe y lo registra: «se recibe
 * y se registra en el packing list, dejando constancia de las cantidades
 * efectivamente recibidas» (respuesta del jefe de logística, 20-nov-2026).
 *
 * En una importación el sistema ya lo permite: pide el motivo y lo PEGA al
 * final de `observaciones`. Eso funciona para que quede dicho, pero no sirve
 * para nada más:
 *
 *   · no se puede listar «las recepciones que llegaron con exceso», porque
 *     habría que buscar una frase dentro de un texto libre;
 *   · si alguien edita las observaciones, el motivo se va con ellas;
 *   · y, sobre todo, `RecibirMercancia` no tiene cómo saber que el exceso fue
 *     autorizado. Por eso en compra NACIONAL está bloqueado de plano: la única
 *     forma de distinguir «llegó de más y lo aceptamos» de «alguien tecleó mal»
 *     era no permitir ninguno de los dos.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * QUÉ CAMBIA
 *
 * El motivo pasa a ser un dato. Con él, recibir de más deja de ser un privilegio
 * de la importación: cualquier recepción puede aceptar el exceso siempre que
 * alguien escriba POR QUÉ, y esa explicación queda pegada a la recepción, no
 * perdida dentro de un párrafo.
 *
 * Sin motivo, el exceso se sigue rechazando. Esa parte no se toca: un conteo
 * que supera lo pedido sin explicación es casi siempre un error de digitación,
 * y pasarlo en silencio descuadra la deuda con el proveedor.
 */
return new class extends Migration
{
    private const TABLA = 'compras_recepciones';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLA) || Schema::hasColumn(self::TABLA, 'motivo_exceso')) {
            return;
        }

        Schema::table(self::TABLA, function (Blueprint $t) {
            $t->string('motivo_exceso', 300)->nullable()->after('observaciones')
                ->comment('Por qué se aceptó recibir más de lo pedido. Vacío = no hubo exceso.');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLA) || ! Schema::hasColumn(self::TABLA, 'motivo_exceso')) {
            return;
        }

        Schema::table(self::TABLA, function (Blueprint $t) {
            $t->dropColumn('motivo_exceso');
        });
    }
};
