<?php

/*
 * Las novedades que quedaron sin la casilla de días.
 *
 * El cuadro pide tres cosas para medir una novedad: días, horas, o las fechas.
 * La casilla de días era opcional y nada la deducía, así que una incapacidad
 * del 5 al 9 se podía guardar con esa casilla en blanco. Y en blanco pesaba
 * CERO en los dos números que salen de ahí:
 *
 *   · el ausentismo que se lleva a la Junta Directiva —«Enfermedad general:
 *     0 día(s)» con ocho días de incapacidad cargados—, y
 *   · el descuento del auxilio de transporte, que se calcula sobre ese mismo
 *     número.
 *
 * Desde ahora la pantalla deduce los días de las fechas y exige una medida si
 * no hay forma de deducirla, así que no vuelven a entrar en blanco. Esto es
 * para las que ya entraron.
 *
 * ── Qué NO toca ─────────────────────────────────────────────────────────────
 *
 * El descuento. Los días son un dato objetivo que estaba faltando y se deduce
 * de la misma fila; el descuento es plata que ya se consignó o no, y recalcular
 * el soporte de un pago que ya salió es justo lo que la regla nueva del corte
 * cerrado prohíbe. Donde la cuenta no cuadre, el cuadro ahora lo dice al lado
 * del número en vez de corregirlo solo.
 *
 * Sólo toca las filas donde no hay NINGUNA medida y sí hay las dos fechas.
 * Si alguien escribió los días a mano —aunque no coincidan con las fechas,
 * que pasa cuando parte de la incapacidad cae en el corte anterior— eso manda.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sinMedida = DB::table('rrhh_novedades')
            ->whereNull('dias')
            ->whereNull('horas')
            ->whereNotNull('fecha_inicio')
            ->whereNotNull('fecha_fin')
            ->get(['id', 'fecha_inicio', 'fecha_fin']);

        foreach ($sinMedida as $n) {
            $dias = Carbon::parse($n->fecha_inicio)
                ->diffInDays(Carbon::parse($n->fecha_fin)) + 1;

            DB::table('rrhh_novedades')
                ->where('id', $n->id)
                ->update(['dias' => max(1, (int) $dias)]);
        }
    }

    public function down(): void
    {
        /*
         * No se devuelve.
         *
         * Vaciar la casilla otra vez sería volver a poner el cero que no se
         * distingue de «no faltó», y no hay forma de saber cuáles filas eran
         * las que estaban en blanco antes de esto.
         */
    }
};
