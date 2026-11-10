<?php

/*
 * La conciliación guardaba un saldo, no un extracto.
 *
 * `conciliaciones_bancarias` existía desde septiembre con su saldo de extracto,
 * su saldo de sistema y la resta de los dos. Nunca se enganchó a una pantalla
 * ni a una ruta, pero aun enganchada habría servido a medias: decir que sobran
 * $4.000.000 no ayuda a encontrar CUÁL movimiento sobra, que es el trabajo real
 * —y es el que hoy se hace a mano, con el extracto impreso al lado.
 *
 * Acá se guardan las líneas. Cada una queda en uno de tres montones:
 * conciliada, sólo en el banco, o sólo en el ERP. Y se guardan también los
 * saldos del encabezado del extracto, para que la conciliación archivada se
 * pueda volver a comprobar sola: anterior + abonos − cargos = actual.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conciliaciones_bancarias', function (Blueprint $t) {
            // El período completo del extracto, no una sola fecha: un extracto
            // mensual son treinta días, y `fecha` sola no dice cuáles.
            if (! Schema::hasColumn('conciliaciones_bancarias', 'periodo_desde')) {
                $t->date('periodo_desde')->nullable()->after('fecha');
            }
            if (! Schema::hasColumn('conciliaciones_bancarias', 'periodo_hasta')) {
                $t->date('periodo_hasta')->nullable()->after('periodo_desde');
            }
            if (! Schema::hasColumn('conciliaciones_bancarias', 'cuenta_numero')) {
                $t->string('cuenta_numero', 40)->nullable()->after('banco')
                    ->comment('Número de la cuenta bancaria tal como lo trae el extracto');
            }
            if (! Schema::hasColumn('conciliaciones_bancarias', 'saldo_anterior')) {
                $t->decimal('saldo_anterior', 16, 2)->default(0)->after('cuenta_puc');
            }
            if (! Schema::hasColumn('conciliaciones_bancarias', 'total_abonos')) {
                $t->decimal('total_abonos', 16, 2)->default(0)->after('saldo_anterior');
            }
            if (! Schema::hasColumn('conciliaciones_bancarias', 'total_cargos')) {
                $t->decimal('total_cargos', 16, 2)->default(0)->after('total_abonos');
            }
        });

        if (Schema::hasTable('conciliacion_lineas')) {
            return;
        }

        Schema::create('conciliacion_lineas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conciliacion_id')->constrained('conciliaciones_bancarias')->cascadeOnDelete();

            $t->date('fecha')->index();
            $t->string('descripcion', 255);
            // Con signo, como lo trae el banco: negativo es cargo. Así no hay
            // que adivinar el sentido por la descripción.
            $t->decimal('valor', 16, 2);
            $t->decimal('saldo', 16, 2)->nullable()->comment('Saldo corrido que trae el extracto');

            $t->string('estado', 20)->default('solo_banco')
                ->comment('conciliada | solo_banco | solo_erp');

            // Con qué pago del ERP casó. Sin relación polimórfica de Eloquent:
            // son dos tablas y nada más, y una morph aquí obligaría a cargar
            // modelos para pintar una tabla de cuatrocientas filas.
            $t->string('pago_tipo', 20)->nullable()->comment('venta | proveedor');
            $t->unsignedBigInteger('pago_id')->nullable();

            $t->string('cuenta_puc', 20)->nullable()
                ->comment('Cuenta sugerida o elegida para lo que sólo está en el banco');
            $t->string('nota', 255)->nullable();

            $t->timestamps();

            $t->index(['conciliacion_id', 'estado']);
            $t->index(['pago_tipo', 'pago_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conciliacion_lineas');

        Schema::table('conciliaciones_bancarias', function (Blueprint $t) {
            foreach (['periodo_desde', 'periodo_hasta', 'cuenta_numero', 'saldo_anterior', 'total_abonos', 'total_cargos'] as $col) {
                if (Schema::hasColumn('conciliaciones_bancarias', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
