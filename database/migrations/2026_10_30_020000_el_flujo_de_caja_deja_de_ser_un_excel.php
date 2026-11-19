<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El flujo de caja: lo que se proyecta contra lo que pasó.
 *
 * Hoy es `flujo_de_caja_semanal_V8.xlsx`. Su estructura ya está pensada y se
 * respeta: conceptos agrupados, cada uno con su tipo (F fijo / V variable),
 * su responsable de informar y su valor fijo mensual; columnas por semana con
 * «Proyección» y «Real» lado a lado; y una hoja de responsables por área.
 *
 * Lo que cambia es de dónde sale la columna «Real». Hoy la teclean a mano
 * desde otros archivos —de ahí que el resumen del mes muestre «mayor
 * descuadre de caja: $106.054.462»— y el ERP ya tiene esos datos: el recaudo
 * de cartera, los pagos a proveedor, la nómina, los gastos de caja. El
 * `origen` de cada concepto es lo que permite calcularla en vez de copiarla.
 *
 * `periodo` guarda el LUNES de la semana o el día 1 del mes, nunca un rango.
 * Guardar «del 5 al 11» obliga a comparar fechas contra fechas en cada
 * consulta; guardar el inicio y el tipo permite agrupar con un índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flujo_conceptos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 150);
            $table->string('grupo', 30)
                ->comment('saldo_inicial|ingreso_operacional|otro_ingreso|egreso_operacional|otro_egreso');
            // F = fijo mensual (nómina, arriendo) · V = variable (recaudo).
            $table->char('tipo', 1)->default('V');
            // Quién informa este renglón. En el Excel es una columna de texto;
            // acá es una persona de verdad, para poder pedírselo.
            $table->foreignId('responsable_id')->nullable()->constrained('users');
            $table->decimal('valor_fijo_mensual', 14, 2)->nullable();
            /*
             * De dónde sale el REAL.
             *
             * null = se captura a mano, como hoy. Con origen, lo calcula el
             * ERP: es la diferencia entre transcribir cifras cada semana y
             * verlas solas.
             */
            $table->string('origen', 40)->nullable()
                ->comment('recaudo_cartera|pagos_proveedor|nomina|gastos_caja|movimientos_caja');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['grupo', 'orden']);
        });

        Schema::create('flujo_proyecciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concepto_id')->constrained('flujo_conceptos')->cascadeOnDelete();
            $table->string('periodo_tipo', 10)->comment('semana|mes');
            // Lunes de la semana, o día 1 del mes. Nunca un rango.
            $table->date('periodo');
            $table->decimal('monto', 14, 2)->default(0);
            // El real capturado a mano, para los conceptos sin origen.
            $table->decimal('real_manual', 14, 2)->nullable();
            $table->foreignId('capturado_por')->nullable()->constrained('users');
            $table->string('notas', 300)->nullable();
            $table->timestamps();

            $table->unique(['concepto_id', 'periodo_tipo', 'periodo'], 'flujo_proyeccion_unica');
            $table->index(['periodo_tipo', 'periodo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flujo_proyecciones');
        Schema::dropIfExists('flujo_conceptos');
    }
};
