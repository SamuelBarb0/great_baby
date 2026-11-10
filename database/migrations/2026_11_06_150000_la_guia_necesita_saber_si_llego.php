<?php

/*
 * Un pedido B2B moría en «despachado».
 *
 * La tabla guardaba `despachado_at`, la transportadora y el número de guía, y
 * ahí se acababa: no había forma de decir que la mercancía llegó. El contrato
 * pide seguimiento de guías con cuatro estados —pendiente, a tiempo, tardía y
 * no entregada— y los cuatro necesitan saber cuándo se entregó, o que no se
 * entregó.
 *
 * Lo que había para esto era la auditoría de mercancía en tránsito, pero mira
 * `dropi_pedidos`: el canal Dropi sí tenía su alerta y la venta B2B no.
 *
 * Tres columnas y nada más:
 *   · entregado_at      cuándo llegó
 *   · entregado_por_id  quién lo registró
 *   · entrega_nota      quién recibió, o por qué no se pudo entregar
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_cliente', function (Blueprint $t) {
            if (! Schema::hasColumn('pedidos_cliente', 'entregado_at')) {
                $t->timestamp('entregado_at')->nullable()->index()
                    ->comment('Cuándo confirmó la transportadora que llegó');
            }
            if (! Schema::hasColumn('pedidos_cliente', 'entregado_por_id')) {
                $t->foreignId('entregado_por_id')->nullable()
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('pedidos_cliente', 'entrega_nota')) {
                $t->string('entrega_nota', 300)->nullable()
                    ->comment('Quién recibió, o por qué no se pudo entregar');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_cliente', function (Blueprint $t) {
            if (Schema::hasColumn('pedidos_cliente', 'entregado_por_id')) {
                $t->dropConstrainedForeignId('entregado_por_id');
            }
            foreach (['entregado_at', 'entrega_nota'] as $col) {
                if (Schema::hasColumn('pedidos_cliente', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
