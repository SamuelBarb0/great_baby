<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase E · El detalle del formato GH-F-015, que no existía.
 *
 * «FORMATO EVALUACIÓN PERÍODO DE PRUEBA» pide al jefe calificar NUEVE aspectos
 * de 1 a 5 y escribir qué resaltar y qué mejorar. El ERP guardaba dos cosas:
 * «continúa / no continúa» y una nota libre de 500 caracteres.
 *
 * La diferencia importa el día que alguien reclama. Un «no continúa · no se
 * adaptó» no sustenta nada; nueve notas y dos párrafos sí dicen qué se miró y
 * qué se le había señalado. Y al revés: con los nueve aspectos se puede ver
 * que a la persona la calificaron bien en todo y aun así no sigue.
 *
 * ── Por qué una tabla aparte y no columnas en el empleado ────────────────────
 *
 * Porque puede haber MÁS DE UNA. El período de prueba se prorroga, y entonces
 * hay una segunda evaluación con fecha propia. En columnas, la segunda pisaría
 * a la primera y se perdería justo el historial que sustenta la decisión.
 *
 * El veredicto sigue viviendo en `rrhh_empleados.prueba_jefe_*`: es lo que ya
 * leen la pantalla, los avisos y la decisión de Gestión Humana. Esta tabla es
 * el detalle del formato, no un segundo lugar donde se decide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrhh_evaluaciones_prueba', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empleado_id')->constrained('rrhh_empleados')->cascadeOnDelete();

            $t->date('fecha_evaluacion');
            $t->foreignId('jefe_id')->nullable()->constrained('users')->nullOnDelete();
            // El formato pide el cargo del jefe, no sólo su nombre: dentro de
            // dos años el nombre no dice desde qué lugar se evaluó.
            $t->string('jefe_cargo', 120)->nullable();

            /*
             * Los nueve aspectos, de 1 a 5.
             *
             * `tinyInteger` nullable y no `default(0)`: un cero sería una nota
             * pésima, y lo que significa es «no lo calificó». «Manejo de
             * personal» es el caso normal de nulo —el formato dice «si
             * aplica»— pero cualquiera puede quedar sin calificar si el jefe
             * guarda a medias.
             */
            foreach ([
                'cumplimiento_normas', 'cumplimiento_horario', 'cumplimiento_tareas',
                'relaciones_interpersonales', 'recibe_retroalimentacion',
                'capacidad_aprendizaje', 'resolucion_problemas', 'actitud_trabajo',
                'manejo_personal',
            ] as $aspecto) {
                $t->unsignedTinyInteger($aspecto)->nullable();
            }

            $t->text('aspectos_resaltar')->nullable();
            $t->text('aspectos_mejorar')->nullable();

            // Espejo del veredicto, para que el impreso de ESTA evaluación diga
            // lo que se decidió ESE día aunque después se prorrogue.
            $t->string('resultado', 20)->nullable();
            $t->text('motivo')->nullable();
            $t->text('observaciones')->nullable();

            $t->timestamps();

            $t->index(['empleado_id', 'fecha_evaluacion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rrhh_evaluaciones_prueba');
    }
};
