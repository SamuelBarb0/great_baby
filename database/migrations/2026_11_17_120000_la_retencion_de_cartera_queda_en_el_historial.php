<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Que la retención de cartera no se borre del historial al liberarla.
 *
 * La línea de tiempo del pedido no se guarda: se arma leyendo los campos de
 * estado. El hito «retenido por cartera» se pintaba con la condición
 * `estado === 'retenido'`, así que en el momento en que Gerencia liberaba el
 * pedido, el hito DESAPARECÍA. Después nadie podía ver que el semáforo lo
 * había bloqueado, ni que alguien decidió vender por encima de él.
 *
 * Es justo lo que una auditoría necesita ver, y es la clase de decisión que
 * conviene que quede firmada: autorizar un despacho a un cliente con facturas
 * vencidas es una decisión comercial con consecuencias, no un trámite.
 *
 * `motivo_retencion` ya existía —guarda «2 factura(s) vencida(s)»— y las
 * columnas de la entrega también, así que acá sólo se agregan las tres que
 * faltaban. Todas nullable: los pedidos viejos quedan en null, que se lee
 * como «nunca estuvo retenido», que es lo que sabemos de ellos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_cliente', function (Blueprint $t) {
            // Cuándo lo frenó el semáforo. Sobrevive a la liberación.
            $t->timestamp('retenido_at')->nullable()->after('motivo_retencion');

            // Y quién decidió vender igual. Sin nombre no sirve de nada.
            $t->timestamp('retencion_liberada_at')->nullable()->after('retenido_at');
            $t->foreignId('retencion_liberada_por_id')->nullable()->after('retencion_liberada_at')
                ->constrained('users')->nullOnDelete();
        });

        /*
         * Los que están retenidos AHORA se marcan con la fecha en que se
         * enviaron, que es cuando el semáforo los frenó. No es exacto al
         * segundo, pero es cierto al día y es mejor que dejarlos en null
         * fingiendo que nunca pasó.
         */
        \DB::table('pedidos_cliente')
            ->where('estado', 'retenido')
            ->whereNull('retenido_at')
            ->update(['retenido_at' => \DB::raw('COALESCE(enviado_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('pedidos_cliente', function (Blueprint $t) {
            $t->dropConstrainedForeignId('retencion_liberada_por_id');
            $t->dropColumn(['retenido_at', 'retencion_liberada_at']);
        });
    }
};
