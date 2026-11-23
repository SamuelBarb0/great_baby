<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LO QUE EL CLIENTE DEVUELVE, REGISTRADO.
 *
 * Hasta hoy una devolución de cliente no existía para el sistema. El módulo de
 * garantías lleva tickets pero no mueve inventario, y las devoluciones que hay
 * son a PROVEEDOR —mercancía que sale—. Así que la que entra terminaba de una
 * de dos formas, las dos malas: quedándose en el piso sin que el ERP supiera
 * que está, o metida al almacén de venta, donde se le vuelve a ofrecer a otro
 * cliente antes de que nadie la revise.
 *
 * El procedimiento lo dio Don Jorge el 23-nov-2026: la mercancía entra al
 * almacén 08 «Devoluciones y garantías de clientes» con estado inicial «Por
 * revisar», se inspecciona a más tardar al día siguiente, y de ahí se traslada
 * al almacén que corresponda según el resultado.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * NO HAY COLUMNA DE ESTADO FÍSICO, Y ES A PROPÓSITO
 *
 * «Por revisar» es estar en el 08. La mercancía sale de ahí con el traslado
 * que el ERP ya tiene, y el día que sale deja de estar por revisar sin que
 * nadie toque un campo. Una columna paralela al sitio físico se desincroniza
 * el primer día que alguien mueva algo sin actualizarla.
 *
 * El `estado` que sí existe acá es el del DOCUMENTO —borrador o confirmada—,
 * que es otra cosa: mientras está en borrador se arma la lista y no se tocó el
 * inventario; al confirmar entra al kardex y ya no se edita.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * LA PLATA VA APARTE
 *
 * Esto registra el movimiento FÍSICO. Reversar la venta —nota crédito, cartera,
 * SIIGO— es el circuito de contabilidad y ya existe en Cartera. Por eso se
 * guarda contra qué factura fue la devolución: para poder emitir la nota desde
 * donde se emite, sin que recibir mercancía en bodega mueva plata sola.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('devoluciones_cliente')) {
            Schema::create('devoluciones_cliente', function (Blueprint $t) {
                $t->id();
                $t->string('numero', 30)->unique();

                $t->foreignId('contacto_id')->constrained('contactos')->restrictOnDelete();

                /*
                 * Contra qué salió. Las dos nullable porque el área recibe
                 * devoluciones de las que no siempre aparece el papel, y
                 * rechazar la mercancía por eso la deja en el piso — que es
                 * justo lo que este módulo viene a evitar. Sin factura se
                 * registra igual, y queda dicho que falta.
                 */
                $t->foreignId('pedido_id')->nullable()
                    ->constrained('pedidos_cliente')->nullOnDelete();
                $t->foreignId('factura_id')->nullable()
                    ->constrained('facturas_venta')->nullOnDelete();

                /*
                 * De dónde vuelve: la bodega contra la que se recibe. De acá
                 * hereda la ubicación del almacén 08 su responsable y su
                 * ciudad — sin eso nace invisible, y la mercancía recién
                 * recibida no le aparece a nadie en su kardex ni la puede
                 * mover.
                 */
                $t->foreignId('ubicacion_origen_id')->constrained('inventario_ubicaciones')->restrictOnDelete();

                // Y a dónde entró. Se escribe al confirmar: es la del par
                // (oficina de la bodega × almacén 08), que nace sola.
                $t->foreignId('ubicacion_destino_id')->nullable()
                    ->constrained('inventario_ubicaciones')->nullOnDelete();

                $t->date('fecha');
                $t->string('motivo', 500);
                $t->enum('estado', ['borrador', 'confirmada'])->default('borrador');

                $t->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('confirmada_at')->nullable();

                /*
                 * Cuándo se revisó. El área puso el plazo —«a más tardar al día
                 * siguiente»— y sin esta fecha nada lo vigila: es lo que
                 * permite listar lo que lleva más de un día esperando.
                 *
                 * Se llena cuando la mercancía SALE del 08 por traslado, que es
                 * el momento en que de verdad terminó la inspección.
                 */
                $t->timestamp('revisada_at')->nullable();
                $t->foreignId('revisada_por')->nullable()->constrained('users')->nullOnDelete();

                $t->timestamps();
                $t->softDeletes();

                // La pantalla abre por lo que falta revisar, y ése es el orden.
                $t->index(['estado', 'revisada_at']);
            });
        }

        if (! Schema::hasTable('devoluciones_cliente_items')) {
            Schema::create('devoluciones_cliente_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('devolucion_id')->constrained('devoluciones_cliente')->cascadeOnDelete();

                // Producto XOR variante, como el resto del ERP: el catálogo
                // tiene referencias que se desglosan por color/talla y otras
                // que no.
                $t->foreignId('variante_id')->nullable()->constrained('producto_variantes')->nullOnDelete();
                $t->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();

                $t->decimal('cantidad', 12, 4);

                /*
                 * A qué costo entra. NO es adorno: el costo promedio de una
                 * ubicación se calcula al vuelo sobre las entradas del kardex,
                 * así que una entrada sin costo deja el promedio del 08 en
                 * cero — y cuando la mercancía salga de ahí por traslado, ese
                 * cero se copia al almacén de destino y contamina el promedio
                 * del inventario bueno.
                 */
                $t->decimal('costo_unit', 14, 4)->nullable();

                $t->string('motivo_item', 200)->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devoluciones_cliente_items');
        Schema::dropIfExists('devoluciones_cliente');
    }
};
