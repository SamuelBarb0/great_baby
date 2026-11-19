<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestión Humana · la liquidación mensual de nómina.
 *
 * Hasta acá el ERP sabía quién trabaja, cuánto gana y qué novedades tuvo en el
 * mes —el cuadro de la Fase B—, y ahí se detenía: el cálculo de lo que
 * realmente se le paga a cada persona se hacía por fuera, en un Excel, y
 * entraba al sistema sólo como un asiento contable por el total.
 *
 * ── Por qué se guarda y no se calcula al vuelo ──────────────────────────────
 *
 * Porque una nómina pagada no puede cambiar. Si el neto se recalculara cada
 * vez que se abre la pantalla, corregir el salario de alguien en diciembre
 * reescribiría lo que se le pagó en marzo, y el soporte de un pago que ya
 * salió del banco dejaría de cuadrar con el banco.
 *
 * Por eso cada línea guarda su propio retrato: el nombre, el documento, el
 * cargo y el salario CON LOS QUE SE LIQUIDÓ. Si la persona cambia de cargo el
 * mes entrante, la nómina de este mes sigue diciendo lo que decía.
 *
 * ── Lo que esta fase NO hace ────────────────────────────────────────────────
 *
 * Liquida lo que recibe el colaborador: devengados, deducciones y neto. Los
 * aportes patronales (el 8,5% de salud, el 12% de pensión, ARL, parafiscales)
 * y las provisiones de prestaciones —cesantías, intereses, prima, vacaciones—
 * son el costo de la empresa, no el pago de la persona, y van en su propio
 * bloque. Se dice acá para que nadie lea este total como «lo que cuesta la
 * nómina»: es lo que se consigna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrhh_nominas', function (Blueprint $t) {
            $t->id();

            // 'YYYY-MM', igual que el cuadro de novedades.
            $t->string('periodo', 7)->unique();

            /*
             * borrador · aprobada · pagada
             *
             * El borrador se puede volver a liquidar las veces que haga falta
             * —llega una incapacidad tarde y hay que rehacerla—. Aprobada ya
             * no: de ahí en adelante lo que dice es lo que se paga.
             */
            $t->string('estado', 15)->default('borrador');

            $t->date('fecha_pago')->nullable();

            // Totales del período, para no sumar 40 líneas en cada pantalla.
            $t->decimal('total_devengado', 14, 2)->default(0);
            $t->decimal('total_deducido', 14, 2)->default(0);
            $t->decimal('total_neto', 14, 2)->default(0);

            $t->text('observaciones')->nullable();

            // El rastro de las tres decisiones: liquidar, aprobar, pagar.
            $t->timestamp('liquidada_at')->nullable();
            $t->foreignId('liquidada_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('aprobada_at')->nullable();
            $t->foreignId('aprobada_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('pagada_at')->nullable();
            $t->foreignId('pagada_por_id')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();
        });

        Schema::create('rrhh_nomina_lineas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('nomina_id')->constrained('rrhh_nominas')->cascadeOnDelete();
            $t->foreignId('empleado_id')->constrained('rrhh_empleados')->cascadeOnDelete();

            /*
             * El retrato de la persona al momento de liquidar.
             *
             * Se repite a propósito lo que ya está en `rrhh_empleados`: un
             * desprendible tiene que poder reimprimirse en dos años diciendo
             * lo mismo, aunque la persona ya no trabaje acá o haya cambiado
             * de cargo y de sueldo.
             */
            $t->string('nombre', 160);
            $t->string('documento', 30)->nullable();
            $t->string('cargo', 120)->nullable();
            $t->decimal('salario_base', 14, 2)->default(0);

            $t->decimal('dias_trabajados', 6, 2)->default(0);

            // Devengados, uno por concepto: un desprendible que sólo dijera el
            // total no le sirve a nadie para revisar.
            $t->decimal('devengado_salario', 14, 2)->default(0);
            $t->decimal('devengado_auxilio', 14, 2)->default(0);
            $t->decimal('devengado_adicional', 14, 2)->default(0);
            $t->decimal('devengado_extras', 14, 2)->default(0);

            // Deducciones.
            $t->decimal('deduccion_salud', 14, 2)->default(0);
            $t->decimal('deduccion_pension', 14, 2)->default(0);
            $t->decimal('deduccion_ausentismo', 14, 2)->default(0);

            $t->decimal('total_devengado', 14, 2)->default(0);
            $t->decimal('total_deducido', 14, 2)->default(0);
            $t->decimal('neto', 14, 2)->default(0);

            /*
             * Las fórmulas en palabras, igual que en el cuadro de novedades.
             *
             * El número solo obliga a creerle al sistema. Guardadas junto al
             * valor, quien revisa ve la cuenta —«2.000.000 ÷ 30 × 28»— sin
             * tener que reconstruirla.
             */
            $t->json('detalle')->nullable();

            $t->timestamps();

            // Una línea por persona y período.
            $t->unique(['nomina_id', 'empleado_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rrhh_nomina_lineas');
        Schema::dropIfExists('rrhh_nominas');
    }
};
