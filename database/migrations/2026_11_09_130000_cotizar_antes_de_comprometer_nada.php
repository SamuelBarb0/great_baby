<?php

/*
 * Cotizar, que es lo que pasa ANTES de un pedido.
 *
 * El ERP sólo sabía levantar pedidos: el vendedor abría su panel, armaba el
 * pedido y quedaba hecho. Pero un cliente B2B casi nunca compra en la primera
 * conversación —pide precios, los compara, consulta con su socio y vuelve a la
 * semana—, y ese paso no existía en ninguna parte. En la práctica se cotizaba
 * por WhatsApp y después se transcribía a mano, con dos problemas: el precio
 * que se prometió no quedaba registrado, y al transcribir se equivocaban.
 *
 * Dos diferencias con un pedido, y las dos importan:
 *
 *   · Una cotización NO compromete inventario. Reservar stock por algo que el
 *     cliente todavía no aceptó dejaría mercancía congelada por conversaciones
 *     que no van a ningún lado. El stock se reserva al aprobar el pedido.
 *   · Una cotización VENCE. Un precio sin fecha es una promesa indefinida, y
 *     con el dólar y los fletes como están, eso se vuelve plata perdida.
 *
 * Los precios se guardan en la cotización y no se vuelven a calcular: si al
 * convertirla se recalculara contra la lista de hoy, cotizar no serviría de
 * nada.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cotizaciones')) {
            Schema::create('cotizaciones', function (Blueprint $t) {
                $t->id();
                $t->string('numero', 30)->unique();

                $t->foreignId('contacto_id')->constrained('contactos')->cascadeOnDelete();
                $t->foreignId('vendedor_id')->nullable()->constrained('users')->nullOnDelete();
                $t->unsignedBigInteger('lista_precios_id')->nullable();
                $t->unsignedBigInteger('empresa_id')->nullable();

                $t->date('fecha');
                // Un precio sin fecha de vencimiento es una promesa indefinida.
                $t->date('vigente_hasta')->index();

                $t->string('estado', 20)->default('borrador')->index()
                    ->comment('borrador | enviada | aceptada | rechazada | convertida');

                $t->decimal('subtotal', 16, 2)->default(0);
                $t->decimal('iva', 16, 2)->default(0);
                $t->decimal('total', 16, 2)->default(0);

                $t->text('notas')->nullable();
                $t->string('motivo_rechazo', 255)->nullable();

                $t->timestamp('enviada_at')->nullable();
                $t->timestamp('respondida_at')->nullable();

                // El pedido que salió de acá. Una cotización se convierte UNA
                // vez: sin esto, dos clics seguidos crean dos pedidos iguales.
                $t->foreignId('pedido_id')->nullable()->constrained('pedidos_cliente')->nullOnDelete();
                $t->timestamp('convertida_at')->nullable();

                $t->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->softDeletes();

                $t->index(['contacto_id', 'estado']);
            });
        }

        if (! Schema::hasTable('cotizacion_items')) {
            Schema::create('cotizacion_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();

                // Los granulares cotizan por variante y los agregados por
                // producto, igual que en el pedido. Van los dos nullable.
                $t->unsignedBigInteger('variante_id')->nullable()->index();
                $t->unsignedBigInteger('producto_id')->nullable()->index();

                // Lo que se le mostró al cliente, congelado: si el producto se
                // renombra después, la cotización impresa tiene que seguir
                // diciendo lo que decía el día que se firmó.
                $t->string('sku_snapshot', 100)->nullable();
                $t->string('descripcion_snapshot', 255)->nullable();

                $t->unsignedInteger('cantidad');
                $t->decimal('precio_unitario', 14, 2);
                $t->decimal('iva_porcentaje', 5, 2)->default(0);
                $t->decimal('subtotal', 16, 2)->default(0);
                $t->decimal('iva_valor', 16, 2)->default(0);
                $t->decimal('total', 16, 2)->default(0);

                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_items');
        Schema::dropIfExists('cotizaciones');
    }
};
