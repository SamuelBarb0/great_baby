<?php

/*
 * Una garantía sólo se podía abrir contra una guía de Dropi.
 *
 * `garantia_tickets.pedido_original_id` apunta a `dropi_pedidos`, y el
 * formulario busca el pedido por número de guía. Un cliente B2B —que es el que
 * compra a crédito y el que más reclama— no tenía por dónde entrar: su ticket
 * quedaba sin venta de respaldo, y entonces no hay contra qué validar la
 * referencia, la cantidad ni la fecha. El contrato pide exactamente eso:
 * «validación de referencia, cliente, pedido/factura y fecha contra la venta
 * original».
 *
 * Es el mismo patrón que apareció tres veces esta semana: el canal Dropi tiene
 * la función y la venta B2B no — las reservas, el seguimiento de guías y ahora
 * las garantías.
 *
 * Se agrega un origen polimórfico, como el que ya usan los movimientos
 * contables. `pedido_original_id` se CONSERVA y se copia al origen: hay
 * tickets viejos apuntando ahí, y borrar la columna en la misma migración que
 * la reemplaza es quedarse sin vuelta atrás.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garantia_tickets', function (Blueprint $t) {
            if (! Schema::hasColumn('garantia_tickets', 'origen_type')) {
                $t->string('origen_type', 120)->nullable()->after('numero')
                    ->comment('De qué venta sale: DropiPedido o FacturaVenta');
                $t->unsignedBigInteger('origen_id')->nullable()->after('origen_type');
                $t->index(['origen_type', 'origen_id']);
            }
            if (! Schema::hasColumn('garantia_tickets', 'contacto_id')) {
                $t->foreignId('contacto_id')->nullable()->after('cliente_telefono')
                    ->constrained('contactos')->nullOnDelete()
                    ->comment('El cliente B2B, cuando la garantía sale de una factura');
            }
            if (! Schema::hasColumn('garantia_tickets', 'producto_id')) {
                // Una venta B2B puede vender el producto agregado, sin variante.
                $t->unsignedBigInteger('producto_id')->nullable()->after('variante_id');
            }
        });

        // Los tickets que ya existían eran todos de Dropi.
        DB::table('garantia_tickets')
            ->whereNotNull('pedido_original_id')
            ->whereNull('origen_type')
            ->update([
                'origen_type' => \App\Modules\Dropi\Models\DropiPedido::class,
                'origen_id' => DB::raw('pedido_original_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('garantia_tickets', function (Blueprint $t) {
            if (Schema::hasColumn('garantia_tickets', 'contacto_id')) {
                $t->dropConstrainedForeignId('contacto_id');
            }
            foreach (['origen_type', 'origen_id', 'producto_id'] as $col) {
                if (Schema::hasColumn('garantia_tickets', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
