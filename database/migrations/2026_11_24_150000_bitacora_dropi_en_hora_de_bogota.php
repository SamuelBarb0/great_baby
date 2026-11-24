<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La bitácora de estados de Dropi guardaba la hora de MySQL (DEFAULT
 * CURRENT_TIMESTAMP), y en producción MySQL corre en UTC mientras la app corre
 * en Bogotá: cada fila quedó 5 h adelantada (24-nov-2026: un pedido liberado a
 * las 14:20 aparecía a las 19:20). Desde ahora la hora la pone PHP (ver
 * DropiEstadoBitacora); esta migración corrige las filas viejas.
 *
 * El desfase se mide en el momento: donde MySQL y PHP ya están en la misma zona
 * (local, pruebas) no cambia nada.
 *
 * Las filas que se escriban entre el `git pull` y este `migrate` ya traen la
 * hora de PHP y quedarían corridas; por eso se corre seguido del pull.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::selectOne('SELECT NOW() AS ahora')->ahora;
        $minutos = (int) round(now()->diffInMinutes(\Carbon\Carbon::parse($mysql, config('app.timezone')), false) / 30) * 30;

        if ($minutos === 0) {
            return;
        }

        DB::update('UPDATE dropi_estados_bitacora SET created_at = created_at - INTERVAL ? MINUTE', [$minutos]);
        DB::update('UPDATE dropi_alistador_locks SET locked_at = locked_at - INTERVAL ? MINUTE', [$minutos]);
    }

    public function down(): void
    {
        // Sin reverso: volver a correr la hora dejaría otra vez la bitácora en UTC.
    }
};
