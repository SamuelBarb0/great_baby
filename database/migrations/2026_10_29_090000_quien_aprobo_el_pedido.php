<?php

/*
 * Quién aprobó el pedido.
 *
 * `pedidos_cliente` guarda `facturado_por_id` y `despachado_por_id`, pero la
 * aprobación sólo tenía `aprobado_at`: la fecha sí, la persona no. Es el único
 * paso de la cadena sin autor, y es el que autoriza que salga mercancía.
 *
 * La línea de tiempo tapaba el hueco escribiendo «Gerencia» fijo, y eso pasó
 * de impreciso a falso el 28-oct, cuando la puerta de aprobar se abrió también
 * al Contador: en la prueba aprobó el Contador y el pedido decía Gerencia.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_cliente', function (Blueprint $t) {
            $t->unsignedBigInteger('aprobado_por_id')->nullable()->after('aprobado_at');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_cliente', function (Blueprint $t) {
            $t->dropColumn('aprobado_por_id');
        });
    }
};
