<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El arqueo diario, como lo hacen hoy en el Excel.
 *
 * `arqueos` nació el 2026-09-05 guardando sólo tres cifras: esperado, contado
 * y diferencia. El archivo real que mandó el cliente —«CAJA MENOR OCTUBRE
 * 2026», una hoja por día— muestra que eso es el resumen, no el arqueo:
 *
 *   · un consecutivo propio, `OCT-001-2026`;
 *   · el saldo con que abrió el día;
 *   · la lista de INGRESOS del día, uno por uno;
 *   · la lista de GASTOS, cada uno con a quién se le entregó la plata
 *     —«Cancelación de domicilios · Olinto · $395.000»—;
 *   · el conteo FÍSICO por denominación: 9 de cien mil, 62 de cincuenta mil,
 *     27 de veinte mil… y las monedas;
 *   · y los conceptos que explican el descuadre.
 *
 * Ese último punto es el que más importa. El 1 de octubre la diferencia entre
 * lo contado y lo esperado fue de $229.400, y los dos conceptos anotados para
 * explicarla —ICA y vueltos— sumaban $229.800. Cuatrocientos pesos que nadie
 * vio, porque una hoja de cálculo no obliga a que las dos cifras coincidan.
 * Acá sí se puede exigir, y por eso los conceptos van en su propia tabla en
 * vez de en un campo de texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Lo que le faltaba al arqueo para ser el documento del día.
        Schema::table('arqueos', function (Blueprint $table) {
            $table->string('numero', 20)->nullable()->after('id');
            $table->decimal('saldo_apertura', 14, 2)->default(0)->after('fecha');
            $table->decimal('total_ingresos', 14, 2)->default(0)->after('saldo_apertura');
            $table->decimal('total_gastos', 14, 2)->default(0)->after('total_ingresos');
            // abierto: se sigue cargando movimientos · cerrado: ya se contó y
            // se cuadró · aprobado: lo revisó alguien más.
            $table->string('estado', 15)->default('abierto')->after('resultado');
            $table->timestamp('cerrado_at')->nullable()->after('estado');

            $table->unique('numero');
            $table->index(['estado', 'fecha']);
        });

        // Cada entrada y salida del día. Es el detalle que el Excel lista a
        // mano y que hoy no vive en ninguna parte del ERP.
        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas');
            // Se llena al cerrar: antes de eso el movimiento es del día pero
            // todavía no pertenece a un arqueo firmado.
            $table->foreignId('arqueo_id')->nullable()->constrained('arqueos')->nullOnDelete();
            $table->date('fecha');
            $table->string('tipo', 10)->comment('ingreso|gasto');
            $table->string('descripcion', 200);
            // «Entregado a» del formato: sin esto un gasto de caja no se le
            // puede cobrar a nadie ni pedirle el soporte.
            $table->string('entregado_a', 120)->nullable();
            $table->decimal('valor', 14, 2);
            $table->string('soporte_path', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['caja_id', 'fecha']);
            $table->index('arqueo_id');
        });

        // El conteo físico, billete por billete. Guardar sólo el total
        // impediría revisar un conteo mal sumado, que es el error más común.
        Schema::create('arqueo_denominaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arqueo_id')->constrained('arqueos')->cascadeOnDelete();
            $table->string('especie', 10)->comment('billete|moneda');
            $table->unsignedInteger('denominacion');
            $table->unsignedInteger('cantidad')->default(0);

            $table->unique(['arqueo_id', 'especie', 'denominacion']);
        });

        // Los conceptos que explican la diferencia. En su propia tabla para
        // poder exigir que sumen exactamente el descuadre.
        Schema::create('arqueo_conceptos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arqueo_id')->constrained('arqueos')->cascadeOnDelete();
            $table->string('concepto', 150);
            $table->decimal('valor', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arqueo_conceptos');
        Schema::dropIfExists('arqueo_denominaciones');
        Schema::dropIfExists('movimientos_caja');

        Schema::table('arqueos', function (Blueprint $table) {
            $table->dropIndex(['estado', 'fecha']);
            $table->dropUnique(['numero']);
            $table->dropColumn([
                'numero', 'saldo_apertura', 'total_ingresos',
                'total_gastos', 'estado', 'cerrado_at',
            ]);
        });
    }
};
