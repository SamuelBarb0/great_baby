<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F8 · La inducción, con temario.
 *
 * El ERP ya registraba que la inducción se dio, quién la dio y cuándo, y los
 * temas en **un campo de texto libre**. El resultado está a la vista: de siete
 * fichas, una sola tiene temas escritos. Un campo de texto que nadie llena no
 * es un registro, es una casilla.
 *
 * Y el daño no es que falte información: la inducción es lo que la empresa
 * tiene para demostrar que le explicó a alguien cómo se trabaja y qué riesgos
 * corre. Si pasa algo y lo único que hay es «se le dio la inducción», no hay
 * con qué responder qué se le explicó.
 *
 * ── Por qué el temario es una regla y no una tabla ──────────────────────────
 *
 * Porque es una lista que cambia poco y la edita el área, no el programa:
 * vive en `rrhh.temario_induccion` y se cambia desde la pantalla de Reglas,
 * igual que los porcentajes de la nómina.
 *
 * Los temas que trae por defecto son un punto de partida razonable —SST,
 * reglamento, producto, sistemas— y están marcados como provisionales: el
 * área todavía no nos pasó su formato de inducción, está pedido en la
 * solicitud de insumos. Cuando llegue, se reemplaza la lista y las
 * inducciones viejas siguen diciendo lo que dijeron, porque cada una guarda
 * SU temario.
 *
 * ── Por qué cada inducción guarda su propio temario ─────────────────────────
 *
 * Si sólo guardara «cuáles marcó», el día que el área agregue un tema las
 * inducciones del año pasado aparecerían como incompletas por un tema que no
 * existía cuando se dieron. El temario se copia al momento de darla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrhh_inducciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empleado_id')->constrained('rrhh_empleados')->cascadeOnDelete();

            $t->date('fecha');

            /*
             * El temario tal como estaba cuando se dio, con lo que se marcó
             * de cada tema: [{tema, cubierto, responsable, observacion}].
             */
            $t->json('temas');

            // Quién la dio en general, cuando no se desglosa por tema.
            $t->string('responsable_nombre', 160)->nullable();
            $t->string('responsable_cargo', 120)->nullable();

            $t->text('observaciones')->nullable();

            /*
             * Cuándo firmó el colaborador la constancia.
             *
             * Se guarda aparte de la fecha de la inducción porque son dos
             * hechos distintos: una cosa es que se la dieran y otra que haya
             * firmado que se la dieron. Lo segundo es lo que sirve de prueba.
             */
            $t->date('firmada_at')->nullable();

            $t->foreignId('registrada_por_id')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();

            // Una por persona: si se corrige, se corrige la que hay.
            $t->unique('empleado_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rrhh_inducciones');
    }
};
