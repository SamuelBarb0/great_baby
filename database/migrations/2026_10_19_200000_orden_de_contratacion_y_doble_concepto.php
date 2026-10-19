<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F5a · Los dos controles de la reunión que faltaban.
 *
 * 1 · LA ORDEN DE CONTRATACIÓN.
 *
 *   Elena lo dijo sin rodeos: «solo ingresa la empresa realmente a un contrato
 *   si viene autorizado por la junta directiva. No es el líder el que toma la
 *   decisión final de la contratación». Es un formato aparte del requerimiento:
 *   el jefe PIDE personal y elige de la terna, pero quien autoriza el gasto de
 *   un cargo nuevo es la junta.
 *
 *   Hasta ahora el sistema dejaba abrir la contratación apenas el jefe elegía,
 *   que es justo saltarse ese control.
 *
 * 2 · EL DOBLE CONCEPTO DEL PERÍODO DE PRUEBA.
 *
 *   «Ese período de prueba debe estar validado por el jefe del área y por
 *   gestión humana». Eran dos conceptos, no uno: el jefe sabe si la persona
 *   rinde en el puesto, Gestión Humana mira lo demás. Estaba implementado con
 *   una sola evaluación.
 *
 * 3 · LA FECHA DE LA ARL.
 *
 *   «Oficialmente la persona ingresa cuando quedó ingresada en ARL» y «la
 *   fecha del contrato tiene que ser exactamente esa misma fecha». La
 *   afiliación se hace el día ANTES del inicio. Se guarda la fecha real de la
 *   afiliación, aparte de cuándo se registró en el sistema, porque son dos
 *   cosas distintas y sólo la primera sirve para comprobar la regla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrhh_ordenes_contratacion', function (Blueprint $t) {
            $t->id();
            $t->string('numero', 30)->unique();

            // Una orden por persona elegida.
            $t->foreignId('candidato_id')->unique()->constrained('rrhh_candidatos')->cascadeOnUpdate();
            $t->foreignId('requisicion_id')->nullable()->constrained('rrhh_requisiciones')->nullOnDelete();
            $t->foreignId('vacante_id')->nullable()->constrained('rrhh_vacantes')->nullOnDelete();

            // Lo que la junta autoriza es un COSTO, no un nombre: el cargo y
            // cuánto se le va a pagar. Se congela acá para que después nadie
            // contrate por más de lo autorizado sin que se note.
            $t->string('cargo', 160);
            $t->decimal('salario_propuesto', 14, 2)->nullable();
            $t->string('tipo_contrato', 60)->nullable();
            $t->string('justificacion', 1000)->nullable();

            $t->string('estado', 20)->default('pendiente')->index();
            $t->foreignId('solicitada_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('solicitada_at')->nullable();
            $t->foreignId('decidida_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decidida_at')->nullable();
            $t->string('motivo_decision', 500)->nullable();

            $t->timestamps();
            $t->softDeletes();
        });

        Schema::table('rrhh_contrataciones', function (Blueprint $t) {
            $t->foreignId('orden_id')->nullable()->after('vacante_id')
                ->constrained('rrhh_ordenes_contratacion')->nullOnDelete();

            // Fecha REAL de la afiliación a la ARL, que no es lo mismo que
            // `arl_at` (cuándo se tecleó acá). Sólo la primera sirve para
            // comprobar que se hizo antes del día de inicio.
            $t->date('arl_fecha')->nullable()->after('arl_at');
        });

        Schema::table('rrhh_empleados', function (Blueprint $t) {
            // El concepto del jefe del área, que va ANTES del de Gestión
            // Humana: ella decide viendo lo que él opinó, no a ciegas.
            $t->timestamp('prueba_jefe_at')->nullable()->after('prueba_hasta');
            $t->foreignId('prueba_jefe_por_id')->nullable()->after('prueba_jefe_at')
                ->constrained('users')->nullOnDelete();
            $t->string('prueba_jefe_resultado', 20)->nullable()->after('prueba_jefe_por_id');
            $t->string('prueba_jefe_observacion', 500)->nullable()->after('prueba_jefe_resultado');
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->dropConstrainedForeignId('prueba_jefe_por_id');
            $t->dropColumn(['prueba_jefe_at', 'prueba_jefe_resultado', 'prueba_jefe_observacion']);
        });
        Schema::table('rrhh_contrataciones', function (Blueprint $t) {
            $t->dropConstrainedForeignId('orden_id');
            $t->dropColumn('arl_fecha');
        });
        Schema::dropIfExists('rrhh_ordenes_contratacion');
    }
};
