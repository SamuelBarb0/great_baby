<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 de Gestión Humana · los dos primeros meses de la persona.
 *
 * Son dos cosas que hoy se pasan de largo:
 *
 *   · La INDUCCIÓN. La tabla ya tenía `estado_induccion`, pero sin fecha ni
 *     responsable: decía «completada» sin que nadie pudiera decir cuándo ni
 *     quién la dio ni qué se vio. Para una inducción en seguridad y salud en
 *     el trabajo, eso es justo lo que hay que poder demostrar.
 *
 *   · El PERÍODO DE PRUEBA. Hoy se vence y la persona simplemente se queda,
 *     porque nadie lleva la fecha. Terminado el período, el contrato ya no se
 *     puede dar por terminado sin justa causa ni indemnización: la decisión
 *     hay que tomarla ANTES, y para eso hay que acordarse.
 *
 * `prueba_hasta` se guarda y no se calcula al vuelo a propósito: el plazo
 * depende del tipo de contrato y es un acuerdo escrito, no una fórmula. El
 * sistema propone una fecha; la que manda es la que quede acá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->timestamp('induccion_at')->nullable()->after('estado_induccion');
            $t->foreignId('induccion_por_id')->nullable()->after('induccion_at')
                ->constrained('users')->nullOnDelete();
            $t->text('induccion_temas')->nullable()->after('induccion_por_id');

            $t->date('prueba_hasta')->nullable()->after('induccion_temas');
            $t->timestamp('prueba_evaluada_at')->nullable()->after('prueba_hasta');
            $t->string('prueba_resultado', 20)->nullable()->after('prueba_evaluada_at');
            $t->string('prueba_observacion', 500)->nullable()->after('prueba_resultado');

            // Para buscar «a quién se le vence la prueba esta semana» sin leer
            // la tabla entera.
            $t->index(['estado', 'prueba_hasta'], 'idx_empleado_prueba');
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->dropIndex('idx_empleado_prueba');
            $t->dropConstrainedForeignId('induccion_por_id');
            $t->dropColumn([
                'induccion_at', 'induccion_temas', 'prueba_hasta',
                'prueba_evaluada_at', 'prueba_resultado', 'prueba_observacion',
            ]);
        });
    }
};
