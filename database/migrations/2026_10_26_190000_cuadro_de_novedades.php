<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase B · El cuadro de novedades.
 *
 * Cada mes Gestión Humana arma un Excel con seis bloques y se lo manda al
 * contador: ingresos, retiros, incapacidades, horas extra, permisos y
 * ausencias. De ahí sale la nómina. Vivía entero por fuera del ERP, y por eso
 * `IndicadoresRrhh::ausentismo()` devolvía «no disponible»: el indicador que
 * Elena pidió para la Junta no tenía de dónde salir.
 *
 * ── Por qué sólo CUATRO de los seis bloques son filas de esta tabla ──────────
 *
 * Los ingresos y los retiros del mes ya están guardados: son `fecha_ingreso` y
 * `fecha_retiro` del empleado. Crear además una novedad de «ingreso» sería
 * tener el mismo hecho en dos lados, y es exactamente la falla que este
 * sistema ya pagó cara varias veces: una mitad se entera y la otra no. Esos
 * dos bloques se DERIVAN al armar el cuadro. Lo que no está en ninguna parte
 * —y por eso vive acá— son las cuatro novedades del día a día.
 *
 * ── El descuento ────────────────────────────────────────────────────────────
 *
 * La columna «DTOS» del Excel no es un número suelto, tiene una regla detrás.
 * Se ve en los datos de octubre: 15 días descontaron 124.547,50; 11 días,
 * 91.334,83; 2 días, 16.606,33. Los tres dividen exacto por el mismo valor
 * diario, porque a quien está incapacitado se le descuenta el AUXILIO DE
 * TRANSPORTE proporcional —no lo necesita, no se transporta.
 *
 * Las ausencias no remuneradas siguen otra regla: 2 días × 66.666,67 contra un
 * salario de 2.000.000, es decir salario ÷ 30 × días.
 *
 * El sistema calcula las dos y deja corregir el resultado, marcando con
 * `descuento_manual` cuándo alguien lo cambió. Sin esa marca, recalcular un
 * período pisaría silenciosamente la corrección que alguien hizo a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrhh_novedades', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empleado_id')->constrained('rrhh_empleados')->cascadeOnDelete();

            // incapacidad · permiso · ausencia · horas_extra
            $t->string('tipo', 20);
            // El subtipo dentro del bloque: «INCAPACIDAD GENERAL», «LICENCIA
            // DE MATERNIDAD», «CITA MÉDICA», «INASISTENCIA SIN JUSTIFICACIÓN».
            // Es la columna «EG-AT» / «TIPO DE PERMISO» / «MOTIVO» del Excel.
            $t->string('clase', 40);

            $t->date('fecha_inicio');
            $t->date('fecha_fin')->nullable();
            // «DÍA REINTEGRO». No siempre es el día siguiente al fin: una
            // incapacidad puede prorrogarse y el reintegro se corre.
            $t->date('fecha_reintegro')->nullable();

            // Decimal porque un permiso se mide en horas y medio día existe.
            $t->decimal('dias', 6, 2)->nullable();
            $t->decimal('horas', 6, 2)->nullable();
            // Las horas extra se pagan distinto de día que de noche.
            $t->boolean('nocturna')->default(false);

            $t->boolean('remunerada')->default(true);
            $t->decimal('descuento', 14, 2)->default(0);
            $t->boolean('descuento_manual')->default(false);

            $t->text('observaciones')->nullable();
            // El soporte de la incapacidad: sin él la EPS no la reconoce.
            $t->string('soporte_path', 255)->nullable();
            $t->string('soporte_nombre', 160)->nullable();

            /*
             * A qué corte pertenece, «YYYY-MM».
             *
             * No se deduce de `fecha_inicio`: una incapacidad que arranca el 29
             * de octubre y va hasta el 12 de noviembre se reporta partida, y
             * una que llegó tarde entra al corte siguiente. Quien la registra
             * decide a qué nómina va, y esa decisión se guarda.
             */
            $t->char('periodo', 7);

            $t->foreignId('registrada_por_id')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();
            $t->softDeletes();

            // Las dos consultas reales: armar el cuadro de un mes y ver la
            // historia de una persona.
            $t->index(['periodo', 'tipo']);
            $t->index(['empleado_id', 'fecha_inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rrhh_novedades');
    }
};
