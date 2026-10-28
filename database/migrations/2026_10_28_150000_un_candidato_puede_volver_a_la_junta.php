<?php

/*
 * Una orden negada no puede ser la última palabra sobre una persona.
 *
 * `rrhh_ordenes_contratacion.candidato_id` era ÚNICO. En el camino feliz se
 * entiende —una persona, una autorización— pero convertía cada negativa en un
 * callejón sin salida:
 *
 *   · el candidato se queda en `seleccionado`, que `esFinal()` considera una
 *     etapa de la que ya no se sale;
 *   · contratarlo es imposible, porque la orden no autoriza;
 *   · y pedir una orden nueva también, porque el índice único no deja crear
 *     la segunda fila. `solicitar()` encontraba la negada, no hacía nada, y
 *     redirigía diciendo «Orden ODC-2026-0001 enviada a la Junta Directiva»
 *     —un mensaje de éxito sobre algo que no ocurrió y que nadie recibió.
 *
 * Y es justo el camino que el propio módulo da por supuesto: al exigir motivo
 * en la negativa, el código dice que es para que «Gestión Humana sepa si
 * insistir, bajar el salario o cerrar la vacante». Insistir era imposible.
 *
 * Se cambia por un índice normal. Cada presentación a la Junta pasa a ser su
 * propio documento con su propio consecutivo: ODC-2026-0001 negada y
 * ODC-2026-0004 autorizada conviven, que es como funciona el papel. Lo que la
 * Junta firmó queda tal cual se firmó, en vez de reescribirse encima.
 *
 * Quien lee la orden vigente de alguien debe tomar la ÚLTIMA, no la primera.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * El índice nuevo va PRIMERO.
         *
         * La llave foránea a `rrhh_candidatos` se estaba apoyando en el índice
         * único para existir, y MySQL no deja quitarle el último índice a una
         * columna con FK: «Cannot drop index … needed in a foreign key
         * constraint». Creado el normal, la FK se apoya en él y el único se
         * suelta sin tocar la restricción.
         */
        Schema::table('rrhh_ordenes_contratacion', function (Blueprint $t) {
            $t->index('candidato_id', 'rrhh_ordenes_contratacion_candidato_id_index');
        });

        Schema::table('rrhh_ordenes_contratacion', function (Blueprint $t) {
            $t->dropUnique('rrhh_ordenes_contratacion_candidato_id_unique');
        });
    }

    public function down(): void
    {
        /*
         * Volver atrás sólo es posible si no quedaron dos órdenes de la misma
         * persona. Si las hay, restaurar el único reventaría a mitad de la
         * migración y dejaría la tabla sin ninguno de los dos índices; es
         * preferible dejar el índice normal y decirlo.
         */
        $repetidos = \Illuminate\Support\Facades\DB::table('rrhh_ordenes_contratacion')
            ->selectRaw('candidato_id')
            ->groupBy('candidato_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($repetidos) {
            return;
        }

        // Mismo cuidado que en `up()`, al revés: primero el que queda.
        Schema::table('rrhh_ordenes_contratacion', function (Blueprint $t) {
            $t->unique('candidato_id', 'rrhh_ordenes_contratacion_candidato_id_unique');
        });

        Schema::table('rrhh_ordenes_contratacion', function (Blueprint $t) {
            $t->dropIndex('rrhh_ordenes_contratacion_candidato_id_index');
        });
    }
};
