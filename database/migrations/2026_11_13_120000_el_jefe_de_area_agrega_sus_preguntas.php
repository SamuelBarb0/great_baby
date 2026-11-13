<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El jefe del área agrega sus propias preguntas a la entrevista.
 *
 * Las quince preguntas del formato viven en código —`SABER_HACER_BLOQUES`— y
 * eso se defendía diciendo que el formato del área y lo que pregunta la
 * pantalla no pueden separarse. Pero el área lo aclaró distinto: esas
 * preguntas son EJEMPLOS de lo que se puede preguntar. Quien de verdad sabe
 * qué hay que preguntarle a un alistador es el jefe de bodega, y a un contador
 * el de contabilidad.
 *
 * Así que las del formato se quedan donde están, intactas, y acá viven las que
 * agrega el jefe. Son dos cosas distintas y se ven distintas en la pantalla y
 * en el impreso: nadie confunde lo que manda el formato con lo que preguntó
 * alguien.
 *
 * POR VACANTE, no globales: es donde de verdad cambian.
 *
 * Y NO SE BORRAN. Una pregunta que ya se respondió, borrada, dejaría una
 * respuesta sin enunciado en una entrevista que alguien firmó. `activa` la
 * saca de las entrevistas nuevas y la deja en las viejas.
 *
 * La clave de la respuesta es `x{id}` y se arma desde el id de la fila, que es
 * único para siempre: no choca con las del formato —g1, a1, e10— y sigue
 * resolviendo aunque la pregunta se desactive después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrhh_preguntas_entrevista', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vacante_id')->constrained('rrhh_vacantes')->cascadeOnDelete();

            // saber_hacer · psicologica · las que tienen preguntas.
            $t->string('tipo', 20);

            $t->string('pregunta', 400);
            $t->unsignedSmallInteger('orden')->default(0);

            /*
             * Desactivada deja de aparecer en entrevistas nuevas y sigue
             * mostrándose en las que ya la respondieron. Borrarla dejaría la
             * respuesta sin enunciado.
             */
            $t->boolean('activa')->default(true);

            $t->foreignId('creada_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['vacante_id', 'tipo', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rrhh_preguntas_entrevista');
    }
};
