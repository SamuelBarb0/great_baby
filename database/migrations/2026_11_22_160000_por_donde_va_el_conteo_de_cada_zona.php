<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POR DÓNDE VA EL CONTEO DE CADA ZONA.
 *
 * Una toma física de la bodega entera son 436 posiciones: nadie la termina, así
 * que en la práctica no se contaba nunca. El conteo cíclico la recorre de a
 * poquito —unas pocas posiciones al día, sin cerrar la bodega— y para eso hace
 * falta recordar una sola cosa: en qué estante se quedó cada zona.
 *
 * Es una tabla nueva y no toca ninguna existente. La decisión es deliberada:
 * `tomas_fisicas_items` no tiene columna de ubicación, así que una toma que
 * abarcara varias posiciones no sabría a cuál devolverle el ajuste —caerían
 * todos en la cabecera y se descuadrarían dos sitios de una vez—. Mientras eso
 * siga así, el conteo del día crea UNA TOMA POR POSICIÓN, que es como el
 * módulo ya sabe trabajar y deja cada ajuste en su estante.
 *
 * La zona se guarda como texto porque eso es: la clave del reparto que el área
 * mueve desde Reglas (`logistica.zonas_bodega`). Hoy son «1», «2» y «3», pero
 * pueden ser letras mañana, y no hay —ni debe haber— una tabla de zonas: el
 * reparto es configuración, no catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conteos_ciclicos_zonas')) {
            return;
        }

        Schema::create('conteos_ciclicos_zonas', function (Blueprint $t) {
            $t->id();

            // La clave del reparto de `logistica.zonas_bodega`, tal cual.
            $t->string('zona', 40)->unique();

            /*
             * El último estante que se mandó a contar. La próxima tanda
             * arranca después de éste y, al llegar al final, vuelve al
             * principio sumando una vuelta.
             *
             * `nullOnDelete`: si alguien borra una posición, la zona no se
             * queda sin cursor ni se cae la fila — simplemente vuelve a
             * empezar por el principio, que es lo correcto.
             */
            $t->foreignId('cursor_posicion_id')->nullable()
                ->constrained('inventario_ubicaciones')->nullOnDelete();

            // Para saber si una zona se está quedando atrás, que es la
            // pregunta que el área le va a hacer a esta tabla.
            $t->timestamp('ultima_tanda_at')->nullable();
            $t->timestamp('ultima_vuelta_at')->nullable();
            $t->unsignedInteger('vueltas')->default(0);
            $t->unsignedInteger('posiciones_mandadas')->default(0);

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conteos_ciclicos_zonas');
    }
};
