<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F11 · Por qué se fue cada quien.
 *
 * Elena lo puso en su lista de indicadores para la Junta: «me pueda generar el
 * reporte de por qué la gente se retira de la empresa». Hoy la tabla sólo tiene
 * `fecha_retiro` y el enum `estado`, así que se podía saber CUÁNTOS se fueron
 * pero no POR QUÉ, que es justo la pregunta.
 *
 * El motivo en texto libre y el tipo en una lista cerrada: el texto sirve para
 * leer el caso, la lista para contar. Sin la lista, el reporte sería un montón
 * de frases distintas que dicen lo mismo y no se pueden sumar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->string('tipo_retiro', 40)->nullable()->after('fecha_retiro');
            $t->string('motivo_retiro', 500)->nullable()->after('tipo_retiro');
            $t->foreignId('retirado_por_id')->nullable()->after('motivo_retiro')
                ->constrained('users')->nullOnDelete();

            // Para los cortes por mes de los indicadores sin leer la tabla
            // entera cada vez.
            $t->index(['fecha_retiro'], 'idx_empleado_retiro');
            $t->index(['fecha_ingreso'], 'idx_empleado_ingreso');
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->dropIndex('idx_empleado_retiro');
            $t->dropIndex('idx_empleado_ingreso');
            $t->dropConstrainedForeignId('retirado_por_id');
            $t->dropColumn(['tipo_retiro', 'motivo_retiro']);
        });
    }
};
