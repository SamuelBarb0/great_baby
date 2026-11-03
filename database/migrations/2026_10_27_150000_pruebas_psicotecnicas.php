<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase C · Las pruebas psicotécnicas del proceso de selección.
 *
 * El área aplica MINIMULT y VALANTI y hoy eso vive entero por fuera: el
 * tablero de selección tiene una etapa «Pruebas» que sólo dice que el
 * candidato pasó por ahí. Ni el concepto, ni quién lo firmó, ni el soporte.
 *
 * ── La decisión que define esta tabla ───────────────────────────────────────
 *
 * Se guarda el CONCEPTO del profesional y el PDF firmado. **No se guardan las
 * respuestas.** No es una simplificación: es la decisión.
 *
 *  · Las respuestas de un MINIMULT son dato sensible de salud mental. La Ley
 *    1581 de 2012 las pone en categoría especial —autorización expresa,
 *    finalidad determinada, acceso restringido—. Una tabla de respuestas en un
 *    ERP que medio mundo puede exportar a Excel es precisamente lo que esa
 *    norma viene a evitar, y el daño de una fuga ahí no se repara.
 *  · Los dos instrumentos tienen derechos de autor. Meter sus ítems en la base
 *    sería copiarlos.
 *
 * Y sobre todo: para decidir una contratación la empresa no necesita las 71
 * respuestas. Necesita saber si el profesional conceptúa apto. Guardar más de
 * lo que se usa es asumir un riesgo a cambio de nada.
 *
 * ── Por qué el archivo no va a la carpeta pública ───────────────────────────
 *
 * El PDF va al disco privado y se sirve por un controlador que revisa permiso
 * y deja anotado quién lo abrió. Un informe psicológico con URL adivinable es
 * una fuga esperando a que alguien comparta el enlace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrhh_pruebas_psicotecnicas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('candidato_id')->constrained('rrhh_candidatos')->cascadeOnDelete();

            // minimult · valanti · otro
            $t->string('instrumento', 20);
            // Sólo cuando es «otro»: el área aplica más pruebas de las dos que
            // mandó en sus formatos y no vale la pena cerrarle la lista.
            $t->string('instrumento_otro', 120)->nullable();

            $t->date('fecha_aplicacion');

            /*
             * Quién la aplicó e interpretó.
             *
             * El nombre y la tarjeta profesional van en texto porque casi
             * siempre es alguien externo: Great Baby no tiene psicólogo de
             * planta. Amarrarlo a un usuario del ERP obligaría a crear una
             * cuenta para alguien que entra una vez.
             *
             * La tarjeta no es un dato de adorno: la Ley 1090 de 2006 reserva
             * la aplicación de pruebas psicológicas a profesionales con ella.
             */
            $t->string('profesional_nombre', 160);
            $t->string('profesional_tarjeta', 40)->nullable();

            // apto · apto_con_observaciones · no_apto
            $t->string('concepto', 30);
            $t->text('observaciones')->nullable();

            // El informe firmado, en el disco privado.
            $t->string('archivo_path')->nullable();

            // Quién lo cargó al sistema (distinto de quién lo firmó).
            $t->foreignId('registrado_por_id')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();

            // Una por instrumento y candidato: si se repite, se corrige la que
            // hay en vez de dejar dos conceptos distintos del mismo examen.
            $t->unique(['candidato_id', 'instrumento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rrhh_pruebas_psicotecnicas');
    }
};
