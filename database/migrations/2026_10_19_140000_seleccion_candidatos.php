<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2 de Gestión Humana · lo que le falta al candidato para recorrer el proceso.
 *
 * El esqueleto traía nombre, correo, teléfono y una ruta de hoja de vida. Lo
 * que Elena describió en la reunión necesita además:
 *
 *   · `fuente` — de dónde salió. La publicación en la agencia pública de
 *     empleo de la caja de compensación es OBLIGATORIA por normativa, así que
 *     tiene que quedar registrado por dónde llegó cada quien.
 *   · `documento` — la cédula. Es con lo que Elena consulta los antecedentes
 *     judiciales, y es el dato que después se copia al empleado sin volver a
 *     pedirlo.
 *   · `motivo_descarte` — por qué no siguió. Sin esto, dentro de tres meses
 *     nadie sabe si a esa persona se la puede volver a llamar.
 *   · `elegido_at` / `elegido_por_id` — cuál de la terna escogió el jefe. Hoy
 *     esa decisión se toma por teléfono y no queda en ninguna parte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rrhh_candidatos', function (Blueprint $t) {
            $t->string('documento', 20)->nullable()->after('nombre');
            $t->string('fuente', 60)->nullable()->after('cv_path');
            $t->string('motivo_descarte', 500)->nullable()->after('antecedentes_resultado');

            // Quién de la terna eligió el jefe que pidió el personal.
            $t->timestamp('elegido_at')->nullable()->after('motivo_descarte');
            $t->foreignId('elegido_por_id')->nullable()->after('elegido_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_candidatos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('elegido_por_id');
            $t->dropColumn(['documento', 'fuente', 'motivo_descarte', 'elegido_at']);
        });
    }
};
