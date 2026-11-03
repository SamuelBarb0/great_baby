<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué facturas se pagan esta semana, y quién lo autorizó.
 *
 * Hoy esa decisión vive en una hoja impresa: la lista de cuentas por pagar
 * con las filas elegidas marcadas en naranja, y al margen, a lápiz,
 * «autorizaron 22 millones Octubre 27». No hay rastro digital de quién
 * autorizó esos 22 millones ni a qué facturas corresponden. Si alguien
 * pregunta en enero por qué se le pagó a un proveedor antes que a otro, la
 * respuesta está en un papel que ya nadie encuentra.
 *
 * Dos tablas y no una porque una programación cubre VARIAS facturas, y cada
 * una puede llevar un monto distinto al saldo completo: en la hoja hay
 * abonos parciales («ARSUTEX tiene un abono de 20.000.000»).
 *
 * El monto autorizado se guarda aparte de la suma de los renglones a
 * propósito. En el papel autorizan una cifra redonda —22 millones— y después
 * se reparte entre facturas; que las dos cifras no coincidan es información,
 * no un error que haya que esconder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programaciones_pago', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            // Para cuándo se programa el desembolso.
            $table->date('fecha_programada');
            $table->string('estado', 15)->default('borrador')
                ->comment('borrador|autorizada|ejecutada|anulada');

            // Lo que se autorizó, que puede no ser la suma de los renglones.
            $table->decimal('monto_autorizado', 14, 2)->nullable();

            $table->foreignId('creada_por')->nullable()->constrained('users');
            $table->foreignId('autorizada_por')->nullable()->constrained('users');
            $table->timestamp('autorizada_at')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado', 'fecha_programada']);
        });

        Schema::create('programacion_pago_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programacion_id')->constrained('programaciones_pago')->cascadeOnDelete();
            // La cuenta por pagar se identifica por su recepción: es donde
            // viven la factura del proveedor y su valor.
            $table->foreignId('recepcion_id')->constrained('compras_recepciones');
            $table->decimal('monto', 14, 2);
            $table->timestamps();

            // La misma factura no puede ir dos veces en la misma programación:
            // se sumaría sola y el total autorizado dejaría de cuadrar.
            $table->unique(['programacion_id', 'recepcion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programacion_pago_items');
        Schema::dropIfExists('programaciones_pago');
    }
};
