<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F9 · Lo que la empresa le entrega a cada persona.
 *
 * Elena contó el caso con nombre propio: «la persona se fue y se llevó la
 * tablet. Y no nos ha entregado la tablet». Y la razón: «no estaba alertada
 * que a ese colaborador le habían dado herramientas de trabajo. Entonces le
 * pedí al jefe de área que me sacara un informe de qué trabajadores tienen
 * herramientas».
 *
 * Dos cosas distintas viven en la misma tabla porque se manejan igual —se
 * entregan, se usan, se devuelven— y porque al final las dos tienen que estar
 * en el paz y salvo:
 *
 *   · DOTACIÓN: la ropa de trabajo. «Como lleva el logo de la empresa, que nos
 *     regrese también la dotación.»
 *   · HERRAMIENTAS: el móvil, la tablet, el teclado. Son activos.
 *
 * La asigna el líder de área, que es quien sabe qué necesita su gente: «es el
 * que está ahí en el área y es el que sabe qué recursos necesita el
 * colaborador».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrhh_asignaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empleado_id')->constrained('rrhh_empleados')->cascadeOnDelete();

            $t->string('tipo', 20)->default('herramienta');
            $t->string('descripcion', 200);
            // Serial, placa o lo que identifique ESA unidad. Sin esto, «una
            // tablet» no se puede reclamar: hay que poder decir cuál.
            $t->string('identificador', 100)->nullable();
            $t->unsignedSmallInteger('cantidad')->default(1);
            $t->decimal('valor', 14, 2)->nullable();

            $t->date('fecha_entrega');
            $t->foreignId('entregada_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('observaciones', 500)->nullable();

            // Dónde está: con la persona, devuelta, o perdida/dañada.
            $t->string('estado', 20)->default('asignada')->index();
            $t->date('fecha_devolucion')->nullable();
            $t->foreignId('recibida_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('estado_devolucion', 300)->nullable();

            $t->timestamps();
            $t->softDeletes();
            $t->index(['empleado_id', 'estado']);
        });

        /*
         * Lo que el colaborador reporta sobre lo que tiene.
         *
         * «Es bueno si al colaborador le dieron la herramienta que pues él
         * también pueda dar aviso del estado de la herramienta. Me pasa en
         * producción: las chicas se acercan y me dicen que les está fallando
         * el teclado… y pues no quedó el registro.»
         */
        Schema::create('rrhh_reportes_asignacion', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asignacion_id')->constrained('rrhh_asignaciones')->cascadeOnDelete();
            $t->foreignId('reportado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('novedad', 20);
            $t->string('detalle', 500);
            $t->string('estado', 20)->default('abierto')->index();
            $t->string('respuesta', 500)->nullable();
            $t->foreignId('atendido_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('atendido_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rrhh_reportes_asignacion');
        Schema::dropIfExists('rrhh_asignaciones');
    }
};
