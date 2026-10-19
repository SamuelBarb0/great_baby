<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F3 de Gestión Humana · lo que pasa entre «lo elegimos» y «ya trabaja acá».
 *
 * Hoy es el tramo más frágil del proceso: el examen médico, los papeles que
 * trae la persona, la firma del contrato y las cuatro afiliaciones de ley
 * (EPS, ARL, pensión y caja de compensación) viven en carpetas y en la memoria
 * de Elena. Si falta una afiliación nadie se entera hasta que pasa algo, y
 * entonces es tarde y es plata.
 *
 * `rrhh_contrataciones` es ese expediente: una fila por persona elegida, con
 * una fecha por cada paso. La pantalla arma la lista de pendientes a partir de
 * las fechas que faltan, así que no hay forma de «dar por hecho» un paso que
 * no se hizo.
 *
 * El empleado NO nace al elegir al candidato: nace cuando la contratación se
 * completa. Antes de eso la persona todavía puede no aceptar la oferta o no
 * pasar el examen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrhh_contrataciones', function (Blueprint $t) {
            $t->id();
            // Una contratación por candidato elegido: si se cancela y se
            // reintenta, es la misma fila que vuelve a abrirse.
            $t->foreignId('candidato_id')->unique()->constrained('rrhh_candidatos')->cascadeOnUpdate();
            $t->foreignId('vacante_id')->nullable()->constrained('rrhh_vacantes')->nullOnDelete();

            $t->string('estado', 20)->default('en_proceso')->index();

            // Lo que se acordó. Sale de la requisición pero se confirma acá,
            // porque lo que se ofreció y lo que se firmó pueden no coincidir.
            $t->decimal('salario', 14, 2)->nullable();
            $t->string('tipo_contrato', 60)->nullable();
            $t->date('fecha_ingreso')->nullable();

            // Examen médico de ingreso. `no_apto` no es un trámite más: frena
            // la contratación entera.
            $t->timestamp('examen_at')->nullable();
            $t->string('examen_resultado', 30)->nullable();
            $t->string('examen_observacion', 500)->nullable();

            $t->timestamp('contrato_firmado_at')->nullable();

            // Las cuatro afiliaciones de ley. Se guarda a QUÉ entidad, no sólo
            // que se hizo: sin el nombre, el dato no sirve para nada después.
            $t->string('eps', 120)->nullable();
            $t->timestamp('eps_at')->nullable();
            $t->string('arl', 120)->nullable();
            $t->timestamp('arl_at')->nullable();
            $t->string('pension', 120)->nullable();
            $t->timestamp('pension_at')->nullable();
            $t->string('caja', 120)->nullable();
            $t->timestamp('caja_at')->nullable();

            $t->foreignId('empleado_id')->nullable()->constrained('rrhh_empleados')->nullOnDelete();
            $t->timestamp('completada_at')->nullable();
            $t->timestamp('cancelada_at')->nullable();
            $t->string('motivo_cancelacion', 500)->nullable();

            $t->timestamps();
            $t->softDeletes();
        });

        /*
         * Los papeles llegan ANTES de que exista el empleado: la persona trae
         * su cédula y sus certificados cuando todavía es candidata. La tabla
         * exigía `empleado_id`, así que no había dónde guardarlos.
         */
        Schema::table('rrhh_documentos', function (Blueprint $t) {
            $t->foreignId('empleado_id')->nullable()->change();
            $t->foreignId('candidato_id')->nullable()->after('empleado_id')
                ->constrained('rrhh_candidatos')->cascadeOnDelete();
            $t->foreignId('subido_por')->nullable()->after('archivo_path')
                ->constrained('users')->nullOnDelete();
        });

        // La historia laboral del empleado: de dónde vino y con qué contrato.
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->foreignId('vacante_id')->nullable()->after('candidato_id')
                ->constrained('rrhh_vacantes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vacante_id');
        });
        Schema::table('rrhh_documentos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('candidato_id');
            $t->dropConstrainedForeignId('subido_por');
        });
        Schema::dropIfExists('rrhh_contrataciones');
    }
};
