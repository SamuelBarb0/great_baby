<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase C · Las dos entrevistas del proceso de selección.
 *
 * Formatos 3 y 4 del área: la de «saber hacer» que hace el jefe o Gestión
 * Humana, y la psicológica. El tablero tenía las dos etapas —`EntrevistaJefe`
 * y `EntrevistaPsicologia`— y movía la tarjeta de una a otra sin guardar una
 * sola palabra de lo que se preguntó ni de lo que contestaron.
 *
 * El daño concreto: la terna llega al jefe que pidió el personal con tres
 * nombres y una calificación, y él tiene que elegir sin ver por qué alguien
 * valoró así. Y si tres meses después alguien pregunta por qué se contrató a
 * esta persona y no a la otra, la respuesta no existe en ninguna parte.
 *
 * ── Una tabla y dos formas ──────────────────────────────────────────────────
 *
 * Las dos entrevistas se parecen en lo que son —una fecha, un entrevistador y
 * lo que se dijo— y se diferencian en el cuestionario. Dos tablas repetirían
 * cuatro columnas idénticas y obligarían a dos pantallas y dos impresos para
 * la misma idea.
 *
 * El cuestionario vive en `EntrevistaCandidato` como catálogo, igual que los
 * nueve aspectos del período de prueba o los seis bloques de la entrega de
 * cargo: el formato manda, el código lo copia, y cambiar una pregunta es
 * editar una constante y no una migración.
 *
 * ── Por qué JSON y no una columna por pregunta ──────────────────────────────
 *
 * Porque son quince respuestas de texto libre que no se filtran ni se
 * suman: se leen. Una columna por pregunta haría una tabla de treinta campos
 * que hay que migrar cada vez que el área ajusta el cuestionario, y ninguna
 * de ellas se consultaría nunca por separado.
 *
 * Lo que SÍ se consulta —las seis calificaciones y su total— se guarda aparte,
 * en su propia columna, porque de eso sí se sacan promedios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrhh_entrevistas_candidato', function (Blueprint $t) {
            $t->id();
            $t->foreignId('candidato_id')->constrained('rrhh_candidatos')->cascadeOnDelete();

            // saber_hacer · psicologica
            $t->string('tipo', 20);

            $t->date('fecha');

            /*
             * Quién entrevistó, en texto.
             *
             * La psicológica suele hacerla alguien externo —Great Baby no
             * tiene psicólogo de planta— y amarrarlo a un usuario del ERP
             * obligaría a crear una cuenta para quien entra una vez.
             */
            $t->string('entrevistador_nombre', 160);
            $t->string('entrevistador_cargo', 120)->nullable();

            // Las respuestas del cuestionario, por clave de pregunta.
            $t->json('respuestas')->nullable();

            /*
             * Las seis calificaciones de 1 a 5 del formato de saber hacer, y
             * su total. Van aparte del JSON porque de acá sí salen promedios
             * y comparaciones entre candidatos.
             */
            $t->json('calificaciones')->nullable();
            $t->unsignedSmallInteger('total')->nullable();

            $t->text('observaciones')->nullable();

            $t->foreignId('registrada_por_id')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();

            // Una de cada tipo por candidato: si se corrige, se corrige la
            // que hay. Dos versiones de la misma entrevista dejarían al jefe
            // sin saber cuál leer.
            $t->unique(['candidato_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rrhh_entrevistas_candidato');
    }
};
