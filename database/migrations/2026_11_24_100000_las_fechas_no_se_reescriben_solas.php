<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cinco columnas de fecha se reescribían solas en cada UPDATE.
 *
 * `$table->timestamp('x')` NOT NULL, siendo el primer TIMESTAMP de la tabla,
 * en MySQL/MariaDB sin `explicit_defaults_for_timestamp` queda como
 * `DEFAULT current_timestamp() ON UPDATE current_timestamp()`. Así se crearon
 * en producción. Cualquier cambio a la fila —un escaneo, la foto, confirmar—
 * pisaba la fecha con la hora del servidor (UTC), mientras el ERP escribe en
 * hora de Bogotá: el inicio del empaque quedaba 5 h después del fin y la
 * estación mostraba «Mejor tiempo 299:35» (recorrido del 23-nov-2026).
 *
 * Se quita el ON UPDATE y se deja el DEFAULT. Los empaques ya dañados (inicio
 * después del fin) toman como inicio la hora en que se creó el registro, que
 * es cuando se abrió el pedido en la estación. En las otras tablas no hay de
 * dónde sacar la fecha original: sólo dejan de dañarse.
 */
return new class extends Migration
{
    private const COLUMNAS = [
        ['empaques_registro', 'inicio_at'],
        ['dropi_devoluciones', 'recibido_at'],
        ['garantia_tickets', 'plazo_concepto_at'],
        ['cobranzas_registro', 'enviado_at'],
        ['dropi_sanciones', 'detectada_at'],
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNAS as [$tabla, $columna]) {
            $c = DB::selectOne(
                'SELECT IS_NULLABLE nul, EXTRA extra, COLUMN_COMMENT comentario FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$tabla, $columna],
            );
            if (! $c || stripos((string) $c->extra, 'on update') === false) {
                continue;
            }

            $comentario = $c->comentario !== '' ? ' COMMENT ' . DB::getPdo()->quote($c->comentario) : '';
            DB::statement("ALTER TABLE `{$tabla}` MODIFY `{$columna}` TIMESTAMP "
                . ($c->nul === 'YES' ? 'NULL' : 'NOT NULL') . ' DEFAULT CURRENT_TIMESTAMP' . $comentario);
        }

        // Ya sin el ON UPDATE: este arreglo no vuelve a pisar la fecha.
        DB::table('empaques_registro')
            ->whereNotNull('fin_at')->whereNotNull('created_at')
            ->whereColumn('inicio_at', '>', 'fin_at')
            ->whereColumn('created_at', '<=', 'fin_at')
            ->update([
                'inicio_at' => DB::raw('created_at'),
                'duracion_segundos' => DB::raw('TIMESTAMPDIFF(SECOND, created_at, fin_at)'),
            ]);
    }

    public function down(): void
    {
        // No se devuelve el ON UPDATE: era el error.
    }
};
