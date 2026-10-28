<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase D1 · Los datos que el formato GH-F-018 pide y la orden no tenía.
 *
 * «FORMATO AUTORIZACIÓN DE CONTRATACIÓN» es el papel que firma la Junta para
 * que alguien entre a la empresa. Pide once datos; la orden del ERP guardaba
 * cinco. Los otros seis se escribían a mano sobre el impreso:
 *
 *   · ÁREA — la orden sabía el cargo pero no de qué área
 *   · FECHA DE INICIO — cuándo empieza, que es lo que la Junta autoriza junto
 *     con el gasto. Hoy se pregunta DESPUÉS, al pactar el acuerdo, y para
 *     entonces la autorización ya está firmada sin esa fecha.
 *   · HORARIO DE TRABAJO y CIUDAD DONDE VA A LABORAR
 *   · TIPO DE PAGO — mensual o quincenal
 *   · OBSERVACIONES ADICIONALES
 *
 * Los tres del medio —horario, ciudad y tipo de pago— no se quedan en la
 * orden: cuando la contratación se cierra pasan a la ficha del empleado, que
 * es donde los pide el cuadro de novedades (Fase A). Hoy había que volver a
 * digitarlos ahí, mirando el papel.
 *
 * Todo nullable: las órdenes que ya existen no tienen estos datos y no se
 * pueden inventar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rrhh_ordenes_contratacion', function (Blueprint $t) {
            $t->string('area', 120)->nullable()->after('cargo');
            $t->date('fecha_inicio')->nullable()->after('tipo_contrato');
            $t->string('horario', 160)->nullable()->after('fecha_inicio');
            $t->string('ciudad', 80)->nullable()->after('horario');
            $t->string('periodicidad_pago', 20)->nullable()->after('ciudad');
            $t->text('observaciones')->nullable()->after('justificacion');
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_ordenes_contratacion', function (Blueprint $t) {
            $t->dropColumn(['area', 'fecha_inicio', 'horario', 'ciudad', 'periodicidad_pago', 'observaciones']);
        });
    }
};
