<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un mismo contenedor se reparte entre varios destinos, con cantidades.
 *
 * Hasta hoy el destino se decidía una sola vez, al armar la orden
 * —`compras_ordenes.bodega_id`, obligatoria— y la recepción lo copiaba a su
 * encabezado: las 50 unidades entraban todas al mismo sitio. Lo que la bodega
 * hace de verdad es otra cosa: «de estas 50, 30 son de Great Baby y 20 son de
 * Dropi», y eso se decide al descargar, mirando la caja, no tres semanas antes
 * cuando se firmó la orden.
 *
 * Por eso el destino baja de la recepción a la LÍNEA. Una misma línea de la
 * orden puede abrir varios renglones, cada uno con su cantidad y su destino.
 *
 * Es aditiva y en NULL: toda recepción que ya existe queda con `ubicacion_id`
 * nulo, que se lee como «la bodega de la orden», que es exactamente lo que
 * hicieron. Ninguna fila cambia de valor ni queda inválida, y una recepción
 * sin repartir sigue comportándose igual que siempre.
 *
 * La bodega concreta sale del par (oficina, almacén) que eligió quien
 * descarga: ver `UbicacionDelPar`. Acá se guarda ya resuelta, porque el kardex
 * sólo entiende de ubicaciones y porque así la fila sigue siendo legible
 * aunque mañana alguien reorganice el catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras_recepcion_items', function (Blueprint $t) {
            /*
             * A dónde entró ESTA parte de la línea.
             *
             * Null = la bodega de la orden. Se guarda normalizado: si alguien
             * elige en la pantalla el mismo destino de la orden, se guarda
             * null igual, porque escribir el id abriría dos filas para un solo
             * sitio y el reparto se vería donde no lo hubo.
             */
            $t->foreignId('ubicacion_id')->nullable()->after('orden_item_id')
                ->constrained('inventario_ubicaciones')->nullOnDelete();

            // «Qué entró a cada bodega en esta recepción» es la pregunta que se
            // hace tanto la pantalla de detalle como el traslado de e-commerce.
            $t->index(['recepcion_id', 'ubicacion_id'], 'recep_item_ubic_idx');
        });
    }

    public function down(): void
    {
        Schema::table('compras_recepcion_items', function (Blueprint $t) {
            $t->dropIndex('recep_item_ubic_idx');
            $t->dropConstrainedForeignId('ubicacion_id');
        });
    }
};
