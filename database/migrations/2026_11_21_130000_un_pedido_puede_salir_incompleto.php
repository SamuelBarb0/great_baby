<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un pedido puede salir incompleto, y hay que saber qué salió.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * EL PROBLEMA
 *
 * El área lo pidió el 20-nov-2026: «se permite realizar despachos parciales
 * cuando exista una necesidad operativa que lo justifique. Se autoriza el
 * despacho de la mercancía disponible, sin esperar a completar la totalidad».
 *
 * Hoy el despacho no maneja cantidades. Marca el pedido entero como despachado
 * y descuenta la cantidad COMPLETA de cada línea; y si la bodega no tiene todo,
 * `exigirSaldo()` aborta con un 422 y no sale nada. O sea: el escenario que el
 * área describe como permitido es justo el que el sistema bloquea, y el
 * camión espera.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * QUÉ SE AGREGA
 *
 * `cantidad_despachada` por línea. Nace igual a `cantidad` en todo lo que ya
 * salió, porque hasta hoy un despacho era siempre completo: así ningún pedido
 * viejo aparece de pronto como «salió incompleto».
 *
 * Y dos datos en el pedido:
 *
 *   · `despacho_parcial`  — salió incompleto, sí o no. Es lo que mira la
 *     pantalla para pintarlo distinto y lo que permite listar «qué salió a
 *     medias» sin recorrer todas las líneas de todos los pedidos.
 *
 *   · `resto_pendiente`   — y acá está la decisión que el área pidió poder
 *     tomar caso por caso:
 *
 *        true  → lo que no salió SIGUE DEBIÉNDOSE. El pedido vuelve a la cola
 *                para un segundo despacho.
 *        false → lo que no salió ya no sale. El pedido se cierra con lo que
 *                se entregó.
 *
 *     No se decide en el código porque no es una decisión de código: depende
 *     de si el cliente va a esperar el resto o no, y eso lo sabe quien está
 *     despachando, no nosotros.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pedidos_cliente_items')
            && ! Schema::hasColumn('pedidos_cliente_items', 'cantidad_despachada')) {
            Schema::table('pedidos_cliente_items', function (Blueprint $t) {
                $t->decimal('cantidad_despachada', 14, 4)->nullable()->after('cantidad')
                    ->comment('Cuánto salió de verdad. NULL = todavía no se ha despachado.');
            });

            /*
             * Todo lo que ya salió, salió completo: hasta hoy no había otra
             * forma. Sin esto, cada pedido despachado del pasado quedaría con
             * `cantidad_despachada` en NULL y las pantallas nuevas lo leerían
             * como «no salió nada».
             */
            DB::statement('
                UPDATE pedidos_cliente_items i
                  JOIN pedidos_cliente p ON p.id = i.pedido_id
                   SET i.cantidad_despachada = i.cantidad
                 WHERE p.despachado_at IS NOT NULL
                   AND i.cantidad_despachada IS NULL
            ');
        }

        if (Schema::hasTable('pedidos_cliente') && ! Schema::hasColumn('pedidos_cliente', 'despacho_parcial')) {
            Schema::table('pedidos_cliente', function (Blueprint $t) {
                $t->boolean('despacho_parcial')->default(false)->after('despachado_at')
                    ->comment('Salió incompleto.');

                $t->boolean('resto_pendiente')->default(false)->after('despacho_parcial')
                    ->comment('Lo que no salió sigue debiéndose y vuelve a la cola.');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pedidos_cliente_items') && Schema::hasColumn('pedidos_cliente_items', 'cantidad_despachada')) {
            Schema::table('pedidos_cliente_items', fn (Blueprint $t) => $t->dropColumn('cantidad_despachada'));
        }

        if (Schema::hasTable('pedidos_cliente') && Schema::hasColumn('pedidos_cliente', 'despacho_parcial')) {
            Schema::table('pedidos_cliente', fn (Blueprint $t) => $t->dropColumn(['despacho_parcial', 'resto_pendiente']));
        }
    }
};
