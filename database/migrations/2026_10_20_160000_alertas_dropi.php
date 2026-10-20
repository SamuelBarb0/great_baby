<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarjeta Dropi · 8. Sanciones / Alertas.
 *
 *   · dropi_creado_at: cuándo nació el pedido EN DROPI. El plazo de 72 h corre
 *     desde ahí; `created_at` es cuándo lo trajo el ERP (un pedido importado
 *     tarde parecía recién llegado).
 *   · alertas_enviadas: qué avisos ya salieron por este pedido (plazo48,
 *     plazo72, transito15, transito20, devolucion, fantasma), para avisar una
 *     sola vez por nivel.
 *   · dropi_sanciones.notificada_at: la diferencia de precio ya se avisó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_pedidos', function (Blueprint $table) {
            if (! Schema::hasColumn('dropi_pedidos', 'dropi_creado_at')) {
                $table->dateTime('dropi_creado_at')->nullable()->after('dropi_orden_id')->index();
                $table->json('alertas_enviadas')->nullable();
            }
        });
        Schema::table('dropi_sanciones', function (Blueprint $table) {
            if (! Schema::hasColumn('dropi_sanciones', 'notificada_at')) {
                $table->timestamp('notificada_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dropi_pedidos', function (Blueprint $table) {
            $table->dropIndex(['dropi_creado_at']);
            $table->dropColumn(['dropi_creado_at', 'alertas_enviadas']);
        });
        Schema::table('dropi_sanciones', function (Blueprint $table) {
            $table->dropColumn('notificada_at');
        });
    }
};
