<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Los pedidos que YA se liberaron también pasaron por cartera.
 *
 * La migración de esta mañana marcó `retenido_at` sólo en los que estaban
 * retenidos en ese momento. Los que el semáforo frenó la semana pasada y
 * Gerencia ya liberó quedaron sin el hito, así que en su historial no aparece
 * que cartera los paró ni que alguien decidió venderles igual —que es
 * justamente la decisión que había que poder reconstruir—.
 *
 * El dato para reconstruirlo estaba ahí: `motivo_retencion` sólo lo escribe el
 * semáforo de cartera, y no se borra al liberar. Si está lleno, ese pedido
 * estuvo retenido, haya pasado lo que haya pasado después.
 *
 * La hora es la del envío, que es cuando el semáforo corre. No es exacta al
 * segundo; es cierta al día, y es mejor que un historial que miente por
 * omisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        \DB::table('pedidos_cliente')
            ->whereNotNull('motivo_retencion')
            ->where('motivo_retencion', '<>', '')
            ->whereNull('retenido_at')
            ->update(['retenido_at' => \DB::raw('COALESCE(enviado_at, created_at)')]);
    }

    public function down(): void
    {
        // No se revierte: borrar la fecha volvería a esconder la retención, y
        // no hay forma de distinguir la que puso esta migración de la que puso
        // el sistema desde hoy.
    }
};
