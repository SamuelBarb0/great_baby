<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde queda guardado el certificado de antecedentes.
 *
 * Hoy `antecedentes_revisados` dice que se miraron y `antecedentes_resultado`
 * qué salió, pero el certificado no se guarda en ninguna parte: queda en el
 * correo de quien lo consultó. Cuando alguien pregunta seis meses después con
 * qué se contrató a esa persona, la respuesta es la palabra de alguien.
 *
 * La columna apunta al disco PRIVADO, igual que la hoja de vida y que el
 * informe psicotécnico. Un certificado judicial es dato sensible: que esté
 * guardado no significa que cualquiera pueda abrirlo, y por eso se sirve por
 * controlador y no por URL pública.
 *
 * La «entrevista del ser» NO necesita tabla: `rrhh_entrevistas_candidato` ya
 * tiene `tipo`, `calificaciones` y `total`, que es exactamente lo que hace
 * falta. Entra como un tipo más, al lado de «saber hacer» y «psicológica».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rrhh_candidatos', function (Blueprint $t) {
            $t->string('antecedentes_archivo_path')->nullable()->after('antecedentes_resultado');
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_candidatos', function (Blueprint $t) {
            $t->dropColumn('antecedentes_archivo_path');
        });
    }
};
