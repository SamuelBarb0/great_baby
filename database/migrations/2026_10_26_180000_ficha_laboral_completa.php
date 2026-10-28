<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase A · Los datos que el cuadro de novedades pide y el empleado no tenía.
 *
 * Gestión Humana lleva cada mes un Excel —«Formato cuadro de novedades»— al
 * contador. Su primer bloque, el de ingresos, pide por cada persona dieciséis
 * datos. El ERP guardaba siete. Los otros nueve se transcriben a mano desde la
 * carpeta física, y por eso el cuadro se arma aparte del sistema.
 *
 * Los que faltaban:
 *
 *   · fecha de nacimiento, grupo sanguíneo, dirección, ciudad
 *   · la cuenta bancaria donde se consigna la nómina
 *   · EPS, pensión, cesantías, caja y ARL con su NIVEL DE RIESGO
 *   · la empresa que afilia —en el Excel, la columna «AFILIADO POR»
 *
 * Las afiliaciones merecen una explicación. Ya se guardaban, pero en
 * `rrhh_contrataciones`: el momento del TRÁMITE. Eso responde «¿se afilió?»,
 * no «¿en qué EPS está hoy?», que es la que se necesita el día de la
 * incapacidad. Alguien que cambia de EPS en marzo seguía apareciendo con la
 * del ingreso. Ahora el dato vigente vive en el empleado; la contratación
 * conserva el suyo como lo que es, el registro del trámite.
 *
 * `valor_adicional` sale textual del Excel: «$2.000.000 + $500.000 (SSI)». En
 * prestación de servicios el pago se parte en honorarios más el aporte a
 * seguridad social, y meter los dos en `salario` daña cualquier promedio.
 *
 * Todo es nullable a propósito: la gente que ya está registrada no tiene estos
 * datos y no se puede inventar ninguno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->date('fecha_nacimiento')->nullable()->after('numero_documento');
            // «O+», «A-». Seis caracteres sobran y evitan discutir el formato.
            $t->string('grupo_sanguineo', 6)->nullable()->after('fecha_nacimiento');
            $t->string('direccion', 200)->nullable()->after('telefono');
            $t->string('ciudad', 80)->nullable()->after('direccion');

            // Quién figura como empleador. En el Excel: «AFILIADO POR».
            $t->string('empresa_afiliadora', 120)->nullable()->after('area');

            $t->string('horario', 160)->nullable()->after('tipo_contrato');
            // Mensual o quincenal: lo pregunta el formato de autorización de
            // contratación (GH-F-018) y manda sobre cuándo se le paga.
            $t->string('periodicidad_pago', 20)->nullable()->after('salario');
            $t->decimal('valor_adicional', 14, 2)->nullable()->after('periodicidad_pago');
            $t->string('concepto_adicional', 60)->nullable()->after('valor_adicional');

            $t->string('banco', 80)->nullable()->after('concepto_adicional');
            $t->string('tipo_cuenta', 20)->nullable()->after('banco');
            $t->string('cuenta_bancaria', 40)->nullable()->after('tipo_cuenta');

            $t->string('eps', 120)->nullable()->after('cuenta_bancaria');
            $t->string('pension', 120)->nullable()->after('eps');
            $t->string('cesantias', 120)->nullable()->after('pension');
            $t->string('caja', 120)->nullable()->after('cesantias');
            $t->string('arl', 120)->nullable()->after('caja');
            // I a V. Lo fija el decreto 1072 de 2015 según la actividad, y de
            // él depende el aporte: no es un adorno de la ficha.
            $t->string('arl_nivel_riesgo', 3)->nullable()->after('arl');
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->dropColumn([
                'fecha_nacimiento', 'grupo_sanguineo', 'direccion', 'ciudad',
                'empresa_afiliadora', 'horario', 'periodicidad_pago',
                'valor_adicional', 'concepto_adicional',
                'banco', 'tipo_cuenta', 'cuenta_bancaria',
                'eps', 'pension', 'cesantias', 'caja', 'arl', 'arl_nivel_riesgo',
            ]);
        });
    }
};
