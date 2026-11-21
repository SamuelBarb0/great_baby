<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * El motivo de un traslado cabe en su columna.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * EL ERROR
 *
 * `InventarioGestionController::trasladoCrear` valida
 * `'motivo' => ['required', 'string', 'max:200']` y pasa `$data` entero a
 * `Traslado::create()`. La columna es `varchar(40)`.
 *
 * O sea: quien escribe un motivo de entre 41 y 200 caracteres —«Reubicación
 * de la mercancía que quedó en la zona de parqueadero tras el conteo», que
 * son 72— pasa la validación y revienta contra la base:
 *
 *     SQLSTATE[22001]: String data, right truncated: 1406
 *     Data too long for column 'motivo'
 *
 * Que para la persona es una pantalla de error 500 después de llenar el
 * formulario. Y es una explicación razonable, no un abuso: cuarenta
 * caracteres no alcanzan para decir por qué se mueve una mercancía.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * POR QUÉ SE ANCHA LA COLUMNA Y NO SE RECORTA LA VALIDACIÓN
 *
 * Recortar a `max:40` haría que el formulario rechace de frente, sin error
 * 500 — pero le estaría pidiendo a la bodega que explique un movimiento de
 * inventario en cuarenta caracteres. La intención del código ya estaba
 * escrita en la validación: doscientos. La base es la que se quedó atrás.
 *
 * Para lo verdaderamente largo ya existe `observaciones`, que es `text`.
 * ───────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    private const TABLA = 'traslados_inventario';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLA) || ! Schema::hasColumn(self::TABLA, 'motivo')) {
            return;
        }

        // Sin `change()` de Doctrine: un ALTER directo no necesita el paquete
        // y no toca ningún otro atributo de la columna.
        DB::statement('ALTER TABLE `'.self::TABLA.'` MODIFY `motivo` VARCHAR(200) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLA) || ! Schema::hasColumn(self::TABLA, 'motivo')) {
            return;
        }

        /*
         * Volver a 40 cortaría los motivos que se hayan escrito más largos, y
         * un `down()` no está autorizado a borrar lo que alguien escribió. Si
         * hay alguno que no cabe, se avisa y la columna se queda ancha.
         */
        $largos = DB::table(self::TABLA)->whereRaw('CHAR_LENGTH(motivo) > 40')->count();

        if ($largos > 0) {
            Log::warning(
                'No se angosta `traslados_inventario.motivo` a 40: hay motivos más largos que se perderían.',
                ['cuantos' => $largos],
            );

            return;
        }

        DB::statement('ALTER TABLE `'.self::TABLA.'` MODIFY `motivo` VARCHAR(40) NULL');
    }
};
