<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que la empresa pone encima del sueldo.
 *
 * La nómina liquidaba lo que recibe el colaborador —devengados, deducciones y
 * neto— y ahí paraba. Pero el neto NO es lo que cuesta la nómina: encima van
 * los aportes patronales y las provisiones de prestaciones, que suman cerca de
 * la mitad del salario otra vez.
 *
 * Sin eso, la pregunta «¿cuánto nos cuesta el equipo?» se responde con el
 * número equivocado, y el presupuesto de un cargo nuevo se queda corto en
 * ~50 % todas las veces.
 *
 * ── Por qué se guardan y no se calculan al mostrar ──────────────────────────
 *
 * Por lo mismo que el resto de la línea: los porcentajes cambian por ley y la
 * exoneración puede cambiar de un año al otro. Una nómina de marzo tiene que
 * seguir diciendo lo que costó en marzo.
 *
 * ── Provisiones ─────────────────────────────────────────────────────────────
 *
 * No se pagan este mes: se guardan. La prima llega en junio y las cesantías en
 * febrero, y la empresa que no las provisiona se encuentra el hueco de golpe.
 * Van en su propia columna para que nadie las sume al pago del mes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rrhh_nomina_lineas', function (Blueprint $t) {
            // Seguridad social y parafiscales a cargo de la empresa.
            $t->decimal('patronal_salud', 14, 2)->default(0)->after('deduccion_ausentismo');
            $t->decimal('patronal_pension', 14, 2)->default(0)->after('patronal_salud');
            $t->decimal('patronal_arl', 14, 2)->default(0)->after('patronal_pension');
            $t->decimal('patronal_caja', 14, 2)->default(0)->after('patronal_arl');
            $t->decimal('patronal_sena', 14, 2)->default(0)->after('patronal_caja');
            $t->decimal('patronal_icbf', 14, 2)->default(0)->after('patronal_sena');

            // Provisiones de prestaciones.
            $t->decimal('provision_cesantias', 14, 2)->default(0)->after('patronal_icbf');
            $t->decimal('provision_intereses', 14, 2)->default(0)->after('provision_cesantias');
            $t->decimal('provision_prima', 14, 2)->default(0)->after('provision_intereses');
            $t->decimal('provision_vacaciones', 14, 2)->default(0)->after('provision_prima');

            /*
             * Los dos totales, separados a propósito.
             *
             * `total_aportes` es plata que sale este mes a la PILA.
             * `total_provisiones` es plata que se guarda para después.
             * Sumarlas en un solo número haría creer que todo se paga ahora.
             */
            $t->decimal('total_aportes', 14, 2)->default(0)->after('provision_vacaciones');
            $t->decimal('total_provisiones', 14, 2)->default(0)->after('total_aportes');
            // Neto + aportes + provisiones: lo que de verdad cuesta la persona.
            $t->decimal('costo_empresa', 14, 2)->default(0)->after('total_provisiones');
        });

        Schema::table('rrhh_nominas', function (Blueprint $t) {
            $t->decimal('total_aportes', 14, 2)->default(0)->after('total_neto');
            $t->decimal('total_provisiones', 14, 2)->default(0)->after('total_aportes');
            $t->decimal('total_costo_empresa', 14, 2)->default(0)->after('total_provisiones');
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_nomina_lineas', function (Blueprint $t) {
            $t->dropColumn([
                'patronal_salud', 'patronal_pension', 'patronal_arl',
                'patronal_caja', 'patronal_sena', 'patronal_icbf',
                'provision_cesantias', 'provision_intereses',
                'provision_prima', 'provision_vacaciones',
                'total_aportes', 'total_provisiones', 'costo_empresa',
            ]);
        });

        Schema::table('rrhh_nominas', function (Blueprint $t) {
            $t->dropColumn(['total_aportes', 'total_provisiones', 'total_costo_empresa']);
        });
    }
};
