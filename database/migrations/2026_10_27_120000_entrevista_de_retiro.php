<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase F1 · La entrevista de retiro, que no existía.
 *
 * Al salir alguien, el ERP guardaba tres cosas: fecha, tipo de retiro de una
 * lista cerrada y un detalle en texto. Con eso se puede contar cuánta gente se
 * fue, pero no por qué — y «por qué se va la gente» es una de las cinco
 * preguntas que Elena lleva a la Junta.
 *
 * El formato del área pide cuatro bloques que no estaban:
 *
 *   · el MOTIVO que da la persona, de una lista de nueve (distinto del tipo de
 *     retiro: ver `MotivoSalida`);
 *   · ocho criterios de la experiencia laboral calificados de 1 a 5, cada uno
 *     con su comentario — el jefe, el equipo, la carga, las herramientas, la
 *     capacitación, el salario, el equilibrio y el ambiente;
 *   · cuatro preguntas abiertas, incluida «¿recomendaría a la empresa como un
 *     excelente lugar para trabajar?», que es la que de verdad duele;
 *   · el paz y salvo.
 *
 * ── Lo que NO se guarda acá ─────────────────────────────────────────────────
 *
 * El bloque 5 del formato lista equipo de cómputo, móvil, carnet y dotación.
 * Eso ya vive en `rrhh_asignaciones` CON SERIAL, y lo que se entregó y lo que
 * falta por devolver ya se sabe. Copiarlo acá sería tener el mismo hecho en
 * dos tablas y volver a escribir a mano lo que el sistema ya tiene: el impreso
 * lo lee de allá. Lo único que se guarda del bloque es `accesos_cerrados`,
 * porque cerrar correos y accesos no es un activo sino una acción, y hasta
 * ahora nadie la registraba en ninguna parte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrhh_entrevistas_retiro', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empleado_id')->constrained('rrhh_empleados')->cascadeOnDelete();

            $t->date('fecha_entrevista');
            $t->foreignId('entrevistador_id')->nullable()->constrained('users')->nullOnDelete();

            // Voluntaria o involuntaria. El formato lo pregunta aparte del
            // motivo porque «fin de contrato» puede ser cualquiera de las dos.
            $t->string('salida', 20)->nullable();
            $t->string('motivo_principal', 30)->nullable();
            // Sólo cuando el motivo es «otro»: el formato tiene su renglón.
            $t->string('motivo_otro', 160)->nullable();
            $t->text('detalle_motivo')->nullable();

            /*
             * Los ocho criterios: nota de 1 a 5 y su comentario.
             *
             * El comentario va por criterio y no en un bloque al final porque
             * es donde está el valor: un 2 en «carga laboral» sin el porqué no
             * se puede atacar, y es justo el dato por el que se hace una
             * entrevista de retiro.
             */
            foreach ([
                'jefe_inmediato', 'equipo_trabajo', 'carga_laboral', 'herramientas',
                'capacitacion', 'condiciones_salariales', 'equilibrio_vida', 'ambiente',
            ] as $criterio) {
                $t->unsignedTinyInteger("n_{$criterio}")->nullable();
                $t->string("c_{$criterio}", 500)->nullable();
            }

            // Las cuatro abiertas.
            $t->text('que_valoro')->nullable();
            $t->text('que_mejorar')->nullable();
            $t->boolean('funciones_correspondian')->nullable();
            $t->text('funciones_explicacion')->nullable();
            $t->boolean('recomendaria')->nullable();
            $t->text('recomendaria_por_que')->nullable();

            // Del bloque de paz y salvo, lo único que no es un activo.
            $t->boolean('accesos_cerrados')->default(false);

            $t->text('observaciones')->nullable();

            $t->timestamps();

            // Una por persona: si se repite, se corrige la que hay.
            $t->unique('empleado_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rrhh_entrevistas_retiro');
    }
};
