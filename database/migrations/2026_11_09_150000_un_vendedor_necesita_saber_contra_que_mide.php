<?php

/*
 * El panel del vendedor mostraba lo vendido, y nada contra qué compararlo.
 *
 * «$4.120.000 este mes» no dice si va bien o mal. El vendedor se entera en la
 * reunión de fin de mes, que es cuando ya no se puede hacer nada: si faltaban
 * dos millones, el día 12 había tiempo de salir a buscarlos y el día 30 no.
 *
 * La meta se guarda por vendedor y por mes, no una sola por vendedor: cambia
 * con la temporada —diciembre no se parece a febrero— y además hay que poder
 * mirar hacia atrás y ver contra qué se medía en su momento. Una meta que se
 * sobreescribe borra la historia del cumplimiento.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('metas_vendedor')) {
            return;
        }

        Schema::create('metas_vendedor', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $t->unsignedSmallInteger('anio');
            $t->unsignedTinyInteger('mes');

            $t->decimal('monto', 16, 2)->default(0)
                ->comment('Meta de venta efectiva del mes: facturado + despachado');
            // Opcional: hay equipos que miden por cantidad de pedidos y no sólo
            // por monto, porque un pedido grande puede tapar un mes sin gestión.
            $t->unsignedInteger('pedidos')->nullable();

            $t->string('notas', 255)->nullable();

            $t->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            // Una meta por vendedor y mes. Sin esto, dos guardados crean dos
            // metas y el cumplimiento depende de cuál lea primero.
            $t->unique(['user_id', 'anio', 'mes'], 'uniq_meta_vendedor_mes');
            $t->index(['anio', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metas_vendedor');
    }
};
