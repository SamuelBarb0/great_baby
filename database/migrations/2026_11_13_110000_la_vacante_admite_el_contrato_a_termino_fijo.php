<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La vacante tiene que admitir el contrato a término fijo.
 *
 * `rrhh_vacantes.tipo_contrato` nació con cinco valores que no son los que el
 * formulario de requisición ofrece. De los cinco que pide el área sólo dos
 * existían acá, así que abrir una vacante a término fijo, de aprendiz SENA o
 * de prestación de servicios hacía que MySQL rechazara el enum y el servidor
 * respondiera 500 — sin que la pantalla dijera nada.
 *
 * Dos de los tres eran ortografía y se resuelven traduciendo —`aprendiz` va a
 * `aprendizaje`, `prestacion` a `prestacion_servicios`, ver `TipoContrato`—.
 * El tercero no: `fijo` no tenía equivalente. El `temporal` que ya estaba es
 * OTRO contrato —el de empresa de servicios temporales— y usarlo habría
 * rotulado mal el contrato de la persona en su propia ficha. Así que se
 * agrega.
 *
 * Es aditivo: ninguna fila existente cambia de valor y las que ya estaban
 * siguen siendo válidas.
 */
return new class extends Migration
{
    private const CON_FIJO = "'indefinido','fijo','obra_labor','prestacion_servicios','aprendizaje','temporal'";

    private const SIN_FIJO = "'indefinido','obra_labor','prestacion_servicios','aprendizaje','temporal'";

    public function up(): void
    {
        DB::statement(
            'ALTER TABLE rrhh_vacantes MODIFY COLUMN tipo_contrato '
            ."ENUM(".self::CON_FIJO.") NOT NULL DEFAULT 'indefinido'"
        );
    }

    public function down(): void
    {
        /*
         * Antes de quitar el valor hay que vaciar las filas que lo usan, o el
         * ALTER las convierte en cadena vacía sin avisar. Se pasan a
         * `temporal`, que es lo más cercano que queda.
         */
        DB::table('rrhh_vacantes')->where('tipo_contrato', 'fijo')->update(['tipo_contrato' => 'temporal']);

        DB::statement(
            'ALTER TABLE rrhh_vacantes MODIFY COLUMN tipo_contrato '
            ."ENUM(".self::SIN_FIJO.") NOT NULL DEFAULT 'indefinido'"
        );
    }
};
