<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién cerró el período de prueba por parte de Gestión Humana.
 *
 * Se vio armando el PDF de la historia laboral: la tabla de conceptos mostraba
 * con nombre y apellido quién opinó por el área, y un guion donde debía ir
 * quién decidió por Gestión Humana. El dato existía sólo en la bitácora.
 *
 * Para un papel que se archiva como prueba, «—» en la columna de quién decidió
 * es justo lo que no puede faltar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->foreignId('prueba_evaluada_por_id')->nullable()->after('prueba_evaluada_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->dropConstrainedForeignId('prueba_evaluada_por_id');
        });
    }
};
