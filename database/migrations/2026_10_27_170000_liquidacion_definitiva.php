<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestión Humana · la liquidación definitiva del contrato.
 *
 * Cuando alguien se va, el ERP ya sabía bastante: la fecha, el tipo de retiro,
 * la entrevista de salida y la entrega de cargo con sus tres firmas. Lo único
 * que no sabía era **cuánta plata se le debe**, que es lo que la persona está
 * esperando.
 *
 * Cesantías, intereses sobre cesantías, prima de servicios y vacaciones. Se
 * calculaba en un Excel aparte, y un error ahí no se nota hasta que la persona
 * reclama —o no reclama, que es peor—.
 *
 * ── Lo que el sistema NO puede saber solo ───────────────────────────────────
 *
 * Tres datos, y por eso son campos y no cálculos:
 *
 *  · **Días de vacaciones ya tomados.** El ERP no lleva vacaciones: el cuadro
 *    de novedades registra incapacidades, permisos, ausencias y horas extra,
 *    no descansos. Mientras no exista ese registro, el dato entra a mano.
 *  · **Cesantías ya consignadas o pagadas.** Las del año anterior se
 *    consignaron al fondo en febrero y no se vuelven a deber; los anticipos
 *    autorizados tampoco.
 *  · **La indemnización.** Depende de si el despido fue sin justa causa, que
 *    es un juicio jurídico, no una casilla. El sistema no lo decide: lo
 *    escribe quien lo decidió, con su concepto al lado.
 *
 * Inventar cualquiera de los tres daría un número con pinta de correcto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrhh_liquidaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empleado_id')->constrained('rrhh_empleados')->cascadeOnDelete();

            /*
             * El retrato, igual que en la nómina: una liquidación pagada no
             * puede cambiar porque después le corrijan el salario a la ficha.
             */
            $t->string('nombre', 160);
            $t->string('documento', 30)->nullable();
            $t->string('cargo', 120)->nullable();
            $t->date('fecha_ingreso');
            $t->date('fecha_retiro');
            $t->string('tipo_retiro', 40)->nullable();
            $t->decimal('salario_base', 14, 2)->default(0);
            $t->decimal('auxilio_base', 14, 2)->default(0);

            // Los días que entran en cada cuenta, cada uno con su período.
            $t->unsignedSmallInteger('dias_cesantias')->default(0);
            $t->unsignedSmallInteger('dias_prima')->default(0);
            $t->unsignedSmallInteger('dias_vacaciones')->default(0);

            // Lo que el sistema no sabe y escribe Gestión Humana.
            $t->unsignedSmallInteger('vacaciones_tomadas')->default(0);
            $t->decimal('cesantias_ya_pagadas', 14, 2)->default(0);
            $t->decimal('indemnizacion', 14, 2)->default(0);
            $t->string('indemnizacion_concepto', 250)->nullable();
            $t->decimal('otros_descuentos', 14, 2)->default(0);
            $t->string('otros_descuentos_concepto', 250)->nullable();

            // Lo calculado.
            $t->decimal('cesantias', 14, 2)->default(0);
            $t->decimal('intereses_cesantias', 14, 2)->default(0);
            $t->decimal('prima', 14, 2)->default(0);
            $t->decimal('vacaciones', 14, 2)->default(0);

            $t->decimal('total_a_favor', 14, 2)->default(0);
            $t->decimal('total_descuentos', 14, 2)->default(0);
            $t->decimal('neto', 14, 2)->default(0);

            // Las fórmulas en palabras, como en el cuadro y en la nómina.
            $t->json('detalle')->nullable();
            $t->text('observaciones')->nullable();

            // borrador · aprobada · pagada
            $t->string('estado', 15)->default('borrador');
            $t->date('fecha_pago')->nullable();

            $t->timestamp('liquidada_at')->nullable();
            $t->foreignId('liquidada_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('aprobada_at')->nullable();
            $t->foreignId('aprobada_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('pagada_at')->nullable();
            $t->foreignId('pagada_por_id')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();

            // Una por persona: si se corrige, se corrige la que hay.
            $t->unique('empleado_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rrhh_liquidaciones');
    }
};
